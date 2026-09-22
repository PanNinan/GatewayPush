<?php
/**
 * 业务动作：session（查询当前连接的会话摘要）
 *
 * 模式：读取型 —— 读 Redis 状态 + 异步回执，示范「处理器如何跨越异步边界回执」。
 *
 * 安全约定：只读取「调用方自己」的会话，不接受 client_id / uid 入参，
 * 从接口形态上根除越权探测他人会话的可能（而非靠运行时校验兜底）。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Business\Action;

use GatewayPush\Business\ActionContext;
use GatewayPush\Business\ActionInterface;
use GatewayPush\Business\Monitor;
use GatewayPush\Business\Session;

/**
 * 业务动作 session：查询调用方自身的会话摘要
 *
 * 读取型；不接受 client_id / uid 入参，从接口形态上根除越权探测。
 */
class SessionAction implements ActionInterface
{
    /**
     * @param ActionContext $ctx
     *
     * @return void
     */
    public function handle(ActionContext $ctx)
    {
        Monitor::incr('action_session');

        $clientId = $ctx->clientId();

        Session::get($clientId, function ($session) use ($ctx, $clientId) {
            $connectAt = isset($session['connect_at']) ? (int)$session['connect_at'] : 0;

            $ctx->reply([
                'action'      => 'session',
                'client_id'   => $clientId,
                'uid'         => isset($session['uid']) ? (string)$session['uid'] : '',
                'device_id'   => isset($session['device_id']) ? (string)$session['device_id'] : '',
                'protocol'    => isset($session['protocol']) ? (string)$session['protocol'] : '',
                'channel'     => $ctx->channel(),
                'online'      => $session ? 1 : 0,
                'connect_at'  => $connectAt,
                'online_secs' => $connectAt > 0 ? max(0, time() - $connectAt) : 0,
            ]);
        });
    }
}
