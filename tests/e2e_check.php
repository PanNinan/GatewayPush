<?php
/**
 * 端到端链路自检脚本（WebSocket + UDP + 定向推送 + HTTP 接口）
 *
 * 用法：
 *   php tests/e2e_check.php <uid> [device_id] [timeout]
 *
 * 前置条件：
 *   1. Redis 可用
 *   2. 已启动 register / gateway / udp / business 四个角色
 *      （HTTP 用例还需 api 角色；若 api 未启动，用例 H 会标记为跳过）
 *
 * 校验用例：
 *   [A] WebSocket 正常链路：连接 -> auth 鉴权 -> ack -> ping -> pong
 *   [B] WebSocket 越权拦截：未鉴权直接发送业务指令 -> 返回 4003 并断开
 *   [C] UDP 正常链路：合法签名报文 -> 收到 ack 回执
 *   [D] UDP 签名拦截：篡改签名报文 -> 返回 4001
 *   [E] 定向推送（在线）：uid 目标 -> 在线连接收到 push 报文
 *   [F] 离线缓存与重连补投：目标离线时入队 -> 上线后自动补投
 *   [G] 推送幂等：同一 msg_id 重复提交 -> 仅投递一次
 *   [H] HTTP 接口：健康探测 / 验签通过 / 验签拒绝
 *   [I] UDP 定向推送：业务进程 -> UDP 出站队列 -> 网关 sendto
 *   [J] 指令路由表：data.action（echo / session）分发与 4006 / 4007 错误分支
 *   [K] UDP 离线补投：UDP 会话重建时经出站队列补投（offline=1）
 *   [L] 报文级限流：单连接连发超量报文 -> 部分放行、部分 4008 拒绝
 *   [M] 业务动作契约：参数校验白名单 / 4006 未知动作 / 4007 参数错误
 *   [N] UDP 通道业务动作：echo 经出站队列回执；report 按声明静默不回执
 *   [O] 订阅与广播闭环：subscribe -> enqueueTopic -> push -> unsubscribe
 *
 * 退出码：0 = 全部通过，1 = 存在失败项
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use GatewayPush\Business\Auth;
use GatewayPush\Business\Message;
use GatewayPush\Business\Push;
use GatewayPush\Common\RedisClient;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\AsyncUdpConnection;
use Workerman\Timer;
use Workerman\Worker;

$uid      = isset($argv[1]) ? (string)$argv[1] : 'e2e-uid-1001';
$deviceId = isset($argv[2]) ? (string)$argv[2] : 'e2e-device-A';
$timeout  = isset($argv[3]) ? (int)$argv[3] : 20;

$appConfig      = require BASE_PATH . '/config/app.php';
$gatewayConfig  = require BASE_PATH . '/config/gateway.php';
$businessConfig = require BASE_PATH . '/config/business.php';

$wsAddress  = str_replace('websocket://0.0.0.0', 'ws://127.0.0.1', $gatewayConfig['websocket']['listen']);
// 客户端侧不能连接 0.0.0.0（仅服务端可绑定的通配地址），需替换为回环地址
$udpAddress = str_replace('udp://0.0.0.0', 'udp://127.0.0.1', $gatewayConfig['udp']['listen']);
$apiAddress = str_replace('0.0.0.0', '127.0.0.1', $appConfig['api']['listen']);

Auth::init($appConfig['auth']);
Push::init($appConfig['push'], $businessConfig['push_queue'], $gatewayConfig['udp']['out_queue']);

$secret = (string)$appConfig['auth']['secret'];
$token  = Auth::issue(array('uid' => $uid, 'device_id' => $deviceId));

// 各推送用例使用独立 uid / device，避免互相干扰
$uidE    = $uid . '-E';
$uidF    = $uid . '-F';
$uidG    = $uid . '-G';
$uidI    = $uid . '-I';
$uidJ    = $uid . '-J';
$uidK    = $uid . '-K';
$uidL    = $uid . '-L';
$uidM    = $uid . '-M';
$uidN    = $uid . '-N';
$uidO    = $uid . '-O';
$deviceE = $deviceId . '-E';
$deviceF = $deviceId . '-F';
$deviceG = $deviceId . '-G';
$deviceI = $deviceId . '-I';
$deviceJ = $deviceId . '-J';
$deviceK = $deviceId . '-K';
$deviceL = $deviceId . '-L';
$deviceM = $deviceId . '-M';
$deviceN = $deviceId . '-N';
$deviceO = $deviceId . '-O';

$tokenE = Auth::issue(array('uid' => $uidE, 'device_id' => $deviceE));
$tokenF = Auth::issue(array('uid' => $uidF, 'device_id' => $deviceF));
$tokenG = Auth::issue(array('uid' => $uidG, 'device_id' => $deviceG));
$tokenI = Auth::issue(array('uid' => $uidI, 'device_id' => $deviceI));
$tokenJ = Auth::issue(array('uid' => $uidJ, 'device_id' => $deviceJ));
$tokenK = Auth::issue(array('uid' => $uidK, 'device_id' => $deviceK));
$tokenL = Auth::issue(array('uid' => $uidL, 'device_id' => $deviceL));
$tokenM = Auth::issue(array('uid' => $uidM, 'device_id' => $deviceM));
$tokenN = Auth::issue(array('uid' => $uidN, 'device_id' => $deviceN));
$tokenO = Auth::issue(array('uid' => $uidO, 'device_id' => $deviceO));

// 上报 / 订阅用例使用独立主题，避免跨轮次互相污染
$topicM = 'e2e_m_' . bin2hex(random_bytes(3));
$topicN = 'e2e_n_' . bin2hex(random_bytes(3));
$topicO = 'e2e_o_' . bin2hex(random_bytes(3));

$nEchoSeq1 = 'n-echo-1';
$nEchoSeq2 = 'n-echo-2';
$nReportSeq = 'n-report-1';

$msgIdE = 'e2e-push-' . bin2hex(random_bytes(4));
$msgIdF = 'e2e-off-' . bin2hex(random_bytes(4));
$msgIdG = 'e2e-idem-' . bin2hex(random_bytes(4));
$msgIdI = 'e2e-udp-' . bin2hex(random_bytes(4));
$msgIdK = 'e2e-udpoff-' . bin2hex(random_bytes(4));

/**
 * 用例状态：'pending' 表示未完成，其余为布尔结果
 */
$state = array(
    'A' => 'pending', 'A_msg' => '',
    'B' => 'pending', 'B_msg' => '',
    'C' => 'pending', 'C_msg' => '',
    'D' => 'pending', 'D_msg' => '',
    'E' => 'pending', 'E_msg' => '',
    'F' => 'pending', 'F_msg' => '',
    'G' => 'pending', 'G_msg' => '',
    'H' => 'pending', 'H_msg' => '',
    'I' => 'pending', 'I_msg' => '',
    'J' => 'pending', 'J_msg' => '',
    'K' => 'pending', 'K_msg' => '',
    'L' => 'pending', 'L_msg' => '',
    'M' => 'pending', 'M_msg' => '',
    'N' => 'pending', 'N_msg' => '',
    'O' => 'pending', 'O_msg' => '',
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

/**
 * 同步 HTTP 请求（用于接口用例）
 *
 * 在事件循环启动前执行，同步阻塞无副作用；启动后请勿调用。
 *
 * @param string $method
 * @param string $url
 * @param array  $headers
 * @param string $body
 * @return array ['ok' => bool, 'status' => int, 'body' => string, 'json' => array|null, 'error' => string]
 */
function httpRequest($method, $url, array $headers = array(), $body = '')
{
    $parts  = parse_url($url);
    $host   = isset($parts['host']) ? $parts['host'] : '127.0.0.1';
    $port   = isset($parts['port']) ? (int)$parts['port'] : 80;
    $path   = isset($parts['path']) ? $parts['path'] : '/';
    if ($path === '') {
        $path = '/';
    }

    $errno  = 0;
    $errstr = '';
    $fp     = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 3);
    if (!$fp) {
        return array('ok' => false, 'status' => 0, 'body' => '', 'json' => null, 'error' => $errstr);
    }

    $req = "{$method} {$path} HTTP/1.1\r\n";
    $req .= "Host: {$host}:{$port}\r\n";
    $req .= "Connection: close\r\n";
    foreach ($headers as $key => $value) {
        $req .= "{$key}: {$value}\r\n";
    }
    if ($body !== '') {
        $req .= 'Content-Length: ' . strlen($body) . "\r\n";
    }
    $req .= "\r\n" . $body;

    fwrite($fp, $req);

    // 服务端默认 keep-alive，不能依赖读到 EOF，需按 Content-Length 精确读取
    $head = '';
    stream_set_timeout($fp, 3);
    while (($line = fgets($fp, 4096)) !== false) {
        $head .= $line;
        if (strpos($head, "\r\n\r\n") !== false) {
            break;
        }
    }
    if ($head === '') {
        fclose($fp);
        return array('ok' => false, 'status' => 0, 'body' => '', 'json' => null, 'error' => '未收到响应头');
    }

    $length = 0;
    if (preg_match('/Content-Length:\s*(\d+)/i', $head, $m) === 1) {
        $length = (int)$m[1];
    }

    $raw    = '';
    $remain = $length;
    while ($remain > 0) {
        $chunk = fread($fp, $remain);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $raw    .= $chunk;
        $remain -= strlen($chunk);
    }
    fclose($fp);

    $status = 0;
    if (preg_match('#^HTTP/\d\.\d\s+(\d+)#', $head, $m) === 1) {
        $status = (int)$m[1];
    }

    return array(
        'ok'     => true,
        'status' => $status,
        'body'   => $raw,
        'json'   => json_decode($raw, true),
        'error'  => '',
    );
}

echo "端到端链路自检（WebSocket + UDP + 定向推送 + HTTP 接口）\n";
echo str_repeat('=', 70) . "\n";
echo "WS  网关 : {$wsAddress}\n";
echo "UDP 网关 : {$udpAddress}\n";
echo "HTTP接口 : {$apiAddress}\n";
echo "uid      : {$uid}\n";
echo "device_id: {$deviceId}\n";
echo str_repeat('-', 70) . "\n";

/* ================= 用例 H：HTTP 接口（同步执行，先于事件循环） ================= */
$hErrors = array();

$health = httpRequest('GET', $apiAddress . '/health');
if (!$health['ok']) {
    $state['H']     = false;
    $state['H_msg'] = '接口不可达（api 角色未启动？）：' . $health['error'];
    $hErrors[]      = $state['H_msg'];
} elseif ($health['status'] !== 200 || !is_array($health['json']) || (int)$health['json']['code'] !== 0) {
    $hErrors[] = "健康探测异常：HTTP {$health['status']}，响应 {$health['body']}";
}

if (!$hErrors) {
    $pushBody = json_encode(array(
        'target_type' => 'uid',
        'target'      => $uid . '-H',
        'payload'     => array('from' => 'http-e2e'),
        'msg_id'      => 'e2e-http-' . bin2hex(random_bytes(4)),
    ), JSON_UNESCAPED_UNICODE);

    $timestamp = time();
    $goodSign  = hash_hmac('sha256', $timestamp . '|' . $pushBody, $secret);

    $accepted = httpRequest('POST', $apiAddress . '/push', array(
        'Content-Type'  => 'application/json',
        'X-Timestamp'   => $timestamp,
        'X-Sign'        => $goodSign,
    ), $pushBody);

    if (!$accepted['ok'] || $accepted['status'] !== 200
        || !is_array($accepted['json']) || (int)$accepted['json']['code'] !== 0) {
        $hErrors[] = sprintf('合法签名请求被拒绝：HTTP %d，响应 %s', $accepted['status'], $accepted['body']);
    }

    $denied = httpRequest('POST', $apiAddress . '/push', array(
        'Content-Type' => 'application/json',
        'X-Timestamp'  => $timestamp,
        'X-Sign'       => str_repeat('0', 64),
    ), $pushBody);

    $deniedCode = is_array($denied['json']) && isset($denied['json']['code']) ? (int)$denied['json']['code'] : 0;
    if ($denied['status'] !== 401 || $deniedCode !== 4001) {
        $hErrors[] = sprintf('伪造签名未被拒绝：HTTP %d，业务码 %d（期望 401 / 4001）', $denied['status'], $deniedCode);
    }
}

if ($hErrors) {
    $state['H']     = false;
    $state['H_msg'] = implode('；', $hErrors);
} else {
    $state['H'] = true;
}
echo '[H] HTTP 接口用例：' . ($state['H'] === true ? "通过\n" : "失败 - {$state['H_msg']}\n");

$worker = new Worker();
$worker->onWorkerStart = function () use (
    $wsAddress, $udpAddress, $uid, $deviceId, $token, $secret, $timeout, &$state,
    $appConfig, $uidE, $deviceE, $tokenE, $uidF, $deviceF, $tokenF, $uidG, $deviceG, $tokenG,
    $uidI, $deviceI, $tokenI, $msgIdE, $msgIdF, $msgIdG, $msgIdI,
    $uidJ, $deviceJ, $tokenJ, $uidK, $deviceK, $tokenK, $msgIdK,
    $uidL, $deviceL, $tokenL,
    $uidM, $deviceM, $tokenM, $topicM,
    $uidN, $deviceN, $tokenN, $topicN, $nEchoSeq1, $nEchoSeq2, $nReportSeq,
    $uidO, $deviceO, $tokenO, $topicO
) {
    RedisClient::init($appConfig['redis']);

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

        // 统计用例编号时排除 E/F/G 使用的主 uid 推送干扰项，直接按定义顺序输出
        echo "\n" . str_repeat('=', 70) . "\n";
        $labels = array(
            'A' => 'WebSocket 鉴权链路（auth -> ack -> ping -> pong）',
            'B' => 'WebSocket 越权拦截（未鉴权业务指令 -> 4003）',
            'C' => 'UDP 正常链路（合法签名 -> ack）',
            'D' => 'UDP 签名拦截（篡改签名 -> 4001）',
            'E' => '定向推送在线投递（uid 目标 -> push 报文）',
            'F' => '离线缓存与重连补投（离线入队 -> 上线补投）',
            'G' => '推送幂等去重（同 msg_id 重复提交 -> 仅一次）',
            'H' => 'HTTP 接口（健康探测 / 验签通过 / 验签拒绝）',
            'I' => 'UDP 定向推送（业务进程 -> 出站队列 -> 网关 sendto）',
            'J' => '指令路由表（data.action 分发 / 4006 / 4007）',
            'K' => 'UDP 离线补投（会话重建 -> 出站队列 -> offline=1）',
            'L' => '报文级限流（超量连发 -> 部分放行 / 部分 4008）',
            'M' => '业务动作契约（参数白名单 / 4006 未知动作 / 4007 参数错误）',
            'N' => 'UDP 通道业务动作（echo 回执 / report 按声明静默）',
            'O' => '订阅与广播闭环（subscribe -> enqueueTopic -> push）',
        );
        foreach ($labels as $key => $label) {
            $ok  = $state[$key] === true;
            $msg = $state[$key . '_msg'] !== '' ? '  原因：' . $state[$key . '_msg'] : '';
            $skipped = ($state[$key] === 'pending' && $key === 'H');
            echo sprintf(
                "[%s] %s%s\n",
                $ok ? 'PASS' : ($skipped ? 'SKIP' : 'FAIL'),
                $label,
                $msg
            );
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

    /* ================= 用例 E：定向推送（在线投递） ================= */
    $connE = new AsyncTcpConnection($wsAddress);
    $eFed  = false;

    $connE->onConnect = function ($con) use ($uidE, $deviceE, $tokenE, $secret) {
        echo "[E] WebSocket 已连接\n";
        $con->send(Message::encode(buildPacket(Message::CMD_AUTH, 'e-auth-1', array(
            'uid'       => $uidE,
            'device_id' => $deviceE,
            'token'     => $tokenE,
        ), $secret)));
        echo "[E] -> auth\n";
    };

    $connE->onMessage = function ($con, $raw) use (&$state, &$eFed, $finish, $uidE, $msgIdE) {
        echo "[E] <- {$raw}\n";
        $packet = json_decode($raw, true);
        if (!is_array($packet) || !isset($packet['cmd'])) {
            return;
        }

        // 鉴权通过后立即投递一条推送任务，验证「在线直达」链路
        if ($packet['cmd'] === Message::CMD_ACK && !$eFed) {
            $eFed = true;
            Push::enqueue('uid', $uidE, array('case' => 'E', 'value' => 7), array(
                'msg_id' => $msgIdE,
                'source' => 'e2e',
            ));
            echo "[E] -> 已提交推送任务（uid 目标，msg_id {$msgIdE}）\n";
            return;
        }

        if ($packet['cmd'] === Message::CMD_PUSH) {
            $state['E'] = true;

            $receivedId = isset($packet['msg_id']) ? (string)$packet['msg_id'] : '';
            if ($receivedId !== $msgIdE) {
                $state['E']     = false;
                $state['E_msg'] = "msg_id 不一致（期望 {$msgIdE}，实际 {$receivedId}）";
            }
            $con->close();
            $finish();
            return;
        }

        if ($packet['cmd'] === Message::CMD_ERROR) {
            $state['E']     = false;
            $state['E_msg'] = isset($packet['data']['msg']) ? (string)$packet['data']['msg'] : '鉴权阶段返回错误';
            $con->close();
            $finish();
        }
    };

    $connE->onClose = function () use (&$state, $finish) {
        if ($state['E'] === 'pending') {
            $state['E']     = false;
            $state['E_msg'] = '流程完成前连接被关闭';
            $finish();
        }
    };

    $connE->connect();

    /* ================= 用例 F：离线缓存与重连补投 ================= */
    // 阶段一：目标离线时提交推送任务（应落入 push:offline:{uid}）
    Push::enqueue('uid', $uidF, array('case' => 'F', 'value' => 'offline'), array(
        'msg_id'       => $msgIdF,
        'offline_mode' => 'queue',
        'source'       => 'e2e',
    ));
    echo "[F] -> 离线推送任务已提交（uid 目标，离线策略 queue）\n";

    // 阶段二：设备上线，鉴权成功后应自动补投
    $connF = new AsyncTcpConnection($wsAddress);

    $connF->onConnect = function ($con) use ($uidF, $deviceF, $tokenF, $secret) {
        echo "[F] WebSocket 已连接\n";
        $con->send(Message::encode(buildPacket(Message::CMD_AUTH, 'f-auth-1', array(
            'uid'       => $uidF,
            'device_id' => $deviceF,
            'token'     => $tokenF,
        ), $secret)));
        echo "[F] -> auth（等待离线消息补投）\n";
    };

    $connF->onMessage = function ($con, $raw) use (&$state, $finish, $msgIdF) {
        echo "[F] <- {$raw}\n";
        $packet = json_decode($raw, true);
        if (!is_array($packet) || !isset($packet['cmd'])) {
            return;
        }

        if ($packet['cmd'] === Message::CMD_PUSH) {
            $isOffline  = isset($packet['offline']) ? (int)$packet['offline'] : 0;
            $receivedId = isset($packet['msg_id']) ? (string)$packet['msg_id'] : '';

            if ($receivedId !== $msgIdF) {
                $state['F']     = false;
                $state['F_msg'] = "补投 msg_id 不一致（期望 {$msgIdF}，实际 {$receivedId}）";
            } elseif ($isOffline !== 1) {
                $state['F']     = false;
                $state['F_msg'] = '补投报文未标记 offline=1';
            } else {
                $state['F'] = true;
            }
            $con->close();
            $finish();
            return;
        }

        if ($packet['cmd'] === Message::CMD_ERROR) {
            $state['F']     = false;
            $state['F_msg'] = isset($packet['data']['msg']) ? (string)$packet['data']['msg'] : '鉴权阶段返回错误';
            $con->close();
            $finish();
        }
    };

    $connF->onClose = function () use (&$state, $finish) {
        if ($state['F'] === 'pending') {
            $state['F']     = false;
            $state['F_msg'] = '连接已关闭但未收到离线补投报文';
            $finish();
        }
    };

    // 稍晚建连，确保离线任务已先入队
    Timer::add(0.3, function () use ($connF) {
        $connF->connect();
    }, array(), false);

    /* ================= 用例 G：推送幂等去重 ================= */
    $connG   = new AsyncTcpConnection($wsAddress);
    $gFed    = false;
    $gCount  = 0;

    $connG->onConnect = function ($con) use ($uidG, $deviceG, $tokenG, $secret) {
        echo "[G] WebSocket 已连接\n";
        $con->send(Message::encode(buildPacket(Message::CMD_AUTH, 'g-auth-1', array(
            'uid'       => $uidG,
            'device_id' => $deviceG,
            'token'     => $tokenG,
        ), $secret)));
        echo "[G] -> auth\n";
    };

    $connG->onMessage = function ($con, $raw) use (&$state, &$gFed, &$gCount, $finish, $uidG, $msgIdG) {
        $packet = json_decode($raw, true);
        if (!is_array($packet) || !isset($packet['cmd'])) {
            return;
        }

        if ($packet['cmd'] === Message::CMD_ACK && !$gFed) {
            $gFed = true;
            // 同一 msg_id 连续提交两次，期望业务侧仅投递一次
            Push::enqueue('uid', $uidG, array('case' => 'G', 'n' => 1), array('msg_id' => $msgIdG, 'source' => 'e2e'));
            Push::enqueue('uid', $uidG, array('case' => 'G', 'n' => 2), array('msg_id' => $msgIdG, 'source' => 'e2e'));
            echo "[G] -> 已提交两次相同 msg_id（{$msgIdG}）\n";

            Timer::add(2.0, function () use (&$state, &$gCount, $finish, $con) {
                if ($gCount === 1) {
                    $state['G'] = true;
                } else {
                    $state['G']     = false;
                    $state['G_msg'] = "收到 {$gCount} 条推送，期望 1 条（幂等去重未生效）";
                }
                $con->close();
                $finish();
            }, array(), false);
            return;
        }

        if ($packet['cmd'] === Message::CMD_PUSH) {
            $gCount++;
            echo "[G] <- push（累计 {$gCount} 条）\n";
        }
    };

    $connG->onClose = function () use (&$state, $finish) {
        if ($state['G'] === 'pending') {
            $state['G']     = false;
            $state['G_msg'] = '连接已关闭但未完成幂等判定';
            $finish();
        }
    };

    $connG->connect();

    /* ================= 用例 I：UDP 定向推送（出站通道） ================= */
    // UDP 的 client_id 不在 Gateway 连接表内，服务端推送必须经「业务进程 -> 出站队列 ->
    // UDP 网关 sendto」闭环，本用例验证该反向通道。
    $udpI      = new AsyncUdpConnection($udpAddress);
    $iReported = false;
    $iAttempt  = 0;

    $sendReport = function () use ($udpI, &$iAttempt, $uidI, $deviceI, $tokenI, $secret, &$iReported) {
        if ($iAttempt >= 3 || $iReported) {
            return;
        }
        $iAttempt++;
        $payload = Message::encode(buildPacket(Message::CMD_DATA, 'i-udp-' . $iAttempt, array(
            'uid'       => $uidI,
            'device_id' => $deviceI,
            'token'     => $tokenI,
            'data'      => array('type' => 'udp-report'),
        ), $secret));
        $udpI->send($payload);
        echo "[I] -> 上报报文（第 {$iAttempt} 次，用于建立 UDP 应用层会话）\n";

        Timer::add(1.0, function () use (&$sendReport, &$iReported) {
            if (!$iReported) {
                $sendReport();
            }
        }, array(), false);
    };

    $udpI->onConnect = function ($con) use ($sendReport, &$state, $finish) {
        echo "[I] UDP 通道已就绪\n";
        Timer::add(0.2, $sendReport, array(), false);

        // 内部超时：UDP 允许丢包，但本地回环下不应丢失，超时即判定失败
        Timer::add(6.0, function () use (&$state, $finish) {
            if ($state['I'] === 'pending') {
                $state['I']     = false;
                $state['I_msg'] = 'UDP 出站推送未在 6 秒内到达客户端';
                $finish();
            }
        }, array(), false);
    };

    $udpI->onMessage = function ($con, $raw) use (&$state, &$iReported, $finish, $uidI, $msgIdI) {
        $packet = json_decode($raw, true);
        if (!is_array($packet) || !isset($packet['cmd'])) {
            return;
        }

        // 会话建立后提交推送任务，验证 UDP 出站通道。
        // 注意时序：ack 由 UDP 网关在收到报文时**立即**回复，而 UDP 应用层会话
        // 由业务进程消费 queue:udp:in 后**异步**建立，二者存在毫秒级竞态。
        // 若在 ack 瞬间入队，推送任务可能先于会话绑定被消费，从而被判定为离线。
        // 此处延迟提交，确保会话已就绪（生产环境下推送由外部系统发起，不存在该竞态）。
        if ($packet['cmd'] === Message::CMD_ACK && !$iReported) {
            $iReported = true;
            echo "[I] <- ack（UDP 应用层会话已建立）\n";

            Timer::add(0.6, function () use ($uidI, $msgIdI) {
                Push::enqueue('uid', $uidI, array('case' => 'I', 'value' => 'udp-push'), array(
                    'msg_id'       => $msgIdI,
                    'offline_mode' => 'drop',
                    'source'       => 'e2e',
                ));
                echo "[I] -> 推送任务已提交（uid 目标，期望经 UDP 出站通道下发）\n";
            }, array(), false);
            return;
        }

        if ($packet['cmd'] === Message::CMD_PUSH) {
            $receivedId = isset($packet['msg_id']) ? (string)$packet['msg_id'] : '';
            if ($receivedId !== $msgIdI) {
                $state['I']     = false;
                $state['I_msg'] = "UDP 补投 msg_id 不一致（期望 {$msgIdI}，实际 {$receivedId}）";
            } else {
                $state['I'] = true;
            }
            echo "[I] <- push（UDP 出站通道打通）\n";
            $con->close();
            $finish();
        }
    };

    $udpI->connect();

    /* ================= 用例 J：指令路由表（data.action 分发） ================= */
    // 验证 handleData 已由「空壳回显」改为按 data.action 查表分发：
    //   1) action=echo     -> ack，回显 params
    //   2) action=session  -> ack，返回本连接会话摘要
    //   3) 未注册 action    -> 4006 未知指令
    //   4) 缺 action        -> 4007 缺少参数
    $connJ = new AsyncTcpConnection($wsAddress);
    $jStep = 0;

    $connJ->onConnect = function ($con) use ($uidJ, $deviceJ, $tokenJ, $secret) {
        echo "[J] WebSocket 已连接\n";
        $con->send(Message::encode(buildPacket(Message::CMD_AUTH, 'j-auth-1', array(
            'uid'       => $uidJ,
            'device_id' => $deviceJ,
            'token'     => $tokenJ,
        ), $secret)));
        echo "[J] -> auth\n";
    };

    $connJ->onMessage = function ($con, $raw) use (&$state, &$jStep, $finish, $secret, $uidJ, $deviceJ) {
        $packet = json_decode($raw, true);
        if (!is_array($packet) || !isset($packet['cmd'])) {
            return;
        }

        $fail = function ($msg) use (&$state, $con, $finish) {
            $state['J']     = false;
            $state['J_msg'] = $msg;
            $con->close();
            $finish();
        };

        switch ($jStep) {
            case 0:   // 等待鉴权 ack
                if ($packet['cmd'] !== Message::CMD_ACK) {
                    $fail('鉴权阶段返回 ' . $packet['cmd']);
                    return;
                }
                $jStep = 1;
                $con->send(Message::encode(buildPacket(Message::CMD_DATA, 'j-echo-1', array(
                    'uid'       => $uidJ,
                    'device_id' => $deviceJ,
                    'data'      => array('action' => 'echo', 'params' => array('k' => 'v', 'n' => 1)),
                ), $secret)));
                echo "[J] -> data / action=echo\n";
                return;

            case 1:   // 期望 echo 回显
                $action = isset($packet['data']['action']) ? (string)$packet['data']['action'] : '';
                $params = isset($packet['data']['params']) ? $packet['data']['params'] : array();
                if ($packet['cmd'] !== Message::CMD_ACK
                    || $action !== 'echo'
                    || !is_array($params)
                    || !isset($params['k']) || (string)$params['k'] !== 'v') {
                    $fail('echo 回显异常：' . $raw);
                    return;
                }
                $jStep = 2;
                $con->send(Message::encode(buildPacket(Message::CMD_DATA, 'j-session-1', array(
                    'uid'       => $uidJ,
                    'device_id' => $deviceJ,
                    'data'      => array('action' => 'session'),
                ), $secret)));
                echo "[J] -> data / action=session\n";
                return;

            case 2:   // 期望会话摘要
                $action = isset($packet['data']['action']) ? (string)$packet['data']['action'] : '';
                $uidGot = isset($packet['data']['uid']) ? (string)$packet['data']['uid'] : '';
                $proto  = isset($packet['data']['protocol']) ? (string)$packet['data']['protocol'] : '';
                if ($packet['cmd'] !== Message::CMD_ACK || $action !== 'session'
                    || $uidGot !== $uidJ || $proto !== 'ws') {
                    $fail(sprintf('session 摘要异常：action=%s uid=%s protocol=%s', $action, $uidGot, $proto));
                    return;
                }
                $jStep = 3;
                $con->send(Message::encode(buildPacket(Message::CMD_DATA, 'j-unknown-1', array(
                    'uid'       => $uidJ,
                    'device_id' => $deviceJ,
                    'data'      => array('action' => 'no_such_action'),
                ), $secret)));
                echo "[J] -> data / action=no_such_action（期望 4006）\n";
                return;

            case 3:   // 期望未注册 action -> 4006
                $code = isset($packet['data']['code']) ? (int)$packet['data']['code'] : 0;
                if ($packet['cmd'] !== Message::CMD_ERROR || $code !== Message::CODE_UNKNOWN_CMD) {
                    $fail(sprintf('未注册 action 未被拒绝（cmd=%s code=%d，期望 error/4006）', $packet['cmd'], $code));
                    return;
                }
                $jStep = 4;
                $con->send(Message::encode(buildPacket(Message::CMD_DATA, 'j-noaction-1', array(
                    'uid'       => $uidJ,
                    'device_id' => $deviceJ,
                    'data'      => array('params' => array('x' => 1)),
                ), $secret)));
                echo "[J] -> data / 缺 action（期望 4007）\n";
                return;

            case 4:   // 期望缺 action -> 4007
                $code = isset($packet['data']['code']) ? (int)$packet['data']['code'] : 0;
                if ($packet['cmd'] !== Message::CMD_ERROR || $code !== Message::CODE_PARAM_MISSING) {
                    $fail(sprintf('缺 action 未返回 4007（cmd=%s code=%d）', $packet['cmd'], $code));
                    return;
                }
                echo "[J] <- 4006 / 4007 分支均按预期返回\n";
                $state['J'] = true;
                $con->close();
                $finish();
                return;
        }
    };

    $connJ->onClose = function () use (&$state, $finish) {
        if ($state['J'] === 'pending') {
            $state['J']     = false;
            $state['J_msg'] = '流程完成前连接被关闭';
            $finish();
        }
    };

    $connJ->connect();

    /* ================= 用例 K：UDP 离线补投 ================= */
    // 阶段一：uidK 无任何在线连接时提交，应落入 push:offline:{uidK}
    Push::enqueue('uid', $uidK, array('case' => 'K', 'value' => 'udp-offline'), array(
        'msg_id'       => $msgIdK,
        'offline_mode' => 'queue',
        'source'       => 'e2e',
    ));
    echo "[K] -> UDP 离线推送任务已提交（目标无在线连接，预期落入离线列表）\n";

    $udpK      = new AsyncUdpConnection($udpAddress);
    $kReported = false;
    $kAttempt  = 0;

    // 阶段二：UDP 客户端上报 -> 会话重建 -> 业务进程补投（经出站队列 sendto 回客户端）
    $sendReportK = function () use ($udpK, &$kAttempt, &$kReported, $uidK, $deviceK, $tokenK, $secret) {
        if ($kAttempt >= 3 || $kReported) {
            return;
        }
        $kAttempt++;
        $udpK->send(Message::encode(buildPacket(Message::CMD_DATA, 'k-udp-' . $kAttempt, array(
            'uid'       => $uidK,
            'device_id' => $deviceK,
            'token'     => $tokenK,
            'data'      => array('type' => 'udp-offline-report'),
        ), $secret)));
        echo "[K] -> 上报报文（第 {$kAttempt} 次，重建 UDP 会话以触发补投）\n";

        Timer::add(1.0, function () use (&$sendReportK, &$kReported) {
            if (!$kReported) {
                $sendReportK();
            }
        }, array(), false);
    };

    $udpK->onConnect = function ($con) use ($sendReportK, &$state, $finish) {
        echo "[K] UDP 通道已就绪\n";

        // 延迟上报：先留出时间让业务进程把首个推送任务写入离线列表；
        // 若会话先建立，任务会走在线直投（offline=0），用例即失去意义。
        Timer::add(1.5, $sendReportK, array(), false);

        Timer::add(9.0, function () use (&$state, $finish) {
            if ($state['K'] === 'pending') {
                $state['K']     = false;
                $state['K_msg'] = 'UDP 离线补投未在 9 秒内到达客户端';
                $finish();
            }
        }, array(), false);
    };

    $udpK->onMessage = function ($con, $raw) use (&$state, &$kReported, $finish, $msgIdK) {
        $packet = json_decode($raw, true);
        if (!is_array($packet) || !isset($packet['cmd'])) {
            return;
        }

        if ($packet['cmd'] === Message::CMD_ACK) {
            $kReported = true;
            echo "[K] <- ack（UDP 会话已重建，等待离线补投）\n";
            return;
        }

        if ($packet['cmd'] === Message::CMD_PUSH) {
            $receivedId = isset($packet['msg_id']) ? (string)$packet['msg_id'] : '';
            $isOffline  = isset($packet['offline']) ? (int)$packet['offline'] : 0;

            if ($receivedId !== $msgIdK) {
                $state['K']     = false;
                $state['K_msg'] = "补投 msg_id 不一致（期望 {$msgIdK}，实际 {$receivedId}）";
            } elseif ($isOffline !== 1) {
                $state['K']     = false;
                $state['K_msg'] = '补投报文未标记 offline=1';
            } else {
                $state['K'] = true;
                echo "[K] <- push（offline=1，UDP 离线补投通道打通）\n";
            }
            $con->close();
            $finish();
        }
    };

    $udpK->connect();

    /* ================= 用例 L：报文级限流（令牌桶） ================= */
    //
    // 验证思路：单连接在极短时间内连发远超桶容量的报文，期望
    //   - 桶内报文被正常处理（echo 回执，证明「不误伤」）
    //   - 超出部分被 4008 拒绝（证明「确实限流」）
    // 两个条件同时成立才算通过：只拒绝不放行说明配额过严，
    // 只放行不拒绝说明限流失效。
    //
    // 本方使用独立 uid/device，避免污染其它用例的令牌桶。
    $connL = new AsyncTcpConnection($wsAddress);

    $lSent  = 100;   // 连发条数，需显著超过 conn 维度 burst（默认 40）
    $lAck   = 0;     // 通过限流的 echo 回执数
    $lLimit = 0;     // 被 4008 拒绝数

    $connL->onConnect = function ($con) use ($uidL, $deviceL, $tokenL, $secret) {
        echo "[L] WebSocket 已连接，发送鉴权\n";
        $con->send(Message::encode(buildPacket(Message::CMD_AUTH, 'L-auth', array(
            'uid'       => $uidL,
            'device_id' => $deviceL,
            'token'     => $tokenL,
        ), $secret)));
    };

    $connL->onMessage = function ($con, $raw) use (
        &$state, &$lAck, &$lLimit, $finish, $lSent, $secret, $uidL, $deviceL, $tokenL
    ) {
        $packet = json_decode($raw, true);
        if (!is_array($packet) || !isset($packet['cmd'])) {
            return;
        }

        // 鉴权回执到达后立即连发，制造瞬时突发
        if ($packet['cmd'] === Message::CMD_ACK && $packet['seq'] === 'L-auth') {
            echo "[L] 鉴权成功，连发 {$lSent} 条 data 报文以触发限流\n";

            for ($i = 1; $i <= $lSent; $i++) {
                $con->send(Message::encode(buildPacket(Message::CMD_DATA, 'L-' . $i, array(
                    'uid'       => $uidL,
                    'device_id' => $deviceL,
                    'token'     => $tokenL,
                    'data'      => array('action' => 'echo', 'params' => array('i' => $i)),
                ), $secret)));
            }

            // 限流判定与回执均为异步，留出收集窗口
            Timer::add(2.5, function () use (&$state, &$lAck, &$lLimit, $finish, $lSent, $con) {
                if ($lLimit <= 0) {
                    $state['L_msg'] = "连发 {$lSent} 条未触发任何限流拒绝（通过 {$lAck} 条）";
                    $state['L']     = false;
                } elseif ($lAck <= 0) {
                    $state['L_msg'] = "全部报文被拒绝（拒绝 {$lLimit} 条），配额可能配置过严";
                    $state['L']     = false;
                } else {
                    $state['L'] = true;
                }
                echo "[L] 限流统计：放行 {$lAck} 条，拒绝 {$lLimit} 条\n";
                $con->close();
                call_user_func($finish);
            }, array(), false);
            return;
        }

        // 业务报文回执（seq 形如 L-<n>）
        if (!preg_match('/^L-\d+$/', (string)$packet['seq'])) {
            return;
        }

        if ($packet['cmd'] === Message::CMD_ACK
            && isset($packet['data']['action'])
            && $packet['data']['action'] === 'echo'
        ) {
            $lAck++;
            return;
        }

        if ($packet['cmd'] === Message::CMD_ERROR
            && isset($packet['data']['code'])
            && (int)$packet['data']['code'] === Message::CODE_RATE_LIMIT
        ) {
            $lLimit++;
            return;
        }

        $state['L_msg'] = '收到非预期回执：' . substr((string)$raw, 0, 120);
        $state['L']     = false;
        call_user_func($finish);
    };

    $connL->connect();

    /* ================= 用例 M：业务动作契约 ================= */
    // 验证声明式动作清单（config/actions.php）的执行语义：
    //   1) echo  params='*' 透传            -> ack，回显 params 且带 channel 字段
    //   2) report 合法入参                  -> ack，返回 accepted / total
    //   3) report 缺 topic（required）      -> 4007
    //   4) report topic 含非法字符          -> 4007
    //   5) action 未注册                    -> 4006
    //   6) data 缺 action                   -> 4007
    // 末步回查 Redis，确认 report 确实已写入（回执与落库一致）
    $connM = new AsyncTcpConnection($wsAddress);
    $mStep = 0;

    $connM->onConnect = function ($con) use ($uidM, $deviceM, $tokenM, $secret) {
        echo "[M] WebSocket 已连接\n";
        $con->send(Message::encode(buildPacket(Message::CMD_AUTH, 'm-auth-1', array(
            'uid'       => $uidM,
            'device_id' => $deviceM,
            'token'     => $tokenM,
        ), $secret)));
        echo "[M] -> auth\n";
    };

    $connM->onMessage = function ($con, $raw) use (&$state, &$mStep, $finish, $secret, $uidM, $deviceM, $topicM) {
        $packet = json_decode($raw, true);
        if (!is_array($packet) || !isset($packet['cmd'])) {
            return;
        }

        $data = isset($packet['data']) && is_array($packet['data']) ? $packet['data'] : array();
        $code = isset($data['code']) ? (int)$data['code'] : -1;
        $act  = isset($data['action']) ? (string)$data['action'] : '';

        $fail = function ($msg) use (&$state, $con, $finish) {
            $state['M']     = false;
            $state['M_msg'] = $msg;
            $con->close();
            $finish();
        };
        $send = function ($seq, array $dataBody) use ($con, $secret, $uidM, $deviceM) {
            $con->send(Message::encode(buildPacket(Message::CMD_DATA, $seq, array(
                'uid'       => $uidM,
                'device_id' => $deviceM,
                'data'      => $dataBody,
            ), $secret)));
        };

        switch ($mStep) {
            case 0:
                if ($packet['cmd'] !== Message::CMD_ACK) {
                    $fail('鉴权阶段返回 ' . $packet['cmd']);
                    return;
                }
                $mStep = 1;
                $send('m-echo-1', array(
                    'action' => 'echo',
                    'params' => array('k' => 'v', 'n' => 1, 'deep' => array('a' => 1)),
                ));
                echo "[M] -> data/action=echo（params 透传）\n";
                return;

            case 1:
                if ($packet['cmd'] !== Message::CMD_ACK
                    || $act !== 'echo'
                    || (isset($data['channel']) ? (string)$data['channel'] : '') !== 'ws'
                    || !isset($data['params']['k']) || (string)$data['params']['k'] !== 'v'
                    || !isset($data['params']['deep']['a'])) {
                    $fail('echo 回显异常（期望透传含 channel=ws）：' . $raw);
                    return;
                }
                $mStep = 2;
                $send('m-report-1', array(
                    'action' => 'report',
                    'params' => array('topic' => $topicM, 'count' => 3),
                ));
                echo "[M] -> data/action=report（合法入参）\n";
                return;

            case 2:
                if ($packet['cmd'] !== Message::CMD_ACK || $act !== 'report'
                    || (int)(isset($data['accepted']) ? $data['accepted'] : 0) !== 3) {
                    $fail('report 回执异常：' . $raw);
                    return;
                }
                $mStep = 3;
                $send('m-report-bad-1', array('action' => 'report', 'params' => array('count' => 1)));
                echo "[M] -> data/action=report（缺 topic，期望 4007）\n";
                return;

            case 3:
                if ($packet['cmd'] !== Message::CMD_ERROR || $code !== Message::CODE_PARAM_MISSING) {
                    $fail(sprintf('缺 required 未返回 4007：cmd=%s code=%d', $packet['cmd'], $code));
                    return;
                }
                $mStep = 4;
                $send('m-report-bad-2', array(
                    'action' => 'report',
                    'params' => array('topic' => 'bad topic!'),
                ));
                echo "[M] -> data/action=report（topic 非法字符，期望 4007）\n";
                return;

            case 4:
                if ($packet['cmd'] !== Message::CMD_ERROR || $code !== Message::CODE_PARAM_MISSING) {
                    $fail(sprintf('非法 topic 未被拦截：cmd=%s code=%d', $packet['cmd'], $code));
                    return;
                }
                $mStep = 5;
                $send('m-unknown-1', array('action' => 'no_such_action'));
                echo "[M] -> data/action=no_such_action（期望 4006）\n";
                return;

            case 5:
                if ($packet['cmd'] !== Message::CMD_ERROR || $code !== Message::CODE_UNKNOWN_CMD) {
                    $fail(sprintf('未知动作未返回 4006：cmd=%s code=%d', $packet['cmd'], $code));
                    return;
                }
                $mStep = 6;
                $send('m-noaction-1', array());
                echo "[M] -> data（缺 action，期望 4007）\n";
                return;

            case 6:
                if ($packet['cmd'] !== Message::CMD_ERROR || $code !== Message::CODE_PARAM_MISSING) {
                    $fail(sprintf('缺 action 未返回 4007：cmd=%s code=%d', $packet['cmd'], $code));
                    return;
                }

                // 回执与落库一致性：report 的回执称已受理 3 条，Redis 计数须相符
                $key = RedisClient::key('action:report:' . $topicM);
                RedisClient::connection()->hGet($key, 'count', function ($count) use (&$state, $con, $finish, $topicM) {
                    $ok = is_numeric($count) && (int)$count >= 3;
                    $state['M'] = $ok;
                    if (!$ok) {
                        $state['M_msg'] = sprintf(
                            'report 回执成功但 Redis 计数不符（topic=%s，实际 %s）',
                            $topicM,
                            var_export($count, true)
                        );
                    }
                    echo $ok
                        ? "[M] 上报计数已落库（count={$count}）\n"
                        : "[M] 上报计数未落库\n";
                    $con->close();
                    $finish();
                });
                return;
        }
    };

    $connM->connect();

    /* ================= 用例 N：UDP 通道业务动作 ================= */
    // 本轮核心：验证 UDP 上报的业务报文能走与 WS 完全一致的动作分发。
    //   1) echo  在 UDP 上声明 sync -> 经出站队列收到 ack（证明回执通道打通）
    //   2) report 在 UDP 上声明 none -> 静默处理，不回任何报文
    //   3) 静默 ≠ 不处理：回查 Redis 确认计数已写入
    //
    // 「静默」的判定方式：先发 report 再发 echo#2，若收到 seq 属于 report 的
    // 任何回执即判失败 —— 这比单纯等待超时更精确。
    $udpN = new AsyncUdpConnection($udpAddress);
    $nEcho1Acked = false;

    $udpN->onConnect = function ($con) use ($secret, $uidN, $deviceN, $tokenN, $nEchoSeq1, &$nEcho1Acked) {
        echo "[N] UDP 通道已就绪\n";

        // 延迟首包 + 应用层重传：规避 UDP 首个报文在 socket 就绪前的静默丢失
        $attempt = function ($n) use ($con, $secret, $uidN, $deviceN, $tokenN, $nEchoSeq1, &$nEcho1Acked, &$attempt) {
            if ($nEcho1Acked || $n > 4) {
                return;
            }
            $con->send(Message::encode(buildPacket(Message::CMD_DATA, $nEchoSeq1, array(
                'uid'       => $uidN,
                'device_id' => $deviceN,
                'token'     => $tokenN,
                'data'      => array('action' => 'echo', 'params' => array('phase' => 1)),
            ), $secret)));
            echo "[N] -> data/action=echo#1（建立 UDP 应用层会话，第 {$n} 次）\n";

            Timer::add(1.2, function () use ($n, &$attempt) {
                $attempt($n + 1);
            }, array(), false);
        };

        Timer::add(0.2, function () use (&$attempt) {
            $attempt(1);
        }, array(), false);
    };

    $udpN->onMessage = function ($con, $raw) use (
        &$state, $finish, $secret, $uidN, $deviceN, $tokenN,
        $nEchoSeq1, $nEchoSeq2, $nReportSeq, $topicN, &$nEcho1Acked
    ) {
        $packet = json_decode($raw, true);
        if (!is_array($packet) || !isset($packet['cmd'])) {
            return;
        }

        $seq  = isset($packet['seq']) ? (string)$packet['seq'] : '';
        $data = isset($packet['data']) && is_array($packet['data']) ? $packet['data'] : array();

        // UDP 上存在两层回执，必须区分，否则会把传输层 ack 误判为业务回执：
        //   传输层 —— UDP 网关收到合法报文即回 ack（data 为空、不含 action 字段），
        //             属于「收包确认」，与业务动作的回执策略无关
        //   业务层 —— 动作执行结果，按 config/actions.php 的 reply 声明发放
        $isActionReply = isset($data['action']);

        $fail = function ($msg) use (&$state, $con, $finish) {
            $state['N']     = false;
            $state['N_msg'] = $msg;
            $con->close();
            $finish();
        };

        // 静默策略校验：只有「业务层回执」才违反 report 在 UDP 上的 none 声明，
        // 传输层 ack 恒定存在，不算违规。
        if ($seq === $nReportSeq) {
            if ($isActionReply) {
                $fail('report 在 UDP 上声明为静默，却收到业务层回执：' . substr((string)$raw, 0, 120));
            }
            return;
        }

        if ($seq === $nEchoSeq1) {
            if (!$isActionReply) {
                return;   // 传输层 ack：收包成功，继续等待业务层回执
            }
            if ($nEcho1Acked) {
                return;   // 忽略重传产生的重复业务回执
            }
            $nEcho1Acked = true;

            if ($packet['cmd'] !== Message::CMD_ACK
                || (string)$data['action'] !== 'echo'
                || (isset($data['channel']) ? (string)$data['channel'] : '') !== 'udp') {
                $fail('UDP echo 业务回执异常（期望 ack 且 channel=udp）：' . $raw);
                return;
            }
            echo "[N] <- echo#1 业务回执（UDP 动作回执通道打通，channel=udp）\n";

            // 连发 3 份上报以容忍 UDP 丢包，count 各计 1
            for ($i = 0; $i < 3; $i++) {
                $con->send(Message::encode(buildPacket(Message::CMD_DATA, $nReportSeq, array(
                    'uid'       => $uidN,
                    'device_id' => $deviceN,
                    'token'     => $tokenN,
                    'data'      => array('action' => 'report', 'params' => array('topic' => $topicN, 'count' => 1)),
                ), $secret)));
            }
            echo "[N] -> data/action=report ×3（UDP 声明静默，期望无任何回执）\n";

            // 延迟发 echo#2：若 report 违规回执，必然先于 echo#2 的 ack 到达
            Timer::add(0.8, function () use ($con, $secret, $uidN, $deviceN, $tokenN, $nEchoSeq2) {
                $con->send(Message::encode(buildPacket(Message::CMD_DATA, $nEchoSeq2, array(
                    'uid'       => $uidN,
                    'device_id' => $deviceN,
                    'token'     => $tokenN,
                    'data'      => array('action' => 'echo', 'params' => array('phase' => 2)),
                ), $secret)));
                echo "[N] -> data/action=echo#2（此刻前若收到 report 回执即为失败）\n";
            }, array(), false);
            return;
        }

        if ($seq === $nEchoSeq2) {
            if (!$isActionReply) {
                return;   // 传输层 ack，继续等待业务层回执
            }
            if ($packet['cmd'] !== Message::CMD_ACK) {
                $fail('echo#2 未返回业务回执：' . $raw);
                return;
            }
            echo "[N] <- echo#2 业务回执（确认期间未收到 report 业务回执）\n";

            // 静默不等于不处理：核对上报计数确实已写入 Redis
            $check = null;
            $check = function ($attempt) use (&$check, &$state, $con, $finish, $topicN) {
                $key = RedisClient::key('action:report:' . $topicN);
                RedisClient::connection()->hGet($key, 'count', function ($count) use (&$check, &$state, $con, $finish, $attempt, $topicN) {
                    $ok = is_numeric($count) && (int)$count >= 1;

                    if ($ok || $attempt >= 3) {
                        $state['N'] = $ok;
                        if (!$ok) {
                            $state['N_msg'] = sprintf(
                                'report 静默执行但计数未写入（topic=%s，实际 %s）',
                                $topicN,
                                var_export($count, true)
                            );
                        }
                        echo $ok
                            ? "[N] 静默上报计数已落库（count={$count}）\n"
                            : "[N] 静默上报计数未落库\n";
                        $con->close();
                        $finish();
                        return;
                    }

                    Timer::add(0.4, function () use (&$check, $attempt) {
                        $check($attempt + 1);
                    }, array(), false);
                });
            };
            $check(1);
            return;
        }
    };

    $udpN->connect();

    /* ================= 用例 O：订阅与广播闭环 ================= */
    // subscribe -> Push::enqueueTopic -> 收到 push -> topics 校验 -> unsubscribe
    $connO = new AsyncTcpConnection($wsAddress);
    $oStep = 0;

    $connO->onConnect = function ($con) use ($uidO, $deviceO, $tokenO, $secret) {
        echo "[O] WebSocket 已连接\n";
        $con->send(Message::encode(buildPacket(Message::CMD_AUTH, 'o-auth-1', array(
            'uid'       => $uidO,
            'device_id' => $deviceO,
            'token'     => $tokenO,
        ), $secret)));
        echo "[O] -> auth\n";
    };

    $connO->onMessage = function ($con, $raw) use (&$state, &$oStep, $finish, $secret, $uidO, $deviceO, $topicO) {
        $packet = json_decode($raw, true);
        if (!is_array($packet) || !isset($packet['cmd'])) {
            return;
        }

        $data   = isset($packet['data']) && is_array($packet['data']) ? $packet['data'] : array();
        $act    = isset($data['action']) ? (string)$data['action'] : '';

        $fail = function ($msg) use (&$state, $con, $finish) {
            $state['O']     = false;
            $state['O_msg'] = $msg;
            $con->close();
            $finish();
        };
        $send = function ($seq, array $dataBody) use ($con, $secret, $uidO, $deviceO) {
            $con->send(Message::encode(buildPacket(Message::CMD_DATA, $seq, array(
                'uid'       => $uidO,
                'device_id' => $deviceO,
                'data'      => $dataBody,
            ), $secret)));
        };

        switch ($oStep) {
            case 0:
                if ($packet['cmd'] !== Message::CMD_ACK) {
                    $fail('鉴权阶段返回 ' . $packet['cmd']);
                    return;
                }
                $oStep = 1;
                $send('o-sub-1', array('action' => 'subscribe', 'params' => array('topic' => $topicO)));
                echo "[O] -> data/action=subscribe topic={$topicO}\n";
                return;

            case 1:
                if ($packet['cmd'] !== Message::CMD_ACK || $act !== 'subscribe'
                    || (int)(isset($data['subscribers']) ? $data['subscribers'] : 0) < 1) {
                    $fail('订阅回执异常：' . $raw);
                    return;
                }
                $oStep = 2;
                echo "[O] <- 订阅成功（订阅者 {$data['subscribers']}），触发主题广播\n";

                // 由测试进程侧调用广播入口，模拟外部系统按主题投递
                Push::enqueueTopic($topicO, array('case' => 'O', 'hello' => 'world'), array('source' => 'e2e'));
                return;

            case 2:
                if ($packet['cmd'] !== Message::CMD_PUSH) {
                    $fail('未收到主题广播推送，实际 ' . $packet['cmd']);
                    return;
                }
                if (!isset($data['hello']) || (string)$data['hello'] !== 'world') {
                    $fail('广播报文内容不符：' . substr((string)$raw, 0, 120));
                    return;
                }
                $oStep = 3;
                $send('o-topics-1', array('action' => 'topics'));
                echo "[O] <- 收到主题广播；查询订阅列表\n";
                return;

            case 3:
                $topics = isset($data['topics']) && is_array($data['topics']) ? $data['topics'] : array();
                if ($packet['cmd'] !== Message::CMD_ACK || $act !== 'topics'
                    || !in_array($topicO, $topics, true)) {
                    $fail('订阅列表未包含 ' . $topicO . '：' . substr((string)$raw, 0, 120));
                    return;
                }
                $oStep = 4;
                $send('o-unsub-1', array('action' => 'unsubscribe', 'params' => array('topic' => $topicO)));
                echo "[O] -> data/action=unsubscribe\n";
                return;

            case 4:
                if ($packet['cmd'] !== Message::CMD_ACK || $act !== 'unsubscribe') {
                    $fail('取消订阅回执异常：' . $raw);
                    return;
                }
                $oStep = 5;
                $send('o-topics-2', array('action' => 'topics'));
                echo "[O] -> data/action=topics（校验已移除）\n";
                return;

            case 5:
                $topics = isset($data['topics']) && is_array($data['topics']) ? $data['topics'] : array();
                if (in_array($topicO, $topics, true)) {
                    $fail('取消订阅后主题仍在列表中');
                    return;
                }
                $state['O'] = true;
                echo "[O] 订阅 -> 广播 -> 取消 闭环完成\n";
                $con->close();
                $finish();
                return;
        }
    };

    $connO->connect();

    /* ================= 超时保护 ================= */
    Timer::add($timeout, function () use (&$state, $timeout) {
        echo "\n[超时] 用例未在 {$timeout} 秒内全部完成。当前状态：\n";
        foreach (array('A', 'B', 'C', 'D', 'E', 'F', 'G', 'I', 'J', 'K', 'L', 'M', 'N', 'O') as $key) {
            echo "  {$key}: " . ($state[$key] === 'pending' ? '未完成' : var_export($state[$key], true)) . "\n";
        }
        exit(1);
    }, array(), false);
};

Worker::runAll();
