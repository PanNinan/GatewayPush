<?php
/**
 * Codec 单元测试（客户端协议层 P0）
 *
 * 核心目标是把「客户端编解码 == 服务端编解码」钉死：
 * `Codec` 不重写任何 JSON 逻辑，全部转发服务端 `Message`，
 * 因此这里既断言委派关系，也用固定报文锁定逐字节输出。
 *
 * 另覆盖三个易错点：
 *   - `data` 为标量时被包装成 `{"value": ...}`；
 *   - 业务信封 `data.action` / `data.params` 的解析容错；
 *   - **UDP 双层回执**的判别（传输层 ack 无 `action`，业务层有）。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Tests\Unit;

use GatewayPush\Business\Message;
use GatewayPush\Client\Error\ErrorCode;
use GatewayPush\Client\Protocol\Codec;
use PHPUnit\Framework\TestCase;

class CodecTest extends TestCase
{
    /* ---------------------------------------------------------------------
     | encode
     --------------------------------------------------------------------- */

    public function testEncodeMatchesGoldenVector(): void
    {
        self::assertSame('{"k":"中文"}', Codec::encode(array('k' => '中文')));
    }

    public function testEncodeDelegatesToServerImplementation(): void
    {
        $packet = array(
            'cmd'  => 'push',
            'seq'  => 'p1',
            'data' => array('z' => 1, 'a' => array('url' => 'http://a/b', 't' => '中文')),
        );

        self::assertSame(Message::encode($packet), Codec::encode($packet));
    }

    public function testEncodeKeepsSlashesAndUnicode(): void
    {
        self::assertSame(
            '{"url":"http://127.0.0.1/a/b","note":"中文/路径"}',
            Codec::encode(array('url' => 'http://127.0.0.1/a/b', 'note' => '中文/路径'))
        );
    }

    public function testEncodeNonArrayProducesErrorPacket(): void
    {
        // 非数组入参不抛异常，而是回落成 error 报文 —— 与服务端一致
        $json = Codec::encode('not-an-array');

        self::assertStringContainsString('"cmd":"error"', $json);
        self::assertStringContainsString((string)ErrorCode::BAD_PACKET, $json);
    }

    /* ---------------------------------------------------------------------
     | decode
     --------------------------------------------------------------------- */

    public function testDecodeDelegatesToServerImplementation(): void
    {
        $raw = '{"cmd":"data","seq":"7","data":{"action":"echo"}}';

        self::assertSame(Message::decode($raw), Codec::decode($raw));
    }

    public function testDecodeNormalizesMissingFields(): void
    {
        $packet = Codec::decode('{"cmd":"ping"}');

        self::assertIsArray($packet);
        self::assertSame('ping', $packet['cmd']);
        self::assertSame('', $packet['seq']);
        self::assertSame(0, $packet['ts']);
        self::assertSame('', $packet['uid']);
        self::assertSame('', $packet['device_id']);
        self::assertSame('', $packet['token']);
        self::assertSame('', $packet['sign']);
        self::assertSame([], $packet['data']);
    }

    public function testDecodeWrapsScalarData(): void
    {
        $packet = Codec::decode('{"cmd":"data","data":"raw"}');

        self::assertSame(array('value' => 'raw'), $packet['data']);
    }

    public function testDecodeRejectsEmptyPacket(): void
    {
        $error = '';

        self::assertNull(Codec::decode('', $error));
        self::assertSame('空报文', $error);
    }

    public function testDecodeRejectsOversizedPacket(): void
    {
        $error = '';

        self::assertNull(Codec::decode(str_repeat('x', Message::MAX_PACKET_SIZE + 1), $error));
        self::assertSame('报文长度超限', $error);
    }

    public function testDecodeRejectsMalformedJson(): void
    {
        $error = '';

        self::assertNull(Codec::decode('{oops', $error));
        self::assertStringContainsString('JSON 解析失败', $error);
    }

    public function testDecodeRejectsNonObjectBody(): void
    {
        $error = '';

        self::assertNull(Codec::decode('123', $error));
        self::assertSame('报文主体必须是 JSON 对象', $error);
    }

    public function testDecodeRequiresNonEmptyStringCmd(): void
    {
        $error = '';

        self::assertNull(Codec::decode('{}', $error));
        self::assertSame('缺少 cmd 字段', $error);

        self::assertNull(Codec::decode('{"cmd":123}', $error));
        self::assertSame('缺少 cmd 字段', $error);
    }

    /* ---------------------------------------------------------------------
     | 报文构造
     --------------------------------------------------------------------- */

    public function testPacketSetsCmdTsAndEmptySeq(): void
    {
        $packet = Codec::packet('ping', array('x' => 1));

        self::assertSame('ping', $packet['cmd']);
        self::assertSame('', $packet['seq']);
        self::assertSame(array('x' => 1), $packet['data']);
        self::assertGreaterThan(0, $packet['ts']);
    }

    public function testPacketAppliesExtraFields(): void
    {
        $packet = Codec::packet('auth', [], array('uid' => 'u1', 'ts' => 123));

        self::assertSame('u1', $packet['uid']);
        self::assertSame(123, $packet['ts']);
    }

    public function testAckCastsSeqToString(): void
    {
        $ack = Codec::ack(12345, array('ok' => 1));

        self::assertSame('ack', $ack['cmd']);
        self::assertSame('12345', $ack['seq']);
        self::assertSame(array('ok' => 1), $ack['data']);
    }

    public function testErrorUsesDefaultMessageAndCarriesRef(): void
    {
        $error = Codec::error(ErrorCode::UNAUTHORIZED, '', 's9', 'data');

        self::assertSame('error', $error['cmd']);
        self::assertSame(ErrorCode::UNAUTHORIZED, $error['data']['code']);
        self::assertSame('连接未鉴权', $error['data']['msg']);
        self::assertSame('s9', $error['seq']);
        self::assertSame('data', $error['ref']);
    }

    public function testDataPacketShape(): void
    {
        $packet = Codec::dataPacket('echo', array('hello' => 'postman'));

        self::assertSame('data', $packet['cmd']);
        self::assertSame(array('action' => 'echo', 'params' => array('hello' => 'postman')), $packet['data']);
        self::assertSame(
            array('cmd', 'seq', 'ts', 'data'),
            array_keys($packet)
        );
    }

    public function testDataPacketDefaultsParamsToEmptyArray(): void
    {
        self::assertSame(array('action' => 'session', 'params' => array()), Codec::dataPacket('session')['data']);
    }

    /* ---------------------------------------------------------------------
     | 业务信封解析
     --------------------------------------------------------------------- */

    public function testActionOfAndParamsOf(): void
    {
        $packet = Codec::dataPacket('subscribe', array('topic' => 't.a'));

        self::assertSame('subscribe', Codec::actionOf($packet));
        self::assertSame(array('topic' => 't.a'), Codec::paramsOf($packet));
    }

    public function testActionOfReturnsEmptyOnMalformedInput(): void
    {
        self::assertSame('', Codec::actionOf(array()));
        self::assertSame('', Codec::actionOf(array('data' => 'raw')));
        self::assertSame('', Codec::actionOf(array('data' => array())));
        self::assertSame('', Codec::actionOf(array('data' => array('action' => 123))));
    }

    public function testParamsOfReturnsEmptyOnMalformedInput(): void
    {
        self::assertSame([], Codec::paramsOf(array()));
        self::assertSame([], Codec::paramsOf(array('data' => array('action' => 'echo'))));
        self::assertSame([], Codec::paramsOf(array('data' => array('params' => 'raw'))));
    }

    /* ---------------------------------------------------------------------
     | UDP 双层回执判别
     --------------------------------------------------------------------- */

    public function testIsTransportAckOnEmptyDataAck(): void
    {
        // 网关收包即回的传输层 ack：data 为空且无 action
        $ack = array('cmd' => 'ack', 'seq' => '1', 'ts' => 1700000000, 'data' => array());

        self::assertTrue(Codec::isTransportAck($ack));
    }

    public function testIsTransportAckFalseForBusinessReply(): void
    {
        $reply = array(
            'cmd'  => 'ack',
            'seq'  => '1',
            'ts'   => 1700000000,
            'data' => array('action' => 'echo', 'ok' => true),
        );

        self::assertFalse(Codec::isTransportAck($reply));
    }

    public function testIsTransportAckFalseForNonAckPacket(): void
    {
        self::assertFalse(Codec::isTransportAck(array('cmd' => 'push', 'data' => array())));
        self::assertFalse(Codec::isTransportAck(array('cmd' => 'error', 'data' => array('code' => 4003))));
    }
}
