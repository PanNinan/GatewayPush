<?php
/**
 * HttpTransport 单测 —— 假连接 + 假计时器
 *
 * 覆盖 P4 验收点的纯逻辑部分：请求构造（方法/路径/头/体）、
 * Content-Length 精确读取、分片到达、无 Content-Length 的 close 兜底、
 * 超时、底层错误、一次性连接销毁。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Tests\Unit;

use GatewayPush\Client\Error\ClientException;
use GatewayPush\Client\Error\ErrorCode;
use GatewayPush\Client\Tests\Support\FakeHttpConnection;
use GatewayPush\Client\Tests\Support\FakeTimers;
use GatewayPush\Client\Transport\HttpTransport;
use PHPUnit\Framework\TestCase;

final class HttpTransportTest extends TestCase
{
    use FakeTimers;

    /** @var FakeHttpConnection */
    private $fake;

    private function makeTransport(&$fake = null)
    {
        $this->makeTimers();

        $fake       = new FakeHttpConnection();
        $this->fake = $fake;
        $captured   = &$fake;

        return new HttpTransport(
            'http://127.0.0.1:8290',
            5.0,
            function () use (&$captured) {
                return $captured;
            },
            $this->timerAdd,
            $this->timerDel
        );
    }

    public function testInvalidUrlThrowsConfig()
    {
        $this->makeTimers();
        try {
            new HttpTransport('ws://127.0.0.1:8290', 5.0, null, $this->timerAdd, $this->timerDel);
            self::fail('非 http:// 的 api_url 必须抛 ClientException');
        } catch (ClientException $e) {
            self::assertSame(ErrorCode::CLIENT_CONFIG, $e->getCode());
        }
    }

    public function testGetRequestShape()
    {
        $transport = $this->makeTransport($fake);
        $done      = null;
        $transport->request('GET', '/health', '', array('X-Sign' => 'abc'), function ($r) use (&$done) {
            $done = $r;
        });

        self::assertCount(1, $fake->sentRaw);
        $raw = $fake->sentRaw[0];
        self::assertStringStartsWith("GET /health HTTP/1.1\r\n", $raw);
        self::assertStringContainsString('Host: 127.0.0.1:8290', $raw);
        self::assertStringContainsString('Connection: close', $raw);
        self::assertStringContainsString('X-Sign: abc', $raw);
        self::assertStringNotContainsString('Content-Length', $raw, '无请求体时不应带 Content-Length');
    }

    public function testPostRequestShape()
    {
        $transport = $this->makeTransport($fake);
        $transport->request('POST', '/push', '{"k":"v"}', [], function () {
        });

        $raw = $fake->sentRaw[0];
        self::assertStringStartsWith("POST /push HTTP/1.1\r\n", $raw);
        self::assertStringContainsString('Content-Type: application/json; charset=utf-8', $raw);
        self::assertStringContainsString('Content-Length: 9', $raw);
        self::assertStringEndsWith("\r\n\r\n" . '{"k":"v"}', $raw);
    }

    public function testResponseParsedByContentLength()
    {
        $transport = $this->makeTransport($fake);
        $done      = null;
        $transport->request('GET', '/stats', '', [], function ($r) use (&$done) {
            $done = $r;
        });

        $body = '{"code":0,"msg":"ok"}';
        $fake->emit("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);

        self::assertNotNull($done, 'Content-Length 收齐即结算');
        self::assertSame(200, $done['status']);
        self::assertSame(0, $done['json']['code']);
        self::assertSame('', $done['error']);
        self::assertTrue($fake->destroyed, '一次性连接完成后销毁');
        foreach ($this->timers as $t) {
            self::assertTrue($t['deleted'], '完成后取消超时定时器');
        }
    }

    public function testChunkedArrivalWaitsForFullBody()
    {
        $transport = $this->makeTransport($fake);
        $done      = null;
        $transport->request('GET', '/stats', '', [], function ($r) use (&$done) {
            $done = $r;
        });

        $body = '{"code":0,"msg":"ok","data":{"a":1}}';
        $head = "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n";

        $fake->emit($head . substr($body, 0, 5));
        self::assertNull($done, '响应体未收齐不得结算');

        $fake->emit(substr($body, 5));
        self::assertNotNull($done);
        self::assertSame($body, $done['body']);
    }

    public function testCloseFallbackWithoutContentLength()
    {
        $transport = $this->makeTransport($fake);
        $done      = null;
        $transport->request('GET', '/health', '', [], function ($r) use (&$done) {
            $done = $r;
        });

        $fake->emit("HTTP/1.1 200 OK\r\nConnection: close\r\n\r\n" . '{"code":0}');
        $fake->emitClose();

        self::assertNotNull($done, '无 Content-Length 时以 close 为兜底完成信号');
        self::assertSame(200, $done['status']);
        self::assertSame(0, $done['json']['code']);
    }

    public function testTimeoutSettlesWithError()
    {
        $transport = $this->makeTransport($fake);
        $done      = null;
        $transport->request('GET', '/stats', '', [], function ($r) use (&$done) {
            $done = $r;
        });

        $this->fireTimer($this->lastTimerId());

        self::assertNotNull($done);
        self::assertSame(0, $done['status']);
        self::assertStringContainsString('超时', $done['error']);
        self::assertTrue($fake->destroyed);
    }

    public function testConnectionErrorSettlesWithError()
    {
        $transport = $this->makeTransport($fake);
        $done      = null;
        $transport->request('GET', '/stats', '', [], function ($r) use (&$done) {
            $done = $r;
        });

        $fake->emitError(111, 'connection refused');

        self::assertNotNull($done);
        self::assertSame(0, $done['status']);
        self::assertStringContainsString('connection refused', $done['error']);
    }

    public function testSettleOnlyOnce()
    {
        $transport = $this->makeTransport($fake);
        $count     = 0;
        $transport->request('GET', '/health', '', [], function () use (&$count) {
            $count++;
        });

        $body = '{"code":0}';
        $fake->emit("HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
        $fake->emitClose();
        $fake->emitError(1, 'late');

        self::assertSame(1, $count, '完成/关闭/错误叠加时只结算一次');
    }
}
