<?php
/**
 * 业务动作：report（数据上报）
 *
 * 模式：写入型 + 双通道差异化回执 —— 本动作是「同一处理器、不同通道不同回执策略」
 * 的示范：WebSocket 上回执受理结果，UDP 上静默处理（reply = none）。
 *
 * 之所以 UDP 静默：UDP 上报通常高频且客户端不关心单条结果，回执会造成
 * 双向流量放大；网关侧已对收包即时 ack，业务结果由后续查询动作获取即可。
 * 处理器本身不写任何通道判断 —— 是否下发完全由 config/actions.php 决定。
 *
 * 落地形态：按 topic 累加计数并记录最近一次上报归属，便于业务侧核对
 * 「上报是否到达」，也是接入真实业务（落库 / 转队列）的替换点。
 *
 * 兼容 PHP 8.0 ~ 8.5
 */

namespace GatewayPush\Business\Action;

use GatewayPush\Business\ActionContext;
use GatewayPush\Business\ActionInterface;
use GatewayPush\Business\Message;
use GatewayPush\Business\Monitor;
use GatewayPush\Common\Logger;
use GatewayPush\Common\RedisClient;

class ReportAction implements ActionInterface
{
    /** 上报统计键前缀 */
    const KEY_PREFIX = 'action:report:';

    /**
     * @param ActionContext $ctx
     * @return void
     */
    public function handle(ActionContext $ctx)
    {
        Monitor::incr('action_report');

        $topic = (string)$ctx->param('topic');
        $count = (int)$ctx->param('count', 1);
        $ttl   = (int)$ctx->option('ttl', 86400);
        $key   = self::KEY_PREFIX . $topic;

        RedisClient::hIncrBy($key, 'count', $count, function ($total) use ($ctx, $key, $topic, $count, $ttl) {
            if (!is_int($total)) {
                Logger::error('上报计数写入失败', array('topic' => $topic));
                $ctx->replyError(Message::CODE_SERVER_ERROR, '上报写入失败');
                return;
            }

            RedisClient::hMSet($key, array(
                'last_at'  => time(),
                'last_uid' => $ctx->uid(),
                'last_dev' => $ctx->deviceId(),
                'last_seq' => $ctx->seq(),
            ), function () use ($ctx, $key, $topic, $count, $total, $ttl) {
                if ($ttl > 0) {
                    RedisClient::expire($key, $ttl);
                }

                Logger::debug('数据上报已受理', array(
                    'topic'   => $topic,
                    'count'   => $count,
                    'total'   => $total,
                    'channel' => $ctx->channel(),
                ));

                $ctx->reply(array(
                    'action'   => 'report',
                    'topic'    => $topic,
                    'accepted' => $count,
                    'total'    => $total,
                    'at'       => time(),
                ));
            });
        });
    }
}
