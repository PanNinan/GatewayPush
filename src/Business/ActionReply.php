<?php
/**
 * HTTP 通道的动作结果回程桥
 *
 * ---------------------------------------------------------------------
 * 解决的问题
 * ---------------------------------------------------------------------
 * WS 与 UDP 的回执都是「单向下发」—— WS 经 GatewayClient::sendToClient 发给
 * 连接，UDP 经出站队列交给网关 sendto。两者都不需要把结果交还给某个等待者。
 *
 * HTTP 不是这样：调用方是**同步等待的外部系统**，但真正的动作执行发生在
 * BusinessWorker 进程里（api 进程刻意不感知会话状态、不直连业务）。
 * 两者之间隔着一条 Redis 队列，必须再有一条**回程通道**把结果送回 api 进程。
 *
 * 本类即该回程通道：业务进程把动作回执写入一个带 TTL 的 Redis 键，
 * api 进程轮询取回。
 *
 * ---------------------------------------------------------------------
 * 键设计
 * ---------------------------------------------------------------------
 *   action:result:{request_id}   String   TTL = action_queue.result_ttl
 *
 * 键名声明于 RedisKeys（见 RedisKeys::ACTION_RESULT），本类只负责读写语义。
 *
 * 值即动作回执报文本身（Message::ack / Message::error 的 JSON 文本），
 * 因此 api 进程可以直接把它作为 packet 字段透出，不需要二次映射。
 *
 * ---------------------------------------------------------------------
 * 为什么用 SET NX EX（首次胜出）
 * ---------------------------------------------------------------------
 * 动作可能同时有两条回执路径：
 *   1. 处理器正常回执；
 *   2. ActionRunner 的超时兜底（处理器未在 timeout 内回执）。
 * 用 SET NX 让**先到者胜出**，后者静默丢弃并记 debug 日志 —— 与「超时即失败」
 * 的语义一致：兜底一旦写入，说明调用方已经等过一次完整超时，此时再被迟到的
 * 正常结果覆盖反而会掩盖超时事实。
 *
 * 注：clientId 取 http:{request_id} 而非复用 WS 的数字 ID，是为了让
 * ActionRunner::channelOf() 能用与前缀表同构的方式识别通道，无需额外传参。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Business;

use GatewayPush\Common\Logger;
use GatewayPush\Common\RedisClient;
use GatewayPush\Common\RedisKeys;

class ActionReply
{
    /**
     * HTTP 通道的虚拟 clientId 前缀
     *
     * 与 Push::UDP_PREFIX 同构：通道由 clientId 前缀推断，不额外传参。
     */
    const CLIENT_PREFIX = 'http:';

    /**
     * 默认结果保留时长（秒）
     */
    const DEFAULT_TTL = 60;

    /**
     * request_id 合法字符集与长度
     *
     * request_id 会直接参与 Redis 键拼接，必须限制字符集以避免键空间污染。
     */
    const REQUEST_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * 结果键保留时长（秒）
     *
     * @var int
     */
    protected static $ttl = self::DEFAULT_TTL;

    /**
     * 初始化
     *
     * @param array $config business.action_queue 配置
     * @return void
     */
    public static function init(array $config = array())
    {
        if (isset($config['result_ttl'])) {
            self::$ttl = max(1, (int)$config['result_ttl']);
        }
    }

    /**
     * @return int
     */
    public static function ttl()
    {
        return self::$ttl;
    }

    /**
     * 拼装 HTTP 通道的虚拟 clientId
     *
     * @param string $requestId
     * @return string
     */
    public static function clientId($requestId)
    {
        return self::CLIENT_PREFIX . (string)$requestId;
    }

    /**
     * 从 clientId 还原 request_id（非 HTTP 通道返回空串）
     *
     * @param string $clientId
     * @return string
     */
    public static function requestId($clientId)
    {
        $clientId = (string)$clientId;
        if (!self::isHttpClient($clientId)) {
            return '';
        }
        return substr($clientId, strlen(self::CLIENT_PREFIX));
    }

    /**
     * 是否为 HTTP 通道的 clientId
     *
     * @param string $clientId
     * @return bool
     */
    public static function isHttpClient($clientId)
    {
        return str_starts_with((string)$clientId, self::CLIENT_PREFIX);
    }

    /**
     * request_id 是否合法（键空间保护的唯一入口）
     *
     * @param string $requestId
     * @return bool
     */
    public static function validRequestId($requestId)
    {
        return preg_match(self::REQUEST_ID_PATTERN, (string)$requestId) === 1;
    }

    /* ---------------------------------------------------------------------
     | 写入（业务进程）/ 读取（api 进程）
     --------------------------------------------------------------------- */

    /**
     * 写入动作回执（供 ActionRunner 的 sender 调用）
     *
     * 首次写入胜出，重复写入静默丢弃。
     *
     * @param string        $clientId HTTP 通道的虚拟 clientId
     * @param array         $packet   已构造的回执报文
     * @param callable|null $cb       function(bool $first)
     * @return void
     */
    public static function store($clientId, array $packet, ?callable $cb = null)
    {
        $requestId = self::requestId($clientId);

        if ($requestId === '' || !self::validRequestId($requestId)) {
            // 键名不可信时宁可丢弃也不写入，避免请求方通过 request_id 污染键空间
            Logger::warn('HTTP 动作回执缺少合法 request_id，已丢弃', array(
                'client_id' => $clientId,
            ));
            if ($cb) {
                $cb(false);
            }
            return;
        }

        $key     = RedisKeys::actionResult($requestId);
        $payload = Message::encode($packet);

        RedisClient::setNxEx($key, $payload, self::$ttl, function ($first) use ($requestId, $cb) {
            if (!$first) {
                Logger::debug('HTTP 动作回执已存在，保留首次结果', array('request_id' => $requestId));
            }
            if ($cb) {
                $cb($first);
            }
        });
    }

    /**
     * 读取动作回执（供 api 进程轮询）
     *
     * 未就绪 / 已过期均返回 null —— 二者对调用方语义相同：当前拿不到结果。
     *
     * @param string   $requestId
     * @param callable $cb        function(array|null $packet)
     * @return void
     */
    public static function fetch($requestId, callable $cb)
    {
        $requestId = (string)$requestId;
        if (!self::validRequestId($requestId)) {
            $cb(null);
            return;
        }

        RedisClient::get(RedisKeys::actionResult($requestId), function ($raw) use ($cb) {
            if (!is_string($raw) || $raw === '') {
                $cb(null);
                return;
            }

            $packet = json_decode($raw, true);
            if (!is_array($packet)) {
                Logger::warn('HTTP 动作回执反序列化失败，按未就绪处理');
                $cb(null);
                return;
            }

            $cb($packet);
        });
    }
}
