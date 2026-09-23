<?php
/**
 * 后台自有 MySQL 连接。
 *
 * 用途边界（详见 docs/GatewayPush WebmanAdmin 管理后台详细设计.md §5.3 / §5.4）：
 * - 仅承载 webman/admin 的管理员 / 角色 / 权限，以及后台自有的 push_task / push_template /
 *   admin_audit_log / admin_settings 四表。
 * - **推送系统的运行不依赖 MySQL**：本库不可用只影响后台，不影响 GatewayPush 的 6 个角色。
 *
 * 三条硬纪律：
 * 1. 连接信息一律由 .env 注入，密钥既不落代码也不进版本库；
 * 2. `collation` 显式钉 `utf8mb4_general_ci` —— 与 webman-admin 自带的 wa_* 七张表
 *    （`plugin/admin/install.sql` 每张表都写死 `COLLATE=utf8mb4_general_ci`）+ 后台自有
 *    `database/001_gw_tables.sql` **完全同源**。⚠ 不可改成 utf8mb4_unicode_ci 或
 *    utf8mb4_0900_ai_ci：与 wa_* 表混排会在跨表 JOIN / UNION 时报
 *    "Illegal mix of collations"，且该错误只在运行到具体 SQL 时才暴露（非启动期）。
 * 3. 本文件同时是 config('database') 与 config('plugin.admin.database') 的真源 ——
 *    见 config/plugin/admin/database.php（require 本文件，避免两处漂移）。
 *
 * 加载前提：.env 中必须存在 ADMIN_DB_* 四项，缺任一项都会在启动期直接暴露连接失败，
 * 而不是退化成「连上了一个别的库」。
 */

$port = (int)(getenv('ADMIN_DB_PORT') ?: 3306);

return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => getenv('ADMIN_DB_HOST') ?: '127.0.0.1',
            'port' => $port,
            'database' => getenv('ADMIN_DB_NAME') ?: 'gateway_push_admin',
            'username' => getenv('ADMIN_DB_USER') ?: '',
            'password' => getenv('ADMIN_DB_PASS') ?: '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_general_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
            'options' => [
                PDO::ATTR_EMULATE_PREPARES => false, // Must be false for Swoole and Swow drivers.
                PDO::ATTR_TIMEOUT => 5,
            ],
            'pool' => [
                'max_connections' => 5,
                'min_connections' => 1,
                'wait_timeout' => 3,
                'idle_timeout' => 60,
                'heartbeat_interval' => 50,
            ],
        ],
    ],
];
