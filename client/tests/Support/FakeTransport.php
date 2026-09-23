<?php
/**
 * 假传输层：记录发出的帧，提供 open/receive/drop/error 模拟服务端行为
 *
 * 供 SessionManager / PushReceiver / Service 各层单测共用。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Client\Tests\Support;

use GatewayPush\Client\Transport\TransportInterface;

final class FakeTransport implements TransportInterface
{
    /** @var array[] 已发出的解码报文 */
    public $sentPackets = [];

    public $connectCalls = 0;

    public $connected = false;

    private $openCb;
    private $msgCb;
    private $closeCb;
    private $errCb;

    public function connect()
    {
        $this->connectCalls++;
    }

    public function send($frame)
    {
        $packet = json_decode((string)$frame, true);
        $this->sentPackets[] = is_array($packet) ? $packet : [];
    }

    public function close()
    {
        $this->connected = false;
        if ($this->closeCb !== null) {
            ($this->closeCb)();
        }
    }

    public function isConnected()
    {
        return $this->connected;
    }

    public function onOpen(callable $cb)
    {
        $this->openCb = $cb;
    }

    public function onMessage(callable $cb)
    {
        $this->msgCb = $cb;
    }

    public function onClose(callable $cb)
    {
        $this->closeCb = $cb;
    }

    public function onError(callable $cb)
    {
        $this->errCb = $cb;
    }

    // ---- 模拟辅助 ----

    /** 模拟服务端：握手完成 */
    public function open()
    {
        $this->connected = true;
        if ($this->openCb !== null) {
            ($this->openCb)();
        }
    }

    /** 模拟服务端：下发报文 */
    public function receive(array $packet)
    {
        ($this->msgCb)(json_encode($packet, JSON_UNESCAPED_UNICODE));
    }

    /** 模拟服务端：下发原始帧（可构造非法 JSON） */
    public function receiveRaw($raw)
    {
        ($this->msgCb)((string)$raw);
    }

    /** 模拟服务端：连接断开（非用户主动） */
    public function drop()
    {
        $this->connected = false;
        if ($this->closeCb !== null) {
            ($this->closeCb)();
        }
    }

    /**
     * 模拟服务端：触发传输层错误
     *
     * @param string $message
     *
     * @return void
     */
    public function error($message)
    {
        if ($this->errCb !== null) {
            ($this->errCb)((string)$message);
        }
    }

    /** 最近发出的报文 */
    public function lastPacket()
    {
        return count($this->sentPackets) > 0 ? $this->sentPackets[count($this->sentPackets) - 1] : null;
    }
}
