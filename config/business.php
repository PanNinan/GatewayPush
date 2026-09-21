<?php
/**
 * BusinessWorker 业务层配置
 *
 * 覆盖：业务进程参数、UDP 解耦队列、HTTP 动作队列、定时任务注册表
 *
 * 取值约定：环境相关项经 Env 读取，变量清单见 .env.example
 */

use GatewayPush\Common\Env;

$basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);

// 定时任务周期统一从此处取值，避免与队列 / 心跳配置出现两套真源
$udpQueueInterval    = Env::float('UDP_QUEUE_INTERVAL', 0.05);
$pushQueueInterval   = Env::float('PUSH_QUEUE_INTERVAL', 0.05);
$actionQueueInterval = Env::float('ACTION_QUEUE_INTERVAL', 0.02);
$heartbeatInterval   = Env::int('HB_CHECK_INTERVAL', 10);
$monitorInterval     = Env::int('MONITOR_INTERVAL', 60);

return [

    /* ---------------------------------------------------------------
     | 业务进程
     --------------------------------------------------------------- */
    'worker' => [
        'name'  => 'BusinessWorker',                    // 进程名，结构性
        'count' => Env::int('BUSINESS_WORKER_COUNT', 4),   // Windows 下自动降级为 1
    ],

    /* ---------------------------------------------------------------
     | 注册中心地址
     |
     | 与 config/gateway.php 的 register.listen 同源：默认复用 REGISTER_LISTEN，
     | 集群拆分部署时可用 REGISTER_ADDRESS 单独指向远程注册中心。
     --------------------------------------------------------------- */
    'register_address' => Env::str('REGISTER_ADDRESS', '') !== ''
        ? Env::str('REGISTER_ADDRESS')
        : Env::str('REGISTER_LISTEN', '127.0.0.1:1238'),

    /* ---------------------------------------------------------------
     | UDP 网关 -> 业务进程 的解耦通道
     |
     | UDP 网关进程完成协议与签名校验后写入 Redis List，
     | 业务进程定时批量消费，避免业务逻辑侵入网关层
     |
     | queue.key 与 gateway.udp.queue.key 同源（UDP_QUEUE_KEY）
     --------------------------------------------------------------- */
    'udp_queue' => [
        'enable'   => Env::bool('UDP_QUEUE_ENABLE', true),
        'key'      => Env::str('UDP_QUEUE_KEY', 'queue:udp:in'),
        'batch'    => Env::int('UDP_QUEUE_BATCH', 100),      // 单次批量消费条数
        'interval' => $udpQueueInterval,                     // 消费周期（秒）
        'max_len'  => Env::int('UDP_QUEUE_MAX_LEN', 10000),
    ],

    /* ---------------------------------------------------------------
     | 定向推送出站队列
     |
     | 外部系统（HTTP 接口 / 业务代码 / 运维命令）统一把推送任务写入该队列，
     | 由业务进程原子批量消费后执行真实推送。单一执行路径便于指标统计与幂等控制。
     |
     | queue.key 与 api / push 模块同源（PUSH_QUEUE_KEY）
     --------------------------------------------------------------- */
    'push_queue' => [
        'enable'   => Env::bool('PUSH_QUEUE_ENABLE', true),
        'key'      => Env::str('PUSH_QUEUE_KEY', 'queue:push:out'),
        'batch'    => Env::int('PUSH_QUEUE_BATCH', 200),
        'interval' => $pushQueueInterval,
        'max_len'  => Env::int('PUSH_QUEUE_MAX_LEN', 10000),  // 积压告警阈值
    ],

    /* ---------------------------------------------------------------
     | HTTP 动作队列（Api 进程 -> 业务进程）
     |
     | 与 udp_queue 同构：投递方只做验签与参数校验，真正的动作执行统一收敛到
     | 业务进程的 ActionRunner 单一路径，避免出现「第二个执行者」——那会让
     | 指标统计、限流配额、幂等与 Session 写入各自多出一处来源。
     |
     | result_ttl 同时供 ActionReply 使用：动作回执写入
     | action:result:{request_id} 并保留该时长，供 Api 取回或事后补查。
     |
     | interval 默认 0.02s（小于 udp_queue 的 0.05s）：HTTP 调用方在同步等待，
     | 消费周期直接计入响应延迟。0.02s 仍在 workerman 定时器精度范围内
     | （实际最小约 10ms），单批 100 条的处理能力远超 HTTP 接口自身的限流额度。
     --------------------------------------------------------------- */
    'action_queue' => [
        'enable'     => Env::bool('ACTION_QUEUE_ENABLE', true),
        'key'        => Env::str('ACTION_QUEUE_KEY', 'queue:action:in'),
        'batch'      => Env::int('ACTION_QUEUE_BATCH', 100),
        'interval'   => $actionQueueInterval,
        'max_len'    => Env::int('ACTION_QUEUE_MAX_LEN', 10000),   // 积压告警 / 拒绝阈值
        'result_ttl' => Env::int('ACTION_RESULT_TTL', 60),
    ],

    /* ---------------------------------------------------------------
     | 定时任务注册表（结构性配置：任务集合本身不随环境变化）
     |
     | scope = first  : 仅在 worker id = 0 的进程注册（全局唯一任务）
     | scope = all    : 每个进程都注册（需分进程独立统计的任务）
     | interval       : 秒，支持小数（受事件循环精度限制，实际最小约 10ms）
     | timeout        : 单次执行耗时上限（秒），超出输出 WARN 日志
     --------------------------------------------------------------- */
    'tasks' => [
        [
            'name'       => 'udp-queue-consume',
            'interval'   => $udpQueueInterval,
            'class'      => 'GatewayPush\Business\Bootstrap',
            'method'     => 'consumeUdpQueue',
            'persistent' => true,
            'timeout'    => 3,
            'scope'      => 'first',
        ],
        [
            'name'       => 'push-queue-consume',
            'interval'   => $pushQueueInterval,
            'class'      => 'GatewayPush\Business\Bootstrap',
            'method'     => 'consumePushQueue',
            'persistent' => true,
            'timeout'    => 3,
            'scope'      => 'first',
        ],
        [
            'name'       => 'action-queue-consume',
            'interval'   => $actionQueueInterval,
            'class'      => 'GatewayPush\Business\Bootstrap',
            'method'     => 'consumeActionQueue',
            'persistent' => true,
            'timeout'    => 3,
            'scope'      => 'first',
        ],
        [
            'name'       => 'heartbeat-timeout-scan',
            'interval'   => $heartbeatInterval,
            'class'      => 'GatewayPush\Business\Session',
            'method'     => 'checkHeartbeatTimeout',
            'persistent' => true,
            'timeout'    => 10,
            'scope'      => 'first',
        ],
        [
            'name'       => 'session-expire-scan',
            'interval'   => 60,
            'class'      => 'GatewayPush\Business\Session',
            'method'     => 'cleanExpired',
            'persistent' => true,
            'timeout'    => 30,
            'scope'      => 'first',
        ],
        [
            'name'       => 'monitor-report',
            'interval'   => $monitorInterval,
            'class'      => 'GatewayPush\Business\Monitor',
            'method'     => 'report',
            'persistent' => true,
            'timeout'    => 30,
            'scope'      => 'all',
        ],
        [
            'name'       => 'log-cleanup',
            'interval'   => 86400,
            'class'      => 'GatewayPush\Common\Logger',
            'method'     => 'cleanup',
            'persistent' => true,
            'timeout'    => 60,
            'scope'      => 'first',
        ],
    ],
];
