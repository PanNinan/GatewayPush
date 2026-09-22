<?php
/**
 * P3 实测脚本：UdpTransport + SessionManager UDP 通道验收（对运行中服务）
 *
 * 用法：php tests/Manual/_p3_udp_check.php [uid] [device_id]
 *
 * 前置：register / udp / business 三角色已启动，Redis 可用。
 * 注意：Windows 单启动文件仅 1 个 Worker 实例（硬约束②），全部场景在同一
 * 事件循环内用定时器编排。
 *
 * 场景：
 *   [1] UDP auth      -> ready（传输层 ack 结算）
 *   [2] ping          -> 传输层 ack（RTT）
 *   [3] echo          -> 业务层回执（data.action，双层回执判别）
 *   [4] subscribe     -> 业务层回执
 *   [5] report        -> 服务端声明静默，客户端本地超时（预期）
 *   [6] 篡改签名原始帧 -> 4001
 *   [7] notify -> push -> 自动回 ack（UDP 推送闭环）
 *   [8] 重传可见性    -> 指向无服务端口，重传逐次发生、耗尽后放弃 + onError
 *
 * 退出码：0 = 全部通过
 */

define('BASE_PATH', dirname(__DIR__, 2));

require BASE_PATH . '/vendor/autoload.php';

use GatewayPush\Client\Error\ErrorCode;
use GatewayPush\Client\Event\PushReceiver;
use GatewayPush\Client\Protocol\Codec;
use GatewayPush\Client\Session\SessionManager;
use GatewayPush\Client\Transport\UdpTransport;
use Workerman\Timer;
use Workerman\Worker;

$uid    = isset($argv[1]) ? (string)$argv[1] : 'p3-udp-' . bin2hex(random_bytes(3));
$device = isset($argv[2]) ? (string)$argv[2] : 'p3-dev-A';

$appConfig     = require BASE_PATH . '/config/app.php';
$gatewayConfig = require BASE_PATH . '/config/gateway.php';
$secret        = (string)$appConfig['auth']['secret'];
$udpUrl        = str_replace('udp://0.0.0.0', 'udp://127.0.0.1', $gatewayConfig['udp']['listen']);

echo "P3 UDP 通道实测\n" . str_repeat('=', 60) . "\n";
echo "UDP 网关 : {$udpUrl}\nuid      : {$uid}\ndevice   : {$device}\n" . str_repeat('-', 60) . "\n";

$state = [
    'auth'       => false,
    'ping'       => false,
    'echo'       => false,
    'subscribe'  => false,
    'report'     => false,
    'badsign'    => false,
    'push'       => false,
    'acked'      => 0,
    'retransmit' => false,
];
$failMsg = [];

$worker = new Worker();

$worker->onWorkerStart = function () use ($udpUrl, $secret, $uid, $device, &$state, &$failMsg) {
    // ---------- 主通道：SessionManager + UdpTransport ----------

    $udp = new UdpTransport($udpUrl);

    $session = new SessionManager([
        'uid'          => $uid,
        'device_id'    => $device,
        'secret'       => $secret,
        'heartbeat'    => 0,          // 实测脚本手动编排
        'timeout'      => 3.0,
        'reconnect'    => false,      // 场景化手动控制（P5 再验重连）
        'attach_token' => true,       // UDP 必需（硬约束⑲）
    ], $udp);

    $session->onError(function ($e) use (&$state, &$failMsg) {
        // [6] 篡改签名 → 服务端 4001
        if ($e->getCode() === ErrorCode::BAD_SIGN) {
            $state['badsign'] = true;
            echo "[6] <- 4001 签名拦截确认\n";

            return;
        }
        // [5] report 静默 → 本地超时（预期）
        if (str_contains($e->getMessage(), 'data.report')
            && $e->getCode() === ErrorCode::CLIENT_TIMEOUT) {
            $state['report'] = true;
            echo "[5] <- report 本地超时（服务端声明静默，预期）\n";

            return;
        }
        $failMsg[] = '意外错误 ' . $e->getCode() . '：' . $e->getMessage();
    });

    // [7] UDP 推送闭环：notify 触发对自身推送，PushReceiver 自动回 ack
    $receiver = new PushReceiver($session);
    $receiver->onPush(function (array $payload, array $meta) use (&$state) {
        $state['push']  = ($meta['source'] === 'notify' || $meta['msg_id'] !== '');
        $state['acked'] = -1; // 回执数在结算后刷新
        echo sprintf("[7] <- push msg_id=%s payload=%s\n", $meta['msg_id'], json_encode($payload, JSON_UNESCAPED_UNICODE));
    });

    // [1] ready 由 auto_auth + 传输层 ack 达成
    $session->onStateChange(function ($new) use ($session, $udp, $uid, $device, &$state) {
        if ($new !== SessionManager::STATE_READY) {
            return;
        }
        $state['auth'] = true;
        echo "[1] <- auth 传输层 ack，会话 ready\n";

        // [2] ping
        $session->ping(function ($ok) use (&$state, $session) {
            $state['ping'] = $ok;
            echo sprintf("[2] <- pong RTT=%.1fms\n", $session->lastRtt() * 1000);
        });

        // [3] echo：业务层回执（双层回执判别 —— 传输层 ack 不结算）
        $session->request('echo', ['p3' => 'udp'], function ($ok, $packet) use (&$state) {
            $state['echo'] = $ok
                && isset($packet['data']['action'])
                && $packet['data']['action'] === 'echo'
                && isset($packet['data']['params']['p3'])
                && $packet['data']['params']['p3'] === 'udp';
            echo '[3] <- echo 业务回执：' . json_encode($packet['data'], JSON_UNESCAPED_UNICODE) . "\n";
        });

        // [4] subscribe
        $session->request('subscribe', ['topic' => 'p3-topic'], function ($ok, $packet) use (&$state) {
            $state['subscribe'] = $ok;
            echo '[4] <- subscribe：' . ($ok ? 'ok' : 'fail') . "\n";
        });

        // [5] report：UDP 静默（预期 ok=false + 本地超时）
        $session->request('report', ['topic' => 'p3.metric', 'count' => 1, 'value' => 42], function ($ok) use (&$failMsg) {
            if ($ok) {
                $failMsg[] = 'report 不应收到回执（服务端声明静默）';
            }
        });

        // [6] 篡改签名的原始帧（绕过 sendPacket，模拟伪造报文）→ 4001
        $bad = Codec::dataPacket('echo', ['tampered' => 1]);
        $bad['uid']       = $uid;
        $bad['device_id'] = $device;
        $bad['seq']       = 'raw-bad-1';
        $bad['ts']        = time();
        $bad['sign']      = str_repeat('0', 64);
        $udp->send(Codec::encode($bad));

        // [7] notify 触发自身推送（服务端受理 -> push 下行 -> 自动 ack）
        // 延迟 1s：规避「网关传输层 ack 即结算 vs 业务会话异步落库」的竞态（硬约束⑯）
        // msg_id 随机：服务端按 msg_id 幂等去重 600s，固定值会在第二轮被去重
        Timer::add(1.0, function () use ($session) {
            $session->request('notify', [
                'value'  => ['hello' => 'udp-push'],
                'msg_id' => 'p3-notify-' . bin2hex(random_bytes(4)),
            ], function ($ok, $packet) {
                echo '[7] <- notify 受理：' . ($ok ? 'ok' : json_encode($packet['data'] ?? [], JSON_UNESCAPED_UNICODE)) . "\n";
            });
        }, [], false);
    });

    $session->connect(); // auto_auth：onOpen 抢发 auth（传输层预热缓冲，0.2s 后补发）

    // ---------- [8] 重传可见性：独立传输层指向无服务端口 ----------

    Timer::add(3.5, function () use (&$state) {
        $dead = new UdpTransport('udp://127.0.0.1:8299', [
            'retransmit_interval' => 0.3,
            'max_attempts'        => 3,
        ]);
        $dead->onError(function ($code, $msg) use (&$state) {
            $state['retransmit'] = true;
            echo "[8] <- 重传耗尽：{$msg}\n";
        });
        $dead->connect();
        $dead->send('{"cmd":"probe"}'); // 0.2s 预热 + 0.3s×2 重传后放弃（首发+重传共 3 次）
    }, [], false);

    // ---------- 汇总 ----------

    Timer::add(6.5, function () use (&$state, &$failMsg, $receiver) {
        echo "\n" . str_repeat('=', 60) . "\n";
        $state['acked'] = $receiver->ackedCount();

        $checks = [
            'auth'      => ['[1] UDP auth -> ready', true],
            'ping'      => ['[2] ping -> 传输层 ack', true],
            'echo'      => ['[3] echo -> 业务回执（双层判别）', true],
            'subscribe' => ['[4] subscribe -> 业务回执', true],
            'report'    => ['[5] report 静默 -> 本地超时', true],
            'badsign'   => ['[6] 篡改签名 -> 4001', true],
            'push'      => ['[7] notify -> push -> 自动 ack', true],
            'retransmit' => ['[8] 超时重传可见 + 放弃告警', true],
        ];
        $pass = true;
        foreach ($checks as $key => $info) {
            $ok   = $state[$key] === true || ($key === 'push' && $state['acked'] >= 1 && $state['push']);
            $pass = $pass && $ok;
            echo sprintf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $info[0]);
        }
        echo '自动回执数 acked=' . $state['acked'] . "\n";
        foreach ($failMsg as $msg) {
            echo "异常：{$msg}\n";
            $pass = false;
        }
        echo str_repeat('=', 60) . "\n";
        echo $pass ? "P3 UDP 实测结论：全部通过\n" : "P3 UDP 实测结论：存在失败项\n";

        exit($pass ? 0 : 1);
    }, [], false);
};

Worker::runAll();
