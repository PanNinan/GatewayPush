<?php
/**
 * 订阅关系动作 —— subscribe / unsubscribe / topics
 *
 * 订阅写入服务端 Redis 双向索引；投递入口为服务端 Push::enqueueTopic()
 * （业务代码 / HTTP 接口 / 运维命令调用）。服务端**刻意不开放客户端 publish**，
 * 客户端侧同样不提供。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Client\Service;

/**
 * 订阅关系动作：subscribe / unsubscribe / topics
 *
 * 服务端刻意不开放客户端 publish，客户端侧同样不提供。
 */
final class SubscribeApi extends AbstractApi
{
    /**
     * 订阅主题
     *
     * @param string        $topic 主题名（字母数字与 _ : . - ，1~64 字符）
     * @param null|callable $cb    function (bool $ok, array $data, ?array $error): void
     *
     * @return string 本请求 seq
     */
    public function subscribe(string $topic, $cb = null): string
    {
        return $this->call('subscribe', ['topic' => $topic], $cb);
    }

    /**
     * 取消订阅主题
     *
     * @param string        $topic
     * @param null|callable $cb
     *
     * @return string 本请求 seq
     */
    public function unsubscribe(string $topic, $cb = null): string
    {
        return $this->call('unsubscribe', ['topic' => $topic], $cb);
    }

    /**
     * 查询本人已订阅的主题列表
     *
     * @param null|callable $cb
     *
     * @return string 本请求 seq
     */
    public function topics($cb = null): string
    {
        return $this->call('topics', [], $cb);
    }
}
