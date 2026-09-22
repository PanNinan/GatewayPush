<?php
/**
 * 假 TCP 连接：模拟 AsyncTcpConnection 的回调面（HttpTransport 单测用）
 *
 * 对齐真实实现的关键语义：connect() 同步触发 onConnect；destroy() 不触发 onClose。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Tests\Support;

final class FakeHttpConnection
{
    /** @var null|callable */
    public $onConnect;

    /** @var null|callable */
    public $onMessage;

    /** @var null|callable */
    public $onClose;

    /** @var null|callable */
    public $onError;

    /** @var string[] 已发送的原始报文 */
    public $sentRaw = [];

    /** @var int */
    public $connectCalls = 0;

    /** @var bool */
    public $destroyed = false;

    public function connect()
    {
        $this->connectCalls++;
        if ($this->onConnect !== null) {
            ($this->onConnect)($this);
        }
    }

    public function send($data, $raw = false)
    {
        $this->sentRaw[] = (string)$data;

        return true;
    }

    public function destroy()
    {
        $this->destroyed = true;
    }

    // ---- 模拟辅助 ----

    /** 模拟服务端下发响应数据 */
    public function emit($raw)
    {
        if ($this->onMessage !== null) {
            ($this->onMessage)($this, (string)$raw);
        }
    }

    /** 模拟服务端关闭连接 */
    public function emitClose()
    {
        if ($this->onClose !== null) {
            ($this->onClose)($this);
        }
    }

    /** 模拟底层错误 */
    public function emitError($code, $msg)
    {
        if ($this->onError !== null) {
            ($this->onError)($this, $code, $msg);
        }
    }
}
