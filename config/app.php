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
 * 兼容：PHP 8.2 ~ 8.5（下限由 dev 工具链的传递依赖决定 —— php-cs-fixer 依赖的
 *   symfony/* 7.x 要求 >= 8.2；项目代码本身未使用 8.3+ 独有语法）
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
        'php_min'           => '8.2.0',
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
        'path'               => $basePath . '/runtime/logs',
        'level'              => Env::str('LOG_LEVEL', 'debug'),   // debug | info | warn | error
        // 按天分割（{role}_{YYYY-MM-DD}.log）由 Logger 内部固化，无需配置项
        'max_size_mb'        => Env::int('LOG_MAX_MB', 10),       // workerman.log 单文件上限（MB），0 = 不轮转
        'keep_days'          => Env::int('LOG_KEEP_DAYS', 30),    // 明文日志保留天数（归档开启后它退为兜底）
        'stdout'             => Env::bool('LOG_STDOUT', true),    // 同时输出到控制台
        'global_handler'     => true,                             // 注册全局异常 / 错误 / 致命错误捕获

        /* 归档：把超期明文压进 archive/{YYYY-MM}.tar.gz 后删除明文（默认关闭）。
         | 不变量：0 < archive_after_days < keep_days —— 反之明文会先被 cleanup 删掉，
         | 归档永远拿不到内容。违反时 Logger 只告警并跳过本轮，不阻断启动。
         | 归档产物后缀为 .tar.gz，与 cleanup 的 .log 判据天然隔离，不会被误删。 */
        'archive_enable'     => Env::bool('LOG_ARCHIVE_ENABLE'),
        'archive_after_days' => Env::int('LOG_ARCHIVE_AFTER_DAYS', 7),    // 明文转为归档的天数
        'archive_dir'        => Env::str('LOG_ARCHIVE_DIR'),         // 空 = runtime/logs/archive
        'archive_keep_days'  => Env::int('LOG_ARCHIVE_KEEP_DAYS', 180),  // 归档包保留天数
        'archive_level'      => Env::int('LOG_ARCHIVE_LEVEL', 6),        // gzip 级别 1~9，越界回落 6
    ],

    /* ---------------------------------------------------------------
     | Redis 中心存储（会话 / 设备映射 / 缓存 / 监控指标）
     |
     | 统一使用 workerman/redis 异步客户端，杜绝同步 IO 阻塞事件循环
     --------------------------------------------------------------- */
    'redis' => [
        'host'               => Env::str('REDIS_HOST', '127.0.0.1'),
        'port'               => Env::int('REDIS_PORT', 6379),
        'password'           => Env::str('REDIS_PASSWORD'),
        'database'           => Env::int('REDIS_DB'),
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
        'secret' => Env::str('INTERNAL_SECRET'),
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
        'secret'       => Env::str('AUTH_SECRET'),
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
     |
     | 键名（session: / heartbeat: / uid:clients: / device:client: / online:*）
     | 统一声明于 RedisKeys，此处不再重复定义。
     --------------------------------------------------------------- */
    'session' => [
        'ttl'           => Env::int('SESSION_TTL', 7200),          // 会话 Redis 过期时间（秒）
        'heartbeat_ttl' => Env::int('SESSION_HEARTBEAT_TTL', 90),  // 与 gateway.heartbeat.session_timeout 一致
        'restore'       => Env::bool('SESSION_RESTORE', true),     // 断线重连自动恢复历史会话
    ],

    /* ---------------------------------------------------------------
     | 单对一定向推送（文档 4.3 的推送侧实现）
     |
     | offline_mode = drop  : 目标不在线时直接丢弃，仅计指标
     | offline_mode = queue : 写入 push:offline:{uid} 列表，设备重连后投递
     --------------------------------------------------------------- */
    'push' => [
        'enable'          => Env::bool('PUSH_ENABLE', true),
        'offline_mode'    => Env::str('PUSH_OFFLINE_MODE', 'queue'),   // drop | queue
        'offline_ttl'     => Env::int('PUSH_OFFLINE_TTL', 86400),      // 离线消息保留时长（秒）
        'offline_max'     => Env::int('PUSH_OFFLINE_MAX', 100),        // 单用户离线消息条数上限
        'replay_batch'    => Env::int('PUSH_REPLAY_BATCH', 50),        // 重连补投单批条数
        'idempotent'      => Env::bool('PUSH_IDEMPOTENT', true),       // 按 msg_id 去重
        'idempotent_ttl'  => Env::int('PUSH_IDEMPOTENT_TTL', 600),     // 去重窗口（秒）
        'payload_max'     => Env::int('PUSH_PAYLOAD_MAX', 4096),       // 单条业务数据体上限（字节）
    ],

    /* ---------------------------------------------------------------
     | HTTP 推送接口（独立进程，仅受理推送入队）
     |
     | 鉴权：HMAC-SHA256(timestamp|rawBody, api.secret)，时间戳用于防重放
     | 权限分离：API 进程只写队列，不直接持有 Gateway 连接与业务密钥
     --------------------------------------------------------------- */
    'api' => [
        'enable'    => Env::bool('API_ENABLE', true),
        'listen'    => Env::str('API_LISTEN', 'http://127.0.0.1:8290'),
        'name'      => 'GW-API',                                 // 进程名，结构性
        'secret'    => Env::str('API_SECRET'),

        // 接口验签开关。**仅供本地调试**，默认开启。
        //
        // 关闭只对回环监听生效：listen 绑定非回环地址（0.0.0.0 / 具体网卡 / 域名）时
        // 本开关被忽略，强制按开启处理。护栏意义在于：即使误把 API_SIGN_ENABLE=false
        // 写进了生产 .env，只要接口对外监听就仍然验签 —— /push 可推任意消息、
        // /action 可执行动作，无鉴权暴露到网络等于业务入口裸奔。
        //
        // 建议只写进 .env.local（已 gitignore，优先级高于 .env），不要动 .env。
        'sign_enable' => Env::bool('API_SIGN_ENABLE', true),

        'sign_ttl'  => Env::int('API_SIGN_TTL', 300),            // 请求时间戳有效窗口（秒）
        'rate'      => Env::int('API_RATE_LIMIT', 600),          // 单 IP 每分钟请求上限，0 = 不限
        'body_max'  => Env::int('API_BODY_MAX', 65536),          // 请求体上限（字节）

        // POST /action 的同步等待窗口（毫秒）。动作由业务进程经队列执行，
        // 本进程只轮询结果，故等待窗必须**大于**动作自身的回执超时
        // （ACTION_TIMEOUT），否则会在动作还能给出结果时先行返回 202。
        // 超窗后不视为失败：任务仍在执行，结果可经 GET /action/{id} 补查。
        'action_wait' => Env::int('API_ACTION_WAIT_MS', 6000),
    ],

    /* ---------------------------------------------------------------
     | 监控面板（独立只读进程）
     |
     | 与 HTTP 推送接口严格分离：面板进程只读 Redis 中的指标数据，
     | 不接触推送链路、不持有业务密钥，因此读写权限与故障域都不交叉。
     |
     | 默认仅监听 127.0.0.1 —— 运维数据不应直接暴露到公网；
     | 需要远程访问时经 Nginx 反代（附加 Basic Auth）或 SSH 隧道。
     --------------------------------------------------------------- */
    'dashboard' => [
        'enable'    => Env::bool('DASHBOARD_ENABLE', true),
        'listen'    => Env::str('DASHBOARD_LISTEN', 'http://127.0.0.1:8291'),
        'name'      => 'GW-DASH',                                // 进程名，结构性
        'view_path' => $basePath . '/resources/dashboard',       // 页面模板目录，结构性
        'refresh'   => Env::int('DASHBOARD_REFRESH', 30),         // 页面轮询间隔（秒），0 = 不自动刷新
    ],

    /* ---------------------------------------------------------------
     | 报文级限流
     |
     | 算法为令牌桶：rate 为令牌补充速率（个/秒，即长期平均上限），
     | burst 为桶容量（即允许的瞬时突发条数），burst 不得小于 rate。
     |
     | 分层：
     |   ip    —— L1 网关防护，进程内内存桶（零 IO），仅 UDP 网关使用
     |   conn  —— L2 业务限流，每连接 clientId（Redis 桶）
     |   uid   —— L2 业务限流，每用户 uid（Redis 桶）
     |   ping  —— 心跳指令独立配额（替代 conn 维度，比业务更严）
     |
     | rate 置 0 表示关闭该维度限流。
     | Redis 不可用时 L2 按 fail-open 放行（限流故障不应导致业务中断）。
     --------------------------------------------------------------- */
    'rate_limit' => [
        'enable'          => Env::bool('RATE_LIMIT_ENABLE', true),

        'conn'            => [
            'rate'  => Env::int('RATE_LIMIT_CONN_RATE', 20),      // 每连接 20 条/秒
            'burst' => Env::int('RATE_LIMIT_CONN_BURST', 40),     // 瞬时允许 40 条
        ],
        'uid'             => [
            'rate'  => Env::int('RATE_LIMIT_UID_RATE', 50),       // 每用户 50 条/秒
            'burst' => Env::int('RATE_LIMIT_UID_BURST', 100),
        ],
        'ip'              => [
            'rate'  => Env::int('RATE_LIMIT_IP_RATE', 200),       // 每 IP 200 条/秒（网关层）
            'burst' => Env::int('RATE_LIMIT_IP_BURST', 400),
        ],
        'ping'            => [
            'rate'  => Env::int('RATE_LIMIT_PING_RATE', 5),       // 心跳 5 条/秒
            'burst' => Env::int('RATE_LIMIT_PING_BURST', 10),
        ],

        'close_on_exceed' => Env::bool('RATE_LIMIT_CLOSE'),   // 超限是否断开连接
        'notify'          => Env::bool('RATE_LIMIT_NOTIFY', true),   // 超限是否回错误报文（UDP 恒定不回）
        'mem_max_buckets' => Env::int('RATE_LIMIT_MEM_MAX', 20000),  // L1 内存桶数量上限
    ],

    /* ---------------------------------------------------------------
     | 订阅关系
     |
     | 主题 <-> 用户 的双向索引存于 Redis（Subscribe 类维护），
     | 按主题广播的投递入口为 Push::enqueueTopic()。
     |
     | 注意：业务动作清单本身是结构性配置，声明在 config/actions.php，
     | 不在此处重复；本节仅承载随环境变化的行为参数。
     --------------------------------------------------------------- */
    'subscribe' => [
        'enable'             => Env::bool('SUBSCRIBE_ENABLE', true),
        'ttl'                => Env::int('SUBSCRIBE_TTL'),            // 订阅关系过期时间（秒），0 = 永不过期
        'max_topics_per_uid' => Env::int('SUBSCRIBE_MAX_TOPICS', 100),   // 单用户订阅主题数上限，0 = 不限
    ],

    /* ---------------------------------------------------------------
     | 监控指标
     --------------------------------------------------------------- */
    'monitor' => [
        'enable'   => Env::bool('MONITOR_ENABLE', true),
        'interval' => Env::int('MONITOR_INTERVAL', 60),   // 上报周期（秒）
        'ttl'      => Env::int('MONITOR_TTL', 600),       // 指标数据保留时长（秒）

        // 结构性配置：需要采集的指标名列表
        'metrics'  => [
            'conn_total', 'conn_ws', 'conn_udp',
            'msg_in', 'msg_out', 'msg_fail',
            'auth_success', 'auth_fail',
            'heartbeat_timeout', 'memory_bytes',
            'push_in', 'push_out', 'push_fail', 'push_offline', 'push_replay', 'push_dedup', 'push_ack',
            'push_topic', 'push_topic_targets',
            'udp_out_queued', 'udp_out', 'udp_out_fail',
            'conn_error', 'buffer_full', 'buffer_drain',
            // 业务动作：前四项由 ActionRunner 统一采集（与具体动作无关），
            // 其余为各处理器内部自采，新增动作时需同步追加。
            // action_http_* 为 HTTP 通道的分通道计数 —— 既有 ws / udp 沿用上面的
            // 聚合指标，只有 HTTP 单独计数，便于从合计值中区分调用来源。
            'action_in', 'action_ok', 'action_fail', 'action_timeout',
            'action_echo', 'action_session', 'action_report',
            'action_subscribe', 'action_unsubscribe', 'action_topics', 'action_notify',
            'action_http_in', 'action_http_ok', 'action_http_fail', 'action_http_timeout',
            'action_http_dequeue',
            'rate_limit_hit', 'rate_limit_ip', 'rate_limit_conn', 'rate_limit_uid', 'rate_limit_ping',
        ],
    ],
];
