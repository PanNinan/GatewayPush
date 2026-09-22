<?php
/**
 * 单次请求上下文 —— pending 请求表的条目
 *
 * 与设计稿 §5.3 草案的一处偏离：`onReply`/`onTimeout` 两个回调合并为
 * `onReply(bool $ok, array $packet)` —— 超时也是「一次结算」，
 * 只是 ok=false 且 packet 为空数组。单一出口避免两条回调的触发次序歧义。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Session;

/**
 * 单次请求上下文（pending 请求表的条目）
 *
 * 回调合并为单出口 onReply(bool $ok, array $packet)，超时也是「一次结算」。
 */
final class PendingRequest
{
    /**
     * 请求序号（与服务端回执的 seq 对齐）
     *
     * @var string
     */
    public $seq;

    /**
     * 请求描述（排障用，如 auth / ping / data.echo）
     *
     * @var string
     */
    public $what;

    /**
     * 发出时刻（ microtime(true)，用于计算 RTT）
     *
     * @var float
     */
    public $sentAt;

    /**
     * 超时秒数
     *
     * @var float
     */
    public $timeout;

    /**
     * 超时定时器 id（workerman Timer；0 表示无）
     *
     * @var int
     */
    public $timerId = 0;

    /**
     * 结算回调 function (bool $ok, array $packet): void
     *
     * @var callable|null
     */
    public $onReply;
}
