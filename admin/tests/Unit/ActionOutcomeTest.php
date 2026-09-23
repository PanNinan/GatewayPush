<?php

declare(strict_types=1);

namespace tests\Unit;

use app\service\ActionCatalog;
use app\service\ActionOutcome;
use app\service\GatewayPushClient;
use PHPUnit\Framework\TestCase;

/**
 * `ActionOutcome` 纯函数单测（零 IO）。
 *
 * 本文件的核心是**四条容易搞反的判据**，它们全部来自实测的响应形态：
 *
 * ① **`202 pending` 不是失败，但 `ok=true` 也不是成功。**
 *   `GatewayPushClient::ok` 的定义是「2xx 且 code=0」，对 `202` 为真 ——
 *   故只看 `ok` 会把「还没算完」当成「已完成」。`isSuccess()` 只认真 `done`。
 *
 * ② **`404 + 4004` 必须先于 `pending` 判定。**
 *   补查未命中时服务端把 `data.status` 也写成 `pending`
 *   （`src/Api/Bootstrap.php:600`）—— 若先判 `pending`，已过期的补查会被当成「还在跑」，
 *   前端会一直轮询到行动超时。
 *
 * ③ **`retryable` 只对 `pending` 为真。**
 *   补查是纯读可重试；而 `transport`（连不上）**不可重试** ——
 *   动作可能已在服务端执行（`report` 累加计数、`notify` 再推一条），
 *   自动重发会产生重复业务效果。这是本类最重要的一条不变量。
 *
 *   ⚠ 「补查能不能重试」与「**动作**能不能再发一次」是**两个问题**，由
 *   `retryable` 与 {@see ActionOutcome::RESEND} 分别回答，见第 ⑤ 节。
 *   把两者合成一个布尔值用，必然在某个状态上说错话（界面已踩过一次）。
 *
 * ④ **`failed` 的 HTTP 状态码是 200。**
 *   执行后业务失败走 `HTTP 200 + code=业务码`（`actionResponse()`），
 *   只看 HTTP 必然把失败当成功。
 */
final class ActionOutcomeTest extends TestCase
{
    /* =====================================================================
     | ① 四种主形态
     ===================================================================== */

    public function testDoneIsRecognisedAndRetryableIsFalse(): void
    {
        $o = ActionOutcome::of([
            'ok' => true,
            'status' => 200,
            'code' => 0,
            'msg' => 'ok',
            'data' => [
                'request_id' => 'f96e53866166af3e',
                'status' => 'done',
                'result' => ['action' => 'echo', 'at' => 1789983030],
            ],
        ]);

        $this->assertSame(ActionOutcome::STATE_DONE, $o['state']);
        $this->assertTrue(ActionOutcome::isSuccess($o));
        $this->assertFalse($o['retryable'], '已完成的任务不需要补查');
        $this->assertSame('f96e53866166af3e', $o['request_id']);
        $this->assertSame('echo', $o['result']['action']);
        $this->assertSame('ok', ActionOutcome::tone(ActionOutcome::STATE_DONE));
    }

    public function testFailedKeepsHttp200ButIsNotSuccess(): void
    {
        // 实测形态：actionResponse() 对动作自身失败走 200 + 业务码
        $o = ActionOutcome::of([
            'ok' => false,
            'status' => 200,
            'code' => 4007,
            'msg' => '缺少必要参数：topic',
            'data' => [
                'request_id' => 'abc',
                'status' => 'failed',
                // 注意：失败时服务端**不回 result**
            ],
        ]);

        $this->assertSame(ActionOutcome::STATE_FAILED, $o['state']);
        $this->assertFalse(ActionOutcome::isSuccess($o));
        $this->assertFalse($o['retryable'], '业务失败不可自动重试（可能已产生副作用）');
        $this->assertSame(4007, $o['code']);
        $this->assertSame(200, $o['http'], 'HTTP 仍是 200 —— 这正是最易误判之处');
        $this->assertSame([], $o['result']);
        $this->assertSame('bad', ActionOutcome::tone(ActionOutcome::STATE_FAILED));
    }

    public function testPendingIsNotFailureAndIsRetryable(): void
    {
        $o = ActionOutcome::of([
            'ok' => true, // ⚠ 2xx + code=0 ⇒ client 的 ok 为 true
            'status' => 202,
            'code' => 0,
            'msg' => 'accepted',
            'data' => ['request_id' => 'def', 'status' => 'pending'],
        ]);

        $this->assertSame(ActionOutcome::STATE_PENDING, $o['state']);
        $this->assertTrue($o['retryable'], 'pending 是唯一的可重试状态（补查是纯读）');
        $this->assertFalse(ActionOutcome::isSuccess($o), '超窗未完成不是成功');
        $this->assertSame('warn', ActionOutcome::tone(ActionOutcome::STATE_PENDING), 'pending 必须是 warn 而非 bad');
    }

    public function testRejectedCoversPreEnqueueFailures(): void
    {
        $cases = [
            [400, GatewayPushClient::CODE_BAD_PARAM, 'target_type 必须是 uid / device / client 之一'],
            [401, GatewayPushClient::CODE_UNAUTHORIZED, '该动作要求身份，请提供 uid'],
            [401, GatewayPushClient::CODE_BAD_SIGN, '签名不匹配'],
            [400, GatewayPushClient::CODE_UNKNOWN_CMD, '动作未开放 HTTP 通道：session'],
            [429, GatewayPushClient::CODE_RATE_LIMIT, '请求频率超限'],
            [503, 5030, '队列积压'],
        ];

        foreach ($cases as [$http, $code, $msg]) {
            $o = ActionOutcome::of([
                'ok' => false,
                'status' => $http,
                'code' => $code,
                'msg' => $msg,
                'data' => [],
            ]);

            $this->assertSame(
                ActionOutcome::STATE_REJECTED,
                $o['state'],
                'HTTP ' . $http . ' code=' . $code . ' 应当是「未入队」'
            );
            $this->assertFalse($o['retryable'], '被拒也不自动重试 —— 由用户改参数后重发');
            $this->assertSame($msg, $o['msg'], '人话文案必须原样透传，不能被兜底文案覆盖');
        }
    }

    /* =====================================================================
     | ② 顺序陷阱：404+4004 先于 pending
     ===================================================================== */

    public function testExpiredWinsOverPendingBecauseServerAlsoReportsPendingOn404(): void
    {
        // 实测：handleActionResult 未命中时 data.status 也是 'pending'。
        // 若判定顺序反了，这一条会得到 pending ⇒ 前端无限轮询到超时。
        $o = ActionOutcome::of([
            'ok' => false,
            'status' => 404,
            'code' => GatewayPushClient::CODE_NOT_FOUND,
            'msg' => '动作结果不存在或已过期',
            'data' => [
                'request_id' => 'ghi',
                'status' => 'pending',
                'hint' => '任务可能仍在执行中，或结果已超过 ACTION_RESULT_TTL 被回收',
            ],
        ]);

        $this->assertSame(
            ActionOutcome::STATE_EXPIRED,
            $o['state'],
            '404+4004 必须优先判为「未命中」，否则已过期的补查会被当成 pending 无限轮询'
        );
        $this->assertFalse($o['retryable'], '补查窗口外不可重试');
        $this->assertStringContainsString('不替你猜', $o['note']);
    }

    public function testPendingIsNotMistakenForExpiredWhenHttpIs202(): void
    {
        // 反向护栏：202 的 pending 不得被误判为 expired
        $o = ActionOutcome::of([
            'ok' => true,
            'status' => 202,
            'code' => 0,
            'msg' => 'accepted',
            'data' => ['request_id' => 'jkl', 'status' => 'pending'],
        ]);

        $this->assertSame(ActionOutcome::STATE_PENDING, $o['state']);
    }

    /* =====================================================================
     | ③ transport 不可重试
     ===================================================================== */

    public function testTransportIsNotRetryable(): void
    {
        $o = ActionOutcome::of([
            'ok' => false,
            'status' => 0,
            'code' => GatewayPushClient::CODE_TRANSPORT,
            'msg' => '连接主项目 API 失败：cURL error 7',
            'data' => [],
        ]);

        $this->assertSame(ActionOutcome::STATE_TRANSPORT, $o['state']);
        $this->assertFalse(
            $o['retryable'],
            '★ 关键不变量：连不上时**不得**自动重发 —— 动作可能已在服务端执行'
            . '（report 会重复计数、notify 会重复推送）'
        );
        $this->assertStringContainsString('不要自动重发', $o['note']);
        $this->assertSame('bad', ActionOutcome::tone(ActionOutcome::STATE_TRANSPORT));
    }

    public function testTransportWinsOverAnyConfusingStatus(): void
    {
        // 防御性：即使 status 是 200 且 data 里有 status=done，只要 code 是传输错误码，
        // 就必须判 transport —— 连响应都没有时，其余字段全部不可信。
        $o = ActionOutcome::of([
            'ok' => false,
            'status' => 200,
            'code' => GatewayPushClient::CODE_TRANSPORT,
            'msg' => 'x',
            'data' => ['status' => 'done'],
        ]);

        $this->assertSame(ActionOutcome::STATE_TRANSPORT, $o['state']);
    }

    /* =====================================================================
     | 健壮性
     ===================================================================== */

    public function testMissingFieldsDoNotExplode(): void
    {
        $o = ActionOutcome::of([
            'ok' => false,
            'status' => 0,
            'code' => 0,
            'msg' => '',
            'data' => [],
        ]);

        // 没有 status / 没有 data：只能落进 rejected（最保守的「没成功」归类）
        $this->assertSame(ActionOutcome::STATE_REJECTED, $o['state']);
        $this->assertFalse(ActionOutcome::isSuccess($o));
        $this->assertNotSame('', $o['note'], '每个状态都必须有兜底说明文案');
    }

    public function testNonArrayDataIsTolerated(): void
    {
        // GatewayPushClient::request() 在 data 非数组时会回落成整个 $json，
        // 但调用方也可能直接把原始 json 丢进来 —— 不能因此炸掉
        $o = ActionOutcome::of([
            'ok' => false,
            'status' => 500,
            'code' => 5000,
            'msg' => '服务端内部错误',
            'data' => 'not-an-array',
        ]);

        $this->assertSame(ActionOutcome::STATE_REJECTED, $o['state']);
        $this->assertSame([], $o['result']);
        $this->assertSame('', $o['request_id']);
    }

    public function testEmptyMsgFallsBackToTheStateNote(): void
    {
        // 服务端没给 msg 时，页面不该显示空白 —— 用状态说明兜底，且兜底内容要能解释这个状态
        $o = ActionOutcome::of([
            'ok' => true,
            'status' => 202,
            'code' => 0,
            'msg' => '',
            'data' => ['request_id' => 'mno', 'status' => 'pending'],
        ]);

        $this->assertNotSame('', $o['msg']);
        $this->assertStringContainsString('不是失败', $o['msg']);
    }

    public function testEveryStateHasLabelNoteAndTone(): void
    {
        $states = [
            ActionOutcome::STATE_DONE,
            ActionOutcome::STATE_FAILED,
            ActionOutcome::STATE_PENDING,
            ActionOutcome::STATE_REJECTED,
            ActionOutcome::STATE_EXPIRED,
            ActionOutcome::STATE_TRANSPORT,
        ];

        foreach ($states as $state) {
            $this->assertArrayHasKey($state, ActionOutcome::NOTES, $state . ' 缺 NOTES');
            $this->assertNotSame('', ActionOutcome::NOTES[$state]);
            $this->assertNotSame($state, ActionOutcome::label($state), $state . ' 缺中文标签');
            $this->assertContains(ActionOutcome::tone($state), ['ok', 'warn', 'bad'], $state . ' 的颜色语义非法');
        }

        // 只有 done 是 ok 色调；pending/expired 是 warn；其余是 bad
        $this->assertSame('ok', ActionOutcome::tone(ActionOutcome::STATE_DONE));
        $this->assertSame('warn', ActionOutcome::tone(ActionOutcome::STATE_EXPIRED));
        $this->assertSame('bad', ActionOutcome::tone(ActionOutcome::STATE_REJECTED));
    }

    public function testOnlyPendingIsRetryableAcrossAllStates(): void
    {
        foreach ($this->stateSamples() as $expected => $res) {
            $o = ActionOutcome::of($res);
            $this->assertSame($expected, $o['state'], '样本与状态不匹配');
            $this->assertSame(
                $expected === ActionOutcome::STATE_PENDING,
                $o['retryable'],
                '★ 只有 pending 可重试，实际 ' . $expected . ' 给的是 ' . var_export($o['retryable'], true)
            );
            $this->assertSame($expected === ActionOutcome::STATE_DONE, ActionOutcome::isSuccess($o));
        }
    }

    /* =====================================================================
     | ⑤ 「补查能不能重试」≠「动作能不能再发一次」
     |
     | 2026-09-23 修的界面缺陷：回执行一度用 `retryable` 渲染「重发」判定，
     | 于是 `rejected`（最该重发的状态）被显示成「不要重发」，
     | 而 `done`（最不该重发的状态）拿到了「重发安全」的措辞 —— 两个方向都错。
     | 本节把两个判据的**分离**钉死。
     ===================================================================== */

    /** @return array<string, array<string, mixed>> 六个状态的样本请求（与上一节同源） */
    private function stateSamples(): array
    {
        return [
            ActionOutcome::STATE_DONE => ['ok' => true, 'status' => 200, 'code' => 0, 'msg' => '', 'data' => ['status' => 'done']],
            ActionOutcome::STATE_FAILED => ['ok' => false, 'status' => 200, 'code' => 4007, 'msg' => '', 'data' => ['status' => 'failed']],
            ActionOutcome::STATE_PENDING => ['ok' => true, 'status' => 202, 'code' => 0, 'msg' => '', 'data' => ['status' => 'pending']],
            ActionOutcome::STATE_REJECTED => ['ok' => false, 'status' => 400, 'code' => 4000, 'msg' => '', 'data' => []],
            ActionOutcome::STATE_EXPIRED => ['ok' => false, 'status' => 404, 'code' => 4004, 'msg' => '', 'data' => ['status' => 'pending']],
            ActionOutcome::STATE_TRANSPORT => ['ok' => false, 'status' => 0, 'code' => 10002, 'msg' => '', 'data' => []],
        ];
    }

    /**
     * ★ 核心回归护栏：`retryable` 与 `resend` 是两个判据，且在 `rejected` 上相反。
     */
    public function testResendVerdictIsIndependentFromRetryable(): void
    {
        $samples = $this->stateSamples();

        foreach ($samples as $state => $res) {
            $o = ActionOutcome::of($res);

            $this->assertSame($state, $o['state'], '样本与状态不匹配：' . $state);
            $this->assertArrayHasKey($state, ActionOutcome::RESEND, $state . ' 缺重发判定');
            $this->assertSame(ActionOutcome::RESEND[$state], $o['resend'], $state . ' 的 resend 与 RESEND 表不一致');
            $this->assertContains(
                $o['resend'],
                [ActionOutcome::RESEND_SAFE, ActionOutcome::RESEND_UNSAFE, ActionOutcome::RESEND_UNKNOWN],
                'resend 越出三值值域'
            );
            $this->assertNotSame('', $o['resend_label'], $state . ' 缺重发短标签');
            $this->assertNotSame('', $o['resend_note'], $state . ' 缺重发解释');
            $this->assertContains($o['resend_tone'], ['ok', 'warn', 'bad'], $state . ' 的重发色调非法');
        }

        // ★ 同一个布尔值在这一态上会同时给出两个相反的答案 —— 这就是当初界面撒谎的地方
        $rejected = ActionOutcome::of($samples[ActionOutcome::STATE_REJECTED]);
        $this->assertFalse($rejected['retryable'], '未入队 ⇒ 根本没有回执可补查');
        $this->assertSame(
            ActionOutcome::RESEND_SAFE,
            $rejected['resend'],
            '★ 但重发**绝对安全**（动作从未执行）—— 用 retryable 渲染该行会显示成「不要重发」'
        );

        // 反向：已完成 / 已执行过的三态不可重发
        foreach ([ActionOutcome::STATE_DONE, ActionOutcome::STATE_FAILED, ActionOutcome::STATE_PENDING] as $state) {
            $this->assertSame(
                ActionOutcome::RESEND_UNSAFE,
                ActionOutcome::of($samples[$state])['resend'],
                '★ ' . $state . ' 必须判「不要重发」—— 重发会产生重复业务效果'
            );
        }

        // 结论未定的两态：既不是「安全」也不是「已执行」，而是「不知道」
        foreach ([ActionOutcome::STATE_EXPIRED, ActionOutcome::STATE_TRANSPORT] as $state) {
            $this->assertSame(
                ActionOutcome::RESEND_UNKNOWN,
                ActionOutcome::of($samples[$state])['resend'],
                $state . ' 只能判「不知道」—— 硬塞进安全/不安全就是替调用方猜'
            );
        }
    }

    /**
     * 重发文案的**关键措辞**：这是回执里运维唯一能看到的「能不能再点一次」的结论。
     *
     * 措辞即契约 —— `tests/Frontend/action_render_check.js` 只能验「后端下发什么就显示什么」，
     * 真正的人话在这里钉住。
     */
    public function testResendNotesCarryTheOperationalVerdict(): void
    {
        foreach (array_keys(ActionOutcome::RESEND) as $state) {
            $this->assertArrayHasKey($state, ActionOutcome::RESEND_NOTES, $state . ' 缺重发解释文案');
            $this->assertNotSame('', ActionOutcome::RESEND_NOTES[$state]);
        }

        $this->assertStringContainsString('重发安全', ActionOutcome::RESEND_NOTES[ActionOutcome::STATE_REJECTED]);
        $this->assertStringContainsString('不要重发', ActionOutcome::RESEND_NOTES[ActionOutcome::STATE_DONE]);
        $this->assertStringContainsString('不要重发', ActionOutcome::RESEND_NOTES[ActionOutcome::STATE_FAILED]);
        $this->assertStringContainsString('不要重发', ActionOutcome::RESEND_NOTES[ActionOutcome::STATE_PENDING]);
        $this->assertStringContainsString('不要自动重发', ActionOutcome::RESEND_NOTES[ActionOutcome::STATE_EXPIRED]);
        $this->assertStringContainsString('不要自动重发', ActionOutcome::RESEND_NOTES[ActionOutcome::STATE_TRANSPORT]);
        // 解释里必须出现「为什么」—— 光说「不要重发」会被当成保守的琐碎限制而被绕过
        $this->assertStringContainsString('重复业务效果', ActionOutcome::RESEND_NOTES[ActionOutcome::STATE_DONE]);
        $this->assertStringContainsString('从未执行', ActionOutcome::RESEND_NOTES[ActionOutcome::STATE_REJECTED]);
        $this->assertStringContainsString(
            (string)ActionCatalog::RESULT_TTL_MIRROR,
            ActionOutcome::RESEND_NOTES[ActionOutcome::STATE_EXPIRED],
            'expired 的解释必须带上真实窗口秒数（它正是「两义」的由来）'
        );
    }

    public function testResendLabelsAndTonesArePresentationReady(): void
    {
        $this->assertSame('重发安全', ActionOutcome::resendLabel(ActionOutcome::STATE_REJECTED));
        $this->assertSame('不要重发', ActionOutcome::resendLabel(ActionOutcome::STATE_DONE));
        $this->assertSame('待确认', ActionOutcome::resendLabel(ActionOutcome::STATE_TRANSPORT));

        $this->assertSame('ok', ActionOutcome::resendTone(ActionOutcome::STATE_REJECTED));
        $this->assertSame('bad', ActionOutcome::resendTone(ActionOutcome::STATE_DONE));
        $this->assertSame(
            'warn',
            ActionOutcome::resendTone(ActionOutcome::STATE_TRANSPORT),
            '「不知道」不是故障 —— 染红会让运维去追一件可能根本没发生的事'
        );

        // 未登记的状态不得抛异常（前端可能收到服务端新加的状态）
        $this->assertSame('待确认', ActionOutcome::resendLabel('not-a-state'));
        $this->assertSame('warn', ActionOutcome::resendTone('not-a-state'));
    }

    public function testOfEmitsResendQuadrupleAlongsideStateFields(): void
    {
        $o = ActionOutcome::of([
            'ok' => true,
            'status' => 200,
            'code' => 0,
            'msg' => 'ok',
            'data' => ['request_id' => 'abc', 'status' => 'done'],
        ]);

        foreach (
            [
                'state', 'code', 'http', 'msg', 'request_id', 'result', 'retryable', 'note',
                'resend', 'resend_label', 'resend_tone', 'resend_note',
            ] as $key
        ) {
            $this->assertArrayHasKey($key, $o, '响应体缺字段：' . $key . '（前端回执少一行，且不会报错）');
        }
    }
}
