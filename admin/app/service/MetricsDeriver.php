<?php

declare(strict_types=1);

namespace app\service;

use GatewayPush\Business\Monitor;

/**
 * 指标派生计算 —— **纯函数，零 IO**。
 *
 * 为什么单独成一个类（而不是塞进 `MonitorAggregator`）：
 * 派生率的边界情形极多（分母为 0、日切负增量、字段缺失、幽灵进程恰好落在阈值上），
 * 这些都必须能被单元测试钉死。把它写成**不碰 Redis / 不读配置**的纯函数，
 * 才能用 PHPUnit 覆盖其中的每一条分支 —— 与主项目 `Monitor::staleFields()` 同一套路数。
 * 因此：**本类不得引入任何 IO**，阈值与当前时间一律由调用方注入。
 *
 * ## 派生率的语义（最易误读，务必看清）
 *
 * 主项目的 `metrics:counter:{Ymd}` 是**当日累计**（`HINCRBY` 累加，保留 7 天），
 * 所以这里算出来的一律是「**今日累计比率**」，**不是瞬时速率**。
 * UI 必须这样标注；否则运维会把「今日 0.3% 失败率」误读为「此刻失败率 0.3%」。
 * 需要瞬时速率时，由前端对相邻两次采样做差（见 `dashboard.js`），不在本类职责内。
 *
 * ## 阈值的两个来源
 *
 * 1. **类常量默认值**（下述 `RATIOS`）—— 兜底，保证任何时候都有可用阈值；
 * 2. `admin_settings.monitor.ratio_thresholds` 的 JSON **覆盖**（由调用方读好后传入）。
 *    覆盖是**逐项可选**的：只写想改的项，其余继续用默认值；
 *    任何非法 JSON / 非法数值一律**静默回落默认值**（阈值只影响配色，不该让页面出错）。
 *
 * ## 与主项目常量的关系（不要改成字面量）
 *
 * gauge 的字段前缀（`pid_at:` / `proc:` / `tasks:` / `memory_bytes:`）直接引用
 * `GatewayPush\Business\Monitor::FIELD_*` 常量，**不复制字面量** —— 复制即漂移，
 * 一旦主项目改了前缀，后台会静默地读不到任何进程（表现为「进程表空」而不是报错）。
 * 实测该引用不会连带加载 workerman 异步栈（`use` 语句不触发自动加载，
 * 常量访问只需 `Monitor.php` 本身），因此后台可安全复用。
 *
 * @phpstan-type RatioRow array{
 *     key: string, label: string, formula: string, num: int, den: int,
 *     value: float|null, level: string, kind: string
 * }
 * @phpstan-type JobRow array{
 *     interval: float, count: int, skip: int, fail: int, last_cost: float, running: bool
 * }
 * @phpstan-type ProcessRow array{
 *     pid: int, role: string, worker_id: int, memory_bytes: int,
 *     reported_at: int, age_secs: int, alive: bool,
 *     tasks: array<string, JobRow>
 * }
 * @phpstan-type AlertRow array{level: string, title: string, detail: string}
 * @phpstan-type Derived array{
 *     ratios: list<RatioRow>, processes: list<ProcessRow>, alerts: list<AlertRow>
 * }
 */
final class MetricsDeriver
{
    /** 正常 */
    public const LEVEL_OK = 'ok';

    /** 触发预警阈值 */
    public const LEVEL_WARN = 'warn';

    /** 触发严重阈值 */
    public const LEVEL_BAD = 'bad';

    /**
     * 比率规格表。
     *
     * - `num` / `den`：计数器名列表，**多项表示求和**（如鉴权失败率的分母 = 成功 + 失败）；
     * - `kind`：`error` 参与告警配色，`share` 仅作占比展示（无良莠之分，恒为 ok）；
     * - `warn` / `bad`：百分比阈值，`null` 表示该项不参与告警。
     *
     * 计数器的完整清单来自主项目 `Monitor::incr()` 的调用点（实测枚举，非猜测）。
     * 新增指标时须同步本表，否则该指标不会出现在派生率里。
     *
     * @var array<string, array{
     *     label: string,
     *     formula: string,
     *     num: list<string>,
     *     den: list<string>,
     *     kind: string,
     *     warn: float|null,
     *     bad: float|null
     * }>
     */
    private const RATIOS = [
        'msg_fail_rate' => [
            'label' => '消息失败率',
            'formula' => 'msg_fail / msg_in',
            'num' => ['msg_fail'],
            'den' => ['msg_in'],
            'kind' => 'error',
            'warn' => 1.0,
            'bad' => 5.0,
        ],
        'auth_fail_rate' => [
            'label' => '鉴权失败率',
            'formula' => 'auth_fail / (auth_success + auth_fail)',
            'num' => ['auth_fail'],
            'den' => ['auth_success', 'auth_fail'],
            'kind' => 'error',
            'warn' => 10.0,
            'bad' => 30.0,
        ],
        'action_fail_rate' => [
            'label' => '动作失败率',
            'formula' => 'action_fail / action_in',
            'num' => ['action_fail'],
            'den' => ['action_in'],
            'kind' => 'error',
            'warn' => 1.0,
            'bad' => 5.0,
        ],
        'action_timeout_rate' => [
            'label' => '动作超时率',
            'formula' => 'action_timeout / action_in',
            'num' => ['action_timeout'],
            'den' => ['action_in'],
            'kind' => 'error',
            'warn' => 0.5,
            'bad' => 2.0,
        ],
        'push_fail_rate' => [
            'label' => '推送失败率',
            'formula' => 'push_fail / push_in',
            'num' => ['push_fail'],
            'den' => ['push_in'],
            'kind' => 'error',
            'warn' => 1.0,
            'bad' => 5.0,
        ],
        'udp_out_fail_rate' => [
            'label' => 'UDP 下发失败率',
            'formula' => 'udp_out_fail / (udp_out + udp_out_fail)',
            'num' => ['udp_out_fail'],
            'den' => ['udp_out', 'udp_out_fail'],
            'kind' => 'error',
            'warn' => 1.0,
            'bad' => 5.0,
        ],
        'heartbeat_timeout_rate' => [
            'label' => '心跳超时率',
            'formula' => 'heartbeat_timeout / conn_open',
            'num' => ['heartbeat_timeout'],
            'den' => ['conn_open'],
            'kind' => 'error',
            'warn' => 20.0,
            'bad' => 50.0,
        ],
        'push_offline_share' => [
            'label' => '离线缓存占比',
            'formula' => 'push_offline / push_in',
            'num' => ['push_offline'],
            'den' => ['push_in'],
            'kind' => 'share',
            'warn' => null,
            'bad' => null,
        ],
        'push_dedup_share' => [
            'label' => '幂等去重占比',
            'formula' => 'push_dedup / push_in',
            'num' => ['push_dedup'],
            'den' => ['push_in'],
            'kind' => 'share',
            'warn' => null,
            'bad' => null,
        ],
    ];

    /**
     * 阈值覆盖项（来自 `admin_settings.monitor.ratio_thresholds`）。
     *
     * 形状：`{ "比率key": { "warn": float|null, "bad": float|null }, ... }`，
     * 只写想改的项即可，缺失项回落类常量默认值。
     *
     * @var array<string, mixed>
     */
    private readonly array $thresholdOverrides;

    /**
     * @param array<string, mixed> $thresholdOverrides 逐项可选的阈值覆盖；非法项静默忽略
     */
    public function __construct(array $thresholdOverrides = [])
    {
        $this->thresholdOverrides = $thresholdOverrides;
    }

    /**
     * 一次算齐所有派生结果（供 API 直接返回）。
     *
     * @param array<string, string> $counter         今日累计计数器（`metrics:counter:{Ymd}` 的 HGETALL）
     * @param array<string, string> $gauge           瞬时指标（`metrics:gauge` 的 HGETALL）
     * @param array<string, int>    $queues          队列深度，键为逻辑队列名
     * @param int                   $queueWarnDepth  队列积压告警阈值
     * @param int                   $staleSecs       进程存活判据（秒）。**必须**是
     *                                               `MONITOR_INTERVAL × 2`（默认 10），
     *                                               不能用 `MONITOR_TTL`(600) —— 两者刻意不同
     * @param int                   $now             当前时间戳
     *
     * @return Derived
     */
    public function derive(
        array $counter,
        array $gauge,
        array $queues,
        int $queueWarnDepth,
        int $staleSecs,
        int $now
    ): array {
        $ratios = $this->ratios($counter);
        $processes = $this->processes($gauge, $staleSecs, $now);

        return [
            'ratios' => $ratios,
            'processes' => $processes,
            'alerts' => $this->alerts($ratios, $processes, $gauge, $queues, $queueWarnDepth),
        ];
    }

    /**
     * 今日累计比率。
     *
     * **分母为 0 时 `value` 返回 `null`（而不是 0）** —— 二者语义完全不同：
     * `null` = 「今日尚无样本」，`0` = 「今日有样本且零失败」。
     * 混同会让面板在系统刚启动时显示「0% 失败率」这一虚假的健康信号。
     *
     * @param array<string, string> $counter
     *
     * @return list<RatioRow>
     */
    public function ratios(array $counter): array
    {
        $thresholds = $this->resolveThresholds();
        $result = [];

        foreach (self::RATIOS as $key => $spec) {
            $num = $this->sumCounters($counter, $spec['num']);
            $den = $this->sumCounters($counter, $spec['den']);
            $value = $den > 0 ? round($num / $den * 100, 2) : null;
            $threshold = $thresholds[$key];

            $result[] = [
                'key' => $key,
                'label' => $spec['label'],
                'formula' => $spec['formula'],
                'num' => $num,
                'den' => $den,
                'value' => $value,
                'level' => $this->levelOf($value, $threshold['warn'], $threshold['bad']),
                'kind' => $spec['kind'],
            ];
        }

        return $result;
    }

    /**
     * 从 gauge 快照解析「进程表」。
     *
     * 为什么必须自己解析：gauge 的 `pid_at:{pid}` / `proc:{pid}` / `tasks:{pid}` /
     * `memory_bytes:{pid}` 是**纯进程内状态**（角色名、worker 号、定时任务健康度），
     * 跨进程读不到，只能靠主项目 `Monitor::report()` 随指标落库 —— 所以这里是
     * 后台唯一能看到「哪个 PID 是什么角色、定时任务有没有卡」的地方。
     *
     * 存活判据：`0 < reported_at` 且 `age_secs <= staleSecs`。刻意用 `<=`：
     * 恰好落在阈值上仍算存活（与主项目 `Monitor::staleFields()` 的
     * `$at >= $deadline` 判定方向一致 —— 两处若反向，会出现「面板说活着、
     * 清理逻辑说死了」的诡异组合）。
     *
     * 时间戳缺失 / 为 0 / 非数字 → 一律 `alive = false` 且 `age_secs = -1`
     * （不可信即不健康），**但不臆造内容**：角色回落 `?`、worker_id 回落 `-1`。
     *
     * @param array<string, string> $gauge
     * @param int                   $staleSecs 存活宽限（秒）
     * @param int                   $now
     *
     * @return list<ProcessRow>
     */
    public function processes(array $gauge, int $staleSecs, int $now): array
    {
        $byPid = [];

        foreach ($gauge as $field => $value) {
            if (!str_starts_with($field, Monitor::FIELD_PID_AT)) {
                continue;
            }

            $pid = substr($field, strlen(Monitor::FIELD_PID_AT));

            // 只认「pid_at: 开头且后缀纯数字」。`report_at` / `conn_total` 等全局字段
            // 不以该前缀开头，天然被排除 —— 它们不属于任何进程，没有「存活」概念。
            if ($pid === '' || !ctype_digit($pid)) {
                continue;
            }

            $reportedAt = is_numeric($value) ? (int)$value : 0;
            $age = $reportedAt > 0 ? max(0, $now - $reportedAt) : -1;
            $alive = $staleSecs > 0 && $reportedAt > 0 && $age <= $staleSecs;
            $proc = $this->decodeJson($gauge[Monitor::FIELD_PROC . $pid] ?? '');
            $tasks = $this->decodeJson($gauge[Monitor::FIELD_TASKS . $pid] ?? '');
            $memory = $gauge[Monitor::FIELD_MEMORY_BYTES . $pid] ?? '';

            $byPid[(int)$pid] = [
                'pid' => (int)$pid,
                'role' => isset($proc['role']) && is_string($proc['role']) ? $proc['role'] : '?',
                'worker_id' => isset($proc['worker_id']) && is_numeric($proc['worker_id'])
                    ? (int)$proc['worker_id']
                    : -1,
                'memory_bytes' => is_numeric($memory) ? (int)$memory : 0,
                'reported_at' => $reportedAt,
                'age_secs' => $age,
                'alive' => $alive,
                'tasks' => $this->taskStats($tasks['jobs'] ?? null),
            ];
        }

        // 用 ksort 而非 usort：键就是 pid，天然唯一且无需闭包（闭包的 array 形参
        // 在 PHPStan L6 下要额外 phpdoc，得不偿失）。顺序稳定 → 前端 diff 不抖动。
        ksort($byPid);

        return array_values($byPid);
    }

    /**
     * 由派生结果 + 原始数据生成告警清单。
     *
     * @param list<RatioRow>   $ratios
     * @param list<ProcessRow> $processes
     * @param array<string, string> $gauge
     * @param array<string, int>    $queues
     *
     * @return list<AlertRow>
     */
    public function alerts(
        array $ratios,
        array $processes,
        array $gauge,
        array $queues,
        int $queueWarnDepth
    ): array {
        $alerts = [];

        // ① 一条指标都没有 —— 这是最该被看见的信号，且极易被误读成「系统没流量」。
        if ($gauge === []) {
            $alerts[] = [
                'level' => self::LEVEL_BAD,
                'title' => '指标为空',
                'detail' => 'metrics:gauge 无任何字段：主项目 MONITOR_ENABLE=false 时采集整条短路，'
                    . '或后台 ADMIN_REDIS_DB / ADMIN_REDIS_PREFIX 配错（配错时不报错，只读到空数据）。',
            ];
        } elseif ($processes === []) {
            $alerts[] = [
                'level' => self::LEVEL_BAD,
                'title' => '无进程指标',
                'detail' => 'gauge 有字段但没有任何 pid_at:* —— 无法判定进程存活，请核对主项目版本是否一致。',
            ];
        }

        // ② 幽灵进程：字段还在，但已超过存活宽限。主项目靠 Monitor::purgeExitedProcesses()
        //    主动清理；清不掉通常意味着「最后一个活进程也停了」（没有进程再执行清理）。
        $dead = [];
        foreach ($processes as $process) {
            if (!$process['alive']) {
                $dead[] = $process['pid'] . '(' . $process['role'] . ')';
            }
        }
        if ($dead !== []) {
            $alerts[] = [
                'level' => self::LEVEL_WARN,
                'title' => '存在已退出进程的残留指标',
                'detail' => '共 ' . count($dead) . ' 个：' . implode('、', $dead)
                    . '。主项目 report() 会主动清理；若长期清不掉，多半是最后一个活进程也停了。',
            ];
        }

        // ③ 比率越限
        foreach ($ratios as $ratio) {
            if ($ratio['level'] === self::LEVEL_OK) {
                continue;
            }

            $alerts[] = [
                'level' => $ratio['level'],
                'title' => $ratio['label'] . '偏高（今日累计）',
                'detail' => $ratio['formula'] . ' = ' . $this->ratioText($ratio['value'])
                    . '（' . $ratio['num'] . ' / ' . $ratio['den'] . '）',
            ];
        }

        // ④ 队列积压
        foreach ($queues as $name => $depth) {
            if ($depth >= $queueWarnDepth) {
                $alerts[] = [
                    'level' => self::LEVEL_BAD,
                    'title' => '队列积压超阈值',
                    'detail' => $name . ' 当前深度 ' . $depth . '，已达阈值 ' . $queueWarnDepth . '。',
                ];
            }
        }

        return $alerts;
    }

    /**
     * 合并「类常量默认值」与「调用方覆盖值」，产出每项的最终阈值。
     *
     * 覆盖项任何非法（非数组 / 非数字）一律**静默忽略** —— 阈值只影响配色，
     * 不该因为一个手滑的配置把整个监控页打挂。
     *
     * @return array<string, array{warn: float|null, bad: float|null}>
     */
    private function resolveThresholds(): array
    {
        $resolved = [];

        foreach (self::RATIOS as $key => $spec) {
            $warn = $spec['warn'];
            $bad = $spec['bad'];
            $override = $this->thresholdOverrides[$key] ?? null;

            if (is_array($override)) {
                if (isset($override['warn']) && is_numeric($override['warn'])) {
                    $warn = (float)$override['warn'];
                }
                if (isset($override['bad']) && is_numeric($override['bad'])) {
                    $bad = (float)$override['bad'];
                }
            }

            $resolved[$key] = ['warn' => $warn, 'bad' => $bad];
        }

        return $resolved;
    }

    /**
     * 阈值分档。**恰好等于阈值即触发**（用 `>=`）—— 与 `processes()` 的存活判定
     * 方向刻意保持一致：两者都是「到点即算」。
     *
     * `value` 为 `null`（无样本）时恒为 ok：没有样本不等于有问题。
     */
    private function levelOf(?float $value, ?float $warn, ?float $bad): string
    {
        if ($value === null || $warn === null || $bad === null) {
            return self::LEVEL_OK;
        }

        if ($value >= $bad) {
            return self::LEVEL_BAD;
        }

        return $value >= $warn ? self::LEVEL_WARN : self::LEVEL_OK;
    }

    /** 比率展示文本，`null` 显示为 `—`（区别于 0.00%） */
    private function ratioText(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 2) . '%';
    }

    /**
     * 多个计数器求和。缺失 / 非数字一律按 0 计（计数器按需创建，
     * 「没有这个字段」在本项目里就等于「今日为 0」，不是错误）。
     *
     * @param array<string, string> $counter
     * @param list<string>          $names
     */
    private function sumCounters(array $counter, array $names): int
    {
        $sum = 0;

        foreach ($names as $name) {
            if (isset($counter[$name]) && is_numeric($counter[$name])) {
                $sum += (int)$counter[$name];
            }
        }

        return $sum;
    }

    /**
     * 解析 gauge 里存放 JSON 的字段（`proc:` / `tasks:`）。
     * 非法 JSON 一律回落空数组，不抛异常 —— 一个坏字段不该让整页 500。
     *
     * @return array<string, mixed>
     */
    private function decodeJson(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 归一化 `tasks:{pid}` 里的 `jobs` 段。
     *
     * 主项目形状（`Task::stats()`）：
     * `{ job_name: { interval, count, skip, fail, last_cost, running }, ... }`
     *
     * @param mixed $raw
     *
     * @return array<string, JobRow>
     */
    private function taskStats(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $jobs = [];

        foreach ($raw as $name => $job) {
            if (!is_array($job)) {
                continue;
            }

            $jobs[(string)$name] = [
                'interval' => isset($job['interval']) && is_numeric($job['interval'])
                    ? (float)$job['interval']
                    : 0.0,
                'count' => isset($job['count']) && is_numeric($job['count']) ? (int)$job['count'] : 0,
                'skip' => isset($job['skip']) && is_numeric($job['skip']) ? (int)$job['skip'] : 0,
                'fail' => isset($job['fail']) && is_numeric($job['fail']) ? (int)$job['fail'] : 0,
                'last_cost' => isset($job['last_cost']) && is_numeric($job['last_cost'])
                    ? (float)$job['last_cost']
                    : 0.0,
                'running' => is_bool($job['running'] ?? null) ? (bool)$job['running'] : false,
            ];
        }

        return $jobs;
    }
}
