<?php
/**
 * admin · 页面 / API 控制器 —— AccessLogPageController。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace app\controller;

use app\controller\api\AccessLogController as AccessLogApiController;
use app\service\AccessLogger;
use app\service\Perm;
use support\Request;
use support\Response;

/**
 * 访问日志页 —— 读 `wa_admin_log`（后台用户行为）的只读检索入口。
 *
 * 与 {@see AuditPageController}（`admin_audit_log` / GatewayPush 业务审计）**并列、不合并**：
 * - 本页：谁登录了、打开了哪些页面与接口（含只读）
 * - `/audit`：谁对推送系统做了写操作（push / kick / …）
 *
 * 页面纪律同其它页：★只渲染骨架★，数据经 `GET /api/access-logs` 取回。
 *
 * 权限点（`wa_rules.key`）：`app\controller\AccessLogPageController`（菜单节点）。
 * 只读检索与 dashboard / 行为日志同级 —— 本页与 `access.list` 同时授予「只读」与「运维」。
 */
final class AccessLogPageController
{
    /**
     * 渲染访问日志页骨架。
     *
     * 数据经 `GET /api/access-logs` 异步取回。
     */
    public function index(Request $request): Response
    {
        return view('accesslog/index', [
            'title' => '访问日志',
            'config' => [
                'list_url' => '/api/access-logs',
                'events' => AccessLogger::EVENTS,
                'results' => AccessLogger::RESULTS,
                'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
                'page_size_options' => [20, 50, 100],
                'page_size_default' => 20,
                'perms' => Perm::map([
                    'list' => [AccessLogApiController::class, 'index'],
                ]),
            ],
        ]);
    }
}
