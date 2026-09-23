<?php

declare(strict_types=1);

namespace app\controller\api;

use app\service\MetricService;
use support\Request;
use support\Response;

/**
 * 指标趋势只读端点（2.0 §1.1）。
 *
 * 两个端点都是**纯只读**，按 §6「只读角色可见」口径同时授予「只读」与「运维」。
 * 数据来自 gw_metric_samples（MetricSampler 进程持续落库），**不实时打 Redis** ——
 * 趋势页要的是历史曲线，实时快照另有 mon.live（dashboard 页在用）。
 */
final class MetricController
{
    /**
     * 时间序列：`GET /api/metrics/range?minutes=60&points=240`
     *
     * minutes 上限 1440（一天）；points 是降采样目标点数（2~720）。
     */
    public function range(Request $request): Response
    {
        $query = $request->get();
        $minutes = max(5, min(1440, (int)($query['minutes'] ?? 60)));
        $points = max(2, min(720, (int)($query['points'] ?? 240)));

        $now = time();
        $rows = (new MetricService())->range($now - $minutes * 60, $now, $points);

        return json([
            'code' => 0,
            'msg' => 'ok',
            'data' => [
                'from' => $now - $minutes * 60,
                'to' => $now,
                'points' => $points,
                'rows' => $rows,
                'count' => count($rows),
            ],
        ]);
    }

    /**
     * 最新一行：趋势页「当前值」栏（列表为空 = 采样进程尚未跑过或刚清理完）。
     */
    public function latest(Request $request): Response
    {
        $row = (new MetricService())->latest();

        return json([
            'code' => 0,
            'msg' => 'ok',
            'data' => ['row' => $row],
        ]);
    }
}
