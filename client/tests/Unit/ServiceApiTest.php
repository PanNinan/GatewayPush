<?php
/**
 * Service 层单测 —— 6 个动作 API 的报文构造与回调包装
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Client\Tests\Unit;

use GatewayPush\Business\Message;
use GatewayPush\Client\Error\ErrorCode;
use GatewayPush\Client\Service\EchoApi;
use GatewayPush\Client\Service\NotifyApi;
use GatewayPush\Client\Service\ReportApi;
use GatewayPush\Client\Service\SessionApi;
use GatewayPush\Client\Service\SubscribeApi;
use GatewayPush\Client\Session\SessionManager;
use GatewayPush\Client\Tests\Support\FakeTimers;
use GatewayPush\Client\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class ServiceApiTest extends TestCase
{
    use FakeTimers;

    public function testEchoSendsParamsVerbatim()
    {
        $session = $this->makeReady($transport);
        $api     = new EchoApi($session);

        $result = null;
        $seq    = $api->send(['hello' => 'postman', 'n' => 1], function ($ok, $data, $error) use (&$result) {
            $result = [$ok, $data, $error];
        });

        $p = $this->lastDataPacket($transport);
        self::assertSame($seq, $p['seq']);
        self::assertSame('echo', $p['data']['action']);
        self::assertSame(['hello' => 'postman', 'n' => 1], $p['data']['params'], 'params=* 原样透传');
        self::assertSame('alice', $p['uid']);
        self::assertNotSame('', $p['sign']);

        $this->ackLast($transport, ['action' => 'echo', 'params' => ['hello' => 'postman', 'n' => 1], 'at' => 1]);
        self::assertTrue($result[0]);
        self::assertSame('echo', $result[1]['action']);
        self::assertNull($result[2]);
    }

    public function testSessionApiSendsNoParams()
    {
        $session = $this->makeReady($transport);
        $api     = new SessionApi($session);

        $api->get(null);

        $p = $this->lastDataPacket($transport);
        self::assertSame('session', $p['data']['action']);
        self::assertSame([], $p['data']['params']);
    }

    public function testReportBuildsTopicCountValue()
    {
        $session = $this->makeReady($transport);
        $api     = new ReportApi($session);

        $api->report('metric.cpu', 5, ['avg' => 0.8]);

        $p = $this->lastDataPacket($transport);
        self::assertSame('report', $p['data']['action']);
        self::assertSame('metric.cpu', $p['data']['params']['topic']);
        self::assertSame(5, $p['data']['params']['count']);
        self::assertSame(['avg' => 0.8], $p['data']['params']['value']);
    }

    public function testReportDefaultsCountToOne()
    {
        $session = $this->makeReady($transport);
        $api     = new ReportApi($session);

        $api->report('metric.mem');

        $p = $this->lastDataPacket($transport);
        self::assertSame(1, $p['data']['params']['count']);
        self::assertNull($p['data']['params']['value']);
    }

    public function testSubscribeUnsubscribeTopics()
    {
        $session = $this->makeReady($transport);
        $api     = new SubscribeApi($session);

        $api->subscribe('topic.a');
        $p = $this->lastDataPacket($transport);
        self::assertSame('subscribe', $p['data']['action']);
        self::assertSame('topic.a', $p['data']['params']['topic']);

        $this->ackLast($transport, ['action' => 'subscribe', 'subscribers' => 1]);
        $api->unsubscribe('topic.a');
        $p = $this->lastDataPacket($transport);
        self::assertSame('unsubscribe', $p['data']['action']);
        self::assertSame('topic.a', $p['data']['params']['topic']);

        $api->topics();
        $p = $this->lastDataPacket($transport);
        self::assertSame('topics', $p['data']['action']);
        self::assertSame([], $p['data']['params']);
    }

    public function testNotifyBuildsValueMsgIdOfflineMode()
    {
        $session = $this->makeReady($transport);
        $api     = new NotifyApi($session);

        $api->notify(['x' => 1], 'msg-001', 'queue');

        $p = $this->lastDataPacket($transport);
        self::assertSame('notify', $p['data']['action']);
        self::assertSame(['x' => 1], $p['data']['params']['value']);
        self::assertSame('msg-001', $p['data']['params']['msg_id']);
        self::assertSame('queue', $p['data']['params']['offline_mode']);
    }

    public function testNotifyDefaults()
    {
        $session = $this->makeReady($transport);
        $api     = new NotifyApi($session);

        $api->notify();

        $p = $this->lastDataPacket($transport);
        self::assertNull($p['data']['params']['value']);
        self::assertSame('', $p['data']['params']['msg_id']);
        self::assertSame('', $p['data']['params']['offline_mode']);
    }

    public function testServerErrorSurfacesInCallback()
    {
        $session = $this->makeReady($transport);
        $api     = new EchoApi($session);

        $result = null;
        $api->send(['x' => 1], function ($ok, $data, $error) use (&$result) {
            $result = [$ok, $data, $error];
        });

        $p = $transport->lastPacket();
        $transport->receive([
            'cmd'  => Message::CMD_ERROR,
            'seq'  => $p['seq'],
            'ref'  => 'data',
            'ts'   => time(),
            'data' => ['code' => ErrorCode::PARAM_MISSING, 'msg' => '参数错误'],
        ]);

        self::assertFalse($result[0]);
        self::assertSame(ErrorCode::PARAM_MISSING, $result[2]['code']);
        self::assertSame('参数错误', $result[2]['msg']);
        self::assertSame(ErrorCode::PARAM_MISSING, $result[1]['code']);
    }

    public function testLocalTimeoutSurfacesAsClientTimeoutError()
    {
        $session = $this->makeReady($transport, ['timeout' => 0.3]);
        $api     = new EchoApi($session);

        $result = null;
        $api->send(['x' => 1], function ($ok, $data, $error) use (&$result) {
            $result = [$ok, $data, $error];
        });

        $this->fireTimer($this->lastTimerId()); // 请求超时定时器

        self::assertFalse($result[0]);
        self::assertSame(ErrorCode::CLIENT_TIMEOUT, $result[2]['code']);
        self::assertNotSame('', $result[2]['msg'], '本地超时也应有完整 error 结构');
    }

    public function testRequestBeforeReadyThrows()
    {
        $this->makeTimers();
        $transport = new FakeTransport();
        $session   = new SessionManager([
            'uid'       => 'alice',
            'device_id' => 'dev1',
            'secret'    => 'test-secret',
            'heartbeat' => 0,
        ], $transport, null, $this->timerAdd, $this->timerDel);

        $api = new EchoApi($session);

        try {
            $api->send([]);
            self::fail('未就绪发请求必须抛 ClientException');
        } catch (\GatewayPush\Client\Error\ClientException $e) {
            self::assertSame(ErrorCode::CLIENT_STATE, $e->getCode());
        }
    }

    private function makeReady(?FakeTransport &$transport = null, array $overrides = [])
    {
        $this->makeTimers();

        $transport = new FakeTransport();
        $session   = new SessionManager(array_merge([
            'uid'       => 'alice',
            'device_id' => 'dev1',
            'secret'    => 'test-secret',
            'heartbeat' => 0,
        ], $overrides), $transport, null, $this->timerAdd, $this->timerDel);

        $session->connect();
        $transport->open();
        $auth = $transport->lastPacket();
        $transport->receive([
            'cmd'  => Message::CMD_ACK,
            'seq'  => $auth['seq'],
            'ts'   => time(),
            'data' => ['uid' => 'alice', 'device_id' => 'dev1', 'protocol' => 'ws', 'reconnected' => 0],
        ]);

        return $session;
    }

    private function lastDataPacket(FakeTransport $transport)
    {
        $p = $transport->lastPacket();
        self::assertSame(Message::CMD_DATA, $p['cmd'], '动作 API 必须发 cmd=data 报文');

        return $p;
    }

    private function ackLast(FakeTransport $transport, array $data)
    {
        $p = $transport->lastPacket();
        $transport->receive([
            'cmd'  => Message::CMD_ACK,
            'seq'  => $p['seq'],
            'ts'   => time(),
            'data' => $data,
        ]);
    }
}
