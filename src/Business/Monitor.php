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
 * Redis 键（键名声明于 RedisKeys）：
 *   metrics:counter:{YYYYMMDD}   Hash  当日累加型指标，保留 7 天
 *   metrics:gauge               Hash  当前瞬时指标，TTL 由配置决定
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Business;

use GatewayPush\Common\Logger;
use GatewayPush\Common\RedisClient;
use GatewayPush\Common\RedisKeys;

/**
 * 服务监控指标采集
 *
 * 业务侧只做进程内累加（零 IO），由定时任务批量刷入 metrics:counter / metrics:gauge。
 */
class Monitor
{
    /** 累加型指标保留天数 */
    public const COUNTER_KEEP_DAYS = 7;

    /**
     * gauge Hash 中「按 PID 独立」的字段前缀
     *
     * 写入侧（report / flushProcessMeta）与清理侧（purgeExitedProcesses）共用，
     * 避免两处各写一份字面量而漂移 —— 前缀一旦不一致，清理就会漏删（残留累积）
     * 或误删（活进程字段被清）。
     */
    public const FIELD_PID_AT       = 'pid_at:';
    public const FIELD_PROC         = 'proc:';
    public const FIELD_TASKS        = 'tasks:';
    public const FIELD_MEMORY_BYTES = 'memory_bytes:';

    /**
     * 监控配置
     *
     * @var array
     */
    protected static $config = [
        'enable'   => true,
        'interval' => 60,
        'ttl'      => 600,
        'metrics'  => [],
    ];

    /**
     * 进程内累加计数
     *
     * @var array
     */
    protected static $counters = [];

    /**
     * 进程内瞬时值
     *
     * @var array
     */
    protected static $gauges = [];

    /**
     * 初始化
     *
     * @param array $config app.monitor 配置
     *
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
     *
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
     *
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
     * @param bool $withOnline 是否采集在线连接数。
     *                         UDP 网关进程仅有连接数以外的指标（出站收发），
     *                         且进程内无 Session/业务上下文，故传 false 跳过。
     *
     * @return void
     */
    public static function report($withOnline = true)
    {
        if (empty(self::$config['enable'])) {
            return;
        }

        self::flushCounters();

        // 覆盖型指标：内存占用按进程粒度上报
        $pid      = getmypid();
        $now      = time();
        $ttl      = (int)self::$config['ttl'];
        $gaugeKey = RedisKeys::METRICS_GAUGE;

        RedisClient::hSet($gaugeKey, self::FIELD_MEMORY_BYTES . $pid, Logger::memoryUsage());

        // 进程元信息：pid_at 用于判定进程存活（gauge TTL 远长于上报周期，
        // 进程退出后其字段仍会残留，面板必须靠时间戳识别幽灵进程）；
        // tasks 为定时任务健康度 —— 纯进程内状态，跨进程读不到，必须随指标落库。
        // 二者按 PID 独立成字段（与 memory_bytes:{pid} 同一命名风格），多 worker 不会互相覆盖。
        self::flushProcessMeta($gaugeKey, $pid, $now);

        RedisClient::hSet($gaugeKey, 'report_at', $now);
        RedisClient::expire($gaugeKey, $ttl);

        // 必须显式清理：Hash 的 field 没有独立 TTL，而上面的 expire() 每次上报都会
        // 刷新整个 key 的存活时间 —— 只要还有活进程在写，key 就永不过期，已退出进程
        // 的字段会无限累积（实测残留可达 4780s+，而 TTL 仅 600s）。
        self::purgeExitedProcesses($gaugeKey, $ttl, $now);

        if (!$withOnline) {
            return;
        }

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
     *
     * @return void
     */
    public static function snapshot(callable $cb)
    {
        RedisClient::hGetAll(RedisKeys::METRICS_GAUGE, function ($gauge) use ($cb) {
            RedisClient::hGetAll(RedisKeys::metricsCounter(), function ($counter) use ($gauge, $cb) {
                $cb([
                    'gauge'   => is_array($gauge) ? $gauge : [],
                    'counter' => is_array($counter) ? $counter : [],
                    'task'    => Task::stats(),
                ]);
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
        return [
            'counters' => self::$counters,
            'gauges'   => self::$gauges,
        ];
    }

    /**
     * 从 gauge 快照中挑出「已退出进程」的残留字段名
     *
     * 纯计算、零 IO —— 与 Redis 读写解耦，便于单测覆盖各类边界
     * （缺时间戳 / 脏 PID / 恰好落在阈值上 / 非进程字段混入）。
     *
     * 判定：`pid_at` 存在且 `0 < pid_at < now - ttl` 即视为已退出。
     * 只认以 pid_at: 开头且后缀为纯数字的字段，其余（report_at、conn_total 等
     * 全局字段）一律不动 —— 它们不属于任何进程，没有「退出」概念。
     *
     * @param array $gauge HGETALL 结果
     * @param int   $ttl   存活宽限（秒），<=0 时视为不清理
     * @param int   $now   当前时间戳
     *
     * @return array 待删除的 field 列表；无需清理时为空数组
     */
    public static function staleFields(array $gauge, $ttl, $now)
    {
        if ($ttl <= 0 || !$gauge) {
            return [];
        }

        $deadline = $now - $ttl;
        $fields   = [];

        foreach ($gauge as $field => $value) {
            if (!str_starts_with($field, self::FIELD_PID_AT)) {
                continue;
            }

            $pid = substr($field, strlen(self::FIELD_PID_AT));
            if ($pid === '' || !ctype_digit($pid)) {
                continue;
            }

            $at = (int)$value;
            // at <= 0：时间戳缺失/损坏的字段不删，避免把来源不明的东西误清
            if ($at <= 0 || $at >= $deadline) {
                continue;
            }

            $fields[] = self::FIELD_PID_AT . $pid;
            $fields[] = self::FIELD_PROC . $pid;
            $fields[] = self::FIELD_TASKS . $pid;
            $fields[] = self::FIELD_MEMORY_BYTES . $pid;
        }

        return $fields;
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
        self::$counters = [];
        if (!$counters) {
            return;
        }

        $key = RedisKeys::metricsCounter();
        foreach ($counters as $metric => $value) {
            if ($value === 0) {
                continue;
            }
            RedisClient::hIncrBy($key, $metric, $value);
        }
        RedisClient::expire($key, self::COUNTER_KEEP_DAYS * 86400);
    }

    /**
     * 上报本进程的元信息（身份 + 存活时间戳 + 定时任务健康度）
     *
     * 三者都是纯进程内状态，跨进程无法读取，面板进程要展示「这个 PID 是谁」
     * 与「定时任务是否卡住」就必须依赖这里的落库。
     *
     * 均按 PID 独立成字段（与 memory_bytes:{pid} 同一命名风格）：
     * 一是多 worker 各写各的、不会互相覆盖；二是进程退出后其字段仍会随 gauge
     * 存活到 TTL 结束，调用方需凭 pid_at 判断该 PID 是否仍在线。
     *
     * @param string $gaugeKey
     * @param int    $pid
     * @param int    $now
     *
     * @return void
     */
    protected static function flushProcessMeta($gaugeKey, $pid, $now)
    {
        $workerId = Task::workerId();
        $role     = defined('APP_ROLE') ? APP_ROLE : 'all';

        RedisClient::hSet($gaugeKey, self::FIELD_PID_AT . $pid, $now);

        // 进程身份：光有 PID 无法判断它是什么进程 —— PID 会被系统回收复用，
        // 同一角色下的多个 worker 也肉眼不可分。APP_ROLE 由 start.php 定义，
        // 是进程唯一的权威身份来源。
        $proc = json_encode([
            'role'      => $role,
            'worker_id' => $workerId,
        ], JSON_UNESCAPED_UNICODE);

        if ($proc !== false) {
            RedisClient::hSet($gaugeKey, self::FIELD_PROC . $pid, $proc);
        }

        $payload = json_encode([
            'worker_id' => $workerId,
            'jobs'      => Task::stats(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        if ($payload !== false) {
            RedisClient::hSet($gaugeKey, self::FIELD_TASKS . $pid, $payload);
        }
    }

    /**
     * 清理已退出进程在 gauge 中残留的字段
     *
     * 为什么必须主动清理：Redis 的 Hash field 没有独立 TTL（HSET 不支持 field 级
     * 过期），而 gauge 的 TTL 是 key 级的。report() 每次上报都 expire(gaugeKey, ttl)
     * 刷新 key —— 只要还有任意一个活进程在写，key 就永不过期，已退出进程的
     * proc / pid_at / tasks / memory_bytes 四个字段会无限累积：面板进程列表越拉
     * 越长、Redis 内存缓慢增长、`HGETALL` 的返回体也越来越大。
     *
     * 判定口径：`pid_at < now - ttl` 视为已退出 —— ttl 与 gauge 自身的 TTL 同源，
     * 语义就是「数据保留时长」，超过它即当过期数据丢弃。
     *
     * 刻意**不**复用面板的 `interval * 2` 阈值：那只是「多久没上报就在界面上标灰」
     * 的展示阈值，只有秒级；拿来做删除判据过于激进 —— 一次长任务阻塞、一次 GC
     * 停顿就会把活进程的字段删掉，表现为面板进程反复闪烁。用 ttl 则天然留出
     * 足够宽限，且「已退出」的可见性仍由面板按 interval*2 标注，两者互补不冲突。
     *
     * 多进程并发调用是安全的：hDel 幂等，重复删除同一批 field 只会返回 0。
     *
     * @param string $gaugeKey
     * @param int    $ttl
     * @param int    $now
     *
     * @return void
     */
    protected static function purgeExitedProcesses($gaugeKey, $ttl, $now)
    {
        if ($ttl <= 0) {
            return;
        }

        RedisClient::hGetAll($gaugeKey, function ($gauge) use ($gaugeKey, $ttl, $now) {
            if (!is_array($gauge)) {
                return;
            }

            $fields = self::staleFields($gauge, $ttl, $now);
            if (!$fields) {
                return;
            }

            RedisClient::hDel($gaugeKey, $fields, function () use ($gaugeKey, $fields) {
                Logger::info('已清理已退出进程的残留指标字段', [
                    'key'    => $gaugeKey,
                    'fields' => $fields,
                ]);
            });
        });
    }
}
