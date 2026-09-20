<?php
/**
 * PushReceiver 单测 —— 推送解析 / 回调 / 自动回执 / offline 标记 / ack 计数
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Tests\Unit;

use GatewayPush\Business\Message;
use GatewayPush\Client\Event\PushReceiver;
use GatewayPush\Client\Session\SessionManager;
use GatewayPush\Client\Tests\Support\FakeTimers;
use GatewayPush\Client\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class PushReceiverTest extends TestCase
{
    use FakeTimers;

    private function makeReady(FakeTransport &$transport = null, array $overrides = array())
    {
        $this->makeTimers();

        $transport = new FakeTransport();
        $session   = new SessionManager(array_merge(array(
            'uid'       => 'alice',
            'device_id' => 'dev1',
            'secret'    => 'test-secret',
            'heartbeat' => 0,
        ), $overrides), $transport, null, $this->timerAdd, $this->timerDel);

        $session->connect();
        $transport->open();
        $auth = $transport->lastPacket();
        $transport->receive(array(
            'cmd'  => Message::CMD_ACK,
            'seq'  => $auth['seq'],
            'ts'   => time(),
            'data' => array('uid' => 'alice', 'device_id' => 'dev1', 'protocol' => 'ws', 'reconnected' => 0),
        ));

        return $session;
    }

    private function pushPacket(array $overrides = array())
    {
        return array_merge(array(
            'cmd'       => Message::CMD_PUSH,
            'seq'       => 'm-1',
            'ts'        => 1700000000,
            'uid'       => 'alice',
            'device_id' => 'dev1',
            'token'     => '',
            'sign'      => '',
            'data'      => array('title' => 'hi', 'n' => 1),
            'msg_id'    => 'm-1',
            'source'    => 'action.notify',
            'offline'   => 0,
            'pushed_at' => 1700000001,
        ), $overrides);
    }

    public function testPushIsParsedIntoPayloadAndMeta()
    {
        $session   = $this->makeReady($transport);
        $receiver  = new PushReceiver($session);
        $got       = null;
        $receiver->onPush(function (array $payload, array $meta) use (&$got) {
            $got = array($payload, $meta);
        });

        $transport->receive($this->pushPacket());

        self::assertNotNull($got);
        self::assertSame(array('title' => 'hi', 'n' => 1), $got[0]);
        self::assertSame('m-1', $got[1]['msg_id']);
        self::assertSame('m-1', $got[1]['seq']);
        self::assertSame('action.notify', $got[1]['source']);
        self::assertSame(0, $got[1]['offline']);
        self::assertSame(1700000001, $got[1]['pushed_at']);
    }

    public function testPushIsAcknowledgedAutomatically()
    {
        $session  = $this->makeReady($transport);
        $receiver = new PushReceiver($session);
        $receiver->onPush(function () {
        });

        $transport->receive($this->pushPacket(array('msg_id' => 'm-42', 'seq' => 'm-42')));

        $ack = $transport->lastPacket();
        self::assertSame(Message::CMD_ACK, $ack['cmd']);
        self::assertSame('m-42', $ack['seq'], 'ack 的 seq 即服务端下发的 msg_id');
        self::assertSame('m-42', $ack['data']['msg_id'], 'data.msg_id 对齐（服务端 handleClientAck 优先读它）');
        self::assertSame(1, $receiver->ackedCount());
    }

    public function testMsgIdFallsBackToSeq()
    {
        $session  = $this->makeReady($transport);
        $receiver = new PushReceiver($session);
        $got      = null;
        $receiver->onPush(function (array $payload, array $meta) use (&$got) {
            $got = $meta;
        });

        // 服务端 buildFrame：msg_id 为空时 seq = genMsgId()，且 msg_id 字段为空串
        $transport->receive($this->pushPacket(array('msg_id' => '', 'seq' => 'p-abcd1234')));

        self::assertSame('p-abcd1234', $got['msg_id']);

        $ack = $transport->lastPacket();
        self::assertSame('p-abcd1234', $ack['data']['msg_id']);
    }

    public function testOfflineFlagMarksBackfillPush()
    {
        $session  = $this->makeReady($transport);
        $receiver = new PushReceiver($session);
        $got      = null;
        $receiver->onPush(function (array $payload, array $meta) use (&$got) {
            $got = $meta;
        });

        $transport->receive($this->pushPacket(array('offline' => 1)));

        self::assertSame(1, $got['offline'], 'offline=1 表示重连补投');
    }

    public function testPushWithoutReceiverCallbackStillAcks()
    {
        $session  = $this->makeReady($transport);
        $receiver = new PushReceiver($session); // 不注册业务回调

        $transport->receive($this->pushPacket());

        $ack = $transport->lastPacket();
        self::assertSame(Message::CMD_ACK, $ack['cmd']);
        self::assertSame(1, $receiver->ackedCount(), '未注册回调也应自动回执');
    }

    public function testSendAckSkippedWhenNotReady()
    {
        $this->makeTimers();
        $transport = new FakeTransport();
        $session   = new SessionManager(array(
            'uid'       => 'alice',
            'device_id' => 'dev1',
            'secret'    => 'test-secret',
            'heartbeat' => 0,
        ), $transport, null, $this->timerAdd, $this->timerDel);

        $receiver = new PushReceiver($session);
        $session->connect();

        // 未完成鉴权就收到 push（极端时序）：不应发送 ack、不应抛异常
        $transport->receive($this->pushPacket());

        foreach ($transport->sentPackets as $p) {
            self::assertNotSame(Message::CMD_ACK, $p['cmd']);
        }
        self::assertSame(0, $receiver->ackedCount());
    }
}
