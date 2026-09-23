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
 * - `app\controller\api\MonitorController@live`
 * - `app\controller\api\MonitorController@summary`
 *
 * 鉴权由 `config/route.php` 挂载的 `app\middleware\AdminAuth` 完成。
 *
 * ## 三条端点的分工（面板按「快 tick / 慢 tick」分别调用）
 *
 * | 端点 | 频率 | 内容 | 是否打主项目 HTTP |
 * |---|---|---|---|
 * | `live`    | 快（5s）  | Redis 直读 + 派生率 + 进程表 | 否 |
 * | `summary` | 慢（30s） | 上面全部 + selfCheck + dbsize + 主项目 `/health` `/stats` | 是 |
 * | `health`  | 按需      | 仅主项目 `/health` 探针 | 是 |
 *
 * 快慢分开的**唯一理由**见 `MonitorAggregator` 类注释：把可能阻塞 8s 的主项目
 * HTTP 调用挡在高频路径之外，避免「主项目一挂、面板一起卡」。
 */
final class MonitorController
{
    /**
     * 轻量实时快照：Redis 直读 + 派生率 + 进程表，**不打主项目 API**。
     *
     * 面板的高频 tick（默认 5s）走这里，因此本方法必须永远快
     * —— 不许在这里加任何可能阻塞的外部调用。
     */
    public function live(Request $request): Response
    {
        return json([
            'code' => 0,
            'msg' => 'ok',
            'data' => (new MonitorAggregator())->live(),
        ]);
    }

    /**
     * 仅探主项目 API 存活：不打 Redis、不聚合。
     *
     * 与 `live` 的区别：`live` 证明「Redis 这条腿通」，`health` 证明「主项目 HTTP 这条腿通」。
     * 两者分开是为了让运维能一眼判定「是哪条腿断了」，而不是得到一个笼统的「面板挂了」。
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
     * 完整快照：在 `live` 的基础上，额外带上
     * Redis 自检（键存在性/类型/TTL）、dbsize、DB 与 PREFIX，以及主项目 `/health` + `/stats`。
     *
     * 前端用于首屏与低频复核 —— 这几项都属「配置是否配对」的验证，
     * 变化极慢，没必要 5s 跑一次。
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
