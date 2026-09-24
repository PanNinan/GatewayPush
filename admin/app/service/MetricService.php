<?php

declare(strict_types=1);

namespace app\service;

use support\Db;
use Throwable;

/**
 * 指标趋势采样与查询（2.0 §1.1「指标趋势采样页」的服务层）。
 *
 * 职责边界：
 * - **采样**（`sample()`）：把 `MonitorAggregator::live()` 的即时快照固化为
 *   `gw_metric_samples` 的一行。读走 Redis（复用 MonitorAggregator 的只读通道），
 *   **不打主项目 HTTP** —— 与「读走 Redis，写走 HTTP」的对接原则一致。
 * - **查询**（`range()`）：给趋势页返回时间序列；降采样与差分速率都是**纯函数**，
 *   便于单测钉住口径（counter 重置 → 速率置 null，绝不出现负速率）。
 * - **清理**（`cleanup()`）：按天删除过期行，由采样进程在启动时与每小时各调一次
 *   （红线 ㊲：Timer::add 是延迟首跑 —— 清理必须显式先行，否则频繁重启的环境
 *   一次都不会跑）。
 *
 * 失败语义：采样失败**静默跳过**（返回 error 信息，不落行、不抛异常）——
 * 趋势图上表现为空洞，「主项目挂了一段时间」本身就是该被看见的事实，
 * 而不是让采样进程用错误日志刷屏（RedisReader 已有 debug 日志，此处不重复）。
 */
final class MetricService
{
    /** 采样间隔默认值（秒）。.env 的 ADMIN_METRIC_SAMPLE_INTERVAL 可覆盖。 */
    public const DEFAULT_INTERVAL = 60;

    /** 保留天数默认值。.env 的 ADMIN_METRIC_KEEP_DAYS 可覆盖。 */
    public const DEFAULT_KEEP_DAYS = 14;

    /** 单次查询最多取回的原始行数（防御性上限，超出由降采样收缩） */
    public const MAX_RAW_ROWS = 5000;

    /** 差分速率参与计算的 counter 键（趋势页画的四组曲线的数据源） */
    public const RATE_KEYS = [
        'msg_in', 'msg_out', 'msg_fail', 'action_ok', 'action_fail', 'rate_limit_hit',
        // 2.0 §3.3 推送送达率：受理 vs 实际下发（口径差异见 UI 备注）
        'push_in', 'push_out', 'push_fail', 'push_offline', 'push_dedup',
    ];

    /**
     * 采样一次。失败返回 ['ok' => false, 'error' => string]，成功返回 ['ok' => true, 'id' => int]。
     *
     * @return array{ok: bool, id?: int, error?: string}
     */
    public function sample(): array
    {
        try {
            $agg = (new MonitorAggregator())->live();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'aggregator: ' . $e->getMessage()];
        }

        $redis = $agg['redis'] ?? [];
        if (($redis['ok'] ?? false) !== true) {
            return ['ok' => false, 'error' => 'redis: ' . (string)($redis['msg'] ?? 'unavailable')];
        }

        $gauge = is_array($redis['gauge'] ?? null) ? $redis['gauge'] : [];
        $now = time();

        try {
            // INSERT IGNORE + uk_sampled_at：进程被误起两套时（Windows 不拒绝重复 bind），
            // 同一秒内后写者静默失败，数据不会翻倍。
            $affected = Db::table('gw_metric_samples')->insertOrIgnore([
                'sampled_at' => $now,
                'conn_ws' => (int)($gauge['conn_ws'] ?? 0),
                'conn_udp' => (int)($gauge['conn_udp'] ?? 0),
                'conn_total' => (int)($gauge['conn_total'] ?? 0),
                'queues' => json_encode(is_array($redis['queues'] ?? null) ? $redis['queues'] : [], JSON_UNESCAPED_UNICODE),
                'counters' => json_encode(is_array($redis['counter'] ?? null) ? $redis['counter'] : [], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'db: ' . $e->getMessage()];
        }

        if ($affected < 1) {
            // 同一秒已有采样行（uk_sampled_at 去重生效，进程被误起两套时出现）——不算失败
            return ['ok' => true, 'id' => 0];
        }

        try {
            $id = (int)Db::table('gw_metric_samples')->where('sampled_at', $now)->value('id');
        } catch (Throwable) {
            $id = 0;
        }

        return ['ok' => true, 'id' => $id];
    }

    /**
     * 查询时间范围 [from, to] 内的采样行，降采样到至多 $points 个点，并附差分速率。
     *
     * @return list<array<string, mixed>> 每行含 sampled_at / conn_* / queues(数组) /
     *                                    counters(数组) / rates(数组，null = 重置点)
     */
    public function range(int $from, int $to, int $points = 240): array
    {
        $to = max($to, $from);
        $points = max(2, min($points, 720));

        try {
            $rows = Db::table('gw_metric_samples')
                ->where('sampled_at', '>=', $from)
                ->where('sampled_at', '<=', $to)
                ->orderBy('sampled_at', 'asc')
                ->limit(self::MAX_RAW_ROWS)
                ->get();
        } catch (Throwable) {
            return [];
        }

        $list = $rows instanceof \Illuminate\Support\Collection ? $rows->all() : (array)$rows;
        $normalized = [];
        foreach ($list as $r) {
            $row = (array)$r;
            $normalized[] = $this->normalizeRow($row);
        }

        return self::withRates(self::downsample($normalized, $points));
    }

    /**
     * 最新一行（趋势页「当前值」栏）。
     *
     * @return array<string, mixed>|null
     */
    public function latest(): ?array
    {
        try {
            $rows = Db::table('gw_metric_samples')
                ->orderBy('sampled_at', 'desc')
                ->limit(1)
                ->get();
        } catch (Throwable) {
            return null;
        }

        $list = $rows instanceof \Illuminate\Support\Collection ? $rows->all() : (array)$rows;
        if ($list === []) {
            return null;
        }

        return $this->normalizeRow((array)$list[0]);
    }

    /**
     * 删除超过 $keepDays 天的行。返回删除条数；DB 不可用返回 -1（调用方只 debug 记，不告警）。
     */
    public function cleanup(int $keepDays = self::DEFAULT_KEEP_DAYS): int
    {
        if ($keepDays < 1) {
            $keepDays = 1;
        }

        try {
            return (int)Db::table('gw_metric_samples')
                ->where('sampled_at', '<', time() - $keepDays * 86400)
                ->delete();
        } catch (Throwable) {
            return -1;
        }
    }

    /**
     * DB 行 → 标准行：JSON 列解码、整型归一。
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        return [
            'sampled_at' => (int)($row['sampled_at'] ?? 0),
            'conn_ws' => (int)($row['conn_ws'] ?? 0),
            'conn_udp' => (int)($row['conn_udp'] ?? 0),
            'conn_total' => (int)($row['conn_total'] ?? 0),
            'queues' => (array)json_decode((string)($row['queues'] ?? '{}'), true),
            'counters' => (array)json_decode((string)($row['counters'] ?? '{}'), true),
        ];
    }

    /**
     * 均匀降采样：保留首尾两点，中间按等距取。纯函数。
     *
     * @param list<array<string, mixed>> $rows 已按 sampled_at 升序
     * @param positive-int $points
     * @return list<array<string, mixed>>
     */
    public static function downsample(array $rows, int $points): array
    {
        $n = count($rows);
        if ($n <= $points) {
            return array_values($rows);
        }

        $out = [];
        $lastIndex = $n - 1;
        for ($i = 0; $i < $points; $i++) {
            // 等距取点：索引四舍五入，且与上一个选中点不重复
            $idx = (int)round($i * $lastIndex / ($points - 1));
            $out[] = $rows[$idx];
        }

        return $out;
    }

    /**
     * 差分速率：相邻两行 counter 差 / 时间差（秒）。纯函数。
     *
     * 口径纪律：
     * - **delta < 0 ⇒ rate = null**（counter 重置：主项目重启或跨天归零。绝不输出负速率 ——
     *   负速率在图上比空洞更误导）；
     * - 时间差 ≤ 0 ⇒ null（降采样后的相邻点可能来自同一秒？不会——uk_sampled_at 保证，
     *   但防御性保留该分支）；
     * - 缺键 ⇒ 0 累计值参与差分（键是主项目演进新增时，旧点按 0 处理，与新点差分为正 ——
     *   与其置 null 制造空洞，不如按「此前一直是 0」理解）。
     *
     * @param list<array<string, mixed>> $rows 已按 sampled_at 升序
     * @return list<array<string, mixed>>
     */
    public static function withRates(array $rows): array
    {
        $out = [];
        $prev = null;
        foreach ($rows as $row) {
            $rates = [];
            if (is_array($prev)) {
                $dt = $row['sampled_at'] - $prev['sampled_at'];
                if ($dt > 0) {
                    foreach (self::RATE_KEYS as $key) {
                        $delta = (int)($row['counters'][$key] ?? 0) - (int)($prev['counters'][$key] ?? 0);
                        $rates[$key] = $delta < 0 ? null : round($delta / $dt, 4);
                    }
                }
            }

            $newRow = $row;
            $newRow['rates'] = $rates;
            $out[] = $newRow;
            $prev = $row;
        }

        return $out;
    }
}
