<?php

declare(strict_types=1);

namespace app\controller;

use app\controller\api\OpsActionController as OpsActionApiController;
use app\service\OpsAction;
use app\service\Perm;
use app\service\SessionInspector;
use app\service\Settings;
use support\Request;
use support\Response;

/**
 * 会话查询页（P2：纯只读）。
 *
 * 与 {@see \app\controller\api\SessionController} 的分工：
 * - 本类（`app\controller\SessionController`）★只渲染骨架★，不含任何数据；
 * - `app\controller\api\SessionController` 提供 7 个只读 JSON 端点，由本页的 JS 按需调用。
 *
 * 权限点（`wa_rules.key`）：`app\controller\SessionController`（菜单节点，见 `scripts/install.php`）。
 * ⚠ 同名的两个控制器在 `wa_rules.key` 里是**不同字符串**
 * （`app\controller\SessionController` vs `app\controller\api\SessionController@index`），
 * 因此页面与 API 的权限可以分别授予，不存在互相顶掉的问题。
 *
 * ---------------------------------------------------------------------
 * 为什么这一页**不像健康总览那样轮询**
 * ---------------------------------------------------------------------
 * 健康总览看的是「此刻的状态」，不看就失去意义，故必须轮询；
 * 会话列表是**检索型**视图 —— 用户输入 uid / 翻页 / 点开某一条才会去看，
 * 静止时反复拉全量列表既无收益、又会把 Redis 读放大到与实际使用无关的频次。
 * 因此本页：**打开时拉一次，其余全部由交互触发**（查询 / 翻页 / 反查 / 抽屉 / 撤销名单）。
 *
 * ⚠ 这条约定有专门的守卫：`tests/Unit/SessionContractTest.php` 断言
 * `public/static/session.js` 内**不存在任何定时器调用** ——
 * 一旦有人为了「自动刷新」加回定时器，门禁立刻变红。
 *
 * 实现同 DashboardController：只输出骨架 + 一份 `#session-config` JSON，
 * 前端配置整体注入，避免在骨架里散落 `data-*`。
 */
final class SessionController
{
    /**
     * 离线队列在抽屉内的分页大小。
     *
     * 刻意**不读 `admin_settings`**：它是抽屉里的「展开看一眼」视图，
     * 与列表分页（`session.page_size`）的调参动机不同（后者受 Redis 往返成本约束）。
     * 两处若共用一个配置，改任一侧都会静默影响另一侧。
     */
    private const OFFLINE_PAGE_SIZE = 20;

    /**
     * 页大小下拉候选。
     *
     * `public` 是刻意的：`tests/Unit/SessionContractTest.php` 直接断言
     * `max(SIZE_OPTIONS) <= SessionInspector::SIZE_MAX` ——
     * 用真实常量断言，而不是正则去匹配源码文本（后者改个排版就失效）。
     */
    public const SIZE_OPTIONS = [20, 50, 100];

    public function index(Request $request): Response
    {
        return view('session/index', [
            'title' => 'GatewayPush 会话查询',
            'config' => [
                // 端点（相对路径，与 config/route.php 一一对应）
                'list_url' => '/api/sessions',
                'detail_base' => '/api/session/',
                'by_uid_base' => '/api/sessions/by-uid/',
                'by_device_base' => '/api/sessions/by-device/',
                'offline_base' => '/api/sessions/offline/',
                'subs_url' => '/api/sessions/subscriptions',
                'revoked_url' => '/api/auth/revoked',

                // ---- P4 运维动作（后台迄今唯一「写主项目」的入口） ----
                //
                // 五个端点各占一个权限节点，只读角色**一个都没有** ——
                // 故下面 `perms` 里的五项对只读角色全为 false，UI 整块隐藏。
                'ops_kick_url' => '/api/ops-action/kick',
                'ops_revoke_url' => '/api/ops-action/revoke',
                'ops_unbind_url' => '/api/ops-action/unbind',
                'ops_force_url' => '/api/ops-action/force-offline',
                'ops_purge_url' => '/api/ops-action/purge-offline',

                // 枚举与上限：一律从 `SessionInspector` 取常量，**不在前端硬编码** ——
                // 前端硬编码的下场是「后端收紧上限后前端仍在请求 size=1000」，静默被夹到 100。
                'scopes' => [
                    SessionInspector::SCOPE_ONLINE,
                    SessionInspector::SCOPE_RETAINED,
                    SessionInspector::SCOPE_ALL,
                ],
                'protocols' => SessionInspector::PROTOCOLS,
                'size_options' => self::sizeOptions(),
                'size_max' => SessionInspector::SIZE_MAX,
                'id_max_len' => SessionInspector::ID_MAX_LEN,
                'page_size' => $this->pageSize(),
                'offline_page_size' => self::OFFLINE_PAGE_SIZE,

                // 「撤销状态不可由会话反推」的固定说明：由后端下发，避免前端各自编词。
                'revoke_note' => SessionInspector::REVOKE_NOTE,

                // ---- P4 运维动作：三条「不做什么」+ 两条组合说明 ----
                //
                // 全部由后端下发（前端一个字都不编）。这三条是本页最容易被误解的地方：
                // 「点了踢线」不等于「用户下线了」—— Token 仍有效，客户端可立即重连。
                'ops_caveats' => OpsAction::CAVEATS,
                'ops_force_note' => OpsAction::FORCE_OFFLINE_NOTE,
                'ops_no_token_note' => OpsAction::NO_TOKEN_NOTE,
                'ops_token_max' => 2048,
                'ops_reason_max' => 128,

                // 渲染期权限：只影响显隐，**不是权限边界**（边界在 AdminAuth + wa_rules）。
                // 用 `::class` 常量而非字符串字面量 —— 写错命名空间会静默返回 false，
                // 表现为「功能不见了」而不是报错，极难查。
                'perms' => Perm::map([
                    'ops_kick' => [OpsActionApiController::class, 'kick'],
                    'ops_revoke' => [OpsActionApiController::class, 'revoke'],
                    'ops_unbind' => [OpsActionApiController::class, 'unbind'],
                    'ops_force' => [OpsActionApiController::class, 'forceOffline'],
                    'ops_purge' => [OpsActionApiController::class, 'purgeOffline'],
                ]),

                'dashboard_url' => $this->cfg('dashboard_url', 'http://127.0.0.1:8291'),
            ],
        ]);
    }

    /**
     * 页大小候选：夹到 `SIZE_MAX` 以内，避免出现「下拉能选、后端却静默夹取」的选项。
     *
     * 刻意用 `min()` 而不是运行时 `if` 过滤 —— 在字面量常量上比较，PHPStan 会直接判定
     * `smallerOrEqual.alwaysTrue`（恒真分支 = 死代码）。这条约束的真正守门人是
     * `tests/Unit/SessionContractTest.php` 的断言，不是这里的分支。
     *
     * @return list<int>
     */
    private static function sizeOptions(): array
    {
        return array_values(array_map(
            static fn (int $n): int => min($n, SessionInspector::SIZE_MAX),
            self::SIZE_OPTIONS
        ));
    }

    /**
     * 列表默认页大小：与 API 侧同一口径（同读 `session.page_size`、同一夹取区间）。
     *
     * 两边口径若不同，会出现「首屏显示 20 条但下拉写着 50」这类自相矛盾。
     */
    private function pageSize(): int
    {
        $configured = Settings::int('session.page_size', 20);

        return max(SessionInspector::SIZE_MIN, min(SessionInspector::SIZE_MAX, $configured));
    }

    private function cfg(string $key, string $default = ''): string
    {
        $value = config('gateway_push.' . $key, $default);

        return is_scalar($value) ? (string)$value : $default;
    }
}
