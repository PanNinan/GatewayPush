<?php
/**
 * Redis 会话管理：绑定、心跳、断线重连恢复、过期清理
 *
 * 会话数据全部落在 Redis，服务节点无本地状态，为后期集群横向扩容预留能力（文档 3.1.3 / 7.2）。
 *
 * Redis 键（键名声明于 RedisKeys，此处只描述语义；实际 key 会再拼接 app.redis.prefix 全局前缀）：
 *   session:{clientId}          Hash   会话主体：uid / device_id / protocol / ip / 时间戳
 *   heartbeat:{clientId}        String 最近一次活跃时间戳（高频写入，单独成键降低写放大）
 *   uid:clients:{uid}           Set    uid -> clientId 集合（多设备在线）
 *   device:client:{deviceId}    String deviceId -> 当前活跃 clientId
 *   online:clients              Set    全量在线 clientId
 *   online:ws / online:udp      Set    按协议维度的在线集合（监控统计用）
 *
 * 所有操作均为异步回调，不阻塞事件循环。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Business;

use GatewayPush\Common\Logger;
use GatewayPush\Common\RedisClient;
use GatewayPush\Common\RedisKeys;
use GatewayWorker\Lib\Gateway as GatewayClient;

class Session
{
    /** 支持的协议标识 */
    const PROTOCOL_WS  = 'ws';
    const PROTOCOL_UDP = 'udp';

    /**
     * 会话配置
     *
     * @var array
     */
    protected static $config = array(
        'ttl'           => 7200,
        'heartbeat_ttl' => 90,
        'restore'       => true,
    );

    /**
     * 单次批量取值的最大 key 数量，避免超长命令
     */
    const BATCH_SIZE = 500;

    /**
     * 初始化
     *
     * @param array $config app.session 配置
     * @return void
     */
    public static function init(array $config)
    {
        self::$config = array_merge(self::$config, $config);
    }

    /* ---------------------------------------------------------------------
     | 会话绑定
     --------------------------------------------------------------------- */

    /**
     * 绑定会话（鉴权成功后调用）
     *
     * @param string        $clientId
     * @param array         $identity ['uid'=>..., 'device_id'=>...]
     * @param string        $protocol Session::PROTOCOL_WS / PROTOCOL_UDP
     * @param array         $connInfo ['client_ip','client_port','gateway','connect_at']
     * @param callable|null $cb
     * @return void
     */
    public static function bind($clientId, array $identity, $protocol, array $connInfo = [], ?callable $cb = null)
    {
        $ttl      = (int)self::$config['ttl'];
        $now      = time();
        $uid      = isset($identity['uid']) ? (string)$identity['uid'] : '';
        $deviceId = isset($identity['device_id']) ? (string)$identity['device_id'] : '';

        $fields = array(
            'client_id'   => (string)$clientId,
            'uid'         => $uid,
            'device_id'   => $deviceId,
            'protocol'    => (string)$protocol,
            'client_ip'   => isset($connInfo['client_ip']) ? (string)$connInfo['client_ip'] : '',
            'client_port' => isset($connInfo['client_port']) ? (string)$connInfo['client_port'] : '',
            'gateway'     => isset($connInfo['gateway']) ? (string)$connInfo['gateway'] : '',
            'connect_at'  => isset($connInfo['connect_at']) ? (int)$connInfo['connect_at'] : $now,
            'last_active' => $now,
        );

        RedisClient::hMSet(RedisKeys::session($clientId), $fields);
        RedisClient::expire(RedisKeys::session($clientId), $ttl);
        RedisClient::set(RedisKeys::heartbeat($clientId), $now, $ttl);
        RedisClient::sAdd(RedisKeys::online(''), $clientId);
        RedisClient::sAdd(RedisKeys::online($protocol), $clientId);

        if ($uid !== '') {
            RedisClient::sAdd(RedisKeys::uidClients($uid), $clientId);
            RedisClient::expire(RedisKeys::uidClients($uid), $ttl);
        }
        if ($deviceId !== '') {
            RedisClient::set(RedisKeys::deviceClient($deviceId), $clientId, $ttl);
        }

        Logger::info('会话绑定完成', array(
            'client_id' => $clientId,
            'uid'       => $uid,
            'device_id' => $deviceId,
            'protocol'  => $protocol,
        ));

        if ($cb) {
            $cb(true);
        }
    }

    /**
     * 刷新心跳时间戳
     *
     * 高频操作，仅写单个 String 键。
     *
     * @param string        $clientId
     * @param callable|null $cb
     * @return void
     */
    public static function touch($clientId, ?callable $cb = null)
    {
        RedisClient::set(
            RedisKeys::heartbeat($clientId),
            time(),
            (int)self::$config['ttl'],
            $cb
        );
    }

    /**
     * 标记连接离线（连接关闭时调用）
     *
     * 与 unbind() 的区别：会话主体保留在 Redis，供客户端断线重连时恢复（文档 4.3）。
     * 仅摘除在线索引与心跳记录，避免离线连接被计入在线数或被巡检判定为僵死。
     *
     * @param string        $clientId
     * @param callable|null $cb
     * @return void
     */
    public static function markOffline($clientId, ?callable $cb = null)
    {
        RedisClient::hGetAll(RedisKeys::session($clientId), function ($session) use ($clientId, $cb) {
            $protocol = is_array($session) && isset($session['protocol']) ? (string)$session['protocol'] : '';

            RedisClient::sRem(RedisKeys::online(''), $clientId);
            if ($protocol !== '') {
                RedisClient::sRem(RedisKeys::online($protocol), $clientId);
            }
            RedisClient::del(RedisKeys::heartbeat($clientId));

            if (is_array($session) && $session) {
                RedisClient::hSet(RedisKeys::session($clientId), 'offline_at', time());
            }

            Logger::info('会话已标记离线，等待断线重连', array(
                'client_id' => $clientId,
                'protocol'  => $protocol,
                'retained'  => is_array($session) && $session ? 1 : 0,
            ));

            if ($cb) {
                $cb(true);
            }
        });
    }

    /**
     * 解绑会话（连接关闭时调用）
     *
     * @param string        $clientId
     * @param callable|null $cb
     * @return void
     */
    public static function unbind($clientId, ?callable $cb = null)
    {
        RedisClient::hGetAll(RedisKeys::session($clientId), function ($session) use ($clientId, $cb) {
            $uid      = is_array($session) && isset($session['uid']) ? (string)$session['uid'] : '';
            $deviceId = is_array($session) && isset($session['device_id']) ? (string)$session['device_id'] : '';
            $protocol = is_array($session) && isset($session['protocol']) ? (string)$session['protocol'] : '';

            RedisClient::del(array(
                RedisKeys::session($clientId),
                RedisKeys::heartbeat($clientId),
            ));
            RedisClient::sRem(RedisKeys::online(''), $clientId);
            if ($protocol !== '') {
                RedisClient::sRem(RedisKeys::online($protocol), $clientId);
            }
            if ($uid !== '') {
                RedisClient::sRem(RedisKeys::uidClients($uid), $clientId);
            }

            // 设备映射仅在指向当前连接时才移除，避免误删新连接的映射
            if ($deviceId !== '') {
                RedisClient::get(RedisKeys::deviceClient($deviceId), function ($current) use ($clientId, $deviceId) {
                    if (is_string($current) && $current !== '' && $current === (string)$clientId) {
                        RedisClient::del(RedisKeys::deviceClient($deviceId));
                    }
                });
            }

            Logger::info('会话已解绑', array(
                'client_id' => $clientId,
                'uid'       => $uid,
                'device_id' => $deviceId,
            ));

            if ($cb) {
                $cb(true);
            }
        });
    }

    /* ---------------------------------------------------------------------
     | 会话查询
     --------------------------------------------------------------------- */

    /**
     * 读取会话内容
     *
     * @param string   $clientId
     * @param callable $cb function(array $session)
     * @return void
     */
    public static function get($clientId, callable $cb)
    {
        RedisClient::hGetAll(RedisKeys::session($clientId), function ($session) use ($cb) {
            $cb(is_array($session) ? $session : array());
        });
    }

    /**
     * 会话是否存在
     *
     * 用 EXISTS 代替 HGETALL 做存在性判定，供高频路径使用
     * （如 UDP 每次报文前的「会话是否重建」探测，避免整表读取）。
     *
     * @param string   $clientId
     * @param callable $cb function(bool $exists)
     * @return void
     */
    public static function exists($clientId, callable $cb)
    {
        RedisClient::exists(RedisKeys::session($clientId), function ($result) use ($cb) {
            $cb(!empty($result));
        });
    }

    /**
     * 按设备查当前活跃 clientId
     *
     * @param string   $deviceId
     * @param callable $cb function(string $clientId)
     * @return void
     */
    public static function findByDevice($deviceId, callable $cb)
    {
        RedisClient::get(RedisKeys::deviceClient($deviceId), function ($clientId) use ($cb) {
            $cb(is_string($clientId) ? $clientId : '');
        });
    }

    /**
     * 按 uid 查全部 clientId（多设备在线）
     *
     * @param string   $uid
     * @param callable $cb function(array $clientIds)
     * @return void
     */
    public static function findByUid($uid, callable $cb)
    {
        RedisClient::sMembers(RedisKeys::uidClients($uid), function ($members) use ($cb) {
            $cb(is_array($members) ? $members : array());
        });
    }

    /**
     * 断线重连恢复：按 device_id 找回历史会话
     *
     * @param string   $deviceId
     * @param callable $cb function(array $session) 未命中返回空数组
     * @return void
     */
    public static function restore($deviceId, callable $cb)
    {
        if (empty(self::$config['restore'])) {
            $cb(array());
            return;
        }

        self::findByDevice($deviceId, function ($clientId) use ($cb) {
            if ($clientId === '') {
                $cb(array());
                return;
            }
            self::get($clientId, function ($session) use ($cb) {
                $cb($session);
            });
        });
    }

    /**
     * 统计在线连接数
     *
     * @param string        $protocol 为空表示全部
     * @param callable|null $cb       function(int $count)
     * @return void
     */
    public static function countOnline($protocol = '', ?callable $cb = null)
    {
        if ($cb === null) {
            $cb = function () {
            };
        }
        $key = RedisKeys::online($protocol);
        RedisClient::sCard($key, function ($count) use ($cb) {
            $cb(is_int($count) ? $count : 0);
        });
    }

    /* ---------------------------------------------------------------------
     | 定时任务
     --------------------------------------------------------------------- */

    /**
     * 心跳超时巡检：清理僵死连接与会话（定时任务）
     *
     * @return void
     */
    public static function checkHeartbeatTimeout()
    {
        $threshold = (int)self::$config['heartbeat_ttl'];
        if ($threshold <= 0) {
            return;
        }
        $now = time();

        RedisClient::sMembers(RedisKeys::online(''), function ($members) use ($threshold, $now) {
            if (!is_array($members) || !$members) {
                return;
            }

            $clientIds = array_values($members);
            $keys      = [];
            foreach ($clientIds as $clientId) {
                $keys[] = RedisClient::key(RedisKeys::heartbeat($clientId));
            }

            // 批量取值，避免逐条命令放大 Redis 压力
            RedisClient::mGet($keys, function ($values) use ($clientIds, $threshold, $now) {
                if (!is_array($values)) {
                    return;
                }
                foreach ($clientIds as $index => $clientId) {
                    $last = isset($values[$index]) && is_numeric($values[$index]) ? (int)$values[$index] : 0;
                    if ($last > 0 && ($now - $last) <= $threshold) {
                        continue;
                    }
                    if ($last === 0) {
                        // 心跳记录已过期，说明会话进入回收阶段，交由 cleanExpired 处理
                        continue;
                    }
                    Logger::warn('检测到心跳超时连接，执行清理', array(
                        'client_id'   => $clientId,
                        'last_active' => $last,
                        'timeout'     => $now - $last,
                    ));
                    Monitor::incr('heartbeat_timeout');
                    self::forceClose($clientId);
                }
            });
        });
    }

    /**
     * 清理过期会话残留索引（定时任务）
     *
     * session 主体键有 TTL 会自动过期，但集合类索引不会，
     * 需要定期比对并剔除失效 clientId，防止集合无限膨胀。
     *
     * @return void
     */
    public static function cleanExpired()
    {
        RedisClient::sMembers(RedisKeys::online(''), function ($members) {
            if (!is_array($members) || !$members) {
                return;
            }

            $clientIds = array_values($members);
            $keys      = [];
            foreach ($clientIds as $clientId) {
                $keys[] = RedisClient::key(RedisKeys::session($clientId));
            }

            RedisClient::mGet($keys, function ($values) use ($clientIds) {
                if (!is_array($values)) {
                    return;
                }
                $stale = [];
                foreach ($clientIds as $index => $clientId) {
                    $value = isset($values[$index]) ? $values[$index] : false;
                    if ($value === false || $value === null || $value === '') {
                        $stale[] = $clientId;
                    }
                }
                if (!$stale) {
                    return;
                }

                RedisClient::sRem(RedisKeys::online(''), $stale);
                RedisClient::sRem(RedisKeys::online(self::PROTOCOL_WS), $stale);
                RedisClient::sRem(RedisKeys::online(self::PROTOCOL_UDP), $stale);

                Logger::info('清理过期会话索引完成', array('count' => count($stale)));
            });
        });
    }

    /**
     * 清理超时会话
     *
     * WebSocket 为有连接协议，先断开网关侧连接再回收会话；
     * UDP 为无连接协议（clientId 形如 udp:ip:port），不存在可断开的连接实体，
     * 且其 clientId 无法被 GatewayWorker 地址解析器识别，因此仅回收会话数据。
     *
     * @param string $clientId
     * @return void
     */
    protected static function forceClose($clientId)
    {
        $isUdp = str_starts_with((string)$clientId, 'udp:');

        if (!$isUdp) {
            try {
                if (GatewayClient::isOnline($clientId)) {
                    GatewayClient::closeClient($clientId);
                }
            } catch (\Throwable $e) {
                Logger::exception($e, 'session.force_close:' . $clientId);
            }
        }

        self::unbind($clientId);
    }
}
