<?php
/**
 * Message 单元测试
 *
 * 重点覆盖三类「契约级」行为：
 *   1. canonicalize —— 递归键名升序 + 紧凑 JSON，保证 data 键序不影响签名；
 *   2. sign —— 签名基串必须与文档一致，且**不含 uid**（安全前提，见下）；
 *   3. decode —— 结构校验与字段归一化。
 *
 * 关于 uid 不参与签名：签名基串为
 *   cmd|seq|ts|device_id|token|canonicalize(data)
 * uid 不在其中，意味着报文字段 uid 可被客户端篡改；UDP 侧身份只能取自
 * token 载荷（服务端密钥 HMAC 保护）。此处用测试把该事实固定下来，
 * 避免后续有人误以为 uid 已受签名保护而放宽校验。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Tests\Unit;

use GatewayPush\Business\Message;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase
{
    /* ---------------------------------------------------------------------
     | canonicalize
     --------------------------------------------------------------------- */

    public function testCanonicalizeSortsKeysAscending(): void
    {
        self::assertSame('{"a":2,"b":1}', Message::canonicalize(array('b' => 1, 'a' => 2)));
    }

    public function testCanonicalizeIsRecursive(): void
    {
        self::assertSame(
            '{"a":1,"z":{"a":1,"b":2}}',
            Message::canonicalize(array('z' => array('b' => 2, 'a' => 1), 'a' => 1))
        );
    }

    public function testCanonicalizeIsKeyOrderIndependent(): void
    {
        $one = Message::canonicalize(array('a' => 1, 'b' => array('x' => 1, 'y' => 2)));
        $two = Message::canonicalize(array('b' => array('y' => 2, 'x' => 1), 'a' => 1));

        self::assertSame($one, $two);
    }

    public function testCanonicalizeIsCompactAndKeepsUnicode(): void
    {
        self::assertSame('{"k":"中文"}', Message::canonicalize(array('k' => '中文')));
    }

    public function testCanonicalizeHandlesEmptyArrayAndScalars(): void
    {
        self::assertSame('[]', Message::canonicalize(array()));
        self::assertSame('x', Message::canonicalize('x'));
        self::assertSame('5', Message::canonicalize(5));
    }

    /* ---------------------------------------------------------------------
     | sign
     --------------------------------------------------------------------- */

    public function testSignFollowsDocumentedBaseString(): void
    {
        $data   = array('b' => 2, 'a' => 1);
        $packet = array(
            'cmd'       => 'data',
            'seq'       => 's1',
            'ts'        => 1690000000,
            'device_id' => 'dev1',
            'token'     => 'tk',
            'data'      => $data,
        );

        $base = 'data|s1|1690000000|dev1|tk|' . Message::canonicalize($data);

        self::assertSame(hash_hmac('sha256', $base, 'secret'), Message::sign($packet, 'secret'));
    }

    public function testSignIgnoresUid(): void
    {
        $base = array(
            'cmd'       => 'data',
            'seq'       => 's1',
            'ts'        => 1690000000,
            'device_id' => 'dev1',
            'token'     => 'tk',
            'data'      => array(),
        );

        $noUid  = $base;
        $alice  = $base + array('uid' => 'alice');
        $bob    = $base + array('uid' => 'bob');

        self::assertSame(Message::sign($noUid, 'secret'), Message::sign($alice, 'secret'));
        self::assertSame(Message::sign($alice, 'secret'), Message::sign($bob, 'secret'));
    }

    public function testSignIsDeterministicAndSecretDependent(): void
    {
        $packet = array(
            'cmd' => 'ping', 'seq' => '1', 'ts' => 1,
            'device_id' => 'd', 'token' => 't', 'data' => array(),
        );

        self::assertSame(Message::sign($packet, 'a'), Message::sign($packet, 'a'));
        self::assertNotSame(Message::sign($packet, 'a'), Message::sign($packet, 'b'));
        self::assertSame(64, strlen(Message::sign($packet, 'a')));
    }

    public function testSignDependsOnToken(): void
    {
        // token 参与签名是 UDP 身份可信的基础，此处固定该事实
        $one = array('cmd' => 'data', 'seq' => '', 'ts' => 1, 'device_id' => '', 'token' => 'tk1', 'data' => array());
        $two = array('cmd' => 'data', 'seq' => '', 'ts' => 1, 'device_id' => '', 'token' => 'tk2', 'data' => array());

        self::assertNotSame(Message::sign($one, 's'), Message::sign($two, 's'));
    }

    /* ---------------------------------------------------------------------
     | encode / decode
     --------------------------------------------------------------------- */

    public function testEncodeKeepsUnicodeUnescaped(): void
    {
        self::assertSame('{"k":"中文"}', Message::encode(array('k' => '中文')));
    }

    public function testEncodeNonArrayProducesErrorPacket(): void
    {
        $json = Message::encode('not-an-array');

        self::assertIsString($json);
        self::assertStringContainsString(Message::CMD_ERROR, $json);
    }

    public function testDecodeRejectsEmptyOversizedAndMalformed(): void
    {
        $error = '';

        self::assertNull(Message::decode('', $error));
        self::assertSame('空报文', $error);

        self::assertNull(Message::decode(str_repeat('x', Message::MAX_PACKET_SIZE + 1), $error));
        self::assertSame('报文长度超限', $error);

        self::assertNull(Message::decode('{oops', $error));
        self::assertStringContainsString('JSON 解析失败', $error);

        self::assertNull(Message::decode('123', $error));
        self::assertSame('报文主体必须是 JSON 对象', $error);
    }

    public function testDecodeRequiresNonEmptyCmd(): void
    {
        $error = '';

        self::assertNull(Message::decode('{}', $error));
        self::assertSame('缺少 cmd 字段', $error);

        self::assertNull(Message::decode('{"cmd":""}', $error));
        self::assertSame('缺少 cmd 字段', $error);

        self::assertNull(Message::decode('{"cmd":123}', $error));
        self::assertSame('缺少 cmd 字段', $error);
    }

    public function testDecodeNormalizesMissingFields(): void
    {
        $packet = Message::decode('{"cmd":"data"}');

        self::assertIsArray($packet);
        self::assertSame('data', $packet['cmd']);
        self::assertSame('', $packet['seq']);
        self::assertSame(0, $packet['ts']);
        self::assertSame('', $packet['uid']);
        self::assertSame('', $packet['device_id']);
        self::assertSame('', $packet['token']);
        self::assertSame('', $packet['sign']);
        self::assertSame(array(), $packet['data']);
    }

    public function testDecodeWrapsScalarData(): void
    {
        $packet = Message::decode('{"cmd":"data","data":"raw"}');

        self::assertSame(array('value' => 'raw'), $packet['data']);
    }

    /* ---------------------------------------------------------------------
     | 报文构造
     --------------------------------------------------------------------- */

    public function testAckCastsSeqToString(): void
    {
        $ack = Message::ack(12345, array('ok' => 1));

        self::assertSame(Message::CMD_ACK, $ack['cmd']);
        self::assertSame('12345', $ack['seq']);
        self::assertSame(array('ok' => 1), $ack['data']);
    }

    public function testErrorUsesDefaultMessage(): void
    {
        $error = Message::error(Message::CODE_BAD_SIGN);

        self::assertSame(Message::CMD_ERROR, $error['cmd']);
        self::assertSame(Message::CODE_BAD_SIGN, $error['data']['code']);
        self::assertSame('签名校验失败', $error['data']['msg']);
    }

    public function testErrorAcceptsCustomMessageAndContext(): void
    {
        $error = Message::error(Message::CODE_BAD_SIGN, '自定义原因', 's9', 'data');

        self::assertSame('自定义原因', $error['data']['msg']);
        self::assertSame('s9', $error['seq']);
        self::assertSame('data', $error['ref']);
    }

    public function testCodeMessageFallsBackForUnknownCode(): void
    {
        self::assertSame('未知错误', Message::codeMessage(99999));
    }

    /* ---------------------------------------------------------------------
     | verify
     --------------------------------------------------------------------- */

    public function testVerifyAcceptsCorrectSignature(): void
    {
        $config = array('sign_enable' => true, 'secret' => 'sec', 'clock_skew' => 300);
        $packet = array(
            'cmd' => 'data', 'seq' => '1', 'ts' => time(),
            'device_id' => 'd', 'token' => 't', 'data' => array(),
        );
        $packet['sign'] = Message::sign($packet, 'sec');

        self::assertTrue(Message::verify($packet, $config)['ok']);
    }

    public function testVerifyRejectsTamperedData(): void
    {
        $config = array('sign_enable' => true, 'secret' => 'sec', 'clock_skew' => 300);
        $packet = array(
            'cmd' => 'data', 'seq' => '1', 'ts' => time(),
            'device_id' => 'd', 'token' => 't', 'data' => array(),
        );
        $packet['sign'] = Message::sign($packet, 'sec');

        $tampered         = $packet;
        $tampered['data'] = array('injected' => 1);

        $result = Message::verify($tampered, $config);

        self::assertFalse($result['ok']);
        self::assertSame(Message::CODE_BAD_SIGN, $result['code']);
    }

    public function testVerifyRejectsStaleTimestamp(): void
    {
        $config = array('sign_enable' => true, 'secret' => 'sec', 'clock_skew' => 10);
        $packet = array(
            'cmd' => 'data', 'seq' => '1', 'ts' => time() - 1000,
            'device_id' => 'd', 'token' => 't', 'data' => array(),
        );
        $packet['sign'] = Message::sign($packet, 'sec');

        $result = Message::verify($packet, $config);

        self::assertFalse($result['ok']);
        self::assertSame(Message::CODE_BAD_TIMESTAMP, $result['code']);
    }

    public function testVerifyRejectsMissingSignField(): void
    {
        $config = array('sign_enable' => true, 'secret' => 'sec', 'clock_skew' => 300);
        $packet = array('cmd' => 'data', 'seq' => '1', 'ts' => time(), 'device_id' => 'd', 'token' => 't', 'data' => array());

        $result = Message::verify($packet, $config);

        self::assertFalse($result['ok']);
        self::assertSame(Message::CODE_BAD_SIGN, $result['code']);
        self::assertSame('缺少 sign 字段', $result['msg']);
    }

    public function testVerifySkipsWhenSignatureDisabled(): void
    {
        self::assertTrue(Message::verify(array('cmd' => 'data'), array('sign_enable' => false))['ok']);
    }

    public function testVerifyRejectsWhenSecretMissing(): void
    {
        $result = Message::verify(array('cmd' => 'data'), array('sign_enable' => true, 'secret' => ''));

        self::assertFalse($result['ok']);
        self::assertSame(Message::CODE_BAD_SIGN, $result['code']);
    }
}
