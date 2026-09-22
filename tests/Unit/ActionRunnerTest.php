<?php
/**
 * ActionRunner 单元测试（仅声明层 + 通道解析）
 *
 * 覆盖三条纯逻辑链路：
 *   1. load() / registered() / has() / declaration() / declarations()
 *      —— 「config/actions.php 声明式接入」的全部契约；
 *   2. HTTP 暴露白名单（httpExposed / httpActions）—— 默认关闭、逐动作开启；
 *   3. channelOf() 的前缀表 —— 由二元判断改造而来，顺序与边界必须锁定。
 *
 * 不覆盖 run() / sender() / emitError()：前两者依赖 Auth / Monitor / Redis /
 * Timer 与 workerman 事件循环，属端到端范畴，由 tests/E2E/CaseHttpAction.php
 * 与 CaseActionRouting.php 覆盖。channelOf 用反射直取，因为它本身是纯函数。
 *
 * 关键回归点：
 *   - declarations() 对 params = '*' 的透传标记不能按数组处理。
 *     早期实现直接 array_keys() 会抛 TypeError —— 纯类型错误，
 *     却要跑完整端到端才会暴露。
 *   - reply 归一化必须覆盖三个通道且缺省回落 sync。既有的双通道声明
 *     （不含 http 键）依赖该回落语义才能零改动兼容 HTTP。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Tests\Unit;

use GatewayPush\Business\ActionContext;
use GatewayPush\Business\ActionRunner;
use GatewayPush\Common\Logger;
use PHPUnit\Framework\TestCase;

class ActionRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        // 声明解析过程会写 info / warn 日志。级别设为 ERROR 使其在写盘与
        // stdout 之前即被丢弃 —— 满足 beStrictAboutOutputDuringTests 约束。
        // path 指向已存在的系统临时目录：Logger::init 只在目录不存在时创建，
        // 这样本用例不产生任何文件或目录残留。
        Logger::init([
            'path'   => sys_get_temp_dir(),
            'level'  => Logger::ERROR,
            'role'   => 'test',
            'stdout' => false,
        ]);
    }

    /* ---------------------------------------------------------------------
     | 注册过滤
     --------------------------------------------------------------------- */

    public function testOnlyValidDeclarationsAreRegistered(): void
    {
        $names = $this->load([
            'good'     => ['handler' => StubAction::class],
            'missing'  => ['handler' => __NAMESPACE__ . '\NoSuchHandlerClass'],
            'not_impl' => ['handler' => NotAnAction::class],
        ]);

        $this->assertSame(['good'], $names);
    }

    public function testActionWithoutHandlerIsSkipped(): void
    {
        $names = $this->load([
            'nohandler' => ['params' => []],
            'ok'        => ['handler' => StubAction::class],
        ]);

        $this->assertSame(['ok'], $names);
    }

    public function testNonArrayDeclarationIsSkipped(): void
    {
        $names = $this->load([
            'scalar' => 'not-an-array',
            'ok'     => ['handler' => StubAction::class],
        ]);

        $this->assertSame(['ok'], $names);
    }

    public function testEmptyActionNameIsSkipped(): void
    {
        $names = $this->load([
            ''   => ['handler' => StubAction::class],
            'ok' => ['handler' => StubAction::class],
        ]);

        $this->assertSame(['ok'], $names);
    }

    public function testMalformedConfigLoadsNothing(): void
    {
        // actions 缺失 / 非数组时不得抛异常，应退化为空表
        $this->assertSame([], ActionRunner::load([]));
        $this->assertSame([], ActionRunner::load(['actions' => 'bogus']));
        $this->assertSame([], ActionRunner::registered());
    }

    /* ---------------------------------------------------------------------
     | 查询接口
     --------------------------------------------------------------------- */

    public function testHasAndDeclaration(): void
    {
        $this->load(['echo' => ['handler' => StubAction::class]]);

        $this->assertTrue(ActionRunner::has('echo'));
        $this->assertFalse(ActionRunner::has('nope'));
        $this->assertTrue(ActionRunner::loaded());

        $this->assertIsArray(ActionRunner::declaration('echo'));
        $this->assertNull(ActionRunner::declaration('nope'));
    }

    public function testLoadIsIdempotentAndReplacesPreviousTable(): void
    {
        $this->load(['old' => ['handler' => StubAction::class]]);
        $this->assertTrue(ActionRunner::has('old'));

        $this->load(['new' => ['handler' => StubAction::class]]);

        $this->assertFalse(ActionRunner::has('old'), '重复装载应以最后一次为准重建声明表');
        $this->assertTrue(ActionRunner::has('new'));
        $this->assertSame(['new'], ActionRunner::registered());
    }

    /* ---------------------------------------------------------------------
     | 默认值合并
     --------------------------------------------------------------------- */

    public function testDefaultsAreAppliedToActionsOmittingKeys(): void
    {
        $this->load(['echo' => ['handler' => StubAction::class]]);

        $decl = ActionRunner::declaration('echo');

        $this->assertTrue($decl['auth'], '默认要求鉴权');
        $this->assertSame(
            [
                ActionContext::CHANNEL_WS   => ActionContext::REPLY_SYNC,
                ActionContext::CHANNEL_UDP  => ActionContext::REPLY_SYNC,
                ActionContext::CHANNEL_HTTP => ActionContext::REPLY_SYNC,
            ],
            $decl['reply']
        );
        $this->assertSame(ActionRunner::DEFAULT_TIMEOUT, $decl['timeout']);
        $this->assertSame([], $decl['params']);
        $this->assertSame('', $decl['description']);
        $this->assertSame([], $decl['options']);
        $this->assertFalse($decl['http'], 'HTTP 通道默认关闭（白名单则否）');
    }

    public function testCustomDefaultsOverrideBuiltinOnes(): void
    {
        $this->load(
            ['echo' => ['handler' => StubAction::class]],
            ['auth' => false, 'timeout' => 9, 'reply' => 'none']
        );

        $decl = ActionRunner::declaration('echo');

        $this->assertFalse($decl['auth']);
        $this->assertSame(9, $decl['timeout']);
        $this->assertSame(ActionContext::REPLY_NONE, $decl['reply'][ActionContext::CHANNEL_UDP]);
    }

    public function testActionLevelDeclarationBeatsDefaults(): void
    {
        $this->load(
            ['report' => [
                'handler' => StubAction::class,
                'auth'    => false,
                'timeout' => '7',
            ]],
            ['auth' => true, 'timeout' => 1]
        );

        $decl = ActionRunner::declaration('report');

        $this->assertFalse($decl['auth']);
        $this->assertSame(7, $decl['timeout'], 'timeout 应被归一化为 int');
    }

    /* ---------------------------------------------------------------------
     | reply 归一化
     --------------------------------------------------------------------- */

    public function testReplyStringAppliesToAllChannels(): void
    {
        $this->load(['r' => ['handler' => StubAction::class, 'reply' => 'none']]);

        $this->assertSame(
            [
                ActionContext::CHANNEL_WS   => ActionContext::REPLY_NONE,
                ActionContext::CHANNEL_UDP  => ActionContext::REPLY_NONE,
                ActionContext::CHANNEL_HTTP => ActionContext::REPLY_NONE,
            ],
            ActionRunner::declaration('r')['reply']
        );
    }

    public function testReplyMayBeDeclaredPerChannel(): void
    {
        $this->load(['r' => [
            'handler' => StubAction::class,
            'reply'   => ['ws' => 'sync', 'udp' => 'none'],
        ]]);

        $decl = ActionRunner::declaration('r');

        $this->assertSame(ActionContext::REPLY_SYNC, $decl['reply'][ActionContext::CHANNEL_WS]);
        $this->assertSame(ActionContext::REPLY_NONE, $decl['reply'][ActionContext::CHANNEL_UDP]);
        $this->assertSame(
            ActionContext::REPLY_SYNC,
            $decl['reply'][ActionContext::CHANNEL_HTTP],
            '未声明的通道必须回落 sync：既有的双通道声明因此无需改动即兼容 HTTP'
        );
    }

    public function testReplyPartialChannelDeclarationFallsBackToSync(): void
    {
        $this->load(['r' => [
            'handler' => StubAction::class,
            'reply'   => ['udp' => 'none'],
        ]]);

        $decl = ActionRunner::declaration('r');

        $this->assertSame(ActionContext::REPLY_SYNC, $decl['reply'][ActionContext::CHANNEL_WS]);
        $this->assertSame(ActionContext::REPLY_NONE, $decl['reply'][ActionContext::CHANNEL_UDP]);
        $this->assertSame(ActionContext::REPLY_SYNC, $decl['reply'][ActionContext::CHANNEL_HTTP]);
    }

    public function testInvalidReplyValueFallsBackToSync(): void
    {
        $this->load(['r' => ['handler' => StubAction::class, 'reply' => 'silent']]);

        $this->assertSame(
            [
                ActionContext::CHANNEL_WS   => ActionContext::REPLY_SYNC,
                ActionContext::CHANNEL_UDP  => ActionContext::REPLY_SYNC,
                ActionContext::CHANNEL_HTTP => ActionContext::REPLY_SYNC,
            ],
            ActionRunner::declaration('r')['reply'],
            '未识别的回执方式必须退化为 sync，不能产生第三种状态'
        );
    }

    /* ---------------------------------------------------------------------
     | params 归一化（含透传标记回归）
     --------------------------------------------------------------------- */

    public function testPassthroughMarkerSurvivesDeclarationListing(): void
    {
        $this->load(['echo' => ['handler' => StubAction::class, 'params' => '*']]);

        $this->assertSame('*', ActionRunner::declaration('echo')['params']);

        // 回归点：透传标记不是数组，列举参数时不得对其调用 array_keys()
        $listed = ActionRunner::declarations();
        $this->assertSame('*', $listed['echo']['params']);
    }

    public function testRuleKeysAreListedInDeclarations(): void
    {
        $this->load(['report' => [
            'handler' => StubAction::class,
            'params'  => [
                'topic' => ['type' => 'string'],
                'count' => ['type' => 'int'],
            ],
        ]]);

        $listed = ActionRunner::declarations();

        $this->assertSame(['topic', 'count'], $listed['report']['params']);
    }

    public function testInvalidParamsValueDegradesToEmptyRules(): void
    {
        $this->load(['a' => ['handler' => StubAction::class, 'params' => 'bogus']]);

        // 非法值退化为空规则 = 拒绝一切入参，而非透传
        $this->assertSame([], ActionRunner::declaration('a')['params']);
        $this->assertSame([], ActionRunner::declarations()['a']['params']);
    }

    /* ---------------------------------------------------------------------
     | HTTP 通道白名单
     --------------------------------------------------------------------- */

    public function testHttpIsClosedByDefault(): void
    {
        $this->load(['a' => ['handler' => StubAction::class]]);

        $this->assertFalse(ActionRunner::httpExposed('a'));
        $this->assertSame([], ActionRunner::httpActions());
    }

    public function testHttpWhitelistIsOptInPerAction(): void
    {
        $this->load([
            'open'   => ['handler' => StubAction::class, 'http' => true],
            'closed' => ['handler' => StubAction::class],
        ]);

        $this->assertTrue(ActionRunner::httpExposed('open'));
        $this->assertFalse(ActionRunner::httpExposed('closed'));
        $this->assertSame(['open'], ActionRunner::httpActions());
    }

    public function testHttpExposureIsListedInDeclarations(): void
    {
        $this->load([
            'open'   => ['handler' => StubAction::class, 'http' => true],
            'closed' => ['handler' => StubAction::class],
        ]);

        $listed = ActionRunner::declarations();

        $this->assertTrue($listed['open']['http']);
        $this->assertFalse($listed['closed']['http']);
    }

    public function testHttpExposureIsBoolTypedEvenWhenDeclaredLoosely(): void
    {
        $this->load(['a' => ['handler' => StubAction::class, 'http' => 1]]);

        $decl = ActionRunner::declaration('a');

        // assertTrue 内部即 === true 判定，已足以钉住「归一化为 bool」，
        // 再补一句 assertSame(true, ...) 是恒真的重复断言（PHPStan 会直接判定 alreadyNarrowed）
        $this->assertTrue($decl['http'], '声明值须归一化为 bool，避免运出到下游出现 1 / true 两种形态');
    }

    public function testUnexposedActionIsNotHttpExposedEvenIfRegistered(): void
    {
        $this->load(['a' => ['handler' => StubAction::class]]);

        // 未注册动作一律 false，不存在「未注册但被判定为可调用」的中间态
        $this->assertFalse(ActionRunner::httpExposed('nope'));
    }

    /* ---------------------------------------------------------------------
     | 通道前缀表（回归点）
     |
     | 由二元判断改用前缀表后，顺序与优先级必须锁定：
     | 早期实现是「是 udp 前缀 ? udp : ws」，任何新前缀都会被误判为 ws。
     --------------------------------------------------------------------- */

    /**
     * @dataProvider channelPrefixProvider
     */
    public function testChannelIsResolvedByClientIdPrefix(string $clientId, string $expected): void
    {
        $method = new \ReflectionMethod(ActionRunner::class, 'channelOf');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke(null, $clientId));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function channelPrefixProvider(): array
    {
        return [
            'WS 数字 ID'      => ['7', ActionContext::CHANNEL_WS],
            'WS 长数字 ID'    => ['123456789', ActionContext::CHANNEL_WS],
            'UDP 虚拟 ID'     => ['udp:127.0.0.1:53210', ActionContext::CHANNEL_UDP],
            'HTTP 虚拟 ID'    => ['http:9f2c1a4b', ActionContext::CHANNEL_HTTP],
            '空 clientId'     => ['', ActionContext::CHANNEL_WS],
            // 前缀必须整段匹配：含 udp 字样但不以 udp: 开头的不能被误判
            '含 udp 字样的 WS' => ['xudp:1', ActionContext::CHANNEL_WS],
            '含 http 字样的 WS' => ['xhttp:1', ActionContext::CHANNEL_WS],
        ];
    }

    /* ---------------------------------------------------------------------
     | 透出结构
     --------------------------------------------------------------------- */

    public function testDeclarationsExposeOperationalFieldsOnly(): void
    {
        $this->load(['report' => [
            'handler'     => StubAction::class,
            'description' => '上报计数',
            'auth'        => true,
            'timeout'     => 3,
        ]]);

        $listed = ActionRunner::declarations();

        $this->assertSame(
            ['description', 'auth', 'reply', 'timeout', 'http', 'params'],
            array_keys($listed['report']),
            '运维接口透出的字段应与类注释一致'
        );
        $this->assertSame('上报计数', $listed['report']['description']);
        $this->assertTrue($listed['report']['auth']);
        $this->assertSame(3, $listed['report']['timeout']);
    }

    public function testOptionsAreKeptOnInternalDeclaration(): void
    {
        $this->load(['report' => [
            'handler' => StubAction::class,
            'options' => ['ttl' => 86400],
        ]]);

        $this->assertSame(['ttl' => 86400], ActionRunner::declaration('report')['options']);
    }

    /**
     * 装载一份动作表
     *
     * @param array $actions
     * @param array $defaults
     *
     * @return array 已装载的动作名
     */
    private function load(array $actions, array $defaults = [])
    {
        return ActionRunner::load(['defaults' => $defaults, 'actions' => $actions]);
    }
}
