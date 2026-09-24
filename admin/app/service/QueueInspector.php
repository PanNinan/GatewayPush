<?php
/**
 * admin · 服务层 —— QueueInspector。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace app\service;

use GatewayPush\Common\RedisKeys;

/**
 * 运维页「队列深度巡检」的阈值判定（2.0 §1.2）。
 *
 * 纯函数、零 IO —— 与 {@see MetricsDeriver} 同一路数：阈值判定边界多
 * （恰好达阈值、负深度防御、缺失阈值回落），必须能被单测钉死。
 *
 * 阈值来源（两段，缺一不可）：
 * - UDP 入/出站与推送出站：起步 1000（`ops.queue_warn_depth`，与
 *   `monitor.queue_warn_depth` 同值但**独立键** —— 后者语义是「面板告警」，
 *   本键是「运维页标红」，调参场景不同，合并会让动一处牵动两处 UI）；
 * - action 入站与回执积压：起步 100（`ops.action_warn_depth`）——
 *   动作队列的正常水位远低于 UDP 批量入站，共用 1000 会永远标不出绿灯变黄。
 *
 * 规划原文：「起步值建议：UDP 队列 > 1000、action 积压 > 100；上线后按实际水位调」。
 * 这里的比较用 `>=`（恰好达阈值即告警），与 MetricsDeriver::levelOf 的
 * 「到点即算」方向一致 —— 两处若一处 > 一处 >=，会出现「面板红、运维页绿」。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */
final class QueueInspector
{
    /** 正常 */
    public const LEVEL_OK = 'ok';

    /** 触发阈值（标红） */
    public const LEVEL_BAD = 'bad';

    /**
     * 展示行：`name` 用 RedisKeys 逻辑键名（不含前缀），`label` 是 UI 文案。
     *
     * `kind` 决定阈值档位：`action` 走 action 阈值，其余走 queue 阈值。
     * `push:offline` / `action:result` 是**聚合指标**（分别对应离线消息总条数、
     * 回执键个数），不是单个 LLEN 键 —— name 仍取 RedisKeys 前缀去尾冒号，
     * 便于与 Redis 键空间对照。
     *
     * @var list<array{name: string, label: string, kind: string}>
     */
    public const ROWS = [
        ['name' => RedisKeys::QUEUE_ACTION_IN, 'label' => 'HTTP 动作入站', 'kind' => 'action'],
        ['name' => RedisKeys::QUEUE_PUSH_OUT, 'label' => '推送出站汇合', 'kind' => 'queue'],
        ['name' => RedisKeys::QUEUE_UDP_IN, 'label' => 'UDP 入站', 'kind' => 'udp'],
        ['name' => RedisKeys::QUEUE_UDP_OUT, 'label' => 'UDP 出站', 'kind' => 'udp'],
        ['name' => 'push_offline', 'label' => '离线消息总量', 'kind' => 'offline'],
        ['name' => 'action_result', 'label' => '动作回执积压', 'kind' => 'action'],
    ];

    /**
     * 把原始深度拼成带阈值与级别的展示行。纯函数。
     *
     * @param array<string, int>             $depths     逻辑键名 / 聚合键（push_offline / action_result）=> 深度
     * @param array{queue: int, action: int} $thresholds 已夹取过的正整数阈值
     *
     * @return list<array{
     *     name: string, label: string, kind: string,
     *     depth: int, threshold: int, level: string
     * }>
     */
    public static function rows(array $depths, array $thresholds): array
    {
        $queueThreshold = max(1, $thresholds['queue']);
        $actionThreshold = max(1, $thresholds['action']);

        $out = [];
        foreach (self::ROWS as $spec) {
            $threshold = $spec['kind'] === 'action' ? $actionThreshold : $queueThreshold;
            $depth = (int)($depths[$spec['name']] ?? 0);
            if ($depth < 0) {
                $depth = 0; // LLEN/SCAN 不会给出负值；防御性夹取，避免 UI 出现 -1
            }

            $out[] = [
                'name' => $spec['name'],
                'label' => $spec['label'],
                'kind' => $spec['kind'],
                'depth' => $depth,
                'threshold' => $threshold,
                'level' => $depth >= $threshold ? self::LEVEL_BAD : self::LEVEL_OK,
            ];
        }

        return $out;
    }

    /**
     * 从 admin_settings 读取两档阈值并夹取为正整数（坏值回落默认）。
     *
     * @return array{queue: int, action: int}
     */
    public static function thresholdsFromSettings(): array
    {
        return [
            'queue' => max(1, Settings::int('ops.queue_warn_depth', 1000)),
            'action' => max(1, Settings::int('ops.action_warn_depth', 100)),
        ];
    }
}
