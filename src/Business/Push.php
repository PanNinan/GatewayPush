<?php
/**
 * 单对一定向推送服务
 *
 * 本类是整个服务对外输出能力的唯一出口，所有推送最终都经由 dispatch() 执行，
 * 保证指标统计、幂等去重、离线缓存三条横切逻辑只有一处实现。
 *
 * ---------------------------------------------------------------------
 * 目标类型（target_type）
 * ---------------------------------------------------------------------
 *   uid    该 uid 名下全部在线连接（多设备场景，含 WebSocket 与 UDP）
 *   device 精确到单台设备（本项目核心场景，经 device:client 映射定位连接）
 *   client 精确到单条连接（调试 / 内部使用）
 *
 * ---------------------------------------------------------------------
 * 投递通道（由 client_id 自动判定）
 * ---------------------------------------------------------------------
 *   WebSocket  Gateway 原生通道：sendToUid / sendToClient，跨进程经 Register 路由
 *   UDP        client_id 形如 udp:ip:port，不在 Gateway 连接表内，
 *              sendToClient 对其无效，改经 Redis 出站队列交由 UDP 网关进程 sendto
 *
 * ---------------------------------------------------------------------
 * 离线策略（offline_mode）
 * ---------------------------------------------------------------------
 *   drop  目标不在线时直接丢弃，仅计指标
 *   queue 写入 push:offline:{uid} 列表，设备重连后由 replayOffline() 补投
 *
 * 注意：离线列表按 uid 聚合（而非 client_id），因为断线重连后 client_id 必然变化，
 * 而 uid 稳定。重连补投采用「投给首个恢复的连接」语义，属至少一次投递。
 *
 * Redis 键：本类涉及的 push:offline: / push:dedup: / queue:push:out / queue:udp:out
 * 统一声明于 RedisKeys，此处不再定义字面量。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Business;

use GatewayPush\Common\Logger;
use GatewayPush\Common\RedisClient;
use GatewayPush\Common\RedisKeys;
use GatewayWorker\Lib\Gateway as GatewayClient;

class Push
{
    /* ---------------------- 目标类型 ---------------------- */
    const TARGET_UID    = 'uid';
    const TARGET_DEVICE = 'device';
    const TARGET_CLIENT = 'client';

    /* ---------------------- 离线策略 ---------------------- */
    const MODE_DROP  = 'drop';
    const MODE_QUEUE = 'queue';

    /* ---------------------- 通道 ---------------------- */
    const CHANNEL_WS  = 'ws';
    const CHANNEL_UDP = 'udp';

    /* --------------- client_id 前缀（非 Redis 键） --------------- */
    /** UDP client_id 前缀（与 Gateway\Bootstrap::udpClientId 约定一致） */
    const UDP_PREFIX = 'udp:';

    /**
     * 推送配置（app.push）
     *
     * @var array
     */
    protected static $config = array(
        'enable'         => true,
        'offline_mode'   => self::MODE_QUEUE,
        'offline_ttl'    => 86400,
        'offline_max'    => 100,
        'replay_batch'   => 50,
        'idempotent'     => true,
        'idempotent_ttl' => 600,
        'payload_max'    => 4096,
    );

    /**
     * 出站队列配置（business.push_queue）
     *
     * @var array
     */
    protected static $queueConfig = array(
        'key'     => RedisKeys::QUEUE_PUSH_OUT,
        'batch'   => 200,
        'enable'  => true,
        'max_len' => 10000,
    );

    /**
     * UDP 出站队列配置（gateway.udp.out_queue）
     *
     * @var array
     */
    protected static $udpOutConfig = array(
        'enable' => true,
        'key'    => RedisKeys::QUEUE_UDP_OUT,
    );

    /**
     * 初始化
     *
     * @param array $config app.push
     * @param array $queue  business.push_queue
     * @param array $udpOut gateway.udp.out_queue
     * @return void
     */
    public static function init(array $config, array $queue = [], array $udpOut = array())
    {
        self::$config = array_merge(self::$config, $config);
        if ($queue) {
            self::$queueConfig = array_merge(self::$queueConfig, $queue);
        }
        if ($udpOut) {
            self::$udpOutConfig = array_merge(self::$udpOutConfig, $udpOut);
        }

        $mode = (string)self::$config['offline_mode'];
        if ($mode !== self::MODE_DROP && $mode !== self::MODE_QUEUE) {
            // 配置非法时回退到保守策略并显式告警，避免静默行为偏离预期
            Logger::warn('推送离线策略取值非法，已回退为 drop', array('offline_mode' => $mode));
            self::$config['offline_mode'] = self::MODE_DROP;
        }
    }

    /**
     * 功能是否启用
     *
     * @return bool
     */
    public static function enabled()
    {
        return !empty(self::$config['enable']);
    }

    /**
     * 当前离线策略
     *
     * @return string
     */
    public static function offlineMode()
    {
        return (string)self::$config['offline_mode'];
    }

    /* ---------------------------------------------------------------------
     | 触发入口
     --------------------------------------------------------------------- */

    /**
     * 写入推送队列（外部系统 / HTTP 接口 / 运维命令的唯一入口）
     *
     * @param string        $targetType uid | device | client
     * @param string        $target
     * @param array         $payload    业务数据体
     * @param array         $opts       ['msg_id'=>.., 'offline_mode'=>.., 'source'=>..]
     * @param callable|null $cb         function(bool $ok)
     * @return void
     */
    public static function enqueue($targetType, $target, array $payload, array $opts = [], callable $cb = null)
    {
        if (!self::enabled()) {
            Logger::warn('推送功能未启用，任务已丢弃', array('target_type' => $targetType, 'target' => $target));
            if ($cb) {
                call_user_func($cb, false);
            }
            return;
        }

        $targetType = self::normalizeTargetType($targetType);
        $target     = (string)$target;
        if ($target === '') {
            Logger::warn('推送目标为空，任务已丢弃', array('target_type' => $targetType));
            if ($cb) {
                call_user_func($cb, false);
            }
            return;
        }

        $job = array(
            'target_type'  => $targetType,
            'target'       => $target,
            'payload'      => $payload,
            'msg_id'       => isset($opts['msg_id']) ? (string)$opts['msg_id'] : '',
            'offline_mode' => isset($opts['offline_mode']) ? (string)$opts['offline_mode'] : '',
            'source'       => isset($opts['source']) ? (string)$opts['source'] : 'unknown',
            'enqueue_at'   => microtime(true),
        );

        $raw = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($raw === false) {
            Logger::error('推送任务序列化失败', array('target_type' => $targetType, 'target' => $target));
            if ($cb) {
                call_user_func($cb, false);
            }
            return;
        }

        $key    = self::$queueConfig['key'];
        $maxLen = (int)self::$queueConfig['max_len'];

        Monitor::incr('push_in');

        RedisClient::rPush($key, $raw, function ($result) use ($cb, $key, $maxLen, $targetType, $target) {
            $ok = is_int($result);
            if (!$ok) {
                Logger::error('推送任务入队失败', array(
                    'queue'       => $key,
                    'target_type' => $targetType,
                    'target'      => $target,
                ));
            } elseif ($maxLen > 0 && $result > $maxLen) {
                Logger::warn('推送队列积压超限，消费能力不足', array(
                    'queue'  => $key,
                    'length' => $result,
                    'max'    => $maxLen,
                ));
            }
            if ($cb) {
                call_user_func($cb, $ok);
            }
        });
    }

    /**
     * 直接推送（进程内调用，跳过队列）
     *
     * 适用于业务代码在处理上行报文时顺带回推结果，省去一次队列往返。
     *
     * @param string $targetType
     * @param string $target
     * @param array  $payload
     * @param array  $opts
     * @return void
     */
    public static function direct($targetType, $target, array $payload, array $opts = array())
    {
        self::dispatch(array(
            'target_type'  => self::normalizeTargetType($targetType),
            'target'       => (string)$target,
            'payload'      => $payload,
            'msg_id'       => isset($opts['msg_id']) ? (string)$opts['msg_id'] : '',
            'offline_mode' => isset($opts['offline_mode']) ? (string)$opts['offline_mode'] : '',
            'source'       => isset($opts['source']) ? (string)$opts['source'] : 'direct',
        ));
    }

    /**
     * 向指定 UDP 连接下发报文（不经队列）
     *
     * 用途：业务动作在 UDP 通道上的回执下发。
     * UDP 的 clientId 不在 Gateway 连接表内，sendToClient 对其无效，
     * 因此统一改为写入网关出站队列，由 UDP 网关进程 sendto
     * —— 与在线推送走同一条已落地的出站通道。
     *
     * @param string $clientId 形如 udp:{ip}:{port}
     * @param array  $packet   已构造的报文数组
     * @param string $uid      仅用于日志串联
     * @param string $msgId    仅用于日志串联
     * @return bool
     */
    public static function sendToUdpClient($clientId, array $packet, $uid = '', $msgId = '')
    {
        if (! str_starts_with((string)$clientId, self::UDP_PREFIX)) {
            Logger::warn('非 UDP 连接不可经出站队列投递', array('client_id' => $clientId));
            return false;
        }

        self::deliverUdp($clientId, Message::encode($packet), (string)$uid, (string)$msgId);

        return true;
    }

    /**
     * 按订阅主题广播（订阅 -> 推送的闭环入口）
     *
     * 订阅关系由 Subscribe 维护，本方法只负责把一条消息投递给该主题下的
     * 全部订阅者。逐个 uid 入队（而非合并下发），使离线缓存 / 幂等 / 指标
     * 全部复用既有单目标链路，无需为广播单独维护一套逻辑。
     *
     * 注意：调用方若指定 msg_id，必须为每个目标派生独立的 msg_id ——
     * 幂等键是 md5(msg_id)，多目标共用同一 msg_id 会导致除首个目标外
     * 全部被判定为重复而静默丢弃。本方法已代为派生。
     *
     * @param string        $topic
     * @param array         $payload
     * @param array         $opts
     * @param callable|null $cb function(int $targets) 入队目标数
     * @return void
     */
    public static function enqueueTopic($topic, array $payload, array $opts = [], callable $cb = null)
    {
        $topic = (string)$topic;
        if ($topic === '') {
            if ($cb) {
                call_user_func($cb, 0);
            }
            return;
        }

        Subscribe::subscribers($topic, function ($uids) use ($topic, $payload, $opts, $cb) {
            if (!$uids) {
                Logger::debug('主题无订阅者，广播跳过', array('topic' => $topic));
                if ($cb) {
                    call_user_func($cb, 0);
                }
                return;
            }

            $baseMsgId = isset($opts['msg_id']) ? (string)$opts['msg_id'] : '';

            foreach ($uids as $uid) {
                $itemOpts = $opts;
                if ($baseMsgId !== '') {
                    $itemOpts['msg_id'] = $baseMsgId . ':' . $uid;
                }
                self::enqueue(self::TARGET_UID, $uid, $payload, $itemOpts);
            }

            Monitor::incr('push_topic');
            Monitor::incr('push_topic_targets', count($uids));

            Logger::info('主题广播已入队', array(
                'topic'   => $topic,
                'targets' => count($uids),
            ));

            if ($cb) {
                call_user_func($cb, count($uids));
            }
        });
    }

    /**
     * 消费推送队列（定时任务，由业务进程调用）
     *
     * 使用 Lua 原子取批，多进程并发消费不会重复处理同一批任务。
     *
     * @return void
     */
    public static function consumeQueue()
    {
        if (!self::enabled() || empty(self::$queueConfig['enable'])) {
            return;
        }

        $key   = self::$queueConfig['key'];
        $batch = max(1, (int)self::$queueConfig['batch']);

        RedisClient::popBatch($key, $batch, function ($items) {
            foreach ($items as $raw) {
                try {
                    $job = json_decode($raw, true);
                    if (!is_array($job)) {
                        Monitor::incr('push_fail');
                        Logger::warn('推送任务格式非法，已丢弃', array('raw' => substr((string)$raw, 0, 200)));
                        continue;
                    }
                    self::dispatch($job);
                } catch (\Throwable $e) {
                    Monitor::incr('push_fail');
                    Logger::exception($e, 'push.consume');
                }
            }
        });
    }

    /* ---------------------------------------------------------------------
     | 投递核心
     --------------------------------------------------------------------- */

    /**
     * 执行一条推送任务
     *
     * 处理顺序：参数归一化 -> 数据体限额 -> 幂等去重 -> 目标解析 -> 通道投递
     *
     * @param array $job
     * @return void
     */
    public static function dispatch(array $job)
    {
        if (!self::enabled()) {
            return;
        }

        $targetType = self::normalizeTargetType(isset($job['target_type']) ? $job['target_type'] : '');
        $target     = isset($job['target']) ? (string)$job['target'] : '';
        $payload    = isset($job['payload']) && is_array($job['payload']) ? $job['payload'] : [];
        $msgId      = isset($job['msg_id']) ? (string)$job['msg_id'] : '';
        $mode       = self::resolveMode(isset($job['offline_mode']) ? $job['offline_mode'] : '');

        if ($target === '') {
            Monitor::incr('push_fail');
            Logger::warn('推送任务缺少目标，已丢弃', array('target_type' => $targetType));
            return;
        }

        // 数据体限额保护：超限报文会被 UDP 侧网关直接丢弃，此处提前拦截并告警
        $bodySize = strlen(Message::encode($payload));
        $maxBody  = (int)self::$config['payload_max'];
        if ($maxBody > 0 && $bodySize > $maxBody) {
            Monitor::incr('push_fail');
            Logger::warn('推送数据体超限，已拒绝', array(
                'target_type' => $targetType,
                'target'      => $target,
                'size'        => $bodySize,
                'max'         => $maxBody,
            ));
            return;
        }

        $execute = function () use ($targetType, $target, $payload, $msgId, $mode, $job) {
            self::resolveTargets($targetType, $target, function ($targets, $uid, $skipReason) use ($payload, $msgId, $mode, $job, $targetType, $target) {
                if (!$targets) {
                    if ($skipReason === 'offline') {
                        self::handleOffline($uid, $payload, $msgId, $mode, $job);
                        return;
                    }
                    Monitor::incr('push_fail');
                    Logger::warn('推送目标解析失败', array(
                        'target_type' => $targetType,
                        'target'      => $target,
                        'reason'      => $skipReason,
                    ));
                    return;
                }
                self::deliverAll($targets, $uid, $targetType, $target, $payload, $msgId, $job);
            });
        };

        // 幂等：仅在调用方提供 msg_id 且开关开启时生效
        if ($msgId !== '' && !empty(self::$config['idempotent'])) {
            RedisClient::setNxEx(
                RedisKeys::pushDedup($msgId),
                1,
                (int)self::$config['idempotent_ttl'],
                function ($first) use ($msgId, $execute, $target) {
                    if (!$first) {
                        Monitor::incr('push_dedup');
                        Logger::debug('推送任务重复，已跳过', array('msg_id' => $msgId, 'target' => $target));
                        return;
                    }
                    $execute();
                }
            );
            return;
        }

        $execute();
    }

    /**
     * 解析目标 -> 定位在线连接集合
     *
     * 回调参数：
     *   $targets    元素形如 ['client_id' => .., 'channel' => ws|udp, 'via' => native|session]
     *   $uid        解析出的 uid（离线缓存需要），可能为空
     *   $skipReason 未命中原因：offline（目标离线）/ invalid（目标非法）
     *
     * @param string   $targetType
     * @param string   $target
     * @param callable $cb
     * @return void
     */
    protected static function resolveTargets($targetType, $target, callable $cb)
    {
        switch ($targetType) {
            case self::TARGET_CLIENT:
                Session::get($target, function ($session) use ($cb, $target) {
                    $uid = isset($session['uid']) ? (string)$session['uid'] : '';

                    if (!self::isOnline($target, $session)) {
                        call_user_func($cb, [], $uid, 'offline');
                        return;
                    }
                    call_user_func($cb, array(self::target($target)), $uid, '');
                });
                return;

            case self::TARGET_DEVICE:
                Session::findByDevice($target, function ($clientId) use ($cb, $target) {
                    if (!is_string($clientId) || $clientId === '') {
                        call_user_func($cb, [], '', 'offline');
                        return;
                    }
                    Session::get($clientId, function ($session) use ($cb, $clientId, $target) {
                        $uid = isset($session['uid']) ? (string)$session['uid'] : '';

                        if (!self::isOnline($clientId, $session)) {
                            Logger::debug('设备映射指向的连接已离线', array(
                                'device_id' => $target,
                                'client_id' => $clientId,
                            ));
                            call_user_func($cb, [], $uid, 'offline');
                            return;
                        }
                        call_user_func($cb, array(self::target($clientId)), $uid, '');
                    });
                });
                return;

            case self::TARGET_UID:
            default:
                // 以 Redis uid 索引为准（同时覆盖 WebSocket 与 UDP 会话），
                // 若索引缺失但 Gateway 原生 uid 路由仍有记录，则退回原生通道兜底
                Session::findByUid($target, function ($clientIds) use ($cb, $target) {
                    if (!is_array($clientIds) || !$clientIds) {
                        if (self::nativeUidOnline($target)) {
                            call_user_func($cb, array(self::target($target, self::CHANNEL_WS, 'native')), $target, '');
                            return;
                        }
                        call_user_func($cb, [], $target, 'offline');
                        return;
                    }

                    $pending = count($clientIds);
                    $targets = [];

                    foreach ($clientIds as $clientId) {
                        Session::get($clientId, function ($session) use (&$pending, &$targets, $cb, $clientId, $target) {
                            if (self::isOnline($clientId, $session)) {
                                $targets[] = self::target($clientId);
                            }
                            if (--$pending === 0) {
                                if (!$targets && self::nativeUidOnline($target)) {
                                    $targets[] = self::target($target, self::CHANNEL_WS, 'native');
                                }
                                if (!$targets) {
                                    call_user_func($cb, [], $target, 'offline');
                                    return;
                                }
                                call_user_func($cb, $targets, $target, '');
                            }
                        });
                    }
                });
                return;
        }
    }

    /**
     * 实际投递：遍历目标通道并下发
     *
     * @param array  $targets
     * @param string $uid
     * @param string $targetType
     * @param string $target
     * @param array  $payload
     * @param string $msgId
     * @param array  $job
     * @return void
     */
    protected static function deliverAll(array $targets, $uid, $targetType, $target, array $payload, $msgId, array $job)
    {
        $frame = self::buildFrame($payload, $msgId, $job);
        $json  = Message::encode($frame);

        foreach ($targets as $item) {
            $clientId = $item['client_id'];
            $channel  = $item['channel'];

            // UDP 通道：连接由 UDP 网关进程持有，业务进程无法直接寻址
            if ($channel === self::CHANNEL_UDP) {
                self::deliverUdp($clientId, $json, $uid, $msgId);
                continue;
            }

            try {
                if ($item['via'] === 'native') {
                    // uid 目标交由 Gateway 原生 uid 路由，天然支持多设备并发投递
                    GatewayClient::sendToUid($uid, $json);
                } else {
                    GatewayClient::sendToClient($clientId, $json);
                }
                Monitor::incr('push_out');
                Logger::info('推送完成', array(
                    'target_type' => $targetType,
                    'target'      => $target,
                    'client_id'   => $clientId,
                    'uid'         => $uid,
                    'msg_id'      => $msgId,
                    'source'      => isset($job['source']) ? $job['source'] : '',
                ));
            } catch (\Throwable $e) {
                Monitor::incr('push_fail');
                Logger::exception($e, 'push.deliver:' . $clientId);
            }
        }
    }

    /**
     * UDP 投递：写入网关出站队列
     *
     * @param string $clientId 形如 udp:{ip}:{port}
     * @param string $json     已序列化的推送报文
     * @param string $uid
     * @param string $msgId
     * @return void
     */
    protected static function deliverUdp($clientId, $json, $uid, $msgId)
    {
        if (empty(self::$udpOutConfig['enable'])) {
            Monitor::incr('push_fail');
            Logger::warn('UDP 出站队列未启用，推送已丢弃', array('client_id' => $clientId));
            return;
        }

        $task = array(
            'client_id' => (string)$clientId,
            'frame'     => (string)$json,
            'uid'       => (string)$uid,
            'msg_id'    => (string)$msgId,
            'queued_at' => microtime(true),
        );

        $raw = json_encode($task, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($raw === false) {
            Monitor::incr('push_fail');
            Logger::error('UDP 出站任务序列化失败', array('client_id' => $clientId));
            return;
        }

        RedisClient::rPush(self::$udpOutConfig['key'], $raw, function ($result) use ($clientId, $uid, $msgId) {
            if (!is_int($result)) {
                Monitor::incr('push_fail');
                Logger::error('UDP 出站任务入队失败', array('client_id' => $clientId));
                return;
            }
            Monitor::incr('udp_out_queued');
            Logger::info('UDP 推送已入出站队列', array(
                'client_id' => $clientId,
                'uid'       => $uid,
                'msg_id'    => $msgId,
            ));
        });
    }

    /* ---------------------------------------------------------------------
     | 离线消息
     --------------------------------------------------------------------- */

    /**
     * 目标离线时的处理
     *
     * @param string $uid
     * @param array  $payload
     * @param string $msgId
     * @param string $mode
     * @param array  $job
     * @return void
     */
    protected static function handleOffline($uid, array $payload, $msgId, $mode, array $job)
    {
        Monitor::incr('push_offline');

        if ($mode !== self::MODE_QUEUE || $uid === '') {
            Logger::info('推送目标离线，按策略丢弃', array(
                'uid'    => $uid,
                'msg_id' => $msgId,
                'mode'   => $mode,
            ));
            return;
        }

        $key = RedisKeys::pushOffline($uid);
        $ttl = (int)self::$config['offline_ttl'];
        $max = (int)self::$config['offline_max'];

        $item = array(
            'payload'    => $payload,
            'msg_id'     => (string)$msgId,
            'source'     => isset($job['source']) ? (string)$job['source'] : '',
            'offline_at' => time(),
        );

        $raw = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($raw === false) {
            Logger::error('离线消息序列化失败', array('uid' => $uid));
            return;
        }

        RedisClient::rPush($key, $raw, function ($result) use ($key, $ttl, $max, $uid, $msgId) {
            if (!is_int($result)) {
                Logger::error('离线消息写入失败', array('uid' => $uid, 'msg_id' => $msgId));
                return;
            }

            RedisClient::expire($key, $ttl);

            // 超限裁剪：仅保留最新的 max 条
            if ($max > 0 && $result > $max) {
                Logger::warn('离线消息超出上限，丢弃最旧记录', array(
                    'uid'   => $uid,
                    'count' => $result,
                    'max'   => $max,
                ));
                RedisClient::lTrimKeepLast($key, $max);
            }

            Logger::info('离线消息已缓存，等待重连补投', array(
                'uid'    => $uid,
                'msg_id' => $msgId,
                'count'  => $result,
            ));
        });
    }

    /**
     * 重连补投：把离线消息投递给刚恢复的连接
     *
     * 双通道支持：
     *   WebSocket 直接 Gateway::sendToClient 下发；
     *   UDP       写入网关出站队列（与在线投递同一通道），由网关进程 sendto。
     * 因此本方法对两种协议统一适用，调用方无需区分协议。
     *
     * 使用 Lua 原子取批，避免「取出」与「清理」之间被新消息插入造成丢失。
     *
     * @param string        $uid
     * @param string        $clientId
     * @param callable|null $cb function(int $count) 补投条数
     * @return void
     */
    public static function replayOffline($uid, $clientId, callable $cb = null)
    {
        if ($uid === '' || $clientId === '') {
            if ($cb) {
                call_user_func($cb, 0);
            }
            return;
        }
        if (self::offlineMode() !== self::MODE_QUEUE) {
            if ($cb) {
                call_user_func($cb, 0);
            }
            return;
        }

        $isUdp = str_starts_with((string)$clientId, self::UDP_PREFIX);
        if ($isUdp && empty(self::$udpOutConfig['enable'])) {
            // UDP 出站通道关闭时无处投递，明确跳过而非静默丢弃
            Logger::warn('UDP 出站队列未启用，离线消息本轮不补投', array(
                'uid'       => $uid,
                'client_id' => $clientId,
            ));
            if ($cb) {
                call_user_func($cb, 0);
            }
            return;
        }

        $key   = RedisKeys::pushOffline($uid);
        $batch = max(1, (int)self::$config['replay_batch']);

        RedisClient::popBatch($key, $batch, function ($items) use ($uid, $clientId, $cb, $batch, $isUdp) {
            if (!$items) {
                if ($cb) {
                    call_user_func($cb, 0);
                }
                return;
            }

            $sent = 0;
            foreach ($items as $raw) {
                $item = json_decode($raw, true);
                if (!is_array($item) || !array_key_exists('payload', $item)) {
                    continue;
                }

                $frame = self::buildFrame(
                    is_array($item['payload']) ? $item['payload'] : [],
                    isset($item['msg_id']) ? (string)$item['msg_id'] : '',
                    array(
                        'source'     => isset($item['source']) ? (string)$item['source'] : '',
                        'offline_at' => isset($item['offline_at']) ? (int)$item['offline_at'] : 0,
                    ),
                    true
                );

                $json = Message::encode($frame);

                // UDP：连接由网关进程持有，业务进程无法寻址，走与在线投递一致的出站队列
                if ($isUdp) {
                    self::deliverUdp($clientId, $json, $uid, isset($item['msg_id']) ? (string)$item['msg_id'] : '');
                    $sent++;
                    continue;
                }

                try {
                    GatewayClient::sendToClient($clientId, $json);
                    $sent++;
                } catch (\Throwable $e) {
                    Logger::exception($e, 'push.replay:' . $clientId);
                }
            }

            Monitor::incr('push_replay', $sent);

            Logger::info('离线消息补投完成', array(
                'uid'       => $uid,
                'client_id' => $clientId,
                'channel'   => $isUdp ? self::CHANNEL_UDP : self::CHANNEL_WS,
                'pulled'    => count($items),
                'delivered' => $sent,
            ));

            if (count($items) >= $batch) {
                Logger::warn('离线消息积压超过单批上限，剩余待下轮补投', array(
                    'uid'   => $uid,
                    'batch' => $batch,
                ));
            }

            if ($cb) {
                call_user_func($cb, $sent);
            }
        });
    }

    /* ---------------------------------------------------------------------
     | 内部辅助
     --------------------------------------------------------------------- */

    /**
     * 构造目标描述
     *
     * @param string $clientId
     * @param string $channel  留空时按 client_id 前缀自动判定
     * @param string $via
     * @return array
     */
    protected static function target($clientId, $channel = '', $via = 'session')
    {
        if ($channel === '') {
            $channel = str_starts_with((string)$clientId, self::UDP_PREFIX) ? self::CHANNEL_UDP : self::CHANNEL_WS;
        }
        return array(
            'client_id' => (string)$clientId,
            'channel'   => $channel,
            'via'       => (string)$via,
        );
    }

    /**
     * 判定连接是否在线
     *
     * WebSocket：以 Gateway 连接表为准（准实时）
     * UDP      ：无连接实体，以会话是否已被标记离线为准
     *
     * @param string $clientId
     * @param array  $session
     * @return bool
     */
    protected static function isOnline($clientId, array $session)
    {
        if (str_starts_with((string)$clientId, self::UDP_PREFIX)) {
            return !empty($session) && empty($session['offline_at']);
        }
        if (empty($session)) {
            return false;
        }
        try {
            // GatewayClient::isOnline() 实际返回 int（1 / 0），与本方法声明的
            // bool 不一致。强制转换使返回类型恒为 bool，调用方可安全使用 === 比较。
            return (bool)GatewayClient::isOnline($clientId);
        } catch (\Throwable $e) {
            Logger::exception($e, 'push.is_online:' . $clientId);
            return false;
        }
    }

    /**
     * Gateway 原生 uid 路由是否在线
     *
     * @param string $uid
     * @return bool
     */
    protected static function nativeUidOnline($uid)
    {
        try {
            return (bool)GatewayClient::isUidOnline($uid);
        } catch (\Throwable $e) {
            Logger::exception($e, 'push.is_uid_online:' . $uid);
            return false;
        }
    }

    /**
     * 构造推送下行报文
     *
     * @param array  $payload
     * @param string $msgId
     * @param array  $job
     * @param bool   $offline 是否为重连补投
     * @return array
     */
    protected static function buildFrame(array $payload, $msgId, array $job, $offline = false)
    {
        return Message::packet(Message::CMD_PUSH, $payload, array(
            'seq'       => $msgId !== '' ? $msgId : self::genMsgId(),
            'msg_id'    => (string)$msgId,
            'source'    => isset($job['source']) ? (string)$job['source'] : '',
            'offline'   => $offline ? 1 : 0,
            'pushed_at' => time(),
        ));
    }

    /**
     * 生成消息标识
     *
     * @return string
     */
    protected static function genMsgId()
    {
        try {
            return 'p-' . bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            return 'p-' . uniqid('', true);
        }
    }

    /**
     * 解析离线策略
     *
     * @param string $mode 任务级覆盖值，为空或非法时取全局配置
     * @return string
     */
    protected static function resolveMode($mode)
    {
        $mode = (string)$mode;
        if ($mode === self::MODE_DROP || $mode === self::MODE_QUEUE) {
            return $mode;
        }
        return (string)self::$config['offline_mode'];
    }

    /**
     * 目标类型归一化
     *
     * @param string $type
     * @return string
     */
    protected static function normalizeTargetType($type)
    {
        $type = strtolower(trim((string)$type));
        if ($type === self::TARGET_UID || $type === self::TARGET_DEVICE || $type === self::TARGET_CLIENT) {
            return $type;
        }
        return self::TARGET_UID;
    }
}
