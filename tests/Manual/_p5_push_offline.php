<?php
/**
 * P5 实测脚本（B）：经 AdminApi 向指定 uid 推送一条离线消息
 *
 * 用法：php tests/Manual/_p5_push_offline.php <uid>
 * 前置：api 角色在线；目标 uid 不在线（gateway 已停止）。
 * 退出码：0 = 受理成功
 */

define('BASE_PATH', dirname(__DIR__, 2));

require BASE_PATH . '/vendor/autoload.php';

use GatewayPush\Client\Service\AdminApi;
use Workerman\Worker;

$uid = isset($argv[1]) ? (string)$argv[1] : '';
if ($uid === '') {
    echo "用法：php _p5_push_offline.php <uid>\n";

    exit(1);
}

$appConfig = require BASE_PATH . '/config/app.php';
$apiUrl    = str_replace('0.0.0.0', '127.0.0.1', $appConfig['api']['listen']);

$worker = new Worker();

$worker->onWorkerStart = function () use ($apiUrl, $appConfig, $uid) {
    $api = new AdminApi($apiUrl, (string)$appConfig['auth']['secret'], 5.0);
    $api->push('uid', $uid, ['title' => 'p5-offline-msg'], [
        'msg_id'       => 'p5-off-' . bin2hex(random_bytes(4)),
        'offline_mode' => 'queue',
    ], function ($ok, $data, $error) {
        if ($ok) {
            echo '[B] 离线消息已受理入队：msg_id=' . ($data['msg_id'] ?? '') . "\n";

            exit(0);
        }
        echo '[B] 推送受理失败：' . json_encode($error, JSON_UNESCAPED_UNICODE) . "\n";

        exit(1);
    });
};

Worker::runAll();
