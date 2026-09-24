<?php

declare(strict_types=1);

namespace app\service;

use GatewayPush\Common\RedisKeys;
use support\Redis;
use Throwable;

/**
 * 限流命中只读巡检（2.0 §1.3）。
 *
 * 动因：`rate:*` 键只在主项目内部使用，被限流的客户端「静默收不到回执」，
 * 排查全靠翻日志。本类只读两类键 + 当日 counter，**不反解任何主体**：
 *
 * | 源 | 形态 | 能看到什么 | 看不到什么 |
 * |---|---|---|---|
 * | `rl:{dim}:{md5(id)}` | Hash（tokens / ts） | 维度、剩余令牌、是否接近耗尽 | 原始 IP / uid / clientId（已 md5） |
 * | `api:rate:{md5(ip)}:{分钟}` | String 计数 | 该分钟窗口的请求数 | 原始 IP（已 md5） |
 * | `metrics:counter:{Ymd}` | Hash | 当日 `rate_limit_hit` 累计 | 谁被限了 |
 *
 * 主体一律 **md5 不可逆**（`RedisKeys::rateBucket` / `rateApi` 的设计）——
 * UI 必须如实说明「只见指纹不见明文」，不得暗示可定位到具体 IP。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */
final class RateInspector
{
    /** 展示的 api:rate 分钟窗口数（含当前分钟，往前各取一格） */
    public const API_WINDOWS = 3;

    /** rl 桶最多展开多少条（超出计入 truncated，不静默丢弃） */
    public const MAX_BUCKETS = 50;

    /**
     * 只读快照。
     *
     * @return array{
     *     ok: bool,
     *     hint: string,
     *     hit_today: int,
     *     dims: list<array{
     *         dim: string, buckets: int, low_tokens: int, label: string
     *     }>,
     *     buckets: list<array{
     *         key: string, dim: string, fingerprint: string,
     *         tokens: float, burst: float|null, level: string
     *     }>,
     *     api_windows: list<array{
     *         minute: int, minute_text: string, hits: int, fingerprint: string
     *     }>,
     *     truncated: bool,
     *     notes: list<string>
     * }
     */
    public function inspect(): array
    {
        $reader = new RedisReader();
        $ping = $reader->ping();
        if (!$ping['ok']) {
            return $this->empty(false, 'Redis 不可用：' . $ping['msg']);
        }

        try {
            $counter = $reader->counter();
        } catch (Throwable $e) {
            return $this->empty(false, '读取当日 counter 失败：' . $e->getMessage());
        }

        $hitToday = 0;
        if (isset($counter['rate_limit_hit']) && is_numeric($counter['rate_limit_hit'])) {
            $hitToday = (int)$counter['rate_limit_hit'];
        }

        $bucketScan = $reader->scanKeys(
            RedisKeys::RATE_LIMIT_BUCKET . '*',
            200,
            50,
            self::MAX_BUCKETS
        );

        $buckets = [];
        $dims = [];
        $truncated = $bucketScan['truncated'];

        foreach ($bucketScan['keys'] as $logical) {
            $parsed = self::parseBucketKey($logical);
            if ($parsed === null) {
                continue;
            }
            [$dim, $fingerprint] = $parsed;

            $hash = [];
            try {
                $raw = Redis::hGetAll($logical);
                $hash = is_array($raw) ? $raw : [];
            } catch (Throwable) {
                // 单键读失败不拖垮整表（键可能在 SCAN 与 HGETALL 之间被 PEXPIRE 清掉）
                continue;
            }

            $tokens = isset($hash['tokens']) && is_numeric($hash['tokens'])
                ? (float)$hash['tokens']
                : 0.0;
            // burst 不在 Hash 里（Lua 只写 tokens/ts）；按「令牌耗尽 ≈ 正在被限」判 warn。
            // 有 tokens>0 但很低时同样 warn —— 那是「刚被掐过、还没回血」的窗口。
            $level = $tokens <= 0.0 ? 'bad' : ($tokens < 1.0 ? 'warn' : 'ok');

            $buckets[] = [
                'key' => $logical,
                'dim' => $dim,
                'fingerprint' => $fingerprint,
                'tokens' => $tokens,
                'burst' => null,
                'level' => $level,
            ];

            if (!isset($dims[$dim])) {
                $dims[$dim] = ['dim' => $dim, 'buckets' => 0, 'low_tokens' => 0, 'label' => self::dimLabel($dim)];
            }
            $dims[$dim]['buckets']++;
            if ($level !== 'ok') {
                $dims[$dim]['low_tokens']++;
            }
        }

        // 维度展示顺序：conn / uid / ip / ping / 其它（与 RateLimiter 常量语义对齐）
        uasort($dims, static function (array $a, array $b): int {
            $order = ['conn' => 0, 'uid' => 1, 'ip' => 2, 'ping' => 3];
            $oa = $order[$a['dim']] ?? 9;
            $ob = $order[$b['dim']] ?? 9;
            return $oa <=> $ob ?: strcmp($a['dim'], $b['dim']);
        });
        // 低令牌桶排前（正在被限的先看见）
        usort($buckets, static function (array $a, array $b): int {
            $rank = ['bad' => 0, 'warn' => 1, 'ok' => 2];
            return ($rank[$a['level']] ?? 3) <=> ($rank[$b['level']] ?? 3)
                ?: strcmp($a['dim'], $b['dim']);
        });

        $apiWindows = $this->apiWindows($reader, $truncated);

        return [
            'ok' => true,
            'hint' => '',
            'hit_today' => $hitToday,
            'dims' => array_values($dims),
            'buckets' => $buckets,
            'api_windows' => $apiWindows,
            'truncated' => $truncated,
            'notes' => self::notes(),
        ];
    }

    /**
     * 当前及前 N-1 个分钟窗口的 HTTP 侧命中计数。
     *
     * 只读 `api:rate:{md5}:{slot}` —— 键本身带 md5，**无法**还原 IP；
     * 同一 md5 在多分钟各有一格，这里按分钟各报一行（hits=0 的格不列，
     * 避免刷一屏「没人打过」的空窗）。
     *
     * @return list<array{minute: int, minute_text: string, hits: int, fingerprint: string}>
     */
    private function apiWindows(RedisReader $reader, bool &$truncated): array
    {
        $nowMinute = (int)floor(time() / 60);
        $scan = $reader->scanKeys(
            RedisKeys::RATE_LIMIT_API . '*',
            100,
            20,
            100
        );
        if ($scan['truncated']) {
            $truncated = true;
        }

        $bySlot = [];
        foreach ($scan['keys'] as $logical) {
            $parts = explode(':', $logical);
            // api:rate:{md5}:{slot} —— 恰好 4 段
            if (count($parts) !== 4 || $parts[0] !== 'api' || $parts[1] !== 'rate') {
                continue;
            }
            $slot = (int)$parts[3];
            // 只保留最近 API_WINDOWS 个窗口（更早的格子多半已 PEXPIRE）
            if ($slot < $nowMinute - self::API_WINDOWS + 1 || $slot > $nowMinute) {
                continue;
            }

            $hits = 0;
            try {
                $raw = Redis::get($logical);
                if (is_numeric($raw)) {
                    $hits = (int)$raw;
                }
            } catch (Throwable) {
                continue;
            }
            if ($hits <= 0) {
                continue;
            }

            $bySlot[$slot] = [
                'minute' => $slot,
                'minute_text' => date('H:i', $slot * 60),
                'hits' => $hits,
                // md5 前 12 位：够区分「是不是同一个源」，又不是可拼回的原文
                'fingerprint' => substr($parts[2], 0, 12),
            ];
        }

        $rows = array_values($bySlot);
        usort($rows, static fn (array $a, array $b): int => $b['minute'] <=> $a['minute']);

        return $rows;
    }

    /**
     * `rl:{dim}:{md5}` → [dim, fingerprint]；形态不符返回 null。
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parseBucketKey(string $logical): ?array
    {
        $prefix = RedisKeys::RATE_LIMIT_BUCKET;
        if (!str_starts_with($logical, $prefix)) {
            return null;
        }
        $rest = substr($logical, strlen($prefix));
        $colon = strpos($rest, ':');
        if ($colon === false || $colon === 0) {
            return null;
        }
        $dim = substr($rest, 0, $colon);
        $md5 = substr($rest, $colon + 1);
        if ($dim === '' || $md5 === '') {
            return null;
        }

        return [$dim, substr($md5, 0, 12)];
    }

    /** 维度中文标签（与 RateLimiter 四维对齐） */
    public static function dimLabel(string $dim): string
    {
        static $map = [
            'conn' => '每连接（clientId）',
            'uid' => '每用户（uid）',
            'ip' => '每来源 IP',
            'ping' => '心跳（ping）',
        ];

        return $map[$dim] ?? $dim;
    }

    /**
     * @return list<string>
     */
    public static function notes(): array
    {
        return [
            '主体指纹一律 md5 前 12 位 —— 键名设计即不可逆，本页无法还原原始 IP / uid。',
            'L2 令牌桶（rl:）是跨进程共享层；L1 进程内内存桶不在 Redis，本页看不到。',
            'HTTP 侧 api:rate: 按分钟开窗；只列最近有命中的窗口，全 0 时不刷空行。',
            '当日 hit 计数取自 metrics:counter（Monitor::incr(\'rate_limit_hit\')），跨天归零属正常。',
        ];
    }

    /**
     * @return array{
     *     ok: bool, hint: string, hit_today: int,
     *     dims: list<array<string, mixed>>, buckets: list<array<string, mixed>>,
     *     api_windows: list<array<string, mixed>>, truncated: bool, notes: list<string>
     * }
     */
    private function empty(bool $ok, string $hint): array
    {
        return [
            'ok' => $ok,
            'hint' => $hint,
            'hit_today' => 0,
            'dims' => [],
            'buckets' => [],
            'api_windows' => [],
            'truncated' => false,
            'notes' => self::notes(),
        ];
    }
}
