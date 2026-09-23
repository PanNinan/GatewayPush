<?php
/**
 * 运维动作转签接口（P4）—— kick / revoke / unbind / force-offline
 *
 * ---------------------------------------------------------------------
 * 这一组端点与 `/actions` 调试页的分工
 * ---------------------------------------------------------------------
 * `/api/action`（ActionController）是**调试器**：给什么动作都转发，面向排障。
 * 本控制器是**运维功能**：只暴露 4 个动作、参数形状固定、一律落审计、
 * 回执里带该动作「不做什么」的说明（`caveats`）。
 *
 * ⚠ **本控制器是后台唯一允许「写主项目」的入口**（P4 之前后台是纯只读的）。
 *   它的权限节点**不得**进只读角色（见 `scripts/install.php` 的 `$viewerRules`），
 *   由 `tests/Unit/OpsActionContractTest.php` 钉住。
 *
 * ---------------------------------------------------------------------
 * 四条必须如实回传的语义（不许在 UI 上概括成「操作成功」）
 * ---------------------------------------------------------------------
 * 1. `kick` 只断 TCP，Token 仍有效 ⇒ 客户端可立即重连；
 * 2. `revoke` 不断连接，下一次鉴权才生效；
 * 3. `unbind` 不踢线，已在线的旧设备不受影响；
 * 4. `force-offline` = revoke → kick **串行**，顺序不可反（反了客户端会重连成功）。
 *
 * ---------------------------------------------------------------------
 * 关于明文 token
 * ---------------------------------------------------------------------
 * 主项目的 `revoke` 只接受明文（指纹单向）。而服务端**不存** Token 明文，
 * 会话详情里取不到它 ⇒ 只能由运维人工粘贴。
 * 明文因此会流经本控制器：它**只进入 `OpsAction::revoke()` 的那一次调用**，
 * 不写日志、不写响应；审计由 `Auditor::redact()` 把 `token` 键整体掩码兜底。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace app\controller\api;

use app\service\ActionOutcome;
use app\service\ApiReply;
use app\service\Auditor;
use app\service\OpsAction;
use support\Request;
use support\Response;

/**
 * 运维动作转签接口
 */
final class OpsActionController
{
    /** client_id 最大长度（与主项目 config/actions.php 的声明一致） */
    private const CLIENT_ID_MAX = 128;

    /** uid 最大长度 */
    private const UID_MAX = 64;

    /** reason 最大长度 */
    private const REASON_MAX = 128;

    /** token 最大长度（JWT 形态，放宽到 2048） */
    private const TOKEN_MAX = 2048;

    /**
     * POST /api/ops-action/kick —— 断开指定连接
     *
     * 入参：`client_id` 与 `uid` **二选一**（至少提供一个），`reason` 可选。
     */
    public function kick(Request $request): Response
    {
        $body    = $this->body($request);
        $clientId = isset($body['client_id']) ? trim((string)$body['client_id']) : '';
        $uid      = isset($body['uid']) ? trim((string)$body['uid']) : '';
        $reason   = isset($body['reason']) ? trim((string)$body['reason']) : '';

        if ($clientId !== '' && strlen($clientId) > self::CLIENT_ID_MAX) {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'client_id 超长（上限 ' . self::CLIENT_ID_MAX . '）');
        }
        if ($uid !== '' && strlen($uid) > self::UID_MAX) {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'uid 超长（上限 ' . self::UID_MAX . '）');
        }
        if (strlen($reason) > self::REASON_MAX) {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'reason 超长（上限 ' . self::REASON_MAX . '）');
        }
        if ($clientId === '' && $uid === '') {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'client_id 与 uid 至少提供一个');
        }

        $params = [];
        if ($clientId !== '') {
            $params['client_id'] = $clientId;
        }
        if ($uid !== '') {
            $params['uid'] = $uid;
        }
        if ($reason !== '') {
            $params['reason'] = $reason;
        }

        return $this->run(
            $request,
            Auditor::ACTION_OPS_KICK,
            'client_id',
            $clientId !== '' ? $clientId : $uid,
            $params,
            OpsAction::kick($params)
        );
    }

    /**
     * POST /api/ops-action/revoke —— 撤销一个 Token（只接受明文）
     *
     * 回执只回指纹。已有连接**不会**立即断开（见类注释第 2 条）。
     */
    public function revoke(Request $request): Response
    {
        $body = $this->body($request);
        $token = isset($body['token']) ? trim((string)$body['token']) : '';
        $ttl   = isset($body['ttl']) ? (int)$body['ttl'] : 0;

        if ($token === '') {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'token 不能为空（服务端不保存明文，需人工提供）');
        }
        if (strlen($token) > self::TOKEN_MAX) {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'token 超长（上限 ' . self::TOKEN_MAX . '）');
        }
        if ($ttl < 0) {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'ttl 不能为负数');
        }

        $res = OpsAction::revoke($token, $ttl);

        // 审计的 params 里**不放** token（Auditor::redact 也会掩，但这里从源头就不带）
        return $this->run(
            $request,
            Auditor::ACTION_OPS_REVOKE,
            'fingerprint',
            (string)($res['fingerprint'] ?? ''),
            ['fingerprint' => (string)($res['fingerprint'] ?? ''), 'ttl' => $ttl],
            $res
        );
    }

    /**
     * POST /api/ops-action/unbind —— 解绑 uid 与设备
     */
    public function unbind(Request $request): Response
    {
        $body = $this->body($request);
        $uid  = isset($body['uid']) ? trim((string)$body['uid']) : '';

        if ($uid === '') {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'uid 不能为空');
        }
        if (strlen($uid) > self::UID_MAX) {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'uid 超长（上限 ' . self::UID_MAX . '）');
        }

        return $this->run(
            $request,
            Auditor::ACTION_OPS_UNBIND,
            'uid',
            $uid,
            ['uid' => $uid],
            OpsAction::unbind($uid)
        );
    }

    /**
     * POST /api/ops-action/force-offline —— 「踢下线且禁止重连」组合
     *
     * 严格按 **revoke → kick** 串行执行：先让 Token 失效，再断开连接。
     * 顺序反了时客户端会落在重连窗口内用同一 Token 重连成功 —— 表现为「执行了却没效果」。
     *
     * ⚠ 未提供 token 时**只执行 kick**，并在 `note` 里如实说明「仍可重连」——
     *   不假装完成了「禁止重连」。服务端不存 Token 明文，这不是缺陷而是不可得。
     */
    public function forceOffline(Request $request): Response
    {
        $body     = $this->body($request);
        $token    = isset($body['token']) ? trim((string)$body['token']) : '';
        $clientId = isset($body['client_id']) ? trim((string)$body['client_id']) : '';
        $uid      = isset($body['uid']) ? trim((string)$body['uid']) : '';
        $reason   = isset($body['reason']) ? trim((string)$body['reason']) : '';

        if ($clientId === '' && $uid === '') {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'client_id 与 uid 至少提供一个');
        }
        if ($clientId !== '' && strlen($clientId) > self::CLIENT_ID_MAX) {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'client_id 超长（上限 ' . self::CLIENT_ID_MAX . '）');
        }
        if ($uid !== '' && strlen($uid) > self::UID_MAX) {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'uid 超长（上限 ' . self::UID_MAX . '）');
        }
        if ($token !== '' && strlen($token) > self::TOKEN_MAX) {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'token 超长（上限 ' . self::TOKEN_MAX . '）');
        }

        $steps = [];

        // —— 第 1 步：revoke（先行） ——
        if ($token !== '') {
            $rev = OpsAction::revoke($token);
            $steps[] = [
                'step' => 1,
                'action' => OpsAction::NAME_REVOKE,
                'fingerprint' => (string)($rev['fingerprint'] ?? ''),
                'outcome' => $rev['outcome'],
            ];
        } else {
            $steps[] = [
                'step' => 1,
                'action' => OpsAction::NAME_REVOKE,
                'skipped' => true,
                'reason' => '未提供 token（服务端不保存明文，会话详情里取不到）',
            ];
        }

        // —— 第 2 步：kick（后行） ——
        $params = [];
        if ($clientId !== '') {
            $params['client_id'] = $clientId;
        }
        if ($uid !== '') {
            $params['uid'] = $uid;
        }
        if ($reason !== '') {
            $params['reason'] = $reason;
        }
        $kick = OpsAction::kick($params);
        $steps[] = [
            'step' => 2,
            'action' => OpsAction::NAME_KICK,
            'outcome' => $kick['outcome'],
        ];

        $allOk = true;
        foreach ($steps as $s) {
            if (isset($s['outcome']) && !ActionOutcome::isSuccess($s['outcome'])) {
                $allOk = false;
            }
        }

        $auditOk = Auditor::record([
            'action' => Auditor::ACTION_OPS_FORCE_OFFLINE,
            'target_type' => 'client_id',
            'target' => $clientId !== '' ? $clientId : $uid,
            // ★ 键名刻意叫 `revoke_applied` 而不是 `has_token`：
            //   `Auditor::REDACT_PATTERN` 按**子串**匹配（含 `token` 即整体打码，宁枉勿纵），
            //   `has_token: true/false` 这个**布尔事实**会被打成 `***` ——
            //   而它正是审计要留的关键信息（「禁止重连那一半到底做没做」）。
            //   换个不含敏感词的键名表达同一事实，值本身不含任何凭证内容。
            'params' => ['revoke_applied' => $token !== '', 'uid' => $uid, 'reason' => $reason],
            'result' => $allOk ? Auditor::RESULT_OK : Auditor::RESULT_FAILED,
        ], $request);

        return ApiReply::ok([
            'steps' => $steps,
            'all_ok' => $allOk,
            'note' => OpsAction::FORCE_OFFLINE_NOTE,
            // 没给 token 时「禁止重连」这一半**没做到** —— 如实告知
            'partial' => $token === '',
            'partial_note' => $token === '' ? OpsAction::NO_TOKEN_NOTE : '',
            'audit_ok' => $auditOk,
        ]);
    }

    /**
     * POST /api/ops-action/purge-offline —— 清空 uid 的离线队列（不可恢复）
     *
     * 主项目 `purge_offline` 动作转签。回执 `result.purged` 是删除前的队列长度。
     * ⚠ 丢弃的是用户离线期间应收到且尚未补投的消息，**不可恢复** —— caveats 原样透出。
     */
    public function purgeOffline(Request $request): Response
    {
        $body = $this->body($request);
        $uid  = isset($body['uid']) ? trim((string)$body['uid']) : '';

        if ($uid === '') {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'uid 不能为空');
        }
        if (strlen($uid) > self::UID_MAX) {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'uid 超长（上限 ' . self::UID_MAX . '）');
        }

        return $this->run(
            $request,
            Auditor::ACTION_OPS_PURGE_OFFLINE,
            'uid',
            $uid,
            ['uid' => $uid],
            OpsAction::purgeOffline($uid)
        );
    }

    /**
     * 统一收口：落审计 + 组响应
     *
     * @param Request                                                    $request
     * @param string                                                     $action
     * @param string                                                     $targetType
     * @param string                                                     $target
     * @param array<string, mixed>                                       $auditParams
     * @param array{outcome: array<string, mixed>, caveats: string, ...} $res
     *
     * @return Response
     */
    private function run(Request $request, string $action, string $targetType, string $target, array $auditParams, array $res): Response
    {
        $outcome = $res['outcome'];
        $ok      = ActionOutcome::isSuccess($outcome);

        // 审计先落（业务已生效，审计失败必须透出而不是吞掉）
        $auditOk = Auditor::record([
            'action' => $action,
            'target_type' => $targetType,
            'target' => $target,
            'params' => $auditParams,
            'result' => $ok ? Auditor::RESULT_OK : Auditor::RESULT_FAILED,
            'code' => (int)$outcome['code'],
            'msg' => (string)$outcome['msg'],
        ], $request);

        $data = [
            'outcome' => $outcome,
            'caveats' => (string)($res['caveats'] ?? ''),
            'audit_ok' => $auditOk,
        ];
        if (isset($res['fingerprint'])) {
            $data['fingerprint'] = (string)$res['fingerprint'];
        }

        // ★ HTTP 恒为 200：成败看 `outcome.state`。
        //   理由与 `/api/action` 一致 —— 动作失败是**业务失败**（HTTP 200 + 业务码），
        //   只有「入队前被拒」才是 4xx。这里不存在 502（转签失败已由 outcome.state=transport 表达）。
        return ApiReply::ok($data);
    }

    /**
     * 取 JSON 请求体
     *
     * webman 在 `Content-Type: application/json` 时已把 body 解析进 `post()`，
     * 故这里读 `$request->post()`；非 JSON 形态回落 `$request->post()` 同样成立。
     *
     * @param Request $request
     *
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        $post = $request->post();

        return is_array($post) ? $post : [];
    }
}
