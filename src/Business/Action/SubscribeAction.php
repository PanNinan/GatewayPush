<?php
/**
 * 业务动作：subscribe（订阅主题）
 *
 * 模式：状态管理型 —— 写 Redis 双向索引 + 异步回执。
 * 与 unsubscribe / topics 三个动作共用 Subscribe 服务，示范「多动作协作」。
 *
 * 订阅后的投递入口是 Push::enqueueTopic()，二者共同构成
 * 「订阅 -> 广播推送」的完整闭环（本动作只负责前半段）。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Business\Action;

use GatewayPush\Business\ActionContext;
use GatewayPush\Business\ActionInterface;
use GatewayPush\Business\Message;
use GatewayPush\Business\Monitor;
use GatewayPush\Business\Subscribe;

/**
 * 业务动作 subscribe：订阅主题
 *
 * 状态管理型；与 unsubscribe / topics 共用 Subscribe 服务，投递入口在 Push::enqueueTopic()。
 */
class SubscribeAction implements ActionInterface
{
    /**
     * @param ActionContext $ctx
     *
     * @return void
     */
    public function handle(ActionContext $ctx)
    {
        Monitor::incr('action_subscribe');

        $uid   = $ctx->uid();
        $topic = (string)$ctx->param('topic');

        if ($uid === '') {
            $ctx->replyError(Message::CODE_UNAUTHORIZED, '缺少用户身份');

            return;
        }

        Subscribe::add($uid, $topic, function ($ok, $msg) use ($ctx, $uid, $topic) {
            if (!$ok) {
                Monitor::incr('action_fail');
                $ctx->replyError(Message::CODE_PARAM_MISSING, $msg);

                return;
            }

            // 回执带上当前订阅者数量，便于调用方确认广播规模
            Subscribe::count($topic, function ($count) use ($ctx, $uid, $topic) {
                $ctx->reply([
                    'action'      => 'subscribe',
                    'uid'         => $uid,
                    'topic'       => $topic,
                    'subscribers' => $count,
                    'at'          => time(),
                ]);
            });
        });
    }
}
