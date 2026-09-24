<?php

declare(strict_types=1);

namespace app\middleware;

use app\service\AccessLogger;
use app\service\Auditor;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 全局访问日志中间件 —— 写 `wa_admin_log`（后台用户行为）。
 *
 * ## 为什么挂全局而不是只挂 AdminAuth
 *
 * `config/middleware.php` 的全局中间件对**根应用路由与 webman-admin 插件路由
 * （`/app/admin/*`）都生效**（`Webman\Middleware::getMiddleware` 合并
 * `instances['']['@']`）。只挂 AdminAuth 会漏掉：
 * - 登录 / 登出 / 验证码（插件 `AccountController`）
 * - 账号 / 角色 / 权限 / 字典等 vendor 管理页
 *
 * ## 过滤（否则日志被轮询刷爆）
 *
 * 不记：
 * - 静态资源（`/static/*` 或常见扩展名）
 * - 验证码图（路径含 captcha）
 * - OPTIONS 预检
 * - **已知高频轮询 GET**（见 {@see SKIP_GET_PATHS}）—— 它们是看板心跳，
 *   不是「用户做了什么」；真要排查连通性去看主项目 / 本项目 runtime 日志。
 *
 * 必记：
 * - 全部非 GET（写操作）
 * - 页面 GET（HTML）
 * - 其余 API GET
 * - 登录成功/失败、登出（即使 path 命中其它规则也优先记）
 *
 * ## 与业务审计的关系
 *
 * 本中间件记「谁发起了 POST /api/ops-action/kick」；
 * {@see Auditor} 记「kick 的目标与结果」。两边都有才构成完整证据链。
 *
 * ## 失败语义
 *
 * 落库异常一律吞掉并由 AccessLogger 打 error —— **绝不能让访问日志把页面搞 500**。
 */
final class AccessLog implements MiddlewareInterface
{
    /**
     * 高频轮询 GET 路径（精确匹配 path，不含查询串）。
     *
     * 命中且 method=GET 时跳过；**非 GET 仍会记**（防有人用 POST 打这些路径做写操作）。
     */
    public const SKIP_GET_PATHS = [
        '/api/monitor/live',
        '/api/monitor/summary',
        '/api/metrics/range',
        '/api/metrics/latest',
    ];

    /** 路径前缀：一律跳过（含本项目静态目录） */
    public const SKIP_PREFIXES = [
        '/static/',
    ];

    /** 路径后缀（不区分大小写）：静态资源 */
    public const SKIP_SUFFIX_REGEX = '#\.(js|css|png|jpe?g|gif|svg|ico|woff2?|ttf|eot|map|webp|bmp|mp4|webm)$#i';

    public function process(Request $request, callable $handler): Response
    {
        // 身份必须在 handler **之前**取：登出会在 handler 里清 session
        $identity = $this->identity();
        $method = strtoupper($request->method());
        $path = '/' . ltrim($request->path(), '/');
        $query = $request->get();
        $isAuthEvent = $this->isLoginPath($path) || $this->isLogoutPath($path);

        if (!$isAuthEvent && $this->shouldSkip($method, $path)) {
            return $handler($request);
        }

        $start = microtime(true);
        try {
            $response = $handler($request);
        } catch (Throwable $e) {
            // 异常也记一条（HTTP 500），再原样抛 —— 访问失败本身是行为
            $this->write($request, [
                'admin_id' => $identity['admin_id'],
                'admin_name' => $identity['admin_name'],
                'event' => $this->eventOf($path, $method),
                'result' => AccessLogger::RESULT_FAILED,
                'method' => $method,
                'path' => $path,
                'controller' => is_string($request->controller) ? $request->controller : '',
                'action_name' => is_string($request->action) ? $request->action : '',
                'query' => AccessLogger::redactQuery(is_array($query) ? $query : []),
                'body' => null,
                'status' => 500,
                'code' => 0,
                'msg' => $this->clip($e->getMessage(), 255),
                'cost_ms' => (int)round((microtime(true) - $start) * 1000),
            ]);
            throw $e;
        }

        $costMs = (int)round((microtime(true) - $start) * 1000);
        $status = $response->getStatusCode();
        $envelope = AccessLogger::envelope($response);

        // 登录成功后 session 已写入 —— handler 后再取一次身份（失败则保持 handler 前的 0）
        if ($this->isLoginPath($path) && $envelope['code'] === 0 && $status < 400) {
            $identity = $this->identity();
        }

        $result = $this->resultOf($path, $method, $status, $envelope['code']);
        $body = null;
        if ($method !== 'GET' && $method !== 'HEAD') {
            $body = AccessLogger::redactBody(
                (string)$request->rawBody(),
                (string)$request->header('content-type', '')
            );
        }

        $this->write($request, [
            'admin_id' => $identity['admin_id'],
            'admin_name' => $identity['admin_name'],
            'event' => $this->eventOf($path, $method),
            'result' => $result,
            'method' => $method,
            'path' => $path,
            'controller' => is_string($request->controller) ? $request->controller : '',
            'action_name' => is_string($request->action) ? $request->action : '',
            'query' => AccessLogger::redactQuery(is_array($query) ? $query : []),
            'body' => $body,
            'status' => $status,
            'code' => $envelope['code'],
            'msg' => $this->loginMsg($path, $result, $envelope['msg'], $status),
            'cost_ms' => $costMs,
        ]);

        return $response;
    }

    /**
     * 是否应跳过（认证事件已在调用方短路，此处不处理 login/logout）。
     */
    public function shouldSkip(string $method, string $path): bool
    {
        if ($method === 'OPTIONS') {
            return true;
        }
        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        if (preg_match(self::SKIP_SUFFIX_REGEX, $path) === 1) {
            return true;
        }
        if (stripos($path, 'captcha') !== false) {
            return true;
        }
        if ($method === 'GET' && in_array($path, self::SKIP_GET_PATHS, true)) {
            return true;
        }

        return false;
    }

    /** login | logout | access */
    public function eventOf(string $path, string $method): string
    {
        unset($method);
        if ($this->isLoginPath($path)) {
            return AccessLogger::EVENT_LOGIN;
        }
        if ($this->isLogoutPath($path)) {
            return AccessLogger::EVENT_LOGOUT;
        }

        return AccessLogger::EVENT_ACCESS;
    }

    /**
     * 结果判定：
     * - 登录：业务码 0 且 HTTP <400 → ok（与插件 `{code:1}` 失败语义对齐）
     * - 其它：HTTP >=400 或业务码 !=0 → failed
     */
    public function resultOf(string $path, string $method, int $status, int $code): string
    {
        unset($path, $method);
        if ($status >= 400 || $code !== 0) {
            return AccessLogger::RESULT_FAILED;
        }

        return AccessLogger::RESULT_OK;
    }

    /**
     * @param array{
     *     admin_id: int,
     *     admin_name: string,
     *     event: string,
     *     result: string,
     *     method: string,
     *     path: string,
     *     controller: string,
     *     action_name: string,
     *     query: string,
     *     body: string|null,
     *     status: int,
     *     code: int,
     *     msg: string,
     *     cost_ms: int
     * } $row
     */
    private function write(Request $request, array $row): void
    {
        try {
            AccessLogger::record($row, $request);
        } catch (Throwable $e) {
            // AccessLogger 内部已捕获；这里兜一层防未来改动漏抛
        }
    }

    /**
     * @return array{admin_id: int, admin_name: string}
     */
    private function identity(): array
    {
        // 复用 Auditor 的 session 读取（插件助手 + function_exists 守卫）
        return Auditor::identity();
    }

    private function isLoginPath(string $path): bool
    {
        return str_ends_with($path, '/app/admin/account/login')
            || str_ends_with($path, '/account/login');
    }

    private function isLogoutPath(string $path): bool
    {
        return str_ends_with($path, '/app/admin/account/logout')
            || str_ends_with($path, '/account/logout');
    }

    private function loginMsg(string $path, string $result, string $envelopeMsg, int $status): string
    {
        if ($this->isLoginPath($path) || $this->isLogoutPath($path)) {
            if ($envelopeMsg !== '') {
                return $envelopeMsg;
            }

            return $result === AccessLogger::RESULT_OK ? 'ok' : 'HTTP ' . $status;
        }

        return $envelopeMsg;
    }

    private function clip(string $value, int $max): string
    {
        return strlen($value) <= $max ? $value : substr($value, 0, $max);
    }
}
