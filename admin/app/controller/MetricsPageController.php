<?php

declare(strict_types=1);

namespace app\controller;

use app\controller\api\MetricController as MetricApiController;
use app\service\Perm;
use support\Request;
use support\Response;

/**
 * 指标趋势页（2.0 §1.1）—— 在线连接 / 消息速率 / 异常与限流 三组曲线。
 *
 * 与 {@see \app\controller\api\MetricController} 的分工同其它页面：
 * 本类 ★只渲染骨架★，数据全部经 `GET /api/metrics/range|latest` 取回。
 *
 * 权限点（`wa_rules.key`）：`app\controller\MetricsPageController`（菜单节点）。
 * 监测属只读能力 —— 本页与 `metric.range` / `metric.latest` 两个 API 节点
 * **同时授予「只读」与「运维」**（与 dashboard / 会话查询同级）。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */
final class MetricsPageController
{
    public function index(Request $request): Response
    {
        return view('metrics/index', [
            'title' => 'GatewayPush 指标趋势',
            'config' => [
                'range_url' => '/api/metrics/range',
                'latest_url' => '/api/metrics/latest',

                // 可选时间范围（分钟）与降采样点数上限 —— 选项面由后端下发，前端不硬编码
                'range_options' => [30, 60, 180, 360, 1440],
                'points_max' => 720,

                // 采样间隔（用于空数据提示文案：刚部署时表格为空不是故障）。
                // 与 MetricSampler 用同一个 env 键，口径单一。
                'sample_interval' => max(10, (int)(getenv('ADMIN_METRIC_SAMPLE_INTERVAL') ?: 60)),

                'perms' => Perm::map([
                    'range' => [MetricApiController::class, 'range'],
                    'latest' => [MetricApiController::class, 'latest'],
                ]),
            ],
        ]);
    }
}
