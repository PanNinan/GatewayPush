<?php
/**
 * GatewayWorker 客户端 SDK —— 完整功能示例（P0 ~ P6）
 * ====================================================================
 * 前置条件：
 *   1. 服务端五角色已起（本地开发，Redis 走 DB9）：
 *        php start.php start --role=register
 *        php start.php start --role=gateway
 *        php start.php start --role=business
 *        php start.php start --role=udp
 *        php start.php start --role=api
 *   2. 下方常量与服务端 config/app.php 保持一致：
 *        AUTH_SECRET = app.auth.secret
 *        API_SECRET  = app.api.secret
 *
 * 运行：
 *   php client/examples/all_features_demo.php
 *
 * 说明：
 *   本示例运行在 workerman 事件循环内（SessionManager / AdminApi 均依赖事件循环）。
 *   整条链路为异步串联：WS 就绪 → 7 动作 → 推送自动回执 → UDP 通道 → HTTP 管理端 → 退出。
 *   P5「重连+离线补投」需中途停网关（见 runtime/_p5_run.sh），此处仅开启能力并标注观察方式。
 * ====================================================================
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use GatewayPush\Client\Error\ClientException;
use GatewayPush\Client\Event\PushReceiver;
use GatewayPush\Client\Protocol\Codec;
use GatewayPush\Client\Protocol\Signer;
use GatewayPush\Client\Protocol\TokenIssuer;
use GatewayPush\Client\Service\AdminApi;
use GatewayPush\Client\Service\EchoApi;
use GatewayPush\Client\Service\NotifyApi;
use GatewayPush\Client\Service\ReportApi;
use GatewayPush\Client\Service\SessionApi;
use GatewayPush\Client\Service\SubscribeApi;
use GatewayPush\Client\Session\SessionManager;
use GatewayPush\Client\Transport\UdpTransport;
use GatewayPush\Client\Transport\WsTransport;
use Workerman\Timer;
use Workerman\Worker;

// ----------------------------- 配置 -----------------------------
// 服务端地址（与服务端 config/gateway.php / config/api.php 一致）
define('WS_URL',  'ws://127.0.0.1:8282');
define('UDP_URL', 'udp://127.0.0.1:8283');
define('API_URL', 'http://127.0.0.1:8290');

// 自动读取项目根 .env 的密钥（与服务端同一套）；缺省回退占位符，此时 auth 会失败需自行填写
$env = loadEnv(__DIR__ . '/../../.env');
define('AUTH_SECRET', $env['AUTH_SECRET'] ?? 'AUTH_SECRET');
define('API_SECRET',  $env['API_SECRET'] ?: ($env['AUTH_SECRET'] ?? 'API_SECRET'));
if (AUTH_SECRET === 'AUTH_SECRET') {
    fwrite(STDERR, "警告：未从 .env 读到 AUTH_SECRET，auth 将失败。请填写真实密钥或配置 .env。\n");
}

define('UID',       'demo-user');
define('DEVICE_ID', 'demo-device');

/**
 * 极简 .env 解析（不依赖服务端 Env 类，保持示例自包含）
 * @return array<string,string>
 */
function loadEnv(string $path): array
{
    $out = [];
    if (!is_file($path)) {
        return $out;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $m)) {
            $out[$m[1]] = trim($m[2], "\"'");
        }
    }
    return $out;
}

/**
 * 统一日志（带步骤前缀）
 */
function logLine(string $tag, string $msg): void
{
    echo sprintf("[%-6s] %s\n", $tag, $msg);
    flush();          // 管道/重定向下即时输出，避免块缓冲吞日志
    fflush(STDOUT);
}

// ====================================================================
// P0 协议层（纯计算，无 IO，可在任意位置调用）
// ====================================================================
function demoProtocol(): void
{
    logLine('P0', '协议层：Token 签发 / 校验 / 报文构造 / 签名');

    // 1) 签发 Token（联调用；生产环境 Token 通常由业务系统签发）
    $issuer = new TokenIssuer(AUTH_SECRET, 7200);
    $token  = $issuer->issue(['uid' => UID, 'device_id' => DEVICE_ID], 600);
    logLine('P0', 'issue token = ' . substr($token, 0, 24) . '...');

    // 2) 连接前本地预校验，避免「连上去才发现 Token 已过期」
    $info = $issuer->inspect($token);   // ['ok','code','msg','claims']
    logLine('P0', 'inspect ok=' . var_export($info['ok'], true) . ' uid=' . ($info['claims']['uid'] ?? '-'));

    // 3) 手工构造业务指令报文并签名（WS 可省，UDP 必需；客户端一律带）
    $packet          = Codec::dataPacket('echo', ['hello' => 'world']);
    $packet['uid']   = UID;
    $packet['device_id'] = DEVICE_ID;
    $packet['token'] = $token;
    $packet['seq']   = '1';
    $packet['ts']    = time();
    $packet['sign']  = Signer::sign($packet, AUTH_SECRET);
    $frame           = Codec::encode($packet);
    logLine('P0', '签名=' . substr($packet['sign'], 0, 16) . '... 帧长=' . strlen($frame) . 'B');
}

// ====================================================================
// P1 + P2 + P5  WS 会话 / 7 动作 / 推送接收 / 重连与离线补投
// ====================================================================
function demoWs(callable $next): void
{
    logLine('P1', 'WS 会话：建连 → 鉴权 → 心跳 → 业务请求 → 推送接收');

    $transport = new WsTransport(WS_URL);
    $session   = new SessionManager([
        'uid'       => UID,
        'device_id' => DEVICE_ID,
        'secret'    => AUTH_SECRET,
        'heartbeat' => 20,       // 主动 ping 间隔（秒），0 = 关闭
        'timeout'   => 5.0,       // 单次请求超时（秒）
        'reconnect' => true,      // P5：断线指数退避重连 + 自动重鉴权
        'auto_auth' => true,      // 握手完成立即抢发 auth（15s 窗口内）
    ], $transport);

    // 状态迁移观察
    $session->onStateChange(function (string $new, string $old) {
        logLine('P1', "state {$old} -> {$new}");
    });
    $session->onError(function (ClientException $e) {
        logLine('ERR', 'session error: ' . $e->getMessage());
    });

    // P2 推送接收：构造即接管 onPush 分发，收到 push 自动回 cmd=ack
    $receiver = new PushReceiver($session);
    $receiver->onPush(function (array $payload, array $meta) {
        logLine('P2', '收到 push meta.offline=' . $meta['offline']
            . ' msg_id=' . $meta['msg_id']
            . ' payload=' . json_encode($payload, JSON_UNESCAPED_UNICODE));
    });

    // 就绪后串联 7 个动作
    $session->onStateChange(function (string $new) use ($session, $receiver, $next) {
        static $fired = false;
        if ($new !== SessionManager::STATE_READY || $fired) {
            return;
        }
        $fired = true;

        $echo      = new EchoApi($session);
        $sessionApi = new SessionApi($session);
        $report    = new ReportApi($session);
        $subscribe = new SubscribeApi($session);
        $notify    = new NotifyApi($session);

        // ① echo（params 透传）
        $echo->send(['hello' => 'world'], function (bool $ok, $data, $error) use ($sessionApi, $report, $subscribe, $notify, $receiver) {
            logLine('P2', 'echo ' . ($ok ? 'ok params=' . json_encode($data['params'] ?? null)
                                        : 'FAIL ' . json_encode($error)));
            // ② session 会话摘要
            $sessionApi->get(function (bool $ok, $data, $error) use ($report, $subscribe, $notify, $receiver) {
                logLine('P2', 'session ' . ($ok ? 'ok uid=' . ($data['uid'] ?? '-')
                                               : 'FAIL ' . json_encode($error)));
                // ③ report（WS 同步回执）
                $report->report('metric.demo', 1, ['v' => 0.8], function (bool $ok, $data, $error) use ($subscribe, $notify, $receiver) {
                    logLine('P2', 'report ' . ($ok ? 'ok' : 'FAIL ' . json_encode($error)));
                    // ④ subscribe
                    $subscribe->subscribe('topic.demo', function (bool $ok, $data, $error) use ($subscribe, $notify, $receiver) {
                        logLine('P2', 'subscribe ' . ($ok ? 'ok' : 'FAIL ' . json_encode($error)));
                        // ⑤ topics 列表
                        $subscribe->topics(function (bool $ok, $data, $error) use ($subscribe, $notify, $receiver) {
                            logLine('P2', 'topics ' . ($ok ? 'ok ' . json_encode($data)
                                                          : 'FAIL ' . json_encode($error)));
                            // ⑥ unsubscribe
                            $subscribe->unsubscribe('topic.demo', function (bool $ok, $data, $error) use ($notify, $receiver) {
                                logLine('P2', 'unsubscribe ' . ($ok ? 'ok' : 'FAIL ' . json_encode($error)));
                                // ⑦ notify 触发对自身推送 → PushReceiver 自动回执（见上方 onPush）
                                $notify->notify(['hi' => 1], 'demo-msg-1', 'queue',
                                    function (bool $ok, $data, $error) use ($receiver) {
                                        logLine('P2', 'notify 受理 ' . ($ok ? 'ok' : 'FAIL ' . json_encode($error)));
                                        // 等推送下行抵达（异步）后打印自动回执数
                                        Timer::add(1.5, function () use ($receiver) {
                                            logLine('P2', '自动回执数 acked=' . $receiver->ackedCount());
                                        }, [], false);
                                    });
                            });
                        });
                    });
                });
            });
        });

        // P1 主动 ping（测 RTT）
        $session->ping(function (bool $ok, array $packet) use ($session) {
            if ($ok) {
                logLine('P1', 'ping RTT=' . $session->lastRtt() . 's');
            }
        });
    });

    $session->connect();

    // 1.5s 后（notify 推送已抵达）切换至 UDP 演示
    Timer::add(4.0, function () use ($next) {
        call_user_func($next);
    }, [], false);
}

// ====================================================================
// P3 UDP 通道（延迟首包 + 应用层重传 + 双层 ack 判别）
// ====================================================================
function demoUdp(callable $next): void
{
    logLine('P3', 'UDP 通道：延迟首包 + 重传 + 双层 ack');

    $transport = new UdpTransport(UDP_URL, [
        'first_send_delay'    => 0.2,  // 建连后延迟首包，规避首包静默丢失（硬约束⑩）
        'retransmit_interval' => 1.2,  // 应用层重传间隔
        'max_attempts'        => 4,    // 最大重传次数
    ]);
    $session = new SessionManager([
        'uid'       => UID . '-udp',   // 独立 uid，规避「设备绑定首胜」拦截（同 uid 换 device 报 4004）
        'device_id' => DEVICE_ID . '-udp',
        'secret'    => AUTH_SECRET,
        'heartbeat' => 0,
        'timeout'   => 5.0,
        'reconnect' => false,
        'auto_auth' => true,
    ], $transport);

    $session->onStateChange(function (string $new) use ($session, $transport, $next) {
        static $fired = false;
        if ($new !== SessionManager::STATE_READY || $fired) {
            return;
        }
        $fired = true;

        $echo   = new EchoApi($session);
        $report = new ReportApi($session);

        // auth 已在 auto_auth 完成；直接发业务
        $echo->send(['udp' => 1], function (bool $ok, $data, $error) use ($report, $transport, $session, $next) {
            logLine('P3', 'echo ' . ($ok ? 'ok' : 'FAIL ' . json_encode($error)));
            // report 在 UDP 侧静默不回执（cb 只会收到本地超时，属预期）
            $report->report('metric.udp', 1, ['v' => 1], function (bool $ok, $data, $error) {
                logLine('P3', 'report ' . ($ok ? 'ok' : '超时(预期,UDP静默)'));
            });
            // 可观测计数
            Timer::add(0.5, function () use ($transport, $next) {
                logLine('P3', 'inflight=' . $transport->inflightCount()
                    . ' queued=' . $transport->queuedCount()
                    . ' dropped=' . $transport->droppedCount());
                // 进入 HTTP 管理端演示
                Timer::add(1.0, function () use ($next) {
                    call_user_func($next);
                }, [], false);
            }, [], false);
        });
    });

    $session->connect();
}

// ====================================================================
// P4 HTTP 管理端（/push /stats /health，HMAC 验签）
// ====================================================================
function demoAdmin(callable $next): void
{
    logLine('P4', 'HTTP 管理端：push / stats / health');

    $api = new AdminApi(API_URL, API_SECRET, 5.0);

    // ① health 免鉴权存活探测
    $api->health(function (bool $ok, $data, $error) {
        logLine('P4', 'health ' . ($ok ? 'ok' : 'FAIL ' . json_encode($error)));
    });

    // ② stats 指标快照
    $api->stats(function (bool $ok, $data, $error) {
        logLine('P4', 'stats ' . ($ok ? 'ok ' . json_encode($data)
                                   : 'FAIL ' . json_encode($error)));
    });

    // ③ push 经 HTTP 推送给自身 uid（WS 会话的 PushReceiver 会收到并自动回执）
    $api->push('uid', UID, ['title' => 'from-admin', 'body' => 'hello'], [
        'msg_id'       => 'demo-admin-1',
        'offline_mode' => 'queue',
    ], function (bool $ok, $data, $error) {
        $status = is_array($error) && isset($error['status']) ? $error['status'] : '-';
        logLine('P4', 'push ' . ($ok ? 'ok' : 'FAIL code=' . ($error['code'] ?? '-') . ' http=' . $status));
    });

    // 等推送下行抵达后收尾
    Timer::add(2.0, function () use ($next) {
        $next();
    }, [], false);
}

// ====================================================================
// 入口
// ====================================================================
$worker = new Worker();
$worker->onWorkerStart = function () {
    demoProtocol();
    demoWs(function () {
        demoUdp(function () {
            demoAdmin(function () {
                logLine('DONE', '全部功能演示完成，3 秒后退出');
                Timer::add(3.0, function () {
                    Worker::stopAll();
                }, [], false);
            });
        });
    });
};

Worker::runAll();
