<?php
/**
 * AdminApi 单测 —— 假 HTTP 传输
 *
 * 覆盖 P4 验收点的纯逻辑部分：
 *   验签头构造（X-Timestamp / X-Sign = hex(hmac("{ts}|{rawBody}"))，含 GET 空体）；
 *   push 请求体形状；health/stats/push 三接口的响应归一化
 *   （2xx + code=0 → ok；401/业务码非 0 → ok=false + error 三元组；
 *   非 JSON 响应与传输失败 → 客户端本地码）。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Tests\Unit;

use GatewayPush\Client\Error\ErrorCode;
use GatewayPush\Client\Service\AdminApi;
use GatewayPush\Client\Tests\Support\FakeHttpConnection;
use GatewayPush\Client\Tests\Support\FakeTimers;
use GatewayPush\Client\Transport\HttpTransport;
use PHPUnit\Framework\TestCase;

final class AdminApiTest extends TestCase
{
    use FakeTimers;

    const API_SECRET = 'api-secret-0123456789abcdef';

    /** @var FakeHttpConnection */
    private $fake;

    private function makeApi(&$fake = null)
    {
        $this->makeTimers();

        $fake       = new FakeHttpConnection();
        $this->fake = $fake;
        $captured   = &$fake;

        $transport = new HttpTransport(
            'http://127.0.0.1:8290',
            5.0,
            function () use (&$captured) {
                return $captured;
            },
            $this->timerAdd,
            $this->timerDel
        );

        return new AdminApi('http://127.0.0.1:8290', self::API_SECRET, 5.0, $transport);
    }

    /**
     * 解析假连接上的原始 HTTP 请求
     *
     * @return array {method, path, headers:array, body:string}
     */
    private function parseRequest()
    {
        $raw  = $this->fake->sentRaw[0];
        $head = substr($raw, 0, strpos($raw, "\r\n\r\n"));
        $body = substr($raw, strpos($raw, "\r\n\r\n") + 4);

        $lines   = explode("\r\n", $head);
        $reqLine = array_shift($lines);
        $headers = array();
        foreach ($lines as $line) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $headers[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
            }
        }

        return array(
            'method'  => substr($reqLine, 0, strpos($reqLine, ' ')),
            'path'    => substr($reqLine, strlen(explode(' ', $reqLine)[0]) + 1, strrpos($reqLine, ' ') - strlen(explode(' ', $reqLine)[0]) - 1),
            'headers' => $headers,
            'body'    => $body,
        );
    }

    private function respond($status, $body)
    {
        $reason = $status === 200 ? 'OK' : 'Error';
        $this->fake->emit("HTTP/1.1 {$status} {$reason}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
    }

    public function testHealthCarriesValidSignOverEmptyBody()
    {
        $api = $this->makeApi($fake);
        $api->health(function () {
        });

        $req = $this->parseRequest();
        self::assertSame('GET', $req['method']);
        self::assertSame('/health', $req['path']);
        self::assertSame('', $req['body']);
        self::assertArrayHasKey('X-Timestamp', $req['headers']);
        self::assertArrayHasKey('X-Sign', $req['headers']);

        $expected = hash_hmac('sha256', $req['headers']['X-Timestamp'] . '|' . '', self::API_SECRET);
        self::assertSame($expected, $req['headers']['X-Sign'], 'GET 空请求体的签名基串须为 "{ts}|"');
    }

    public function testPushBuildsJobAndSignsRawBody()
    {
        $api = $this->makeApi($fake);
        $api->push('uid', 'alice', array('title' => 'hi'), array('msg_id' => 'm-1', 'offline_mode' => 'queue'), function () {
        });

        $req = $this->parseRequest();
        self::assertSame('POST', $req['method']);
        self::assertSame('/push', $req['path']);

        $job = json_decode($req['body'], true);
        self::assertSame('uid', $job['target_type']);
        self::assertSame('alice', $job['target']);
        self::assertSame(array('title' => 'hi'), $job['payload']);
        self::assertSame('m-1', $job['msg_id']);
        self::assertSame('queue', $job['offline_mode']);

        // 验签以「原始请求体」参与：服务端用 rawBody 验签，客户端须同样对原始串签名
        $expected = hash_hmac('sha256', $req['headers']['X-Timestamp'] . '|' . $req['body'], self::API_SECRET);
        self::assertSame($expected, $req['headers']['X-Sign']);
    }

    public function testStatsSuccessTriplet()
    {
        $api = $this->makeApi($fake);
        $out = null;
        $api->stats(function ($ok, $data, $error) use (&$out) {
            $out = array($ok, $data, $error);
        });

        $snapshot = array('gauge' => array('conn_total' => 3), 'counter' => array('msg_in' => 10));
        $this->respond(200, json_encode(array('code' => 0, 'msg' => 'ok', 'ts' => time(), 'data' => $snapshot)));

        self::assertTrue($out[0]);
        self::assertSame($snapshot, $out[1]);
        self::assertNull($out[2]);
    }

    public function testBadSignResponds401Triplet()
    {
        $api = $this->makeApi($fake);
        $out = null;
        $api->stats(function ($ok, $data, $error) use (&$out) {
            $out = array($ok, $data, $error);
        });

        $this->respond(401, json_encode(array('code' => 4001, 'msg' => '签名校验失败', 'ts' => time())));

        self::assertFalse($out[0]);
        self::assertSame(401, $out[2]['status']);
        self::assertSame(ErrorCode::HTTP_BAD_SIGN, $out[2]['code']);
        self::assertSame('签名校验失败', $out[2]['msg']);
    }

    public function testBusinessCodeNonZeroFailsEvenOn200()
    {
        $api = $this->makeApi($fake);
        $out = null;
        $api->push('uid', 'nobody', array(), array(), function ($ok, $data, $error) use (&$out) {
            $out = array($ok, $data, $error);
        });

        $this->respond(200, json_encode(array('code' => 4000, 'msg' => 'target_type 必须是 uid / device / client 之一')));

        self::assertFalse($out[0], 'HTTP 200 但业务码非 0 须判定失败');
        self::assertSame(ErrorCode::HTTP_BAD_PARAM, $out[2]['code']);
    }

    public function testNonJsonResponseFailsWithServerError()
    {
        $api = $this->makeApi($fake);
        $out = null;
        $api->health(function ($ok, $data, $error) use (&$out) {
            $out = array($ok, $data, $error);
        });

        $this->respond(502, '<html>Bad Gateway</html>');

        self::assertFalse($out[0]);
        self::assertSame(ErrorCode::HTTP_SERVER_ERROR, $out[2]['code']);
        self::assertSame(502, $out[2]['status']);
    }

    public function testTransportFailureUsesLocalCode()
    {
        $api = $this->makeApi($fake);
        $out = null;
        $api->stats(function ($ok, $data, $error) use (&$out) {
            $out = array($ok, $data, $error);
        });

        $this->fake->emitError(111, 'connection refused');

        self::assertFalse($out[0]);
        self::assertSame(ErrorCode::CLIENT_TRANSPORT, $out[2]['code']);
        self::assertStringContainsString('connection refused', $out[2]['msg']);
    }

    public function testEmptySecretStillSendsRequest()
    {
        $this->makeTimers();

        $fake      = new FakeHttpConnection();
        $this->fake = $fake;
        $captured  = &$fake;
        $transport = new HttpTransport('http://127.0.0.1:8290', 5.0, function () use (&$captured) {
            return $captured;
        }, $this->timerAdd, $this->timerDel);

        $api = new AdminApi('http://127.0.0.1:8290', '', 5.0, $transport);
        $out = null;
        $api->health(function ($ok, $data, $error) use (&$out) {
            $out = array($ok, $data, $error);
        });

        $req = $this->parseRequest();
        self::assertSame('', $req['headers']['X-Sign'], '密钥为空时签名头为空串（服务端以 500 拒绝）');

        $this->respond(500, json_encode(array('code' => 5000, 'msg' => '服务端未配置接口密钥')));
        self::assertFalse($out[0]);
        self::assertSame(ErrorCode::HTTP_SERVER_ERROR, $out[2]['code']);
    }
}
