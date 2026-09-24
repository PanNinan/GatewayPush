<?php
/**
 * admin 单测 —— SignerParityTest。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace tests\Unit;

use app\service\GatewayPushClient;
use GatewayPush\Client\Service\AdminApi;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * ★ 签名同源金标 —— 本测试是「杜绝第三份签名实现漂移」的**唯一硬约束**。
 *
 * 背景：后台的 HTTP 签名与主项目客户端是**两份独立实现**
 * （后台用 Guzzle 同步栈，`client/` 用 workerman 异步栈），
 * 二者必须产出逐字节相同的签名，否则会出现「后台自洽、服务端拒绝」的疑难杂症。
 *
 * 本测试同时钉住两件事，缺一不可：
 *   ① **同源**：后台 `sign()` 与主项目 `client/src/Service/AdminApi::sign()` 输出一致
 *      （参照实现经 `autoload-dev` 的 psr-4 映射 `GatewayPush\Client\ → ../client/src/` 加载）；
 *   ② **合规范**：两者都与**服务端验签口径**一致
 *      （`src/Api/Bootstrap.php:907`：`hash_hmac('sha256', $timestamp . '|' . $rawBody, $secret)`）。
 *      只做 ① 是不够的 —— 若两侧同时改错，① 仍会通过。
 *
 * 另外覆盖 `encode()`：两侧 JSON flags 必须一致（`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`），
 * 否则同一份数据会产生不同字节 → 签名基串不同（典型触发场景：载荷含中文或 URL）。
 */
final class SignerParityTest extends TestCase
{
    /**
     * 绝对金标：`[ts, rawBody, secret, 期望签名]`。
     *
     * 期望值独立于任何实现算出（一次性手工计算后固化），因此**两侧同时改错也会红**。
     *
     * @var list<array{0: int, 1: string, 2: string, 3: string}>
     */
    private const GOLDEN_VECTORS = [
        // 无体请求（GET /stats）：基串为 "{ts}|"
        [1790154508, '', 's3cr3t', 'b4756c20868bc64b93eacc8de5faf5c076bdb6b09accaa33da02c5fba9a6e457'],
        // 有体请求：基串为 "{ts}|{原始 JSON 字节}"
        [1790154508, '{"a":1}', 's3cr3t', 'dab611060c995139a397de1b962fa7312c941fbe0cd7a5aaf19a6c5201e3e8a4'],
        [1, '{}', 'k', 'fc4d1e1013ed92ff2f47d7197e22a59f7aab60aa6c2b2ab51355036178d1cdb5'],
        // 含中文 + 绝对 URL：专门针对 encode() 的 flags 漂移
        [
            1790154508,
            '{"title":"中文标题","url":"http://a/b"}',
            's3cr3t',
            'fd619af1439374b81f706791cdde7b139d2a1f02d3930fba339eada5d23fbfb8',
        ],
    ];

    private const API_URL = 'http://127.0.0.1:8290';

    /**
     * ① 合服务端口径：与 `hash_hmac('sha256', "{ts}|{body}", secret)` 一致。
     */
    public function testSignMatchesServerSpecification(): void
    {
        foreach (self::GOLDEN_VECTORS as [$ts, $body, $secret, $expected]) {
            $client = new GatewayPushClient(self::API_URL, $secret);

            $this->assertSame(
                $expected,
                $client->sign($ts, $body),
                "签名与绝对金标不符（ts={$ts}）—— 服务端验签口径见 src/Api/Bootstrap.php:907"
            );
        }
    }

    /**
     * ② 同源：与主项目 `client/src/Service/AdminApi::sign()` 逐字节一致。
     */
    public function testSignParityWithReferenceClient(): void
    {
        $sign = new ReflectionMethod(AdminApi::class, 'sign');
        $sign->setAccessible(true);

        foreach (self::GOLDEN_VECTORS as [$ts, $body, $secret, $expected]) {
            // ⚠ 参照实现必须与待测端**用同一密钥**构造：密钥是实例状态而非入参，
            //    复用同一个实例会让「同源」断言退化成「两个不同密钥的输出相等」，必然假红。
            $reference = new AdminApi(self::API_URL, $secret);
            $client = new GatewayPushClient(self::API_URL, $secret);

            $this->assertSame(
                $sign->invoke($reference, $ts, $body),
                $client->sign($ts, $body),
                "后台签名与主项目 client 的 AdminApi::sign() 不一致（ts={$ts}）—— 出现了第三份实现"
            );
            $this->assertSame($expected, $client->sign($ts, $body));
        }
    }

    /**
     * 密钥为空时两侧都返回空串（服务端会以 500 拒绝，与「未配置密钥」语义对齐）。
     */
    public function testEmptySecretYieldsEmptySignatureOnBothSides(): void
    {
        $reference = new AdminApi(self::API_URL, '');
        $sign = new ReflectionMethod(AdminApi::class, 'sign');
        $sign->setAccessible(true);

        $this->assertSame('', (new GatewayPushClient(self::API_URL, ''))->sign(1790154508, '{}'));
        $this->assertSame('', $sign->invoke($reference, 1790154508, '{}'));
    }

    /**
     * ③ 请求体编码同源：flags 必须一致，否则「签的 body ≠ 发的 body」。
     */
    public function testEncodeParityWithReferenceClient(): void
    {
        $reference = new AdminApi(self::API_URL, 'unused');
        $encode = new ReflectionMethod(AdminApi::class, 'encode');
        $encode->setAccessible(true);

        $cases = [
            ['a' => 1],
            ['title' => '中文标题'],
            ['url' => 'http://a/b'],
            ['nested' => ['k' => '中文', 'u' => 'http://x/y']],
            [],
        ];

        $client = new GatewayPushClient(self::API_URL, 'unused');
        foreach ($cases as $case) {
            $this->assertSame(
                $encode->invoke($reference, $case),
                $client->encode($case),
                'encode() 与主项目 client 不一致 —— JSON flags 漂移会导致签名基串不同'
            );
        }
    }

    /**
     * encode() 的两条具体口径（即便两侧同时改错，这两条断言仍会红）。
     */
    public function testEncodeKeepsUnicodeAndSlashesRaw(): void
    {
        $client = new GatewayPushClient(self::API_URL, 'unused');

        $this->assertSame('{"title":"中文标题"}', $client->encode(['title' => '中文标题']));
        $this->assertSame('{"url":"http://a/b"}', $client->encode(['url' => 'http://a/b']));
    }

    /**
     * 请求头契约：`X-Timestamp` / `X-Sign` 的存在性与取值口径。
     */
    public function testHeadersContract(): void
    {
        $client = new GatewayPushClient(self::API_URL, 's3cr3t');
        $headers = $client->headers('', 1790154508);

        $this->assertSame('1790154508', $headers['X-Timestamp']);
        $this->assertSame(
            'b4756c20868bc64b93eacc8de5faf5c076bdb6b09accaa33da02c5fba9a6e457',
            $headers['X-Sign']
        );
        $this->assertSame('application/json; charset=utf-8', $headers['Content-Type']);
    }
}
