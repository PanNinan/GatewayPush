<?php
/**
 * P5 实测脚本（C）：设备绑定「首个绑定者胜出」验证
 *
 * 序列：
 *   1. uid 使用 device-A 首次鉴权（写入 auth:bind:{uid}）-> 期望成功
 *   2. 同 uid 换 device-B 鉴权                        -> 期望失败（设备不匹配）
 *   3. 清除 Redis 绑定键 gwpush:auth:bind:{uid}（DB 9）
 *   4. 同 uid 再以 device-B 鉴权                      -> 期望成功
 *
 * 用法：php tests/Manual/_p5_bind_check.php
 * 退出码：0 = 四项均符合预期
 */

define('BASE_PATH', dirname(__DIR__, 2));
require BASE_PATH . '/vendor/autoload.php';

use GatewayPush\Client\Session\SessionManager;
use GatewayPush\Client\Transport\WsTransport;
use Workerman\Redis\Client as RedisClient;
use Workerman\Worker;

$uid    = 'p5-bind-uid-' . bin2hex(random_bytes(3));
$secret = (require BASE_PATH . '/config/app.php')['auth']['secret'];

echo "P5 设备绑定首胜验证 uid={$uid}\n";

$result = [];

$worker = new Worker();

$worker->onWorkerStart = function () use ($uid, $secret, &$result) {
    /**
     * 单次鉴权尝试
     *
     * @param string   $device
     * @param callable $done function(bool $ok, int $code, string $msg)
     * @return void
     */
    $attempt = function ($device, callable $done) use ($uid, $secret) {
        $transport = new WsTransport('ws://127.0.0.1:8282');
        $session   = new SessionManager(array(
            'uid'       => $uid,
            'device_id' => $device,
            'secret'    => $secret,
            'heartbeat' => 0,
            'timeout'   => 5.0,
            'reconnect' => false,
            'auto_auth' => false,
        ), $transport);

        $settled = false;
        $finish  = function ($ok, $code, $msg) use (&$settled, $done, $session) {
            if ($settled) {
                return;
            }
            $settled = true;
            $session->close();
            call_user_func($done, $ok, $code, $msg);
        };

        $session->onStateChange(function ($new, $old) use ($session, $finish) {
            if ($new === SessionManager::STATE_CONNECTED) {
                $session->auth(function ($ok, $packet) use ($finish) {
                    $code = isset($packet['data']['code']) ? (int)$packet['data']['code']
                        : (isset($packet['code']) ? (int)$packet['code'] : 0);
                    $msg  = isset($packet['data']['msg']) ? (string)$packet['data']['msg']
                        : (isset($packet['msg']) ? (string)$packet['msg'] : '');
                    $finish($ok, $code, $msg);
                });
            }
            // 服务端鉴权失败会直接断连，此时 auth 回调不会到达
            if ($new === SessionManager::STATE_DISCONNECTED && $old !== SessionManager::STATE_DISCONNECTED) {
                \Workerman\Timer::add(0.5, function () use ($finish) {
                    $finish(false, -1, '连接被服务端关闭（鉴权失败断连）');
                }, [], false);
            }
        });

        $session->onError(function ($e) {
            echo "  [error] " . $e->getMessage() . "\n";
        });

        $session->connect();
    };

    // [1] 首设备
    $attempt('p5-dev-A', function ($ok, $code, $msg) use (&$result, $uid, $attempt) {
        $result['first'] = $ok;
        echo sprintf("[1] device=p5-dev-A 首次鉴权：%s（code=%d msg=%s）\n", $ok ? '成功' : '失败', $code, $msg);

        // [2] 换设备（应被拒）
        $attempt('p5-dev-B', function ($ok, $code, $msg) use (&$result, $uid, $attempt) {
            $result['mismatch'] = !$ok;
            echo sprintf("[2] device=p5-dev-B 换设备鉴权：%s（code=%d msg=%s）\n", $ok ? '成功' : '被拒', $code, $msg);

            // [3] 清除绑定键（DB 9）
            $redis = new RedisClient('redis://127.0.0.1:6379');
            $redis->select(9, function () use ($redis, $uid, $attempt, &$result) {
                $key = 'gwpush:auth:bind:' . $uid;
                $redis->del($key, function ($r) use ($redis, $uid, $key, $attempt, &$result) {
                    echo sprintf("[3] 清除绑定键 %s -> del=%s\n", $key, var_export($r, true));
                    $redis->close();

                    // [4] 清绑后同设备再鉴权
                    $attempt('p5-dev-B', function ($ok, $code, $msg) use (&$result) {
                        $result['afterClear'] = $ok;
                        echo sprintf("[4] 清绑后 device=p5-dev-B 鉴权：%s（code=%d msg=%s）\n", $ok ? '成功' : '失败', $code, $msg);

                        $pass = !empty($result['first']) && !empty($result['mismatch']) && !empty($result['afterClear']);
                        echo str_repeat('=', 60) . "\n";
                        echo sprintf("[%s] 首个绑定者胜出\n", $result['first'] ? 'PASS' : 'FAIL');
                        echo sprintf("[%s] 换设备被拒\n", $result['mismatch'] ? 'PASS' : 'FAIL');
                        echo sprintf("[%s] 清绑后可换设备\n", $result['afterClear'] ? 'PASS' : 'FAIL');
                        echo str_repeat('=', 60) . "\n";
                        echo $pass ? "P5 设备绑定结论：全部通过\n" : "P5 设备绑定结论：存在失败项\n";
                        exit($pass ? 0 : 1);
                    });
                });
            });
        });
    });
};

Worker::runAll();
