<?php
/**
 * 客户端 SDK 端到端 —— 覆盖服务端 tests/e2e_check.php 的用例 A~O 共 15 个（服务端另有 P）
 *
 * 用法：
 *   php client/tests/E2E/ClientE2E.php [uid前缀]
 *
 * 前置：Redis 可用；register / gateway / udp / business / api 五角色已启动。
 *
 * 与服务端 e2e 的差异（客户端视角的必要调整）：
 *   - [B] 越权探测：服务端可裸连发报文，客户端 SDK 走「关闭 auto_auth 后直接 request」等价路径；
 *   - [D] 篡改签名：以错误 secret 签发 Token + 签名（等价服务端篡改 sign 字段）；
 *   - [H] 验签拒绝：服务端处于**免签模式**（API_SIGN_ENABLE=false 且监听回环地址）时，
 *         「错误签名必被拒」这条断言不成立，先探测再标 SKIP（与 tests/Api/api_sign_check.js、
 *         tests/Api/http_demo.php 口径一致），否则本地调试环境会一直误报失败；
 *   - [K] UDP 离线补投：UDP 无断连事件，改为「首次鉴权前先入队离线消息」再建会话补投；
 *   - [O] 订阅闭环：HTTP /push 仅支持 uid|device|client 三类目标，主题广播属服务端内部能力，
 *         客户端侧以「订阅关系 + 定向可达 + 退订」闭环对齐。
 *
 * 退出码：0 = 全部通过（SKIP 不算失败）
 */

define('BASE_PATH', dirname(__DIR__, 3));

require BASE_PATH . '/vendor/autoload.php';

use GatewayPush\Client\Event\PushReceiver;
use GatewayPush\Client\Protocol\Codec;
use GatewayPush\Client\Protocol\Signer;
use GatewayPush\Client\Protocol\TokenIssuer;
use GatewayPush\Client\Service\AdminApi;
use GatewayPush\Client\Session\SessionManager;
use GatewayPush\Client\Transport\UdpTransport;
use GatewayPush\Client\Transport\WsTransport;
use Workerman\Timer;
use Workerman\Worker;

$appConfig     = require BASE_PATH . '/config/app.php';
$gatewayConfig = require BASE_PATH . '/config/gateway.php';

$secret = (string)$appConfig['auth']['secret'];
$apiUrl = str_replace('0.0.0.0', '127.0.0.1', preg_replace('#^[a-z]+://#i', '', (string)$appConfig['api']['listen']));
$apiUrl = 'http://' . $apiUrl;
$wsUrl  = 'ws://' . str_replace('0.0.0.0', '127.0.0.1', preg_replace('#^[a-z]+://#i', '', (string)$gatewayConfig['websocket']['listen']));
$udpUrl = 'udp://' . str_replace('0.0.0.0', '127.0.0.1', preg_replace('#^[a-z]+://#i', '', (string)$gatewayConfig['udp']['listen']));

$prefix = $argv[1] ?? ('ce2e-' . substr(md5((string)microtime(true)), 0, 6));

echo "客户端 SDK 端到端自检（uid 前缀 {$prefix}）\n";
echo "ws={$wsUrl} udp={$udpUrl} api={$apiUrl}\n";

$results = [];
$pushes  = [];
$queue   = [];

/**
 * 取报文错误码：服务端 error 报文的 code 位于 data 内；本地超时 packet 为空（记 10001）
 *
 * @param mixed $packet
 * @param int   $default
 *
 * @return int
 */
function ce2eCode($packet, $default = 0)
{
    if (!is_array($packet) || $packet === []) {
        return 10001;
    }
    if (isset($packet['data']['code'])) {
        return (int)$packet['data']['code'];
    }
    if (isset($packet['code'])) {
        return (int)$packet['code'];
    }

    return $default;
}

/* -------------------------------------------------------------------------
 | 工具
 ------------------------------------------------------------------------- */

/**
 * 新建会话
 *
 * @param string $uid
 * @param string $device
 * @param string $proto  ws|udp
 * @param string $sec    密钥
 * @param array  $extra  覆盖配置
 *
 * @return SessionManager
 */
$newSession = function ($uid, $device, $proto, $sec, array $extra = []) use ($wsUrl, $udpUrl) {
    $transport = $proto === 'udp' ? new UdpTransport($udpUrl) : new WsTransport($wsUrl);

    return new SessionManager(array_merge([
        'uid'       => $uid,
        'device_id' => $device,
        'secret'    => $sec,
        'heartbeat' => 0,
        'timeout'   => 3.0,
        'reconnect' => false,
        'auto_auth' => true,
    ], $extra), $transport);
};

/**
 * 挂载推送收集器
 *
 * @param string         $key
 * @param SessionManager $session
 *
 * @return PushReceiver
 */
$attach = function ($key, SessionManager $session) use (&$pushes) {
    $pushes[$key] = [];
    $receiver     = new PushReceiver($session);
    $receiver->onPush(function (array $payload, array $meta) use ($key, &$pushes) {
        $pushes[$key][] = ['payload' => $payload, 'meta' => $meta];
    });

    return $receiver;
};

/**
 * 等待会话进入目标状态
 *
 * @param SessionManager $session
 * @param string         $target
 * @param callable       $ok
 * @param null|callable  $fail
 *
 * @return void
 */
$waitState = function (SessionManager $session, $target, callable $ok, ?callable $fail = null) {
    $waited  = 0.0;
    $timerId = null;
    $timerId = Timer::add(0.1, function () use (&$timerId, &$waited, $session, $target, $ok, $fail) {
        $waited += 0.1;
        if ($session->state() === $target) {
            Timer::del($timerId);
            $ok();

            return;
        }
        if ($waited > 12.0) {
            Timer::del($timerId);
            if ($fail !== null) {
                $fail();
            }
        }
    });
};

/**
 * 等待条件成立（轮询）
 *
 * @param callable $cond
 * @param float    $limit
 * @param callable $done  function(bool $ok)
 *
 * @return void
 */
$waitUntil = function (callable $cond, $limit, callable $done) {
    $waited  = 0.0;
    $timerId = null;
    $timerId = Timer::add(0.1, function () use (&$timerId, &$waited, $cond, $limit, $done) {
        $waited += 0.1;
        if ($cond()) {
            Timer::del($timerId);
            $done(true);

            return;
        }
        if ($waited > $limit) {
            Timer::del($timerId);
            $done(false);
        }
    });
};

/**
 * 记录结果
 *
 * @param string $id
 * @param string $label
 * @param bool   $ok
 * @param string $detail
 * @param bool   $skip   免签模式下不适用的断言：既不算通过也不算失败，显式标记
 *
 * @return void
 */
$record = function ($id, $label, $ok, $detail = '', $skip = false) use (&$results) {
    $results[$id] = ['label' => $label, 'ok' => $ok, 'detail' => $detail, 'skip' => $skip];
    $status       = $skip ? 'SKIP' : ($ok ? 'PASS' : 'FAIL');
    echo sprintf("[%s] %s %s\n", $status, $id, $label) . ($detail !== '' ? "      {$detail}\n" : '');
};

$admin = null;

/* -------------------------------------------------------------------------
 | 用例
 ------------------------------------------------------------------------- */

$worker = new Worker();

$worker->onWorkerStart = function () use (
    &$queue,
    &$results,
    &$pushes,
    $newSession,
    $attach,
    $waitState,
    $waitUntil,
    $record,
    &$admin,
    $apiUrl,
    $wsUrl,
    $udpUrl,
    $secret,
    $prefix
) {
    $admin = new AdminApi($apiUrl, $secret, 5.0);

    $run = null;
    $run = function () use (&$queue, &$run, &$results) {
        $step = array_shift($queue);
        if ($step === null) {
            echo "\n" . str_repeat('=', 62) . "\n";
            $fail    = 0;
            $skipped = 0;
            foreach ($results as $id => $r) {
                if (!empty($r['skip'])) {
                    $skipped++;
                } elseif (!$r['ok']) {
                    $fail++;
                }
                echo sprintf("  %-3s %-28s %s\n", $id, $r['label'], !empty($r['skip']) ? 'SKIP' : ($r['ok'] ? 'PASS' : 'FAIL'));
            }
            echo str_repeat('=', 62) . "\n";
            echo sprintf(
                "客户端 E2E 结论：%d 用例，失败 %d%s\n",
                count($results),
                $fail,
                $skipped > 0 ? sprintf('（另 SKIP %d 项，免签模式）', $skipped) : ''
            );

            exit($fail === 0 ? 0 : 1);
        }
        $step($run);
    };

    $uidWs  = $prefix . '-ws';
    $uidUdp = $prefix . '-udp';
    $ws     = null;
    $udp    = null;

    // [A] WS 正常链路：connect -> auth -> ping
    $queue[] = function ($next) use (&$ws, $newSession, $attach, $waitState, $record, $uidWs) {
        $ws = $newSession($uidWs, 'dev-ws', 'ws', $GLOBALS['secret']);
        $attach('ws', $ws);
        $ws->onError(function ($e) {
            echo '      [error] ' . $e->getMessage() . "\n";
        });
        $ws->connect();
        $waitState($ws, SessionManager::STATE_READY, function () use ($ws, $record, $next) {
            $ws->ping(function ($ok) use ($ws, $record, $next) {
                $record('A', 'WS 链路 auth+ping', $ok, 'rtt=' . sprintf('%.1f', $ws->lastRtt() * 1000) . 'ms');
                $next();
            });
        }, function () use ($record, $next) {
            $record('A', 'WS 链路 auth+ping', false, '等待 ready 超时');
            $next();
        });
    };

    /* [B] 越权拦截：未鉴权发业务指令 -> 4003
     * SDK 的 request() 有 ready 守卫（未鉴权直接抛状态异常），故本用例走裸传输层：
     * 直接用 Codec 组包经 WsTransport 发送，等价服务端「未鉴权直发业务指令」。 */
    $queue[] = function ($next) use ($record, $wsUrl) {
        $transport = new WsTransport($wsUrl);
        $settled   = false;

        $transport->onMessage(function ($frame) use (&$settled, $transport, $record, $next) {
            $packet = Codec::decode((string)$frame);
            if (!is_array($packet) || $settled) {
                return;
            }
            $settled = true;
            $code    = ce2eCode($packet);
            $record('B', '未鉴权越权拦截 4003', $code === 4003, 'code=' . $code);
            $transport->close();
            $next();
        });

        $transport->onOpen(function () use ($transport) {
            // 未鉴权直发业务指令（data.action=echo）
            $transport->send(Codec::encode(Codec::dataPacket('echo', ['probe' => 1])));
        });

        $transport->onError(function ($code, $msg) use (&$settled, $transport, $record, $next) {
            if ($settled) {
                return;
            }
            $settled = true;
            $record('B', '未鉴权越权拦截 4003', false, '传输错误 ' . $code . ':' . $msg);
            $transport->close();
            $next();
        });

        $transport->connect();

        Timer::add(8.0, function () use (&$settled, $transport, $record, $next) {
            if ($settled) {
                return;
            }
            $settled = true;
            $record('B', '未鉴权越权拦截 4003', false, '等待回执超时');
            $transport->close();
            $next();
        }, [], false);
    };

    // [C] UDP 正常链路
    $queue[] = function ($next) use (&$udp, $newSession, $attach, $waitState, $record, $uidUdp) {
        $udp = $newSession($uidUdp, 'dev-udp', 'udp', $GLOBALS['secret'], ['timeout' => 4.0]);
        $attach('udp', $udp);
        $udp->connect();
        $waitState($udp, SessionManager::STATE_READY, function () use ($record, $next) {
            $record('C', 'UDP 链路 auth', true);
            $next();
        }, function () use ($record, $next) {
            $record('C', 'UDP 链路 auth', false, '等待 ready 超时（查服务端日志）');
            $next();
        });
    };

    /* [D] UDP 签名拦截：错误密钥签发的报文 -> 4001
     * 同样走裸传输层（SessionManager 的 auth 需先进入 connected 态，UDP 的 onOpen 有
     * 预热延迟，轮询等待易与状态迁移错配），直接用 Codec 组 auth 报文。 */
    $queue[] = function ($next) use ($record, $udpUrl, $prefix) {
        $uid    = $prefix . '-d';
        $issuer = new TokenIssuer('wrong-secret-for-negative-test');
        $token  = $issuer->issue(['uid' => $uid, 'device_id' => 'dev-d']);

        $packet = Codec::packet('auth', ['client' => 'gateway-push-client'], [
            'seq'       => 'ce2e-d-1',
            'uid'       => $uid,
            'device_id' => 'dev-d',
            'token'     => $token,
        ]);
        // 签名也用错误密钥：等价于服务端收到被篡改的 sign 字段
        $packet['sign'] = Signer::sign($packet, 'wrong-secret-for-negative-test');

        $transport = new UdpTransport($udpUrl);
        $settled   = false;

        $finish = function ($ok, $detail) use (&$settled, $transport, $record, $next) {
            if ($settled) {
                return;
            }
            $settled = true;
            $record('D', 'UDP 错误签名 4001', $ok, $detail);
            $transport->close();
            $next();
        };

        $transport->onMessage(function ($frame) use ($finish) {
            $packet = Codec::decode($frame);
            $code   = ce2eCode($packet);
            $finish($code === 4001, 'code=' . $code);
        });

        $transport->onOpen(function () use ($transport, $packet) {
            $transport->send(Codec::encode($packet));
        });

        $transport->onError(function ($code, $msg) use ($finish) {
            $finish(false, '传输错误 ' . $code . ':' . $msg);
        });

        $transport->connect();

        Timer::add(6.0, function () use ($finish) {
            $finish(false, '等待回执超时（UDP 错误多为静默，查服务端日志）');
        }, [], false);
    };

    // [E] 在线定向推送
    $queue[] = function ($next) use (&$pushes, $waitUntil, $record, $admin) {
        $msgId = 'ce2e-e-' . bin2hex(random_bytes(3));
        $admin->push('uid', $GLOBALS['uidWsFix'], ['case' => 'E'], ['msg_id' => $msgId], function ($ok) use (&$pushes, $waitUntil, $record, $next, $msgId) {
            if (!$ok) {
                $record('E', '在线定向推送', false, '受理失败');
                $next();

                return;
            }
            $waitUntil(function () use (&$pushes, $msgId) {
                foreach ($pushes['ws'] as $p) {
                    if ($p['meta']['msg_id'] === $msgId) {
                        return true;
                    }
                }

                return false;
            }, 5.0, function ($hit) use ($record, $next, $msgId) {
                $record('E', '在线定向推送', $hit, 'msg_id=' . $msgId);
                $next();
            });
        });
    };

    // [F] 离线缓存与重连补投
    $queue[] = function ($next) use ($newSession, $attach, $waitState, $record, $admin, $prefix) {
        $uid = $prefix . '-f';
        $s1  = $newSession($uid, 'dev-f', 'ws', $GLOBALS['secret']);
        $s1->connect();
        $waitState($s1, SessionManager::STATE_READY, function () use ($s1, $uid, $newSession, $attach, $waitState, $record, $admin, $next) {
            $s1->close();
            Timer::add(1.0, function () use ($uid, $newSession, $attach, $waitState, $record, $admin, $next) {
                $msgId = 'ce2e-f-' . bin2hex(random_bytes(3));
                $admin->push('uid', $uid, ['case' => 'F'], ['msg_id' => $msgId, 'offline_mode' => 'queue'], function ($ok) use ($uid, $newSession, $attach, $waitState, $record, $next, $msgId) {
                    // 等队列消费者在「离线」判定下完成入缓存，再建新会话触发补投
                    Timer::add(1.5, function () use ($uid, $newSession, $attach, $waitState, $record, $next, $msgId) {
                        $s2 = $newSession($uid, 'dev-f', 'ws', $GLOBALS['secret']);
                        $attach('f', $s2);
                        $s2->connect();
                        $waitState($s2, SessionManager::STATE_READY, function () use ($s2, $record, $next, $msgId) {
                            // 补投发生在鉴权成功后，留 4s 观察窗口
                            Timer::add(4.0, function () use ($s2, $record, $next, $msgId) {
                                $hit  = false;
                                $seen = [];
                                foreach ($GLOBALS['pushes']['f'] as $p) {
                                    $seen[] = $p['meta']['msg_id'] . '/offline=' . $p['meta']['offline'];
                                    if ($p['meta']['msg_id'] === $msgId && (int)$p['meta']['offline'] === 1) {
                                        $hit = true;
                                    }
                                }
                                $record(
                                    'F',
                                    '离线缓存 + 重连补投 offline=1',
                                    $hit,
                                    'msg_id=' . $msgId . ' 实收[' . implode(' ', $seen) . ']'
                                );
                                $s2->close();
                                $next();
                            }, [], false);
                        });
                    }, [], false);
                });
            }, [], false);
        });
    };

    // [G] 推送幂等：同 msg_id 两次 -> 仅一次
    $queue[] = function ($next) use (&$pushes, $record, $admin) {
        $msgId = 'ce2e-g-' . bin2hex(random_bytes(3));
        $uid   = $GLOBALS['uidWsFix'];
        $adminApi = $admin;
        $adminApi->push('uid', $uid, ['case' => 'G'], ['msg_id' => $msgId], function () use ($adminApi, $uid, $msgId, &$pushes, $record, $next) {
            $adminApi->push('uid', $uid, ['case' => 'G'], ['msg_id' => $msgId], function () use ($msgId, &$pushes, $record, $next) {
                Timer::add(3.0, function () use ($msgId, &$pushes, $record, $next) {
                    $count = 0;
                    foreach ($pushes['ws'] as $p) {
                        if ($p['meta']['msg_id'] === $msgId) {
                            $count++;
                        }
                    }
                    $record('G', '推送幂等（同 msg_id 仅一次）', $count === 1, '收到 ' . $count . ' 次');
                    $next();
                }, [], false);
            });
        });
    };

    // [H] HTTP 接口：health / stats / 验签拒绝
    $queue[] = function ($next) use ($apiUrl, $secret, $record) {
        $api = new AdminApi($apiUrl, $secret, 5.0);
        $api->health(function ($ok) use ($api, $apiUrl, $record, $next) {
            $api->stats(function ($ok2) use ($apiUrl, $record, $next, $ok) {
                // 免签模式探测：以**空密钥**请求 /stats（AdminApi::sign() 在空密钥时返回空串）。
                // 服务端 API_SIGN_ENABLE=false 且监听回环地址时不验签 → 直接 200；
                // 验签模式下空签名必被拒（401）→ 探测为 false。
                // 探测只决定「错误签名被拒」这条断言是否适用，不改变其余断言。
                $probe = new AdminApi($apiUrl, '', 5.0);
                $probe->stats(function ($okProbe) use ($apiUrl, $record, $next, $ok, $ok2) {
                    if ($okProbe) {
                        // 免签模式：仅「错误签名被拒」不适用；health/stats 本身的失败仍须暴露，
                        // 故 pass 与 skip 都取 $ok && $ok2 —— 二者不成立时按 FAIL 记录。
                        $record(
                            'H',
                            'HTTP health/stats + 验签拒绝 401',
                            $ok && $ok2,
                            'health=' . var_export($ok, true) . ' stats=' . var_export($ok2, true)
                                . '（免签模式 API_SIGN_ENABLE=false，验签拒绝断言不适用）',
                            $ok && $ok2
                        );
                        $next();

                        return;
                    }
                    $bad = new AdminApi($apiUrl, 'bad-secret-for-negative-test', 5.0);
                    $bad->push('uid', 'nobody', ['case' => 'H'], [], function ($ok3, $data, $error) use ($record, $next, $ok, $ok2) {
                        $status = is_array($error) && isset($error['status']) ? (int)$error['status'] : 0;
                        $pass   = $ok && $ok2 && !$ok3 && $status === 401;
                        $record('H', 'HTTP health/stats + 验签拒绝 401', $pass, 'health=' . var_export($ok, true)
                            . ' stats=' . var_export($ok2, true) . ' badStatus=' . $status);
                        $next();
                    });
                });
            });
        });
    };

    // [I] UDP 定向推送（经出站队列）
    $queue[] = function ($next) use (&$pushes, $waitUntil, $record, $admin) {
        $msgId = 'ce2e-i-' . bin2hex(random_bytes(3));
        $admin->push('uid', $GLOBALS['uidUdpFix'], ['case' => 'I'], ['msg_id' => $msgId], function ($ok) use (&$pushes, $waitUntil, $record, $next, $msgId) {
            if (!$ok) {
                $record('I', 'UDP 定向推送', false, '受理失败');
                $next();

                return;
            }
            $waitUntil(function () use (&$pushes, $msgId) {
                foreach ($pushes['udp'] as $p) {
                    if ($p['meta']['msg_id'] === $msgId) {
                        return true;
                    }
                }

                return false;
            }, 6.0, function ($hit) use ($record, $next, $msgId) {
                $record('I', 'UDP 定向推送（出站队列）', $hit, 'msg_id=' . $msgId);
                $next();
            });
        });
    };

    // [J] 指令路由表：echo / session / 未知动作 4006
    $queue[] = function ($next) use (&$ws, $record) {
        $session = $ws;
        $session->request('echo', ['j' => 1], function ($ok) use ($session, $record, $next) {
            $session->request('session', [], function ($ok2) use ($session, $record, $next, $ok) {
                $session->request('__unknown_action__', [], function ($ok3, $packet) use ($record, $next, $ok, $ok2) {
                    $code = ce2eCode($packet);
                    $pass = $ok && $ok2 && !$ok3 && $code === 4006;
                    $record('J', '路由表 echo/session + 未知动作 4006', $pass, 'unknown code=' . $code);
                    $next();
                });
            });
        });
    };

    // [K] UDP 离线补投：建会话前入队 -> 首次鉴权后补投
    $queue[] = function ($next) use ($newSession, $attach, $waitState, $waitUntil, $record, $admin, $prefix) {
        $uid   = $prefix . '-k';
        $msgId = 'ce2e-k-' . bin2hex(random_bytes(3));
        $admin->push('uid', $uid, ['case' => 'K'], ['msg_id' => $msgId, 'offline_mode' => 'queue'], function ($ok) use ($uid, $msgId, $newSession, $attach, $waitState, $waitUntil, $record, $next) {
            Timer::add(1.0, function () use ($uid, $msgId, $newSession, $attach, $waitState, $waitUntil, $record, $next) {
                $s = $newSession($uid, 'dev-k', 'udp', $GLOBALS['secret'], ['timeout' => 4.0]);
                $attach('k', $s);
                $s->connect();
                $waitState($s, SessionManager::STATE_READY, function () use ($s, $msgId, $waitUntil, $record, $next) {
                    $waitUntil(function () use ($msgId) {
                        foreach ($GLOBALS['pushes']['k'] as $p) {
                            if ($p['meta']['msg_id'] === $msgId && (int)$p['meta']['offline'] === 1) {
                                return true;
                            }
                        }

                        return false;
                    }, 5.0, function ($hit) use ($s, $record, $next, $msgId) {
                        $record('K', 'UDP 离线补投 offline=1', $hit, 'msg_id=' . $msgId);
                        $s->close();
                        $next();
                    });
                }, function () use ($s, $record, $next) {
                    $record('K', 'UDP 离线补投 offline=1', false, 'UDP ready 超时');
                    $s->close();
                    $next();
                });
            }, [], false);
        });
    };

    /* [L] 报文级限流：连发超量 -> 出现 4008（并发突发，不等待前序回执）
     * conn 维度 burst 默认 40，故发 100 条；使用独立 uid 避免污染其它用例的令牌桶。 */
    $queue[] = function ($next) use ($newSession, $waitState, $record, $prefix) {
        $session = $newSession($prefix . '-l', 'dev-l', 'ws', $GLOBALS['secret']);
        $session->connect();

        $waitState($session, SessionManager::STATE_READY, function () use ($session, $record, $next) {
            // @var 覆盖 PHPStan 对 by-ref 闭包链的空数组收窄：$codes 由下方
            // 100 个并发回调填充，分析器看不到跨闭包赋值（见 tests baseline 说明）
            /** @var array<int, int> $codes */
            $codes = [];
            $left  = 100;
            $maybe = function () use (&$left, &$codes, $session, $record, $next) {
                $left--;
                if ($left > 0) {
                    return;
                }
                $limited = in_array(4008, $codes, true);
                $passed  = count(array_filter($codes, fn ($c) => $c === 0));
                $record(
                    'L',
                    '报文级限流（出现 4008）',
                    $limited,
                    '放行 ' . $passed . ' / 100，codes=' . implode(',', array_unique($codes))
                );
                $session->close();
                $next();
            };

            for ($i = 0; $i < 100; $i++) {
                $session->request('echo', ['burst' => $i], function ($ok, $packet) use (&$codes, $maybe) {
                    $codes[] = ce2eCode($packet);
                    $maybe();
                });
            }
        }, function () use ($session, $record, $next) {
            $record('L', '报文级限流（出现 4008）', false, '等待 ready 超时');
            $session->close();
            $next();
        });
    };

    // [M] 业务动作契约：参数错误 4007
    $queue[] = function ($next) use (&$ws, $record) {
        $ws->request('report', ['count' => 1], function ($ok, $packet) use ($record, $next) {
            $code = ce2eCode($packet);
            $record('M', '动作契约：缺参数 4007', !$ok && $code === 4007, 'code=' . $code);
            $next();
        });
    };

    // [N] UDP 动作：echo 回执 / report 静默
    $queue[] = function ($next) use (&$udp, $record) {
        $session = $udp;
        $session->request('echo', ['n' => 1], function ($ok) use ($session, $record, $next) {
            $session->request('report', ['topic' => 'ce2e-topic', 'count' => 1], function ($ok2, $packet) use ($record, $next, $ok) {
                $code = ce2eCode($packet);
                $pass = $ok && !$ok2 && $code === 10001; // 10001 = 本地超时（UDP 侧按声明静默）
                $record('N', 'UDP echo 回执 + report 静默', $pass, 'echo=' . var_export($ok, true) . ' report code=' . $code);
                $next();
            });
        });
    };

    // [O] 订阅闭环：subscribe -> topics -> unsubscribe
    $queue[] = function ($next) use (&$ws, $record) {
        $topic = 'ce2e-topic-' . bin2hex(random_bytes(2));
        $ws->request('subscribe', ['topic' => $topic], function ($ok) use ($ws, $topic, $record, $next) {
            $ws->request('topics', [], function ($ok2, $packet) use ($ws, $topic, $record, $next, $ok) {
                $list  = isset($packet['data']['topics']) ? (array)$packet['data']['topics'] : [];
                $hasIt = in_array($topic, $list, true);
                $ws->request('unsubscribe', ['topic' => $topic], function ($ok3) use ($record, $next, $ok, $ok2, $hasIt) {
                    $record(
                        'O',
                        '订阅闭环 subscribe/topics/unsubscribe',
                        $ok && $ok2 && $hasIt && $ok3,
                        'subscribed=' . var_export($hasIt, true)
                    );
                    $next();
                });
            });
        });
    };

    $GLOBALS['uidWsFix']  = $uidWs;
    $GLOBALS['uidUdpFix'] = $uidUdp;

    $run();
};

$GLOBALS['secret']    = $secret;
$GLOBALS['uidWsFix']  = $prefix . '-ws';
$GLOBALS['uidUdpFix'] = $prefix . '-udp';

// workerman 默认把框架日志落在「入口脚本所在目录」（$argv[0] 同级），会在
// client/tests/E2E/ 里凭空多出一个 workerman.log。显式收敛到 runtime/logs，
// 与服务端 start.php 同一处，运行时产物不散落在源码树里。
$logDir = BASE_PATH . '/runtime/logs';
if (!is_dir($logDir) && !@mkdir($logDir, 0o755, true) && !is_dir($logDir)) {
    fwrite(STDERR, "[WARN] 日志目录创建失败：{$logDir}\n");
}
Worker::$logFile = $logDir . '/client_e2e.log';

Worker::runAll();
