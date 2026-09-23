<?php

declare(strict_types=1);

namespace app\service;

/**
 * 业务动作的**清单镜像层**：从主项目 `config/actions.php` 抄出「HTTP 通道可用」的那一批。
 *
 * ## 为什么是手写镜像，而不是运行时读取
 *
 * 运行时读取在技术上不可行（也不该做）：
 * - `config/actions.php` 里的 handler 项是 `GatewayPush\Business\Action\*::class`，取值本身安全，
 *   但该文件顶层 `use` 了主项目的 `Env` —— 引导它会去读**主项目的 .env**，
 *   在后台进程里混入另一套环境变量（`config/gateway_push.php` 已就此立规矩：
 *   「后台不得自行解析主项目 .env」）。
 * - 主项目已提供 `ActionRunner::httpActions()`，但那是**运行期**接口，需要主项目自身完成
 *   `Bootstrap::init()`；后台拿不到那个上下文。
 *
 * 所以采用与 `RedisKeyLiteralSniff::$prefixes` 相同的手法：**手写副本 + 自动化漂移检测**。
 * 检测在 `tests/Unit/ActionCatalogTest.php`（静态解析 `../config/actions.php`，双向比对
 * 名称集合、描述、以及 `auth` 声明），并在 `tests/Manual/p3_acceptance.php` 里再做一次线上口径核对。
 * 漂移会**变红**，不会静默。
 *
 * ## ⚠ 最危险的一处漂移：`session`
 *
 * `config/actions.php` 的 `session` 动作**刻意没有** `'http' => true` —— 它的语义锚点是
 * 「当前连接」，而 HTTP 通道下无连接实体（`clientId = http:{request_id}`），调用无意义
 * （`docs/GatewayPush 对外接口文档.md` §8.1 已写明）。若把它列进下拉框，用户每次点都会拿到
 * `400` + `4006`（动作未开放 HTTP 通道）—— **一个必然失败的选项就是虚假功能**。
 * `ActionCatalogTest::testSessionActionIsDeliberatelyAbsent()` 用具名锚点钉住这一点。
 */
final class ActionCatalog
{
    /**
     * HTTP 通道可用的动作清单（真源：主项目 `config/actions.php` 中 `'http' => true` 的项）。
     *
     * 逐项字段：
     * - `description`  —— **原文照抄**主项目声明，便于人工与漂移测试逐字比对；
     * - `requires_uid` —— 该动作是否要求 `uid`。等价于主项目声明里 `empty($decl['auth']) === false`
     *                     （`auth` 默认 `true`，见 `config/actions.php` 的 `defaults`）。
     *                     ⚠ 这不是「UI 想不想填」的问题：`src/Api/Bootstrap.php:546` 对
     *                     `auth` 为真且 `uid` 为空的请求**直接回 401 + 4003**，不填必然失败。
     * - `params_hint`  —— 参数提示（面向人，逐字对照 `对外接口文档.md` §8.2 的 schema）。
     *
     * @var array<string, array{description: string, requires_uid: bool, params_hint: string}>
     */
    public const ACTIONS = [
        'echo' => [
            'description' => '原样回显，用于联通性验证与压测',
            'requires_uid' => true,
            'params_hint' => '无 schema —— 任意键值原样透传（传什么回什么）',
        ],
        'report' => [
            'description' => '数据上报：按主题累加计数（UDP 下静默不回执）',
            'requires_uid' => true,
            'params_hint' => 'topic（必填）、count（1~10000，默认 1）、value（任意 JSON）',
        ],
        'subscribe' => [
            'description' => '订阅主题',
            'requires_uid' => true,
            'params_hint' => 'topic（必填）',
        ],
        'unsubscribe' => [
            'description' => '取消订阅主题',
            'requires_uid' => true,
            'params_hint' => 'topic（必填）',
        ],
        'topics' => [
            'description' => '查询本人已订阅的主题列表',
            'requires_uid' => true,
            'params_hint' => '无参数',
        ],
        'notify' => [
            'description' => '请求服务端向本人推送一条消息（验证推送闭环）',
            'requires_uid' => true,
            'params_hint' => 'value（任意 JSON）、msg_id（<= 64 字节）、offline_mode（"" / drop / queue）',
        ],
    ];

    /**
     * **刻意不列入**的动作（存在但 HTTP 未开放）。
     *
     * 这份清单的存在意义是让「不列」成为一个**被测试钉住的显式决定**，而不是遗忘。
     * 由 `ActionCatalogTest` 双向断言：这些名字必须**不在** {@see ACTIONS} 里，
     * 且必须**在**主项目声明里。
     *
     * ⚠ 本常量**只**表示「HTTP 未开放」。P4 起另有一类「HTTP 开放但调试器不列」——
     *   见 {@see NOT_IN_DEBUGGER}，两者语义不同，不要混用。
     *
     * @var list<string>
     */
    public const NOT_HTTP_EXPOSED = ['session'];

    /**
     * **HTTP 已开放、但调试器刻意不列**的动作（P4 的运维动作）。
     *
     * 与 {@see NOT_HTTP_EXPOSED} 的区别必须分清：
     *
     * | 常量 | 主项目声明 | 能不能经 HTTP 调 | 调试器列不列 | 理由 |
     * |---|---|---|---|---|
     * | `NOT_HTTP_EXPOSED` | 无 `http` | **不能**（4006） | 不列 | 列了就是必然失败的虚假选项 |
     * | `NOT_IN_DEBUGGER` | `http=true` + `channels=[http]` | **能** | **不列** | 能调，但调试器不该提供它 |
     *
     * 为什么运维动作不能进调试器下拉：
     * `/actions` 是**排障工具**，形态是「选个动作 → 填参数 → 执行」。
     * 一旦把 `kick` / `revoke` / `unbind` 放进去，它就变成了一个
     * **一键踢任意人 / 撤销任意 Token / 解绑任意 uid** 的按钮 ——
     * 而这些动作**不可撤销**，且调试页没有运维动作需要的 `caveats`（「不做什么」）展示区。
     * 运维动作走专用入口 `OpsActionController`（有审计、有语义说明、有独立权限节点）。
     *
     * ⚠ 调试器与运维入口共用同一个 `POST /action` 通道，故「不列」纯粹是**后台 UI 层的决定**，
     *   不是安全边界 —— 边界在主项目的 `channels` 声明（WS/UDP 侧）与后台的权限节点（HTTP 侧）。
     *
     * @var list<string>
     */
    public const NOT_IN_DEBUGGER = ['kick', 'revoke', 'unbind', 'purge_offline'];

    /* =====================================================================
     | topic 规则的镜像（`report` / `subscribe` / `unsubscribe` 三处共用同一条 $topicRule）
     ===================================================================== */

    /**
     * 主题名字符集与长度（正则字面量，**必须与主项目逐字符相同**）。
     *
     * 主题名会直接参与 Redis 键拼接，故服务端限制字符集以防键空间被污染。
     * 前端预校验用同一串，避免「本地放过 → 服务端 4007」的往返。
     * `ActionCatalogTest` 用**子串存在性**断言这串必须在 `../config/actions.php` 里原样出现 ——
     * 比解析 PHP 结构更抗排版变化。
     */
    public const TOPIC_PATTERN = '/^[A-Za-z0-9_:.\-]{1,64}$/';

    /**
     * `request_id` 的合法形态（正则字面量，**必须与主项目逐字符相同**）。
     *
     * 真源：`src/Business/ActionReply::REQUEST_ID_PATTERN`。⚠ 它比「16 位 hex」**宽**得多
     * （服务端自己生成的是 `bin2hex(random_bytes(8))`，但校验正则允许 `[A-Za-z0-9_-]{1,64}`）——
     * 后台若按「16 位 hex」收紧，会把**别的调用方**产生的合法 request_id 判成非法，
     * 从而让「补查」这个纯读操作无谓失败。故一律按服务端的口径来。
     */
    public const REQUEST_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /* =====================================================================
     | 时限镜像（真源在主项目 .env，同 Pusher 的镜像纪律）
     ===================================================================== */

    /**
     * `/action` 服务端同步等待窗（毫秒）的镜像，真源 `API_ACTION_WAIT_MS`（本机 6000）。
     *
     * ⚠ 这是**服务端会阻塞这么久**的值，不是后台的超时值 —— 后台 `GatewayPushClient`
     * 的默认超时 8s 必须大于它，否则会先于服务端放弃，把「超窗转 202」误报成连接失败。
     */
    public const WAIT_MS_MIRROR = 6000;

    /**
     * 动作回执保留时长（秒）的镜像，真源 `ACTION_RESULT_TTL`（本机 60）。
     *
     * 这是**补查窗口的硬上限**：`GET /action/{id}` 在超时后返回 `404` + `4004`，
     * 而该响应**有两义**（任务仍在执行 / 结果已被回收），后台无法区分 ——
     * 故前端的退避轮询必须在这个窗口内收敛，超时后如实报「已超出补查窗口」而非继续重试。
     */
    public const RESULT_TTL_MIRROR = 60;

    /* =====================================================================
     | 说明文案（后端下发，前端不得自行编词）
     ===================================================================== */

    /**
     * `uid` 在 HTTP 通道下的真实语义。
     *
     * 这条必须显式告知使用者：HTTP 通道**不校验 uid 归属** ——
     * `src/Api/Bootstrap.php` 的注释已写明「uid 由请求方在 body 中给出，但它参与 HMAC 签名覆盖，
     * 即『密钥持有者可代表任意 uid 发起动作』，与 `/push` 同权，不构成提权」。
     * 在页面上不写清，读者会误以为「填了自己的 uid 就只能操作自己」。
     */
    public const UID_SEMANTICS_NOTE = 'HTTP 通道下 uid 是**由调用方填写**的，服务端不校验归属 —— '
        . '持有 API_SECRET 者可代表任意 uid 发起动作，与 /push 同权。'
        . '这在本项目是既定的信任模型（管理面凭证），不是缺陷。';

    /** 为何每个动作都要 uid */
    public const UID_REQUIRED_NOTE = '当前 HTTP 开放的 6 个动作的声明里 `auth` 均为默认的 true，'
        . '故 uid 必填 —— 缺 uid 会被服务端直接回 401 + 4003（CODE_UNAUTHORIZED），不会进入队列。';

    /** `202 pending` 不是失败 */
    public const PENDING_NOTE = '服务端最多同步等待 ' . self::WAIT_MS_MIRROR
        . 'ms；超窗会返回 202 + status=pending —— **超窗不是失败**，'
        . '只是结果还没算完，需凭 request_id 补查。';

    /** 补查窗口与其两义性 */
    public const RESULT_TTL_NOTE = '动作回执只保留 ' . self::RESULT_TTL_MIRROR . 's。'
        . '窗口外的 404 + 4004 **有两种含义**（仍在执行 / 已被回收），服务端不作区分 —— '
        . '故补查超时后本页如实报告「超出补查窗口」，不会替你猜。';

    /** 本页是调试工具，不是运维动作入口 */
    public const SCOPE_NOTE = '本页只覆盖 HTTP 已开放的 6 个调试/业务动作；'
        . 'kick / revoke / unbind 三个运维动作属 P4（需先落主项目 C1~C5 的通道白名单），'
        . '在此之前它们不在此列表，也不在服务端的 httpActions() 里。';

    /* =====================================================================
     | 查询
     ===================================================================== */

    /**
     * 动作名归一：**不在清单内一律回落空串**。
     *
     * 与 `Pusher::normalizeTargetType()` 同一纪律：这里的清单本身已经是「HTTP 可用」的
     * 白名单，故归一 + 白名单在这一层一次完成，控制器只需判空。
     */
    public static function normalize(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = strtolower(trim($value));

        return array_key_exists($value, self::ACTIONS) ? $value : '';
    }

    /** @return list<string> 供前端下拉的取值顺序（保持声明顺序，稳定可断言） */
    public static function names(): array
    {
        return array_keys(self::ACTIONS);
    }

    /**
     * 该动作是否需要 `uid`。
     *
     * 返回 `false` 有两种含义，调用方**不需要**区分：
     *   ① 动作声明 `auth=false`（P4 的 kick / revoke / unbind）；
     *   ② 动作名不在白名单（此时 {@see normalize()} 已经回落空串，控制器先于本方法拦住）。
     */
    public static function requiresUid(string $action): bool
    {
        return self::ACTIONS[$action]['requires_uid'] ?? false;
    }

    /** `request_id` 形态校验（用服务端口径的正则，见 {@see REQUEST_ID_PATTERN}）。 */
    public static function validRequestId(string $requestId): bool
    {
        return preg_match(self::REQUEST_ID_PATTERN, $requestId) === 1;
    }

    /**
     * 供前端渲染的完整清单（含说明与是否需要 uid）。
     *
     * @return list<array{name: string, description: string, requires_uid: bool, params_hint: string}>
     */
    public static function forUi(): array
    {
        $out = [];
        foreach (self::ACTIONS as $name => $meta) {
            $out[] = [
                'name' => $name,
                'description' => $meta['description'],
                'requires_uid' => $meta['requires_uid'],
                'params_hint' => $meta['params_hint'],
            ];
        }

        return $out;
    }
}
