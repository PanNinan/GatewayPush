<?php
/**
 * admin · 服务层 —— MonitorAggregator。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace app\service;

use Throwable;

/**
 * 监控数据聚合。
 *
 * 数据来源两条腿，**语义不同不可混用**：
 * - `redis`：直读主项目 Redis 键空间。展示的是**推送系统自己的状态**（在线数、指标、队列深度）。
 * - `api`  ：调主项目 HTTP `/health` + `/stats`。展示的是**进程存活与自报口径**，
 *            同时用作「后台 ↔ 主项目」这条链路的连通性证明。
 *
 * 两者刻意都采：一旦 `redis.ok=true` 而 `api.ok=false`，就能立刻区分
 * 「Redis 配对但 API 挂了」与「Redis 也读不到」（后者多半是 DB/PREFIX 配错）——
 * 这正是 `/api/ops/redis/scan` 要解决的静默错误。
 *
 * 本类**只读**：不写任何 Redis 键、不调任何会改变主项目状态的 API。
 *
 * ## 两条通道（P1 新增，分工的理由是**失败模式隔离**，不是省毫秒）
 *
 * | 方法 | 用途 | 是否打主项目 HTTP | 是否查 MySQL | 是否跑 selfCheck/dbsize |
 * |---|---|---|---|---|
 * | `collect()` | 首屏 + 慢 tick（默认 30s） | 是（`/health` + `/stats`） | 是（读设置） | 是 |
 * | `live()`    | 快 tick（默认 5s） | **否** | 是（读设置） | **否** |
 *
 * 为什么必须分开：`GatewayPushClient` 的 `connect_timeout` 是 3s、总超时 8s，
 * 若把主项目 HTTP 调用混进 5s 的快 tick，**主项目一挂，面板反而开始卡顿** ——
 * 恰好在最需要它的时候失去可用性。快 tick 只碰 Redis，因此永远快。
 *
 * @phpstan-import-type Derived from MetricsDeriver
 *
 * @phpstan-type RedisLive array{
 *     ok: bool, msg: string, latency_ms: float, online: int,
 *     gauge: array<string, string>, counter: array<string, string>,
 *     queues: array<string, int>, report_at: int
 * }
 * @phpstan-type LiveSnapshot array{ts: int, redis: RedisLive, derived: Derived}
 */
final class MonitorAggregator
{
    /** @var RedisReader */
    private RedisReader $redis;

    /** @var GatewayPushClient */
    private GatewayPushClient $api;

    /**
     * @param null|RedisReader       $redis
     * @param null|GatewayPushClient $api
     */
    public function __construct(?RedisReader $redis = null, ?GatewayPushClient $api = null)
    {
        $this->redis = $redis ?? new RedisReader();
        $this->api = $api ?? new GatewayPushClient(
            self::cfg('api_url', 'http://127.0.0.1:8290'),
            self::cfg('api_secret')
        );
    }

    /**
     * 只探主项目 API 存活：**不打 Redis、不聚合**。
     *
     * 用途：`/api/monitor/health` 这个独立探针端点（与面板的快 tick 不是同一件事）。
     *
     * @return array{ts: int, ok: bool, status: int, code: int, msg: string, url: string}
     */
    public function apiHealth(): array
    {
        $health = $this->api->health();

        return [
            'ts' => time(),
            'ok' => $health['ok'],
            'status' => $health['status'],
            'code' => $health['code'],
            'msg' => $health['msg'],
            'url' => $this->api->apiUrl(),
        ];
    }

    /**
     * 轻量实时快照（面板快 tick 专用）。
     *
     * 只读 Redis + 算派生：**不打主项目 HTTP、不跑 selfCheck、不查 dbsize**。
     * 上层（`MonitorController::live()`）负责把它暴露成 JSON。
     *
     * 失败时**不抛异常**：Redis 不可用则 `redis.ok=false` + `derived` 全空，
     * 让面板显示「Redis 不可用」而不是整个请求 500。
     *
     * @return LiveSnapshot
     */
    public function live(): array
    {
        $now = time();
        $ping = $this->redis->ping();

        if (!$ping['ok']) {
            return [
                'ts' => $now,
                'redis' => [
                    'ok' => false,
                    'msg' => $ping['msg'],
                    'latency_ms' => $ping['latency_ms'],
                    'online' => 0,
                    'gauge' => [],
                    'counter' => [],
                    'queues' => [],
                    'report_at' => 0,
                ],
                'derived' => ['ratios' => [], 'processes' => [], 'alerts' => []],
            ];
        }

        try {
            $gauge = $this->redis->gauge();
            $counter = $this->redis->counter();
            $queues = $this->redis->queueDepths();

            return [
                'ts' => $now,
                'redis' => [
                    'ok' => true,
                    'msg' => '',
                    'latency_ms' => $ping['latency_ms'],
                    'online' => $this->redis->onlineCount(),
                    'gauge' => $gauge,
                    'counter' => $counter,
                    'queues' => $queues,
                    // 全局上报时间戳（非进程字段）：面板用它显示「指标最后一次刷新于」。
                    'report_at' => $this->reportAt($gauge),
                ],
                'derived' => $this->derived($gauge, $counter, $queues, $now),
            ];
        } catch (Throwable $e) {
            return [
                'ts' => $now,
                'redis' => [
                    'ok' => false,
                    'msg' => $e->getMessage(),
                    'latency_ms' => $ping['latency_ms'],
                    'online' => 0,
                    'gauge' => [],
                    'counter' => [],
                    'queues' => [],
                    'report_at' => 0,
                ],
                'derived' => ['ratios' => [], 'processes' => [], 'alerts' => []],
            ];
        }
    }

    /**
     * 采集一次完整快照（首屏 + 慢 tick）。
     *
     * @return array{
     *     ts: int,
     *     redis: array{
     *         ok: bool, msg: string, latency_ms: float, online: int, db_size: int,
     *         db: int, prefix: string, report_at: int,
     *         gauge: array<string, string>, counter: array<string, string>,
     *         queues: array<string, int>,
     *         self_check: array{ok: bool, checks: list<array<string, mixed>>, hint: string}
     *     },
     *     api: array{
     *         ok: bool, status: int, code: int, msg: string, url: string,
     *         has_secret: bool, health: array<string, mixed>, stats: array<string, mixed>
     *     },
     *     derived: Derived
     * }
     */
    public function collect(): array
    {
        $now = time();
        $redis = $this->redisSnapshot();

        return [
            'ts' => $now,
            'redis' => $redis,
            'api' => $this->apiSnapshot(),
            'derived' => $this->derived($redis['gauge'], $redis['counter'], $redis['queues'], $now),
        ];
    }

    /**
     * Redis 快照。整体 try/catch：Redis 不可用时不让整个页面 500，
     * 而是把失败原因带回给调用方展示（`ok=false` + `msg`）。
     *
     * @return array{
     *     ok: bool, msg: string, latency_ms: float, online: int, db_size: int,
     *     db: int, prefix: string, report_at: int,
     *     gauge: array<string, string>, counter: array<string, string>,
     *     queues: array<string, int>,
     *     self_check: array{ok: bool, checks: list<array<string, mixed>>, hint: string}
     * }
     */
    private function redisSnapshot(): array
    {
        $ping = $this->redis->ping();
        $latency = $ping['latency_ms'];

        try {
            $gauge = $this->redis->gauge();

            return [
                'ok' => $ping['ok'],
                'msg' => $ping['msg'],
                'latency_ms' => $latency,
                'online' => $this->redis->onlineCount(),
                'db_size' => $this->redis->dbSize(),
                'db' => RedisReader::dbIndex(),
                'prefix' => RedisReader::prefix(),
                // 与 live() 保持同名字段，否则慢 tick 一回写就会把面板上的
                // 「指标上报于」擦成 —（P0 只有服务端渲染，没有这个坑）。
                'report_at' => $this->reportAt($gauge),
                'gauge' => $gauge,
                'counter' => $this->redis->counter(),
                'queues' => $this->redis->queueDepths(),
                'self_check' => $this->redis->selfCheck(),
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'msg' => $e->getMessage(),
                'latency_ms' => $latency,
                'online' => 0,
                'db_size' => 0,
                'db' => RedisReader::dbIndex(),
                'prefix' => RedisReader::prefix(),
                'report_at' => 0,
                'gauge' => [],
                'counter' => [],
                'queues' => [],
                'self_check' => ['ok' => false, 'checks' => [], 'hint' => $e->getMessage()],
            ];
        }
    }

    /**
     * 全局上报时间戳（非进程字段 `report_at`）：面板用它显示「指标最后一次刷新于」。
     *
     * @param array<string, string> $gauge
     */
    private function reportAt(array $gauge): int
    {
        return isset($gauge['report_at']) && is_numeric($gauge['report_at'])
            ? (int)$gauge['report_at']
            : 0;
    }

    /**
     * 主项目 API 快照。`/health` 免签，`/stats` 需签（免签部署下也可用）。
     *
     * @return array{
     *     ok: bool, status: int, code: int, msg: string, url: string,
     *     has_secret: bool, health: array<string, mixed>, stats: array<string, mixed>
     * }
     */
    private function apiSnapshot(): array
    {
        $health = $this->api->health();

        // /stats 只在 /health 通过时才值得再打一次（避免主项目不可用时多等一个超时）。
        $stats = $health['ok'] ? $this->api->stats()['data'] : [];

        return [
            'ok' => $health['ok'],
            'status' => $health['status'],
            'code' => $health['code'],
            'msg' => $health['msg'],
            'url' => $this->api->apiUrl(),
            'has_secret' => $this->api->hasSecret(),
            'health' => $health['data'],
            'stats' => $stats,
        ];
    }

    /**
     * 组装派生器并计算。阈值与存活宽限**一律来自 `admin_settings`**，
     * 因此调参不必改代码（`Settings` 的缓存带 TTL，改库后数秒生效）。
     *
     * ⚠ `gauge_stale_secs` 是「面板展示判据」= `MONITOR_INTERVAL × 2`（默认 10s），
     * **不是** 主项目清理用的 `MONITOR_TTL`(600s) —— 两者刻意不同、不可互换。
     *
     * @param array<string, string> $gauge
     * @param array<string, string> $counter
     * @param array<string, int>    $queues
     *
     * @return Derived
     */
    private function derived(array $gauge, array $counter, array $queues, int $now): array
    {
        $deriver = new MetricsDeriver(Settings::json('monitor.ratio_thresholds'));

        return $deriver->derive(
            $counter,
            $gauge,
            $queues,
            max(1, Settings::int('monitor.queue_warn_depth', 1000)),
            max(1, Settings::int('monitor.gauge_stale_secs', 10)),
            $now
        );
    }

    /** 读 config('gateway_push.*') 的字符串项（配置里非字符串时回落默认值）。 */
    private static function cfg(string $key, string $default = ''): string
    {
        $value = config('gateway_push.' . $key, $default);

        return is_scalar($value) ? (string)$value : $default;
    }
}
