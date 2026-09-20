<?php
/**
 * Signer 单元测试（客户端协议层 P0）
 *
 * 三层保障，缺一不可：
 *   1. **委派断言** —— `Signer` 的输出必须与服务端 `Message` 逐字节相同，
 *      防止有人「优化」适配层时把 `canonicalize` / 签名基串重新实现一遍；
 *   2. **金标向量（golden vector）** —— 固定 fixture 的规范化串与签名十六进制写死在测试里，
 *      任何一侧（服务端或客户端）发生行为漂移都会立刻失败；
 *   3. **事实固定** —— `uid` 不参与签名、`clock_skew=0` 跳过时效校验等反直觉约定，
 *      用断言钉住，避免后人误以为已受保护而放宽校验。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Tests\Unit;

use GatewayPush\Business\Message;
use GatewayPush\Client\Error\ErrorCode;
use GatewayPush\Client\Protocol\Signer;
use PHPUnit\Framework\TestCase;

class SignerTest extends TestCase
{
    /**
     * 固定 fixture：覆盖键序打乱、嵌套、中文、斜杠、列表、布尔、null
     *
     * @return array
     */
    private function fixtureData()
    {
        return array(
            'b'      => 2,
            'a'      => 1,
            'list'   => array(3, 1, 2),
            'nested' => array('z' => 1, 'y' => array('k' => '中文', 'p' => 'a/b')),
            'bool'   => true,
            'nil'    => null,
        );
    }

    /**
     * 与 fixtureData 对应的固定报文（uid 刻意存在，用于验证其不参与签名）
     *
     * @return array
     */
    private function fixturePacket()
    {
        return array(
            'cmd'       => 'data',
            'seq'       => 's1',
            'ts'        => 1690000000,
            'uid'       => 'alice',
            'device_id' => 'dev1',
            'token'     => 'tk',
            'data'      => $this->fixtureData(),
        );
    }

    /* ---------------------------------------------------------------------
     | canonicalize
     --------------------------------------------------------------------- */

    public function testCanonicalizeMatchesGoldenVector(): void
    {
        $expected = '{"a":1,"b":2,"bool":true,"list":[3,1,2],'
            . '"nested":{"y":{"k":"中文","p":"a/b"},"z":1},"nil":null}';

        self::assertSame($expected, Signer::canonicalize($this->fixtureData()));
    }

    public function testCanonicalizeDelegatesToServerImplementation(): void
    {
        $data = $this->fixtureData();

        self::assertSame(Message::canonicalize($data), Signer::canonicalize($data));
    }

    public function testCanonicalizeSortsKeysRecursively(): void
    {
        self::assertSame('{"a":1,"z":{"a":1,"b":2}}', Signer::canonicalize(array(
            'z' => array('b' => 2, 'a' => 1),
            'a' => 1,
        )));
    }

    public function testCanonicalizeIsKeyOrderIndependent(): void
    {
        $one = Signer::canonicalize(array('a' => 1, 'b' => array('x' => 1, 'y' => 2)));
        $two = Signer::canonicalize(array('b' => array('y' => 2, 'x' => 1), 'a' => 1));

        self::assertSame($one, $two);
    }

    public function testCanonicalizeKeepsListOrder(): void
    {
        // 列表键为 0..n，ksort 后顺序不变 —— 顺序信息不会被规范化破坏
        self::assertSame('[3,1,2]', Signer::canonicalize(array(3, 1, 2)));
    }

    public function testCanonicalizeIsCompactAndKeepsUnicodeAndSlashes(): void
    {
        self::assertSame('{"k":"中文"}', Signer::canonicalize(array('k' => '中文')));
        self::assertSame('{"p":"a/b"}', Signer::canonicalize(array('p' => 'a/b')));
    }

    public function testCanonicalizeHandlesEmptyArrayAndScalars(): void
    {
        self::assertSame('[]', Signer::canonicalize(array()));
        self::assertSame('x', Signer::canonicalize('x'));
        self::assertSame('5', Signer::canonicalize(5));
        // 标量走 (string) 强转，故 true -> '1'、null -> ''。属服务端既有语义，此处钉住。
        self::assertSame('1', Signer::canonicalize(true));
        self::assertSame('', Signer::canonicalize(null));
    }

    /* ---------------------------------------------------------------------
     | 签名基串
     --------------------------------------------------------------------- */

    public function testBaseStringMatchesGoldenVector(): void
    {
        $expected = 'data|s1|1690000000|dev1|tk|' . Signer::canonicalize($this->fixtureData());

        self::assertSame($expected, Signer::baseString($this->fixturePacket()));
    }

    public function testBaseStringIsConsistentWithSign(): void
    {
        // 漂移守卫：baseString() 与 sign() 内部的拼接规则必须始终一致
        $packet = $this->fixturePacket();

        self::assertSame(
            hash_hmac('sha256', Signer::baseString($packet), 'secret'),
            Signer::sign($packet, 'secret')
        );
    }

    public function testBaseStringSkipsMissingFields(): void
    {
        // 缺字段按空串占位，不报错 —— 报文可能来自对端，字段未必齐全
        self::assertSame('ping|||||[]', Signer::baseString(array('cmd' => 'ping')));
    }

    /* ---------------------------------------------------------------------
     | sign
     --------------------------------------------------------------------- */

    public function testSignMatchesGoldenVector(): void
    {
        self::assertSame(
            '74c5deeb2f993f58e601dd51a2585a285711b96fd213a489199b5012c6600dce',
            Signer::sign($this->fixturePacket(), 'secret')
        );
    }

    public function testSignDelegatesToServerImplementation(): void
    {
        $packet = $this->fixturePacket();

        self::assertSame(Message::sign($packet, 'secret'), Signer::sign($packet, 'secret'));
    }

    public function testSignIsHexOfLength64(): void
    {
        $sign = Signer::sign($this->fixturePacket(), 'secret');

        self::assertSame(64, strlen($sign));
        self::assertSame(1, preg_match('/^[0-9a-f]{64}$/', $sign));
    }

    public function testSignIgnoresUidAndSignField(): void
    {
        // uid 不在签名基串内 —— 报文字段 uid 可被篡改，身份只能取自 token 载荷
        $packet = array(
            'cmd' => 'data', 'seq' => 's1', 'ts' => 1690000000,
            'device_id' => 'dev1', 'token' => 'tk', 'data' => array(),
        );

        $alice = $packet + array('uid' => 'alice');
        $bob   = $packet + array('uid' => 'bob', 'sign' => 'whatever');

        self::assertSame(Signer::sign($packet, 'secret'), Signer::sign($alice, 'secret'));
        self::assertSame(Signer::sign($alice, 'secret'), Signer::sign($bob, 'secret'));
    }

    public function testSignDependsOnEverySignedField(): void
    {
        $packet = $this->fixturePacket();
        $base   = Signer::sign($packet, 'secret');

        $mutations = array(
            'cmd'       => 'ack',
            'seq'       => 's2',
            'ts'        => 1690000001,
            'device_id' => 'dev2',
            'token'     => 'tk2',
            'data'      => array('a' => 1),
        );

        foreach ($mutations as $field => $value) {
            $mutated         = $packet;
            $mutated[$field] = $value;

            self::assertNotSame(
                $base,
                Signer::sign($mutated, 'secret'),
                '字段 ' . $field . ' 变更后签名必须变化'
            );
        }
    }

    public function testSignIsSecretDependent(): void
    {
        $packet = $this->fixturePacket();

        self::assertNotSame(
            Signer::sign($packet, 'secret-a'),
            Signer::sign($packet, 'secret-b')
        );
    }

    public function testSignIsStableAcrossKeyOrdering(): void
    {
        $ordered    = $this->fixturePacket();
        $shuffled   = $ordered;
        $shuffled['data'] = array_reverse($ordered['data'], true);

        self::assertSame(Signer::sign($ordered, 'secret'), Signer::sign($shuffled, 'secret'));
    }

    /* ---------------------------------------------------------------------
     | verify（本地预演服务端判定）
     --------------------------------------------------------------------- */

    public function testVerifyAcceptsOwnSignature(): void
    {
        $packet = $this->fixturePacket();
        $packet['ts']   = time();
        $packet['sign'] = Signer::sign($packet, 'secret');

        self::assertTrue(Signer::verify($packet, 'secret')['ok']);
    }

    public function testVerifyRejectsTamperedData(): void
    {
        $packet = $this->fixturePacket();
        $packet['ts']   = time();
        $packet['sign'] = Signer::sign($packet, 'secret');

        $packet['data'] = array('injected' => 1);

        $result = Signer::verify($packet, 'secret');

        self::assertFalse($result['ok']);
        self::assertSame(ErrorCode::BAD_SIGN, $result['code']);
    }

    public function testVerifyRejectsWrongSecret(): void
    {
        $packet = $this->fixturePacket();
        $packet['ts']   = time();
        $packet['sign'] = Signer::sign($packet, 'secret');

        self::assertFalse(Signer::verify($packet, 'other-secret')['ok']);
    }

    public function testVerifyRejectsStaleTimestamp(): void
    {
        $packet = $this->fixturePacket();
        $packet['ts']   = time() - 1000;
        $packet['sign'] = Signer::sign($packet, 'secret');

        $result = Signer::verify($packet, 'secret', 300);

        self::assertFalse($result['ok']);
        self::assertSame(ErrorCode::BAD_TIMESTAMP, $result['code']);
    }

    public function testVerifySkipsTimestampWhenSkewIsZero(): void
    {
        // 服务端规则：clock_skew <= 0 时不校验时效（仅验签）
        $packet = $this->fixturePacket();
        $packet['ts']   = time() - 100000;
        $packet['sign'] = Signer::sign($packet, 'secret');

        self::assertTrue(Signer::verify($packet, 'secret', 0)['ok']);
    }

    public function testVerifyRejectsMissingSign(): void
    {
        $packet = $this->fixturePacket();
        $packet['ts'] = time();

        $result = Signer::verify($packet, 'secret');

        self::assertFalse($result['ok']);
        self::assertSame(ErrorCode::BAD_SIGN, $result['code']);
        self::assertSame('缺少 sign 字段', $result['msg']);
    }

    public function testVerifyRejectsEmptySecret(): void
    {
        $result = Signer::verify(array('cmd' => 'data'), '');

        self::assertFalse($result['ok']);
        self::assertSame(ErrorCode::BAD_SIGN, $result['code']);
        self::assertSame('服务端未配置签名密钥', $result['msg']);
    }
}
