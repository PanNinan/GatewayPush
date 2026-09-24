<?php
/**
 * 运维动作转签层（P4）
 *
 * ---------------------------------------------------------------------
 * 定位：后台不自己实现运维逻辑，只做「转签 + 如实回传」
 * ---------------------------------------------------------------------
 * 四个动作（kick / revoke / unbind / purge_offline）的**执行**在主项目 business 进程内 ——
 * 踢线依赖 `GatewayWorker\Lib\Gateway::closeClient()`，而它必须有到 Gateway 的连接池，
 * HTTP 进程没有。故后台只能经 `POST /action` 转签，再取回执。
 *
 * 本层的价值不在「转发」，而在把**四个语义陷阱**固化成常量与结构，
 * 使控制器与前端都不必各自编词：
 *
 * 1. **`kick` 不撤 Token** —— 客户端可立即用同一 Token 重连。
 *    「踢下线且禁止重连」= **先 revoke、后 kick**，顺序反了等于白做。
 * 2. **`revoke` 不断连接** —— 已有 WS 连接在下一次鉴权时才被拒；UDP 侧下一个包才被丢弃。
 * 3. **`unbind` 不踢线** —— 已在线的旧设备不受影响。
 * 4. **`purge_offline` 不可恢复** —— 丢弃的是用户离线期间应收到且尚未补投的消息，
 *    删掉就没了；不影响在线投递与订阅关系。
 *
 * ---------------------------------------------------------------------
 * 关于 token：只接受明文，且**绝不落库**
 * ---------------------------------------------------------------------
 * 主项目的 `revoke` 只接受明文 `token`（指纹是 sha256 单向摘要，允许按指纹撤销
 * 等于开放「猜测指纹撤销任意 Token」的接口面）。而服务端会话 Hash 里**没有** token
 * 字段 ⇒ 后台的会话详情页**拿不到** token ⇒ 「撤销」只能由人工粘贴明文发起。
 *
 * 明文 token 因此会流经后台进程：本层保证它**只出现在这一次 HTTP 调用里**，
 * 审计侧由 `Auditor::redact()` 把 `token` 键整体掩码（正则命中即掩），
 * 回执里只回指纹。⚠ 任何新增代码都**不得**把 `$token` 写进日志 / 审计 / 响应。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace app\service;

use support\Log;

/**
 * 运维动作转签
 */
final class OpsAction
{
    /** 动作名：踢线 */
    public const NAME_KICK = 'kick';

    /** 动作名：撤销 Token */
    public const NAME_REVOKE = 'revoke';

    /** 动作名：解绑设备 */
    public const NAME_UNBIND = 'unbind';

    /** 动作名：清空离线队列 */
    public const NAME_PURGE_OFFLINE = 'purge_offline';

    /**
     * 各动作「不做什么」—— UI 必须原样展示，不许概括成「成功 / 失败」
     *
     * 键即动作名，与 `config/actions.php`（主项目）登记的名字一一对应。
     */
    public const CAVEATS = [
        self::NAME_KICK => '仅断开 TCP 连接，Token 仍然有效：客户端可立即重连。'
            . '如需禁止重连，必须先 revoke 再 kick（顺序不可反）。',
        self::NAME_REVOKE => '只把 Token 加入撤销名单，不主动断开已有连接：'
            . 'WS 侧该 Token 的下一次鉴权会被拒，UDP 侧下一个包被丢弃。'
            . '如需立刻断线，须在本动作之后追加 kick。',
        self::NAME_UNBIND => '只清除 uid 与设备的绑定关系，不踢线：'
            . '已在线的旧设备连接不受影响，仍在线、仍可推送。',
        self::NAME_PURGE_OFFLINE => '清空该 uid 的离线队列：未补投的离线消息被丢弃且不可恢复。'
            . '不影响在线投递、连接与订阅关系。生产环境慎用。',
    ];

    /**
     * 「强制下线」组合的顺序说明（UI 直接展示）
     */
    public const FORCE_OFFLINE_NOTE = '组合动作按 revoke → kick 串行执行：'
        . '先撤销 Token 再断开连接。顺序反过来时，客户端会落在重连窗口内用同一 Token 重连成功，'
        . '表现为「明明执行了却没效果」。';

    /**
     * 缺 token 时的说明 —— 不是错误，是「本形态做不到」
     *
     * 后台拿不到 token（服务端不存明文），故「强制下线」在没有 token 时
     * **只能做到 kick**，必须如实告知，不能假装完成了「禁止重连」。
     */
    public const NO_TOKEN_NOTE = '未提供的 token：本次只执行了 kick（断开连接），'
        . '客户端仍可用同一 Token 立即重连。服务端不保存 Token 明文，会话详情里取不到它。';

    /**
     * kick：断开指定连接
     *
     * @param array{client_id?: string, uid?: string, reason?: string} $params
     *
     * @return array{outcome: array<string, mixed>, caveats: string}
     */
    public static function kick(array $params): array
    {
        return self::call(self::NAME_KICK, $params);
    }

    /**
     * revoke：撤销一个 Token
     *
     * ⚠ `$token` 是明文。本方法保证它只进入这一次请求体，
     *   不写日志、不进审计（审计侧另有 redact 兜底）、不出现在回执里。
     *
     * @param string $token 明文 Token
     * @param int    $ttl   0 = 取服务端 AUTH_TOKEN_TTL
     *
     * @return array{outcome: array<string, mixed>, caveats: string, fingerprint: string}
     */
    public static function revoke(string $token, int $ttl = 0): array
    {
        $res  = self::call(self::NAME_REVOKE, $ttl > 0 ? ['token' => $token, 'ttl' => $ttl] : ['token' => $token]);
        // 只回指纹 —— 明文在任何出口都不出现
        $res['fingerprint'] = self::fingerprint($token);

        return $res;
    }

    /**
     * unbind：解绑 uid 与设备
     *
     * @param string $uid
     *
     * @return array{outcome: array<string, mixed>, caveats: string}
     */
    public static function unbind(string $uid): array
    {
        return self::call(self::NAME_UNBIND, ['uid' => $uid]);
    }

    /**
     * purge_offline：清空 uid 的离线队列（不可恢复）
     *
     * @param string $uid
     *
     * @return array{outcome: array<string, mixed>, caveats: string}
     */
    public static function purgeOffline(string $uid): array
    {
        return self::call(self::NAME_PURGE_OFFLINE, ['uid' => $uid]);
    }

    /**
     * Token 指纹（与主项目 `Auth::tokenFingerprint()` 同口径：sha256 前 32 位）
     *
     * ⚠ 这是**第四处**指纹实现（主项目 Auth / 主项目 RevokeTokenAction / 后台本类 /
     *   可能的客户端）。四处必须同口径，否则「撤销名单里的指纹」与
     *   「界面展示的指纹」对不上。**改任何一处必须同步其余。**
     *
     * @param string $token
     *
     * @return string
     */
    public static function fingerprint(string $token): string
    {
        return substr(hash('sha256', $token), 0, 32);
    }

    /**
     * 统一转签：调主项目 `POST /action` 并把结果归一成 ActionOutcome
     *
     * 与 `/actions` 调试页走**同一条**路径（同一个 `GatewayPushClient::action()`），
     * 故回执形态完全一致：`state` / `http` / `code` / `msg` / `result` / `resend_*`。
     * 差异只有两点：
     *   ① 这里额外带上 `caveats`（该动作「不做什么」的说明）；
     *   ② 运维动作**不自动补查** —— 它们是同步完成的，出现 `pending` 说明服务端超时，
     *      由调用方（UI）决定是否用 `request_id` 补查，不在本层起轮询。
     *
     * @param string               $action
     * @param array<string, mixed> $params
     *
     * @return array{outcome: array<string, mixed>, caveats: string}
     */
    private static function call(string $action, array $params): array
    {
        $client = GatewayPushClient::fromConfig();

        $res = $client->action([
            'action' => $action,
            // 运维动作 auth=false：uid 是操作对象而非调用方身份，故不传 uid
            'params' => $params,
        ]);

        $outcome = ActionOutcome::of($res);

        if (!ActionOutcome::isSuccess($outcome)) {
            // 运维动作失败必须留痕 —— 但**不写 params**（revoke 的 params 含明文 token）
            Log::warning('运维动作执行未成功', [
                'action'  => $action,
                'state'   => $outcome['state'],
                'http'    => $outcome['http'],
                'code'    => $outcome['code'],
                'msg'     => $outcome['msg'],
                'request_id' => $outcome['request_id'],
            ]);
        }

        return [
            'outcome' => $outcome,
            'caveats' => self::CAVEATS[$action],
        ];
    }
}
