<?php

declare(strict_types=1);

namespace tests\Unit;

use app\service\MetricsDeriver;
use GatewayPush\Business\Monitor;
use PHPUnit\Framework\TestCase;

/**
 * `MetricsDeriver` 纯函数单测。
 *
 * 之所以把派生计算写成纯函数（零 IO、不读配置），就是为了让这里能覆盖
 * 「线上最容易悄悄出错、又最难复现」的那批边界 —— 分母为 0、日切、
 * 字段缺失、时间戳损坏、幽灵进程恰好落在阈值上。
 *
 * 其中两条是本测试的**核心断言**，改动派生逻辑时最容易被顺手破坏：
 *  ① **分母为 0 必须得 `null`，不是 `0.0`** —— 前者是「今日无样本」，
 *     后者是「今日有样本且零失败」，二者在面板上是「—」与「0.00%」的区别。
 *     混同会让刚启动的系统显示虚假的健康信号。
 *  ② **存活判定用 `<=`，阈值分档用 `>=`** —— 两处都「到点即算」，
 *     方向若被改反，会出现「面板显示存活、但主项目清理逻辑已判其死亡」的诡异组合。
 *
 * `@phpstan-import-type` 而非就地重写 shape：类型定义只有一处（`MetricsDeriver`），
 * 测试与实现共用同一份，避免改了实现忘了改测试的 shape 断言。
 *
 * @phpstan-import-type RatioRow from MetricsDeriver
 * @phpstan-import-type ProcessRow from MetricsDeriver
 * @phpstan-import-type AlertRow from MetricsDeriver
 */
final class MetricsDeriverTest extends TestCase
{
    /** 固定时间戳：不使用 time()，保证断言与执行时刻无关 */
    private const NOW = 1800000000;

    /** 默认存活宽限（= MONITOR_INTERVAL × 2） */
    private const STALE = 10;

    // -----------------------------------------------------------------
    // 比率：分母为 0 的语义
    // -----------------------------------------------------------------

    /**
     * 计数器全空 → 所有比率都是 `null`（无样本），且一律不告警。
     */
    public function testAllRatiosAreNullWhenNothingRecorded(): void
    {
        $ratios = (new MetricsDeriver())->ratios([]);

        $this->assertNotEmpty($ratios, '比率规格表不应为空');

        foreach ($ratios as $ratio) {
            $this->assertNull($ratio['value'], $ratio['key'] . ' 在无样本时应为 null');
            $this->assertSame(
                MetricsDeriver::LEVEL_OK,
                $ratio['level'],
                $ratio['key'] . ' 无样本不应触发告警'
            );
        }
    }

    /**
     * 只有分子、没有分母 → 仍是 `null`，**不得降级成 0.0**。
     *
     * 这是最容易被顺手写成 `$num / max(1, $den)` 或 `$den > 0 ? ... : 0` 的地方。
     */
    public function testMissingDenominatorYieldsNullNotNullZero(): void
    {
        $ratios = (new MetricsDeriver())->ratios(['msg_fail' => '7']);
        $row = $this->ratio($ratios, 'msg_fail_rate');

        $this->assertSame(7, $row['num'], '分子应如实读出');
        $this->assertSame(0, $row['den']);
        $this->assertNull($row['value'], '分母为 0 时必须为 null（无样本），不能是 0.0');
        $this->assertSame(MetricsDeriver::LEVEL_OK, $row['level']);
    }

    /**
     * 缺失字段按 0 参与求和（计数器按需创建，「没有字段」在本项目等于「今日为 0」）。
     */
    public function testMissingCounterCountsAsZeroInSum(): void
    {
        $ratios = (new MetricsDeriver())->ratios(['auth_success' => '90']);
        $row = $this->ratio($ratios, 'auth_fail_rate');

        $this->assertSame(0, $row['num']);
        $this->assertSame(90, $row['den'], '分母应为 auth_success + auth_fail = 90 + 0');
        $this->assertSame(0.0, $row['value']);
        $this->assertSame(MetricsDeriver::LEVEL_OK, $row['level']);
    }

    /**
     * 多项分母求和（鉴权失败率的分母是成功 + 失败，不是单字段）。
     */
    public function testMultiTermDenominatorIsSummed(): void
    {
        $ratios = (new MetricsDeriver())->ratios(['auth_success' => '90', 'auth_fail' => '10']);
        $row = $this->ratio($ratios, 'auth_fail_rate');

        $this->assertSame(100, $row['den']);
        $this->assertSame(10.0, $row['value']);
        $this->assertSame(MetricsDeriver::LEVEL_WARN, $row['level'], '10% 恰好等于 warn 阈值 → 触发');
    }

    /**
     * 三档位与「**恰好等于阈值即触发**」的边界。
     *
     * `msg_fail_rate` 默认 warn=1.0 / bad=5.0，故 0.4 / 1.0 / 5.0 / 5.1 分别落在
     * ok / warn / bad / bad —— 1.0 与 5.0 是刻意选的**等号**用例。
     */
    public function testLevelBoundariesTriggerOnEquality(): void
    {
        $cases = [
            [4, MetricsDeriver::LEVEL_OK, 0.4],    // 4 / 1000
            [10, MetricsDeriver::LEVEL_WARN, 1.0],  // 恰好等于 warn
            [50, MetricsDeriver::LEVEL_BAD, 5.0],   // 恰好等于 bad
            [51, MetricsDeriver::LEVEL_BAD, 5.1],
        ];

        foreach ($cases as [$failCount, $expectedLevel, $expectedValue]) {
            $ratios = (new MetricsDeriver())->ratios([
                'msg_in' => '1000',
                'msg_fail' => (string)$failCount,
            ]);
            $row = $this->ratio($ratios, 'msg_fail_rate');

            $this->assertSame($expectedValue, $row['value']);
            $this->assertSame(
                $expectedLevel,
                $row['level'],
                "msg_fail={$failCount}/1000 应落在 {$expectedLevel}"
            );
        }
    }

    /**
     * `share` 类（占比）恒为 ok —— 离线缓存占比 100% 也不是「故障」，
     * 给它套错误率配色会制造假警报。
     */
    public function testShareRatiosNeverAlert(): void
    {
        $ratios = (new MetricsDeriver())->ratios([
            'push_in' => '100',
            'push_offline' => '100',
            'push_dedup' => '100',
        ]);

        foreach (['push_offline_share', 'push_dedup_share'] as $key) {
            $row = $this->ratio($ratios, $key);

            $this->assertSame('share', $row['kind']);
            $this->assertSame(100.0, $row['value']);
            $this->assertSame(MetricsDeriver::LEVEL_OK, $row['level'], $key . ' 不应触发告警');
        }
    }

    /**
     * 比率规格表必须覆盖主项目**实际在用**的关键指标 —— 防止有人优化时误删规格。
     */
    public function testRatioTableCoversExpectedKeys(): void
    {
        $keys = [];
        foreach ((new MetricsDeriver())->ratios([]) as $ratio) {
            $keys[] = $ratio['key'];
        }

        foreach ([
            'msg_fail_rate',
            'auth_fail_rate',
            'action_fail_rate',
            'action_timeout_rate',
            'push_fail_rate',
            'udp_out_fail_rate',
            'heartbeat_timeout_rate',
            'push_offline_share',
            'push_dedup_share',
        ] as $expected) {
            $this->assertContains($expected, $keys, '比率规格缺失：' . $expected);
        }
    }

    // -----------------------------------------------------------------
    // 阈值覆盖
    // -----------------------------------------------------------------

    /**
     * `admin_settings.monitor.ratio_thresholds` 覆盖生效，且**逐项可选**
     * （只写想改的项，其余继续用默认值）。
     */
    public function testThresholdOverrideAppliesPerKey(): void
    {
        $counter = ['msg_in' => '1000', 'msg_fail' => '5'];   // 0.5%：默认 ok
        $default = $this->ratio((new MetricsDeriver())->ratios($counter), 'msg_fail_rate');
        $this->assertSame(MetricsDeriver::LEVEL_OK, $default['level']);

        $overridden = $this->ratio(
            (new MetricsDeriver(['msg_fail_rate' => ['warn' => 0.1]]))->ratios($counter),
            'msg_fail_rate'
        );
        $this->assertSame(MetricsDeriver::LEVEL_WARN, $overridden['level'], '覆盖后 0.5% 应触发 warn');

        // 未覆盖的项保持默认：同一个快照里 action_fail_rate 仍是 ok
        $ratios = (new MetricsDeriver(['msg_fail_rate' => ['warn' => 0.1]]))->ratios($counter + [
            'action_in' => '1000',
            'action_fail' => '5',
        ]);
        $this->assertSame(MetricsDeriver::LEVEL_OK, $this->ratio($ratios, 'action_fail_rate')['level']);
    }

    /**
     * 非法覆盖一律**静默回落默认值**（阈值只影响配色，不该让监控页出错）。
     */
    public function testInvalidThresholdOverrideFallsBackToDefaults(): void
    {
        $counter = ['msg_in' => '1000', 'msg_fail' => '5'];

        $invalid = [
            'not an array',                     // 整项不是数组
            ['warn' => 'abc'],                  // 非数字
            ['bad' => null],                    // null 不视作数值
            ['warn' => [1, 2]],                 // 嵌套数组
        ];

        foreach ($invalid as $override) {
            $ratios = (new MetricsDeriver(['msg_fail_rate' => $override]))->ratios($counter);
            $row = $this->ratio($ratios, 'msg_fail_rate');

            $this->assertSame(
                MetricsDeriver::LEVEL_OK,
                $row['level'],
                '非法覆盖应回落默认阈值（0.5% < 1.0% → ok），实际：' . var_export($override, true)
            );
        }
    }

    /**
     * 未在规格表中的覆盖项直接忽略（防止手滑写错 key 时把配置静默当成生效）。
     */
    public function testUnknownThresholdKeyIsIgnored(): void
    {
        $deriver = new MetricsDeriver(['not_a_real_ratio' => ['warn' => 0.01]]);
        $row = $this->ratio($deriver->ratios(['msg_in' => '1000', 'msg_fail' => '5']), 'msg_fail_rate');

        $this->assertSame(MetricsDeriver::LEVEL_OK, $row['level']);
    }

    // -----------------------------------------------------------------
    // 进程表
    // -----------------------------------------------------------------

    /**
     * 正常进程：身份、内存、存活时间都要如实解析。
     */
    public function testProcessIdentityIsParsed(): void
    {
        $processes = $this->deriver()->processes([
            Monitor::FIELD_PID_AT . '1234' => (string)(self::NOW - 3),
            Monitor::FIELD_PROC . '1234' => '{"role":"business","worker_id":2}',
            Monitor::FIELD_MEMORY_BYTES . '1234' => '4194304',
            Monitor::FIELD_TASKS . '1234' => '{"worker_id":2,"jobs":{"metrics":{"interval":60,"count":9,"skip":1,"fail":0,"last_cost":0.0012,"running":false}}}',
            'report_at' => (string)self::NOW,
            'conn_total' => '17',
        ], self::STALE, self::NOW);

        $this->assertCount(1, $processes, 'report_at / conn_total 不是进程字段，不得被当成进程');

        $process = $processes[0];
        $this->assertSame(1234, $process['pid']);
        $this->assertSame('business', $process['role']);
        $this->assertSame(2, $process['worker_id']);
        $this->assertSame(4194304, $process['memory_bytes']);
        $this->assertSame(3, $process['age_secs']);
        $this->assertTrue($process['alive']);
        $this->assertArrayHasKey('metrics', $process['tasks']);
        $this->assertSame(9, $process['tasks']['metrics']['count']);
        $this->assertSame(1, $process['tasks']['metrics']['skip']);
        $this->assertSame(0.0012, $process['tasks']['metrics']['last_cost']);
        $this->assertFalse($process['tasks']['metrics']['running']);
    }

    /**
     * ★ 存活边界：`age_secs` **恰好等于** staleSecs 时仍算存活。
     *
     * 反向（用 `<`）会与主项目 `Monitor::staleFields()` 的 `$at >= $deadline`
     * 判定错位，出现「面板说活着、清理逻辑说死了」的组合。
     */
    public function testProcessAliveAtExactlyStaleSecs(): void
    {
        $processes = $this->processesWithAge(self::STALE);

        $this->assertTrue($processes[0]['alive'], '恰好 10s 应仍算存活（<= 语义）');
        $this->assertSame(self::STALE, $processes[0]['age_secs']);
    }

    /**
     * 超出 1 秒即判死亡。
     */
    public function testProcessDeadOneSecondPastStaleSecs(): void
    {
        $processes = $this->processesWithAge(self::STALE + 1);

        $this->assertFalse($processes[0]['alive']);
        $this->assertSame(self::STALE + 1, $processes[0]['age_secs']);
    }

    /**
     * 时间戳为 0 / 负数 / 非数字 / 空串 → 不可信即不健康，且 `age_secs` 用 `-1` 标记
     * （区别于「0 秒前」这一合法值）。
     */
    public function testUntrustworthyTimestampsAreMarkedDead(): void
    {
        /** @var list<array{0: string, 1: string}> [原始值, 说明] */
        $cases = [
            ['0', '时间戳 0'],
            ['-5', '负数'],
            ['abc', '非数字'],
            ['', '空串'],
        ];

        foreach ($cases as [$raw, $label]) {
            $processes = $this->deriver()->processes([
                Monitor::FIELD_PID_AT . '7' => $raw,
            ], self::STALE, self::NOW);

            $this->assertCount(1, $processes, $label);
            $this->assertFalse($processes[0]['alive'], '不可信时间戳应判死：' . $label);
            $this->assertSame(-1, $processes[0]['age_secs'], $label);
            $this->assertSame('?', $processes[0]['role'], '身份缺失时回落 ?，不得臆造：' . $label);
            $this->assertSame(-1, $processes[0]['worker_id'], $label);
        }
    }

    /**
     * 未来时间戳（时钟回拨）不应产生负的 age_secs —— 钳到 0，避免前端画图越界。
     */
    public function testFutureTimestampIsClampedToZeroAge(): void
    {
        $processes = $this->processesWithOffset(60);

        $this->assertSame(0, $processes[0]['age_secs']);
        $this->assertTrue($processes[0]['alive']);
    }

    /**
     * 后缀非纯数字的 `pid_at:*` 直接跳过（防止脏字段被当成 PID 0 之类的假进程）。
     */
    public function testNonNumericPidSuffixIsSkipped(): void
    {
        $processes = $this->deriver()->processes([
            Monitor::FIELD_PID_AT . 'abc' => (string)self::NOW,
            Monitor::FIELD_PID_AT => (string)self::NOW,
            Monitor::FIELD_PID_AT . '00' => (string)self::NOW,
        ], self::STALE, self::NOW);

        $this->assertCount(1, $processes, '只有 "00" 是合法的纯数字后缀');
        $this->assertSame(0, $processes[0]['pid'], '"00" 应解析为 PID 0');
    }

    /**
     * `proc:` / `tasks:` 的 JSON 损坏不得抛异常，回落占位值。
     */
    public function testMalformedJsonIsTolerated(): void
    {
        $processes = $this->deriver()->processes([
            Monitor::FIELD_PID_AT . '9' => (string)self::NOW,
            Monitor::FIELD_PROC . '9' => '{"role":',
            Monitor::FIELD_TASKS . '9' => 'not json at all',
            Monitor::FIELD_MEMORY_BYTES . '9' => 'NaN',
        ], self::STALE, self::NOW);

        $this->assertSame('?', $processes[0]['role']);
        $this->assertSame(-1, $processes[0]['worker_id']);
        $this->assertSame([], $processes[0]['tasks']);
        $this->assertSame(0, $processes[0]['memory_bytes']);
    }

    /**
     * `jobs` 内层字段的归一化：缺字段 / 非数字 / 非 bool 一律回落，
     * 且非数组的 job 项整体丢弃。
     */
    public function testTaskStatsNormalization(): void
    {
        $processes = $this->deriver()->processes([
            Monitor::FIELD_PID_AT . '5' => (string)self::NOW,
            Monitor::FIELD_TASKS . '5' => json_encode([
                'worker_id' => 0,
                'jobs' => [
                    'good' => ['interval' => 60, 'count' => 3, 'skip' => 0, 'fail' => 1, 'last_cost' => 0.5, 'running' => true],
                    'partial' => ['count' => 'x'],
                    'scalar' => 'not an array',
                ],
            ], JSON_THROW_ON_ERROR),
        ], self::STALE, self::NOW);

        $jobs = $processes[0]['tasks'];

        $this->assertArrayHasKey('good', $jobs);
        $this->assertArrayNotHasKey('scalar', $jobs, '非数组的 job 应整体丢弃');
        $this->assertTrue($jobs['good']['running']);

        $this->assertArrayHasKey('partial', $jobs);
        $this->assertSame(0, $jobs['partial']['count'], '"x" 非数字 → 0');
        $this->assertSame(0.0, $jobs['partial']['interval']);
        $this->assertFalse($jobs['partial']['running'], '缺失 running → false');
    }

    /**
     * 顺序稳定性：按 PID 升序。前端按序 diff，顺序抖动会导致整表重排。
     */
    public function testProcessesSortedByPidAscending(): void
    {
        $processes = $this->deriver()->processes([
            Monitor::FIELD_PID_AT . '300' => (string)self::NOW,
            Monitor::FIELD_PID_AT . '20' => (string)self::NOW,
            Monitor::FIELD_PID_AT . '4000' => (string)self::NOW,
        ], self::STALE, self::NOW);

        $this->assertSame([20, 300, 4000], array_column($processes, 'pid'));
    }

    /**
     * 空 gauge → 空进程表，且不报错。
     */
    public function testEmptyGaugeYieldsEmptyProcessList(): void
    {
        $this->assertSame([], $this->deriver()->processes([], self::STALE, self::NOW));
    }

    // -----------------------------------------------------------------
    // 告警
    // -----------------------------------------------------------------

    /**
     * 最该被看见的信号：gauge 一个字段都没有（MONITOR_ENABLE=false 或 DB/PREFIX 配错）。
     *
     * ⚠ 这里刻意**不走 derive() 辅助方法** —— 那个辅助会给空 gauge 补一个健康进程，
     * 以免污染无关断言；而本用例要的正是「真空 gauge」。
     */
    public function testAlertWhenGaugeIsEmpty(): void
    {
        $result = $this->deriver()->derive([], [], [], 1000, self::STALE, self::NOW);

        $this->assertCount(1, $result['alerts']);
        $this->assertSame(MetricsDeriver::LEVEL_BAD, $result['alerts'][0]['level']);
        $this->assertSame('指标为空', $result['alerts'][0]['title']);
    }

    /**
     * gauge 有全局字段但没有任何 `pid_at:*` → 无法判定进程存活（版本不一致的典型信号）。
     */
    public function testAlertWhenGaugeHasNoProcessFields(): void
    {
        $result = $this->derive(gauge: ['conn_total' => '5', 'report_at' => (string)self::NOW]);

        $this->assertSame('无进程指标', $result['alerts'][0]['title']);
        $this->assertSame(MetricsDeriver::LEVEL_BAD, $result['alerts'][0]['level']);
    }

    /**
     * 幽灵进程 → warn，且 detail 里带上 PID 与角色（便于直接定位）。
     */
    public function testAlertForDeadProcess(): void
    {
        $result = $this->derive(gauge: [
            Monitor::FIELD_PID_AT . '888' => (string)(self::NOW - 999),
            Monitor::FIELD_PROC . '888' => '{"role":"gateway","worker_id":0}',
        ]);

        $titles = array_column($result['alerts'], 'title');
        $this->assertContains('存在已退出进程的残留指标', $titles);

        foreach ($result['alerts'] as $alert) {
            if ($alert['title'] === '存在已退出进程的残留指标') {
                $this->assertSame(MetricsDeriver::LEVEL_WARN, $alert['level']);
                $this->assertStringContainsString('888(gateway)', $alert['detail']);
            }
        }
    }

    /**
     * 比率越限 → 告警级别与比率级别一致，且 detail 里给出算式与原始分子分母。
     */
    public function testAlertForRatioBreachCarriesFormula(): void
    {
        $result = $this->derive(counter: ['msg_in' => '100', 'msg_fail' => '20']);

        $found = false;
        foreach ($result['alerts'] as $alert) {
            if ($alert['title'] === '消息失败率偏高（今日累计）') {
                $found = true;
                $this->assertSame(MetricsDeriver::LEVEL_BAD, $alert['level']);
                $this->assertStringContainsString('msg_fail / msg_in', $alert['detail']);
                $this->assertStringContainsString('20.00%', $alert['detail']);
                $this->assertStringContainsString('20 / 100', $alert['detail']);
            }
        }

        $this->assertTrue($found, '应产生消息失败率告警');
    }

    /**
     * 队列积压达阈值 → bad；恰好等于阈值即算积压。
     */
    public function testAlertForQueueBacklogAtThreshold(): void
    {
        $atThreshold = $this->derive(queues: ['queue:push:out' => 1000], queueWarnDepth: 1000);
        $below = $this->derive(queues: ['queue:push:out' => 999], queueWarnDepth: 1000);

        $this->assertContains('队列积压超阈值', array_column($atThreshold['alerts'], 'title'));
        $this->assertNotContains('队列积压超阈值', array_column($below['alerts'], 'title'));
    }

    /**
     * 健康快照必须**零告警** —— 否则面板会长期挂着噪声，真故障被淹没。
     */
    public function testHealthySnapshotProducesNoAlerts(): void
    {
        $result = $this->derive(
            counter: [
                'msg_in' => '10000',
                'msg_fail' => '1',        // 0.01%
                'auth_success' => '500',
                'auth_fail' => '1',       // 0.2%
                'action_in' => '5000',
                'action_fail' => '1',
                'action_timeout' => '1',
                'push_in' => '9000',
                'push_fail' => '0',
                'udp_out' => '1000',
                'udp_out_fail' => '0',
                'conn_open' => '200',
                'heartbeat_timeout' => '1',   // 0.5%
            ],
            gauge: [
                Monitor::FIELD_PID_AT . '100' => (string)self::NOW,
                Monitor::FIELD_PROC . '100' => '{"role":"all","worker_id":0}',
                'conn_total' => '42',
            ],
            queues: ['queue:action:in' => 0, 'queue:push:out' => 3]
        );

        $this->assertSame([], $result['alerts'], '健康快照不应产生任何告警');
    }

    // -----------------------------------------------------------------
    // derive() 组合
    // -----------------------------------------------------------------

    /**
     * `derive()` 必须把三块结果都带上，且结构完整（供 API 直接返回）。
     */
    public function testDeriveReturnsAllSections(): void
    {
        $result = $this->derive(counter: ['msg_in' => '100', 'msg_fail' => '0']);

        $this->assertArrayHasKey('ratios', $result);
        $this->assertArrayHasKey('processes', $result);
        $this->assertArrayHasKey('alerts', $result);
        $this->assertNotEmpty($result['ratios']);
        $this->assertNotEmpty($result['processes']);
    }

    // -----------------------------------------------------------------
    // 辅助
    // -----------------------------------------------------------------

    private function deriver(): MetricsDeriver
    {
        return new MetricsDeriver();
    }

    /**
     * @param array<string, string> $counter
     * @param array<string, string> $gauge
     * @param array<string, int>    $queues
     *
     * @return array{
     *     ratios: list<RatioRow>,
     *     processes: list<ProcessRow>,
     *     alerts: list<AlertRow>
     * }
     */
    private function derive(
        array $counter = [],
        array $gauge = [],
        array $queues = [],
        int $queueWarnDepth = 1000
    ): array {
        // 默认给一个健康进程，避免「无进程指标」告警污染与本组无关的断言
        if ($gauge === []) {
            $gauge = [
                Monitor::FIELD_PID_AT . '4242' => (string)self::NOW,
                Monitor::FIELD_PROC . '4242' => '{"role":"all","worker_id":0}',
                'report_at' => (string)self::NOW,
            ];
        }

        return $this->deriver()->derive($counter, $gauge, $queues, $queueWarnDepth, self::STALE, self::NOW);
    }

    /**
     * 造一个「距今恰好 $ageSecs 上报」的进程。
     *
     * @return list<ProcessRow>
     */
    private function processesWithAge(int $ageSecs): array
    {
        return $this->deriver()->processes([
            Monitor::FIELD_PID_AT . '6' => (string)(self::NOW - $ageSecs),
        ], self::STALE, self::NOW);
    }

    /**
     * 造一个「上报时间在未来 $offsetSecs」的进程（时钟回拨场景）。
     *
     * @return list<ProcessRow>
     */
    private function processesWithOffset(int $offsetSecs): array
    {
        return $this->deriver()->processes([
            Monitor::FIELD_PID_AT . '6' => (string)(self::NOW + $offsetSecs),
        ], self::STALE, self::NOW);
    }

    /**
     * 按 key 取一条比率。
     *
     * @param list<RatioRow> $ratios
     *
     * @return RatioRow
     */
    private function ratio(array $ratios, string $key): array
    {
        foreach ($ratios as $ratio) {
            if ($ratio['key'] === $key) {
                return $ratio;
            }
        }

        $this->fail('比率不存在：' . $key);
    }
}
