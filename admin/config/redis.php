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
 * 客户端选择：`ADMIN_REDIS_CLIENT`（默认 phpredis）。本机已装扩展 6.3.0；
 * 无 `extension=redis` 的环境改回 `predis`（纯 PHP）。缺扩展时用 phpredis
 * 会抛「Class Redis not found」——属环境问题，不是配置键放错。
 *
 * ⚠ 性能取舍：predis 是**阻塞**客户端。Windows + select 事件循环下，
 *   单次阻塞读会占住当前 worker。后台是低 QPS 页面（轮询 ≥5s），回环实测可接受。
 *   phpredis 更快且 closer 走原生 close()；或引入 Swoole/Swow 协程彻底消除阻塞。
 */

$host = getenv('ADMIN_REDIS_HOST') ?: '127.0.0.1';
$port = (int)(getenv('ADMIN_REDIS_PORT') ?: 6379);
$password = getenv('ADMIN_REDIS_PASSWORD') ?: '';
$database = (int)(getenv('ADMIN_REDIS_DB') ?: 0);
$timeout = (float)(getenv('ADMIN_REDIS_TIMEOUT') ?: 3);

return [
    // ⚠ 客户端选择必须放在**顶层**：`support\Redis::instance()`（vendor/webman/redis/src/support/Redis.php:254）
    //   读的是 `config('redis')['client']`，**不是** `redis.default.client`。
    //   放错位置会被静默忽略并回退 phpredis（缺扩展时抛 Class "Redis" not found）。
    //   合法值取自 `support\Redis::$allowClient`（phpredis / predis）。
    'client' => getenv('ADMIN_REDIS_CLIENT') ?: 'phpredis',
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
            // min=0 + idle_timeout：空闲连接直接丢弃，而不是靠心跳保活。
            // ⚠ 2.0 踩坑记录（deploy.md §13.3）：原配置 min_connections=1 + heartbeat_interval=50，
            //   metric-sampler 进程（60s 才采样一次）的连接必然 idle 超过心跳间隔，
            //   而此时对端（Redis）早已关掉该连接 ⇒ 心跳 GET 写失败 ⇒
            //   池尝试关闭连接 ⇒ 客户端无 close()（predis）⇒ 每 50s 刷一轮异常堆栈。
            // ⚠ 关闭路径的**根因修复**在 app/support/PredisSafeRedisManager.php
            //   （closer 按客户端类型走 disconnect()/close()），由 RedisBootstrap 在
            //   每个 worker 启动时替换 support\Redis::$instance。此处只保留池参数策略：
            //   低 QPS 后台「每次取用时新建」远比「保活坏连接」可靠。
            'min_connections' => 0,
            'max_idle_time' => 55,
            'idle_timeout' => 55,
            'wait_timeout' => 3,
            'heartbeat_interval' => 3600,
        ],
    ],
];
