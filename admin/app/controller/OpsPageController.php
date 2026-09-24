<?php

declare(strict_types=1);

namespace app\controller;

use app\controller\api\OpsController as OpsApiController;
use app\service\ErrorAggregator;
use app\service\LogTailService;
use app\service\Perm;
use support\Request;
use support\Response;

/**
 * 运维页（P5）—— 角色状态 / 日志尾读 / 密钥轮换引导。
 *
 * 与 {@see \app\controller\api\OpsController} 的分工同 P2/P3 页面：
 * - 本类 ★只渲染骨架★，不含任何数据；
 * - api 侧提供 3 个只读端点：`logs` / `roles` / `rotation`。
 *
 * 权限点（`wa_rules.key`）：`app\controller\OpsPageController`（菜单节点）。
 * ⚠ 本页**只给运维角色**（与 /actions 同级）：日志内容可能含敏感行、
 * 轮换引导含密钥指纹，不给只读角色。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */
final class OpsPageController
{
    public function index(Request $request): Response
    {
        return view('ops/index', [
            'title' => 'GatewayPush 运维',
            'config' => [
                'logs_url' => '/api/ops/logs',
                'roles_url' => '/api/ops/roles',
                'rotation_url' => '/api/ops/rotation',
                'queues_url' => '/api/ops/queues',
                'errors_url' => '/api/ops/errors',
                'config_url' => '/api/ops/config',
                'rate_url' => '/api/ops/rate',

                // 日志尾读的选项面：role 白名单与行数上限都由后端下发，前端不硬编码
                'log_roles' => LogTailService::ROLES,
                'tail_max' => LogTailService::TAIL_MAX,
                'error_default_lines' => ErrorAggregator::DEFAULT_LINES,

                // 渲染期权限：只影响显隐，边界在 AdminAuth + wa_rules
                'perms' => Perm::map([
                    'logs' => [OpsApiController::class, 'logs'],
                    'roles' => [OpsApiController::class, 'roles'],
                    'rotation' => [OpsApiController::class, 'rotation'],
                    'queues' => [OpsApiController::class, 'queues'],
                    'errors' => [OpsApiController::class, 'errors'],
                    'config' => [OpsApiController::class, 'config'],
                    'rate' => [OpsApiController::class, 'rate'],
                ]),
            ],
        ]);
    }
}
