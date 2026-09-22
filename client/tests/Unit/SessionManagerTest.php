<?php
/**
 * SessionManager 单测 —— 假传输层 + 假计时器，脱离 workerman 事件环境
 *
 * 覆盖 P1 验收点的纯逻辑部分：
 *   建连 → auth → ack → ready；ping → pong（RTT）；应答服务端反向 ping；
 *   业务请求（echo）结算；超时；断线重连退避；pending 断线失败；用户主动关闭。
 *
 * WsTransport 的真实链路行为由 P1 实测脚本（对运行中服务）验证，不在单测范围。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Tests\Unit;

use GatewayPush\Business\Message;
use GatewayPush\Client\Error\ClientException;
use GatewayPush\Client\Error\ErrorCode;
use GatewayPush\Client\Session\SessionManager;
use GatewayPush\Client\Tests\Support\FakeTimers;
use GatewayPush\Client\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class SessionManagerTest extends TestCase
{
    use FakeTimers;

    /* ---------------------------------------------------------------------
     | 配置与状态守卫
     --------------------------------------------------------------------- */

    public function testMissingRequiredConfigThrows()
    {
        try {
            $this->makeSession(['secret' => '']);
            self::fail('缺少 secret 必须抛 ClientException');
        } catch (ClientException $e) {
            self::assertSame(ErrorCode::CLIENT_CONFIG, $e->getCode());
        }
    }

    public function testInvalidWsUrlThrowsWhenTransportNotInjected()
    {
        try {
            // 不注入 transport，让 SessionManager 按默认逻辑构造 WsTransport
            new SessionManager([
                'uid'       => 'a',
                'device_id' => 'd',
                'secret'    => 's',
                'ws_url'    => 'http://127.0.0.1:8282',
            ]);
            self::fail('非 ws(s):// 的 ws_url 必须抛 ClientException');
        } catch (ClientException $e) {
            self::assertSame(ErrorCode::CLIENT_CONFIG, $e->getCode());
        }
    }

    public function testConnectTwiceThrowsState()
    {
        $session   = $this->makeSession([], $transport);
        $session->connect();

        try {
            $session->connect();
            self::fail('重复建连必须抛 ClientException');
        } catch (ClientException $e) {
            self::assertSame(ErrorCode::CLIENT_STATE, $e->getCode());
        }
    }

    public function testRequestBeforeReadyThrowsState()
    {
        $session = $this->makeSession([], $transport);

        try {
            $session->request('echo');
            self::fail('未鉴权发业务请求必须抛 ClientException');
        } catch (ClientException $e) {
            self::assertSame(ErrorCode::CLIENT_STATE, $e->getCode());
        }
    }

    /* ---------------------------------------------------------------------
     | 建连 → 鉴权 → ready
     --------------------------------------------------------------------- */

    public function testAutoAuthSendsAuthPacketOnOpen()
    {
        $session = $this->makeSession([], $transport);
        $session->connect();
        $transport->open();

        self::assertSame(SessionManager::STATE_AUTHENTICATING, $session->state());

        $auth = $transport->lastPacket();
        self::assertSame(Message::CMD_AUTH, $auth['cmd']);
        self::assertSame('alice', $auth['uid']);
        self::assertSame('dev1', $auth['device_id']);
        self::assertNotSame('', $auth['token']);
        self::assertSame('1', $auth['seq']);

        // token 载荷须携带同一身份（服务端据此识别 uid）
        $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], explode('.', $auth['token'])[0])), true);
        self::assertSame('alice', $payload['uid']);
        self::assertSame('dev1', $payload['device_id']);
    }

    public function testAuthAckEntersReadyAndSchedulesHeartbeat()
    {
        $states   = [];
        $session  = $this->makeSession([], $transport);
        $session->onStateChange(function ($new, $old) use (&$states) {
            $states[] = $new;
        });

        $this->connectAndReady($session, $transport);

        self::assertContains(SessionManager::STATE_READY, $states);
        self::assertCount(1, $this->persistentTimers(), 'ready 后应恰好一个心跳定时器');
        self::assertSame(20.0, $this->persistentTimers()[0]['interval']);
        self::assertSame(0, $session->stats()['reconnect_attempts']);
    }

    public function testManualAuthModeStaysConnectedAfterOpen()
    {
        $session = $this->makeSession(['auto_auth' => false], $transport);
        $cbOk    = null;
        $session->connect();
        $transport->open();

        self::assertSame(SessionManager::STATE_CONNECTED, $session->state());
        self::assertCount(0, $transport->sentPackets, '手动模式下不发 auth');

        $session->auth(function ($ok, $data) use (&$cbOk) {
            $cbOk = $ok;
        });
        self::assertSame(SessionManager::STATE_AUTHENTICATING, $session->state());

        $auth = $transport->lastPacket();
        $transport->receive([
            'cmd'  => Message::CMD_ACK,
            'seq'  => $auth['seq'],
            'ts'   => time(),
            'data' => ['uid' => 'alice', 'device_id' => 'dev1', 'protocol' => 'ws', 'reconnected' => 0],
        ]);

        self::assertTrue($session->isReady());
        self::assertTrue($cbOk);
    }

    public function testAuthErrorRejectsPendingAndFiresError()
    {
        $session   = $this->makeSession([], $transport);
        $errorCode = null;
        $cbResult  = null;
        $session->onError(function (ClientException $e) use (&$errorCode) {
            $errorCode = $e->getCode();
        });

        $session->connect();
        $transport->open();
        $auth = $transport->lastPacket();

        $transport->receive([
            'cmd'  => Message::CMD_ERROR,
            'seq'  => $auth['seq'],
            'ref'  => 'auth',
            'ts'   => time(),
            'data' => ['code' => ErrorCode::AUTH_FAILED, 'msg' => 'Token 无效'],
        ]);

        self::assertSame(ErrorCode::AUTH_FAILED, $errorCode);
        self::assertSame(0, $session->pendingCount());
        self::assertFalse($session->isReady());
    }

    /* ---------------------------------------------------------------------
     | 心跳与业务请求
     --------------------------------------------------------------------- */

    public function testPingPongSetsRtt()
    {
        $session = $this->makeSession([], $transport);
        $this->connectAndReady($session, $transport);

        $result = null;
        $session->ping(function ($ok, $data) use (&$result) {
            $result = [$ok, $data];
        });

        $ping = $transport->lastPacket();
        self::assertSame(Message::CMD_PING, $ping['cmd']);

        $transport->receive([
            'cmd'  => Message::CMD_PONG,
            'seq'  => $ping['seq'],
            'ts'   => time(),
            'data' => [],
        ]);

        self::assertTrue($result[0]);
        self::assertGreaterThanOrEqual(0.0, $session->lastRtt());
        self::assertSame(0, $session->pendingCount());
    }

    public function testServerReversePingIsAnsweredWithPong()
    {
        $session = $this->makeSession([], $transport);
        $this->connectAndReady($session, $transport);
        $sentBefore = count($transport->sentPackets);

        // 服务端网关反向心跳固定为 {"cmd":"ping","ts":0}（无 seq）
        $transport->receive(['cmd' => Message::CMD_PING, 'ts' => 0]);

        $reply = $transport->lastPacket();
        self::assertCount($sentBefore + 1, $transport->sentPackets, '反向心跳必须立即应答');
        self::assertSame(Message::CMD_PONG, $reply['cmd']);
        self::assertSame('', $reply['seq'], 'pong 应原样回传 ping 的 seq（空）');
        self::assertSame(0, $session->pendingCount(), '服务端 ping 不应干扰 pending 表');
    }

    public function testRequestBuildsDataPacketAndSettlesOnAck()
    {
        $session = $this->makeSession([], $transport);
        $this->connectAndReady($session, $transport);

        $result = null;
        $seq    = $session->request('echo', ['hello' => 'postman'], function ($ok, $data) use (&$result) {
            $result = [$ok, $data];
        });

        $req = $transport->lastPacket();
        self::assertSame(Message::CMD_DATA, $req['cmd']);
        self::assertSame($seq, $req['seq']);
        self::assertSame('echo', $req['data']['action']);
        self::assertSame(['hello' => 'postman'], $req['data']['params']);
        self::assertSame('alice', $req['uid']);
        self::assertNotSame('', $req['sign'], '业务报文应带签名（WS 不校验但无副作用）');
        self::assertSame(1, $session->pendingCount());

        $transport->receive([
            'cmd'  => Message::CMD_ACK,
            'seq'  => $seq,
            'ts'   => time(),
            'data' => ['action' => 'echo', 'echoed' => ['hello' => 'postman']],
        ]);

        self::assertTrue($result[0]);
        self::assertSame('echo', $result[1]['data']['action']);
        self::assertSame(0, $session->pendingCount());
    }

    public function testUnsolicitedAckIsIgnored()
    {
        $session = $this->makeSession([], $transport);
        $this->connectAndReady($session, $transport);

        // 服务端推送回执等无主 ack 不应触发任何 pending 或异常
        $transport->receive(['cmd' => Message::CMD_ACK, 'seq' => '999', 'ts' => time(), 'data' => []]);

        self::assertSame(0, $session->pendingCount());
        self::assertTrue($session->isReady());
    }

    public function testInvalidFrameFiresBadPacketError()
    {
        $session   = $this->makeSession([], $transport);
        $errorCode = null;
        $session->onError(function (ClientException $e) use (&$errorCode) {
            $errorCode = $e->getCode();
        });

        $this->connectAndReady($session, $transport);
        $transport->receiveRaw('not-json{{');

        self::assertSame(ErrorCode::BAD_PACKET, $errorCode);
        self::assertTrue($session->isReady(), '非法帧不应破坏会话状态');
    }

    public function testPushIsForwardedToCallback()
    {
        $session = $this->makeSession([], $transport);
        $pushed  = null;
        $session->onPush(function (array $packet) use (&$pushed) {
            $pushed = $packet;
        });

        $this->connectAndReady($session, $transport);

        $transport->receive([
            'cmd'       => Message::CMD_PUSH,
            'seq'       => 'm-1',
            'ts'        => time(),
            'data'      => ['title' => 'hi'],
            'msg_id'    => 'm-1',
            'source'    => 'cli',
            'offline'   => 0,
            'pushed_at' => time(),
        ]);

        self::assertNotNull($pushed);
        self::assertSame('m-1', $pushed['msg_id']);
    }

    /* ---------------------------------------------------------------------
     | UDP 通道（P3）：attach_token 与双层回执判别
     --------------------------------------------------------------------- */

    public function testTransportAckSettlesAuthAndPingButNotData()
    {
        $session = $this->makeSession(['heartbeat' => 0], $transport);

        // auth：UDP 网关对 auth 无业务层回执，传输层 ack（data 空且无 action）即结算
        $session->connect();
        $transport->open();
        $auth = $transport->lastPacket();
        $transport->receive([
            'cmd'  => Message::CMD_ACK,
            'seq'  => $auth['seq'],
            'ts'   => time(),
            'data' => [],
        ]);
        self::assertTrue($session->isReady(), '传输层 ack 应结算 auth');

        // data.echo：传输层 ack 不得结算，须等业务层回执（带 data.action）
        $settled = null;
        $seq     = $session->request('echo', ['k' => 'v'], function ($ok, $packet) use (&$settled) {
            $settled = $ok;
        });
        $transport->receive([
            'cmd'  => Message::CMD_ACK,
            'seq'  => $seq,
            'ts'   => time(),
            'data' => [],
        ]);
        self::assertNull($settled, '传输层 ack 不得结算业务请求（硬约束⑳）');
        self::assertSame(1, $session->pendingCount());

        $transport->receive([
            'cmd'  => Message::CMD_ACK,
            'seq'  => $seq,
            'ts'   => time(),
            'data' => ['action' => 'echo', 'echoed' => ['k' => 'v']],
        ]);
        self::assertTrue($settled);
        self::assertSame(0, $session->pendingCount());
    }

    public function testAttachTokenCarriesTokenOnOutgoingPackets()
    {
        $session = $this->makeSession(['heartbeat' => 0, 'attach_token' => true], $transport);
        $this->connectAndReady($session, $transport);

        $session->request('echo', [], null);
        $req = $transport->lastPacket();

        self::assertNotSame('', $req['token'], 'attach_token 开启时业务报文必须携带 Token（硬约束⑲）');
        self::assertSame(
            $transport->sentPackets[0]['token'],
            $req['token'],
            '业务报文 Token 须与 auth 报文一致'
        );

        // Token 参与签名：签名须随 Token 附加后重新计算（本地验签通过即证明顺序正确）
        $packet               = $req;
        $sign                 = $packet['sign'];
        $packet['sign']       = '';
        $expected             = \GatewayPush\Client\Protocol\Signer::sign($packet, 'test-secret');
        self::assertSame($expected, $sign);
    }

    public function testAttachTokenDisabledKeepsPacketsTokenFree()
    {
        $session = $this->makeSession(['heartbeat' => 0], $transport);
        $this->connectAndReady($session, $transport);

        $session->request('echo', [], null);
        $req = $transport->lastPacket();

        self::assertArrayNotHasKey('token', $req, '默认（WS）路径不应附加 Token');
    }

    /* ---------------------------------------------------------------------
     | 超时
     --------------------------------------------------------------------- */

    public function testRequestTimeoutFiresTimeoutError()
    {
        $session   = $this->makeSession(['timeout' => 0.5], $transport);
        $errorCode = null;
        $session->onError(function (ClientException $e) use (&$errorCode) {
            $errorCode = $e->getCode();
        });

        $this->connectAndReady($session, $transport);

        $result = null;
        $session->request('echo', [], function ($ok, $data) use (&$result) {
            $result = [$ok, $data];
        });

        // 最后登记的计时器即本请求的超时定时器（auth 的已随 ack 删除）
        $this->fireTimer($this->lastTimerId());

        self::assertNotNull($result);
        self::assertFalse($result[0]);
        self::assertSame(ErrorCode::CLIENT_TIMEOUT, $errorCode);
        self::assertSame(0, $session->pendingCount());
    }

    public function testHeartbeatDisabledWhenZero()
    {
        $session = $this->makeSession(['heartbeat' => 0], $transport);
        $this->connectAndReady($session, $transport);

        self::assertCount(0, $this->persistentTimers(), 'heartbeat=0 时不应有心跳定时器');
    }

    public function testHeartbeatSendsPingPeriodically()
    {
        $session = $this->makeSession(['heartbeat' => 20], $transport);
        $this->connectAndReady($session, $transport);

        $sentBefore = count($transport->sentPackets);
        $hb         = $this->persistentTimers()[0];
        // 触发心跳定时器（其 id 为计时器表中该持久定时器的序号）
        $id = 0;
        foreach ($this->timers as $idx => $t) {
            if ($t === $hb) {
                $id = $idx + 1;
            }
        }
        $this->fireTimer($id);

        self::assertCount($sentBefore + 1, $transport->sentPackets);
        self::assertSame(Message::CMD_PING, $transport->lastPacket()['cmd']);
    }

    /* ---------------------------------------------------------------------
     | 断线与重连
     --------------------------------------------------------------------- */

    public function testDisconnectFailsPendingAndSchedulesReconnect()
    {
        $session = $this->makeSession([], $transport);
        $this->connectAndReady($session, $transport);

        $result = null;
        $session->request('echo', [], function ($ok, $data) use (&$result) {
            $result = [$ok, $data];
        });

        $transport->drop();

        self::assertSame(SessionManager::STATE_RECONNECTING, $session->state());
        self::assertFalse($result[0], '断线时 pending 请求须以失败结算');
        self::assertSame(0, $session->pendingCount());
        self::assertSame(1, $session->stats()['reconnect_attempts']);

        $reconnectTimers = $this->nonPersistentTimers();
        self::assertCount(1, $reconnectTimers, '应有一个重连定时器');
        self::assertSame(1.0, $reconnectTimers[0]['interval'], '首次退避 = base');
    }

    public function testReconnectBackoffIsExponential()
    {
        $session = $this->makeSession([], $transport);
        $this->connectAndReady($session, $transport);

        // 第一轮：退避 1s
        $transport->drop();
        // 第二轮：退避 2s（进入 connecting → drop，再进 reconnecting）
        // 先触发重连定时器走回 connecting
        $this->fireTimer($this->lastTimerId());
        $transport->drop();

        self::assertSame(SessionManager::STATE_RECONNECTING, $session->state());
        self::assertSame(2, $session->stats()['reconnect_attempts']);
        $t = $this->nonPersistentTimers();
        self::assertCount(1, $t);
        self::assertSame(2.0, $t[0]['interval'], '第二次退避 = base * 2');
        self::assertSame(2, $transport->connectCalls, '重连定时器触发时再次建连');
    }

    public function testReconnectSuccessResetsAttempts()
    {
        $session = $this->makeSession([], $transport);
        $this->connectAndReady($session, $transport);

        $transport->drop();
        $this->fireTimer($this->lastTimerId()); // → connecting
        $transport->open();                     // auto_auth 再次发 auth
        $auth = $transport->lastPacket();
        $transport->receive([
            'cmd'  => Message::CMD_ACK,
            'seq'  => $auth['seq'],
            'ts'   => time(),
            'data' => ['uid' => 'alice', 'device_id' => 'dev1', 'protocol' => 'ws', 'reconnected' => 1],
        ]);

        self::assertTrue($session->isReady());
        self::assertSame(0, $session->stats()['reconnect_attempts'], '鉴权成功后重连计数清零');
    }

    public function testReconnectDisabledGoesDisconnected()
    {
        $session = $this->makeSession(['reconnect' => false], $transport);
        $errors  = [];
        $session->onError(function (ClientException $e) use (&$errors) {
            $errors[] = $e;
        });

        $this->connectAndReady($session, $transport);
        $transport->drop();

        self::assertSame(SessionManager::STATE_DISCONNECTED, $session->state());
        self::assertCount(0, $this->nonPersistentTimers(), '未启用重连不应有重连定时器');
        self::assertCount(1, $errors, '应通过 onError 告知断线');
        self::assertSame(ErrorCode::CLIENT_TRANSPORT, $errors[0]->getCode());
    }

    public function testUserCloseDoesNotReconnect()
    {
        $session = $this->makeSession([], $transport);
        $this->connectAndReady($session, $transport);

        $session->close();

        self::assertSame(SessionManager::STATE_DISCONNECTED, $session->state());
        self::assertSame(0, $session->stats()['reconnect_attempts']);
        self::assertCount(0, $this->nonPersistentTimers(), '用户关闭不应安排重连');
        self::assertCount(0, $this->persistentTimers(), '关闭后心跳定时器应已移除');
    }

    public function testUserCloseDuringReconnectCancelsReconnectTimer()
    {
        $session = $this->makeSession([], $transport);
        $this->connectAndReady($session, $transport);

        $transport->drop(); // → reconnecting，重连定时器挂起
        self::assertSame(SessionManager::STATE_RECONNECTING, $session->state());

        $session->close(); // 重连等待期关闭：取消定时器并直接落 disconnected

        self::assertSame(SessionManager::STATE_DISCONNECTED, $session->state());
        self::assertCount(0, $this->nonPersistentTimers(), '挂起的重连定时器应被取消');

        $callsBefore = $transport->connectCalls;
        $this->fireTimer(count($this->timers)); // 旧定时器已删除，不应再建连
        self::assertSame($callsBefore, $transport->connectCalls, '已取消的重连不得再触发建连');
    }

    public function testStatsSnapshotShape()
    {
        $session = $this->makeSession([], $transport);
        $stats   = $session->stats();

        self::assertSame('disconnected', $stats['state']);
        self::assertSame('alice', $stats['uid']);
        self::assertSame('dev1', $stats['device_id']);
        self::assertSame(0, $stats['pending']);
        self::assertArrayHasKey('last_rtt', $stats);
        self::assertArrayHasKey('reconnect_attempts', $stats);
    }

    private function makeSession(array $overrides = [], ?FakeTransport &$transport = null)
    {
        $this->makeTimers();

        $transport = new FakeTransport();
        $config    = array_merge([
            'uid'       => 'alice',
            'device_id' => 'dev1',
            'secret'    => 'test-secret',
        ], $overrides);

        return new SessionManager($config, $transport, null, $this->timerAdd, $this->timerDel);
    }

    /** 建连并完成鉴权，进入 ready */
    private function connectAndReady(SessionManager $session, FakeTransport $transport)
    {
        $session->connect();
        $transport->open(); // auto_auth 发出 auth

        $authPacket = $transport->lastPacket();
        self::assertSame(Message::CMD_AUTH, $authPacket['cmd']);

        $transport->receive([
            'cmd' => Message::CMD_ACK,
            'seq' => $authPacket['seq'],
            'ts'  => time(),
            'data' => [
                'uid'         => 'alice',
                'device_id'   => 'dev1',
                'protocol'    => 'ws',
                'reconnected' => 0,
            ],
        ]);
        self::assertTrue($session->isReady());
    }
}
