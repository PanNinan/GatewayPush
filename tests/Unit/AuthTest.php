<?php
/**
 * Auth 单元测试（纯计算面：配置读取 + Token 签发/本地校验）
 *
 * Auth 的校验刻意拆成两段：
 *   verifyLocal()      同步纯计算（签名 + 时效），无 IO —— 本文件覆盖
 *   isRevoked()        异步查 Redis 撤销名单 —— 归 e2e，不在单测面
 *   checkDeviceBind()  异步查 Redis 设备绑定 —— 归 e2e，不在单测面
 *
 * 关键回归点：
 *   - issue() → verifyLocal() 往返必须闭环（签名基串或密钥任一改动即断）；
 *   - 过期 Token 必须回 CODE_TOKEN_EXPIRED（区别于通用 4004）；
 *   - 签名篡改 / 结构非法 / 空 Token 全部回 4004 且 claims 为空；
 *   - 时钟偏移窗口内放行、超窗拒绝（clock_skew 语义）。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Tests\Unit;

use GatewayPush\Business\Auth;
use GatewayPush\Business\Message;
use GatewayPush\Common\Logger;
use PHPUnit\Framework\TestCase;

class AuthTest extends TestCase
{
    /**
     * @var array<string, mixed> 进场前的原始配置快照
     */
    private $originalConfig = [];

    protected function setUp(): void
    {
        Logger::init([
            'path'   => sys_get_temp_dir(),
            'level'  => Logger::ERROR,
            'role'   => 'test',
            'stdout' => false,
        ]);

        $this->originalConfig = $this->readConfig();
        Auth::init([
            'enable'       => true,
            'mode'         => 'hmac',
            'secret'       => 'unit-test-secret',
            'token_ttl'    => 7200,
            'clock_skew'   => 300,
            'bind_device'  => true,
            'fail_close'   => true,
            'auth_timeout' => 15,
            'allow_cmds'   => ['auth', 'ping'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->writeConfig($this->originalConfig);
        Logger::init([
            'path'   => sys_get_temp_dir(),
            'level'  => Logger::ERROR,
            'role'   => 'test',
            'stdout' => false,
        ]);
    }

    /* -----------------------------------------------------------------
     | 配置读取（纯 getter）
     ----------------------------------------------------------------- */

    public function testEnabledFollowsConfigFlag(): void
    {
        Auth::init(['enable' => true]);
        self::assertTrue(Auth::enabled());

        Auth::init(['enable' => false]);
        self::assertFalse(Auth::enabled());

        Auth::init(['enable' => 0]);
        self::assertFalse(Auth::enabled());
    }

    public function testIsAllowedBeforeAuthMatchesWhitelistStrictly(): void
    {
        Auth::init(['allow_cmds' => ['auth', 'ping']]);

        self::assertTrue(Auth::isAllowedBeforeAuth('auth'));
        self::assertTrue(Auth::isAllowedBeforeAuth('ping'));
        self::assertFalse(Auth::isAllowedBeforeAuth('data'));
        self::assertFalse(Auth::isAllowedBeforeAuth('AUTH'), '必须严格 === 比对，不做大小写折叠');
        self::assertFalse(Auth::isAllowedBeforeAuth(''));
    }

    public function testAuthTimeoutIsCastToInt(): void
    {
        Auth::init(['auth_timeout' => '7']);
        self::assertSame(7, Auth::authTimeout());

        Auth::init(['auth_timeout' => 0]);
        self::assertSame(0, Auth::authTimeout());
    }

    public function testShouldCloseOnFailFollowsConfigFlag(): void
    {
        Auth::init(['fail_close' => true]);
        self::assertTrue(Auth::shouldCloseOnFail());

        Auth::init(['fail_close' => false]);
        self::assertFalse(Auth::shouldCloseOnFail());
    }

    /* -----------------------------------------------------------------
     | issue → verifyLocal 往返
     ----------------------------------------------------------------- */

    public function testIssueVerifyRoundTripSucceeds(): void
    {
        $token = Auth::issue(['uid' => 'u1', 'device_id' => 'd1']);
        $result = Auth::verifyLocal($token);

        self::assertTrue($result['ok'], '往返校验必须通过：' . $result['msg']);
        self::assertSame(Message::CODE_OK, $result['code']);
        self::assertSame('u1', $result['claims']['uid']);
        self::assertSame('d1', $result['claims']['device_id']);
        self::assertArrayHasKey('iat', $result['claims']);
        self::assertArrayHasKey('exp', $result['claims']);
        self::assertArrayHasKey('nonce', $result['claims']);
    }

    public function testIssueDefaultsUidAndDeviceToEmpty(): void
    {
        $token  = Auth::issue([]);
        $result = Auth::verifyLocal($token);

        self::assertTrue($result['ok']);
        self::assertSame('', $result['claims']['uid']);
        self::assertSame('', $result['claims']['device_id']);
    }

    public function testIssueHonorsExplicitTtl(): void
    {
        $token  = Auth::issue(['uid' => 'u1'], 60);
        $result = Auth::verifyLocal($token);

        self::assertTrue($result['ok']);
        $ttl = (int)$result['claims']['exp'] - (int)$result['claims']['iat'];
        self::assertSame(60, $ttl);
    }

    public function testIssueUsesConfigTtlWhenTtlZero(): void
    {
        Auth::init(['token_ttl' => 1234]);
        $token  = Auth::issue(['uid' => 'u1'], 0);
        $result = Auth::verifyLocal($token);

        self::assertTrue($result['ok']);
        $ttl = (int)$result['claims']['exp'] - (int)$result['claims']['iat'];
        self::assertSame(1234, $ttl);
    }

    public function testTokenIsTwoSegmentDotForm(): void
    {
        $token = Auth::issue(['uid' => 'u1']);

        self::assertStringContainsString('.', $token);
        self::assertCount(2, explode('.', $token));
    }

    /* -----------------------------------------------------------------
     | verifyLocal 失败形态
     ----------------------------------------------------------------- */

    public function testEmptyTokenFails(): void
    {
        $result = Auth::verifyLocal('');

        self::assertFalse($result['ok']);
        self::assertSame(Message::CODE_AUTH_FAILED, $result['code']);
        self::assertSame([], $result['claims']);
    }

    public function testWhitespaceOnlyTokenFailsStructure(): void
    {
        $result = Auth::verifyLocal(' ');

        self::assertFalse($result['ok']);
        self::assertSame(Message::CODE_AUTH_FAILED, $result['code']);
    }

    public function testMalformedStructureFails(): void
    {
        // 单段 / 三段 / 纯点号均非「body.sign」两段结构
        foreach (['abc', 'a.b.c', '.', 'a.', '.b'] as $bad) {
            $result = Auth::verifyLocal($bad);
            self::assertFalse($result['ok'], "应拒绝：{$bad}");
            self::assertSame(Message::CODE_AUTH_FAILED, $result['code']);
            self::assertSame([], $result['claims']);
        }
    }

    public function testTamperedSignatureFails(): void
    {
        $token = Auth::issue(['uid' => 'u1']);
        [$body, $sign] = explode('.', $token);

        // 翻转签名末字符（base64url 字母表内换一个字符）
        $tampered = substr($sign, 0, -1) . ($sign[strlen($sign) - 1] === 'A' ? 'B' : 'A');
        $result   = Auth::verifyLocal($body . '.' . $tampered);

        self::assertFalse($result['ok'], '篡改签名必须拒绝');
        self::assertSame(Message::CODE_AUTH_FAILED, $result['code']);
        self::assertSame([], $result['claims']);
    }

    public function testTamperedBodyFailsEvenWithValidLookingSign(): void
    {
        $token = Auth::issue(['uid' => 'u1']);
        [$body, $sign] = explode('.', $token);

        // 改 body 一个字符，签名必然对不上
        $newBody = substr($body, 0, -1) . ($body[strlen($body) - 1] === 'A' ? 'B' : 'A');
        $result  = Auth::verifyLocal($newBody . '.' . $sign);

        self::assertFalse($result['ok'], '篡改载荷必须拒绝');
        self::assertSame(Message::CODE_AUTH_FAILED, $result['code']);
    }

    public function testWrongSecretRejectsToken(): void
    {
        $token = Auth::issue(['uid' => 'u1']);

        Auth::init(['secret' => 'other-secret']);
        $result = Auth::verifyLocal($token);

        self::assertFalse($result['ok'], '换密钥后旧 Token 必须失效');
        self::assertSame(Message::CODE_AUTH_FAILED, $result['code']);
    }

    public function testExpiredTokenReturnsExpiredCode(): void
    {
        $token = Auth::issue(['uid' => 'u1'], 1);

        // 把 exp 拨到过去：直接改 claims 再用当前 secret 重签不可行（hash 是 protected），
        // 改用「短 TTL + 时钟推进」的等价手法不可靠；这里构造过期载荷再签发。
        $result = $this->verifyCrafted(['uid' => 'u1', 'iat' => time() - 100, 'exp' => time() - 10]);

        self::assertFalse($result['ok']);
        self::assertSame(Message::CODE_TOKEN_EXPIRED, $result['code'], '过期必须回 4005 而非 4004');
        self::assertSame([], $result['claims']);
        unset($token);
    }

    public function testFutureIatBeyondSkewFails(): void
    {
        Auth::init(['clock_skew' => 300]);
        $result = $this->verifyCrafted([
            'uid' => 'u1',
            'iat' => time() + 1000,
            'exp' => time() + 10000,
        ]);

        self::assertFalse($result['ok']);
        self::assertSame(Message::CODE_AUTH_FAILED, $result['code']);
        self::assertSame('Token 签发时间异常', $result['msg']);
    }

    public function testFutureIatWithinSkewPasses(): void
    {
        Auth::init(['clock_skew' => 300]);
        $result = $this->verifyCrafted([
            'uid' => 'u1',
            'iat' => time() + 10,
            'exp' => time() + 3600,
        ]);

        self::assertTrue($result['ok'], '窗口内的轻微未来时间戳应放行');
    }

    public function testSkewDisabledAlwaysPassesIatCheck(): void
    {
        Auth::init(['clock_skew' => 0]);
        $result = $this->verifyCrafted([
            'uid' => 'u1',
            'iat' => time() + 100000,
            'exp' => time() + 200000,
        ]);

        self::assertTrue($result['ok'], 'clock_skew=0 时关闭签发时间检查');
    }

    /* -----------------------------------------------------------------
     | 工具
     ----------------------------------------------------------------- */

    /**
     * 用当前 secret 手工构造合法签名的 Token（覆盖 issue 的字段生成）
     *
     * @param array<string, mixed> $claims
     *
     * @return array<string, mixed>
     */
    private function verifyCrafted(array $claims): array
    {
        $payload = array_merge([
            'device_id' => '',
            'nonce'     => 'testnonce',
        ], $claims);

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $body = rtrim(strtr(base64_encode((string)$json), '+/', '-_'), '=');
        $sign = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, 'unit-test-secret', true)), '+/', '-_'), '=');

        return Auth::verifyLocal($body . '.' . $sign);
    }

    /**
     * @return array<string, mixed>
     */
    private function readConfig(): array
    {
        $prop = (new \ReflectionClass(Auth::class))->getProperty('config');
        $prop->setAccessible(true);

        return $prop->getValue();
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return void
     */
    private function writeConfig(array $config): void
    {
        $prop = (new \ReflectionClass(Auth::class))->getProperty('config');
        $prop->setAccessible(true);
        $prop->setValue(null, $config);
    }
}
