<?php
/**
 * HTTP 接口验签开关测试
 *
 * 这个开关是**安全相关**的：关掉它意味着任何能访问该端口的人都能调 /push 推任意
 * 消息、调 /action 执行业务动作。因此它的重点不在「能不能关」，而在
 * **「什么情况下不允许关」** —— 本测试主要锁定的就是那道护栏：
 * 只要接口不是监听在回环地址上，即便配置写了关闭，也必须按「启用」处理。
 *
 * 同时覆盖 isLoopbackHost() 的各类 listen 写法。该方法刻意**没有**用 parse_url()
 * （对「省略协议」与「裸 IPv6」的解析结果不稳定），故这些边界必须有回归保护 ——
 * 否则将来有人"顺手换个更规范的写法"就会静默改变安全判定。该方法被声明为 public
 * 供 start.php 复用，也是为了让自检输出与真实行为不可能出现分歧。
 *
 * 两个方法都是纯函数 / 只读静态配置，零 IO，符合本套件口径。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Tests\Unit;

use GatewayPush\Api\Bootstrap as ApiBootstrap;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class ApiSignSwitchTest extends TestCase
{
    /**
     * 静态配置快照：Api\Bootstrap::$config 是静态属性，改动会跨用例残留
     *
     * @var array
     */
    private $configSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configSnapshot = $this->configProperty()->getValue(null);
    }

    protected function tearDown(): void
    {
        $this->configProperty()->setValue(null, $this->configSnapshot);
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | isLoopbackHost：各类 listen 写法
     --------------------------------------------------------------------- */

    /**
     * @dataProvider listenProvider
     */
    public function testLoopbackHostDetection($listen, $expected, $why): void
    {
        self::assertSame(
            $expected,
            ApiBootstrap::isLoopbackHost($listen),
            sprintf('listen=%s（%s）', var_export($listen, true), $why)
        );
    }

    public function listenProvider(): array
    {
        return array(
            // ── 回环：应识别为 true ──
            'IPv4 回环 + 协议 + 端口' => array('http://127.0.0.1:8290', true, '标准写法'),
            'IPv4 回环 省略协议'      => array('127.0.0.1:8290', true, 'workerman 允许省略协议'),
            'IPv4 回环 无端口'        => array('127.0.0.1', true, '无冒号，整串即 host'),
            '回环段内其他地址'        => array('http://127.5.5.5:8290', true, '127.0.0.0/8 整段都是回环'),
            'localhost'               => array('http://localhost:8290', true, ''),
            'localhost 省略协议'      => array('localhost:8290', true, ''),
            'localhost 大小写'        => array('LOCALHOST:8290', true, 'DNS 名不区分大小写'),
            'IPv6 回环 带方括号'      => array('http://[::1]:8290', true, 'workerman 的标准 IPv6 写法'),
            'IPv6 回环 省略协议'      => array('[::1]:8290', true, ''),
            'IPv6 回环 裸写'          => array('::1', true, 'parse_url 取不到 host，故未采用它'),

            // ── 非回环：必须保持验签 ──
            '通配地址'         => array('http://0.0.0.0:8290', false, '对外监听'),
            '通配地址 省略协议' => array('0.0.0.0:8290', false, ''),
            '内网 IP'          => array('http://192.168.1.10:8290', false, '局域网内可访问'),
            '域名'             => array('http://api.example.com:8290', false, ''),
            'IPv6 非回环'      => array('http://[2001:db8::1]:8290', false, ''),
            'IPv4 映射的 IPv6' => array('::ffff:127.0.0.1', false, '非 ::1，按非回环处理偏安全'),
            '空串'             => array('', false, '判定不了时保留验签'),
            '纯空白'           => array('  ', false, ''),
        );
    }

    /* ---------------------------------------------------------------------
     | signEnabled：护栏语义（本测试的核心）
     --------------------------------------------------------------------- */

    public function testEnabledWhenSwitchIsOn(): void
    {
        $this->withConfig(array('listen' => 'http://127.0.0.1:8290', 'sign_enable' => true));
        self::assertTrue($this->signEnabled());
    }

    public function testEnabledByDefaultWhenKeyAbsent(): void
    {
        // 老 .env 未设置该键时必须按开启处理
        $this->withConfig(array('listen' => 'http://127.0.0.1:8290'));
        self::assertTrue($this->signEnabled(), '缺少 sign_enable 键应回落到「开启」');
    }

    public function testDisabledWhenOffAndListeningLoopback(): void
    {
        $this->withConfig(array('listen' => 'http://127.0.0.1:8290', 'sign_enable' => false));
        self::assertFalse($this->signEnabled(), '本机回环监听应允许免签（本地调试场景）');
    }

    /**
     * 护栏核心：配置想关，但接口对外监听 —— 必须强制验签
     *
     * 这条守的是「一处 .env 失误 = 业务入口裸奔」的风险。
     */
    public function testForcedOnWhenOffButListeningExternally(): void
    {
        $listens = array(
            'http://0.0.0.0:8290',
            '0.0.0.0:8290',
            'http://192.168.1.10:8290',
            'http://api.example.com:8290',
            'http://[2001:db8::1]:8290',
        );

        foreach ($listens as $listen) {
            $this->withConfig(array('listen' => $listen, 'sign_enable' => false));
            self::assertTrue(
                $this->signEnabled(),
                'listen=' . $listen . ' 非回环监听，必须忽略 API_SIGN_ENABLE=false 并强制验签'
            );
        }
    }

    public function testEnabledWhenListenMissingOrEmpty(): void
    {
        // listen 缺失 / 为空 → 无法确认是回环 → 保留验签（出错偏安全侧）
        $this->withConfig(array('sign_enable' => false));
        self::assertTrue($this->signEnabled(), 'listen 缺失时应保留验签');

        $this->withConfig(array('listen' => '', 'sign_enable' => false));
        self::assertTrue($this->signEnabled(), 'listen 为空时应保留验签');
    }

    /* ---------------------------------------------------------------------
     | 结构性断言：短路分支的位置
     --------------------------------------------------------------------- */

    /**
     * 免签短路必须落在「限流之后、签名比对之前」
     *
     * 位置写错会静默产生两类问题：提前到限流之前 → 免签模式绕过限流；
     * 晚于签名比对 → 本该免签的请求仍被 401，等于开关无效（这正是本次修复的缺陷形态）。
     */
    public function testFreeShortCircuitSitsBetweenRateLimitAndSignatureCheck(): void
    {
        $code = (string)file_get_contents($this->root('src/Api/Bootstrap.php'));

        $rateAt = strpos($code, 'if (!self::rateLimit($request))');
        $freeAt = strpos($code, 'if ($free) {');
        $signAt = strpos($code, '$expect = hash_hmac');

        self::assertNotFalse($rateAt, '未找到限流调用');
        self::assertNotFalse($freeAt, 'authenticate() 缺少免签短路分支');
        self::assertNotFalse($signAt, '未找到签名比对语句');

        self::assertGreaterThan($rateAt, $freeAt, '免签短路必须晚于限流 —— 否则免签模式会绕过限流');
        self::assertLessThan($signAt, $freeAt, '免签短路必须早于签名比对 —— 否则开关形同无效');
    }

    /**
     * 结构性断言：关掉验签不能连带跳过「密钥回退」之外的其它保护
     */
    public function testSignEnabledReadsApiConfigKey(): void
    {
        $code = (string)file_get_contents($this->root('src/Api/Bootstrap.php'));

        self::assertStringContainsString(
            "\$config['sign_enable']",
            $code,
            'signEnabled() 必须读取 api.sign_enable —— 键名不一致会导致开关静默失效'
        );
    }

    /* ---------------------------------------------------------------------
     | 辅助
     --------------------------------------------------------------------- */

    private function configProperty(): ReflectionProperty
    {
        $prop = (new ReflectionClass(ApiBootstrap::class))->getProperty('config');
        $prop->setAccessible(true);

        return $prop;
    }

    /**
     * 直接改写静态配置，模拟不同的 .env 组合
     */
    private function withConfig(array $config): void
    {
        $this->configProperty()->setValue(null, $config);
    }

    private function signEnabled(): bool
    {
        $method = new ReflectionMethod(ApiBootstrap::class, 'signEnabled');
        $method->setAccessible(true);

        return (bool)$method->invoke(null);
    }

    private function root(string $relative): string
    {
        return dirname(__DIR__, 2) . '/' . $relative;
    }
}
