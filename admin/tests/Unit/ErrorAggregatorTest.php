<?php
/**
 * admin 单测 —— ErrorAggregatorTest。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace tests\Unit;

use app\service\ErrorAggregator;
use app\service\LogTailService;
use PHPUnit\Framework\TestCase;

/**
 * 2.0 §1.4 错误日志聚合的服务层契约。
 *
 * 钉的是三条「计数会骗人」的边界：
 * 1. **角色文件按 `[ERROR]` 过滤**，汇总文件不过滤、且**不计入 total**
 *    （否则同一批行会被双写加成两倍）；
 * 2. **not_found ≠ 错误** —— 今天还没日志要说清楚，不渲染成 0 条红字；
 * 3. **单角色失败不拖垮整表** —— 一行 bad，其余照常出数。
 *
 * fixture 目录与 `P5OpsServiceTest` 共用 `tests/Unit/fixtures/logs`。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */
final class ErrorAggregatorTest extends TestCase
{
    private const DATE = '2026-09-24';

    public function testAggregateReturnsSevenRowsSixRolesPlusDigest(): void
    {
        $res = $this->svc()->aggregate(self::DATE);

        $this->assertSame(self::DATE, $res['date']);
        $this->assertSame(ErrorAggregator::ERROR_KEYWORD, $res['keyword']);
        $this->assertCount(7, $res['roles'], '六业务角色 + error 汇总');

        $names = array_column($res['roles'], 'role');
        foreach (['register', 'gateway', 'udp', 'business', 'api', 'dashboard', 'error'] as $role) {
            $this->assertContains($role, $names, '缺角色行 ' . $role);
        }
    }

    public function testRoleRowsFilterByErrorKeyword(): void
    {
        $res = $this->svc()->aggregate(self::DATE, 50);

        $business = $this->byRole($res, 'business');
        $this->assertTrue($business['ok'], $business['hint']);
        $this->assertFalse($business['not_found']);
        // fixtures/business_2026-09-24.log 里 2 行 [ERROR]、其余行不过滤
        $this->assertSame(2, $business['count'], '只数 [ERROR] 行');
        $this->assertNotCount(0, $business['lines']);
        foreach ($business['lines'] as $line) {
            $this->assertStringContainsString(
                ErrorAggregator::ERROR_KEYWORD,
                $line,
                '展示行也必须是过滤后的命中行'
            );
        }
    }

    public function testDigestRowCountsEveryLineAndIsNotInTotal(): void
    {
        $res = $this->svc()->aggregate(self::DATE, 50);

        $digest = $this->byRole($res, 'error');
        $this->assertTrue($digest['ok'], $digest['hint']);
        // fixtures/error_2026-09-24.log：全部是 error 行、可无 [ERROR] 字面量
        $this->assertGreaterThan(0, $digest['count'], '汇总通道必须有行');
        foreach ($digest['lines'] as $line) {
            $this->assertNotSame('', trim((string)$line));
        }

        $roleSum = 0;
        foreach ($res['roles'] as $row) {
            if ($row['role'] !== 'error' && $row['ok']) {
                $roleSum += $row['count'];
            }
        }
        $this->assertSame(
            $roleSum,
            $res['total'],
            '★ total 必须只含业务角色 —— 汇总文件是同一批行的副本，计入会翻倍'
        );
    }

    public function testMissingFileIsNotFoundNotError(): void
    {
        $res = $this->svc()->aggregate('1999-01-01');

        foreach ($res['roles'] as $row) {
            $this->assertFalse($row['ok'], $row['role']);
            $this->assertTrue($row['not_found'], $row['role'] . ' 应标 not_found');
            $this->assertSame(0, $row['count']);
            $this->assertSame([], $row['lines'], 'not_found 不得带出行');
            $this->assertSame('日志文件不存在', $row['hint']);
        }
        $this->assertSame(0, $res['total']);
    }

    public function testLinesClampedToTailMax(): void
    {
        $res = $this->svc()->aggregate(self::DATE, 0);
        $this->assertSame(1, $res['lines'], '0 夹到 1');

        $res = $this->svc()->aggregate(self::DATE, 99999);
        $this->assertSame(LogTailService::TAIL_MAX, $res['lines'], '超上限夹到 TAIL_MAX');
    }

    public function testApiFixtureWithoutErrorLinesCountsZero(): void
    {
        $res = $this->svc()->aggregate(self::DATE, 50);

        $api = $this->byRole($res, 'api');
        $this->assertTrue($api['ok']);
        $this->assertSame(0, $api['count'], 'api fixture 全是 INFO/WARN，[ERROR] 过滤后应为 0');
        $this->assertFalse($api['not_found'], '文件在，只是没有 error —— 不是 not_found');
    }

    private function svc(): ErrorAggregator
    {
        return new ErrorAggregator(new LogTailService(__DIR__ . '/fixtures/logs'));
    }

    /**
     * @param array{roles: list<array<string, mixed>>} $result
     *
     * @return array<string, mixed>
     */
    private function byRole(array $result, string $role): array
    {
        foreach ($result['roles'] as $row) {
            if ($row['role'] === $role) {
                return $row;
            }
        }

        $this->fail('结果里没有角色 ' . $role);
    }
}
