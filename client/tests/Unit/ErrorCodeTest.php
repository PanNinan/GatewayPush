<?php
/**
 * 错误层单元测试（客户端协议层 P0）
 *
 * 重点是把两个「同名不同义」的错误码空间钉死 ——
 * 服务端的**报文码**（WS/UDP）与**HTTP 业务码**数字重叠但语义不同：
 * `4004` 在报文里是「鉴权失败」，在 HTTP 里是「接口不存在」。
 * 混用会直接导致排障方向跑偏，因此这里用断言把它固化成文档。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Client\Tests\Unit;

use GatewayPush\Business\Message;
use GatewayPush\Client\Error\ClientException;
use GatewayPush\Client\Error\ErrorCode;
use PHPUnit\Framework\TestCase;

class ErrorCodeTest extends TestCase
{
    /* ---------------------------------------------------------------------
     | 报文码：与服务端 Message 同源
     --------------------------------------------------------------------- */

    public function testPacketCodesAreSameAsServerConstants(): void
    {
        self::assertSame(Message::CODE_OK, ErrorCode::OK);
        self::assertSame(Message::CODE_BAD_PACKET, ErrorCode::BAD_PACKET);
        self::assertSame(Message::CODE_BAD_SIGN, ErrorCode::BAD_SIGN);
        self::assertSame(Message::CODE_BAD_TIMESTAMP, ErrorCode::BAD_TIMESTAMP);
        self::assertSame(Message::CODE_UNAUTHORIZED, ErrorCode::UNAUTHORIZED);
        self::assertSame(Message::CODE_AUTH_FAILED, ErrorCode::AUTH_FAILED);
        self::assertSame(Message::CODE_TOKEN_EXPIRED, ErrorCode::TOKEN_EXPIRED);
        self::assertSame(Message::CODE_UNKNOWN_CMD, ErrorCode::UNKNOWN_CMD);
        self::assertSame(Message::CODE_PARAM_MISSING, ErrorCode::PARAM_MISSING);
        self::assertSame(Message::CODE_RATE_LIMIT, ErrorCode::RATE_LIMIT);
        self::assertSame(Message::CODE_SERVER_ERROR, ErrorCode::SERVER_ERROR);
    }

    public function testMessageMatchesServerText(): void
    {
        $codes = [
            ErrorCode::OK,
            ErrorCode::BAD_PACKET,
            ErrorCode::BAD_SIGN,
            ErrorCode::BAD_TIMESTAMP,
            ErrorCode::UNAUTHORIZED,
            ErrorCode::AUTH_FAILED,
            ErrorCode::TOKEN_EXPIRED,
            ErrorCode::UNKNOWN_CMD,
            ErrorCode::PARAM_MISSING,
            ErrorCode::RATE_LIMIT,
            ErrorCode::SERVER_ERROR,
        ];

        foreach ($codes as $code) {
            self::assertSame(
                Message::codeMessage($code),
                ErrorCode::message($code),
                '错误码 ' . $code . ' 的文案必须与服务端一致'
            );
        }
    }

    public function testMessageFallsBackForUnknownCode(): void
    {
        self::assertSame('未知错误', ErrorCode::message(99999));
    }

    /* ---------------------------------------------------------------------
     | HTTP 业务码：与报文码不共享语义
     --------------------------------------------------------------------- */

    public function testHttpAndPacketNamespacesOverlapButDifferInMeaning(): void
    {
        // 数字相同
        self::assertSame(4004, ErrorCode::HTTP_NOT_FOUND);
        self::assertSame(4004, ErrorCode::AUTH_FAILED);

        // 但 message() 给的是**报文侧**语义（鉴权失败）。
        // HTTP 侧的 4004 是「接口不存在」，其文案硬编码在 src/Api/Bootstrap.php，
        // 不在 Message::$codeMessages 里 —— 这正是不能拿 message() 解释 HTTP 响应码的原因。
        self::assertSame('鉴权失败', ErrorCode::message(ErrorCode::HTTP_NOT_FOUND));
    }

    public function testRateLimitCodesDifferBetweenNamespaces(): void
    {
        // 报文限流是 4008，HTTP 限流是 4029 —— 不可互换
        self::assertSame(4008, ErrorCode::RATE_LIMIT);
        self::assertSame(4029, ErrorCode::HTTP_RATE_LIMIT);
    }

    public function testHttpCodeSetMatchesServerApiBootstrap(): void
    {
        self::assertSame(0, ErrorCode::HTTP_OK);
        self::assertSame(4000, ErrorCode::HTTP_BAD_PARAM);
        self::assertSame(4001, ErrorCode::HTTP_BAD_SIGN);
        self::assertSame(4002, ErrorCode::HTTP_EXPIRED);
        self::assertSame(4004, ErrorCode::HTTP_NOT_FOUND);
        self::assertSame(4029, ErrorCode::HTTP_RATE_LIMIT);
        self::assertSame(5000, ErrorCode::HTTP_SERVER_ERROR);
    }

    /* ---------------------------------------------------------------------
     | 客户端本地码
     --------------------------------------------------------------------- */

    public function testIsLocal(): void
    {
        self::assertTrue(ErrorCode::isLocal(ErrorCode::CLIENT_TIMEOUT));
        self::assertTrue(ErrorCode::isLocal(ErrorCode::CLIENT_TRANSPORT));
        self::assertTrue(ErrorCode::isLocal(ErrorCode::CLIENT_CONFIG));
        self::assertTrue(ErrorCode::isLocal(ErrorCode::CLIENT_STATE));
        self::assertTrue(ErrorCode::isLocal(ErrorCode::CLIENT_INTERNAL));

        self::assertFalse(ErrorCode::isLocal(ErrorCode::SERVER_ERROR));
        self::assertFalse(ErrorCode::isLocal(ErrorCode::HTTP_RATE_LIMIT));
        self::assertFalse(ErrorCode::isLocal(ErrorCode::OK));
    }

    public function testLocalMessage(): void
    {
        self::assertSame('请求超时', ErrorCode::localMessage(ErrorCode::CLIENT_TIMEOUT));
        self::assertSame('传输层错误', ErrorCode::localMessage(ErrorCode::CLIENT_TRANSPORT));
        self::assertSame('客户端配置非法', ErrorCode::localMessage(ErrorCode::CLIENT_CONFIG));
        self::assertSame('客户端状态非法', ErrorCode::localMessage(ErrorCode::CLIENT_STATE));
        self::assertSame('客户端内部错误', ErrorCode::localMessage(ErrorCode::CLIENT_INTERNAL));
        self::assertSame('未知客户端错误', ErrorCode::localMessage(999));
    }

    public function testLocalCodesDoNotCollideWithServerCodes(): void
    {
        $candidates = [
            ErrorCode::CLIENT_TIMEOUT,
            ErrorCode::CLIENT_TRANSPORT,
            ErrorCode::CLIENT_CONFIG,
            ErrorCode::CLIENT_STATE,
            ErrorCode::CLIENT_INTERNAL,
        ];

        foreach ($candidates as $code) {
            self::assertGreaterThanOrEqual(10000, $code);
            self::assertSame('未知错误', ErrorCode::message($code));
        }
    }

    /* ---------------------------------------------------------------------
     | ClientException
     --------------------------------------------------------------------- */

    public function testFromPacketExtractsCodeMessageAndRef(): void
    {
        $packet = Message::error(ErrorCode::UNAUTHORIZED, '', 'seq-9', 'data');
        $e      = ClientException::fromPacket($packet);

        self::assertSame(ErrorCode::UNAUTHORIZED, $e->getCode());
        self::assertStringContainsString('连接未鉴权', $e->getMessage());
        self::assertStringContainsString('来源指令：data', $e->getMessage());
        self::assertSame($packet, $e->packet());
    }

    public function testFromPacketKeepsCustomMessage(): void
    {
        $e = ClientException::fromPacket(Message::error(ErrorCode::BAD_SIGN, '自定义原因'));

        self::assertSame('自定义原因', $e->getMessage());
    }

    public function testFromPacketFallsBackToServerError(): void
    {
        $packet = ['cmd' => 'error', 'data' => []];
        $e      = ClientException::fromPacket($packet);

        self::assertSame(ErrorCode::SERVER_ERROR, $e->getCode());
        self::assertSame(ErrorCode::message(ErrorCode::SERVER_ERROR), $e->getMessage());
        // 原始报文原样保留（未做任何改写），便于调用方/日志回溯
        self::assertSame($packet, $e->packet());
    }

    public function testFromPacketToleratesNonArrayData(): void
    {
        $e = ClientException::fromPacket(['cmd' => 'error', 'data' => 'raw']);

        self::assertSame(ErrorCode::SERVER_ERROR, $e->getCode());
    }

    public function testTimeoutCarriesContext(): void
    {
        $e = ClientException::timeout('data.echo', 'seq-7', 1.234);

        self::assertSame(ErrorCode::CLIENT_TIMEOUT, $e->getCode());
        self::assertStringContainsString('data.echo', $e->getMessage());
        self::assertStringContainsString('seq-7', $e->getMessage());
        self::assertStringContainsString('1.23s', $e->getMessage());
        self::assertSame([], $e->packet());
    }

    public function testTimeoutWithoutContext(): void
    {
        $e = ClientException::timeout('auth');

        self::assertSame('请求超时：auth', $e->getMessage());
    }

    public function testTransportConfigStateInternalFactories(): void
    {
        self::assertSame(ErrorCode::CLIENT_TRANSPORT, ClientException::transport('socket closed')->getCode());
        self::assertSame(ErrorCode::CLIENT_CONFIG, ClientException::config('bad url')->getCode());
        self::assertSame(ErrorCode::CLIENT_STATE, ClientException::state('not connected')->getCode());
        self::assertSame(ErrorCode::CLIENT_INTERNAL, ClientException::internal('boom')->getCode());
    }

    public function testExceptionIsRuntimeExceptionAndKeepsPrevious(): void
    {
        $previous = new \RuntimeException('底层失败');
        $e        = ClientException::internal('包装后', $previous);

        self::assertInstanceOf(\RuntimeException::class, $e);
        self::assertSame($previous, $e->getPrevious());
    }
}
