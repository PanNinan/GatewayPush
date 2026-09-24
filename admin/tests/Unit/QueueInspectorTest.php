<?php

declare(strict_types=1);

namespace tests\Unit;

use app\service\QueueInspector;
use GatewayPush\Common\RedisKeys;
use PHPUnit\Framework\TestCase;

/**
 * 2.0 §1.2 队列深度巡检的阈值判定契约。
 *
 * 钉的是三类「静默标错」：
 * 1. 比较方向 —— 恰好达阈值必须标红（`>=`，与 MetricsDeriver「到点即算」同向）；
 *    一处 `>` 一处 `>=` 会出现「面板红、运维页绿」的两套真相。
 * 2. 阈值档位 —— action 与 queue 分档（100 vs 1000）；混档会让动作积压
 *    永远标不出红。
 * 3. 键名与 RedisKeys 同源 —— 手写字面量在改键后会静默读成 0。
 *
 * 阈值来源（settings）依赖 MySQL，不在纯单测里碰；`thresholdsFromSettings`
 * 的坏值夹取由 Settings 的既有容错覆盖。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */
final class QueueInspectorTest extends TestCase
{
    private const THRESHOLDS = ['queue' => 1000, 'action' => 100];

    public function testRowsUseRedisKeysConstantsForQueueNames(): void
    {
        $names = array_column(QueueInspector::ROWS, 'name');

        $this->assertContains(RedisKeys::QUEUE_ACTION_IN, $names, '动作入站行名必须是 RedisKeys 常量');
        $this->assertContains(RedisKeys::QUEUE_PUSH_OUT, $names);
        $this->assertContains(RedisKeys::QUEUE_UDP_IN, $names);
        $this->assertContains(RedisKeys::QUEUE_UDP_OUT, $names);
        $this->assertContains('push_offline', $names, '离线总量是聚合展示键（非单键 LLEN），不带冒号以免被当字面量');
        $this->assertContains('action_result', $names);
        $this->assertCount(6, $names, '六行：四队列 + 离线 + 回执');
    }

    public function testExactThresholdIsBad(): void
    {
        $rows = QueueInspector::rows(
            [
                RedisKeys::QUEUE_UDP_IN => 1000,
                RedisKeys::QUEUE_ACTION_IN => 100,
            ],
            self::THRESHOLDS
        );

        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['name']] = $row;
        }

        $this->assertSame(
            QueueInspector::LEVEL_BAD,
            $byName[RedisKeys::QUEUE_UDP_IN]['level'],
            'UDP 恰好 1000 必须标红（>=）—— 与面板告警同向'
        );
        $this->assertSame(
            QueueInspector::LEVEL_BAD,
            $byName[RedisKeys::QUEUE_ACTION_IN]['level'],
            'action 恰好 100 必须标红（>=）'
        );
    }

    public function testJustBelowThresholdIsOk(): void
    {
        $rows = QueueInspector::rows(
            [
                RedisKeys::QUEUE_UDP_IN => 999,
                RedisKeys::QUEUE_ACTION_IN => 99,
            ],
            self::THRESHOLDS
        );

        foreach ($rows as $row) {
            $this->assertSame(QueueInspector::LEVEL_OK, $row['level'], $row['name'] . ' 应为正常');
        }
    }

    public function testActionAndQueueUseSeparateThresholds(): void
    {
        $rows = QueueInspector::rows(
            [
                RedisKeys::QUEUE_ACTION_IN => 150, // >100 但 <1000
                RedisKeys::QUEUE_PUSH_OUT => 150,   // <1000
            ],
            self::THRESHOLDS
        );

        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['name']] = $row;
        }

        $this->assertSame(QueueInspector::LEVEL_BAD, $byName[RedisKeys::QUEUE_ACTION_IN]['level']);
        $this->assertSame(QueueInspector::LEVEL_OK, $byName[RedisKeys::QUEUE_PUSH_OUT]['level'],
            'queue 阈值是 1000，150 不该被 action 的 100 误伤');
    }

    public function testOfflineAndBacklogUseExpectedThresholdBuckets(): void
    {
        $rows = QueueInspector::rows(
            ['push_offline' => 500, 'action_result' => 150],
            self::THRESHOLDS
        );

        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['name']] = $row;
        }

        // 离线总量走 queue 档（1000）；回执积压走 action 档（100）
        $this->assertSame(1000, $byName['push_offline']['threshold']);
        $this->assertSame(QueueInspector::LEVEL_OK, $byName['push_offline']['level']);
        $this->assertSame(100, $byName['action_result']['threshold']);
        $this->assertSame(QueueInspector::LEVEL_BAD, $byName['action_result']['level']);
    }

    public function testMissingDepthDefaultsToZeroAndNegativeClamped(): void
    {
        $rows = QueueInspector::rows(
            [RedisKeys::QUEUE_UDP_IN => -5],
            self::THRESHOLDS
        );

        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['name']] = $row;
        }

        $this->assertSame(0, $byName[RedisKeys::QUEUE_UDP_IN]['depth'], '负深度夹到 0，UI 不出 -1');
        $this->assertSame(0, $byName[RedisKeys::QUEUE_PUSH_OUT]['depth'], '缺失键按 0，不当 null 摊进前端');
        $this->assertSame(QueueInspector::LEVEL_OK, $byName[RedisKeys::QUEUE_UDP_IN]['level']);
    }

    public function testNonPositiveThresholdsFallBackToAtLeastOne(): void
    {
        $rows = QueueInspector::rows(
            [RedisKeys::QUEUE_UDP_IN => 1],
            ['queue' => 0, 'action' => -10]
        );

        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['name']] = $row;
        }

        $this->assertSame(1, $byName[RedisKeys::QUEUE_UDP_IN]['threshold'], '坏阈值回落 1，不许除零或恒绿');
        $this->assertSame(1, $byName[RedisKeys::QUEUE_ACTION_IN]['threshold']);
        $this->assertSame(QueueInspector::LEVEL_BAD, $byName[RedisKeys::QUEUE_UDP_IN]['level'],
            '阈值 1 且深度 1 → 达阈值即红');
    }

    public function testEveryRowCarriesLabelKindAndThreshold(): void
    {
        $rows = QueueInspector::rows([], self::THRESHOLDS);
        $this->assertCount(6, $rows);

        foreach ($rows as $row) {
            $this->assertNotSame('', $row['label'], $row['name'] . ' 缺中文标签');
            $this->assertContains($row['kind'], ['action', 'queue', 'udp', 'offline']);
            $this->assertGreaterThanOrEqual(1, $row['threshold']);
            $this->assertContains($row['level'], [QueueInspector::LEVEL_OK, QueueInspector::LEVEL_BAD]);
        }
    }
}
