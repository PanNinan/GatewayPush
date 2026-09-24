<?php
/**
 * admin · 页面 / API 控制器 —— PushController。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace app\controller;

use app\controller\api\PushController as PushApiController;
use app\service\Perm;
use app\service\Pusher;
use app\service\PushRepository;
use app\service\SessionInspector;
use app\service\Settings;
use support\Request;
use support\Response;

/**
 * 推送管理页（P3：发起推送 + 推送历史 + 模板管理 三合一）。
 *
 * 与 {@see PushApiController}（`app\controller\api\PushController`）的分工同 P2：
 * - 本类 ★只渲染骨架★，不含任何数据；
 * - api 侧提供 5 个端点，其中 3 个是**写**（`create` / `templateSave` / `templateDelete`）。
 *
 * 权限点（`wa_rules.key`）：`app\controller\PushController`（菜单节点，见 `scripts/install.php`）。
 * ⚠ 同名两个控制器在 `wa_rules.key` 里是**不同字符串**，故「能看这一页」与
 * 「能发推送」是两件事 —— 后者由 `app\controller\api\PushController@create` 单独控制。
 *
 * ---------------------------------------------------------------------
 * 本页为什么**没有定时器**
 * ---------------------------------------------------------------------
 * 与 `/sessions` 同理：推送历史是**检索型**视图（用户设条件、翻页才看），
 * 模板是人工维护的少量资产。静止时反复拉列表既无收益、又把后台 MySQL 读放大到
 * 与实际使用无关的频次。故：打开时拉一次历史 + 一次模板，其余全部由交互触发。
 *
 * ⚠ 被 `tests/Unit/PushContractTest.php` 断言钉住（`push.js` 内不得出现任何定时器调用）。
 *   `/actions` 的补查需要**等待**，那里用的是**有界退避**（setTimeout 递归 + 次数上限），
 *   不是周期轮询 —— 两者在语义与失败模式上都不同，不可混为一谈。
 *
 * ---------------------------------------------------------------------
 * 三条「响应里读不出来」的事实，一律由后端下发（前端不得自行编词）
 * ---------------------------------------------------------------------
 * 1. `code=0` 只代表**已入队**（`notes.accepted`）；
 * 2. 同 `msg_id` 被**静默去重**：调用方拿不到任何标志位（`notes.dedup`）；
 * 3. `payload` 超限是在**业务进程**里静默丢弃、调用方只看到 accepted（`notes.payload_drop`）。
 *
 * 这三条是 P3 最容易被误读成 bug 的地方（详见设计文档 §11 的偏差台账），
 * 故不以「小字提示」的形式散落在视图里，而由 `Pusher` 的常量统一产出、经本页注入。
 */
final class PushController
{
    /**
     * 历史列表页大小下拉候选。
     *
     * `public` 是刻意的：`tests/Unit/PushContractTest.php` 直接断言
     * `max(SIZE_OPTIONS) <= PushRepository::SIZE_MAX` —— 用真实常量断言，
     * 而不是正则去匹配源码文本（后者改个排版就失效）。同 `SessionController::SIZE_OPTIONS`。
     */
    public const SIZE_OPTIONS = [20, 50, 100];

    /**
     * 渲染推送页骨架。
     */
    public function index(Request $request): Response
    {
        return view('push/index', [
            'title' => 'GatewayPush 推送管理',
            'config' => [
                // ---- 端点（相对路径，与 config/route.php 一一对应）----
                'create_url' => '/api/push',
                'history_url' => '/api/push/history',
                'templates_url' => '/api/push/templates',
                'template_delete_base' => '/api/push/templates/',

                /* ---- 枚举与标签：一律从 Pusher 取常量，**不在前端硬编码** ----
                 | 前端硬编码枚举的下场是「后端删掉某个 target_type 后，下拉里还在」，
                 | 提交才报 4007；而 target_type 的三值来自主项目 `Push::TARGET_*`，
                 | 是会被主项目改动影响的**外部契约**。 */
                'target_types' => Pusher::TARGET_TYPES,
                'target_labels' => Pusher::TARGET_LABELS,
                'offline_modes' => Pusher::OFFLINE_MODES,
                'offline_labels' => Pusher::OFFLINE_LABELS,

                // ---- 上限：前端预校验与后端同口径 ----
                // msg_id 留空即自动生成，故前端提示的「自动生成长度」与
                // 「手填长度上限」是两个数，都要下发（否则会出现 16 与 64 混用的文案）。
                'msg_id_len' => Pusher::MSG_ID_LEN,
                'msg_id_max_len' => Pusher::MSG_ID_MAX_LEN,
                'payload_max' => $this->payloadMax(),
                'template_name_max_len' => Pusher::TEMPLATE_NAME_MAX_LEN,
                'template_remark_max_len' => Pusher::TEMPLATE_REMARK_MAX_LEN,
                'size_options' => self::sizeOptions(),
                'size_max' => PushRepository::SIZE_MAX,
                'page_size' => $this->pageSize(),
                'statuses' => PushRepository::STATUSES,
                // `target` / `uid` / `device_id` 的长度上限（`SessionInspector::validId()` 的口径）。
                // ⚠ 必须下发：历史筛选里的 `target` 若非法会被 `PushRepository::appliedFilters()`
                //   **静默丢弃** —— 界面会照常显示「查询结果」，但那个条件根本没生效。
                //   前端据此预校验并拒绝提交，是唯一的止损点。
                'id_max_len' => SessionInspector::ID_MAX_LEN,

                /* ---- 说明文案（后端下发，前端**既不编词也不选样式**）----
                 | 结构刻意是「分组 + {key,tone,text}」而不是「扁平 map + 前端选 tone」：
                 | 若让前端按 key 决定用 info 还是 warn，就等于把「哪条严重」这个判断
                 | 复制到了前端 —— 后端把 payload_drop 从 info 调成 warn 时，前端不会跟着变。
                 | 分组（global / history / templates）同理：哪条说明出现在哪一段是**后端决定**的。 */
                'notes' => [
                    'global' => [
                        ['key' => 'no_topic', 'tone' => 'warn', 'text' => Pusher::NO_TOPIC_NOTE],
                        ['key' => 'accepted', 'tone' => 'info', 'text' => Pusher::ACCEPTED_NOTE],
                        ['key' => 'dedup', 'tone' => 'info', 'text' => Pusher::DEDUP_NOTE],
                        ['key' => 'payload_drop', 'tone' => 'warn', 'text' => Pusher::PAYLOAD_DROP_NOTE],
                    ],
                    'history' => [
                        ['key' => 'record', 'tone' => 'info', 'text' => PushRepository::RECORD_NOTE],
                    ],
                    'templates' => [
                        ['key' => 'template', 'tone' => 'info', 'text' => Pusher::TEMPLATE_NOTE],
                    ],
                ],

                /* ---- 权限：**渲染期只决定控件可见性**，不是权限边界 ----
                 | 真正的边界是 config/route.php 上每条路由的 AdminAuth。
                 | 这里求值只为让只读角色打开本页时不出现写控件（降噪 + 防误触）。
                 |
                 | ⚠ 控制器名一律写 `PushApiController::class` 而**不写字面量**：
                 |   写错命名空间不会报任何错 —— `Auth::canAccess()` 只是查不到规则、返回 false，
                 |   表现为「所有人都看不到按钮」，而排查方向会跑偏到「角色配错了」。 */
                'perms' => Perm::map([
                    'can_create' => [PushApiController::class, 'create'],
                    'can_template_save' => [PushApiController::class, 'templateSave'],
                    'can_template_delete' => [PushApiController::class, 'templateDelete'],
                ]),

                'dashboard_url' => $this->cfg('dashboard_url', ''),
            ],
        ]);
    }

    /**
     * `payload` 上限：与 api 侧同一函数（`Pusher::payloadMax`），**只允许调小**。
     *
     * 两侧若各算一遍，会出现「页面提示 4096、提交却被按 1024 拒」这类不一致。
     */
    private function payloadMax(): int
    {
        return Pusher::payloadMax(Settings::int('push.payload_max', 0));
    }

    /**
     * 历史列表默认页大小：与 api 侧同一口径（同读 `push.page_size`、同一夹取区间）。
     */
    private function pageSize(): int
    {
        $configured = Settings::int('push.page_size', PushApiController::HISTORY_PAGE_SIZE);

        return max(PushRepository::SIZE_MIN, min(PushRepository::SIZE_MAX, $configured));
    }

    /**
     * 页大小候选：夹到 `SIZE_MAX` 以内，避免出现「下拉能选、后端却静默夹取」的选项。
     *
     * 同 `SessionController::sizeOptions()`：刻意用 `min()` 而非运行时 `if` 过滤 ——
     * 在字面量常量上比较，PHPStan 会直接判定恒真分支。真正的守门人是契约测试的断言。
     *
     * @return list<int>
     */
    private static function sizeOptions(): array
    {
        return array_values(array_map(
            static fn (int $n): int => min($n, PushRepository::SIZE_MAX),
            self::SIZE_OPTIONS
        ));
    }

    /**
     * 读 gateway_push.* 配置并标量归一。
     */
    private function cfg(string $key, string $default = ''): string
    {
        $value = config('gateway_push.' . $key, $default);

        return is_scalar($value) ? (string)$value : $default;
    }
}
