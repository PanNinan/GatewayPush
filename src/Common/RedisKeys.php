<?php
/**
 * Redis 键空间统一声明（唯一权威来源）
 *
 * ---------------------------------------------------------------------
 * 本文件是全部逻辑键名的**唯一声明处**，其他任何位置不得再写字面量。
 * ---------------------------------------------------------------------
 *
 * 两个层次，别混淆：
 *   1. 这里声明的是「逻辑键名」—— 不含全局前缀
 *   2. 实际落库的键 = `config/app.php` 的 `redis.prefix`（默认 `gwpush:`）+ 逻辑键名
 *      拼接由 `RedisClient::key()` 统一完成，业务代码无需感知
 *
 * 因此在本文件里看到 `session:abc`，真实键是 `gwpush:session:abc`。
 * 例外：`Session` 的批量 MGET 走 `RedisClient::key()` 显式补前缀（硬约束⑰）。
 *
 * ---------------------------------------------------------------------
 * 键空间总览
 * ---------------------------------------------------------------------
 * 一、会话与索引（src/Business/Session.php）
 *   session:{clientId}          Hash   会话主体：uid / device_id / protocol / ip / 时间戳
 *   heartbeat:{clientId}        String 最近活跃时间戳（高频写入，单独成键以降低写放大）
 *   uid:clients:{uid}           Set    uid -> clientId 集合（多设备在线）
 *   device:client:{deviceId}    String deviceId -> 当前活跃 clientId（单对一定向的定位依据）
 *   online:clients              Set    全量在线 clientId（SCARD 得在线数，集群下天然全局）
 *   online:ws / online:udp      Set    按协议维度的在线集合
 *
 * 二、鉴权（src/Business/Auth.php）
 *   auth:revoked:{fingerprint}  String Token 撤销名单（指纹为 sha256 前 32 位，不落明文 Token）
 *   auth:bind:{uid}             String uid -> deviceId 绑定关系
 *
 * 三、队列（四条，按流向分两类）
 *   queue:udp:in                List   入站：UDP 网关 -> 业务进程
 *   queue:action:in             List   入站：Api -> BusinessWorker（元素含 request_id）
 *   queue:udp:out               List   出站：业务进程 -> UDP 网关（网关 sendto）
 *   queue:push:out              List   出站：推送任务队列（所有推送入口的汇合点）
 *
 *   队列键**跨进程共享**（Api / Gateway / Business 三方按同一键名读写），
 *   故其默认值集中于此、由 config 用 RedisKeys 常量作 `Env::str` 的回落值，
 *   确保「生产者与消费者指向同一键」这一不变量不可能被破坏。
 *
 * 四、推送（src/Business/Push.php）
 *   push:offline:{uid}          List   离线消息缓存，设备重连后补投
 *   push:dedup:{md5(msgId)}     String 幂等去重标记
 *
 * 五、订阅（src/Business/Subscribe.php）
 *   subscribe:uid:{uid}         Set    用户订阅的主题集合（正向）
 *   subscribe:topic:{topic}     Set    主题的订阅者 uid 集合（反向）
 *
 * 六、动作（src/Business/ActionReply.php / Action/ReportAction.php）
 *   action:result:{requestId}   String HTTP 动作回执报文（SET NX EX 首次胜出）
 *   action:report:{topic}       String report 动作的累计计数
 *
 * 七、指标（src/Business/Monitor.php）
 *   metrics:counter:{YYYYMMDD}  Hash   当日累加型指标（HINCRBY）
 *   metrics:gauge               Hash   当前瞬时指标（HSET），按 PID 独立成字段
 *
 * 八、限流（src/Common/RateLimiter.php / src/Api/Bootstrap.php）
 *   rl:{dim}:{md5(id)}          Hash   L2 令牌桶（WS / UDP 侧，多桶原子判定）
 *   api:rate:{md5(ip)}:{分钟}    String HTTP 侧单 IP 滑动分钟窗口计数
 *
 * 九、运维（src/Common/RedisClient.php）
 *   health:probe                探测键，仅用于 EXISTS 判连通性
 *
 * ---------------------------------------------------------------------
 * 边界约定：什么不该放进来
 * ---------------------------------------------------------------------
 *   本类只收「作为 Redis 键或其前缀」的字符串。以下均**不属于**键名，各自留在原处：
 *     - clientId 前缀：`Push::UDP_PREFIX`（`udp:`）、`Gateway\Bootstrap::udpClientId()`
 *     - 进程内内存桶键：`RateLimiter::MEM_PREFIX`（`mem:`，仅作数组下标）
 *     - 枚举值：`PROTOCOL_*` / `CHANNEL_*` / `TARGET_*` / `MODE_*` / `DIM_*`
 *     - 键内字段名：如 gauge 的 `memory_bytes:{pid}` / `pid_at:{pid}` / `proc:{pid}`
 *       （它们是 Hash 的 field 而非 key，改 field 不涉及键空间）
 *
 * ---------------------------------------------------------------------
 * 维护须知
 * ---------------------------------------------------------------------
 *   1. 改键名 = 破坏存量数据兼容（在线的会话、挂起的离线消息、已撤销的 Token 名单
 *      都会失配）。**必须**同时提供 RENAME 迁移脚本并全角色重启，不可单独发版。
 *   2. 本文件的键字符串受 `tests/Unit/RedisKeysTest.php` 的金标断言保护 ——
 *      任何改动都会使该测试失败，这是刻意设计，用于拦截「顺手改个名」。
 *   3. 全部键的**最终字符串**在此集中，README 第 10 章的键空间表面向使用者，
 *      两者需保持一致。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Common;

final class RedisKeys
{
    /* ---------------------------------------------------------------------
     | 一、会话与索引
     --------------------------------------------------------------------- */

    /** 会话主体前缀，`+ clientId` */
    const SESSION = 'session:';

    /** 心跳时间戳前缀，`+ clientId`（高频写入，单独成键） */
    const HEARTBEAT = 'heartbeat:';

    /** uid -> clientId 集合前缀，`+ uid` */
    const UID_CLIENTS = 'uid:clients:';

    /** deviceId -> clientId 前缀，`+ deviceId` */
    const DEVICE_CLIENT = 'device:client:';

    /** 全量在线 clientId 集合（完整键名，无后缀） */
    const ONLINE_CLIENTS = 'online:clients';

    /** 协议维度在线集合前缀，`+ protocol`（ws / udp） */
    const ONLINE_PREFIX = 'online:';

    /* ---------------------------------------------------------------------
     | 二、鉴权
     --------------------------------------------------------------------- */

    /** Token 撤销名单前缀，`+ tokenFingerprint` */
    const AUTH_REVOKED = 'auth:revoked:';

    /** uid -> deviceId 绑定前缀，`+ uid` */
    const AUTH_BIND = 'auth:bind:';

    /* ---------------------------------------------------------------------
     | 三、队列（跨进程共享，键名一致性由常量保证）
     --------------------------------------------------------------------- */

    /** 入站队列：UDP 网关 -> 业务进程 */
    const QUEUE_UDP_IN = 'queue:udp:in';

    /** 入站队列：Api -> BusinessWorker（HTTP 动作） */
    const QUEUE_ACTION_IN = 'queue:action:in';

    /** 出站队列：业务进程 -> UDP 网关 */
    const QUEUE_UDP_OUT = 'queue:udp:out';

    /** 出站队列：推送任务汇合点 */
    const QUEUE_PUSH_OUT = 'queue:push:out';

    /* ---------------------------------------------------------------------
     | 四、推送
     --------------------------------------------------------------------- */

    /** 离线消息列表前缀，`+ uid` */
    const PUSH_OFFLINE = 'push:offline:';

    /** 幂等去重标记前缀，`+ md5(msgId)` */
    const PUSH_DEDUP = 'push:dedup:';

    /* ---------------------------------------------------------------------
     | 五、订阅
     --------------------------------------------------------------------- */

    /** 正向索引前缀，`+ uid` */
    const SUBSCRIBE_UID = 'subscribe:uid:';

    /** 反向索引前缀，`+ topic` */
    const SUBSCRIBE_TOPIC = 'subscribe:topic:';

    /* ---------------------------------------------------------------------
     | 六、动作
     --------------------------------------------------------------------- */

    /** HTTP 动作回执报文前缀，`+ requestId` */
    const ACTION_RESULT = 'action:result:';

    /** report 动作累计计数前缀，`+ topic` */
    const ACTION_REPORT = 'action:report:';

    /* ---------------------------------------------------------------------
     | 七、指标
     --------------------------------------------------------------------- */

    /** 累加型指标 Hash 前缀，`+ YYYYMMDD` */
    const METRICS_COUNTER = 'metrics:counter:';

    /** 瞬时指标 Hash（完整键名，无后缀） */
    const METRICS_GAUGE = 'metrics:gauge';

    /* ---------------------------------------------------------------------
     | 八、限流
     --------------------------------------------------------------------- */

    /** L2 令牌桶前缀，`+ dim + ':' + md5(id)` */
    const RATE_LIMIT_BUCKET = 'rl:';

    /** HTTP 侧单 IP 分钟窗口前缀，`+ md5(ip) + ':' + 分钟序号` */
    const RATE_LIMIT_API = 'api:rate:';

    /* ---------------------------------------------------------------------
     | 九、运维
     --------------------------------------------------------------------- */

    /** 连通性探测键（仅 EXISTS，不写入） */
    const HEALTH_PROBE = 'health:probe';

    /* =====================================================================
     | 构造方法
     |
     | 带动态后缀的键一律经此处拼装，调用方不得自行 `前缀 . 后缀`。
     | 这样后缀的编码方式（md5 / 时间格式 / 分隔符）也只有一处实现。
     ===================================================================== */

    /**
     * 会话主体键
     *
     * @param string $clientId
     * @return string
     */
    public static function session($clientId)
    {
        return self::SESSION . (string)$clientId;
    }

    /**
     * 心跳时间戳键
     *
     * @param string $clientId
     * @return string
     */
    public static function heartbeat($clientId)
    {
        return self::HEARTBEAT . (string)$clientId;
    }

    /**
     * uid -> clientId 集合键
     *
     * @param string $uid
     * @return string
     */
    public static function uidClients($uid)
    {
        return self::UID_CLIENTS . (string)$uid;
    }

    /**
     * deviceId -> clientId 键
     *
     * @param string $deviceId
     * @return string
     */
    public static function deviceClient($deviceId)
    {
        return self::DEVICE_CLIENT . (string)$deviceId;
    }

    /**
     * 在线集合键
     *
     * 空 $protocol 取全量在线集合（online:clients），否则取协议维度集合（online:ws / online:udp）。
     * 二者由同一方法产出，避免调用方在两套前缀间手工二选一。
     *
     * @param string $protocol 空 = 全量；否则 Session::PROTOCOL_WS / PROTOCOL_UDP
     * @return string
     */
    public static function online($protocol = '')
    {
        $protocol = (string)$protocol;

        return $protocol === '' ? self::ONLINE_CLIENTS : self::ONLINE_PREFIX . $protocol;
    }

    /**
     * Token 撤销名单键
     *
     * 只接收**已算好的指纹**：指纹截断方式属鉴权语义（见 Auth::tokenFingerprint），
     * 不由键空间类裁定。
     *
     * @param string $fingerprint
     * @return string
     */
    public static function authRevoked($fingerprint)
    {
        return self::AUTH_REVOKED . (string)$fingerprint;
    }

    /**
     * uid -> deviceId 绑定键
     *
     * @param string $uid
     * @return string
     */
    public static function authBind($uid)
    {
        return self::AUTH_BIND . (string)$uid;
    }

    /**
     * 离线消息列表键
     *
     * @param string $uid
     * @return string
     */
    public static function pushOffline($uid)
    {
        return self::PUSH_OFFLINE . (string)$uid;
    }

    /**
     * 幂等去重键
     *
     * @param string $msgId
     * @return string
     */
    public static function pushDedup($msgId)
    {
        return self::PUSH_DEDUP . md5((string)$msgId);
    }

    /**
     * 用户订阅主题集合键（正向）
     *
     * @param string $uid
     * @return string
     */
    public static function subscribeUid($uid)
    {
        return self::SUBSCRIBE_UID . (string)$uid;
    }

    /**
     * 主题订阅者集合键（反向）
     *
     * @param string $topic
     * @return string
     */
    public static function subscribeTopic($topic)
    {
        return self::SUBSCRIBE_TOPIC . (string)$topic;
    }

    /**
     * HTTP 动作回执键
     *
     * @param string $requestId
     * @return string
     */
    public static function actionResult($requestId)
    {
        return self::ACTION_RESULT . (string)$requestId;
    }

    /**
     * report 动作累计计数键
     *
     * @param string $topic
     * @return string
     */
    public static function actionReport($topic)
    {
        return self::ACTION_REPORT . (string)$topic;
    }

    /**
     * 当日累加指标键
     *
     * @param string|null $date YYYYMMDD，空则取当日
     * @return string
     */
    public static function metricsCounter($date = null)
    {
        $date = ($date === null || $date === '') ? date('Ymd') : (string)$date;

        return self::METRICS_COUNTER . $date;
    }

    /**
     * L2 限流桶键
     *
     * 主体标识统一 md5 压缩：uid 可能含任意字符，且避免键名过长。
     *
     * @param string $dim 维度（RateLimiter::DIM_*）
     * @param string $id  主体标识（clientId / uid / ip）
     * @return string
     */
    public static function rateBucket($dim, $id)
    {
        return self::RATE_LIMIT_BUCKET . (string)$dim . ':' . md5((string)$id);
    }

    /**
     * HTTP 侧单 IP 分钟窗口键
     *
     * @param string   $ip
     * @param int|null $minute 分钟序号，空则取当前（floor(time() / 60)）
     * @return string
     */
    public static function rateApi($ip, $minute = null)
    {
        $slot = ($minute === null) ? (int)floor(time() / 60) : (int)$minute;

        return self::RATE_LIMIT_API . md5((string)$ip) . ':' . $slot;
    }
}
