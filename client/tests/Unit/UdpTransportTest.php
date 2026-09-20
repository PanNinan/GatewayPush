<?php
/**
 * UdpTransport 单测 —— 假连接 + 假计时器，脱离 workerman 事件环境
 *
 * 覆盖 P3 验收点的纯逻辑部分：
 *   建连同步触发 onOpen；预热窗口内发送入队、窗口结束补发；
 *   收到下行确认最旧在途报文（停止重传）；超时重传逐次累加、耗尽放弃并触发 onError；
 *   关闭后不可复用；未建连发送抛状态异常。
 *
 * UdpTransport 的真实链路行为由 P3 实测脚本（对运行中服务）验证，不在单测范围。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Tests\Unit;

use GatewayPush\Client\Error\ClientException;
use GatewayPush\Client\Error\ErrorCode;
use GatewayPush\Client\Tests\Support\FakeTimers;
use GatewayPush\Client\Tests\Support\FakeUdpConnection;
use GatewayPush\Client\Transport\UdpTransport;
use PHPUnit\Framework\TestCase;

final class UdpTransportTest extends TestCase
{
    use FakeTimers;

    /** @var FakeUdpConnection */
    private $fake;

    private function makeTransport(array $options = array(), &$fake = null)
    {
        $this->makeTimers();

        $fake         = new FakeUdpConnection();
        $this->fake   = $fake;
        $capturedFake = &$fake;

        return new UdpTransport(
            'udp://127.0.0.1:8283',
            $options,
            function () use (&$capturedFake) {
                return $capturedFake;
            },
            $this->timerAdd,
            $this->timerDel
        );
    }

    /** 建连并走完预热窗口 */
    private function connectAndWarmup(UdpTransport $transport)
    {
        $transport->connect();
        $this->fireTimer($this->lastTimerId()); // 预热窗口结束
    }

    public function testInvalidUrlThrowsConfig()
    {
        $this->makeTimers();
        try {
            new UdpTransport('ws://127.0.0.1:8283', array(), null, $this->timerAdd, $this->timerDel);
            self::fail('非 udp:// 的地址必须抛 ClientException');
        } catch (ClientException $e) {
            self::assertSame(ErrorCode::CLIENT_CONFIG, $e->getCode());
        }
    }

    public function testConnectFiresOpenSynchronously()
    {
        $transport = $this->makeTransport(array(), $fake);
        $opened    = false;
        $transport->onOpen(function () use (&$opened) {
            $opened = true;
        });

        $transport->connect();

        self::assertTrue($opened, 'UDP 建连为同步动作，onOpen 须在 connect() 行内触发');
        self::assertTrue($transport->isConnected());
        self::assertSame(1, $fake->connectCalls);
    }

    public function testConnectIsIdempotent()
    {
        $transport = $this->makeTransport(array(), $fake);
        $transport->connect();
        $transport->connect();

        self::assertSame(1, $fake->connectCalls);
    }

    public function testSendDuringWarmupIsQueuedAndFlushed()
    {
        $transport = $this->makeTransport(array(), $fake);
        $sentInOpen = 0;
        $transport->onOpen(function () use ($transport, &$sentInOpen) {
            $transport->send('{"cmd":"auth"}'); // onConnect 同步回调内的发送（硬约束⑩ 场景）
            $sentInOpen++;
        });

        $transport->connect();

        self::assertSame(1, $sentInOpen);
        self::assertCount(0, $fake->sent, '预热窗口内不得真正发出报文（首包静默丢失）');
        self::assertSame(1, $transport->queuedCount(), '报文应进入预热缓冲');

        $this->fireTimer($this->lastTimerId()); // 预热窗口结束
        self::assertCount(1, $fake->sent, '窗口结束须补发缓冲帧');
        self::assertSame('{"cmd":"auth"}', $fake->sent[0]);
        self::assertSame(0, $transport->queuedCount());
        self::assertSame(1, $transport->inflightCount(), '补发后进入在途跟踪');
    }

    public function testSendAfterWarmupGoesOutImmediately()
    {
        $transport = $this->makeTransport(array(), $fake);
        $this->connectAndWarmup($transport);

        $transport->send('frame-1');

        self::assertCount(1, $fake->sent);
        self::assertSame('frame-1', $fake->sent[0]);
    }

    public function testSendBeforeConnectThrowsState()
    {
        $transport = $this->makeTransport(array(), $fake);

        try {
            $transport->send('frame-1');
            self::fail('未建连发送必须抛 ClientException');
        } catch (ClientException $e) {
            self::assertSame(ErrorCode::CLIENT_STATE, $e->getCode());
        }
    }

    public function testInboundConfirmsOldestInflightAndStopsRetransmit()
    {
        $transport = $this->makeTransport(array(), $fake);
        $frames    = array();
        $transport->onMessage(function ($frame) use (&$frames) {
            $frames[] = $frame;
        });

        $this->connectAndWarmup($transport);
        $transport->send('frame-1');
        $transport->send('frame-2');
        self::assertSame(2, $transport->inflightCount());

        // 第一笔下行：确认最旧一笔（frame-1）
        $fake->emit('ack-1');
        self::assertSame(1, $transport->inflightCount());
        self::assertSame(array('ack-1'), $frames);

        // 第二笔下行：确认 frame-2，在途清空，重传定时器应被取消
        $fake->emit('ack-2');
        self::assertSame(0, $transport->inflightCount());
        self::assertSame(array('ack-1', 'ack-2'), $frames);

        // 推进时间：不应有任何重传发生
        $sentBefore = count($fake->sent);
        $this->fireTimer($this->lastTimerId());
        $this->fireTimer($this->lastTimerId());
        self::assertSame($sentBefore, count($fake->sent), '在途清空后不得再重传');
    }

    public function testRetransmitResendsInflightUntilAcked()
    {
        $transport = $this->makeTransport(array(), $fake);
        $this->connectAndWarmup($transport);

        $transport->send('frame-1');
        self::assertSame(1, $fake->sendCount('frame-1'));

        // 未收到下行：第一次重传
        $this->fireTimer($this->lastTimerId());
        self::assertSame(2, $fake->sendCount('frame-1'), '未收到回执须重传');

        // 收到下行：确认并停止
        $fake->emit('ack');
        $sentBefore = count($fake->sent);
        $this->fireTimer($this->lastTimerId());
        self::assertSame($sentBefore, count($fake->sent));
    }

    public function testRetransmitExhaustionDropsFrameAndFiresError()
    {
        $transport   = $this->makeTransport(array('max_attempts' => 2), $fake);
        $err         = null;
        $transport->onError(function ($code, $msg) use (&$err) {
            $err = array($code, $msg);
        });

        $this->connectAndWarmup($transport);
        $transport->send('frame-1');

        // 首发已 1 次；第一次重传 → 2 次（达上限）
        $this->fireTimer($this->lastTimerId());
        self::assertNull($err);
        self::assertSame(2, $fake->sendCount('frame-1'));

        // 第二次重传触发：超上限放弃
        $this->fireTimer($this->lastTimerId());
        self::assertNotNull($err, '重传耗尽须触发 onError');
        self::assertSame(ErrorCode::CLIENT_TRANSPORT, $err[0]);
        self::assertSame(1, $transport->droppedCount());
        self::assertSame(0, $transport->inflightCount());
        self::assertSame(2, $fake->sendCount('frame-1'), '放弃后不得再发送');

        // 放弃后不再安排重传
        $sentBefore = count($fake->sent);
        $this->fireTimer($this->lastTimerId());
        self::assertSame($sentBefore, count($fake->sent));
    }

    public function testCloseFiresOnCloseAndDiscardsConnection()
    {
        $transport = $this->makeTransport(array(), $fake);
        $closed    = false;
        $transport->onClose(function () use (&$closed) {
            $closed = true;
        });

        $this->connectAndWarmup($transport);
        $transport->send('frame-1');
        $transport->close();

        self::assertTrue($closed, 'close 须触发 onClose');
        self::assertTrue($fake->closed);
        self::assertFalse($transport->isConnected());

        try {
            $transport->send('frame-2');
            self::fail('关闭后发送必须抛 ClientException');
        } catch (ClientException $e) {
            self::assertSame(ErrorCode::CLIENT_STATE, $e->getCode());
        }

        // 关闭后可重新建连（重连路径：底层连接实例已丢弃）
        $transport->connect();
        self::assertTrue($transport->isConnected());
    }

    public function testCloseCancelsPendingTimers()
    {
        $transport = $this->makeTransport(array(), $fake);
        $transport->connect();
        $transport->send('frame-1'); // 此时在预热窗口内，有 warmup 定时器挂起

        $transport->close();

        foreach ($this->timers as $t) {
            self::assertTrue($t['deleted'], '关闭须取消全部挂起定时器（预热/重传）');
        }
    }

    public function testWarmupFlushDropsFramesIfClosedInBetween()
    {
        $transport = $this->makeTransport(array(), $fake);
        $transport->connect();
        $transport->send('frame-1'); // 入队

        $transport->close(); // 窗口结束前关闭

        // 触发已删除的 warmup 定时器：不应有任何发送，也不应异常
        $this->fireTimer(count($this->timers));
        self::assertCount(0, $fake->sent);
    }
}
