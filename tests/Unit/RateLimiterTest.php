<?php
/**
 * RateLimiter 单元测试（仅 L1 进程内内存桶）
 *
 * 只覆盖零 IO 的那一层：
 *   - spec()        配额读取与容量下限
 *   - checkMemory() 令牌桶放行 / 拒绝 / 按时间补充 / 桶隔离
 *   - evictIfNeeded() 桶数量上限与淘汰下限
 *   - logReject()   按维度每秒采样
 *
 * 不覆盖 acquire()（L2 Redis 桶）：依赖 RedisClient 异步回调，
 * 属端到端范畴，由 tests/e2e_check.php 覆盖。
 *
 * L1 的抗洪水能力完全建立在这些性质上：任何一项被改坏，
 * 网关层要么被伪造源 IP 打爆内存，要么把合法突发流量误杀。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Tests\Unit;

use GatewayPush\Common\Logger;
use GatewayPush\Common\RateLimiter;
use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{
    /**
     * 采样测试用的日志目录
     *
     * @var string
     */
    private $logDir = '';

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gwpush-rl-test';
        $this->useQuietLogger();
        RateLimiter::reset();
        $this->configure();
    }

    protected function tearDown(): void
    {
        RateLimiter::reset();
        $this->useQuietLogger();
        $this->clearLogs();
    }

    /**
     * 日志级别设为 ERROR：info / warn 在写盘前即被丢弃，
     * 既满足 beStrictAboutOutputDuringTests，也不污染临时目录。
     *
     * @return void
     */
    private function useQuietLogger()
    {
        Logger::init(array(
            'path'   => $this->logDir,
            'level'  => Logger::ERROR,
            'role'   => 'test',
            'stdout' => false,
        ));
    }

    /**
     * 写入限流配置（每次传入完整集合，避免上一个用例的静态残留影响判定）
     *
     * @param array $overrides
     * @return void
     */
    private function configure(array $overrides = array())
    {
        RateLimiter::init(array_merge(array(
            'enable'          => true,
            'conn'            => array('rate' => 20,  'burst' => 40),
            'uid'             => array('rate' => 50,  'burst' => 100),
            'ip'              => array('rate' => 200, 'burst' => 400),
            'ping'            => array('rate' => 5,   'burst' => 10),
            'close_on_exceed' => false,
            'notify'          => true,
            'mem_max_buckets' => 20000,
        ), $overrides));
    }

    /**
     * @return void
     */
    private function clearLogs()
    {
        $files = glob($this->logDir . DIRECTORY_SEPARATOR . '*.log');
        if (is_array($files)) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        // 目录由本用例创建，运行结束一并清掉，避免临时目录累积空壳
        @rmdir($this->logDir);
    }

    /* ---------------------------------------------------------------------
     | 配置读取
     --------------------------------------------------------------------- */

    public function testEnabledShouldCloseAndShouldNotifyFollowConfig(): void
    {
        $this->configure(array('enable' => false, 'close_on_exceed' => true, 'notify' => false));

        $this->assertFalse(RateLimiter::enabled());
        $this->assertTrue(RateLimiter::shouldClose());
        $this->assertFalse(RateLimiter::shouldNotify());
    }

    public function testSpecRaisesBurstUpToRate(): void
    {
        $this->configure(array('conn' => array('rate' => 20, 'burst' => 5)));

        // 容量小于速率会异常收紧瞬时突发，实现强制抬齐
        $this->assertSame(array('rate' => 20, 'burst' => 20), RateLimiter::spec('conn'));
    }

    public function testSpecKeepsBurstWhenAlreadyLarger(): void
    {
        $this->configure(array('conn' => array('rate' => 20, 'burst' => 40)));

        $this->assertSame(array('rate' => 20, 'burst' => 40), RateLimiter::spec('conn'));
    }

    public function testSpecOfUnknownDimensionMeansUnlimited(): void
    {
        $this->assertSame(array('rate' => 0, 'burst' => 0), RateLimiter::spec('no_such_dim'));
        $this->assertTrue(RateLimiter::checkMemory('no_such_dim', 'anything'), 'rate = 0 表示该维度不限流');
    }

    public function testCheckMemoryAllowsEverythingWhenDisabled(): void
    {
        $this->configure(array('enable' => false, 'ip' => array('rate' => 1, 'burst' => 1)));

        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue(RateLimiter::checkMemory('ip', '10.0.0.1'));
        }
    }

    /* ---------------------------------------------------------------------
     | 令牌桶判定
     --------------------------------------------------------------------- */

    public function testBurstIsConsumedThenRequestsAreRejected(): void
    {
        $this->configure(array('ip' => array('rate' => 1, 'burst' => 3)));

        $this->assertTrue(RateLimiter::checkMemory('ip', '10.0.0.1'));
        $this->assertTrue(RateLimiter::checkMemory('ip', '10.0.0.1'));
        $this->assertTrue(RateLimiter::checkMemory('ip', '10.0.0.1'));
        $this->assertFalse(RateLimiter::checkMemory('ip', '10.0.0.1'), '容量耗尽后应拒绝');
    }

    public function testTokensAreRefilledOverTime(): void
    {
        $this->configure(array('ip' => array('rate' => 1000, 'burst' => 2)));

        $this->assertTrue(RateLimiter::checkMemory('ip', '10.0.0.2'));
        $this->assertTrue(RateLimiter::checkMemory('ip', '10.0.0.2'));

        // 此处不断言「第三次被拒」：rate = 1000/s 即 1 令牌/毫秒，
        // 两次调用间隔只要超过 1ms 就会补足令牌，断言会与机器速度耦合。
        // 「耗尽即拒绝」由 testBurstIsConsumedThenRequestsAreRejected
        // 以 rate = 1/s 覆盖，时间窗口宽裕。
        usleep(20000);

        // 若补充逻辑失效，令牌将恒为 0，此断言即失败
        $this->assertTrue(RateLimiter::checkMemory('ip', '10.0.0.2'), '经过时间应按速率线性补充令牌');
    }

    public function testCostIsClampedToAtLeastOne(): void
    {
        $this->configure(array('ip' => array('rate' => 1, 'burst' => 2)));

        // cost = 0 会被归一化为 1，否则可用 0 成本无限调用
        $this->assertTrue(RateLimiter::checkMemory('ip', '10.0.0.3', 0));
        $this->assertTrue(RateLimiter::checkMemory('ip', '10.0.0.3', 0));
        $this->assertFalse(RateLimiter::checkMemory('ip', '10.0.0.3', 0));
    }

    public function testOverLargeCostIsRejectedWithoutDeductingQuota(): void
    {
        $this->configure(array('ip' => array('rate' => 1, 'burst' => 5)));

        $this->assertFalse(RateLimiter::checkMemory('ip', '10.0.0.4', 100));

        // 被拒绝的请求不得扣减令牌，否则失败请求会成为耗尽配额的手段
        $this->assertTrue(RateLimiter::checkMemory('ip', '10.0.0.4', 5), '拒绝后容量应保持完整');
    }

    public function testBucketsAreIsolatedByDimensionAndId(): void
    {
        $this->configure();

        RateLimiter::checkMemory('ip', '10.0.0.5');
        RateLimiter::checkMemory('ip', '10.0.0.6');
        RateLimiter::checkMemory('conn', '10.0.0.5');
        RateLimiter::checkMemory('ip', '10.0.0.5');

        $this->assertSame(3, RateLimiter::bucketCount(), '同一维度不同主体、不同维度均应各占一个桶');
    }

    public function testResetClearsInMemoryState(): void
    {
        $this->configure(array('ip' => array('rate' => 1, 'burst' => 1)));

        $this->assertTrue(RateLimiter::checkMemory('ip', '10.0.0.7'));
        $this->assertFalse(RateLimiter::checkMemory('ip', '10.0.0.7'));
        $this->assertSame(1, RateLimiter::bucketCount());

        RateLimiter::reset();

        $this->assertSame(0, RateLimiter::bucketCount());
        $this->assertTrue(RateLimiter::checkMemory('ip', '10.0.0.7'), '重置后应恢复满容量');
    }

    /* ---------------------------------------------------------------------
     | 桶淘汰
     --------------------------------------------------------------------- */

    public function testReachingBucketLimitEvictsHalf(): void
    {
        $this->configure(array(
            'mem_max_buckets' => 100,
            'ip'              => array('rate' => 1, 'burst' => 1000),
        ));

        // 第 101 个新桶创建前触发淘汰：100 个桶保留后一半后新增，得 51
        for ($i = 0; $i < 101; $i++) {
            RateLimiter::checkMemory('ip', 'ip-' . $i);
        }

        $this->assertSame(51, RateLimiter::bucketCount(), '达到上限应淘汰最旧的一半，防止伪造源 IP 撑爆内存');
    }

    public function testBucketLimitHasLowerBoundOfOneHundred(): void
    {
        $this->configure(array(
            'mem_max_buckets' => 5,     // 低于下限，实现按 100 处理
            'ip'              => array('rate' => 1, 'burst' => 1000),
        ));

        for ($i = 0; $i < 101; $i++) {
            RateLimiter::checkMemory('ip', 'ip-' . $i);
        }

        $this->assertSame(51, RateLimiter::bucketCount());
    }

    public function testExistingBucketsAreNotEvictedWhileUnderLimit(): void
    {
        $this->configure(array('ip' => array('rate' => 1, 'burst' => 2)));

        for ($i = 0; $i < 50; $i++) {
            RateLimiter::checkMemory('ip', 'keep-' . $i);
        }

        $this->assertSame(50, RateLimiter::bucketCount());

        // 已有桶仍保有各自配额，说明未被误淘汰
        $this->assertTrue(RateLimiter::checkMemory('ip', 'keep-0'));
    }

    /* ---------------------------------------------------------------------
     | 超限日志采样
     --------------------------------------------------------------------- */

    public function testLogRejectSamplesPerDimensionWithinOneSecond(): void
    {
        $this->ensureLogDir();
        Logger::init(array('path' => $this->logDir, 'level' => Logger::WARN, 'role' => 'test', 'stdout' => false));
        RateLimiter::reset();
        $this->configure(array('ip' => array('rate' => 1, 'burst' => 3)));

        RateLimiter::logReject('ip', '10.0.0.11');
        RateLimiter::logReject('ip', '10.0.0.12');   // 同维度、1 秒内 -> 被采样抑制
        RateLimiter::logReject('conn', 'cid-1');     // 不同维度独立采样 -> 落盘

        $file  = $this->logDir . DIRECTORY_SEPARATOR . 'test_' . date('Y-m-d') . '.log';
        $this->assertFileExists($file);

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(2, $lines, '被限流的流量本身即洪水，同维度每秒最多一条');
        $this->assertStringContainsString('10.0.0.11', $lines[0]);
    }

    /**
     * @return void
     */
    private function ensureLogDir()
    {
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0777, true);
        }
    }
}
