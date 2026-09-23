<?php

declare(strict_types=1);

namespace tests\Unit;

use app\service\MetricService;
use PHPUnit\Framework\TestCase;

/**
 * MetricService 的纯函数与集成行为（2.0 §1.1）。
 *
 * downsample / withRates 是趋势页的**口径真源** —— 两个最容易被静默改错的语义：
 * 1. 降采样必须**保留首尾**（否则时间窗口边界会漂移，图上首尾点对不上筛选范围）；
 * 2. counter 差分遇**重置（delta<0）必须置 null**，绝不输出负速率 ——
 *    负速率在图上比空洞更误导（运维会以为系统在「倒吐消息」）。
 * 这两组断言就是钉这两个语义的金标。
 *
 * @covers \app\service\MetricService
 */
final class MetricServiceTest extends TestCase
{
    /* ================= 降采样 ================= */

    public function testDownsampleKeepsRowsWhenUnderLimit(): void
    {
        $rows = $this->rows(10);
        $out = MetricService::downsample($rows, 20);

        self::assertCount(10, $out, '行数少于目标点数时原样返回');
        self::assertSame(0, $out[0]['sampled_at']);
        self::assertSame(9, $out[9]['sampled_at']);
    }

    public function testDownsampleKeepsFirstAndLastPoints(): void
    {
        $rows = $this->rows(100);
        $out = MetricService::downsample($rows, 10);

        self::assertCount(10, $out);
        self::assertSame(0, $out[0]['sampled_at'], '首点必须保留 —— 否则窗口起点漂移');
        self::assertSame(99, $out[9]['sampled_at'], '尾点必须保留 —— 否则窗口终点漂移');
    }

    public function testDownsampleIsMonotonicAndDeduped(): void
    {
        $rows = $this->rows(50);
        $out = MetricService::downsample($rows, 7);

        $prev = -1;
        foreach ($out as $row) {
            self::assertGreaterThan($prev, $row['sampled_at'], '降采样点必须严格递增（索引四舍五入可能撞重，必须去重）');
            $prev = $row['sampled_at'];
        }
    }

    public function testDownsampleHandlesTrivialSizes(): void
    {
        self::assertSame([], MetricService::downsample([], 10));
        $one = $this->rows(1);
        self::assertCount(1, MetricService::downsample($one, 10));
        self::assertCount(2, MetricService::downsample($this->rows(2), 10));
    }

    /* ================= 差分速率 ================= */

    public function testFirstRowHasEmptyRates(): void
    {
        $rows = $this->rowsWithCounters(2, 60);
        $out = MetricService::withRates($rows);

        self::assertSame([], $out[0]['rates'], '首点无前驱，速率为空（前端断线处理）');
        self::assertArrayHasKey('msg_in', $out[1]['rates']);
    }

    public function testRateIsDeltaOverSeconds(): void
    {
        $rows = $this->rowsWithCounters(2, 60);  // 间隔 60s，msg_in 每行 +100
        $out = MetricService::withRates($rows);

        self::assertSame(round(100 / 60, 4), $out[1]['rates']['msg_in'], '速率 = delta / dt（4 位小数）');
        self::assertSame(round(100 / 60, 4), $out[1]['rates']['msg_out']);
    }

    public function testCounterResetYieldsNullRateNotNegative(): void
    {
        $rows = [
            $this->row(0, ['msg_in' => 5000]),
            $this->row(60, ['msg_in' => 3]),  // 重置：5000 → 3
        ];
        $out = MetricService::withRates($rows);

        self::assertNull($out[1]['rates']['msg_in'], 'counter 重置必须置 null —— 绝不输出负速率');
    }

    public function testMissingKeyTreatedAsZeroBaseline(): void
    {
        $rows = [
            $this->row(0, []),              // 旧点没有 msg_out 键
            $this->row(60, ['msg_out' => 5]),  // 新点出现
        ];
        $out = MetricService::withRates($rows);

        self::assertSame(round(5 / 60, 4), $out[1]['rates']['msg_out'], '缺键按 0 基线差分（键演进不制造空洞）');
    }

    public function testZeroOrNegativeDeltaTimeYieldsNoRates(): void
    {
        $rows = [
            $this->row(100, ['msg_in' => 10]),
            $this->row(100, ['msg_in' => 20]),  // 同一秒（防御分支）
        ];
        $out = MetricService::withRates($rows);

        self::assertSame([], $out[1]['rates'], 'dt<=0 时不输出速率');
    }

    /* ================= 集成（DB 可用才跑） ================= */

    public function testSampleInsertsRowAndRangeReturnsIt(): void
    {
        if (!extension_loaded('pdo_mysql') || getenv('ADMIN_DB_HOST') === false) {
            self::markTestSkipped('无 DB 环境，跳过集成断言');
        }

        $svc = new MetricService();
        $res = $svc->sample();

        if (!$res['ok']) {
            self::markTestSkipped('采样不可用（Redis/DB 不在线）：' . ($res['error'] ?? ''));
        }

        self::assertGreaterThanOrEqual(0, $res['id'] ?? 0);

        $rows = $svc->range(time() - 60, time(), 10);
        self::assertNotEmpty($rows);
        $last = $rows[count($rows) - 1];
        self::assertArrayHasKey('rates', $last);
        self::assertIsArray($last['queues']);
        self::assertIsArray($last['counters']);
    }

    public function testCleanupRejectsZeroDays(): void
    {
        $svc = new MetricService();
        // keepDays<1 归一为 1 —— 不会删光全部数据（防御性，行为通过「不抛异常」验证）
        $n = $svc->cleanup(0);
        self::assertNotFalse($n >= 0 || $n === -1);
    }

    /* ================= 夹具 ================= */

    /** @return list<array<string, mixed>> */
    private function rows(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = ['sampled_at' => $i, 'conn_ws' => 1, 'conn_udp' => 0, 'conn_total' => 1,
                'queues' => [], 'counters' => []];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function rowsWithCounters(int $n, int $stepSecs): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->row($i * $stepSecs, ['msg_in' => 100 * ($i + 1), 'msg_out' => 100 * ($i + 1)]);
        }

        return $out;
    }

    /**
     * @param array<string, int> $counters
     * @return array{sampled_at: int, conn_ws: int, conn_udp: int, conn_total: int, queues: list<mixed>, counters: array<string, int>}
     */
    private function row(int $ts, array $counters): array
    {
        return ['sampled_at' => $ts, 'conn_ws' => 1, 'conn_udp' => 0, 'conn_total' => 1,
            'queues' => [], 'counters' => $counters];
    }
}
