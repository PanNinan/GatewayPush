<?php

declare(strict_types=1);

namespace app\service;

use GatewayPush\Common\RedisKeys;
use support\Redis;
use Throwable;

/**
 * 后台读取 GatewayPush 状态的**唯一入口**（只读门面）。
 *
 * 两条纪律（对应主项目红线 ㉝ 与管理后台设计文档 §5.2）：
 * 1. **禁止手写 Redis 键字面量**。所有键名一律经 `GatewayPush\Common\RedisKeys` 产出 ——
 *    该类零依赖、纯常量 + 静态方法，已通过 admin 的 psr-4 映射
 *    （`"GatewayPush\\": "../src/"`）直接复用主项目真源，不存在「镜像副本漂移」问题。
 * 2. **只读**。本类不暴露任何写命令；后台改变推送系统状态一律走 HTTP API。
 *
 * 前缀由 Redis 客户端自动施加（`config/redis.php` 的 `prefix`，默认 `gwpush:`），
 * 因此这里只传 `RedisKeys` 产出的**逻辑键名**，不手工拼前缀 —— 手工拼会与前缀叠加成 `gwpush:gwpush:xxx`。
 */
final class RedisReader
{
    /** 队列键清单：展示顺序即优先级顺序（积压最影响体感的排前面） */
    public const QUEUE_KEYS = [
        RedisKeys::QUEUE_ACTION_IN,
        RedisKeys::QUEUE_PUSH_OUT,
        RedisKeys::QUEUE_UDP_IN,
        RedisKeys::QUEUE_UDP_OUT,
    ];

    /**
     * 当前生效的 Redis 库号（`ADMIN_REDIS_DB`）。
     *
     * 必须由后台**自己从配置读**，不能依赖主项目任何 API 回带 ——
     * `/health` 的 data 里没有这个字段，早期版本误取它导致页面恒显示 DB 0。
     */
    public static function dbIndex(): int
    {
        $value = config('redis.default.database', 0);

        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * 当前生效的键前缀（`ADMIN_REDIS_PREFIX`，须与主项目 `REDIS_PREFIX` 一致）。
     * 非密钥，可安全展示 —— 用于人工核对「后台真的在看同一个键空间」。
     */
    public static function prefix(): string
    {
        $value = config('redis.default.prefix', 'gwpush:');

        return is_scalar($value) ? (string)$value : '';
    }


    /**
     * 连通性与延迟探测。
     *
     * @return array{ok: bool, latency_ms: float, msg: string}
     */
    public function ping(): array
    {
        $start = microtime(true);
        try {
            // 走 `Redis::connection()->command()` 而非门面的 `Redis::command()`：
            // 前者是 support\Redis 的**真实类型化方法**，返回 Illuminate\Redis\Connections\Connection
            // （其 command() 有完整签名），PHPStan 可解析；
            // 后者只在 @method 注解里存在，会触发 staticMethod.notFound。
            // 语义一致：都不依赖具体客户端实现（phpredis / predis 均可）。
            Redis::connection()->command('ping');
        } catch (Throwable $e) {
            return ['ok' => false, 'latency_ms' => 0.0, 'msg' => $e->getMessage()];
        }

        return ['ok' => true, 'latency_ms' => round((microtime(true) - $start) * 1000, 2), 'msg' => ''];
    }

    /**
     * 指标 gauge 快照（`metrics:gauge` Hash）。
     *
     * ⚠ 该 Hash 的 field **没有独立 TTL**：已退出进程的 `pid_at` / `proc` / `tasks` / `memory_bytes`
     * 需靠主项目 `Monitor::staleFields()` + `purgeExitedProcesses()` 主动 HDEL。
     * 后台做「进程存活」判定应使用 `interval × 2`（默认 10s），**不能**用 `MONITOR_TTL`(600s) ——
     * 两者刻意不同、不可互换。
     *
     * @return array<string, string>
     */
    public function gauge(): array
    {
        $raw = Redis::hGetAll(RedisKeys::METRICS_GAUGE);

        return is_array($raw) ? array_map('strval', $raw) : [];
    }

    /**
     * 指标 counter 快照（`metrics:counter:{Ymd}` Hash）。
     *
     * @param string|null $date `Ymd`（如 20260923）；null = 今天。**不是** `Y-m-d`。
     *
     * @return array<string, string>
     */
    public function counter(?string $date = null): array
    {
        $key = RedisKeys::metricsCounter($date);
        $raw = Redis::hGetAll($key);

        return is_array($raw) ? array_map('strval', $raw) : [];
    }

    /**
     * 四个队列的当前深度。
     *
     * @return array<string, int> 键为逻辑队列名（不含前缀）
     */
    public function queueDepths(): array
    {
        $result = [];
        foreach (self::QUEUE_KEYS as $key) {
            $len = Redis::lLen($key);
            $result[$key] = is_int($len) ? $len : (int)$len;
        }

        return $result;
    }

    /**
     * 在线连接数（`online:clients` 共享 Set，天然全局，跨进程无需聚合）。
     */
    public function onlineCount(): int
    {
        $n = Redis::sCard(RedisKeys::ONLINE_CLIENTS);

        return is_int($n) ? $n : (int)$n;
    }

    /**
     * 「预期键存在性」自检 —— **这是防静默错误的关键兜底**。
     *
     * 动因：`ADMIN_REDIS_DB` / `ADMIN_REDIS_PREFIX` 配错时 Redis 不会报错，
     * 只会读到空数据（页面显示 0）。若不做这项检查，运维会以为「系统没有流量」。
     *
     * 刻意**不用 `SCAN` 全库遍历**：那既有成本又可能在大 keyspace 上拖住 worker。
     * 这里只对「必须存在的骨架键」逐个做 EXISTS / TYPE / TTL，成本恒定且结论明确。
     *
     * @return array{
     *     ok: bool,
     *     checks: list<array{key: string, exists: bool, type: string, ttl: int, expect: string, hint: string}>,
     *     hint: string
     * }
     */
    public function selfCheck(): array
    {
        // 每项：[逻辑键, 期望的 Redis 类型, 缺失时的排查提示]
        $targets = [
            [RedisKeys::METRICS_GAUGE, 'hash', '指标采集未开启（MONITOR_ENABLE=false）或 DB/PREFIX 配错'],
            [RedisKeys::ONLINE_CLIENTS, 'set', '当前无在线连接，或 DB/PREFIX 配错'],
            [RedisKeys::metricsCounter(), 'hash', '今日尚无指标写入，或 DB/PREFIX 配错'],
            [RedisKeys::QUEUE_PUSH_OUT, 'list', '键不存在属正常（队列为空时 Redis 会自动删除空列表）'],
        ];

        $checks = [];
        foreach ($targets as [$key, $expectType, $hint]) {
            $exists = (int)Redis::exists($key) > 0;
            $type = $exists ? (string)Redis::type($key) : 'none';
            $ttl = $exists ? (int)Redis::ttl($key) : -2;

            $checks[] = [
                'key' => $key,          // 逻辑键名；实际键 = prefix + 本名
                'exists' => $exists,
                'type' => $type,
                'ttl' => $ttl,          // -1 = 永久，-2 = 键不存在
                'expect' => $expectType,
                'hint' => $exists && $type === $expectType ? '' : $hint,
            ];
        }

        // 骨架判断：`metrics:gauge` 与 `online:clients` 至少有一个存在，
        // 才认为「DB + PREFIX 配对了」。两者都缺 → 几乎必然是 DB/PREFIX 配错。
        $skeletonHits = 0;
        foreach ($checks as $c) {
            if ($c['exists'] && in_array($c['key'], [RedisKeys::METRICS_GAUGE, RedisKeys::ONLINE_CLIENTS], true)) {
                $skeletonHits++;
            }
        }

        $ok = $skeletonHits > 0;

        return [
            'ok' => $ok,
            'checks' => $checks,
            'hint' => $ok
                ? ''
                : 'metrics:gauge 与 online:clients 均不存在：请核对 ADMIN_REDIS_DB 与 '
                    . 'ADMIN_REDIS_PREFIX 是否与主项目一致（本机主项目为 REDIS_DB=9 / PREFIX=gwpush:），'
                    . '或主项目 6 角色是否尚未启动。',
        ];
    }

    /**
     * 当前库的键总数（辅助判断「是否连到了空库」）。
     */
    public function dbSize(): int
    {
        $n = Redis::connection()->command('dbsize');

        return is_int($n) ? $n : (int)$n;
    }
}
