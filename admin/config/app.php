<?php
/**
 * admin 配置 —— app。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

use support\Request;

return [
    'debug' => true,
    'error_reporting' => E_ALL,
    'default_timezone' => 'Asia/Shanghai',
    'request_class' => Request::class,
    'public_path' => base_path() . DIRECTORY_SEPARATOR . 'public',
    'runtime_path' => base_path(false) . DIRECTORY_SEPARATOR . 'runtime',
    'controller_suffix' => 'Controller',
    'controller_reuse' => false,
];
