<?php
/**
 * 业务动作：unbind（设备解绑）
 *
 * 删除 `auth:bind:{uid}`，使该 uid **下一次鉴权重新「首绑胜出」** —— 即允许换设备登录。
 *
 * ---------------------------------------------------------------------
 * 语义边界（必须在后台 UI 上写明）
 * ---------------------------------------------------------------------
 * 1. **不踢线。** 已在线的旧设备连接不受影响，仍然在线、仍然可推送。
 *    要「换设备且旧的立刻下线」= unbind + kick 组合（顺序无所谓，两者无竞态）。
 * 2. **不解绑期间的风控语义消失。** 解绑后到下次鉴权前，任何设备都能成为「首绑者」，
 *    这是运维动作本身的意图，不是缺陷。
 * 3. **幂等。** 对未绑定的 uid 解绑同样返回成功 —— 与 unsubscribe 同口径，
 *    调用方无需区分「首次解绑」与「重复解绑」。
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
 * 业务动作 unbind：解绑 uid 与设备的绑定关系（运维动作，仅限 HTTP 通道）
 */
class UnbindDeviceAction implements ActionInterface
{
    /**
     * @param ActionContext $ctx
     *
     * @return void
     */
    public function handle(ActionContext $ctx): void
    {
        Monitor::incr('action_unbind');

        $uid = trim((string)$ctx->param('uid', ''));

        if ($uid === '') {
            Monitor::incr('action_fail');
            $ctx->replyError(Message::CODE_PARAM_MISSING, 'uid 不能为空');

            return;
        }

        Auth::unbindDevice($uid, function () use ($ctx, $uid) {
            Logger::warn('运维动作 unbind 已执行', [
                'uid'      => $uid,
                'channel'  => $ctx->channel(),
                'operator' => $ctx->uid(),
            ]);

            $ctx->reply([
                'action'  => 'unbind',
                'uid'     => $uid,
                'unbound' => true,
                'at'      => time(),
                'note'    => '已清除设备绑定：该 uid 的下一次鉴权将重新「首绑胜出」。'
                    . '本动作不踢线 —— 已在线的旧设备连接不受影响。',
            ]);
        });
    }
}
