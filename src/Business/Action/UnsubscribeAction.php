<?php
/**
 * 业务动作：unsubscribe（取消订阅）
 *
 * 模式：状态管理型 —— 与 SubscribeAction 成对，示范写操作的幂等处理：
 * 取消一个未订阅的主题同样返回成功，调用方无需区分「首次取消」与「重复取消」。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Business\Action;

use GatewayPush\Business\ActionContext;
use GatewayPush\Business\ActionInterface;
use GatewayPush\Business\Message;
use GatewayPush\Business\Monitor;
use GatewayPush\Business\Subscribe;

/**
 * 业务动作 unsubscribe：取消订阅（幂等）
 *
 * 与 SubscribeAction 成对；取消未订阅的主题同样返回成功。
 */
class UnsubscribeAction implements ActionInterface
{
    /**
     * @param ActionContext $ctx
     * @return void
     */
    public function handle(ActionContext $ctx)
    {
        Monitor::incr('action_unsubscribe');

        $uid   = $ctx->uid();
        $topic = (string)$ctx->param('topic');

        if ($uid === '') {
            $ctx->replyError(Message::CODE_UNAUTHORIZED, '缺少用户身份');
            return;
        }

        Subscribe::remove($uid, $topic, function ($ok, $msg) use ($ctx, $uid, $topic) {
            if (!$ok) {
                Monitor::incr('action_fail');
                $ctx->replyError(Message::CODE_PARAM_MISSING, $msg);
                return;
            }

            Subscribe::count($topic, function ($count) use ($ctx, $uid, $topic) {
                $ctx->reply(array(
                    'action'      => 'unsubscribe',
                    'uid'         => $uid,
                    'topic'       => $topic,
                    'subscribers' => $count,
                    'at'          => time(),
                ));
            });
        });
    }
}
