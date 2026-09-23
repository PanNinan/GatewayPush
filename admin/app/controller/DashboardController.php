<?php

declare(strict_types=1);

namespace app\controller;

use app\service\MonitorAggregator;
use app\service\Settings;
use support\Request;
use support\Response;

/**
 * 健康总览页（P0 版）。
 *
 * 与主项目 `src/Dashboard/` 的分工（设计文档 §0 已决策「并存」）：
 * - Dashboard（:8291） = 免登录的轻量只读静态页，面向「大屏 / 外部探针」；
 * - 本页（:8292）      = 登录后的交互式管理面，后续按下钻 / 时间范围 / 告警阈值演进。
 *
 * P0 采用「服务端渲染 + `<meta refresh>` 定时整页重载」：
 * 数据在 `index()` 内一次采齐后直接渲染，**不依赖任何 JS**，因此断网时页面不会白屏。
 * P1 会换成「AJAX 轮询 + Layui 图表」，那时本方法只渲染页面骨架。
 *
 * 权限点（`wa_rules.key`）：`app\controller\DashboardController`
 */
final class DashboardController
{
    public function index(Request $request): Response
    {
        return view('dashboard/index', [
            'title' => 'GatewayPush 健康总览',
            'snapshot' => (new MonitorAggregator())->collect(),
            'dashboard_url' => $this->cfg('dashboard_url', 'http://127.0.0.1:8291'),
            // 轮询间隔与队列告警阈值都来自 admin_settings（库不可用时回落默认值），
            // 因此调参不需要改代码、也不需要重启后台。
            'refresh_secs' => max(1, Settings::int('monitor.poll_interval', 5)),
            'queue_warn_depth' => max(1, Settings::int('monitor.queue_warn_depth', 1000)),
        ]);
    }

    private function cfg(string $key, string $default = ''): string
    {
        $value = config('gateway_push.' . $key, $default);

        return is_scalar($value) ? (string)$value : $default;
    }
}
