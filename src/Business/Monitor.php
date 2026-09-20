<?php
/**
 * 服务监控指标采集（对应文档 4.7）
 *
 * 采集维度：
 *  - 实时在线连接总数（WebSocket / UDP 分别统计）
 *  - 消息收发量与失败次数
 *  - 鉴权成功 / 失败次数
 *  - 心跳超时清理次数
 *  - 进程内存占用
 *
 * 写入策略（避免高频 Redis 写放大）：
 *  - 业务侧只做进程内内存累加，零 IO
 *  - 由定时任务周期性批量刷入 Redis
 *  - 累加型指标用 HINCRBY（多进程原子安全），覆盖型指标用 HSET
 *
 * Redis 键：
 *   metrics:counter:{YYYYMMDD}   Hash  当日累加型指标，保留 7 天
 *   metrics:gauge               Hash  当前瞬时指标，TTL 由配置决定
 *
 * 兼容 PHP 8.0 ~ 8.5
 */

namespace GatewayPush\Business;

use GatewayPush\Common\Logger;
use GatewayPush\Common\RedisClient;

class Monitor
{
    /** 累加型指标 key 前缀 */
    const KEY_COUNTER = 'metrics:counter:';

    /** 覆盖型指标 key */
    const KEY_GAUGE = 'metrics:gauge';

    /** 累加型指标保留天数 */
    const COUNTER_KEEP_DAYS = 7;

    /**
     * 监控配置
     *
     * @var array
     */
    protected static $config = array(
        'enable'   => true,
        'interval' => 60,
        'ttl'      => 600,
        'key'      => 'metrics',
        'metrics'  => array(),
    );

    /**
     * 进程内累加计数
     *
     * @var array
     */
    protected static $counters = array();

    /**
     * 进程内瞬时值
     *
     * @var array
     */
    protected static $gauges = array();

    /**
     * 初始化
     *
     * @param array $config app.monitor 配置
     * @return void
     */
    public static function init(array $config)
    {
        self::$config = array_merge(self::$config, $config);
    }

    /**
     * 累加型指标自增（进程内内存操作，零 IO）
     *
     * @param string $metric
     * @param int    $step
     * @return void
     */
    public static function incr($metric, $step = 1)
    {
        if (empty(self::$config['enable'])) {
            return;
        }
        if (!isset(self::$counters[$metric])) {
            self::$counters[$metric] = 0;
        }
        self::$counters[$metric] += (int)$step;
    }

    /**
     * 覆盖型指标赋值
     *
     * @param string $metric
     * @param mixed  $value
     * @return void
     */
    public static function gauge($metric, $value)
    {
        if (empty(self::$config['enable'])) {
            return;
        }
        self::$gauges[$metric] = $value;
    }

    /**
     * 上报指标（定时任务调用）
     *
     * @return void
     */
    public static function report()
    {
        if (empty(self::$config['enable'])) {
            return;
        }

        self::flushCounters();

        // 覆盖型指标：内存占用按进程粒度上报
        $pid   = getmypid();
        $ttl   = (int)self::$config['ttl'];
        $gaugeKey = self::KEY_GAUGE;

        RedisClient::hSet($gaugeKey, 'memory_bytes:' . $pid, Logger::memoryUsage());
        RedisClient::hSet($gaugeKey, 'report_at', time());
        RedisClient::expire($gaugeKey, $ttl);

        // 在线连接数仅在 worker 0 采集，避免多进程重复写入
        if (Task::workerId() !== 0) {
            return;
        }

        Session::countOnline('', function ($total) use ($gaugeKey, $ttl) {
            RedisClient::hSet($gaugeKey, 'conn_total', $total);
            RedisClient::expire($gaugeKey, $ttl);
        });
        Session::countOnline(Session::PROTOCOL_WS, function ($count) use ($gaugeKey) {
            RedisClient::hSet($gaugeKey, 'conn_ws', $count);
        });
        Session::countOnline(Session::PROTOCOL_UDP, function ($count) use ($gaugeKey) {
            RedisClient::hSet($gaugeKey, 'conn_udp', $count);
        });
    }

    /**
     * 读取指标快照（供监控面板 / 运维接口调用）
     *
     * @param callable $cb function(array $snapshot)
     * @return void
     */
    public static function snapshot(callable $cb)
    {
        RedisClient::hGetAll(self::KEY_GAUGE, function ($gauge) use ($cb) {
            RedisClient::hGetAll(self::counterKey(), function ($counter) use ($gauge, $cb) {
                call_user_func($cb, array(
                    'gauge'   => is_array($gauge) ? $gauge : array(),
                    'counter' => is_array($counter) ? $counter : array(),
                    'task'    => Task::stats(),
                ));
            });
        });
    }

    /**
     * 当前进程未落盘的指标（调试用）
     *
     * @return array
     */
    public static function pending()
    {
        return array(
            'counters' => self::$counters,
            'gauges'   => self::$gauges,
        );
    }

    /* ---------------------------------------------------------------------
     | 内部实现
     --------------------------------------------------------------------- */

    /**
     * 批量刷入累加型指标
     *
     * @return void
     */
    protected static function flushCounters()
    {
        $counters = self::$counters;
        self::$counters = array();
        if (!$counters) {
            return;
        }

        $key = self::counterKey();
        foreach ($counters as $metric => $value) {
            if ($value === 0) {
                continue;
            }
            RedisClient::hIncrBy($key, $metric, $value);
        }
        RedisClient::expire($key, self::COUNTER_KEEP_DAYS * 86400);
    }

    /**
     * 当日累加指标 key
     *
     * @return string
     */
    protected static function counterKey()
    {
        return self::KEY_COUNTER . date('Ymd');
    }
}
