<?php
/**
 * WebSocket 传输 —— workerman `AsyncTcpConnection('ws://...')` 的薄封装
 *
 * 职责只有三件事：
 *   1. 把 AsyncTcpConnection 的四类回调归一化为 TransportInterface 的签名；
 *   2. 维护 `isConnected()`（onConnect / onClose 驱动的布尔态）；
 *   3. 断开后丢弃底层连接对象 —— AsyncTcpConnection 关闭后不可复用，
 *      重连必须由 SessionManager 再次调用 connect() 新建实例。
 *
 * ws:// 握手（HTTP Upgrade）由 workerman 自动完成，onConnect 即握手完成。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Transport;

use GatewayPush\Client\Error\ClientException;
use Workerman\Connection\AsyncTcpConnection;

final class WsTransport implements TransportInterface
{
    /**
     * 服务地址（ws:// 或 wss://）
     *
     * @var string
     */
    private $url;

    /**
     * 底层连接（断开后置 null，重连时新建）
     *
     * @var AsyncTcpConnection|null
     */
    private $conn;

    /**
     * 连接可用状态
     *
     * @var bool
     */
    private $connected = false;

    /** @var callable|null function (): void */
    private $onOpenCb;

    /** @var callable|null function (string $frame): void */
    private $onMessageCb;

    /** @var callable|null function (): void */
    private $onCloseCb;

    /** @var callable|null function (int $code, string $message): void */
    private $onErrorCb;

    /**
     * @param string $url ws://host:port 或 wss://host:port
     * @throws ClientException URL 非法
     */
    public function __construct($url)
    {
        $url    = (string)$url;
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (!in_array($scheme, array('ws', 'wss'), true)) {
            throw ClientException::config('ws_url 必须以 ws:// 或 wss:// 开头，当前为：' . $url);
        }

        $this->url = $url;
    }

    /**
     * @inheritDoc
     */
    public function connect()
    {
        if ($this->conn !== null) {
            return; // 幂等：已在建连/已连接，重复调用无副作用
        }

        $conn = new AsyncTcpConnection($this->url);

        $conn->onConnect = function () {
            $this->connected = true;
            if ($this->onOpenCb !== null) {
                call_user_func($this->onOpenCb);
            }
        };

        $conn->onMessage = function ($con, $frame) {
            if ($this->onMessageCb !== null) {
                call_user_func($this->onMessageCb, (string)$frame);
            }
        };

        $conn->onClose = function () {
            // AsyncTcpConnection 关闭后不可复用，丢弃实例等待重连时新建
            $this->connected = false;
            $this->conn      = null;
            if ($this->onCloseCb !== null) {
                call_user_func($this->onCloseCb);
            }
        };

        $conn->onError = function ($con, $code, $msg) {
            if ($this->onErrorCb !== null) {
                call_user_func($this->onErrorCb, (int)$code, (string)$msg);
            }
        };

        $this->conn = $conn;
        $conn->connect();
    }

    /**
     * @inheritDoc
     */
    public function send($frame)
    {
        if ($this->conn === null || !$this->connected) {
            throw ClientException::state('WebSocket 连接未建立，无法发送报文');
        }

        $this->conn->send((string)$frame);
    }

    /**
     * @inheritDoc
     */
    public function close()
    {
        if ($this->conn !== null) {
            $this->conn->close(); // onClose 回调里统一置位与清理
        }
    }

    /**
     * @inheritDoc
     */
    public function isConnected()
    {
        return $this->connected;
    }

    /**
     * @inheritDoc
     */
    public function onOpen(callable $cb)
    {
        $this->onOpenCb = $cb;
    }

    /**
     * @inheritDoc
     */
    public function onMessage(callable $cb)
    {
        $this->onMessageCb = $cb;
    }

    /**
     * @inheritDoc
     */
    public function onClose(callable $cb)
    {
        $this->onCloseCb = $cb;
    }

    /**
     * @inheritDoc
     */
    public function onError(callable $cb)
    {
        $this->onErrorCb = $cb;
    }
}
