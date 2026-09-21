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
use GatewayPush\Business\ActionInterface;
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
        Logger::init(array(
            'path'   => sys_get_temp_dir(),
            'level'  => Logger::ERROR,
            'role'   => 'test',
            'stdout' => false,
        ));
    }

    /**
     * 装载一份动作表
     *
     * @param array $actions
     * @param array $defaults
     * @return array 已装载的动作名
     */
    private function load(array $actions, array $defaults = array())
    {
        return ActionRunner::load(array('defaults' => $defaults, 'actions' => $actions));
    }

    /* ---------------------------------------------------------------------
     | 注册过滤
     --------------------------------------------------------------------- */

    public function testOnlyValidDeclarationsAreRegistered(): void
    {
        $names = $this->load(array(
            'good'     => array('handler' => StubAction::class),
            'missing'  => array('handler' => __NAMESPACE__ . '\\NoSuchHandlerClass'),
            'not_impl' => array('handler' => NotAnAction::class),
        ));

        $this->assertSame(array('good'), $names);
    }

    public function testActionWithoutHandlerIsSkipped(): void
    {
        $names = $this->load(array(
            'nohandler' => array('params' => array()),
            'ok'        => array('handler' => StubAction::class),
        ));

        $this->assertSame(array('ok'), $names);
    }

    public function testNonArrayDeclarationIsSkipped(): void
    {
        $names = $this->load(array(
            'scalar' => 'not-an-array',
            'ok'     => array('handler' => StubAction::class),
        ));

        $this->assertSame(array('ok'), $names);
    }

    public function testEmptyActionNameIsSkipped(): void
    {
        $names = $this->load(array(
            ''   => array('handler' => StubAction::class),
            'ok' => array('handler' => StubAction::class),
        ));

        $this->assertSame(array('ok'), $names);
    }

    public function testMalformedConfigLoadsNothing(): void
    {
        // actions 缺失 / 非数组时不得抛异常，应退化为空表
        $this->assertSame(array(), ActionRunner::load(array()));
        $this->assertSame(array(), ActionRunner::load(array('actions' => 'bogus')));
        $this->assertSame(array(), ActionRunner::registered());
    }

    /* ---------------------------------------------------------------------
     | 查询接口
     --------------------------------------------------------------------- */

    public function testHasAndDeclaration(): void
    {
        $this->load(array('echo' => array('handler' => StubAction::class)));

        $this->assertTrue(ActionRunner::has('echo'));
        $this->assertFalse(ActionRunner::has('nope'));
        $this->assertTrue(ActionRunner::loaded());

        $this->assertIsArray(ActionRunner::declaration('echo'));
        $this->assertNull(ActionRunner::declaration('nope'));
    }

    public function testLoadIsIdempotentAndReplacesPreviousTable(): void
    {
        $this->load(array('old' => array('handler' => StubAction::class)));
        $this->assertTrue(ActionRunner::has('old'));

        $this->load(array('new' => array('handler' => StubAction::class)));

        $this->assertFalse(ActionRunner::has('old'), '重复装载应以最后一次为准重建声明表');
        $this->assertTrue(ActionRunner::has('new'));
        $this->assertSame(array('new'), ActionRunner::registered());
    }

    /* ---------------------------------------------------------------------
     | 默认值合并
     --------------------------------------------------------------------- */

    public function testDefaultsAreAppliedToActionsOmittingKeys(): void
    {
        $this->load(array('echo' => array('handler' => StubAction::class)));

        $decl = ActionRunner::declaration('echo');

        $this->assertTrue($decl['auth'], '默认要求鉴权');
        $this->assertSame(
            array(
                ActionContext::CHANNEL_WS   => ActionContext::REPLY_SYNC,
                ActionContext::CHANNEL_UDP  => ActionContext::REPLY_SYNC,
                ActionContext::CHANNEL_HTTP => ActionContext::REPLY_SYNC,
            ),
            $decl['reply']
        );
        $this->assertSame(ActionRunner::DEFAULT_TIMEOUT, $decl['timeout']);
        $this->assertSame(array(), $decl['params']);
        $this->assertSame('', $decl['description']);
        $this->assertSame(array(), $decl['options']);
        $this->assertFalse($decl['http'], 'HTTP 通道默认关闭（白名单则否）');
    }

    public function testCustomDefaultsOverrideBuiltinOnes(): void
    {
        $this->load(
            array('echo' => array('handler' => StubAction::class)),
            array('auth' => false, 'timeout' => 9, 'reply' => 'none')
        );

        $decl = ActionRunner::declaration('echo');

        $this->assertFalse($decl['auth']);
        $this->assertSame(9, $decl['timeout']);
        $this->assertSame(ActionContext::REPLY_NONE, $decl['reply'][ActionContext::CHANNEL_UDP]);
    }

    public function testActionLevelDeclarationBeatsDefaults(): void
    {
        $this->load(
            array('report' => array(
                'handler' => StubAction::class,
                'auth'    => false,
                'timeout' => '7',
            )),
            array('auth' => true, 'timeout' => 1)
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
        $this->load(array('r' => array('handler' => StubAction::class, 'reply' => 'none')));

        $this->assertSame(
            array(
                ActionContext::CHANNEL_WS   => ActionContext::REPLY_NONE,
                ActionContext::CHANNEL_UDP  => ActionContext::REPLY_NONE,
                ActionContext::CHANNEL_HTTP => ActionContext::REPLY_NONE,
            ),
            ActionRunner::declaration('r')['reply']
        );
    }

    public function testReplyMayBeDeclaredPerChannel(): void
    {
        $this->load(array('r' => array(
            'handler' => StubAction::class,
            'reply'   => array('ws' => 'sync', 'udp' => 'none'),
        )));

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
        $this->load(array('r' => array(
            'handler' => StubAction::class,
            'reply'   => array('udp' => 'none'),
        )));

        $decl = ActionRunner::declaration('r');

        $this->assertSame(ActionContext::REPLY_SYNC, $decl['reply'][ActionContext::CHANNEL_WS]);
        $this->assertSame(ActionContext::REPLY_NONE, $decl['reply'][ActionContext::CHANNEL_UDP]);
        $this->assertSame(ActionContext::REPLY_SYNC, $decl['reply'][ActionContext::CHANNEL_HTTP]);
    }

    public function testInvalidReplyValueFallsBackToSync(): void
    {
        $this->load(array('r' => array('handler' => StubAction::class, 'reply' => 'silent')));

        $this->assertSame(
            array(
                ActionContext::CHANNEL_WS   => ActionContext::REPLY_SYNC,
                ActionContext::CHANNEL_UDP  => ActionContext::REPLY_SYNC,
                ActionContext::CHANNEL_HTTP => ActionContext::REPLY_SYNC,
            ),
            ActionRunner::declaration('r')['reply'],
            '未识别的回执方式必须退化为 sync，不能产生第三种状态'
        );
    }

    /* ---------------------------------------------------------------------
     | params 归一化（含透传标记回归）
     --------------------------------------------------------------------- */

    public function testPassthroughMarkerSurvivesDeclarationListing(): void
    {
        $this->load(array('echo' => array('handler' => StubAction::class, 'params' => '*')));

        $this->assertSame('*', ActionRunner::declaration('echo')['params']);

        // 回归点：透传标记不是数组，列举参数时不得对其调用 array_keys()
        $listed = ActionRunner::declarations();
        $this->assertSame('*', $listed['echo']['params']);
    }

    public function testRuleKeysAreListedInDeclarations(): void
    {
        $this->load(array('report' => array(
            'handler' => StubAction::class,
            'params'  => array(
                'topic' => array('type' => 'string'),
                'count' => array('type' => 'int'),
            ),
        )));

        $listed = ActionRunner::declarations();

        $this->assertSame(array('topic', 'count'), $listed['report']['params']);
    }

    public function testInvalidParamsValueDegradesToEmptyRules(): void
    {
        $this->load(array('a' => array('handler' => StubAction::class, 'params' => 'bogus')));

        // 非法值退化为空规则 = 拒绝一切入参，而非透传
        $this->assertSame(array(), ActionRunner::declaration('a')['params']);
        $this->assertSame(array(), ActionRunner::declarations()['a']['params']);
    }

    /* ---------------------------------------------------------------------
     | HTTP 通道白名单
     --------------------------------------------------------------------- */

    public function testHttpIsClosedByDefault(): void
    {
        $this->load(array('a' => array('handler' => StubAction::class)));

        $this->assertFalse(ActionRunner::httpExposed('a'));
        $this->assertSame(array(), ActionRunner::httpActions());
    }

    public function testHttpWhitelistIsOptInPerAction(): void
    {
        $this->load(array(
            'open'   => array('handler' => StubAction::class, 'http' => true),
            'closed' => array('handler' => StubAction::class),
        ));

        $this->assertTrue(ActionRunner::httpExposed('open'));
        $this->assertFalse(ActionRunner::httpExposed('closed'));
        $this->assertSame(array('open'), ActionRunner::httpActions());
    }

    public function testHttpExposureIsListedInDeclarations(): void
    {
        $this->load(array(
            'open'   => array('handler' => StubAction::class, 'http' => true),
            'closed' => array('handler' => StubAction::class),
        ));

        $listed = ActionRunner::declarations();

        $this->assertTrue($listed['open']['http']);
        $this->assertFalse($listed['closed']['http']);
    }

    public function testHttpExposureIsBoolTypedEvenWhenDeclaredLoosely(): void
    {
        $this->load(array('a' => array('handler' => StubAction::class, 'http' => 1)));

        $decl = ActionRunner::declaration('a');

        $this->assertTrue($decl['http']);
        $this->assertSame(true, $decl['http'], '声明值须归一化为 bool，避免运出到下游出现 1 / true 两种形态');
    }

    public function testUnexposedActionIsNotHttpExposedEvenIfRegistered(): void
    {
        $this->load(array('a' => array('handler' => StubAction::class)));

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
        return array(
            'WS 数字 ID'      => array('7', ActionContext::CHANNEL_WS),
            'WS 长数字 ID'    => array('123456789', ActionContext::CHANNEL_WS),
            'UDP 虚拟 ID'     => array('udp:127.0.0.1:53210', ActionContext::CHANNEL_UDP),
            'HTTP 虚拟 ID'    => array('http:9f2c1a4b', ActionContext::CHANNEL_HTTP),
            '空 clientId'     => array('', ActionContext::CHANNEL_WS),
            // 前缀必须整段匹配：含 udp 字样但不以 udp: 开头的不能被误判
            '含 udp 字样的 WS' => array('xudp:1', ActionContext::CHANNEL_WS),
            '含 http 字样的 WS' => array('xhttp:1', ActionContext::CHANNEL_WS),
        );
    }

    /* ---------------------------------------------------------------------
     | 透出结构
     --------------------------------------------------------------------- */

    public function testDeclarationsExposeOperationalFieldsOnly(): void
    {
        $this->load(array('report' => array(
            'handler'     => StubAction::class,
            'description' => '上报计数',
            'auth'        => true,
            'timeout'     => 3,
        )));

        $listed = ActionRunner::declarations();

        $this->assertSame(
            array('description', 'auth', 'reply', 'timeout', 'http', 'params'),
            array_keys($listed['report']),
            '运维接口透出的字段应与类注释一致'
        );
        $this->assertSame('上报计数', $listed['report']['description']);
        $this->assertTrue($listed['report']['auth']);
        $this->assertSame(3, $listed['report']['timeout']);
    }

    public function testOptionsAreKeptOnInternalDeclaration(): void
    {
        $this->load(array('report' => array(
            'handler' => StubAction::class,
            'options' => array('ttl' => 86400),
        )));

        $this->assertSame(array('ttl' => 86400), ActionRunner::declaration('report')['options']);
    }
}

/* -------------------------------------------------------------------------
 | 测试夹具：仅在装载过滤逻辑中被引用，无任何行为
 ------------------------------------------------------------------------- */

class StubAction implements ActionInterface
{
    public function handle(ActionContext $ctx)
    {
        return null;
    }
}

class NotAnAction
{
}
