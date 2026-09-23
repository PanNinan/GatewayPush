<?php
/**
 * Monitor 单元测试（纯函数层）
 *
 * 覆盖 staleFields() —— 从 gauge 快照里挑出「已退出进程」的残留字段。
 *
 * 为什么这段逻辑值得单独测：
 *   gauge 是 Hash，**field 没有独立 TTL**，而 report() 每次上报都刷新整个 key
 *   的过期时间。只要还有任意一个活进程在写，key 就永不过期，已退出进程的
 *   proc / pid_at / tasks / memory_bytes 四个字段会无限累积 —— 面板进程列表
 *   越拉越长、Redis 内存缓慢增长、HGETALL 返回体越来越大。清理是唯一的出口，
 *   所以两个方向的错误都不可接受：
 *     - 漏删：残留继续累积，等于没修
 *     - 误删：活进程字段被清掉，面板进程反复闪烁，更糟
 *
 *   判定阈值刻意用 ttl（600s）而不是面板的 interval*2（10s）：后者是纯展示
 *   阈值，拿来做删除判据会在一次长任务阻塞 / GC 停顿时误删活进程。
 *   本测试把「边界保活」这一条钉死。
 *
 * 不覆盖 purgeExitedProcesses() 本身：它依赖 RedisClient 异步回调，属端到端
 * 范畴，由 e2e 覆盖 —— 与 RateLimiterTest 对 L2 的处理口径一致。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Tests\Unit;

use GatewayPush\Business\Monitor;
use PHPUnit\Framework\TestCase;

class MonitorTest extends TestCase
{
    /** 与 config 默认值一致 */
    public const TTL = 600;

    /* -----------------------------------------------------------------
     | 前置短路
     ----------------------------------------------------------------- */

    public function testReturnsEmptyWhenGaugeIsEmpty()
    {
        $this->assertSame([], Monitor::staleFields([], self::TTL, 1000000));
    }

    public function testReturnsEmptyWhenTtlDisabled()
    {
        // ttl <= 0 视为「不清理」—— 配置成 0 时不能反过来变成「全都删」
        $gauge = $this->gauge([123 => 1]);
        $this->assertSame([], Monitor::staleFields($gauge, 0, 1000000));
        $this->assertSame([], Monitor::staleFields($gauge, -1, 1000000));
    }

    /* -----------------------------------------------------------------
     | 存活判定与边界
     ----------------------------------------------------------------- */

    public function testKeepsLiveProcess()
    {
        $now   = 1000000;
        $gauge = $this->gauge([123 => $now - 1]);
        $this->assertSame([], Monitor::staleFields($gauge, self::TTL, $now));
    }

    public function testKeepsProcessExactlyAtDeadline()
    {
        // 边界：pid_at 恰好等于 now - ttl 时**保活**（用 >= 判定）。
        // 若哪天被改成 >，这条会红 —— 那意味着宽限期被悄悄缩短了 1s 且语义不清。
        $now   = 1000000;
        $gauge = $this->gauge([123 => $now - self::TTL]);
        $this->assertSame([], Monitor::staleFields($gauge, self::TTL, $now));
    }

    public function testPurgesProcessOneSecondPastDeadline()
    {
        $now   = 1000000;
        $gauge = $this->gauge([123 => $now - self::TTL - 1]);

        $this->assertSame([
            Monitor::FIELD_PID_AT . '123',
            Monitor::FIELD_PROC . '123',
            Monitor::FIELD_TASKS . '123',
            Monitor::FIELD_MEMORY_BYTES . '123',
        ], Monitor::staleFields($gauge, self::TTL, $now));
    }

    public function testPurgesAllFourFieldsOfEachDeadProcess()
    {
        // 四个字段必须同进同出：漏掉任意一个都会留下孤儿字段
        $now   = 1000000;
        $gauge = $this->gauge([222 => $now - self::TTL - 900]);
        $fields = Monitor::staleFields($gauge, self::TTL, $now);

        $this->assertCount(4, $fields);
        $this->assertContains('pid_at:222', $fields);
        $this->assertContains('proc:222', $fields);
        $this->assertContains('tasks:222', $fields);
        $this->assertContains('memory_bytes:222', $fields);
    }

    public function testPurgesOnlyExpiredAmongMixedProcesses()
    {
        $now = 1000000;
        $gauge = $this->gauge([
            111 => $now - 5,                 // 活
            222 => $now - self::TTL - 1,     // 刚过宽限
            333 => $now - 10,                // 活
            444 => $now - self::TTL - 4780,  // 久（实测出现过的残留时长）
        ]);

        $fields = Monitor::staleFields($gauge, self::TTL, $now);

        $this->assertCount(8, $fields, '两个死进程各 4 个字段');
        $this->assertContains('proc:222', $fields);
        $this->assertContains('memory_bytes:444', $fields);
        $this->assertNotContains('pid_at:111', $fields);
        $this->assertNotContains('pid_at:333', $fields);
        $this->assertNotContains('proc:111', $fields);
        $this->assertNotContains('tasks:333', $fields);
    }

    /* -----------------------------------------------------------------
     | 越界保护：不该动的东西一概不动
     ----------------------------------------------------------------- */

    public function testNeverTouchesGlobalFields()
    {
        // report_at / conn_* 不属于任何进程，没有「退出」概念 —— 删了面板就读不到数据
        $now = 1000000;
        $gauge = $this->gauge(
            [222 => $now - self::TTL - 1],
            [
                'report_at' => (string)$now,
                'conn_total' => '12',
                'conn_ws'    => '7',
                'conn_udp'   => '5',
            ]
        );

        $fields = Monitor::staleFields($gauge, self::TTL, $now);
        $this->assertNotContains('report_at', $fields);
        $this->assertNotContains('conn_total', $fields);
        $this->assertNotContains('conn_ws', $fields);
        $this->assertNotContains('conn_udp', $fields);
    }

    public function testIgnoresProcessFieldsWithoutPidAt()
    {
        // 没有 pid_at 就无从判存活 —— 宁可留下也不误删
        $gauge = [
            'proc:222'         => '{"role":"business","worker_id":0}',
            'tasks:222'        => '{"worker_id":0,"jobs":[]}',
            'memory_bytes:222' => '4194304',
        ];
        $this->assertSame([], Monitor::staleFields($gauge, self::TTL, 1000000));
    }

    public function testIgnoresNonNumericPidSuffix()
    {
        // 后缀非纯数字（脏字段 / 前缀被当键名误用）不得进入删除列表
        $gauge = [
            'pid_at:abc' => '1',
            'pid_at:12x' => '1',
            'pid_at:'    => '1',
            'pid_at: 7'  => '1',
        ];
        $this->assertSame([], Monitor::staleFields($gauge, self::TTL, 1000000));
    }

    public function testIgnoresMissingOrCorruptTimestamp()
    {
        // (int) 转换后 <= 0 即视为缺失 —— 不能当成「1970 年，早已过期」而删掉
        $gauge = [
            'pid_at:333' => '0',
            'pid_at:444' => '',
            'pid_at:555' => 'abc',
        ];
        $this->assertSame([], Monitor::staleFields($gauge, self::TTL, 1000000));
    }

    /* -----------------------------------------------------------------
     | 金标：字段前缀是对外契约
     ----------------------------------------------------------------- */

    public function testFieldPrefixGoldStandard()
    {
        // 前缀被面板 JS 的正则、以及写入侧拼接共同依赖。
        // 改名不是「顺手重构」，而是一次需要同时改三处的决定（PHP 写入 / PHP 清理 / 面板解析）。
        $this->assertSame('pid_at:', Monitor::FIELD_PID_AT);
        $this->assertSame('proc:', Monitor::FIELD_PROC);
        $this->assertSame('tasks:', Monitor::FIELD_TASKS);
        $this->assertSame('memory_bytes:', Monitor::FIELD_MEMORY_BYTES);
    }

    public function testDashboardParsesTheSameFieldPrefixes()
    {
        // 跨语言一致性：面板靠正则解析这四类字段。PHP 侧改了前缀而面板没改，
        // 表现是「采集正常但面板进程列表空」—— 排查成本很高，这里直接钉死。
        $html = (string)file_get_contents($this->root('resources/dashboard/index.html'));

        foreach ([
            Monitor::FIELD_PID_AT,
            Monitor::FIELD_PROC,
            Monitor::FIELD_TASKS,
            Monitor::FIELD_MEMORY_BYTES,
        ] as $prefix) {
            $this->assertStringContainsString(
                '/^' . $prefix . '(\d+)$/',
                $html,
                '面板未按前缀 ' . $prefix . ' 解析字段'
            );
        }
    }

    /* -----------------------------------------------------------------
     | 结构性防线：清理调用不能被悄悄摘掉
     ----------------------------------------------------------------- */

    public function testReportInvokesPurgeAfterExpire()
    {
        $src = (string)file_get_contents($this->root('src/Business/Monitor.php'));

        $expire = $this->statementOffset($src, 'RedisClient::expire($gaugeKey, $ttl);');
        $purge  = $this->statementOffset($src, 'self::purgeExitedProcesses($gaugeKey, $ttl, $now);');

        $this->assertNotNull($expire, 'report() 中找不到 gauge 续期调用');
        $this->assertNotNull($purge, 'report() 中找不到残留清理调用 —— 残留会重新开始累积');
        $this->assertGreaterThan((int)$expire, (int)$purge, '清理应先完成本进程字段写入与续期，再执行');
    }

    /**
     * 构造一份 gauge 快照：每个 pid 生成 4 个字段（与写入侧字段完全一致）
     *
     * @param array $pidAts pid => pid_at 时间戳
     * @param array $extra  额外字段（全局指标等）
     *
     * @return array
     */
    private function gauge(array $pidAts, array $extra = [])
    {
        $gauge = $extra;
        foreach ($pidAts as $pid => $at) {
            $gauge[Monitor::FIELD_PID_AT . $pid]       = (string)$at;
            $gauge[Monitor::FIELD_PROC . $pid]         = '{"role":"business","worker_id":0}';
            $gauge[Monitor::FIELD_TASKS . $pid]        = '{"worker_id":0,"jobs":[]}';
            $gauge[Monitor::FIELD_MEMORY_BYTES . $pid] = '4194304';
        }

        return $gauge;
    }

    private function root($relative)
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    /**
     * 定位「真实语句」在源码中的偏移量
     *
     * 必须锚定行首，不能用 strpos：strpos 只找子串，把语句注释掉（前面加 //）
     * 依然算命中 —— 那样这条结构性断言就形同虚设（已实测验证过这个漏洞）。
     * 行首锚点要求该语句是该行第一个语法单元，注释行因此被排除。
     *
     * @param string $src
     * @param string $statement
     *
     * @return null|int 找不到时返回 null
     */
    private function statementOffset($src, $statement)
    {
        $pattern = '/^[ \t]*' . preg_quote($statement, '/') . '/m';
        if (preg_match($pattern, $src, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return $m[0][1];
    }
}
