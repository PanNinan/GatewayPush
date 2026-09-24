<?php
/**
 * admin · 页面 / API 控制器 —— ActionController。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace app\controller;

use app\controller\api\ActionController as ActionApiController;
use app\service\ActionCatalog;
use app\service\ActionOutcome;
use app\service\Perm;
use app\service\SessionInspector;
use support\Request;
use support\Response;

/**
 * 动作调试页（P3）。
 *
 * 与 {@see \app\controller\api\ActionController}（`app\controller\api\ActionController`）的分工同 P2：
 * - 本类 ★只渲染骨架★，不含任何数据；
 * - api 侧提供 2 个端点：`invoke`（**写** —— 会在真实客户端上执行动作）与 `result`（纯读补查）。
 *
 * 权限点（`wa_rules.key`）：`app\controller\ActionController`（菜单节点）。
 * ⚠ 本页**只给运维角色**：它能在任意 uid 的在线连接上执行动作（echo / notify / report…），
 * 属「主动对生产连接施加行为」，与只读查询不是一个风险级别。故 `scripts/install.php`
 * 不把它放进只读角色 —— 只读角色甚至打不开这一页（点菜单 403）。
 *
 * ---------------------------------------------------------------------
 * 补查为什么用「有界退避」而不是定时器
 * ---------------------------------------------------------------------
 * `POST /api/action` 超窗会返回 `pending`，此时需要凭 `request_id` 补查
 * `GET /api/action/{request_id}`。补查是**一次性等待**（等这一条结果），不是
 * 「持续观察某个会变化的状态」，因此：
 * - 用 `setTimeout` **递归**（每次只挂一个，取到结果或耗尽次数即停）；
 * - 延迟序列取自 {@see POLL_DELAYS_MS}（后端下发，前端不得自行编数）；
 * - **不设任何 `setInterval`** —— 那会变成「无论有没有结果都持续打主项目 API」。
 *
 * 两条不变量（由 `tests/Unit/ActionContractTest.php` 钉住，不是注释）：
 * 1. `push.js` / `action.js` 内不得出现定时器调用；
 * 2. 退避总时长必须**小于**回执保留窗口（`ActionCatalog::RESULT_TTL_MIRROR`）——
 *    否则最后一两次补查注定落在窗口外，只会在界面上稳定产出「已超出补查窗口」的噪声。
 */
final class ActionController
{
    /**
     * 补查退避序列（毫秒）。总时长 23s，刻意留在 60s 回执窗口内且留足余量。
     *
     * 为什么是 5 次而不是更多：`ACTION_RESULT_TTL` 是硬窗口，超出后的补查必然返回
     * `expired`（且该状态**两义**，服务端不区分「还在跑」与「已回收」）。
     * 继续重试只是把「不知道」这个结论重复更多遍。
     *
     * `public` 是刻意的：契约测试直接断言 `array_sum(POLL_DELAYS_MS) < RESULT_TTL_MIRROR * 1000`。
     *
     * @var list<int>
     */
    public const POLL_DELAYS_MS = [1000, 2000, 4000, 8000, 8000];

    /**
     * 渲染动作页骨架。
     */
    public function index(Request $request): Response
    {
        return view('action/index', [
            'title' => 'GatewayPush 动作调试',
            'config' => [
                // ---- 端点 ----
                'invoke_url' => '/api/action',
                'result_base' => '/api/action/',

                /* ---- 动作白名单：**只列 HTTP 已开放的 6 个** ----
                 | `session` 刻意不在其中（它是 `http=false`，HTTP 通道下语义不成立），
                 | 由 ActionCatalog::NOT_HTTP_EXPOSED 显式钉住「不列」这个决定。 */
                'actions' => ActionCatalog::forUi(),
                'names' => ActionCatalog::names(),

                // ---- 时限与服务端口径 ----
                'wait_ms' => ActionCatalog::WAIT_MS_MIRROR,
                'result_ttl' => ActionCatalog::RESULT_TTL_MIRROR,
                'request_id_pattern' => ActionCatalog::REQUEST_ID_PATTERN,
                'id_max_len' => SessionInspector::ID_MAX_LEN,

                // ---- 补查退避（前端定时行为的**唯一**来源）----
                'poll_delays_ms' => self::POLL_DELAYS_MS,

                /* ---- 说明文案（后端下发，前端**既不编词也不选样式**）----
                 | 与 `app\controller\PushController` 同一结构约定：tone 由后端给，
                 | 前端不得按 key 自行判断「哪条严重」。 */
                'notes' => [
                    ['key' => 'scope', 'tone' => 'info', 'text' => ActionCatalog::SCOPE_NOTE],
                    ['key' => 'uid_semantics', 'tone' => 'warn', 'text' => ActionCatalog::UID_SEMANTICS_NOTE],
                    ['key' => 'uid_required', 'tone' => 'info', 'text' => ActionCatalog::UID_REQUIRED_NOTE],
                    ['key' => 'pending', 'tone' => 'info', 'text' => ActionCatalog::PENDING_NOTE],
                    ['key' => 'result_ttl', 'tone' => 'info', 'text' => ActionCatalog::RESULT_TTL_NOTE],
                ],
                // 六种状态的解释。由 `ActionOutcome::NOTES` 原样下发 ——
                // 后端 `invoke` / `result` 响应里的 `state` 就是这些键，前端按 state 取值即可，
                // **不需要**在前端复刻一份 state → 文案 的映射（那必然会与后端漂移）。
                'outcome_notes' => ActionOutcome::NOTES,

                /* ---- 权限：**渲染期只决定控件可见性**，不是权限边界 ----
                 | 控制器名写 `ActionApiController::class` 而非字面量：写错命名空间不报错，
                 | `Auth::canAccess()` 只是静默返回 false（见 `app\service\Perm` 的类注释）。 */
                'perms' => Perm::map([
                    'can_invoke' => [ActionApiController::class, 'invoke'],
                    'can_result' => [ActionApiController::class, 'result'],
                ]),
            ],
        ]);
    }
}
