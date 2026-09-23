<?php
/**
 * 业务动作：revoke（Token 撤销）
 *
 * ---------------------------------------------------------------------
 * 只接受明文 token，不接受 fingerprint
 * ---------------------------------------------------------------------
 * 指纹是 `sha256(token)` 前 32 位（单向）。若允许按指纹撤销，等于开放
 * 「按猜测的指纹撤销任意 Token」的接口面 —— 而会话 Hash 里**没有** token 字段，
 * 服务端也从不存 Token 明文，故撤销只能由持有明文的一方发起。
 *
 * 这意味着后台 UI 上「撤销」**必须以粘贴 Token 为输入形态**（或由业务系统侧发起），
 * 不存在「从会话详情一键撤销」这条路径。
 *
 * ---------------------------------------------------------------------
 * 生效时机（按通道不同，务必如实告知调用方）
 * ---------------------------------------------------------------------
 * - **WS**：不会立即断开已有连接；该连接**下一次鉴权**（重连 / 重新 auth）时回 4001。
 * - **UDP**：无连接可断，该 Token 的**下一个包**被静默丢弃。
 * - 要「立刻断线」= revoke 后追加 kick（顺序不可反，见 KickAction 类注释）。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Business\Action;

use GatewayPush\Business\ActionContext;
use GatewayPush\Business\ActionInterface;
use GatewayPush\Business\Auth;
use GatewayPush\Business\Message;
use GatewayPush\Business\Monitor;
use GatewayPush\Common\Logger;

/**
 * 业务动作 revoke：撤销一个 Token（运维动作，仅限 HTTP 通道）
 *
 * 回执只回指纹，不回 Token 明文 —— 回执会进日志与后台审计。
 */
class RevokeTokenAction implements ActionInterface
{
    /**
     * @param ActionContext $ctx
     *
     * @return void
     */
    public function handle(ActionContext $ctx): void
    {
        Monitor::incr('action_revoke');

        $token = trim((string)$ctx->param('token', ''));
        $ttl   = (int)$ctx->param('ttl', 0);

        if ($token === '') {
            Monitor::incr('action_fail');
            $ctx->replyError(Message::CODE_PARAM_MISSING, 'token 不能为空');

            return;
        }

        if ($ttl < 0) {
            Monitor::incr('action_fail');
            $ctx->replyError(Message::CODE_PARAM_MISSING, 'ttl 不能为负数');

            return;
        }

        // 指纹在调用前算好 —— 不进闭包，也就不需要把明文 token 捕获进闭包作用域
        $fingerprint = self::fingerprint($token);

        // ttl = 0 时 Auth::revoke 自行回落 AUTH_TOKEN_TTL（默认 7200）
        Auth::revoke($token, $ttl, function ($ok) use ($ctx, $ttl, $fingerprint) {
            if (!$ok) {
                Monitor::incr('action_fail');
                $ctx->replyError(Message::CODE_SERVER_ERROR, '撤销失败：Redis 写入异常');

                return;
            }

            Logger::warn('运维动作 revoke 已执行', [
                'fingerprint' => $fingerprint,
                'ttl'         => $ttl,
                'channel'     => $ctx->channel(),
                'operator'    => $ctx->uid(),
            ]);

            $ctx->reply([
                'action'      => 'revoke',
                'fingerprint' => $fingerprint,
                'ttl'         => $ttl,
                'at'          => time(),
                'note'        => '已加入撤销名单：已有 WS 连接不会立即断开，'
                    . '该 Token 的下一次鉴权会被拒（4001）；UDP 侧下一个包被丢弃。'
                    . '如需立刻断线，请在本动作之后追加 kick。',
            ]);
        });
    }

    /**
     * Token 指纹（与 Auth 内部实现同口径：sha256 前 32 位）
     *
     * ⚠ 这是**第三处**指纹实现（Auth 内有一处私有、后台另有一处）。
     *   三处必须同口径，否则「撤销名单里的指纹」与「界面展示的指纹」对不上。
     *   此处刻意复用 `hash('sha256', $token)` 而非新增一套摘要算法。
     *
     * @param string $token
     *
     * @return string
     */
    private static function fingerprint(string $token): string
    {
        return substr(hash('sha256', $token), 0, 32);
    }
}
