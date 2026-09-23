<?php

declare(strict_types=1);

namespace app\service;

use support\Db;
use support\Log;
use support\Request;
use Throwable;

/**
 * `admin_audit_log`（**业务语义审计**）的唯一写入口。
 *
 * ## 与 webman-admin 自带日志的分工
 *
 * | 日志 | 记什么 | 谁写 |
 * |---|---|---|
 * | webman-admin 自带的 `wa_*` 日志 | 「谁登录了、访问了哪些页面」= **访问行为** | 插件自动 |
 * | `admin_audit_log`（本类） | 「谁对推送系统做了什么」= **业务语义** | 只允许经本类 |
 *
 * 两者都要保留：只有访问日志时，「某人点过推送页」与「某人真的发了一条推送」无法区分。
 *
 * ## ⚠ 审计失败**不阻断**请求，但必须可见
 *
 * 这是本类唯一一处刻意违反「写操作失败要上抛」的地方，理由是**对称性**：
 * 审计写入发生在**业务动作已经生效之后**（`/push` 已入队、模板已落库）。
 * 若此时上抛，调用方会看到失败并**重试** —— 于是同一条推送被发两次、
 * 模板被建两份，而且**第二次很可能写入成功**，最终库里躺着一份「失败的记录 + 两份真实效果」。
 * 用一次审计缺失换一次重复投递，是明确的坏交易。
 *
 * 因此策略是：
 *   1. `record()` 返回 `bool`（`false` = 落库失败），**不抛**；
 *   2. 失败时以 `error` 级写 `runtime/logs/webman.log`（含完整事件，可人工补录）；
 *   3. 调用方**必须把该布尔透出到响应里**（如 `audit_ok: false`），
 *      让操作者当下就知道「动作生效了、留痕没成」，而不是事后在日志里翻。
 *      这条纪律由 `tests/Unit/PushContractTest.php` 的源码断言守住：
 *      凡调用 `Auditor::record()` 的控制器，响应里必须出现 `audit_ok`。
 */
final class Auditor
{
    /**
     * 已登记的业务动作名（对应 `admin_audit_log.action`）。
     *
     * 刻意用常量集合而不是随手写的字符串：`action` 是审计检索的主要维度
     * （`idx_action_time`），一旦出现 `push.create` / `push_create` 两种写法，
     * 按动作筛选就会**静默漏记录**。
     */
    public const ACTION_PUSH_CREATE = 'push.create';

    public const ACTION_TEMPLATE_SAVE = 'push.template.save';

    public const ACTION_TEMPLATE_DELETE = 'push.template.delete';

    /** P3 新增：动作调试器的调用（**只读语义的动作也会留痕** —— 调用动作是写行为） */
    public const ACTION_ACTION_INVOKE = 'action.invoke';

    /* -----------------------------------------------------------------
     | P4 新增：运维动作
     |
     | 这一组与上面那些有个本质区别：它们**改变别人的连接状态**，
     | 是后台第一个「写主项目」的动作族。审计对它们不是「留个记录」，
     | 而是**唯一可追溯的依据**（踢线不可撤销、无法回滚）。
     ----------------------------------------------------------------- */

    /** 踢线（断开连接，Token 仍有效） */
    public const ACTION_OPS_KICK = 'ops.kick';

    /** 撤销 Token */
    public const ACTION_OPS_REVOKE = 'ops.revoke';

    /** 解绑设备 */
    public const ACTION_OPS_UNBIND = 'ops.unbind';

    /** 「踢下线且禁止重连」组合（revoke → kick） */
    public const ACTION_OPS_FORCE_OFFLINE = 'ops.force_offline';

    /** 清空离线队列（不可恢复） */
    public const ACTION_OPS_PURGE_OFFLINE = 'ops.purge_offline';

    /** 全部已知动作名（供审计页的筛选下拉与测试双向比对） */
    public const ACTIONS = [
        self::ACTION_PUSH_CREATE,
        self::ACTION_TEMPLATE_SAVE,
        self::ACTION_TEMPLATE_DELETE,
        self::ACTION_ACTION_INVOKE,
        self::ACTION_OPS_KICK,
        self::ACTION_OPS_REVOKE,
        self::ACTION_OPS_UNBIND,
        self::ACTION_OPS_FORCE_OFFLINE,
        self::ACTION_OPS_PURGE_OFFLINE,
    ];

    /** 结果枚举：与 `admin_audit_log.result` 的 `ok|failed` 一致 */
    public const RESULT_OK = 'ok';

    public const RESULT_FAILED = 'failed';

    /**
     * 密钥类字段名（大小写不敏感，**子串**匹配）—— 命中即整体替换为 {@see MASK}。
     *
     * 用子串而非全等是有意的：`api_secret` / `apiSecret` / `x_api_secret` / `secret_key`
     * 都应命中，而精确名单永远列不全。误伤（如把 `keyboard` 也打码）只损失可读性，
     * 漏掉一个真密钥则是安全事故 —— 两者不对称，故宁枉勿纵。
     */
    public const REDACT_PATTERN = '/(pass|pwd|secret|token|sign|key|authorization|credential|cookie)/i';

    /** 脱敏后的占位符（与设计文档 §5.3.2「密钥类字段已脱敏/省略」一致） */
    public const MASK = '***';

    /**
     * 写入一条审计记录。
     *
     * @param array{
     *     action: string,
     *     target_type?: string,
     *     target?: string,
     *     params?: array<string, mixed>|null,
     *     result?: string,
     *     code?: int,
     *     msg?: string
     * } $event
     *
     * @return bool `false` = 审计落库失败（**调用方必须把它透出到响应里**，见类注释）
     */
    public static function record(array $event, Request $request): bool
    {
        $identity = self::identity();
        $params = $event['params'] ?? null;

        try {
            Db::table('admin_audit_log')->insert([
                'admin_id' => $identity['admin_id'],
                'admin_name' => self::clip($identity['admin_name'], 64),
                'action' => self::clip((string)($event['action'] ?? ''), 64),
                'target_type' => self::clip((string)($event['target_type'] ?? ''), 16),
                'target' => self::clip((string)($event['target'] ?? ''), 191),
                // `params` 列可空；`null` 与 `'null'` 不同 —— 前者是「本次没有参数」，
                // 后者会让读的人以为「传了一个 null」。故无参时写 SQL NULL。
                'params' => $params === null
                    ? null
                    : (string)json_encode(self::redact($params), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'result' => self::clip((string)($event['result'] ?? self::RESULT_OK), 16),
                'code' => (int)($event['code'] ?? 0),
                'msg' => self::clip((string)($event['msg'] ?? ''), 255),
                'ip' => self::clip($request->getRealIp(), 45),
                'user_agent' => self::clip((string)$request->header('user-agent', ''), 255),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (Throwable $e) {
            // 不抛（见类注释）。**必须写 error 级**并带上完整事件 ——
            // 这是审计缺失后唯一的人工补录依据。
            Log::error('审计落库失败（业务动作已生效，仅留痕缺失）', [
                'action' => $event['action'] ?? '',
                'target' => $event['target'] ?? '',
                'admin_id' => $identity['admin_id'],
                'admin_name' => $identity['admin_name'],
                'params' => self::redact(is_array($params) ? $params : []),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 递归脱敏（**纯函数**，可单测）。
     *
     * 递归而非只扫顶层：`params` 的形态是自由 JSON，
     * `{"payload":{"token":"..."}}` 这类嵌套是常态。
     *
     * 深度上限 8：防自引用结构造成的无限递归。超深的分支整体替换为掩码
     * （丢弃而非保留 —— 与「宁枉勿纵」同一方向）。
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    public static function redact(array $data, int $depth = 0): array
    {
        if ($depth >= 8) {
            return [self::MASK];
        }

        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::REDACT_PATTERN, $key) === 1) {
                $out[$key] = self::MASK;

                continue;
            }

            if (is_array($value)) {
                $out[$key] = self::redact($value, $depth + 1);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * 当前管理员身份（来自 webman-admin 的 session）。
     *
     * 用 `admin_id()` / `admin()` 这两个插件自带助手而不是自己读 session：
     * 密钥是插件定义的（`session('admin.id')`），自己解析会在插件升级后静默失配
     * （`admin_id` 变成 0，审计记录全部变成「匿名操作」—— 一个不会报错的退化）。
     *
     * ⚠ 这两个函数由**插件**在启动时定义，在纯 CLI 脚本里可能不存在
     * （`tests/Manual/*.php` 会引导框架，但引导到哪一步取决于脚本）。
     * 故用 `function_exists()` 守卫而不是无条件调用 —— 后者在那种场景下是**致命错误**，
     * 会让整个审计入口消失（审计是「不阻断业务」的，绝不能因为自己挂了而带走请求）。
     * 退回 `admin_id = 0` 会在审计页显示成「系统 / 匿名」，可识别、可追查。
     *
     * @return array{admin_id: int, admin_name: string}
     */
    public static function identity(): array
    {
        $id = 0;
        $name = '';

        try {
            if (function_exists('admin_id')) {
                $id = (int)(admin_id() ?? 0);
            }
            if (function_exists('admin')) {
                $value = admin('username');
                $name = is_string($value) ? $value : '';
            }
        } catch (Throwable) {
            // `admin()` 会刷新 session（可能触达存储）；失败不应影响审计主体信息，
            // 因为「谁操作的」这件事在 session 里已经有了，刷新只是延长有效期。
        }

        return ['admin_id' => $id, 'admin_name' => $name];
    }

    /** 截断到列宽（MySQL 严格模式下超长会直接报 1406，宁可截断也不要丢整条记录）。 */
    private static function clip(string $value, int $max): string
    {
        return strlen($value) <= $max ? $value : substr($value, 0, $max);
    }
}
