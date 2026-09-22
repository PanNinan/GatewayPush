<?php
/**
 * P5 实测脚本（A）：重连 + 自动重鉴权 + 离线补投（对运行中服务，WS 通道）
 *
 * 用法：php tests/Manual/_p5_reconnect_check.php [uid] [device]
 *
 * 配合外部编排（由测试驱动方执行）：
 *   1. 本脚本建连并就绪（reconnected=0）
 *   2. 外部停止 gateway -> 本端断线 -> 指数退避重连（期间 gateway 不在，重试失败须继续退避）
 *   3. 外部经 AdminApi 向本 uid 推送一条离线消息（offline 入队）
 *   4. 外部重启 gateway -> 本端重连成功 -> 自动重鉴权（reconnected=1）-> 补投 offline=1 -> 自动回 ack
 *   5. 30s 兜底汇总退出
 *
 * 断线期间的重连退避必须覆盖「网关暂不可达」：依赖 WsTransport 对
 * 建连失败补发 close 信号（workerman 建连失败只触发 onError）。
 *
 * 退出码：0 = 全部通过
 */

define('BASE_PATH', dirname(__DIR__, 2));

require BASE_PATH . '/vendor/autoload.php';

use GatewayPush\Client\Event\PushReceiver;
use GatewayPush\Client\Session\SessionManager;
use GatewayPush\Client\Transport\WsTransport;
use Workerman\Worker;

$uid    = isset($argv[1]) ? (string)$argv[1] : 'p5-uid-' . bin2hex(random_bytes(3));
$device = isset($argv[2]) ? (string)$argv[2] : 'p5-dev';

echo "P5 重连/补投实测（客户端 A）\n";
echo "uid={$uid} device={$device}\n";

$state = [
    'auth1'         => false,   // 首次鉴权完成（reconnected=0）
    'dropped'       => false,   // 被动断线已发生
    'reconnectTry'  => 0,       // 重连尝试次数
    'reconnected'   => false,   // 重连后重鉴权 reconnected=1
    'offlinePush'   => false,   // 收到 offline=1 补投
    'acked'         => 0,
];
$failMsg = [];

$worker = new Worker();

$worker->onWorkerStart = function () use ($uid, $device, &$state, &$failMsg) {
    $transport = new WsTransport('ws://127.0.0.1:8282');

    $session = new SessionManager([
        'uid'            => $uid,
        'device_id'      => $device,
        'secret'         => (require BASE_PATH . '/config/app.php')['auth']['secret'],
        'heartbeat'      => 0,
        'timeout'        => 5.0,
        'reconnect'      => true,
        'reconnect_base' => 1.0,
        'reconnect_max'  => 4.0,
        'auto_auth'      => false, // 手动鉴权以便捕获 reconnected 标志
    ], $transport);

    $session->onStateChange(function ($new, $old) use ($session, &$state) {
        echo sprintf("[state] %s -> %s\n", $old, $new);

        if ($new === SessionManager::STATE_CONNECTED) {
            // 首连与重连共用该分支：重连成功后自动重鉴权
            $session->auth(function ($ok, $packet) use (&$state) {
                $flag = isset($packet['data']['reconnected']) ? (int)$packet['data']['reconnected'] : -1;
                if ($ok && $flag === 0 && !$state['auth1']) {
                    $state['auth1'] = true;
                    echo "[auth] 首次鉴权 ok，reconnected={$flag}\n";
                } elseif ($ok && $flag === 1) {
                    $state['reconnected'] = true;
                    echo "[auth] 重连重鉴权 ok，reconnected=1（会话已恢复）\n";
                } else {
                    $GLOBALS['failMsg'][] = '鉴权回执异常 ok=' . var_export($ok, true) . ' reconnected=' . $flag;
                }
            });
        }

        if ($old === SessionManager::STATE_READY) {
            $state['dropped'] = true;
        }
        if ($new === SessionManager::STATE_RECONNECTING) {
            $state['reconnectTry']++;
            echo sprintf("[reconnect] 第 %d 次进入退避\n", $state['reconnectTry']);
        }
    });

    $session->onError(function ($e) use (&$failMsg) {
        // 网关不可达期间的重试错误是预期路径，不计失败
        echo '[error] ' . $e->getMessage() . "\n";
    });

    $receiver = new PushReceiver($session);
    $receiver->onPush(function (array $payload, array $meta) use (&$state) {
        echo sprintf(
            "[push] msg_id=%s offline=%d payload=%s\n",
            $meta['msg_id'],
            $meta['offline'],
            json_encode($payload, JSON_UNESCAPED_UNICODE)
        );
        if ($meta['offline'] === 1) {
            $state['offlinePush'] = true;
        }
    });

    $session->connect();

    // 轮询汇总：全部通过即提前退出，避免固定窗口与外部编排节奏错配
    $elapsed = 0;
    \Workerman\Timer::add(1, function () use (&$state, &$failMsg, &$elapsed, $receiver) {
        $elapsed++;

        $done = $state['auth1'] && $state['dropped'] && $state['reconnected']
            && $state['offlinePush'] && $receiver->ackedCount() >= 1;

        if (!$done && $elapsed < 240) {
            return;
        }

        echo "\n" . str_repeat('=', 60) . "\n";
        $checks = [
            'auth1'       => '首次鉴权 ready（reconnected=0）',
            'dropped'     => '网关停止后被动断线',
            'reconnected' => '重连后自动重鉴权（reconnected=1）',
            'offlinePush' => '离线补投 offline=1',
        ];
        $pass = $receiver->ackedCount() >= 1;
        foreach ($checks as $key => $label) {
            $ok   = $state[$key] === true;
            $pass = $pass && $ok;
            echo sprintf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
        }
        echo '自动回执数 acked=' . $receiver->ackedCount() . "\n";
        foreach ($failMsg as $msg) {
            echo "异常：{$msg}\n";
            $pass = false;
        }
        echo str_repeat('=', 60) . "\n";
        echo $pass ? "P5 实测结论：全部通过\n" : "P5 实测结论：存在失败项（等待 {$elapsed}s）\n";

        exit($pass ? 0 : 1);
    });
};

Worker::runAll();
