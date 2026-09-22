<?php
/**
 * Gateway 网关层配置
 *
 * 覆盖：Register 注册中心、WebSocket 网关、UDP 网关、心跳策略
 * 原则：网关进程只做网络调度，不承载任何业务逻辑
 *
 * 取值约定：环境相关项经 Env 读取，变量清单见 .env.example
 */

use GatewayPush\Common\Env;
use GatewayPush\Common\RedisKeys;

$basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);

// 注册中心监听地址，作为单机部署时的统一来源（business 端默认复用同一变量）
$registerListen = Env::str('REGISTER_LISTEN', '127.0.0.1:1238');

return [
    /* ---------------------------------------------------------------
     | Register 注册中心（Gateway 与 BusinessWorker 的地址发现服务）
     |
     | 单机部署时随主进程一起拉起；集群部署可独立部署并改为远程地址
     --------------------------------------------------------------- */
    'register' => [
        'enable' => Env::bool('REGISTER_ENABLE', true),
        'listen' => $registerListen,
        'name'   => 'Register',   // 进程名，结构性
    ],

    /* ---------------------------------------------------------------
     | WebSocket 网关（长连接实时推送）
     --------------------------------------------------------------- */
    'websocket' => [
        'enable'     => Env::bool('WS_ENABLE', true),
        'listen'     => Env::str('WS_LISTEN', 'websocket://0.0.0.0:8282'),
        'name'       => 'GW-WS',                          // 进程名，结构性
        'count'      => Env::int('WS_COUNT', 4),          // Windows 下自动降级为 1
        'lan_ip'     => Env::str('WS_LAN_IP', '127.0.0.1'),   // 集群部署时改为本机内网 IP
        'start_port' => Env::int('WS_START_PORT', 2300),      // 内部通信端口起始值，多机需错开

        // SSL（生产环境 WSS 必需）
        'ssl' => [
            'enable'      => Env::bool('SSL_ENABLE'),
            'local_cert'  => Env::str('SSL_CERT'),
            'local_pk'    => Env::str('SSL_PK'),
            'verify_peer' => false,   // 自签 / 单域名证书场景下的固定策略
        ],
    ],

    /* ---------------------------------------------------------------
     | UDP 网关（轻量化数据上报 / 离线推送）
     |
     | UDP 无连接，采用应用层会话识别（报文内 device_id + token）
     | 网关进程仅做协议解析与签名校验，业务处理经 Redis 队列解耦投递
     |
     | queue     入站：客户端 -> 网关 -> 业务进程
     | out_queue 出站：业务进程 -> 网关 -> 客户端（定向推送）
     |   UDP 的 client_id 形如 udp:ip:port，不在 Gateway 连接表内，
     |   sendToClient 对其无效，因此出站必须由网关进程直接 sendto。
     --------------------------------------------------------------- */
    'udp' => [
        'enable'          => Env::bool('UDP_ENABLE', true),
        'listen'          => Env::str('UDP_LISTEN', 'udp://0.0.0.0:8283'),
        'name'            => 'GW-UDP',                          // 进程名，结构性
        'count'           => Env::int('UDP_COUNT', 2),           // Windows 下自动降级为 1
        'max_packet_size' => Env::int('UDP_MAX_PACKET_SIZE', 8192),   // 单包上限，超出丢弃
        'queue'           => [
            'enable'  => Env::bool('UDP_QUEUE_ENABLE', true),
            'key'     => Env::str('UDP_QUEUE_KEY', RedisKeys::QUEUE_UDP_IN),
            'max_len' => Env::int('UDP_QUEUE_MAX_LEN', 10000),   // 队列长度上限，溢出丢弃并告警
        ],
        'out_queue'       => [
            'enable'   => Env::bool('UDP_OUT_QUEUE_ENABLE', true),
            'key'      => Env::str('UDP_OUT_QUEUE_KEY', RedisKeys::QUEUE_UDP_OUT),
            'batch'    => Env::int('UDP_OUT_QUEUE_BATCH', 200),      // 单次原子弹出条数
            'interval' => Env::float('UDP_OUT_QUEUE_INTERVAL', 0.05), // 出站消费周期（秒）
        ],
    ],

    /* ---------------------------------------------------------------
     | 心跳与死连接清理
     |
     | gateway_*      由 Gateway 原生实现，无需业务层参与
     | session_*      业务层兜底扫描，清理异常掉线残留的会话数据
     --------------------------------------------------------------- */
    'heartbeat' => [
        'gateway_ping_interval'           => Env::int('HB_PING_INTERVAL', 25),   // 主动心跳间隔（秒）
        'gateway_ping_data'               => '{"cmd":"ping","ts":0}',            // 结构性
        'gateway_ping_not_response_limit' => Env::int('HB_PING_NOT_RESPONSE_LIMIT', 2),
        'session_timeout'                 => Env::int('HB_SESSION_TIMEOUT', 90),  // 业务层心跳超时阈值（秒）
        'check_interval'                  => Env::int('HB_CHECK_INTERVAL', 10),   // 死连接巡检周期（秒）
    ],
];
