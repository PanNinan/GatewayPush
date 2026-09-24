<?php
/**
 * admin · 服务层 —— ActionOutcome。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace app\service;

/**
 * `/action` 与 `GET /action/{id}` 响应的**语义归一层**（纯函数，零 IO，可独立单测）。
 *
 * ## 为什么必须集中归一，而不能让前端直接看 HTTP 状态码
 *
 * 主项目的动作回执有**四种**互不相同的形态，其中两种的 HTTP 状态码与成败**方向相反**：
 *
 * | 情形 | HTTP | `code` | `data.status` | 判成败 |
 * |---|---|---|---|---|
 * | 执行完成 | `200` | `0` | `done` | 成功 |
 * | **执行后业务失败**（如缺参 4007） | `200` | **业务码 ≠ 0** | `failed` | **失败，但 HTTP 是 200** |
 * | **超窗未完成** | **`202`** | `0` | `pending` | **不是失败**（`GatewayPushClient::ok` 为 true） |
 * | 入队前失败（未知动作 / 未开放通道 / 验签 / 限流 / 积压） | `4xx`/`5xx` | 4000~5030 | 无 | 失败 |
 * | 补查未命中 | `404` | `4004` | `pending` | **两义**（仍在执行 / 已回收） |
 *
 * ⚠ 单看 `202` 会被误判成失败、单看 `200` 会把业务失败误判成成功 ——
 * 而 `GatewayPushClient::ok`（`2xx && code===0`）对 `202 pending` 返回 **true**，
 * 光看 `ok` 又会把「还没算完」当成「已完成」。故必须同时看 `status` 与 `code`。
 *
 * ## 两条**判据不同**的不变量：`retryable` 与 `RESEND`
 *
 * 这是本类最容易搞混的一处，也是界面上最容易撒谎的一处：
 *
 * | 字段 | 回答的问题 | 值域 |
 * |---|---|---|
 * | `retryable` | **补查**能不能重试？ | 只对 `pending` 为真（`GET /action/{id}` 是**纯读**） |
 * | {@see self::RESEND} | **动作**能不能再发一次？ | `safe` / `unsafe` / `unknown` |
 *
 * 两者**在 `rejected` 上正好相反**：它从未入队，补查无从谈起（`retryable=false`），
 * 但重发绝对安全。用一个布尔值同时表达这两件事，必然在某个状态上说错话 ——
 * 2026-09-23 的 `action.js` 就是这样：行标签写「可重试」，文案却在讲「重发会造成重复业务效果」，
 * 于是「未入队」这个**最该重发的状态**被显示成「不要重发」。
 *
 * 重发之所以要单独建模：`report` 是累加计数、`notify` 会再推一条消息，
 * 重发即产生**重复业务效果**。而调试页用户的行为习惯恰恰是「没看到结果就再点一次」，
 * 故这一列必须在回执里**可扫读**（短标签 + 色调），而不是埋在长句子里。
 */
final class ActionOutcome
{
    /* =====================================================================
     | 状态枚举
     ===================================================================== */

    /** 执行完成，`result` 有效 */
    public const STATE_DONE = 'done';

    /** 执行后业务失败（HTTP 仍可能是 200），`code` 是业务码 */
    public const STATE_FAILED = 'failed';

    /** 超窗未完成 —— **不是失败**，凭 request_id 补查 */
    public const STATE_PENDING = 'pending';

    /** 入队前失败（未知动作 / 未开放 HTTP / 验签 / 限流 / 队列积压） */
    public const STATE_REJECTED = 'rejected';

    /** 补查未命中：`404` + `4004` 的**两义**情形，服务端刻意不区分 */
    public const STATE_EXPIRED = 'expired';

    /** 根本没连上主项目（`GatewayPushClient` 自造的 0 + 10002） */
    public const STATE_TRANSPORT = 'transport';

    /** 每状态的一句话解释（后端下发，前端不得自行编词） */
    public const NOTES = [
        self::STATE_DONE => '动作已执行完成，result 即服务端回执。',
        self::STATE_FAILED => '动作进过队列但执行失败 —— 注意此时 HTTP 状态码仍是 200，'
            . '只看 HTTP 会把失败误判成成功。',
        self::STATE_PENDING => '超窗未完成，**不是失败**。可凭 request_id 在回执保留窗口内补查。',
        self::STATE_REJECTED => '请求在**入队前**就被拒（未知动作 / 未开放 HTTP 通道 / 验签失败 / '
            . '限流 / 队列积压），动作从未执行，重试是安全的。',
        self::STATE_EXPIRED => '补查未命中。服务端对该响应刻意不区分「仍在执行」与「已超 '
            . ActionCatalog::RESULT_TTL_MIRROR . 's 被回收」两种含义 —— 本页同样不替你猜。',
        self::STATE_TRANSPORT => '未取到响应（连不上主项目 / 超时）。**不要自动重发** —— '
            . '动作可能已在服务端执行，重发会造成重复业务效果（如 report 重复计数）。',
    ];

    /* =====================================================================
     | 「动作能不能再发一次」—— 与 `retryable`（补查能不能重试）**刻意分开**
     |
     | 三值而不是布尔：`unknown` 是**真实存在**的结论，把它硬塞进
     | 「可以 / 不可以」里，就等于替调用方猜了一件它无从判断的事。
     ===================================================================== */

    /** 动作从未执行（入队前被拒）⇒ 重发安全 */
    public const RESEND_SAFE = 'safe';

    /** 动作已执行、或可能已执行 ⇒ 重发会产生重复业务效果 */
    public const RESEND_UNSAFE = 'unsafe';

    /** 无法判定动作是否执行过 ⇒ 不要自动重发，人工核对 */
    public const RESEND_UNKNOWN = 'unknown';

    /**
     * 状态 → 重发判定。
     *
     * ⚠ **不是** `retryable` 的别名：见类注释的对照表。
     *
     * `expired` / `transport` 给 `unknown` 而非 `unsafe`：这两态下**确实不知道**
     * 动作有没有执行（补查未命中可能是「还在跑」也可能是「已回收」；连不上时连响应都没有）。
     * 结论都是「不要自动重发」，但理由不同 —— 理由写在 {@see self::RESEND_NOTES}，界面照抄。
     *
     * @var array<string, string>
     */
    public const RESEND = [
        self::STATE_DONE => self::RESEND_UNSAFE,
        self::STATE_FAILED => self::RESEND_UNSAFE,
        self::STATE_PENDING => self::RESEND_UNSAFE,
        self::STATE_REJECTED => self::RESEND_SAFE,
        self::STATE_EXPIRED => self::RESEND_UNKNOWN,
        self::STATE_TRANSPORT => self::RESEND_UNKNOWN,
    ];

    /**
     * 重发判定的完整解释（每状态一句，界面**原样**展示，前端不得编词）。
     *
     * @var array<string, string>
     */
    public const RESEND_NOTES = [
        self::STATE_DONE => '不要重发：动作已执行完成，重发会产生重复业务效果'
            . '（report 会重复计数、notify 会重复推送）。',
        self::STATE_FAILED => '不要重发：动作**已经进入队列并执行过**（失败发生在执行阶段），'
            . '重发会产生重复业务效果。请先按 msg / result 修正参数，再决定是否重新发起。',
        self::STATE_PENDING => '补查可以放心重试（纯读），但**不要重发动作** —— '
            . '它可能仍在执行、也可能已经执行完，重发会造成重复业务效果。',
        self::STATE_REJECTED => '重发安全：请求在**入队前**就被拒（未知动作 / 未开放 HTTP 通道 / '
            . '验签失败 / 限流 / 队列积压），动作从未执行。',
        self::STATE_EXPIRED => '不要自动重发：补查未命中，无法判定动作是否执行过 —— '
            . '服务端刻意不区分「仍在执行」与「已超过 ' . ActionCatalog::RESULT_TTL_MIRROR . 's 被回收」，'
            . '请按 request_id 人工核对主项目日志。',
        self::STATE_TRANSPORT => '不要自动重发：未取到响应，动作**可能已在服务端执行**'
            . '（如 report 已重复计数）。请先核对，再决定是否重发。',
    ];

    /* =====================================================================
     | 归一
     ===================================================================== */

    /**
     * 把一个 `GatewayPushClient` 的归一化结果转成**唯一权威状态**。
     *
     * 判定顺序是刻意的，不可重排：
     *   1. `transport` —— 连响应都没有，后续字段全不可信；
     *   2. `404` + `4004` —— 必须**先于** `pending` 判定：补查未命中时服务端也会把
     *      `data.status` 写成 `pending`（见 `src/Api/Bootstrap.php:600`），
     *      若先判 `pending` 就会把「已过期的补查」永久当成「还在跑」，前端会无限轮询到超时；
     *   3. `202` 或 `status=pending` → `pending`；
     *   4. `status=done` / `status=failed`；
     *   5. 其余（HTTP 4xx/5xx 且非 4004）一律 `rejected`。
     *
     * @param array{ok?: bool, status?: int, code?: int, msg?: string, data?: mixed} $res
     *                                                                                    刻意声明为**宽松 shape**：`GatewayPushClient::request()` 在 `data` 非数组时会把整个
     *                                                                                    `$json` 回落进来，调用方也可能直接把原始解码结果丢进来 ——
     *                                                                                    本方法必须容忍缺字段与异常类型（`testNonArrayDataIsTolerated` 钉住这一点），
     *                                                                                    而不是把「调用方传得不对」变成一次 500
     *
     * @return array{
     *     state: string,
     *     code: int,
     *     http: int,
     *     msg: string,
     *     request_id: string,
     *     result: array<string, mixed>,
     *     retryable: bool,
     *     note: string,
     *     resend: string,
     *     resend_label: string,
     *     resend_tone: string,
     *     resend_note: string
     * }
     */
    public static function of(array $res): array
    {
        $http = isset($res['status']) && is_int($res['status']) ? $res['status'] : 0;
        $code = isset($res['code']) && is_int($res['code']) ? $res['code'] : 0;
        $msg = isset($res['msg']) && is_string($res['msg']) ? $res['msg'] : '';
        $data = isset($res['data']) && is_array($res['data']) ? $res['data'] : [];
        $status = isset($data['status']) && is_string($data['status']) ? $data['status'] : '';
        $requestId = isset($data['request_id']) && is_string($data['request_id']) ? $data['request_id'] : '';
        $result = isset($data['result']) && is_array($data['result']) ? $data['result'] : [];

        if ($code === GatewayPushClient::CODE_TRANSPORT) {
            $state = self::STATE_TRANSPORT;
        } elseif ($http === 404 && $code === GatewayPushClient::CODE_NOT_FOUND) {
            $state = self::STATE_EXPIRED;
        } elseif ($http === 202 || $status === 'pending') {
            $state = self::STATE_PENDING;
        } elseif ($status === 'done') {
            $state = self::STATE_DONE;
        } elseif ($status === 'failed') {
            $state = self::STATE_FAILED;
        } else {
            $state = self::STATE_REJECTED;
        }

        return [
            'state' => $state,
            'code' => $code,
            'http' => $http,
            'msg' => $msg !== '' ? $msg : self::NOTES[$state],
            'request_id' => $requestId,
            'result' => $result,
            // 只有 pending 可自动重试（补查是纯读）。详见类注释的不变量。
            'retryable' => $state === self::STATE_PENDING,
            'note' => self::NOTES[$state],
            // 「动作能不能再发一次」——**独立于** `retryable` 的第二条判据。
            // 四件套（值 / 短标签 / 色调 / 解释）与上面的 state 同形，
            // 目的是让前端**一个字都不用编**（含颜色语义）。
            'resend' => self::RESEND[$state],
            'resend_label' => self::resendLabel($state),
            'resend_tone' => self::resendTone($state),
            'resend_note' => self::RESEND_NOTES[$state],
        ];
    }

    /**
     * 是否算「成功」——**面向运维语义**，与 `GatewayPushClient::ok` 刻意不同。
     *
     * `ok=true` 只说明「HTTP 2xx 且业务码为 0」，对 `202 pending` 也为真；
     * 本方法要求**执行真的完成了**。
     *
     * @param array<string, mixed> $outcome {@see of()} 的产物
     */
    public static function isSuccess(array $outcome): bool
    {
        return ($outcome['state'] ?? '') === self::STATE_DONE;
    }

    /**
     * 状态的中文短标签（用于徽标）。
     */
    public static function label(string $state): string
    {
        $labels = [
            self::STATE_DONE => '已完成',
            self::STATE_FAILED => '执行失败',
            self::STATE_PENDING => '超窗未完成',
            self::STATE_REJECTED => '未入队（被拒）',
            self::STATE_EXPIRED => '补查未命中',
            self::STATE_TRANSPORT => '未取到响应',
        ];

        return $labels[$state] ?? $state;
    }

    /**
     * 徽标色调（前端把 class 拼到 `.chip.<tone>`）。
     *
     * `pending` 用 `warn` 而非 `bad`：它是**未完成**，不是失败 —— 色调必须与语义一致，
     * 否则运维第一眼就会把它当故障去追。
     */
    public static function tone(string $state): string
    {
        $tones = [
            self::STATE_DONE => 'ok',
            self::STATE_FAILED => 'bad',
            self::STATE_PENDING => 'warn',
            self::STATE_REJECTED => 'bad',
            self::STATE_EXPIRED => 'warn',
            self::STATE_TRANSPORT => 'bad',
        ];

        return $tones[$state] ?? 'warn';
    }

    /**
     * 重发判定的中文短标签（回执里的可扫读徽标）。
     *
     * 与 {@see self::label()} 同一分工：**前端不得自行编词**。
     * 三值的用词刻意不对齐 `state` 的用词 —— 「未入队（被拒）」是状态，
     * 「重发安全」是**结论**，两者在界面上是两行。
     *
     * @param string $state 已归一的 `state`（见 {@see self::of()}）
     */
    public static function resendLabel(string $state): string
    {
        $labels = [
            self::RESEND_SAFE => '重发安全',
            self::RESEND_UNSAFE => '不要重发',
            self::RESEND_UNKNOWN => '待确认',
        ];

        // 先归一到三值域，再取标签 —— 这样第二层索引是**可证存在**的，
        // 不需要（也不该）再挂一个 `?? 兜底`（PHPStan L6 会报「左侧恒存在」）。
        return $labels[self::RESEND[$state] ?? self::RESEND_UNKNOWN];
    }

    /**
     * 重发判定的色调（值域与 {@see self::tone()} 一致，前端直接拼 `.tag.<tone>`）。
     *
     * `safe` 用 `ok`、`unsafe` 用 `bad`、`unknown` 用 `warn` ——
     * 「待确认」刻意**不用** `bad`：它不是故障，只是结论未定，
     * 染成红色会让运维去追一件可能根本没发生的事。
     */
    public static function resendTone(string $state): string
    {
        $tones = [
            self::RESEND_SAFE => 'ok',
            self::RESEND_UNSAFE => 'bad',
            self::RESEND_UNKNOWN => 'warn',
        ];

        return $tones[self::RESEND[$state] ?? self::RESEND_UNKNOWN];
    }
}
