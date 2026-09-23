<?php

declare(strict_types=1);

namespace app\controller;

use app\service\Settings;
use support\Request;
use support\Response;

/**
 * 健康总览页。
 *
 * 与主项目 `src/Dashboard/` 的分工（设计文档 §0 已决策「并存」）：
 * - Dashboard（:8291） = 免登录的轻量只读静态页，面向「大屏 / 外部探针」；
 * - 本页（:8292）      = 登录后的交互式管理面，按下钻 / 时间范围 / 告警阈值演进。
 *
 * ## P1 起：本方法**不再采集数据**，只渲染骨架
 *
 * P0 是「服务端一次采齐 + `<meta refresh>` 整页重载」。那个做法有两个硬伤：
 *  ① 每次刷新都整页重建 DOM，无法保留**趋势**（历史在换页时被丢掉），也无法做局部更新；
 *  ② 一次请求内串行做完 Redis 全量 + 主项目 HTTP，主项目一慢，整页就白屏等待。
 *
 * 现在改为：本方法只输出骨架与一份前端配置，数据由浏览器按**快/慢两条 tick**
 * 分别拉 `MonitorController::live()` 与 `summary()`。带来三个结果：
 *  - 趋势可累积（浏览器端环形缓冲，见 `public/static/dashboard.js`）；
 *  - 主项目 API 挂掉只影响慢 tick 那几块，快 tick 照常刷新；
 *  - 调参（间隔 / 阈值）改库即生效，无需改代码、也无需重启后台
 *    （`Settings` 的缓存带 TTL，见其类注释）。
 *
 * 权限点（`wa_rules.key`）：`app\controller\DashboardController`
 */
final class DashboardController
{
    /**
     * 前端从最近多少点里画趋势。120 点 × 5s ≈ 10 分钟窗口。
     *
     * 刻意**只放浏览器内存**：主项目没有历史指标存储（只有当前 gauge + 当日累计
     * counter），做服务端历史要新增采样表与采样进程，属后续阶段的事。
     * 因此刷新页面即清零 —— UI 上也必须如实标注「本次会话」。
     */
    private const HISTORY_POINTS = 120;

    public function index(Request $request): Response
    {
        return view('dashboard/index', [
            'title' => 'GatewayPush 健康总览',
            // 前端配置整体注入为一个 JSON 块，避免在骨架里散落 data-* 属性。
            'config' => [
                'live_url' => '/api/monitor/live',
                'summary_url' => '/api/monitor/summary',
                'live_interval_ms' => max(1, Settings::int('monitor.poll_interval', 5)) * 1000,
                'slow_interval_ms' => max(1, Settings::int('monitor.slow_interval', 30)) * 1000,
                'history_points' => self::HISTORY_POINTS,
                // 这两个阈值前端只用于**展示与标色**；真正的告警判定在服务端
                // （`MetricsDeriver`）完成。前端阈值与服务端若不一致，会出现
                // 「表格标红但告警条没有」的矛盾 —— 故两边读的是同一个 setting。
                'queue_warn_depth' => max(1, Settings::int('monitor.queue_warn_depth', 1000)),
                'gauge_stale_secs' => max(1, Settings::int('monitor.gauge_stale_secs', 10)),
                'dashboard_url' => $this->cfg('dashboard_url', 'http://127.0.0.1:8291'),
            ],
        ]);
    }

    private function cfg(string $key, string $default = ''): string
    {
        $value = config('gateway_push.' . $key, $default);

        return is_scalar($value) ? (string)$value : $default;
    }
}
