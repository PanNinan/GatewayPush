#!/usr/bin/env php
<?php
/**
 * gwclient —— GatewayPush 实时推送服务客户端调试器
 *
 * 用法：
 *   php client/bin/gwclient.php <command> [args] [options]
 *   php client/bin/gwclient.php shell
 *   php client/bin/gwclient.php help
 *
 * 默认参数取自项目配置（config/app.php、config/gateway.php），可用选项覆盖。
 * Windows 控制台建议先执行 `chcp 65001` 以免中文输出乱码。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

$basePath = dirname(__DIR__, 2);

if (!is_file($basePath . '/vendor/autoload.php')) {
    fwrite(STDERR, '未找到 vendor/autoload.php，请先在项目根目录执行 composer install' . PHP_EOL);
    exit(1);
}

define('BASE_PATH', $basePath);
require BASE_PATH . '/vendor/autoload.php';

use GatewayPush\Client\Cli\Debugger;

$appConfig     = require BASE_PATH . '/config/app.php';
$gatewayConfig = require BASE_PATH . '/config/gateway.php';

/**
 * 监听地址转客户端地址（0.0.0.0 / websocket:// 等形态统一为 127.0.0.1）
 *
 * @param string $listen
 * @param string $scheme
 * @param string $default
 * @return string
 */
$toClientUrl = function ($listen, $scheme, $default) {
    $listen = (string)$listen;
    $listen = preg_replace('#^[a-z]+://#i', '', $listen);
    $listen = str_replace('0.0.0.0', '127.0.0.1', $listen);
    if ($listen === '') {
        return $default;
    }
    return $scheme . '://' . $listen;
};

$wsUrl  = $toClientUrl($gatewayConfig['websocket']['listen'], 'ws', 'ws://127.0.0.1:8282');
$udpUrl = $toClientUrl($gatewayConfig['udp']['listen'], 'udp', 'udp://127.0.0.1:8283');
$apiUrl = $toClientUrl(isset($appConfig['api']['listen']) ? $appConfig['api']['listen'] : '', 'http', 'http://127.0.0.1:8290');

$debugger = new Debugger(array(
    'secret'     => isset($appConfig['auth']['secret']) ? (string)$appConfig['auth']['secret'] : '',
    'api_secret' => isset($appConfig['api']['secret']) ? (string)$appConfig['api']['secret'] : '',
    'ws_url'     => $wsUrl,
    'udp_url'    => $udpUrl,
    'api_url'    => $apiUrl,
));

exit($debugger->run(array_slice($argv, 1)));
