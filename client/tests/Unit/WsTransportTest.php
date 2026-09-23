<?php
/**
 * WsTransport 单测 —— 假 TCP 连接（ws 握手前的纯传输面）
 *
 * 重点覆盖 P5 补强的「建连失败」路径：workerman 的 AsyncTcpConnection 在
 * 建连失败时只触发 onError、**不触发 onClose**（见 AsyncTcpConnection::
 * checkConnection 失败分支），WsTransport 须补发 close 信号，
 * 否则 SessionManager 会卡死在 connecting 态、重连链路中断。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Client\Tests\Unit;

use GatewayPush\Client\Error\ClientException;
use GatewayPush\Client\Error\ErrorCode;
use GatewayPush\Client\Tests\Support\FakeTcpConnection;
use GatewayPush\Client\Transport\WsTransport;
use PHPUnit\Framework\TestCase;

final class WsTransportTest extends TestCase
{
    public function testInvalidUrlThrowsConfig()
    {
        try {
            new WsTransport('http://127.0.0.1:8282');
            self::fail('非 ws(s):// 的地址必须抛 ClientException');
        } catch (ClientException $e) {
            self::assertSame(ErrorCode::CLIENT_CONFIG, $e->getCode());
        }
    }

    public function testConnectFiresOpenAndMessagePasses()
    {
        $transport = $this->makeTransport($fake);
        $opened    = false;
        $frames    = [];
        $transport->onOpen(function () use (&$opened) {
            $opened = true;
        });
        $transport->onMessage(function ($frame) use (&$frames) {
            $frames[] = $frame;
        });

        $transport->connect();
        self::assertTrue($opened);
        self::assertTrue($transport->isConnected());

        $fake->emit('{"cmd":"ack"}');
        self::assertSame(['{"cmd":"ack"}'], $frames);
    }

    public function testConnectFailureEmitsErrorThenCloseSignal()
    {
        $transport = $this->makeTransport($fake);
        $errors    = [];
        $closed    = false;
        $transport->onError(function ($code, $msg) use (&$errors) {
            $errors[] = [$code, $msg];
        });
        $transport->onClose(function () use (&$closed) {
            $closed = true;
        });

        $fake->failOnConnect = true;
        $transport->connect();
        // 模拟 workerman 建连失败：onConnect 不触发，仅 onError（真实实现不触发 onClose）
        $fake->emitError(13, 'Connection refused');

        self::assertCount(1, $errors, 'onError 须照常向上抛');
        self::assertTrue($closed, '建连失败须补发 onClose 信号（否则会话层卡死在 connecting）');
        self::assertFalse($transport->isConnected());
        self::assertTrue($fake->destroyed, '失败连接实例须被销毁以便重建');
    }

    public function testMidConnectionErrorDoesNotForceClose()
    {
        $transport = $this->makeTransport($fake);
        $closed    = false;
        $transport->onClose(function () use (&$closed) {
            $closed = true;
        });

        $transport->connect();
        // 已建连后的错误（如协议错误）不主动补发 close，等待真实 onClose
        $fake->emitError(2, 'protocol error');

        self::assertFalse($closed);
        self::assertTrue($transport->isConnected());
    }

    public function testCloseFiresOnCloseAndAllowsReconnect()
    {
        $transport = $this->makeTransport($fake);
        $closed    = false;
        $transport->onClose(function () use (&$closed) {
            $closed = true;
        });

        $transport->connect();
        $transport->close();

        self::assertTrue($closed);
        self::assertFalse($transport->isConnected());

        // 重连须新建底层连接实例（工厂返回同一假实例，connectCalls 累加）
        $transport->connect();
        self::assertSame(2, $fake->connectCalls);
    }

    public function testSendBeforeConnectThrowsState()
    {
        $transport = $this->makeTransport($fake);

        try {
            $transport->send('{}');
            self::fail('未建连发送必须抛 ClientException');
        } catch (ClientException $e) {
            self::assertSame(ErrorCode::CLIENT_STATE, $e->getCode());
        }
    }

    private function makeTransport(&$fake = null)
    {
        $fake     = new FakeTcpConnection();
        $captured = &$fake;

        return new WsTransport('ws://127.0.0.1:8282', function () use (&$captured) {
            return $captured;
        });
    }
}
