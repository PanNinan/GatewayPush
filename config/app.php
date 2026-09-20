<?php
/**
 * 全局基础配置
 *
 * 覆盖：运行环境约束、日志、Redis、鉴权、会话、监控
 *
 * 取值约定：
 *   环境相关与敏感配置统一经 Env 读取（真实值来自 .env / .env.{APP_ENV} /
 *   系统环境变量），本文件仅声明缺省值，两者共同构成完整配置。
 *   结构性配置（扩展清单、指令白名单、指标名列表等）保留在代码中，
 *   不因部署环境变化而调整。
 *
 *   变量清单与说明见 .env.example，加载逻辑见 src/Common/Env.php
 *
 * 兼容：PHP 8.0 ~ 8.5
 */

use GatewayPush\Common\Env;

$basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);

return [

    /* ---------------------------------------------------------------
     | 应用基础信息
     --------------------------------------------------------------- */
    'app' => [
        'name'     => Env::str('APP_NAME', 'gateway-push'),
        'env'      => Env::str('APP_ENV', 'dev'),          // dev | test | prod
        'debug'    => Env::bool('APP_DEBUG', true),        // prod 建议 false
        'timezone' => Env::str('APP_TIMEZONE', 'Asia/Shanghai'),
    ],

    /* ---------------------------------------------------------------
     | 运行环境约束（启动自检使用）
     |
     | 属结构性配置，不随部署环境变化，故不使用环境变量
     |
     | require_ext        所有平台必须存在的扩展
     | require_ext_linux  仅 Linux 必需（Windows 无 pcntl/posix，多进程自动降级）
     | optional_ext       建议安装，缺失仅告警
     --------------------------------------------------------------- */
    'runtime' => [
        'php_min'           => '8.0.0',
        'php_max_warn'      => '8.5.99',   // 超出该版本仅告警，不阻断启动
        'require_ext'       => ['json', 'openssl', 'sockets'],
        'require_ext_linux' => ['pcntl', 'posix'],
        'optional_ext'      => ['event', 'redis', 'mbstring'],
        'runtime_path'      => $basePath . '/runtime',
        'log_path'          => $basePath . '/runtime/logs',
        'pid_path'          => $basePath . '/runtime/pid',
    ],

    /* ---------------------------------------------------------------
     | 日志配置
     --------------------------------------------------------------- */
    'log' => [
        'path'           => $basePath . '/runtime/logs',
        'level'          => Env::str('LOG_LEVEL', 'debug'),    // debug | info | warn | error
        'rotate'         => 'daily',                           // 按天分割
        'keep_days'      => Env::int('LOG_KEEP_DAYS', 30),     // 自动清理超过 N 天的日志文件
        'stdout'         => Env::bool('LOG_STDOUT', true),     // 同时输出到控制台
        'global_handler' => true,                              // 注册全局异常 / 错误 / 致命错误捕获
    ],

    /* ---------------------------------------------------------------
     | Redis 中心存储（会话 / 设备映射 / 缓存 / 监控指标）
     |
     | 统一使用 workerman/redis 异步客户端，杜绝同步 IO 阻塞事件循环
     --------------------------------------------------------------- */
    'redis' => [
        'host'               => Env::str('REDIS_HOST', '127.0.0.1'),
        'port'               => Env::int('REDIS_PORT', 6379),
        'password'           => Env::str('REDIS_PASSWORD', ''),
        'database'           => Env::int('REDIS_DB', 0),
        'timeout'            => Env::float('REDIS_TIMEOUT', 2.0),   // 连接超时（秒）
        'pool_size'          => Env::int('REDIS_POOL_SIZE', 8),     // 每进程异步连接数
        'prefix'             => Env::str('REDIS_PREFIX', 'gwpush:'),
        'reconnect_interval' => 1.0,                                // 断线重连间隔（秒）
        'max_reconnect'      => 0,                                  // 0 = 无限重连
    ],

    /* ---------------------------------------------------------------
     | 内部通信密钥
     |
     | Register / Gateway / BusinessWorker 三方内部通信鉴权使用，必须完全一致。
     | 留空则回退复用 auth.secret（见 start.php 归一化逻辑）；生产环境建议独立配置，
     | 避免业务鉴权密钥扩散到内部通信用途（二者泄露影响面不同）。
     --------------------------------------------------------------- */
    'internal' => [
        'secret' => Env::str('INTERNAL_SECRET', ''),
    ],

    /* ---------------------------------------------------------------
     | 连接鉴权
     |
     | mode = hmac  : 自包含签名 Token（base64(payload).hmac），本地校验无需 IO
     | mode = store : Redis 反查模式，Token 内容存 Redis
     --------------------------------------------------------------- */
    'auth' => [
        'enable'       => Env::bool('AUTH_ENABLE', true),
        'mode'         => Env::str('AUTH_MODE', 'hmac'),
        'secret'       => Env::str('AUTH_SECRET', ''),
        'sign_enable'  => Env::bool('AUTH_SIGN_ENABLE', true),   // 是否校验报文签名（UDP 强烈建议开启）
        'token_ttl'    => Env::int('AUTH_TOKEN_TTL', 7200),      // 默认 Token 有效期（秒）
        'clock_skew'   => Env::int('AUTH_CLOCK_SKEW', 300),      // 允许的时钟偏移（秒）
        'bind_device'  => Env::bool('AUTH_BIND_DEVICE', true),   // 校验 uid <-> device_id 绑定关系
        'fail_close'   => Env::bool('AUTH_FAIL_CLOSE', true),    // 鉴权失败立即断开连接
        'auth_timeout' => Env::int('AUTH_TIMEOUT', 15),          // 建连后 N 秒未鉴权则断开

        // 结构性配置：鉴权前允许的指令白名单，不随环境变化
        'allow_cmds'   => ['auth', 'ping'],
    ],

    /* ---------------------------------------------------------------
     | 会话管理（断线重连 / 会话保持）
     --------------------------------------------------------------- */
    'session' => [
        'ttl'           => Env::int('SESSION_TTL', 7200),          // 会话 Redis 过期时间（秒）
        'prefix'        => 'session',                              // 键名约定，结构性
        'online_key'    => 'online:clients',                       // 在线 client_id 集合
        'heartbeat_ttl' => Env::int('SESSION_HEARTBEAT_TTL', 90),  // 与 gateway.heartbeat.session_timeout 一致
        'restore'       => Env::bool('SESSION_RESTORE', true),     // 断线重连自动恢复历史会话
    ],

    /* ---------------------------------------------------------------
     | 监控指标
     --------------------------------------------------------------- */
    'monitor' => [
        'enable'   => Env::bool('MONITOR_ENABLE', true),
        'interval' => Env::int('MONITOR_INTERVAL', 60),   // 上报周期（秒）
        'ttl'      => Env::int('MONITOR_TTL', 600),       // 指标数据保留时长（秒）
        'key'      => 'metrics',                          // 键名约定，结构性

        // 结构性配置：需要采集的指标名列表
        'metrics'  => [
            'conn_total', 'conn_ws', 'conn_udp',
            'msg_in', 'msg_out', 'msg_fail',
            'auth_success', 'auth_fail',
            'heartbeat_timeout', 'memory_bytes',
        ],
    ],
];
