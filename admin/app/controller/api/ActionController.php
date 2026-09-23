<?php

declare(strict_types=1);

namespace app\controller\api;

use app\service\ActionCatalog;
use app\service\ActionOutcome;
use app\service\ApiReply;
use app\service\Auditor;
use app\service\GatewayPushClient;
use app\service\Pusher;
use app\service\SessionInspector;
use support\Request;
use support\Response;

/**
 * 动作调试 API（`/action` 与 `GET /action/{id}` 的转签代理）。
 *
 * 权限点（`wa_rules.key`）：
 * - `app\controller\api\ActionController@invoke`  调用一个 HTTP 已开放的动作（**写**）
 * - `app\controller\api\ActionController@result`  补查动作回执（**纯读**）
 *
 * ## 为什么由后台代理，而不是让浏览器直连主项目
 *
 * 三个都不可省的理由：
 * 1. **密钥不下发**：调用 `/action` 需要 `API_SECRET` 参与 HMAC。让浏览器直连就得把密钥
 *    交给前端（或做一次裸转发），两者都等于把管理面凭证交给拿到页面的人；
 * 2. **留痕**：调用动作是**写行为**（`report` 会改计数、`notify` 会推消息），必须落审计；
 * 3. **错误分层收口**：主项目的四种响应形态（见 {@see ActionOutcome}）在浏览器里
 *    极容易被误读成成败，归一必须发生在服务端。
 *
 * ## 本控制器只开放「HTTP 已开放」的动作
 *
 * 白名单取自 {@see ActionCatalog}（与主项目 `config/actions.php` 的 `'http' => true`
 * 双向对齐，由 `tests/Unit/ActionCatalogTest.php` 守住）。**`session` 刻意不在列** ——
 * 它的语义锚点是「当前连接」，HTTP 通道下无连接实体，调用必然 `4006`。
 */
final class ActionController
{
    /**
     * 调用一个业务动作（**同步等待**，服务端最长阻塞 `ActionCatalog::WAIT_MS_MIRROR` ms）。
     *
     * 请求体：`action`（必填）/ `uid` / `device_id` / `params`（对象或 JSON 字符串）。
     *
     * 响应语义（**必须按 `data.state` 读，不能按 HTTP 状态码读**）：
     * | `state` | HTTP | 含义 | 前端该做什么 |
     * |---|---|---|---|
     * | `done` | 200 | 执行完成，`result` 有效 | 展示结果 |
     * | `failed` | 200 | 进了队列但执行失败（业务码在 `code`） | 展示原因；**HTTP 是 200，不是成功** |
     * | `pending` | 200 | 超窗未完成，**不是失败** | 凭 `request_id` 退避补查 |
     * | `rejected` / `transport` | 502 | 未入队（含连不上主项目） | 按 `resend` / `resend_note` 判重发是否安全 |
     *
     * ⚠ 判「能不能重发」必须看 `data.resend`（`safe` / `unsafe` / `unknown`），
     * **不要**看 `data.retryable` —— 后者回答的是「补查能不能重试」，
     * 两者在 `rejected` 上正好相反（`retryable=false` 但重发安全）。
     * 详见 {@see ActionOutcome} 的类注释对照表。
     *
     * ⚠ `202` 在后台这一层被统一收敛成 `HTTP 200` + `state=pending`：
     * 后台自身的 API 不存在「部分成功」这个 HTTP 语义，用它反而会让前端的
     * 统一错误处理把「还没算完」当故障。
     */
    public function invoke(Request $request): Response
    {
        $input = $request->post();

        $action = ActionCatalog::normalize($input['action'] ?? null);
        if ($action === '') {
            return ApiReply::fail(
                400,
                ApiReply::CODE_INVALID_ARG,
                'action 非法。HTTP 已开放的动作只有：' . implode(' / ', ActionCatalog::names())
                . '（' . ActionCatalog::SCOPE_NOTE . '）',
                ['allowed' => ActionCatalog::names()]
            );
        }

        $uid = Pusher::normalizeTarget($input['uid'] ?? null);
        $deviceId = Pusher::normalizeTarget($input['device_id'] ?? null);

        if (ActionCatalog::requiresUid($action) && $uid === '') {
            return ApiReply::fail(
                400,
                ApiReply::CODE_INVALID_ARG,
                '动作 ' . $action . ' 要求 uid。' . ActionCatalog::UID_REQUIRED_NOTE,
                ['requires_uid' => true]
            );
        }
        foreach (['uid' => $uid, 'device_id' => $deviceId] as $field => $value) {
            if ($value !== '' && !SessionInspector::validId($value)) {
                return ApiReply::fail(
                    400,
                    ApiReply::CODE_INVALID_ARG,
                    $field . ' 非法：长度超过 ' . SessionInspector::ID_MAX_LEN . ' 字节或含控制字符'
                );
            }
        }

        // `params` 与推送的 payload 同口径：接受对象与 JSON 字符串两种形态
        // 注：`?? []` 已把「键缺失」与「值为 null」两种情况一并消掉，故此处只需再处理空串。
        $paramsRaw = $input['params'] ?? [];
        if ($paramsRaw === '') {
            $paramsRaw = [];
        }
        if (is_string($paramsRaw)) {
            try {
                $paramsRaw = json_decode($paramsRaw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return ApiReply::fail(
                    400,
                    ApiReply::CODE_INVALID_ARG,
                    'params 不是合法 JSON：' . $e->getMessage()
                );
            }
        }
        if (!is_array($paramsRaw)) {
            return ApiReply::fail(
                400,
                ApiReply::CODE_INVALID_ARG,
                'params 必须是 JSON 对象（当前是 ' . get_debug_type($paramsRaw) . '）'
            );
        }

        /** @var array<string, mixed> $params */
        $params = $paramsRaw;

        $job = ['action' => $action, 'params' => $params];
        // 空值不塞进请求体：主项目对 `uid` 的判据是 `trim() === ''`，传空串与不传等价，
        // 但少一个键能让抓到的原始请求体更接近文档示例（`docs/GatewayPush 对外接口文档.md` §4.2）。
        if ($uid !== '') {
            $job['uid'] = $uid;
        }
        if ($deviceId !== '') {
            $job['device_id'] = $deviceId;
        }

        $res = GatewayPushClient::fromConfig()->action($job);
        $outcome = ActionOutcome::of($res);

        $auditOk = Auditor::record([
            'action' => Auditor::ACTION_ACTION_INVOKE,
            'target_type' => $uid !== '' ? 'uid' : '',
            'target' => $uid,
            // `params` 会被 Auditor::redact() 递归脱敏（密钥类字段名命中即整体打码）
            'params' => [
                'action' => $action,
                'uid' => $uid,
                'device_id' => $deviceId,
                'params' => $params,
                'outcome_state' => $outcome['state'],
            ],
            'result' => ActionOutcome::isSuccess($outcome) ? Auditor::RESULT_OK : Auditor::RESULT_FAILED,
            'code' => $outcome['code'],
            'msg' => $outcome['msg'],
        ], $request);

        $body = $outcome + [
            'label' => ActionOutcome::label($outcome['state']),
            'tone' => ActionOutcome::tone($outcome['state']),
            'action' => $action,
            'params_hint' => ActionCatalog::ACTIONS[$action]['params_hint'],
            'audit_ok' => $auditOk,
            'wait_ms' => ActionCatalog::WAIT_MS_MIRROR,
            'result_ttl' => ActionCatalog::RESULT_TTL_MIRROR,
            'notes' => [
                ActionCatalog::UID_SEMANTICS_NOTE,
                ActionCatalog::PENDING_NOTE,
                ActionCatalog::RESULT_TTL_NOTE,
            ],
        ];

        // 未入队（被拒 / 连不上）才用 5xx：此时动作**从未执行**，重发是安全的。
        // `failed` 不走这里 —— 它已经执行过了，属「执行结果」，用 200 承载才对。
        if (in_array($outcome['state'], [ActionOutcome::STATE_REJECTED, ActionOutcome::STATE_TRANSPORT], true)) {
            return ApiReply::fail(
                502,
                ApiReply::CODE_UPSTREAM,
                '动作未入队：' . $outcome['msg'],
                $body
            );
        }

        return ApiReply::ok($body);
    }

    /**
     * 补查动作回执（`GET /action/{id}` 的转签代理，**纯读、可重试**）。
     *
     * ⚠ **与 `/api/session/{clientId}` 的 404 刻意不同**：那个 404 是**终态**
     * （会话已被回收，再查也不会有），而这里的「未命中」是**轮询过程中的瞬态**，
     * 且服务端刻意把「仍在执行」与「已过 `ACTION_RESULT_TTL` 被回收」混为一义
     * （`src/Api/Bootstrap.php:600` 的 hint）。用 404 会让前端的统一错误处理
     * 把它当接口故障并打断轮询 —— 那是本接口最需要它继续跑的场合。
     * 故：**HTTP 恒 200，判据是 `data.state`**（未命中 = `expired`）。
     */
    public function result(string $requestId, Request $request): Response
    {
        if (!ActionCatalog::validRequestId($requestId)) {
            return ApiReply::fail(
                400,
                ApiReply::CODE_INVALID_ARG,
                'request_id 形态非法（服务端口径：' . ActionCatalog::REQUEST_ID_PATTERN . '）'
            );
        }

        $res = GatewayPushClient::fromConfig()->actionResult($requestId);
        $outcome = ActionOutcome::of($res);

        return ApiReply::ok($outcome + [
            'label' => ActionOutcome::label($outcome['state']),
            'tone' => ActionOutcome::tone($outcome['state']),
            'result_ttl' => ActionCatalog::RESULT_TTL_MIRROR,
            // 补查是纯读，**不落审计**：若每次轮询都写一条，
            // `admin_audit_log` 会被单个调试会话刷满，真正的写操作反而被淹没。
            'audited' => false,
            'notes' => [ActionCatalog::RESULT_TTL_NOTE],
        ]);
    }
}
