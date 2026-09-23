<?php
/**
 * 业务动作：purge_offline（清空离线队列）
 *
 * 删除 `push:offline:{uid}` 列表 —— 该 uid 的全部离线缓存消息**被丢弃且不可恢复**。
 *
 * ---------------------------------------------------------------------
 * 语义边界（必须在后台 UI 上写明）
 * ---------------------------------------------------------------------
 * 1. **不可恢复。** 与 kick 不同，本动作没有任何「重连窗口」语义：
 *    删掉的消息不会再补投（replayOffline 读的就是这个列表）。回执里的
 *    `purged` 是删除前的队列长度，仅作留痕，消息内容不回带。
 * 2. **不影响在线投递。** 只清缓存，不动任何连接与订阅关系。
 * 3. **幂等。** 对空队列 / 无队列的 uid 执行同样返回成功（purged=0）。
 *
 * 典型场景：测试环境遗留的旧消息想手动清掉、某 uid 的离线缓存堆积需要
 * 运维介入丢弃。生产环境慎用 —— 丢弃的是「用户离线期间应该收到的消息」。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Business\Action;

use GatewayPush\Business\ActionContext;
use GatewayPush\Business\ActionInterface;
use GatewayPush\Business\Message;
use GatewayPush\Business\Monitor;
use GatewayPush\Common\Logger;
use GatewayPush\Common\RedisClient;
use GatewayPush\Common\RedisKeys;

/**
 * 业务动作 purge_offline：丢弃 uid 的全部离线缓存消息（运维动作，仅限 HTTP 通道）
 */
class PurgeOfflineAction implements ActionInterface
{
    /**
     * @param ActionContext $ctx
     *
     * @return void
     */
    public function handle(ActionContext $ctx): void
    {
        Monitor::incr('action_purge_offline');

        $uid = trim((string)$ctx->param('uid', ''));

        if ($uid === '') {
            Monitor::incr('action_fail');
            $ctx->replyError(Message::CODE_PARAM_MISSING, 'uid 不能为空');

            return;
        }

        $key = RedisKeys::pushOffline($uid);

        // 先记长度再删：回执只留「丢了多少条」这个数字，不回带任何消息内容。
        RedisClient::lLen($key, function ($len) use ($ctx, $key, $uid) {
            $count = (int)($len ?? 0);

            RedisClient::del($key, function () use ($ctx, $count, $uid) {
                Logger::warn('运维动作 purge_offline 已执行', [
                    'uid'     => $uid,
                    'purged'  => $count,
                    'channel' => $ctx->channel(),
                    'operator' => $ctx->uid(),
                ]);

                $ctx->reply([
                    'action'  => 'purge_offline',
                    'uid'     => $uid,
                    'purged'  => $count,
                    'at'      => time(),
                    'note'    => '已清空离线队列：' . $count . ' 条离线缓存被丢弃且不可恢复。'
                        . '本动作不影响在线投递与订阅关系。',
                ]);
            });
        });
    }
}
