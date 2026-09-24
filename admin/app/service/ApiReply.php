<?php
/**
 * admin · 服务层 —— ApiReply。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace app\service;

use support\Response;

/**
 * 后台自有 JSON API 的统一响应构造器。
 *
 * 存在的唯一理由：**webman 的 `json()` 助手把 HTTP 状态码硬编码为 200**
 * （`vendor/workerman/webman-framework/src/support/helpers.php:183-186`，签名里没有 status 参数）。
 * 直接用它会得到「HTTP 200 + 业务码 4xx」，即用 200 掩盖失败 —— 前端 fetch 无法按状态码
 * 统一拦截登录失效，反代 / 探针也识别不出失败。这条已在设计文档 **D15** 记录过一次
 * （`AdminAuth::deny()` 因此改为自建 Response）。本类是同一纪律的复用点。
 *
 * 信封与主项目一致（`{code, msg, data}`），便于前端复用同一套解析逻辑；
 * 但**状态码语义更严格**：
 *   - `ok()`        → HTTP 200 + code 0
 *   - `fail()`      → HTTP 4xx/5xx + 对应业务码（两者必须同时给出）
 */
final class ApiReply
{
    /** 参数非法（形态/范围不合法）——与主项目「缺参 4007」同码，便于调用方统一处理 */
    public const CODE_INVALID_ARG = 4007;

    /** 目标不存在（如 clientId 对应的会话已被回收） */
    public const CODE_NOT_FOUND = 4004;

    /**
     * 后台自有 MySQL 不可用（读历史 / 模板，或写受理记录 / 审计）。
     *
     * 值域说明：主项目的 API 错误码占 `0 / 4000~4008 / 4029 / 5000 / 5030`
     * （`src/Api/Bootstrap.php:81-95`），故后台自有码一律取 **5010 / 5020** 这类未被占用的值，
     * 避免与主项目码混淆 —— 两套码在界面上都可见，同值不同义是最难排查的一类问题。
     */
    public const CODE_DB_UNAVAILABLE = 5010;

    /**
     * **上游主项目未受理**（连不上，或返回了非 `code=0`）。
     *
     * `data` 里会带完整的三元组（`http` / `code` / `msg` / `state`）供排查。
     * 用 502 而非透传主项目的状态码：透传会让「后台参数校验通过、主项目仍拒绝」
     * 看起来像**用户填错了**，而那实际是两侧契约漂移（该由开发处理，不该让用户反复改表单）。
     */
    public const CODE_UPSTREAM = 5020;

    /**
     * 成功响应。
     *
     * @param array<string, mixed> $data
     */
    public static function ok(array $data = []): Response
    {
        return json(['code' => 0, 'msg' => 'ok', 'data' => $data]);
    }

    /**
     * 失败响应：**HTTP 状态码与业务码成对给出**。
     *
     * @param int                  $httpStatus 真实 HTTP 状态码（400 / 404 / 403 / 500 …）
     * @param int                  $code       业务码（本类 `CODE_*` 或主项目错误码）
     * @param string               $msg        面向人的说明（可直接展示）
     * @param array<string, mixed> $data       附加数据（如部分结果）
     */
    public static function fail(int $httpStatus, int $code, string $msg, array $data = []): Response
    {
        return response(
            (string)json_encode(
                ['code' => $code, 'msg' => $msg, 'data' => $data],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
            $httpStatus,
            ['Content-Type' => 'application/json']
        );
    }
}
