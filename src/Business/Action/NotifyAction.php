<?php
/**
 * 业务动作：notify（请求服务端向本人推送）
 *
 * 模式：触发型 —— 处理器内部调用推送服务，示范「上行报文 -> 下行推送」的闭环。
 *
 * 为何走 Push::enqueue 而非直接下发：
 *   队列化使本动作自动继承离线缓存、幂等去重、指标统计与 UDP 出站分流
 *   等既有能力，无需在处理器内重复实现任何投递细节。
 *
 * 安全边界：目标恒为调用方自身 uid，不接受任意 uid 入参 ——
 * 否则任何客户端都能借服务端向他人推送（消息伪造）。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Business\Action;

use GatewayPush\Business\ActionContext;
use GatewayPush\Business\ActionInterface;
use GatewayPush\Business\Message;
use GatewayPush\Business\Monitor;
use GatewayPush\Business\Push;

/**
 * 业务动作 notify：请求服务端向本人推送
 *
 * 目标恒为调用方自身 uid；经 Push::enqueue 复用离线缓存与幂等去重。
 */
class NotifyAction implements ActionInterface
{
    /**
     * @param ActionContext $ctx
     *
     * @return void
     */
    public function handle(ActionContext $ctx): void
    {
        Monitor::incr('action_notify');

        $uid = $ctx->uid();
        if ($uid === '') {
            $ctx->replyError(Message::CODE_UNAUTHORIZED, '缺少用户身份');

            return;
        }

        $msgId   = (string)$ctx->param('msg_id', '');
        $offline = (string)$ctx->param('offline_mode', '');

        Push::enqueue(Push::TARGET_UID, $uid, [
            'action' => 'notify',
            'value'  => $ctx->param('value', []),
            'from'   => 'action.notify',
            'at'     => time(),
        ], [
            'msg_id'       => $msgId,
            'source'       => 'action.notify',
            'offline_mode' => $offline,
        ], function ($ok) use ($ctx, $uid, $msgId) {
            if (!$ok) {
                Monitor::incr('action_fail');
                $ctx->replyError(Message::CODE_SERVER_ERROR, '推送任务入队失败');

                return;
            }

            $ctx->reply([
                'action' => 'notify',
                'target' => $uid,
                'msg_id' => $msgId,
                'queued' => 1,
                'at'     => time(),
            ]);
        });
    }
}
