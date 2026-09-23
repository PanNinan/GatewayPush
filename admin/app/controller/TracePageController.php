<?php

declare(strict_types=1);

namespace app\controller;

use app\controller\api\SessionController as SessionApiController;
use app\service\Perm;
use support\Request;
use support\Response;

/**
 * uid 一站式排查页（2.0 §3.2）—— 输入一个 uid，聚合展示它的全部只读状态。
 *
 * 与其它页面的分工同口径：本类 ★只渲染骨架★，页内**并行调用既有 4 个只读端点**
 * （by-uid 会话 / offline 离线队列 / subscriptions 订阅 / by-device 反查），零新增 API。
 * 因此本页**没有也不需要新的 API 权限节点** —— 端点各自的权限（sess.*）就是边界，
 * 页面菜单节点只决定「菜单里能不能看到这一页」。
 *
 * 权限点（`wa_rules.key`）：`app\controller\TracePageController`（菜单节点），
 * 同时授予「只读」与「运维」（页内全是既有只读端点，与 /sessions 同级）。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */
final class TracePageController
{
    public function index(Request $request): Response
    {
        return view('trace/index', [
            'title' => 'GatewayPush uid 排查',
            'config' => [
                'by_uid_url' => '/api/sessions/by-uid',
                'by_device_url' => '/api/sessions/by-device',
                'offline_url' => '/api/sessions/offline',
                'subscriptions_url' => '/api/sessions/subscriptions',

                'perms' => Perm::map([
                    'byUid' => [SessionApiController::class, 'byUid'],
                    'byDevice' => [SessionApiController::class, 'byDevice'],
                    'offline' => [SessionApiController::class, 'offline'],
                    'subs' => [SessionApiController::class, 'subscriptions'],
                ]),
            ],
        ]);
    }
}
