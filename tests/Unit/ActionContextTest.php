<?php
/**
 * ActionContext 单元测试
 *
 * 重点覆盖「回执抑制语义」——本类存在的核心价值：
 * 当声明为 reply = none 时，回执不下发，但仍标记为已回执，
 * 使 ActionRunner 的超时保护不再触发。
 *
 * 这条语义决定了处理器可以始终按「处理完就回执」的写法实现，
 * 无需为 UDP 静默通道写 if 分支；一旦被破坏，静默动作会在
 * 每次调用后触发一次假超时告警与错误回执（本地不可见的线上噪音）。
 *
 * 全程不触碰 Redis / workerman，仅注入闭包作为下发器与钩子。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Tests\Unit;

use GatewayPush\Business\ActionContext;
use GatewayPush\Business\Message;
use PHPUnit\Framework\TestCase;

class ActionContextTest extends TestCase
{
    /**
     * 被捕获的下发报文
     *
     * @var array
     */
    private $sent = [];

    /**
     * 回执钩子被调用的次数
     *
     * @var int
     */
    private $hookCalls = 0;

    protected function setUp(): void
    {
        $this->sent      = [];
        $this->hookCalls = 0;
    }

    /* ---------------------------------------------------------------------
     | 身份与来源
     --------------------------------------------------------------------- */

    public function testIdentityGetters(): void
    {
        $ctx = $this->context('report', [], [], ActionContext::CHANNEL_UDP, ActionContext::REPLY_SYNC, [], 'udp');

        $this->assertSame('report', $ctx->action());
        $this->assertSame('1234567890123456789', $ctx->clientId());
        $this->assertSame('uid-1001', $ctx->uid());
        $this->assertSame('dev-A', $ctx->deviceId());
        $this->assertSame('udp', $ctx->protocol());
        $this->assertSame(ActionContext::CHANNEL_UDP, $ctx->channel());
    }

    public function testMissingIdentityFieldsBecomeEmptyStrings(): void
    {
        $ctx = new ActionContext(
            'echo',
            [],
            [],
            [],
            ActionContext::CHANNEL_WS,
            ActionContext::REPLY_SYNC,
            function (array $p) {
                $this->sent[] = $p;
            }
        );

        $this->assertSame('', $ctx->clientId());
        $this->assertSame('', $ctx->uid());
        $this->assertSame('', $ctx->deviceId());
        $this->assertSame('', $ctx->protocol());
    }

    public function testSeqIsReadFromPacketAndDefaultsToEmpty(): void
    {
        $ctx = $this->context('echo', ['seq' => 'sp-1']);
        $this->assertSame('sp-1', $ctx->seq());

        $this->assertSame('', $this->context('echo', [])->seq());
    }

    public function testPacketIsReturnedVerbatim(): void
    {
        $packet = ['cmd' => 'data', 'seq' => 'x', 'data' => ['action' => 'echo']];
        $ctx    = $this->context('echo', $packet);

        $this->assertSame($packet, $ctx->packet());
    }

    /* ---------------------------------------------------------------------
     | 参数与私有配置
     --------------------------------------------------------------------- */

    public function testParamsExposeValidatedValues(): void
    {
        $ctx = $this->context('report', [], ['topic' => 'a/b', 'count' => 5]);

        $this->assertSame(['topic' => 'a/b', 'count' => 5], $ctx->params());
        $this->assertSame('a/b', $ctx->param('topic'));
        $this->assertSame(5, $ctx->param('count'));
    }

    public function testParamFallsBackToDefaultOnlyWhenKeyAbsent(): void
    {
        $ctx = $this->context('report', [], ['flag' => null, 'zero' => 0]);

        // 键存在即使是 null / 0 也应原样返回，不回落默认值
        $this->assertNull($ctx->param('flag', 'fallback'));
        $this->assertSame(0, $ctx->param('zero', 99));
        $this->assertSame('fallback', $ctx->param('nope', 'fallback'));
    }

    public function testOptionsAreReadableWithDefault(): void
    {
        $ctx = $this->context('report', [], [], ActionContext::CHANNEL_WS, ActionContext::REPLY_SYNC, ['ttl' => 3600]);

        $this->assertSame(['ttl' => 3600], $ctx->options());
        $this->assertSame(3600, $ctx->option('ttl'));
        $this->assertSame('d', $ctx->option('absent', 'd'));
    }

    /* ---------------------------------------------------------------------
     | 回执方式归一化
     --------------------------------------------------------------------- */

    public function testReplyModeDefaultsToSyncOnUnknownValue(): void
    {
        $ctx = $this->context('echo', [], [], ActionContext::CHANNEL_WS, 'bogus');

        $this->assertSame(ActionContext::REPLY_SYNC, $ctx->replyMode());
    }

    /* ---------------------------------------------------------------------
     | 回执
     --------------------------------------------------------------------- */

    public function testReplySendsAckCarryingSeqAndData(): void
    {
        $ctx = $this->context('echo', ['seq' => 'sp-9']);

        $this->assertFalse($ctx->isReplied(), '构造后不应处于已回执状态');

        $sent = $ctx->reply(['ok' => 1]);

        $this->assertTrue($sent, 'sync 模式应真正下发');
        $this->assertTrue($ctx->isReplied());
        $this->assertCount(1, $this->sent);
        $this->assertSame(Message::CMD_ACK, $this->sent[0]['cmd']);
        $this->assertSame('sp-9', $this->sent[0]['seq']);
        $this->assertSame(['ok' => 1], $this->sent[0]['data']);
    }

    public function testReplyErrorSendsErrorPacketWithCodeSeqAndRef(): void
    {
        $ctx = $this->context('echo', ['seq' => 'sp-10', 'cmd' => 'data']);

        $sent = $ctx->replyError(Message::CODE_PARAM_MISSING);

        $this->assertTrue($sent);
        $this->assertCount(1, $this->sent);
        $this->assertSame(Message::CMD_ERROR, $this->sent[0]['cmd']);
        $this->assertSame(Message::CODE_PARAM_MISSING, $this->sent[0]['data']['code']);
        // 未显式传文案时取错误码默认文案，而非空串
        $this->assertSame(Message::codeMessage(Message::CODE_PARAM_MISSING), $this->sent[0]['data']['msg']);
        $this->assertSame('sp-10', $this->sent[0]['seq']);
        $this->assertSame('data', $this->sent[0]['ref']);
    }

    public function testReplyErrorPrefersExplicitMessage(): void
    {
        $ctx = $this->context('echo', ['seq' => 'sp-11']);

        $ctx->replyError(Message::CODE_SERVER_ERROR, '自定义文案');

        $this->assertSame('自定义文案', $this->sent[0]['data']['msg']);
    }

    /* ---------------------------------------------------------------------
     | 回执抑制语义（核心）
     --------------------------------------------------------------------- */

    public function testReplyNoneSuppressesSendButMarksReplied(): void
    {
        $ctx = $this->context('report', ['seq' => 'sp-12'], [], ActionContext::CHANNEL_UDP, ActionContext::REPLY_NONE);

        $sent = $ctx->reply(['count' => 3]);

        $this->assertFalse($sent, 'reply=none 时不应真正下发');
        $this->assertSame([], $this->sent, '下发器不得被调用');
        $this->assertTrue($ctx->isReplied(), '静默回执仍须标记已回执，否则超时保护会误报');
    }

    public function testReplyErrorIsAlsoSuppressedWhenReplyNone(): void
    {
        $ctx = $this->context('report', ['seq' => 'sp-13'], [], ActionContext::CHANNEL_UDP, ActionContext::REPLY_NONE);

        $this->assertFalse($ctx->replyError(Message::CODE_SERVER_ERROR));
        $this->assertSame([], $this->sent);
        $this->assertTrue($ctx->isReplied());
    }

    public function testReplyHookFiresOnSuppressedReplyToo(): void
    {
        $ctx = $this->context('report', [], [], ActionContext::CHANNEL_UDP, ActionContext::REPLY_NONE);
        $ctx->setReplyHook(function () {
            $this->hookCalls++;
        });

        $ctx->reply([]);

        $this->assertSame(1, $this->hookCalls, '静默回执也必须注销超时定时器');
    }

    /* ---------------------------------------------------------------------
     | 回执钩子
     --------------------------------------------------------------------- */

    public function testReplyHookIsNotFiredBeforeAnyReply(): void
    {
        $ctx = $this->context('echo');
        $ctx->setReplyHook(function () {
            $this->hookCalls++;
        });

        $this->assertSame(0, $this->hookCalls);
    }

    public function testReplyHookFiresOnlyOnceAcrossMultipleSends(): void
    {
        $ctx = $this->context('echo');
        $ctx->setReplyHook(function () {
            $this->hookCalls++;
        });

        $ctx->reply(['n' => 1]);
        $ctx->reply(['n' => 2]);
        $ctx->send(['cmd' => 'push', 'data' => []]);

        // 定时器只需注销一次；后续下发不应重复触发钩子
        $this->assertSame(1, $this->hookCalls);
        $this->assertCount(3, $this->sent, 'sync 模式下每次调用都应实际下发');
    }

    public function testSendAcceptsArbitraryPacket(): void
    {
        $ctx = $this->context('echo');

        $this->assertTrue($ctx->send(['cmd' => 'push', 'seq' => 'p-1', 'data' => ['x' => 1]]));
        $this->assertSame('push', $this->sent[0]['cmd']);
        $this->assertSame(['x' => 1], $this->sent[0]['data']);
    }

    public function testWithoutReplyHookSendStillWorks(): void
    {
        $ctx = $this->context('echo');

        // 未注册钩子时不得报错（钩子由 ActionRunner 注入，属可选依赖）
        $this->assertTrue($ctx->reply(['ok' => 1]));
        $this->assertCount(1, $this->sent);
    }

    /**
     * 构造被测上下文
     *
     * @param string $action
     * @param array  $packet
     * @param array  $params
     * @param string $channel
     * @param string $replyMode
     * @param array  $options
     * @param string $protocol
     *
     * @return ActionContext
     */
    private function context(
        $action = 'echo',
        array $packet = [],
        array $params = [],
        $channel = ActionContext::CHANNEL_WS,
        $replyMode = ActionContext::REPLY_SYNC,
        array $options = [],
        $protocol = 'ws'
    ) {
        return new ActionContext(
            $action,
            $packet,
            $params,
            [
                'client_id' => '1234567890123456789',
                'uid'       => 'uid-1001',
                'device_id' => 'dev-A',
                'protocol'  => $protocol,
            ],
            $channel,
            $replyMode,
            function (array $p) {
                $this->sent[] = $p;
            },
            $options
        );
    }
}
