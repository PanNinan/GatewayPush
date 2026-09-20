<?php
/**
 * 假 TCP 连接：模拟 AsyncTcpConnection 的回调面（WsTransport 单测用）
 *
 * 关键语义对齐真实实现：建连失败只触发 onError、不触发 onClose；
 * destroy() 同步触发 onClose。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Tests\Support;

final class FakeTcpConnection
{
    /** @var callable|null */
    public $onConnect;

    /** @var callable|null */
    public $onMessage;

    /** @var callable|null */
    public $onClose;

    /** @var callable|null */
    public $onError;

    /** @var string[] 已发送的帧 */
    public $sent = array();

    /** @var int */
    public $connectCalls = 0;

    /** @var bool 模拟建连失败：connect() 不触发 onConnect（真实实现仅随后触发 onError） */
    public $failOnConnect = false;

    /** @var bool */
    public $destroyed = false;

    public function connect()
    {
        $this->connectCalls++;
        if (!$this->failOnConnect && $this->onConnect !== null) {
            ($this->onConnect)($this);
        }
    }

    public function send($data, $raw = false)
    {
        $this->sent[] = (string)$data;

        return true;
    }

    public function close()
    {
        if ($this->onClose !== null) {
            ($this->onClose)($this);
        }
    }

    public function destroy()
    {
        $this->destroyed = true;
        if ($this->onClose !== null) {
            ($this->onClose)($this);
        }
    }

    /* ---- 模拟辅助 ---- */

    public function emit($frame)
    {
        if ($this->onMessage !== null) {
            ($this->onMessage)($this, (string)$frame);
        }
    }

    public function emitError($code, $msg)
    {
        if ($this->onError !== null) {
            ($this->onError)($this, $code, $msg);
        }
    }
}
