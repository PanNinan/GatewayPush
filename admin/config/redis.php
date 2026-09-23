<?php
/**
 * Redis 连接（`support\Redis` 门面，来自 webman/redis）。
 *
 * 用途边界（设计文档 §5.2「Redis 写边界」）：
 * - 后台**只读** `gwpush:*` 键空间；所有会改变推送系统状态的操作走 HTTP API，不直写 Redis。
 * - `database` / `prefix` **必须与主项目一致**。本机主项目为 `REDIS_DB=9`
 *   （DB0 有历史残留 `gwpush:*` 键），`REDIS_PREFIX=gwpush:`。
 *   ⚠ 配错的表现是「读到空数据」而**不是报错** —— 静默错误。
 *   故 OpsController 的 `/api/ops/redis/scan` 会做一次「预期键存在性」自检兜这点。
 *
 * 客户端选择：本机**无 phpredis 扩展**（`extension_loaded('redis') === false`），
 * 故显式指定 `client => predis`（纯 PHP 客户端）。不指定时 illuminate/redis 默认走 phpredis，
 * 在本机会直接抛「Class Redis not found」。
 *
 * ⚠ 性能取舍：predis 是**阻塞**客户端。本机为 Windows + select 事件循环，
 *   单次阻塞读会占住当前 worker。后台是低 QPS 的交互式页面（页面轮询间隔 ≥5s），
 *   且 host 为回环，实测可接受。若后续部署到 Linux 并装上 phpredis，
 *   改用 `client => phpredis` 即可（配置项名不变）；或引入 Swoole/Swow 协程彻底消除阻塞。
 */

$host = getenv('ADMIN_REDIS_HOST') ?: '127.0.0.1';
$port = (int)(getenv('ADMIN_REDIS_PORT') ?: 6379);
$password = getenv('ADMIN_REDIS_PASSWORD') ?: '';
$database = (int)(getenv('ADMIN_REDIS_DB') ?: 0);
$timeout = (float)(getenv('ADMIN_REDIS_TIMEOUT') ?: 3);

return [
    // ⚠ 客户端选择必须放在**顶层**：`support\Redis::instance()`（vendor/webman/redis/src/support/Redis.php:254）
    //   读的是 `config('redis')['client']`，**不是** `redis.default.client`。
    //   放错位置会被静默忽略并回退 phpredis，本机随即抛 `Class "Redis" not found`。
    //   合法值取自 `support\Redis::$allowClient`（phpredis / predis）。
    'client' => getenv('ADMIN_REDIS_CLIENT') ?: 'predis',
    'default' => [
        'host' => $host,
        'password' => $password,
        'port' => $port,
        'database' => $database,
        // 必须与主项目 config/app.php 的 REDIS_PREFIX 一致（默认 gwpush:）。
        // illuminate/redis 的 prefix 会由客户端自动加在所有键前，
        // 因此业务代码里**只写 RedisKeys 产出的逻辑键名**，不手工拼前缀。
        'prefix' => getenv('ADMIN_REDIS_PREFIX') ?: 'gwpush:',
        'read_timeout' => $timeout,
        'timeout' => $timeout,
        'pool' => [
            'max_connections' => 5,
            'min_connections' => 1,
            'wait_timeout' => 3,
            'idle_timeout' => 60,
            'heartbeat_interval' => 50,
        ],
    ],
];
