<?php
/**
 * 订阅关系动作 —— subscribe / unsubscribe / topics
 *
 * 订阅写入服务端 Redis 双向索引；投递入口为服务端 Push::enqueueTopic()
 * （业务代码 / HTTP 接口 / 运维命令调用）。服务端**刻意不开放客户端 publish**，
 * 客户端侧同样不提供。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Service;

final class SubscribeApi extends AbstractApi
{
    /**
     * 订阅主题
     *
     * @param string        $topic 主题名（字母数字与 _ : . - ，1~64 字符）
     * @param callable|null $cb    function (bool $ok, array $data, ?array $error): void
     * @return string 本请求 seq
     */
    public function subscribe($topic, $cb = null)
    {
        return $this->call('subscribe', array('topic' => (string)$topic), $cb);
    }

    /**
     * 取消订阅主题
     *
     * @param string        $topic
     * @param callable|null $cb
     * @return string 本请求 seq
     */
    public function unsubscribe($topic, $cb = null)
    {
        return $this->call('unsubscribe', array('topic' => (string)$topic), $cb);
    }

    /**
     * 查询本人已订阅的主题列表
     *
     * @param callable|null $cb
     * @return string 本请求 seq
     */
    public function topics($cb = null)
    {
        return $this->call('topics', array(), $cb);
    }
}
