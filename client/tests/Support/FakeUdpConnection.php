<?php
/**
 * 假 UDP 连接：模拟 AsyncUdpConnection 的回调面（onConnect/onMessage/onClose）
 *
 * UdpTransport 通过连接工厂创建底层连接，单测注入本类脱离 workerman 事件环境。
 * 行为对齐真实实现的两点关键语义：
 *   - connect() 为同步动作，onConnect 在 connect() 调用行内触发；
 *   - close() 同步触发 onClose。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Tests\Support;

final class FakeUdpConnection
{
    /** @var callable|null function (self $con): void */
    public $onConnect;

    /** @var callable|null function (self $con, string $raw): void */
    public $onMessage;

    /** @var callable|null function (self $con): void */
    public $onClose;

    /** @var string[] 已发出的原始帧 */
    public $sent = array();

    /** @var int */
    public $connectCalls = 0;

    /** @var bool */
    public $closed = false;

    public function connect()
    {
        $this->connectCalls++;
        if ($this->onConnect !== null) {
            ($this->onConnect)($this);
        }
    }

    public function send($buffer)
    {
        $this->sent[] = (string)$buffer;

        return true;
    }

    public function close()
    {
        $this->closed = true;
        if ($this->onClose !== null) {
            ($this->onClose)($this);
        }
    }

    /* ---- 模拟辅助 ---- */

    /** 模拟服务端下发一帧原始报文 */
    public function emit($raw)
    {
        if ($this->onMessage !== null) {
            ($this->onMessage)($this, (string)$raw);
        }
    }

    /** 已发送某帧的次数（含重传） */
    public function sendCount($frame)
    {
        $n = 0;
        foreach ($this->sent as $buf) {
            if ($buf === (string)$frame) {
                $n++;
            }
        }

        return $n;
    }
}
