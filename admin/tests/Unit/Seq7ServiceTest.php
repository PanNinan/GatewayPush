<?php
/**
 * admin 单测 —— Seq7ServiceTest。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace tests\Unit;

use app\service\EnvInfoService;
use app\service\RateInspector;
use PHPUnit\Framework\TestCase;

/**
 * 2.0 序7 服务层契约：
 * - RateInspector：键解析 / 维度标签 / 不可逆说明（纯函数，不碰 Redis）；
 * - EnvInfoService：composer.lock 白名单解析 / runtime 项形状（不依赖框架 config()）。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */
final class Seq7ServiceTest extends TestCase
{
    /* =====================================================================
     | RateInspector：键解析与文案
     ===================================================================== */

    public function testParseBucketKeyAcceptsRlShape(): void
    {
        $md5 = md5('client-1');
        $parsed = RateInspector::parseBucketKey('rl:conn:' . $md5);

        self::assertNotNull($parsed);
        self::assertSame('conn', $parsed[0]);
        self::assertSame(substr($md5, 0, 12), $parsed[1], '指纹必须是 md5 前 12 位');
        self::assertNotSame($md5, $parsed[1], '不得回显完整 md5（增加可关联性）');
    }

    public function testParseBucketKeyRejectsForeignShapes(): void
    {
        self::assertNull(RateInspector::parseBucketKey('session:abc'), '非 rl 前缀拒绝');
        self::assertNull(RateInspector::parseBucketKey('rl:'), '缺 dim 拒绝');
        self::assertNull(RateInspector::parseBucketKey('rl:conn'), '缺 md5 拒绝');
        self::assertNull(RateInspector::parseBucketKey('rl::' . md5('x')), '空 dim 拒绝');
    }

    public function testDimLabelCoversFourDimensions(): void
    {
        self::assertStringContainsString('连接', RateInspector::dimLabel('conn'));
        self::assertStringContainsString('用户', RateInspector::dimLabel('uid'));
        self::assertStringContainsString('IP', RateInspector::dimLabel('ip'));
        self::assertStringContainsString('心跳', RateInspector::dimLabel('ping'));
        self::assertSame('unknown', RateInspector::dimLabel('unknown'), '未知维度回落原值，不编中文');
    }

    public function testNotesDeclareIrreversibility(): void
    {
        $joined = implode("\n", RateInspector::notes());
        self::assertStringContainsString('不可逆', $joined, '必须如实说明 md5 不可逆');
        self::assertStringContainsString('L1', $joined, '须说明进程内桶不在 Redis、本页看不到');
        self::assertStringContainsString('跨天归零', $joined, 'hit 计数跨天语义须交代');
    }

    public function testInspectReturnsShapeWhenRedisDown(): void
    {
        // 纯单测环境不连 Redis —— ping 失败路径必须仍返回完整形状（前端不炸）
        $out = (new RateInspector())->inspect();

        self::assertArrayHasKey('ok', $out);
        self::assertArrayHasKey('hit_today', $out);
        self::assertArrayHasKey('dims', $out);
        self::assertArrayHasKey('buckets', $out);
        self::assertArrayHasKey('api_windows', $out);
        self::assertArrayHasKey('notes', $out);
        self::assertIsBool($out['ok']);
        self::assertSame(0, $out['hit_today']);
        self::assertSame([], $out['buckets']);
    }

    /* =====================================================================
     | EnvInfoService：runtime 项 + composer.lock 白名单
     ===================================================================== */

    public function testViewAlwaysIncludesRuntimeItems(): void
    {
        $view = (new EnvInfoService())->view();

        self::assertNotEmpty($view['items']);
        $keys = array_column($view['items'], 'key');
        self::assertContains('PHP 版本', $keys);
        self::assertContains('PHP SAPI', $keys);
        self::assertContains('操作系统', $keys);

        foreach ($view['items'] as $item) {
            self::assertArrayHasKey('key', $item);
            self::assertArrayHasKey('value', $item);
            self::assertArrayHasKey('configured', $item);
            self::assertArrayHasKey('source', $item);
            // 空值必须 configured=false —— 不把「读不到」伪装成「没有配置但有值」
            if ($item['value'] === '') {
                self::assertFalse($item['configured'], $item['key'] . ' 空值不得 configured=true');
            }
        }
    }

    public function testViewDeclaresWorkermanPackagesFromMainLock(): void
    {
        $view = (new EnvInfoService())->view();
        $keys = array_column($view['items'], 'key');

        // 主项目根 composer.lock 与本仓同树 —— 同仓形态下至少 workerman 应在；
        // 独立部署（lock 不可达）时白名单项整体缺席也算通过（configured 语义已由上一测钉住）。
        // 与 EnvInfoService::packageVersions 同路径：dirname(__DIR__, 3) 已是主项目根。
        $lockPath = dirname(__DIR__, 3) . '/composer.lock';
        if (!is_file($lockPath)) {
            self::assertNotContains('workerman/workerman', $keys, '独立部署下不得伪造包版本');

            return;
        }

        self::assertContains('workerman/workerman', $keys, '主项目 lock 里有 workerman 但未摘出');
        foreach ($view['items'] as $item) {
            if (in_array($item['key'], EnvInfoService::PACKAGES, true)) {
                self::assertTrue($item['configured'], $item['key'] . ' 版本应非空');
                self::assertStringStartsWith('主项目 composer.lock', $item['source']);
            }
        }
    }

    public function testPackageWhitelistIsOrderedAndBounded(): void
    {
        // 白名单顺序即 UI 顺序；过长 = 噪音（把传递依赖误读成主动升级）
        self::assertSame(
            ['workerman/workerman', 'workerman/gateway-worker', 'workerman/register', 'workerman/redis', 'predis/predis'],
            EnvInfoService::PACKAGES
        );
        self::assertLessThanOrEqual(8, count(EnvInfoService::PACKAGES));
    }

    public function testNotesExplainPhpVersionCaveat(): void
    {
        $joined = implode("\n", EnvInfoService::notes());
        self::assertStringContainsString('后台进程', $joined, '须说明 PHP 版本取自后台运行时');
        self::assertStringContainsString('无热重载', $joined, 'APP_ENV 改动语义须交代');
    }
}
