<?php

declare(strict_types=1);

namespace app\service;

use Throwable;

/**
 * 权限求值 —— 供**页面控制器**在渲染时决定「哪些写控件可见」。
 *
 * ## 定位：这不是权限边界
 *
 * 真正的权限边界是 `app\middleware\AdminAuth`（挂在 `config/route.php` 的每条路由上，
 * 逐请求调用 `plugin\admin\api\Auth::canAccess`）。本类只在**页面渲染那一刻**额外问一遍，
 * 目的单一：让只有读权限的角色打开 `/push` 时，页面上不出现「发起推送」表单与
 * 「模板新建/编辑/删除」按钮 —— **降噪与防误触**，不是安全措施。
 *
 * ⚠ 因此：**前端隐藏了控件，后端仍必须独立校验**。反过来，本类若因任何原因返回 `false`，
 * 后果只是「有权限的人看不到按钮」（他仍可直接调 API，或改角色后刷新），
 * 不会造成越权。这是本类刻意选择的失败方向，见 {@see can()} 的 catch 分支。
 *
 * ## 为什么不用「把规则列表下发到前端」
 *
 * webman-admin 的做法是把 `wa_rules` 全量下发给 layui 前端自行判断。本项目**刻意不采用**：
 * 页面的权限判断一旦分散到 JS，就会出现「后端收紧了、前端还显示」与
 * 「前端 JS 被改一行就多出按钮」两类漂移，且都无法被 PHPUnit 断言。
 * 这里把判定收在 PHP 侧、结果以布尔量注入 `#push-config` / `#action-config`，
 * 前端只消费 `cfg.perms.can_create` 这类**既定事实**，不再自行推导。
 */
final class Perm
{
    /**
     * 插件鉴权类的 FQCN（**故意写成字符串**）。
     *
     * 理由：`plugin/` 是 composer 的 `"": "./"` psr-4 映射下的第三方插件代码，
     * 本项目不改它、也不保证它在所有上下文（如 PHPUnit CLI）下都被加载。
     * 用字符串 + 守卫可以避免「类不存在」直接致命错误，且让依赖关系在代码里显式可见。
     */
    private const AUTH_CLASS = 'plugin\\admin\\api\\Auth';

    /**
     * 批量求值。
     *
     * @param array<string, array{0: string, 1: string}> $specs alias => [控制器全类名, 动作名]
     *
     * @return array<string, bool> alias => 是否有权
     */
    public static function map(array $specs): array
    {
        $out = [];
        foreach ($specs as $alias => $spec) {
            $out[$alias] = self::can($spec[0], $spec[1]);
        }

        return $out;
    }

    /**
     * 单点求值：`{控制器全类名}@{动作}` 是否在当前登录者的角色规则内。
     *
     * 判定完全委托给插件的 `Auth::canAccess()`（与中间件同一函数），
     * **不在此处复刻任何规则匹配逻辑** —— 复刻一份就意味着两处会各自漂移，
     * 而漂移的表现是「按钮显示出来了但点击 403」这种最难排查的形态。
     *
     * @param string $controller 控制器全类名（如 `app\controller\api\PushController`）
     * @param string $action     动作名（如 `create`）；`index` 有特殊的「前缀匹配」语义
     *
     * @return bool 任何异常 / 依赖缺失一律返回 `false`（收紧方向）
     */
    public static function can(string $controller, string $action): bool
    {
        // 控制器类不存在时 `canAccess` 内部的 `new \ReflectionClass()` 会抛 ReflectionException；
        // 插件未加载时静态调用会致命错误。两者都在这里挡掉。
        if (!class_exists(self::AUTH_CLASS) || !class_exists($controller)) {
            return false;
        }

        try {
            return (bool)\plugin\admin\api\Auth::canAccess($controller, $action);
        } catch (Throwable) {
            // 判定失败一律**收紧**：宁可让运维看不到按钮（他可在角色管理里自行核对，
            // 或直接调 API），也不要因一次 DB 抖动把写控件放给只读角色。
            // ⚠ 注意本方法的返回值**不构成安全边界**（见类注释），故这里收紧不会「挡住」任何真实操作。
            return false;
        }
    }
}
