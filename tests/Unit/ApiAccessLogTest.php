<?php
/**
 * HTTP 访问日志上下文组装测试
 *
 * 锁三件事（改坏即排障能力失效 / 安全事故）：
 *   ① 入站日志字段完整：method / path / query / ip / headers / body / body_len
 *   ② 敏感头脱敏：X-Sign / Authorization / Cookie 明文不得进日志
 *      （明文进日志 = 翻日志即可重放或仿冒调用方）
 *   ③ 超长 body 截断并标注原始长度 —— 否则 Logger 的 2000 字符 context 上限
 *      会把整条日志硬截成半截 JSON，headers 恰好被截掉
 *
 * requestLogContext / redactHeaders 是纯组装（不写盘、不碰 Redis），
 * 用 workerman Request 的原始 buffer 构造，零 IO，符合本套件口径。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Tests\Unit;

use GatewayPush\Api\Bootstrap as ApiBootstrap;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Workerman\Protocols\Http\Request;

final class ApiAccessLogTest extends TestCase
{
    /**
     * @dataProvider contextProvider
     *
     * @param array<string, mixed> $expected
     * @param string               $why
     *
     * @return void
     */
    public function testRequestLogContextFields(array $expected, string $why): void
    {
        $raw = "POST /action?src=demo HTTP/1.1\r\n"
            . "Host: 127.0.0.1:8290\r\n"
            . "Content-Type: application/json\r\n"
            . "X-Timestamp: 1700000000\r\n"
            . "X-Sign: deadbeefcafebabe0123456789abcdef\r\n"
            . "X-Forwarded-For: 10.1.2.3, 172.16.0.1\r\n"
            . "\r\n"
            . '{"action":"echo","params":{"msg":"hi"}}';

        $ctx = $this->invoke('requestLogContext', [new Request($raw)]);

        foreach ($expected as $key => $value) {
            if ($key === 'headers.x-sign') {
                $headers = $ctx['headers'] ?? [];
                self::assertArrayHasKey('x-sign', $headers, $why);
                self::assertStringContainsString('deadbeef', (string)$headers['x-sign'], $why);
                self::assertStringNotContainsString(
                    'cafebabe0123456789abcdef',
                    (string)$headers['x-sign'],
                    'X-Sign 不得明文进日志'
                );
                self::assertStringContainsString('len=', (string)$headers['x-sign'], $why);

                continue;
            }

            self::assertArrayHasKey($key, $ctx, $why . ' / missing ' . $key);
            self::assertSame($value, $ctx[$key], $why . ' / ' . $key);
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public function contextProvider(): array
    {
        $body = '{"action":"echo","params":{"msg":"hi"}}';

        return [
            'method/path/query' => [
                ['method' => 'POST', 'path' => '/action', 'query' => 'src=demo'],
                '请求行三要素',
            ],
            'client_ip 取 X-Forwarded-For 首段' => [
                ['ip' => '10.1.2.3'],
                '多级代理只取第一个（真实来源）',
            ],
            'body 原样 + body_len' => [
                ['body' => $body, 'body_len' => strlen($body)],
                '未超长 body 应完整记录',
            ],
            'X-Sign 脱敏' => [
                ['headers.x-sign' => true],
                '签名头前 8 字符 + len 标注',
            ],
        ];
    }

    public function testLongBodyIsTruncatedWithOriginalLength(): void
    {
        $body  = str_repeat('x', ApiBootstrap::ACCESS_LOG_BODY_MAX + 500);
        $raw   = "POST /push HTTP/1.1\r\nHost: h\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
        $ctx   = $this->invoke('requestLogContext', [new Request($raw)]);

        self::assertSame(ApiBootstrap::ACCESS_LOG_BODY_MAX + 500, $ctx['body_len'], 'body_len 必须是原始长度');
        self::assertStringContainsString('truncated', (string)$ctx['body'], '截断标记');
        self::assertStringContainsString('total=' . (ApiBootstrap::ACCESS_LOG_BODY_MAX + 500), (string)$ctx['body'], '原始长度写在截断标记里');
        self::assertLessThan(
            ApiBootstrap::ACCESS_LOG_BODY_MAX + 80,
            strlen((string)$ctx['body']),
            '截断后的 body 不能接近原始长度'
        );
    }

    public function testSensitiveHeadersRedactedAndPlainHeadersKept(): void
    {
        $headers = [
            'Host'           => '127.0.0.1:8290',
            'Content-Type'   => 'application/json',
            'X-Sign'         => str_repeat('a', 64),
            'Authorization'  => 'Bearer super-secret-token-value',
            'Cookie'         => 'session=abc123',
            'X-Forwarded-For' => '1.2.3.4',
            'X-Timestamp'    => '1700000000',
        ];

        $safe = $this->invoke('redactHeaders', [$headers]);

        self::assertSame('127.0.0.1:8290', $safe['host'], '普通头原样保留（键名小写）');
        self::assertSame('application/json', $safe['content-type'], 'Content-Type 原样');
        self::assertSame('1700000000', $safe['x-timestamp'], '时间戳是公开值，保留以便与签名对照');

        self::assertStringNotContainsString(str_repeat('a', 64), $safe['x-sign'], 'X-Sign 脱敏');
        self::assertStringNotContainsString('super-secret-token-value', $safe['authorization'], 'Authorization 脱敏');
        self::assertStringNotContainsString('session=abc123', $safe['cookie'], 'Cookie 脱敏');

        foreach (['x-sign', 'authorization', 'cookie'] as $name) {
            self::assertStringContainsString('len=', $safe[$name], $name . ' 应带长度标注');
        }
    }

    public function testRedactHeadersAcceptsNonArray(): void
    {
        self::assertSame([], $this->invoke('redactHeaders', [null]), 'header() 非数组时返回空表');
        self::assertSame([], $this->invoke('redactHeaders', ['nope']), '字符串也不该炸');
    }

    public function testRepeatedHeaderArrayIsJoined(): void
    {
        $safe = $this->invoke('redactHeaders', [[
            'X-Forwarded-For' => ['10.0.0.1', '10.0.0.2'],
        ]]);

        self::assertSame('10.0.0.1,10.0.0.2', $safe['x-forwarded-for'], '重复头拼成逗号串');
    }

    public function testShortSecretIsFullyMasked(): void
    {
        self::assertSame('***len=4', $this->invoke('redactSecret', ['abcd']), '≤8 字符不泄露任何内容');
        self::assertSame('xxxxxxxx***len=64', $this->invoke('redactSecret', [str_repeat('x', 64)]), '长值留前 8');
    }

    /**
     * @param string            $name
     * @param array<int, mixed> $args
     *
     * @return mixed
     */
    private function invoke(string $name, array $args)
    {
        $m = new ReflectionMethod(ApiBootstrap::class, $name);
        $m->setAccessible(true);

        return $m->invokeArgs(null, $args);
    }
}
