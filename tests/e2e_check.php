<?php
/**
 * 端到端链路自检脚本（WebSocket + UDP 双协议）
 *
 * 用法：
 *   php tests/e2e_check.php <uid> [device_id] [timeout]
 *
 * 前置条件：
 *   1. Redis 可用
 *   2. 已启动 register / gateway / udp / business 四个角色
 *
 * 校验用例：
 *   [A] WebSocket 正常链路：连接 -> auth 鉴权 -> ack -> ping -> pong
 *   [B] WebSocket 越权拦截：未鉴权直接发送业务指令 -> 返回 4003 并断开
 *   [C] UDP 正常链路：合法签名报文 -> 收到 ack 回执
 *   [D] UDP 签名拦截：篡改签名报文 -> 返回 4001
 *
 * 退出码：0 = 全部通过，1 = 存在失败项
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use GatewayPush\Business\Auth;
use GatewayPush\Business\Message;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\AsyncUdpConnection;
use Workerman\Timer;
use Workerman\Worker;

$uid      = isset($argv[1]) ? (string)$argv[1] : 'e2e-uid-1001';
$deviceId = isset($argv[2]) ? (string)$argv[2] : 'e2e-device-A';
$timeout  = isset($argv[3]) ? (int)$argv[3] : 12;

$appConfig     = require BASE_PATH . '/config/app.php';
$gatewayConfig = require BASE_PATH . '/config/gateway.php';

$wsAddress  = str_replace('websocket://0.0.0.0', 'ws://127.0.0.1', $gatewayConfig['websocket']['listen']);
// 客户端侧不能连接 0.0.0.0（仅服务端可绑定的通配地址），需替换为回环地址
$udpAddress = str_replace('udp://0.0.0.0', 'udp://127.0.0.1', $gatewayConfig['udp']['listen']);

Auth::init($appConfig['auth']);
$secret = (string)$appConfig['auth']['secret'];
$token  = Auth::issue(array('uid' => $uid, 'device_id' => $deviceId));

/**
 * 用例状态：'pending' 表示未完成，其余为布尔结果
 */
$state = array(
    'A' => 'pending', 'A_msg' => '',
    'B' => 'pending', 'B_msg' => '',
    'C' => 'pending', 'C_msg' => '',
    'D' => 'pending', 'D_msg' => '',
);

/**
 * 构造带签名的标准报文
 */
function buildPacket($cmd, $seq, array $extra, $secret)
{
    $packet = array(
        'cmd'       => $cmd,
        'seq'       => $seq,
        'ts'        => time(),
        'uid'       => '',
        'device_id' => '',
        'token'     => '',
        'data'      => array(),
    );
    foreach ($extra as $key => $value) {
        $packet[$key] = $value;
    }
    $packet['sign'] = Message::sign($packet, $secret);
    return $packet;
}

/**
 * 发送 UDP 报文并在未收到回执时重传
 *
 * AsyncUdpConnection::send() 返回 true 仅表示数据已交给内核，不代表服务端已收到；
 * 首个报文尤其可能因 socket 尚未就绪而静默丢失（此时 send 仍返回 true）。
 * 因此按「应用层确认」语义处理：延迟首包 + 未收到回执则重传，收到回执立即停止。
 *
 * @param AsyncUdpConnection $con
 * @param string             $payload    已编码的报文
 * @param array              $state      用例状态（引用传递，收到回执后由 onMessage 置为非 pending）
 * @param string             $case       用例标识
 * @param int                $maxAttempt 最大发送次数
 * @param float              $interval   重传间隔（秒）
 * @return void
 */
function udpSendUntilAck($con, $payload, &$state, $case, $maxAttempt = 4, $interval = 1.2)
{
    $attempt = function ($n) use ($con, $payload, &$state, $case, $maxAttempt, $interval, &$attempt) {
        if ($state[$case] !== 'pending' || $n > $maxAttempt) {
            return;
        }

        $sent = $con->send($payload);

        echo sprintf(
            "[%s] -> 报文 %d 字节，第 %d/%d 次发送（send 返回 %s）\n",
            $case,
            strlen($payload),
            $n,
            $maxAttempt,
            var_export($sent, true)
        );

        Timer::add($interval, function () use ($n, &$attempt) {
            $attempt($n + 1);
        }, array(), false);
    };

    // 延迟首包，规避 socket 就绪竞态
    Timer::add(0.2, function () use (&$attempt) {
        $attempt(1);
    }, array(), false);
}

echo "端到端链路自检（WebSocket + UDP）\n";
echo str_repeat('=', 70) . "\n";
echo "WS  网关 : {$wsAddress}\n";
echo "UDP 网关 : {$udpAddress}\n";
echo "uid      : {$uid}\n";
echo "device_id: {$deviceId}\n";
echo str_repeat('-', 70) . "\n";

$worker = new Worker();
$worker->onWorkerStart = function () use ($wsAddress, $udpAddress, $uid, $deviceId, $token, $secret, $timeout, &$state) {

    /**
     * 全部用例完成后汇总判定
     */
    $finish = function () use (&$state) {
        $pass = true;
        foreach ($state as $key => $value) {
            if (substr($key, -4) === '_msg') {
                continue;
            }
            if ($value === 'pending') {
                return;   // 仍有未完成用例
            }
            if ($value === false) {
                $pass = false;
            }
        }

        echo "\n" . str_repeat('=', 70) . "\n";
        $labels = array(
            'A' => 'WebSocket 鉴权链路（auth -> ack -> ping -> pong）',
            'B' => 'WebSocket 越权拦截（未鉴权业务指令 -> 4003）',
            'C' => 'UDP 正常链路（合法签名 -> ack）',
            'D' => 'UDP 签名拦截（篡改签名 -> 4001）',
        );
        foreach ($labels as $key => $label) {
            $ok  = $state[$key] === true;
            $msg = $state[$key . '_msg'] !== '' ? '  原因：' . $state[$key . '_msg'] : '';
            echo sprintf("[%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $msg);
        }
        echo str_repeat('=', 70) . "\n";
        echo $pass ? "端到端自检结论：全部通过\n" : "端到端自检结论：存在失败项\n";
        exit($pass ? 0 : 1);
    };

    /* ================= 用例 A：WebSocket 正常鉴权链路 ================= */
    $connA = new AsyncTcpConnection($wsAddress);

    $connA->onConnect = function ($con) use ($uid, $deviceId, $token, $secret) {
        echo "[A] WebSocket 已连接\n";
        $con->send(Message::encode(buildPacket(Message::CMD_AUTH, 'a-auth-1', array(
            'uid'       => $uid,
            'device_id' => $deviceId,
            'token'     => $token,
            'data'      => array('client' => 'e2e-check'),
        ), $secret)));
        echo "[A] -> auth（含签名）\n";
    };

    $connA->onMessage = function ($con, $raw) use ($secret, &$state, $finish) {
        echo "[A] <- {$raw}\n";
        $packet = json_decode($raw, true);
        if (!is_array($packet) || !isset($packet['cmd'])) {
            return;
        }
        if ($packet['cmd'] === Message::CMD_ACK) {
            $con->send(Message::encode(buildPacket(Message::CMD_PING, 'a-ping-1', array(), $secret)));
            echo "[A] -> ping\n";
            return;
        }
        if ($packet['cmd'] === Message::CMD_PONG) {
            $state['A'] = true;
            $con->close();
            $finish();
            return;
        }
        if ($packet['cmd'] === Message::CMD_ERROR) {
            $state['A']     = false;
            $state['A_msg'] = isset($packet['data']['msg']) ? (string)$packet['data']['msg'] : '未知错误';
            $con->close();
            $finish();
        }
    };

    $connA->onError = function ($con, $code, $msg) use (&$state, $finish) {
        $state['A']     = false;
        $state['A_msg'] = "连接错误 {$code}: {$msg}";
        $finish();
    };

    $connA->onClose = function () use (&$state, $finish) {
        if ($state['A'] === 'pending') {
            $state['A']     = false;
            $state['A_msg'] = '流程完成前连接被关闭';
            $finish();
        }
    };

    $connA->connect();

    /* ================= 用例 B：WebSocket 越权拦截 ================= */
    $connB = new AsyncTcpConnection($wsAddress);

    $connB->onConnect = function ($con) use ($secret) {
        echo "[B] WebSocket 已连接（不发送 auth）\n";
        $con->send(Message::encode(buildPacket(Message::CMD_DATA, 'b-data-1', array(
            'uid'       => 'probe-uid',
            'device_id' => 'probe-device',
            'data'      => array('probe' => 1),
        ), $secret)));
        echo "[B] -> data（未鉴权越权探测）\n";
    };

    $connB->onMessage = function ($con, $raw) use (&$state, $finish) {
        echo "[B] <- {$raw}\n";
        $packet = json_decode($raw, true);
        if (is_array($packet) && isset($packet['cmd']) && $packet['cmd'] === Message::CMD_ERROR) {
            $code = isset($packet['data']['code']) ? (int)$packet['data']['code'] : 0;
            if ($code === Message::CODE_UNAUTHORIZED) {
                $state['B'] = true;
            } else {
                $state['B']     = false;
                $state['B_msg'] = "返回错误码 {$code}，期望 " . Message::CODE_UNAUTHORIZED;
            }
        }
    };

    $connB->onClose = function () use (&$state, $finish) {
        if ($state['B'] === 'pending') {
            $state['B']     = false;
            $state['B_msg'] = '连接已关闭但未收到越权拦截提示';
        }
        $finish();
    };

    $connB->connect();

    /* ================= 用例 C：UDP 正常链路 ================= */
    $udpC = new AsyncUdpConnection($udpAddress);

    $udpC->onConnect = function ($con) use ($uid, $deviceId, $token, $secret, &$state) {
        echo "[C] UDP 通道已就绪\n";
        $payload = Message::encode(buildPacket(Message::CMD_DATA, 'c-udp-1', array(
            'uid'       => $uid,
            'device_id' => $deviceId,
            'token'     => $token,
            'data'      => array('metric' => 42),
        ), $secret));
        udpSendUntilAck($con, $payload, $state, 'C');
    };

    $udpC->onMessage = function ($con, $raw) use (&$state, $finish) {
        echo "[C] <- {$raw}\n";
        $packet = json_decode($raw, true);
        $state['C'] = false;
        if (is_array($packet) && isset($packet['cmd'])) {
            if ($packet['cmd'] === Message::CMD_ACK) {
                $state['C'] = true;
            } else {
                $state['C_msg'] = isset($packet['data']['msg'])
                    ? '期望 ack，实际返回 ' . $packet['cmd'] . '：' . $packet['data']['msg']
                    : '期望 ack，实际返回 ' . $packet['cmd'];
            }
        } else {
            $state['C_msg'] = '响应不是合法 JSON 报文';
        }
        $con->close();
        $finish();
    };

    $udpC->connect();

    /* ================= 用例 D：UDP 签名拦截 ================= */
    $udpD = new AsyncUdpConnection($udpAddress);

    $udpD->onConnect = function ($con) use ($uid, $deviceId, $secret, &$state) {
        echo "[D] UDP 通道已就绪\n";
        $packet  = buildPacket(Message::CMD_DATA, 'd-udp-1', array(
            'uid'       => $uid,
            'device_id' => $deviceId,
            'data'      => array('tampered' => 1),
        ), $secret);
        // 篡改签名，模拟伪造报文
        $packet['sign'] = str_repeat('0', 64);
        udpSendUntilAck($con, Message::encode($packet), $state, 'D');
    };

    $udpD->onMessage = function ($con, $raw) use (&$state, $finish) {
        echo "[D] <- {$raw}\n";
        $packet    = json_decode($raw, true);
        $state['D'] = false;
        if (is_array($packet) && isset($packet['cmd']) && $packet['cmd'] === Message::CMD_ERROR) {
            $code = isset($packet['data']['code']) ? (int)$packet['data']['code'] : 0;
            if ($code === Message::CODE_BAD_SIGN) {
                $state['D'] = true;
            } else {
                $state['D_msg'] = "返回错误码 {$code}，期望 " . Message::CODE_BAD_SIGN;
            }
        } else {
            $state['D_msg'] = '未收到签名校验失败的错误报文';
        }
        $con->close();
        $finish();
    };

    $udpD->connect();

    /* ================= 超时保护 ================= */
    Timer::add($timeout, function () use (&$state, $timeout) {
        echo "\n[超时] 用例未在 {$timeout} 秒内全部完成。当前状态：\n";
        foreach (array('A', 'B', 'C', 'D') as $key) {
            echo "  {$key}: " . ($state[$key] === 'pending' ? '未完成' : var_export($state[$key], true)) . "\n";
        }
        exit(1);
    }, array(), false);
};

Worker::runAll();
