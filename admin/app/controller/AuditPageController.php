<?php
/**
 * admin · 页面 / API 控制器 —— AuditPageController。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace app\controller;

use app\controller\api\AuditController as AuditApiController;
use app\service\Auditor;
use app\service\Perm;
use support\Request;
use support\Response;

/**
 * 行为日志页 —— 读 `admin_audit_log`（业务语义审计）的只读检索入口。
 *
 * 与 {@see \app\controller\api\AuditController} 的分工同其它页面：
 * 本类 ★只渲染骨架★，数据经 `GET /api/audit/logs` 取回。
 *
 * 前身是插件「示例页面 → 系统管理 → 行为日志」静态 demo（读假 JSON），
 * 2026-09-24 起示例 demo 树（demos）由 install.php 步骤 3b 从 wa_rules 清理；
 * 本页是替代它的**真实可用**菜单（menu.php 本体是 vendor 副本，不可作为清理真源）。
 *
 * 权限点（`wa_rules.key`）：`app\controller\AuditPageController`（菜单节点）。
 * 只读检索与 dashboard / 会话查询同级 —— 本页与 `audit.list` 同时授予「只读」与「运维」。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */
final class AuditPageController
{
    /**
     * 渲染行为日志页骨架。
     */
    public function index(Request $request): Response
    {
        return view('audit/index', [
            'title' => 'GatewayPush 行为日志',
            'config' => [
                'list_url' => '/api/audit/logs',
                // 动作枚举由后端下发（Auditor::ACTIONS 单一真源），前端不硬编码
                'actions' => Auditor::ACTIONS,
                'results' => [Auditor::RESULT_OK, Auditor::RESULT_FAILED],
                'page_size_options' => [20, 50, 100],
                'page_size_default' => 20,
                'perms' => Perm::map([
                    'list' => [AuditApiController::class, 'index'],
                ]),
            ],
        ]);
    }
}
