<?php
/**
 * P4 实测脚本：AdminApi（HTTP 管理端）验收（对运行中服务）
 *
 * 用法：php tests/Manual/_p4_admin_check.php
 *
 * 前置：api 角色已启动。全部场景在同一事件循环内顺序发起（互不依赖）。
 * 场景：
 *   [1] /health 免鉴权      -> 200 + code=0
 *   [2] /stats 验签通过     -> 200 + 指标快照
 *   [3] /push 验签通过      -> 200 accepted
 *   [4] 错误密钥 -> 401     -> 401 + 业务码 4001
 *   [5] 过期时间戳 -> 401   -> 401 + 业务码 4002（HttpTransport 手写过期头）
 *
 * 退出码：0 = 全部通过
 */

define('BASE_PATH', dirname(__DIR__, 2));
require BASE_PATH . '/vendor/autoload.php';

use GatewayPush\Client\Error\ErrorCode;
use GatewayPush\Client\Service\AdminApi;
use GatewayPush\Client\Transport\HttpTransport;
use Workerman\Worker;

$appConfig = require BASE_PATH . '/config/app.php';
$apiUrl    = str_replace('0.0.0.0', '127.0.0.1', $appConfig['api']['listen']);
$secret    = (string)$appConfig['auth']['secret'];

echo "P4 AdminApi 实测\n" . str_repeat('=', 60) . "\n";
echo "API 地址 : {$apiUrl}\n" . str_repeat('-', 60) . "\n";

$state = array(
    'health'  => false,
    'stats'   => false,
    'push'    => false,
    'badsign' => false,
    'expired' => false,
);
$failMsg = array();

$worker = new Worker();

$worker->onWorkerStart = function () use ($apiUrl, $secret, &$state, &$failMsg) {

    $api = new AdminApi($apiUrl, $secret, 5.0);

    // [1] /health
    $api->health(function ($ok, $data, $error) use (&$state) {
        $state['health'] = $ok;
        echo sprintf("[1] /health -> %s %s\n", $ok ? '200 ok' : 'FAIL', json_encode($data, JSON_UNESCAPED_UNICODE));
    });

    // [2] /stats
    $api->stats(function ($ok, $data, $error) use (&$state) {
        $state['stats'] = $ok && isset($data['gauge']);
        echo sprintf("[2] /stats  -> %s gauge 字段数=%d\n",
            $ok ? '200 ok' : ('FAIL ' . json_encode($error, JSON_UNESCAPED_UNICODE)),
            isset($data['gauge']) ? count($data['gauge']) : 0
        );
    });

    // [3] /push
    $api->push('uid', 'p3-udp-run4', array('title' => 'p4-direct-push'), array(
        'msg_id' => 'p4-push-' . bin2hex(random_bytes(4)),
    ), function ($ok, $data, $error) use (&$state) {
        $state['push'] = $ok && isset($data['target_type']) && $data['target_type'] === 'uid';
        echo sprintf("[3] /push   -> %s %s\n",
            $ok ? '200 accepted' : 'FAIL ' . json_encode($error, JSON_UNESCAPED_UNICODE),
            $ok ? json_encode($data, JSON_UNESCAPED_UNICODE) : ''
        );
    });

    // [4] 错误密钥 -> 401 + 4001
    $badApi = new AdminApi($apiUrl, 'wrong-secret-000', 5.0);
    $badApi->stats(function ($ok, $data, $error) use (&$state) {
        $state['badsign'] = !$ok && isset($error['status']) && $error['status'] === 401 && $error['code'] === ErrorCode::HTTP_BAD_SIGN;
        echo sprintf("[4] 错签    -> %s\n", $ok ? 'FAIL（不应通过）' : ('401 code=' . $error['code'] . ' ' . $error['msg']));
    });

    // [5] 过期时间戳 -> 401 + 4002（HttpTransport 手写过期头）
    $ts   = time() - 9999; // 超出默认 300s 防重放窗口
    $sign = hash_hmac('sha256', $ts . '|' . '', $secret);
    (new HttpTransport($apiUrl, 5.0))->request('GET', '/stats', '', array(
        'X-Timestamp' => (string)$ts,
        'X-Sign'      => $sign,
    ), function ($response) use (&$state) {
        $json            = $response['json'];
        $state['expired'] = $response['status'] === 401 && is_array($json) && (int)$json['code'] === ErrorCode::HTTP_EXPIRED;
        echo sprintf("[5] 过期    -> HTTP %d code=%s %s\n",
            $response['status'],
            is_array($json) ? $json['code'] : '?',
            is_array($json) ? $json['msg'] : ''
        );
    });

    // 汇总
    \Workerman\Timer::add(2.0, function () use (&$state, &$failMsg) {
        echo "\n" . str_repeat('=', 60) . "\n";
        $checks = array(
            'health'  => '[1] /health 免鉴权 200',
            'stats'   => '[2] /stats 验签通过返回指标',
            'push'    => '[3] /push 验签通过受理推送',
            'badsign' => '[4] 错误密钥 -> 401/4001',
            'expired' => '[5] 过期时间戳 -> 401/4002',
        );
        $pass = true;
        foreach ($checks as $key => $label) {
            $ok   = $state[$key] === true;
            $pass = $pass && $ok;
            echo sprintf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
        }
        foreach ($failMsg as $msg) {
            echo "异常：{$msg}\n";
            $pass = false;
        }
        echo str_repeat('=', 60) . "\n";
        echo $pass ? "P4 实测结论：全部通过\n" : "P4 实测结论：存在失败项\n";
        exit($pass ? 0 : 1);
    }, array(), false);
};

Worker::runAll();
