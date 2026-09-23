<?php

declare(strict_types=1);

namespace app\service;

use Throwable;

/**
 * 监控数据聚合（P0 版）。
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
 */
final class MonitorAggregator
{
    private RedisReader $redis;

    private GatewayPushClient $api;

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
     * 用途：监控页的高频轮询（默认 5s）。探活不该每次把 Redis 指标全量读一遍 ——
     * 那是 `collect()` 的职责（首屏 + 低频刷新）。
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
     * 采集一次完整快照。
     *
     * @return array{
     *     ts: int,
     *     redis: array{
     *         ok: bool, msg: string, latency_ms: float, online: int, db_size: int,
     *         db: int, prefix: string,
     *         gauge: array<string, string>, counter: array<string, string>,
     *         queues: array<string, int>,
     *         self_check: array{ok: bool, checks: list<array<string, mixed>>, hint: string}
     *     },
     *     api: array{
     *         ok: bool, status: int, code: int, msg: string, url: string,
     *         has_secret: bool, health: array<string, mixed>, stats: array<string, mixed>
     *     }
     * }
     */
    public function collect(): array
    {
        return [
            'ts' => time(),
            'redis' => $this->redisSnapshot(),
            'api' => $this->apiSnapshot(),
        ];
    }

    /**
     * Redis 快照。整体 try/catch：Redis 不可用时不让整个页面 500，
     * 而是把失败原因带回给调用方展示（`ok=false` + `msg`）。
     *
     * @return array{
     *     ok: bool, msg: string, latency_ms: float, online: int, db_size: int,
     *     db: int, prefix: string,
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
            return [
                'ok' => $ping['ok'],
                'msg' => $ping['msg'],
                'latency_ms' => $latency,
                'online' => $this->redis->onlineCount(),
                'db_size' => $this->redis->dbSize(),
                'db' => RedisReader::dbIndex(),
                'prefix' => RedisReader::prefix(),
                'gauge' => $this->redis->gauge(),
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
                'gauge' => [],
                'counter' => [],
                'queues' => [],
                'self_check' => ['ok' => false, 'checks' => [], 'hint' => $e->getMessage()],
            ];
        }
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

    /** 读 config('gateway_push.*') 的字符串项（配置里非字符串时回落默认值）。 */
    private static function cfg(string $key, string $default = ''): string
    {
        $value = config('gateway_push.' . $key, $default);

        return is_scalar($value) ? (string)$value : $default;
    }
}
