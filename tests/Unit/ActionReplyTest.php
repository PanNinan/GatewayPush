<?php
/**
 * ActionReply 单元测试（纯函数层）
 *
 * ActionReply 是 HTTP 动作结果的回程桥，其 store() / fetch() 直接操作 Redis，
 * 属端到端范畴（由 tests/E2E/CaseHttpAction.php 覆盖）。此处只锁定三处
 * **纯计算且出错代价高**的逻辑：
 *
 *   1. clientId <-> request_id 的双向转换 —— ActionRunner::channelOf() 依赖
 *      它识别 HTTP 通道，转换一错就整条链路走错分支；
 *   2. request_id 的键空间校验 —— request_id 直接拼进 Redis 键，
 *      校验形同虚设时任何持有接口密钥者都可污染键空间；
 *   3. TTL 取值的下界保护 —— 0 或负数会让结果键瞬间消失，
 *      表现为「动作偶发查不到结果」这种极难定位的故障。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Tests\Unit;

use GatewayPush\Business\ActionReply;
use PHPUnit\Framework\TestCase;

class ActionReplyTest extends TestCase
{
    /* ---------------------------------------------------------------------
     | clientId 转换
     --------------------------------------------------------------------- */

    public function testClientIdRoundTrip(): void
    {
        $requestId = 'a1b2c3d4e5f60718';

        $clientId = ActionReply::clientId($requestId);

        $this->assertSame('http:' . $requestId, $clientId);
        $this->assertSame($requestId, ActionReply::requestId($clientId));
        $this->assertTrue(ActionReply::isHttpClient($clientId));
    }

    public function testNonHttpClientIsRejected(): void
    {
        // WS 数字 ID 与 UDP 虚拟 ID 都不是 HTTP 通道
        foreach (['7', '123456789', 'udp:127.0.0.1:53210', ''] as $clientId) {
            $this->assertFalse(ActionReply::isHttpClient($clientId), 'clientId=' . $clientId);
            $this->assertSame('', ActionReply::requestId($clientId), 'clientId=' . $clientId);
        }
    }

    public function testPrefixMustMatchWholeSegment(): void
    {
        // 含 http 字样但不以 http: 开头 —— 不得被识别为 HTTP 通道
        $this->assertFalse(ActionReply::isHttpClient('xhttp:1'));
        $this->assertSame('', ActionReply::requestId('xhttp:1'));
    }

    /* ---------------------------------------------------------------------
     | request_id 键空间校验
     --------------------------------------------------------------------- */

    /**
     * @dataProvider requestIdProvider
     */
    public function testRequestIdValidation(string $requestId, bool $expected): void
    {
        $this->assertSame($expected, ActionReply::validRequestId($requestId), 'request_id=' . $requestId);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public function requestIdProvider(): array
    {
        return [
            '十六进制（生产形态）' => ['9f2c1a4b7e6d5c30', true],
            '含连字符与下划线'     => ['req-1_a', true],
            '单字符'               => ['a', true],
            '64 字符（上限）'      => [str_repeat('a', 64), true],
            '65 字符（越界）'      => [str_repeat('a', 65), false],
            '空串'                 => ['', false],
            // 路径穿越是键空间污染的主要形态，必须拦住
            '路径穿越 ../'         => ['../evil', false],
            '含冒号'               => ['a:b', false],
            '含空格'               => ['a b', false],
            '含换行'               => ["a\nb", false],
            '含中文'               => ['请求', false],
            '含通配符 *'           => ['*', false],
        ];
    }

    /* ---------------------------------------------------------------------
     | TTL
     --------------------------------------------------------------------- */

    public function testDefaultTtlIsPositive(): void
    {
        $this->assertGreaterThan(0, ActionReply::DEFAULT_TTL);
        $this->assertSame(ActionReply::DEFAULT_TTL, ActionReply::ttl());
    }

    /**
     * @dataProvider ttlProvider
     */
    public function testTtlHasLowerBound(int $configured, int $expected): void
    {
        ActionReply::init(['result_ttl' => $configured]);
        $this->assertSame($expected, ActionReply::ttl());

        // 还原默认值，避免影响同进程内的后续用例
        ActionReply::init(['result_ttl' => ActionReply::DEFAULT_TTL]);
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public function ttlProvider(): array
    {
        return [
            '正常值' => [120, 120],
            '0 被抬到 1' => [0, 1],
            '负数被抬到 1' => [-5, 1],
        ];
    }

    public function testInitWithoutResultTtlKeepsCurrentValue(): void
    {
        ActionReply::init(['result_ttl' => 90]);
        ActionReply::init(['enable' => true]);
        $this->assertSame(90, ActionReply::ttl());

        ActionReply::init(['result_ttl' => ActionReply::DEFAULT_TTL]);
    }

    public function testInitToleratesEmptyConfig(): void
    {
        // business.php 未提供 action_queue 段时不得抛异常
        ActionReply::init([]);
        $this->assertGreaterThan(0, ActionReply::ttl());
    }
}
