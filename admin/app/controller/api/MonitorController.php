<?php

declare(strict_types=1);

namespace app\controller\api;

use app\service\MonitorAggregator;
use support\Request;
use support\Response;

/**
 * 监控数据 API（均为只读）。
 *
 * 权限点（`wa_rules.key`，由 `scripts/install.php` 写入）：
 * - `app\controller\api\MonitorController@health`
 * - `app\controller\api\MonitorController@summary`
 *
 * 鉴权由 `config/route.php` 挂载的 `app\middleware\AdminAuth` 完成。
 */
final class MonitorController
{
    /**
     * 轻量健康探测：只探主项目 `/health`，不打 Redis、不聚合。
     *
     * 用途：监控页的高频轮询（默认 5s）—— 探活不该每次都把 Redis 指标全量读一遍。
     */
    public function health(Request $request): Response
    {
        return json([
            'code' => 0,
            'msg' => 'ok',
            'data' => (new MonitorAggregator())->apiHealth(),
        ]);
    }

    /**
     * 完整快照：Redis（在线数 / gauge / counter / 队列 / 键自检）+ 主项目 API（health / stats）。
     *
     * 前端两个端点的分工：`summary` 用于首屏与低频刷新，`health` 用于高频探活。
     */
    public function summary(Request $request): Response
    {
        return json([
            'code' => 0,
            'msg' => 'ok',
            'data' => (new MonitorAggregator())->collect(),
        ]);
    }
}
