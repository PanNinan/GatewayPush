<?php

declare(strict_types=1);

namespace app\middleware;

use plugin\admin\api\Auth;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 后台自有路由的鉴权中间件 —— **复用 webman-admin 的 RBAC，不另建一套**。
 *
 * 为什么需要它：webman-admin 的 `AccessControl` 中间件只挂在插件作用域
 * （`plugin/admin/config/middleware.php` 的 `'' => [AccessControl::class]`），
 * 只覆盖 `/app/admin/*`。本项目的控制器在根应用（`app/controller/**`），
 * 不在插件作用域内，因此必须显式挂载。
 *
 * 权限模型（真源 `plugin/admin/api/Auth.php::canAccess`，与设计文档的语义键方案不同）：
 * - `wa_rules.key` = `{控制器全类名}` 或 `{控制器全类名}@{action}`
 * - `wa_roles.rules` = 逗号分隔的 rule id 列表，`'*'` 表示全权
 * - 所以路由定义**必须**用 `[Controller::class, 'method']` 形式，
 *   这样 `$request->controller` 才是控制器全类名，能与 `wa_rules.key` 对上；
 *   若改用字符串路由或闭包，`$request->controller` 为空 → 鉴权会被跳过（见下方 fail-close）。
 */
final class AdminAuth implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $controller = $request->controller;
        $action = $request->action;

        // ⚠ fail-close：`Auth::canAccess()` 对空 controller 是**直接放行**
        //   （源码注释：「函数调用不属于任何控制器，鉴权在函数内部完成」）。
        //   对后台管理面而言这等于绕鉴权，故此处先拦掉。
        if (!is_string($controller) || $controller === '') {
            return $this->deny($request, 403, '无法确定目标控制器，已拒绝请求');
        }

        $code = 0;
        $msg = '';
        try {
            $allowed = Auth::canAccess($controller, is_string($action) ? $action : '', $code, $msg);
        } catch (Throwable $e) {
            // 控制器类不存在（拼错类名 / 自动加载失败）会在这里暴露，而不是静默放行。
            return $this->deny($request, 403, '鉴权过程异常：' . $e->getMessage());
        }

        if (!$allowed) {
            return $this->deny($request, $code > 0 ? $code : 403, $msg);
        }

        return $handler($request);
    }

    /**
     * 拒绝响应。**HTTP 状态码与业务码同时给出**，两者都有消费方：
     * - `code` 字段：与 webman-admin 前端约定一致（`{code,msg,data}`）；
     * - HTTP 状态码：供 fetch / 反代 / 探针按标准语义识别 401 与 403。
     *
     * 分流规则：
     * - JSON 请求（`Accept: application/json` 或 XHR）：401|403 + JSON 体；
     * - 页面请求：未登录（401）302 到 webman-admin 登录页，已登录无权限（403）返回文本。
     */
    private function deny(Request $request, int $code, string $msg): Response
    {
        $msg = $msg === '' ? '无权限' : $msg;
        $status = $code === 401 ? 401 : 403;

        if ($request->expectsJson()) {
            // ⚠ 不能用 json() 助手：它把 HTTP 状态码硬编码为 200
            //   （vendor/workerman/webman-framework/src/support/helpers.php:183-186）。
            //   返回 HTTP 200 + code=401 会让前端 fetch 无法按状态码统一处理登录失效，
            //   属「用 200 掩盖失败」，与本项目主项目的 HTTP 错误分层原则相悖。
            return response(
                (string)json_encode(
                    ['code' => $code, 'msg' => $msg, 'data' => []],
                    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                ),
                $status,
                ['Content-Type' => 'application/json']
            );
        }

        if ($code === 401) {
            return redirect('/app/admin');
        }

        return response($msg, $status);
    }
}
