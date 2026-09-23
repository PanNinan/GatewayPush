<?php

declare(strict_types=1);

namespace app\service;

use GatewayPush\Business\Message;
use GatewayPush\Business\Push;

/**
 * 推送表单的**纯函数判定层**（零 IO：不碰 Redis / MySQL / 主项目 HTTP，可独立单测）。
 *
 * 与 `MetricsDeriver`、`SessionInspector` 的静态部分同一口径：**所有阈值由调用方注入**，
 * 本类只做「形态判定 + 归一 + 字节预算」，副作用全部留给控制器与仓储。
 *
 * ## 为什么必须以「提交前预校验」为主，而不能依赖服务端报错
 *
 * 主项目的 `PUSH_PAYLOAD_MAX`(4096) 判定点在 **`Push::dispatch()`**
 * （`src/Business/Push.php:421`）—— 那是 **business 进程**，即 `/push` 已经返回
 * `200 accepted`**之后**。超限时的处理是 `Monitor::incr('push_fail')` + `Logger::warn`，
 * **不给调用方任何反馈**。⇒ 用户提交 5KB payload 会看到「已受理」，而**服务端什么都没做**。
 *
 * 因此本类用 `Message::encode()` 做**逐字节同源**的预估，在提交前把这类载荷挡掉
 * （`Message::encode()` 与服务端 `Push::dispatch()` 调用的是同一个方法、同一组 JSON flag）。
 *
 * ## 三条「响应里读不出来」的语义（均已落成文案常量由后端下发）
 *
 * | 事实 | 依据 |
 * |---|---|
 * | `code:0` 只表示**已入队**，不表示已投递 | `/push` 是异步受理；服务端不落逐条投递历史 |
 * | **本次是否被去重不可判定** | `Push::enqueue()` 的 `setNxEx` 非首次只做 `Monitor::incr('push_dedup')` + `Logger::debug`，响应与首次**逐字段相同** |
 * | 超限载荷可能「已受理但被静默丢弃」 | 见上段；判定在 business 进程 |
 *
 * 这三条都**不能**靠前端猜，故以常量形式由后端下发 —— 各处自行编词必然出现
 * 「一处说已投递、另一处说仅受理」的自相矛盾（与 P2 的 `REVOKE_NOTE` 同一处理方式）。
 */
final class Pusher
{
    /* =====================================================================
     | 枚举（**全部引用主项目常量**，不写字面量）
     ===================================================================== */

    /**
     * 可用的目标类型。
     *
     * ⚠ **只有三个值**，且**不含主题广播** —— `Push::enqueueTopic()` 没有 HTTP 入口
     * （`src/Api/Bootstrap.php` 只分发 `/push` 与 `/action`）。UI 若出现「按主题推送」
     * 就是虚假功能（设计文档 §7 P3 已把它列为风险项）。
     *
     * 直接取 `Push::TARGET_*` 而非本地字面量：这两组值一旦漂移，后台会开始发
     * 服务端必然 400 的请求。已实测 `Push` 类在 admin 侧可安全裸加载
     * （该类对 `GatewayWorker\Lib\Gateway` 只有 `use` 别名，取常量不触发连接池初始化）。
     */
    public const TARGET_TYPES = [Push::TARGET_UID, Push::TARGET_DEVICE, Push::TARGET_CLIENT];

    /**
     * `offline_mode` 的取值集合。空串 = **取服务端默认值**（不在此处重复声明默认值是哪个）。
     *
     * ⚠ 服务端对未知值是**静默回落默认**（`Push::resolveMode()`），而 `target_type` 的未知值
     * 会被 API 层**显式 400**。两者口径本就不同；本类对两者都做严格校验（fail-closed）——
     * 下拉框不可能产出非法值，出现非法值即说明请求是手搓的。
     */
    public const OFFLINE_MODES = ['', Push::MODE_DROP, Push::MODE_QUEUE];

    /** 目标类型的中文说明（与设计文档 §6.5 的语义表逐行对应） */
    public const TARGET_LABELS = [
        Push::TARGET_UID => '按用户 —— 该 uid 名下的全部在线连接',
        Push::TARGET_DEVICE => '按设备 —— 单对一核心场景，命中 1 条连接',
        Push::TARGET_CLIENT => '按连接 —— 直接指定 clientId，仅调试用',
    ];

    /** 离线策略的中文说明 */
    public const OFFLINE_LABELS = [
        '' => '服务端默认（PUSH_OFFLINE_MODE；本机为 queue）',
        Push::MODE_DROP => '丢弃 —— 目标不在线则直接丢，仅计指标',
        Push::MODE_QUEUE => '离线缓存 —— 写入 push:offline:{uid}，重连后补投（单用户上限 '
            . self::OFFLINE_MAX_MIRROR . ' 条）',
    ];

    /* =====================================================================
     | 上限与镜像值
     ===================================================================== */

    /**
     * `msg_id` 长度上限。
     *
     * 与主项目 `notify` 动作的 `msg_id` 规则同值（`docs/GatewayPush 对外接口文档.md` §8.2
     * 的 `max_len=64`）—— 同一个 id 在两条通道下必须同口径，否则「HTTP 能发、WS 不能发」。
     */
    public const MSG_ID_MAX_LEN = 64;

    /** `msg_id` 自动生成的随机字节数（8 字节 = 64 bit，人工触发量级下碰撞概率可忽略） */
    public const MSG_ID_BYTES = 8;

    /** `msg_id` 自动生成后的字符长度 = `bin2hex(MSG_ID_BYTES)` = 16 位 hex（设计文档 §7 P3 任务 ③） */
    public const MSG_ID_LEN = 16;

    /**
     * payload 字节上限的**手写镜像**，真源是主项目 `config/app.php` 的
     * `push.payload_max`（Env `PUSH_PAYLOAD_MAX`，默认 4096）。
     *
     * 为什么只能镜像而不能读：后台与主项目**不同进程、不同 .env**，且本项目不允许后台解析
     * 主项目的 `.env`（`config/gateway_push.php` 已就此立规矩：「角色启用清单的唯一真源走
     * 主项目 CLI 的 JSON 契约，后台不得自行解析 .env」）。镜像漂移由
     * `tests/Manual/p3_acceptance.php` 的对照断言**检测**（它同机可读 `../.env`），
     * 与 `RedisKeyLiteralSniff::$prefixes` 配 `composer lint:self` 自检是同一手法。
     *
     * ⚠ **只允许调小**（见 {@see payloadMax()}）：调大只会让后台放过「服务端会静默丢弃」的载荷。
     */
    public const PAYLOAD_MAX_MIRROR = 4096;

    /** 幂等去重窗口（秒）的镜像，真源 `push.idempotent_ttl`（Env `PUSH_IDEMPOTENT_TTL`）。仅用于文案。 */
    public const IDEMPOTENT_TTL_MIRROR = 600;

    /** 单用户离线队列条数上限的镜像，真源 `push.offline_max`（Env `PUSH_OFFLINE_MAX`）。仅用于文案。 */
    public const OFFLINE_MAX_MIRROR = 100;

    /* =====================================================================
     | 固定说明文案（后端下发，前端不得自行编词）
     ===================================================================== */

    /** `/push` 成功响应的真实含义 */
    public const ACCEPTED_NOTE = '响应 code=0 只表示「已入队受理」，不代表已投递 —— '
        . '服务端不承诺逐条投递回执。要看真实效果请到监控页对比 push_out / push_fail 的增量。';

    /** 去重不可观测（本页最容易做出假功能的一处） */
    public const DEDUP_NOTE = '同 msg_id 在服务端幂等窗口（默认 ' . self::IDEMPOTENT_TTL_MIRROR
        . 's）内不会二次投递，但该判定不在响应里 —— '
        . '服务端去重是静默的（只累加 push_dedup 指标，响应与首次逐字段相同），'
        . '因此后台无法告诉你本次是否被去重。';

    /** 超限载荷会被静默丢弃 */
    public const PAYLOAD_DROP_NOTE = 'payload 超过上限时，/push 仍返回 accepted，随后在业务进程里被'
        . '静默丢弃（只记 push_fail 与 warn 日志，调用方拿不到任何反馈）—— '
        . '故本页在提交前用与服务端同一口径的字节预估先挡一次。';

    /** 本页不支持主题广播 */
    public const NO_TOPIC_NOTE = '本页只支持 uid / device / client 三种定向推送；'
        . '主题广播（Push::enqueueTopic()）在服务端没有 HTTP 入口，故不在此页提供。';

    /** 模板表的定位（P3 只做载荷复用，不做「发送计划」） */
    public const TEMPLATE_NOTE = '模板只保存「目标类型 + 载荷 + 离线策略」，不保存目标值 —— '
        . '目标是每次发送时填的；把 uid / 设备号存进模板极易误发到旧目标。';

    /* =====================================================================
     | 归一（未知值一律显式失败，不回落到看似合理的默认值）
     ===================================================================== */

    /**
     * `target_type` 归一：未知值（含非字符串）回落空串，由调用方据此报错。
     *
     * **刻意不学 `Push::normalizeTargetType()` 回落 `uid`** —— 那个回落发生在 business 进程的
     * `dispatch()` 里，是给「队列里的脏 job」兜底的最后一环；后台若也这么回落，
     * 一个拼错的 `target_type` 就会静默变成「按 uid 推送」，错得非常隐蔽。
     */
    public static function normalizeTargetType(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = strtolower(trim($value));

        return in_array($value, self::TARGET_TYPES, true) ? $value : '';
    }

    /**
     * `offline_mode` 归一。
     *
     * @return null|string 合法值原样返回（**含空串**，空串表示取服务端默认）；非法值返回 `null`
     */
    public static function normalizeOfflineMode(mixed $value): ?string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));

        return in_array($value, self::OFFLINE_MODES, true) ? $value : null;
    }

    /** `target` 去空格；非字符串回落空串。 */
    public static function normalizeTarget(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /** `msg_id` 去空格；非字符串回落空串（空 = 由调用方决定是否自动生成）。 */
    public static function normalizeMsgId(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * payload 字段归一：接受**数组**（前端已 `JSON.parse`）与**JSON 字符串**（textarea 原样提交）两种形态。
     *
     * 为什么必须在服务端容忍字符串形态：表单里的载荷是个 `textarea`，
     * 前端 `JSON.parse` 失败只能给出一个笼统的「格式不对」；而后端拿原文能精确报出
     * `Syntax error` 的位置。反之，若前端解析成功后**改发对象**，本方法也照样工作 ——
     * 两种形态都收，是为了让「前端怎么发」不成为一个隐性契约。
     *
     * `''` / `null` / 键缺失都归一为**空载荷** `[]`（与服务端 `$job['payload'] ?? []` 同义）。
     *
     * @return array{ok: bool, value: array<mixed>, msg: string}
     */
    public static function decodePayload(mixed $value): array
    {
        if ($value === null || $value === '') {
            return ['ok' => true, 'value' => [], 'msg' => ''];
        }

        if (is_array($value)) {
            return ['ok' => true, 'value' => $value, 'msg' => ''];
        }

        if (is_string($value)) {
            try {
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return ['ok' => false, 'value' => [], 'msg' => 'payload 不是合法 JSON：' . $e->getMessage()];
            }

            if (!is_array($decoded)) {
                // `"123"` / `"true"` / `"\"str\""` 都是合法 JSON 但不是对象/数组 ——
                // 服务端 `handlePush` 对非数组 payload 直接 4000，故这里也要拦。
                return [
                    'ok' => false,
                    'value' => [],
                    'msg' => 'payload 必须是 JSON 对象或数组（解析结果是 ' . get_debug_type($decoded) . '）',
                ];
            }

            return ['ok' => true, 'value' => $decoded, 'msg' => ''];
        }

        return [
            'ok' => false,
            'value' => [],
            'msg' => 'payload 必须是 JSON 对象、数组或其字符串形式（当前是 ' . get_debug_type($value) . '）',
        ];
    }

    /**
     * 生成 `msg_id`：16 位小写 hex。
     *
     * ⚠ 它是**弱随机**（`random_bytes` 的密码学强度足够，但长度只有 64 bit），
     * 用途仅是「人工触发推送时避免重发」，**不用于任何安全判定**。
     */
    public static function genMsgId(): string
    {
        return bin2hex(random_bytes(self::MSG_ID_BYTES));
    }

    /**
     * payload 的**序列化字节数** —— 与服务端 `Push::dispatch()` 完全同源。
     *
     * 绝不能改成 `count()` / 字符数 / 整个请求体长度：三者都与服务端判据不一致，
     * 会出现「后台说没超、服务端静默丢弃」或反之。
     *
     * @param array<mixed> $payload
     */
    public static function payloadBytes(array $payload): int
    {
        return strlen(Message::encode($payload));
    }

    /**
     * 生效的 payload 上限（**只允许比镜像值更严**）。
     *
     * 允许 `admin_settings.push.payload_max` 覆盖，但**只在调小方向生效** ——
     * 调大没有意义：服务端上限没变，后台放开只会产出「已受理但被静默丢弃」的载荷，
     * 正是本类要消灭的那类故障。故此处夹取而不是直接采信。
     *
     * @param int $configured 来自 `admin_settings`；`<= 0`（含未配置）表示走镜像值
     *
     * @return int 恒 `<= PAYLOAD_MAX_MIRROR`
     */
    public static function payloadMax(int $configured = 0): int
    {
        if ($configured <= 0 || $configured > self::PAYLOAD_MAX_MIRROR) {
            return self::PAYLOAD_MAX_MIRROR;
        }

        return $configured;
    }

    /** 模板名的长度上限（与 `push_template.name` 的 `VARCHAR(64)` 一致） */
    public const TEMPLATE_NAME_MAX_LEN = 64;

    /** 模板备注的长度上限（与 `push_template.remark` 的 `VARCHAR(255)` 一致） */
    public const TEMPLATE_REMARK_MAX_LEN = 255;

    /* =====================================================================
     | 校验主入口
     ===================================================================== */

    /**
     * 校验并归一一次推送表单。
     *
     * `payload` 的**类型判定放在服务端**（而不是只靠前端 `JSON.parse`）：前端解析失败会被
     * `try/catch` 吃掉，若同时还不发请求就变成「点了没反应」；服务端判定无论如何都有一个
     * 明确的错误码与文案回去。
     *
     * **`msg_id` 留空时自动补一个**：服务端只在提供了 `msg_id` 时才去重
     * （`Push::enqueue()` 的 `$msgId !== ''` 判定），留空 = 无幂等保护 ——
     * 与「不填就等于不保护」的运维直觉相反，故由后台兜住。
     *
     * @param array<string, mixed> $input      请求体原样
     * @param int                  $payloadMax 生效上限，见 {@see payloadMax()}
     *
     * @return array{
     *     ok: bool,
     *     errors: list<string>,
     *     job: array{target_type: string, target: string, payload: array<mixed>, msg_id: string, offline_mode: string},
     *     bytes: int,
     *     max: int,
     *     msg_id_generated: bool
     * }
     */
    public static function validatePush(array $input, int $payloadMax): array
    {
        $core = self::core($input, $payloadMax);
        $errors = $core['errors'];

        $target = self::normalizeTarget($input['target'] ?? null);
        if ($target === '') {
            $errors[] = 'target 不能为空（首尾空白已去除）';
        } elseif (!SessionInspector::validId($target)) {
            // 与「会话查询」共用同一个 id 校验口径（`SessionInspector::validId`）：
            // 非空、<= 128 字节、无控制字符。两处若各写一套，就会出现
            // 「列表页查得到、推送页发不出去」这类难以解释的不一致。
            $errors[] = 'target 非法：长度超过 ' . SessionInspector::ID_MAX_LEN
                . ' 字节，或含控制字符（uid / device_id / clientId 都会被直接拼进 Redis 键）';
        }

        $msgId = self::normalizeMsgId($input['msg_id'] ?? null);
        $generated = false;
        if ($msgId === '') {
            $msgId = self::genMsgId();
            $generated = true;
        } elseif (strlen($msgId) > self::MSG_ID_MAX_LEN) {
            $errors[] = 'msg_id 长度超过 ' . self::MSG_ID_MAX_LEN . ' 字节';
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'job' => [
                'target_type' => $core['target_type'],
                'target' => $target,
                'payload' => $core['payload'],
                'msg_id' => $msgId,
                'offline_mode' => $core['offline_mode'],
            ],
            'bytes' => $core['bytes'],
            'max' => $payloadMax,
            'msg_id_generated' => $generated,
        ];
    }

    /**
     * 推送与模板**共用**的校验内核：`target_type` / `offline_mode` / `payload` 三件。
     *
     * 刻意不含 `target` 与 `msg_id`：模板不存这两个字段（见 {@see validateTemplate()}）。
     * 抽成内核而不是给 `validatePush()` 加开关参数 —— 开关参数会让「模板路径」与
     * 「推送路径」的差异变成运行期分支，而这里两者的差异是**静态字段集不同**，
     * 用组合表达比用布尔量表达更难写错。
     *
     * @param array<string, mixed> $input
     *
     * @return array{
     *     errors: list<string>,
     *     target_type: string,
     *     offline_mode: string,
     *     payload: array<mixed>,
     *     bytes: int
     * }
     */
    private static function core(array $input, int $payloadMax): array
    {
        $errors = [];

        $targetType = self::normalizeTargetType($input['target_type'] ?? null);
        if ($targetType === '') {
            $errors[] = 'target_type 必须是 ' . implode(' / ', self::TARGET_TYPES) . ' 之一';
        }

        // 未知 offline_mode 显式报错而不是回落：见 OFFLINE_MODES 的注释
        // （服务端对未知值是静默回落默认的，后台若也回落就会掩盖一个手搓请求）。
        $mode = self::normalizeOfflineMode($input['offline_mode'] ?? null);
        if ($mode === null) {
            $errors[] = 'offline_mode 只能是空串（取服务端默认）/ '
                . implode(' / ', [Push::MODE_DROP, Push::MODE_QUEUE]);
            $mode = '';
        }

        // `?? []` 已经把「键缺失」与「显式 null」都归成了 `[]`，故此处只需再兜住空串
        $decoded = self::decodePayload($input['payload'] ?? []);
        if (!$decoded['ok']) {
            $errors[] = $decoded['msg'];
        }
        $payload = $decoded['value'];
        $bytes = self::payloadBytes($payload);

        if ($bytes > $payloadMax) {
            $errors[] = sprintf(
                'payload 序列化后 %d 字节，超过上限 %d 字节'
                . '（服务端超限时会在业务进程里静默丢弃，而 /push 仍返回 accepted）',
                $bytes,
                $payloadMax
            );
        }

        return [
            'errors' => $errors,
            'target_type' => $targetType,
            'offline_mode' => $mode,
            'payload' => $payload,
            'bytes' => $bytes,
        ];
    }

    /**
     * 校验并归一一条**推送模板**。
     *
     * 复用 {@see core()} 的三件校验（目标类型 / 离线策略 / 载荷字节），另加 `name` / `remark`。
     *
     * ⚠ **模板刻意不含 `target`（目标值）**：把 uid / 设备号存进模板，最容易出现的故障是
     * 「上周的模板今天一键发出去，发到了早就换掉的旧设备」。目标是每次发送时填的，
     * 模板只复用它不会过期的部分 —— 载荷、目标**类型**、离线策略。
     *
     * `msg_id` 同样不落模板：它必须每次不同，否则第二次发送会被服务端静默去重
     * （幂等窗口内同 msg_id 不二次投递），表现为「点了发送但没人收到」。
     *
     * @param array<string, mixed> $input
     * @param int                  $payloadMax 见 {@see payloadMax()}
     *
     * @return array{
     *     ok: bool,
     *     errors: list<string>,
     *     row: array{name: string, target_type: string, payload: array<mixed>, offline_mode: string, remark: string},
     *     bytes: int,
     *     max: int
     * }
     */
    public static function validateTemplate(array $input, int $payloadMax): array
    {
        $core = self::core($input, $payloadMax);
        $errors = $core['errors'];

        $name = isset($input['name']) && is_string($input['name']) ? trim($input['name']) : '';
        if ($name === '') {
            $errors[] = 'name 不能为空';
        } elseif (strlen($name) > self::TEMPLATE_NAME_MAX_LEN) {
            $errors[] = 'name 长度超过 ' . self::TEMPLATE_NAME_MAX_LEN . ' 字节';
        }

        $remark = isset($input['remark']) && is_string($input['remark']) ? trim($input['remark']) : '';
        if (strlen($remark) > self::TEMPLATE_REMARK_MAX_LEN) {
            $errors[] = 'remark 长度超过 ' . self::TEMPLATE_REMARK_MAX_LEN . ' 字节';
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'row' => [
                'name' => $name,
                'target_type' => $core['target_type'],
                'payload' => $core['payload'],
                'offline_mode' => $core['offline_mode'],
                'remark' => $remark,
            ],
            'bytes' => $core['bytes'],
            'max' => $payloadMax,
        ];
    }

    /* =====================================================================
     | 展示辅助
     ===================================================================== */

    /** 目标类型的中文说明（未知值回落原样，便于排查）。 */
    public static function labelOfTargetType(string $type): string
    {
        return self::TARGET_LABELS[$type] ?? $type;
    }

    /** 离线策略的中文说明（未知值回落原样）。 */
    public static function labelOfOfflineMode(string $mode): string
    {
        return self::OFFLINE_LABELS[$mode] ?? $mode;
    }
}
