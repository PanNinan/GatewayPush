<?php
/**
 * TokenIssuer 单元测试（客户端协议层 P0）
 *
 * 覆盖面：
 *   1. 构造与出参校验（空密钥、负 TTL、缺 uid、Token 结构）；
 *   2. 载荷契约（uid / device_id / iat / exp / nonce，exp-iat 必须等于 TTL）；
 *   3. 校验分支（过期、签发时间过前、错密钥、篡改载荷、空 Token）；
 *   4. **静态状态隔离** —— 同进程内多个不同密钥的 issuer 必须互不串味，
 *      这是复用服务端静态类 `Auth` 后最真实的风险点。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Tests\Unit;

use GatewayPush\Business\Auth;
use GatewayPush\Client\Error\ClientException;
use GatewayPush\Client\Error\ErrorCode;
use GatewayPush\Client\Protocol\TokenIssuer;
use PHPUnit\Framework\TestCase;

class TokenIssuerTest extends TestCase
{
    const SECRET = 'unit-test-secret';

    /* ---------------------------------------------------------------------
     | 构造与基础
     --------------------------------------------------------------------- */

    public function testConstructorRejectsEmptySecret(): void
    {
        try {
            new TokenIssuer('');
            self::fail('空密钥必须抛 ClientException');
        } catch (ClientException $e) {
            self::assertSame(ErrorCode::CLIENT_CONFIG, $e->getCode());
            self::assertStringContainsString('密钥不能为空', $e->getMessage());
        }
    }

    public function testConstructorRejectsNegativeTtl(): void
    {
        $this->expectException(ClientException::class);

        new TokenIssuer(self::SECRET, -1);
    }

    public function testDefaultTtlFallsBackTo7200(): void
    {
        self::assertSame(7200, (new TokenIssuer(self::SECRET))->defaultTtl());
        self::assertSame(60, (new TokenIssuer(self::SECRET, 60))->defaultTtl());
    }

    /* ---------------------------------------------------------------------
     | issue
     --------------------------------------------------------------------- */

    public function testIssueRequiresNonEmptyUid(): void
    {
        $issuer = new TokenIssuer(self::SECRET);

        try {
            $issuer->issue(array('device_id' => 'd1'));
            self::fail('缺少 uid 必须抛 ClientException');
        } catch (ClientException $e) {
            self::assertSame(ErrorCode::CLIENT_CONFIG, $e->getCode());
        }

        $this->expectException(ClientException::class);
        $issuer->issue(array('uid' => ''));
    }

    public function testIssueProducesTwoPartBase64UrlToken(): void
    {
        $token = (new TokenIssuer(self::SECRET))->issue(array('uid' => 'alice'));

        self::assertSame(1, preg_match('#^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$#', $token));
        // URL 安全 base64：不得出现 '+' '/' '=' 填充
        self::assertStringNotContainsString('=', $token);
        self::assertStringNotContainsString('+', $token);
        self::assertStringNotContainsString('/', $token);
        self::assertSame(2, count(explode('.', $token)));
    }

    public function testIssuePayloadContract(): void
    {
        $issuer = new TokenIssuer(self::SECRET);
        $before = time();
        $token  = $issuer->issue(array('uid' => 'alice', 'device_id' => 'dev1'), 600);
        $after  = time();

        $claims = $issuer->peek($token);

        self::assertSame('alice', $claims['uid']);
        self::assertSame('dev1', $claims['device_id']);
        self::assertSame(600, $claims['exp'] - $claims['iat']);
        self::assertGreaterThanOrEqual($before, $claims['iat']);
        self::assertLessThanOrEqual($after, $claims['iat']);
        self::assertSame(1, preg_match('/^[0-9a-f]{16}$/', $claims['nonce']));
    }

    public function testIssueUsesDefaultTtlWhenOmitted(): void
    {
        $issuer = new TokenIssuer(self::SECRET, 60);
        $claims = $issuer->peek($issuer->issue(array('uid' => 'alice')));

        self::assertSame(60, $claims['exp'] - $claims['iat']);
    }

    public function testIssueRejectsNegativeTtl(): void
    {
        $this->expectException(ClientException::class);

        (new TokenIssuer(self::SECRET))->issue(array('uid' => 'alice'), -5);
    }

    /* ---------------------------------------------------------------------
     | inspect / verify / claims
     --------------------------------------------------------------------- */

    public function testInspectAcceptsOwnToken(): void
    {
        $issuer = new TokenIssuer(self::SECRET);
        $token  = $issuer->issue(array('uid' => 'alice', 'device_id' => 'dev1'));

        $result = $issuer->inspect($token);

        self::assertTrue($result['ok']);
        self::assertSame(ErrorCode::OK, $result['code']);
        self::assertSame('alice', $result['claims']['uid']);
        self::assertTrue($issuer->verify($token));
        self::assertSame('alice', $issuer->claims($token)['uid']);
    }

    public function testInspectRejectsEmptyToken(): void
    {
        $result = (new TokenIssuer(self::SECRET))->inspect('');

        self::assertFalse($result['ok']);
        self::assertSame(ErrorCode::AUTH_FAILED, $result['code']);
        self::assertSame('Token 不能为空', $result['msg']);
    }

    public function testInspectRejectsMalformedStructure(): void
    {
        $result = (new TokenIssuer(self::SECRET))->inspect('not-a-token');

        self::assertFalse($result['ok']);
        self::assertSame('Token 结构非法', $result['msg']);
    }

    public function testInspectRejectsWrongSecret(): void
    {
        $token  = (new TokenIssuer(self::SECRET))->issue(array('uid' => 'alice'));
        $result = (new TokenIssuer('another-secret'))->inspect($token);

        self::assertFalse($result['ok']);
        self::assertSame(ErrorCode::AUTH_FAILED, $result['code']);
        self::assertSame('Token 签名校验失败', $result['msg']);
    }

    public function testInspectRejectsTamperedPayload(): void
    {
        $issuer = new TokenIssuer(self::SECRET);
        $token  = $issuer->issue(array('uid' => 'alice'));

        $parts = explode('.', $token);
        $body  = $parts[0];
        $mid   = intdiv(strlen($body), 2);
        $body[$mid] = $body[$mid] === 'A' ? 'B' : 'A';

        $result = $issuer->inspect($body . '.' . $parts[1]);

        self::assertFalse($result['ok']);
        self::assertSame('Token 签名校验失败', $result['msg']);
    }

    public function testInspectRejectsExpiredToken(): void
    {
        $issuer = new TokenIssuer(self::SECRET);
        // claims 在 array_merge 中覆盖默认值，故可构造「签发即过期」的 Token
        $token  = $issuer->issue(array('uid' => 'alice', 'exp' => time() - 10));

        $result = $issuer->inspect($token);

        self::assertFalse($result['ok']);
        self::assertSame(ErrorCode::TOKEN_EXPIRED, $result['code']);
        self::assertSame('Token 已过期', $result['msg']);
    }

    public function testInspectRejectsFutureIssuedAt(): void
    {
        $issuer = new TokenIssuer(self::SECRET, 0, 300);
        $token  = $issuer->issue(array('uid' => 'alice', 'iat' => time() + 3600));

        $result = $issuer->inspect($token);

        self::assertFalse($result['ok']);
        self::assertSame(ErrorCode::AUTH_FAILED, $result['code']);
        self::assertSame('Token 签发时间异常', $result['msg']);
    }

    public function testInspectSkipsIssuedAtCheckWhenClockSkewIsZero(): void
    {
        // clock_skew = 0 时服务端不校验 iat，此处必须与之一致
        $issuer = new TokenIssuer(self::SECRET, 0, 0);
        $token  = $issuer->issue(array('uid' => 'alice', 'iat' => time() + 3600));

        self::assertTrue($issuer->inspect($token)['ok']);
    }

    public function testClaimsReturnsEmptyArrayOnFailure(): void
    {
        self::assertSame([], (new TokenIssuer(self::SECRET))->claims('garbage'));
    }

    /* ---------------------------------------------------------------------
     | peek（不验签）
     --------------------------------------------------------------------- */

    public function testPeekDecodesWithoutVerifying(): void
    {
        $token = (new TokenIssuer(self::SECRET))->issue(array('uid' => 'alice', 'device_id' => 'dev1'));

        // 换成错密钥的 issuer：inspect 必失败，peek 仍可读出载荷
        $other = new TokenIssuer('another-secret');

        self::assertFalse($other->inspect($token)['ok']);
        self::assertSame('alice', $other->peek($token)['uid']);
        self::assertSame('dev1', $other->peek($token)['device_id']);
    }

    public function testPeekReturnsEmptyOnMalformedInput(): void
    {
        $issuer = new TokenIssuer(self::SECRET);

        self::assertSame([], $issuer->peek(''));
        self::assertSame([], $issuer->peek('not-a-token'));
        self::assertSame([], $issuer->peek('a.b.c'));
        self::assertSame([], $issuer->peek('!!!.sig'));
        self::assertSame([], $issuer->peek('aGVsbG8.sig'));
    }

    /* ---------------------------------------------------------------------
     | 与服务端同源（委派关系）
     --------------------------------------------------------------------- */

    public function testIssueDelegatesToServerAuth(): void
    {
        // 服务端 Auth 为静态类，先按同一密钥初始化，验证客户端产出可被服务端直接认可
        Auth::init(array('secret' => self::SECRET, 'token_ttl' => 7200));

        $token  = (new TokenIssuer(self::SECRET))->issue(array('uid' => 'alice'));
        $result = Auth::verifyLocal($token);

        self::assertTrue($result['ok']);
        self::assertSame('alice', $result['claims']['uid']);
    }

    public function testServerIssuedTokenIsAcceptedByClient(): void
    {
        Auth::init(array('secret' => self::SECRET, 'token_ttl' => 7200));
        $token = Auth::issue(array('uid' => 'bob', 'device_id' => 'dev9'));

        $result = (new TokenIssuer(self::SECRET))->inspect($token);

        self::assertTrue($result['ok']);
        self::assertSame('bob', $result['claims']['uid']);
    }

    /* ---------------------------------------------------------------------
     | 静态状态隔离
     --------------------------------------------------------------------- */

    public function testMultipleIssuersDoNotLeakSecret(): void
    {
        $issuerA = new TokenIssuer('secret-a', 7200);
        $tokenA  = $issuerA->issue(array('uid' => 'alice'));

        // 后建实例若污染了 Auth 的静态密钥，issuerA 的校验会跟着失效
        $issuerB = new TokenIssuer('secret-b', 7200);
        $tokenB  = $issuerB->issue(array('uid' => 'bob'));

        self::assertTrue($issuerA->inspect($tokenA)['ok'], 'issuerA 必须仍能用 secret-a 校验自己的 Token');
        self::assertTrue($issuerB->inspect($tokenB)['ok'], 'issuerB 必须能用 secret-b 校验自己的 Token');
        self::assertFalse($issuerA->inspect($tokenB)['ok'], 'issuerA 不得接受 secret-b 签发的 Token');
        self::assertFalse($issuerB->inspect($tokenA)['ok'], 'issuerB 不得接受 secret-a 签发的 Token');
    }
}
