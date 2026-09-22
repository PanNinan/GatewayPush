<?php
/**
 * 业务动作：topics（查询本人订阅的主题列表）
 *
 * 模式：读取型 —— 查询当前 uid 的订阅集合。
 * 与 session 动作同样的越权防护：不接受 uid 入参，只能查自己。
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
 * 业务动作 topics：查询本人订阅的主题列表
 *
 * 读取型；只查当前 uid，不接受 uid 入参。
 */
class TopicsAction implements ActionInterface
{
    /**
     * @param ActionContext $ctx
     *
     * @return void
     */
    public function handle(ActionContext $ctx)
    {
        Monitor::incr('action_topics');

        $uid = $ctx->uid();
        if ($uid === '') {
            $ctx->replyError(Message::CODE_UNAUTHORIZED, '缺少用户身份');

            return;
        }

        Subscribe::topicsOf($uid, function ($topics) use ($ctx, $uid) {
            $ctx->reply([
                'action' => 'topics',
                'uid'    => $uid,
                'topics' => $topics,
                'count'  => count($topics),
                'at'     => time(),
            ]);
        });
    }
}
