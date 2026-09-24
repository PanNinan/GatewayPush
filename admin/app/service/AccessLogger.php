<?php

declare(strict_types=1);

namespace app\service;

use support\Db;
use support\Log;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * `wa_admin_log`（**后台用户行为 / 访问日志**）的唯一写入口。
 *
 * ## 与 {@see Auditor}（`admin_audit_log`）的分工
 *
 * | 日志 | 记什么 | 写入方 |
 * |---|---|---|
 * | `wa_admin_log`（本类） | 登录 / 登出 / 打开了哪个页面与接口（含只读） | 全局 {@see \app\middleware\AccessLog} |
 * | `admin_audit_log` | 谁对推送系统做了**写操作**（push / kick / …） | 只允许 {@see Auditor::record} |
 *
 * 同一次踢线两边各一条：访问日志回答「谁在何时点了运维接口」，
 * 业务审计回答「对哪个目标做了什么、结果如何」—— 视角不同，不是重复。
 *
 * ## ⚠ 落库失败**不阻断**请求
 *
 * 与 Auditor 同款：访问留痕发生在业务已处理之后，上抛只会让正常页面 500。
 * 失败时写 error 级日志（含事件摘要），返回 false；中间件不把结果透出给用户
 * （访问日志没有「响应体必须带 audit_ok」的契约 —— 它是旁路）。
 *
 * ## 脱敏
 *
 * query / body 一律经 {@see Auditor::redact()}；密码、token、sign 等命中即 `***`。
 * 登录请求的 username **会记**（可追责），password **绝不记原文**。
 */
final class AccessLogger
{
    public const EVENT_LOGIN = 'login';

    public const EVENT_LOGOUT = 'logout';

    public const EVENT_ACCESS = 'access';

    public const RESULT_OK = 'ok';

    public const RESULT_FAILED = 'failed';

    /** 供访问日志页筛选下拉（与 DB event 列一致，单一真源） */
    public const EVENTS = [
        self::EVENT_LOGIN,
        self::EVENT_LOGOUT,
        self::EVENT_ACCESS,
    ];

    public const RESULTS = [
        self::RESULT_OK,
        self::RESULT_FAILED,
    ];

    /** body 列写入上限（字节）：超长截断，避免一次大 POST 撑爆行 */
    public const BODY_MAX = 4000;

    /**
     * 写入一条访问记录。
     *
     * @param array{
     *     admin_id?: int,
     *     admin_name?: string,
     *     event: string,
     *     result?: string,
     *     method?: string,
     *     path?: string,
     *     controller?: string,
     *     action_name?: string,
     *     query?: string,
     *     body?: string|null,
     *     status?: int,
     *     code?: int,
     *     msg?: string,
     *     cost_ms?: int
     * } $row
     */
    public static function record(array $row, Request $request): bool
    {
        try {
            Db::table('wa_admin_log')->insert([
                'admin_id' => (int)($row['admin_id'] ?? 0),
                'admin_name' => self::clip((string)($row['admin_name'] ?? ''), 64),
                'event' => self::clip((string)($row['event'] ?? self::EVENT_ACCESS), 32),
                'result' => self::clip((string)($row['result'] ?? self::RESULT_OK), 16),
                'method' => self::clip((string)($row['method'] ?? ''), 8),
                'path' => self::clip((string)($row['path'] ?? ''), 191),
                'controller' => self::clip((string)($row['controller'] ?? ''), 191),
                'action_name' => self::clip((string)($row['action_name'] ?? ''), 64),
                'query' => self::clip((string)($row['query'] ?? ''), 500),
                'body' => self::clipBody($row['body'] ?? null),
                'status' => (int)($row['status'] ?? 0),
                'code' => (int)($row['code'] ?? 0),
                'msg' => self::clip((string)($row['msg'] ?? ''), 255),
                'cost_ms' => (int)($row['cost_ms'] ?? 0),
                'ip' => self::clip($request->getRealIp(), 45),
                'user_agent' => self::clip((string)$request->header('user-agent', ''), 255),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (Throwable $e) {
            Log::error('访问日志落库失败（请求已处理，仅留痕缺失）', [
                'event' => $row['event'] ?? '',
                'path' => $row['path'] ?? '',
                'admin_id' => $row['admin_id'] ?? 0,
                'method' => $row['method'] ?? '',
                'status' => $row['status'] ?? 0,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 从响应提取业务码与 msg（仅当 body 是 JSON 信封时；否则 0 / ''）。
     *
     * webman-admin 插件与本项目 ApiReply 都是 `{code, msg, ...}`；
     * 页面 HTML 响应直接返回 0 / 空串。
     *
     * @return array{code: int, msg: string}
     */
    public static function envelope(Response $response): array
    {
        $raw = $response->rawBody();
        if ($raw === '' || $raw[0] !== '{') {
            return ['code' => 0, 'msg' => ''];
        }
        // 只窥前 2KB：完整 decode 大 body 浪费；信封字段总在开头
        $head = strlen($raw) > 2048 ? substr($raw, 0, 2048) : $raw;
        $json = json_decode($head, true);
        if (!is_array($json) || !array_key_exists('code', $json)) {
            // 截断可能导致 json 失败；再试完整（仅当不太大时）
            if (strlen($raw) <= 65536) {
                $json = json_decode($raw, true);
            }
            if (!is_array($json) || !array_key_exists('code', $json)) {
                return ['code' => 0, 'msg' => ''];
            }
        }

        return [
            'code' => (int)($json['code'] ?? 0),
            'msg' => self::clip((string)($json['msg'] ?? ''), 255),
        ];
    }

    /**
     * 查询串脱敏：parse → redact → 重新拼接（保持可读、可筛）。
     *
     * @param array<string, mixed> $query
     */
    public static function redactQuery(array $query): string
    {
        if ($query === []) {
            return '';
        }
        $safe = Auditor::redact($query);

        return http_build_query($safe);
    }

    /**
     * 请求体脱敏快照：JSON 优先，否则 http_build_query 形态再 redact。
     *
     * @return string|null null = 无体 / 不可解析（不记原文，避免把未知格式塞进日志）
     */
    public static function redactBody(?string $raw, string $contentType): ?string
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return null;
        }

        $first = $raw[0];
        $isJson = stripos($contentType, 'application/json') !== false
            || $first === '{'
            || $first === '[';
        if ($isJson) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $safe = Auditor::redact($decoded);
                $out = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                return is_string($out) ? $out : null;
            }
            // 非法 JSON：不落原文（可能是截断二进制）
            return null;
        }

        $pairs = [];
        parse_str($raw, $pairs);
        if ($pairs === []) {
            return null;
        }
        $safe = Auditor::redact($pairs);

        return http_build_query($safe);
    }

    private static function clipBody(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        return self::clip($body, self::BODY_MAX);
    }

    private static function clip(string $value, int $max): string
    {
        return strlen($value) <= $max ? $value : substr($value, 0, $max);
    }
}
