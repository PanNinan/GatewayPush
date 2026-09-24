<?php

/**
 * P3 验收脚本（手工执行，**不在** PHPUnit 套件内：它依赖后台服务、MySQL 与主项目在线）。
 *
 * 用法：
 *     cd admin && php tests/Manual/p3_acceptance.php            # 只读模式：只发「必然被拒」的请求
 *     cd admin && php tests/Manual/p3_acceptance.php --seed     # 建立夹具 + 允许**真实推送**，跑完清理
 *
 * ## 为什么必须分两种模式
 *
 * P3 的交付面是「**写**」：发起推送 / 调用动作 / 模板增删改 —— 与 P2 的只读面板有本质区别。
 * 一次成功的 `/api/push` 会在主项目的真实连接上产生投递，一次 `/api/action` 会真的执行动作
 * （`report` 累加计数、`notify` 推消息）。故默认模式**只验证拒绝路径**；
 * 所有会产生副作用的请求都必须带 `--seed`，且目标一律是 `p3test-*` 这种**不存在**的 id
 * （运行期会再核一次：若它真的在线，直接 ABORT）。
 *
 * ## 四层
 *
 *   1. **镜像常量 ↔ 主项目 `.env` 的在线对照**：五个数值镜像由静态测试对着
 *      `../config/*.php` 的默认值守；但运维若在主项目 `.env` 里**显式**改了它们，
 *      静态测试仍全绿，而后台的预校验会**用错上限**（拦得太松 = 静默丢弃）。本层补这一段。
 *   2. **服务层**：`validatePush` 关键分支、`ActionCatalog` 白名单、审计脱敏、载荷字节口径。
 *   3. **静态一致性**：`install.php` 的 9 个 P3 节点 ↔ 控制器真实动作 ↔ 路由动词 ↔ 角色规则。
 *      与 P2 同口径 —— 验收的是**契约**，不是「运维有没有跑过 install.php」。
 *   4. **HTTP 层**（依赖后台 8292 在线）：默认路由回归、未登录、页面骨架、校验分支、
 *      RBAC 动态矩阵，以及三处**语义反证**。
 *
 * ## 三处「反证」—— 本脚本最有价值的部分
 *
 * 前两处要证明的是**「不拦就一定没有反馈」**，而不是「我们的代码写对了」：
 *
 *   A. **payload 超限的静默丢弃**：用后台自己的签名客户端直连主项目，发一个**超限**载荷 ——
 *      主项目照常返回 `code=0`（受理），随后业务进程在 `Push::dispatch()` 里
 *      `Monitor::incr('push_fail')` 并丢弃该消息，**调用方永远拿不到失败反馈**。
 *      本项同时读主项目 `metrics:counter:*` 的 `push_fail` 增量，用计数变化**证明丢弃真的发生**。
 *      这就是后台必须做逐字节同源预校验（`Pusher::payloadBytes()`）的全部理由。
 *
 *   B. **幂等的不可观测性**：同一个 `msg_id` 连发两次，两次都回 `code=0`，
 *      第二次被 `setNxEx` 去重（`Monitor::incr('push_dedup')`）——
 *      而**两次响应的键集合完全一致**，没有任何字段能区分。故推送历史只能如实写「受理记录」，
 *      不得宣称「已投递」或「已去重」（`PushRepository::RECORD_NOTE`）。
 *
 *   C. **404 的语义分流**：`GET /api/action/{id}` 未命中必须是 `HTTP 200 + state=expired`，
 *      而不是 404 —— 该状态**两义**（仍在执行 / 已过 `ACTION_RESULT_TTL` 被回收），
 *      用 404 会让前端的统一错误处理把它当接口故障并**打断补查**，而那正是最需要它继续跑的场合。
 *
 * 后台未在线 → 第 4 层整体 SKIP；主项目 API 不可达 → 第 6 节的三处反证 SKIP；
 * `.env` 缺 `ADMIN_VIEWER_USER/PASS` → viewer 矩阵 SKIP。SKIP 不影响退出码。
 *
 * ⚠ 本脚本**只**在 `--seed` 下写数据，且只写：MySQL 的 `p3test-*` 夹具行、
 *   主项目队列里发往 `p3test-*` 目标的消息。清理在 `finally` 里无条件执行。
 *   生产代码侧的只读边界由 `tests/Unit/PushContractTest` / `ActionContractTest` 守。
 *
 * 退出码：0 = 全绿（SKIP 不算失败）；1 = 有 FAIL；2 = 安全闸拒绝执行。
 */

use app\service\ActionCatalog;
use app\service\Auditor;
use app\service\GatewayPushClient;
use app\service\Pusher;
use app\service\PushRepository;
use app\service\RedisReader;
use app\service\TemplateRepository;
use support\Db;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../support/bootstrap.php';

const ADMIN_HOST = '127.0.0.1';
const ADMIN_PORT = 8292;
const SESSION_DIR = __DIR__ . '/../../runtime/sessions';

/**
 * 夹具命名空间。
 *
 * ⚠ 只允许改前缀，**不要**把它换成任何看起来像真实业务 id 的值 ——
 *   `FIX_TARGET_UID` / `FIX_TARGET_DEV` 会被真的推一次消息。
 */
const FIX = 'p3test';
const FIX_TARGET_UID = FIX . '-uid';
const FIX_TARGET_DEV = FIX . '-dev';
const FIX_MSG_ID = FIX . 'msgid000000001';
const FIX_TPL_NAME = FIX . '-tpl';

/**
 * 夹具 `request_id`（`push_task.request_id` 是 **CHAR(16)**，超长直接 1406 Data too long —— 实测踩过）。
 *
 * 真实行的值来自 `bin2hex(random_bytes(8))`，恒为 16 位小写 hex；夹具用两个必然不同的 16 字符值，
 * 既避开唯一键 `uk_request_id`，又与真实行同宽。
 */
const FIX_REQ_ACC = 'p3acc00000000001';
const FIX_REQ_REJ = 'p3rej00000000001';
/**
 * 夹具操作者 id。
 *
 * 取 `0`：`push_task.operator_id` 是**无符号**列（负数直接 1264 out of range —— 实测踩过），
 * 而真实行的值来自 `admin_id()`，恒 `> 0`，故 0 在本表里是空闲的。
 * ⚠ 但**不**用 `operator_id` 作清理判据：那会连带删掉任何 `operator_id = 0` 的行。
 *   清理只按 `target` / `name` 的 `p3test%` 前缀。
 */
const FIX_OPERATOR = 0;

$pass = 0;
$fail = 0;
$skip = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  [PASS] {$label}" . ($detail !== '' ? "  {$detail}" : '') . PHP_EOL;
    } else {
        $fail++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? "  {$detail}" : '') . PHP_EOL;
    }
}

function note(string $label, string $detail = ''): void
{
    global $skip;
    $skip++;
    echo "  [SKIP] {$label}" . ($detail !== '' ? "  {$detail}" : '') . PHP_EOL;
}

function section(string $title): void
{
    echo PHP_EOL . $title . PHP_EOL;
}

/**
 * 发起一次 HTTP 请求。
 *
 * ⚠ `CURLOPT_PROXY => ''` 是必须的：本机环境存在 `http_proxy`，libcurl 会遵循它，
 *   而该代理在复用连接的第 2 个请求上返回 400 —— 现象酷似「后台 API 有长连接 bug」。
 *
 * @param array<string, mixed> $post
 * @param list<string>         $headers
 *
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function http(string $method, string $path, array $post = [], array $headers = [], ?string $jar = null): array
{
    $ch = curl_init('http://' . ADMIN_HOST . ':' . ADMIN_PORT . $path);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROXY => '',
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 3,
    ];

    if ($post !== []) {
        // JSON 体：webman 对 `Content-Type: application/json` 会解析成 `$request->post()`，
        // 与前端 `push.js` 的发送方式一致 —— 用表单编码测不出这条真正的链路。
        $options[CURLOPT_POSTFIELDS] = json_encode($post, JSON_UNESCAPED_UNICODE);
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }
    if ($jar !== null) {
        $options[CURLOPT_COOKIEJAR] = $jar;
        $options[CURLOPT_COOKIEFILE] = $jar;
    }

    curl_setopt_array($ch, $options);
    $raw = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeaders = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    $parsed = [];
    foreach (explode("\r\n", $rawHeaders) as $line) {
        $pos = strpos($line, ':');
        if ($pos !== false) {
            $parsed[strtolower(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
        }
    }

    return ['status' => $status, 'headers' => $parsed, 'body' => $body];
}

/** @return array<string, mixed> */
function jsonBody(string $body): array
{
    $decoded = json_decode($body, true);

    return is_array($decoded) ? $decoded : [];
}

/** 从统一信封里取 `data`（非数组时回落空数组） */
function dataOf(array $body): array
{
    return is_array($body['data'] ?? null) ? $body['data'] : [];
}

/**
 * 从 session 文件里读出验证码（webman session 为 file 驱动 + PHP serialize 格式）。
 *
 * 登录**强制校验验证码**，故自动化验收必须走「先 GET captcha 建立 session → 读明文 → 再 POST 登录」。
 */
function readCaptcha(string $jar): string
{
    $cookie = file_get_contents($jar);
    if ($cookie === false || !preg_match('/PHPSID\s+(\S+)/', $cookie, $m)) {
        return '';
    }

    $file = SESSION_DIR . '/session_' . $m[1];
    if (!is_file($file)) {
        return '';
    }

    $raw = file_get_contents($file);
    if ($raw === false) {
        return '';
    }

    /** @var mixed $data */
    $data = @unserialize($raw, ['allowed_classes' => false]);
    if (!is_array($data)) {
        return '';
    }

    foreach ($data as $key => $value) {
        if (is_string($key) && strtolower($key) === 'captcha-login' && is_string($value)) {
            return $value;
        }
    }

    return '';
}

function login(string $jar, string $user, string $pass): bool
{
    http('GET', '/app/admin/account/captcha/login', [], ['Accept: image/*'], $jar);
    $captcha = readCaptcha($jar);
    if ($captcha === '') {
        return false;
    }

    $res = http('POST', '/app/admin/account/login', [
        'username' => $user,
        'password' => $pass,
        'captcha' => $captcha,
    ], ['Accept: application/json'], $jar);

    return (int)(jsonBody($res['body'])['code'] ?? -1) === 0;
}

/* ===========================================================================
 * 夹具：建立与清理
 * =========================================================================== */

/**
 * 建立 MySQL 夹具（**只在 `--seed` 时调用**）。
 *
 * 两行 `push_task` 刻意覆盖两种状态：
 *   - `p3test-uid`  accepted  —— 供分页 / 筛选 / summary 断言
 *   - `p3test-dev`  rejected  —— 供「rejected = total - accepted」断言
 *
 * ⚠ 不写 Redis：本脚本对主项目的写只经 `GatewayPushClient`（已签名的 HTTP API）。
 *   直连 Redis 在集群下会写错节点，也无法复现真实的受理语义。
 */
function seedFixture(): void
{
    $now = date('Y-m-d H:i:s');

    Db::table('push_task')->insert([
        [
            'request_id' => FIX_REQ_ACC,
            'target_type' => 'uid',
            'target' => FIX_TARGET_UID,
            'payload' => (string)json_encode(['fixture' => 'accepted'], JSON_UNESCAPED_UNICODE),
            'payload_bytes' => 24,
            'msg_id' => FIX_MSG_ID,
            'offline_mode' => 'drop',
            'http_status' => 200,
            'code' => 0,
            'msg' => 'accepted',
            'status' => PushRepository::STATUS_ACCEPTED,
            'operator_id' => FIX_OPERATOR,
            'created_at' => $now,
        ],
        [
            'request_id' => FIX_REQ_REJ,
            'target_type' => 'device',
            'target' => FIX_TARGET_DEV,
            'payload' => (string)json_encode(['fixture' => 'rejected'], JSON_UNESCAPED_UNICODE),
            'payload_bytes' => 24,
            'msg_id' => '',
            'offline_mode' => '',
            'http_status' => 502,
            'code' => 5020,
            'msg' => 'fixture rejected',
            'status' => PushRepository::STATUS_REJECTED,
            'operator_id' => FIX_OPERATOR,
            'created_at' => $now,
        ],
    ]);
}

/**
 * 清理夹具 —— 只按 `p3test%` 前缀删除，绝不 `truncate`，也不按 `operator_id` 兜底。
 *
 * ⚠ 真实推送产生的 `push_task` 行的 `request_id` 由服务端生成（不可预测），
 *   但 `target` 一定是 `p3test-*`，故按 `target` 前缀即可兜住本次运行的全部行。
 */
function unseedFixture(): void
{
    $removed = 0;
    try {
        $removed = (int)Db::table('push_task')->where('target', 'like', FIX . '%')->delete();
    } catch (Throwable $e) {
        echo '  [WARN] push_task 夹具清理失败：' . $e->getMessage() . PHP_EOL;
    }

    try {
        Db::table('push_template')->where('name', 'like', FIX . '%')->delete();
        Db::table('admin_audit_log')->where('target', 'like', FIX . '%')->delete();
    } catch (Throwable $e) {
        echo '  [WARN] 模板 / 审计夹具清理失败：' . $e->getMessage() . PHP_EOL;
        return;
    }

    echo '  夹具已清理（push_task 删除 ' . $removed . ' 行；模板与审计按 ' . FIX . '-* 删除）' . PHP_EOL;
}

/** Redis 指标计数快照（读主项目的 `metrics:counter:{Ymd}`） */
function counters(RedisReader $reader, bool $redisOk): array
{
    return $redisOk ? $reader->counter() : [];
}

/* ===========================================================================
 * 主流程
 * =========================================================================== */

$seed = in_array('--seed', array_slice($argv, 1), true);
$env = $_ENV + $_SERVER;
$reader = new RedisReader();
$ping = $reader->ping();
$redisOk = $ping['ok'];

echo PHP_EOL . '=== P3 验收（GatewayPush 管理后台 · 推送管理与动作调试）===' . PHP_EOL;
echo 'PHP ' . PHP_VERSION . ' / ' . date('Y-m-d H:i:s')
    . ' / Redis ' . ($redisOk ? $ping['latency_ms'] . 'ms' : '不可达')
    . ' / 夹具：' . ($seed ? '启用（p3test-*，允许真实推送，跑完清理）' : '关闭（--seed 可启用真实推送）') . PHP_EOL;

// ★ 安全闸：真实推送只允许打到**不存在**的目标上。
//   如果这个 id 真的在线，说明有人把夹具命名空间改成了真实业务 id —— 立即拒绝执行，
//   而不是「小心一点」地继续（那会往生产连接投递真实消息）。
if ($seed && $redisOk) {
    foreach ([FIX_TARGET_UID, FIX_TARGET_DEV] as $probeTarget) {
        if ($reader->sessionExists($probeTarget)) {
            echo PHP_EOL . '[ABORT] 夹具目标 ' . $probeTarget . ' 是**真实存在**的会话；'
                . '本脚本会向它投递消息，拒绝执行。' . PHP_EOL;
            exit(2);
        }
    }
}

// ===========================================================================
// 1. 镜像常量 ↔ 主项目 .env
// ===========================================================================
section('[1] 镜像常量 ↔ 主项目 .env（在线对照）');

$mirrors = [
    'PUSH_PAYLOAD_MAX' => ['Pusher::PAYLOAD_MAX_MIRROR', Pusher::PAYLOAD_MAX_MIRROR],
    'PUSH_IDEMPOTENT_TTL' => ['Pusher::IDEMPOTENT_TTL_MIRROR', Pusher::IDEMPOTENT_TTL_MIRROR],
    'PUSH_OFFLINE_MAX' => ['Pusher::OFFLINE_MAX_MIRROR', Pusher::OFFLINE_MAX_MIRROR],
    'API_ACTION_WAIT_MS' => ['ActionCatalog::WAIT_MS_MIRROR', ActionCatalog::WAIT_MS_MIRROR],
    'ACTION_RESULT_TTL' => ['ActionCatalog::RESULT_TTL_MIRROR', ActionCatalog::RESULT_TTL_MIRROR],
];

$envPath = dirname(__DIR__, 3) . '/.env';
$envValues = [];
if (is_file($envPath)) {
    foreach ((array)file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        $parts = explode('=', $line, 2);
        $envValues[trim($parts[0])] = trim(trim($parts[1]), "\"'");
    }
}

if ($envValues === []) {
    note('主项目 .env 在线对照', '未找到 ' . $envPath . '（或为空）—— 默认值由 MainProjectMirrorsTest 静态守');
} else {
    foreach ($mirrors as $key => [$label, $mirror]) {
        if (!array_key_exists($key, $envValues)) {
            note('主项目 .env 未显式设置 ' . $key, $label . '=' . $mirror . '（取默认值，由静态测试守）');
            continue;
        }
        $actual = (int)$envValues[$key];
        check('★ ' . $key . ' 的镜像与主项目 .env 一致', $actual === $mirror,
            'env=' . $actual . ' ' . $label . '=' . $mirror);
    }
}

// ===========================================================================
// 2. 服务层：纯函数
// ===========================================================================
section('[2] 服务层：纯函数（无 IO）');

$payload = ['a' => '中文'];
check('payloadBytes 与 Message::encode 同口径（中文 3 字节）',
    Pusher::payloadBytes($payload)
    === strlen((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
    Pusher::payloadBytes($payload) . ' bytes');

// 服务端判据是**紧凑重新编码**后的字节数，而不是用户输入的原文长度：
// 一个多行缩进的载荷原文可能大得多 —— 按原文拦就是误杀（前端 `encodePayload` 同样口径）。
$pretty = "{\n    \"a\": \"中文\"\n}";
check('★ 字节数按紧凑编码计，不按原文（格式化 JSON 不被误拦）',
    Pusher::payloadBytes(['a' => '中文']) < strlen($pretty),
    'compact=' . Pusher::payloadBytes(['a' => '中文']) . ' raw=' . strlen($pretty));

check('零载荷按服务端的 [] 计 2 字节', Pusher::payloadBytes([]) === 2, Pusher::payloadBytes([]) . ' bytes');
check('payloadMax() 无配置时回落镜像值', Pusher::payloadMax() === Pusher::PAYLOAD_MAX_MIRROR);

// ★ 只允许**调小**：服务端上限没变，后台放开只会产出「已受理但被静默丢弃」的载荷 ——
//   正是第 6 节反证 A 要消灭的那类故障。故 `payloadMax()` 是**夹取**而不是采信。
//   （初版验收脚本在这里断言「8192 被采纳」，跑出 FAIL —— 错的是断言，不是代码。）
check('★ payloadMax(8192) 调大**无效**，仍回落镜像值（放开只会产出被静默丢弃的载荷）',
    Pusher::payloadMax(Pusher::PAYLOAD_MAX_MIRROR * 2) === Pusher::PAYLOAD_MAX_MIRROR,
    'payloadMax(8192)=' . Pusher::payloadMax(8192));
check('★ payloadMax(1024) 调小生效', Pusher::payloadMax(1024) === 1024);
check('  payloadMax(0) / 负数 走镜像值',
    Pusher::payloadMax(0) === Pusher::PAYLOAD_MAX_MIRROR
    && Pusher::payloadMax(-1) === Pusher::PAYLOAD_MAX_MIRROR);

$max = Pusher::payloadMax();

$ok = Pusher::validatePush(['target_type' => 'uid', 'target' => FIX_TARGET_UID, 'payload' => ['x' => 1]], $max);
check('validatePush 放行合法请求', $ok['ok'], implode('; ', $ok['errors']));
check('★ msg_id 留空时自动补一个（服务端只在有 msg_id 时去重）',
    $ok['msg_id_generated'] === true && $ok['job']['msg_id'] !== '', 'msg_id=' . $ok['job']['msg_id']);
check('自动补的 msg_id 是 16 字符（8 字节 hex）',
    strlen($ok['job']['msg_id']) === Pusher::MSG_ID_LEN, strlen($ok['job']['msg_id']) . ' 字符');

$badType = Pusher::validatePush(['target_type' => 'topic', 'target' => 'x', 'payload' => []], $max);
check('★ 非法 target_type 被拒（R1：不存在「按主题推送」）',
    !$badType['ok'], implode('; ', $badType['errors']));

$noTarget = Pusher::validatePush(['target_type' => 'uid', 'target' => '  ', 'payload' => []], $max);
check('空 target 被拒', !$noTarget['ok'], implode('; ', $noTarget['errors']));

$over = Pusher::validatePush(
    ['target_type' => 'uid', 'target' => FIX_TARGET_UID, 'payload' => ['big' => str_repeat('中', $max)]],
    $max
);
check('★ 超限载荷被拒（放过去会静默丢弃，见第 6 节的反证 A）', !$over['ok'],
    'bytes=' . $over['bytes'] . ' max=' . $over['max']);
check('  超限时字节数如实给出（前端据此定位差值）', $over['bytes'] > $max);

check('normalizeOfflineMode 对未知值返回 null（区别于「没传」= 空串）',
    Pusher::normalizeOfflineMode('nope') === null && Pusher::normalizeOfflineMode('') === '');

check('ActionCatalog::names() = 6 个 HTTP 开放动作',
    count(ActionCatalog::names()) === 6, implode('/', ActionCatalog::names()));
check('session 不在 HTTP 开放清单内', !in_array('session', ActionCatalog::names(), true));
check('forUi() 每项都带 UI 必需元信息',
    array_reduce(ActionCatalog::forUi(), static function (bool $c, array $a): bool {
        return $c && isset($a['name'], $a['description'], $a['params_hint'])
            && array_key_exists('requires_uid', $a);
    }, true));
check('validRequestId 接受调用方自造 id（比 16 位 hex 宽得多）',
    ActionCatalog::validRequestId('A_b-9') && !ActionCatalog::validRequestId('bad id!'));

$redacted = Auditor::redact([
    'api_secret' => 'x',
    'password' => 'y',
    'nested' => ['token' => 'z'],
    'keep' => 'v',
]);
check('★ 审计脱敏：密钥类字段一律打码，普通字段原样保留',
    $redacted['api_secret'] === Auditor::MASK
    && $redacted['password'] === Auditor::MASK
    && $redacted['nested']['token'] === Auditor::MASK
    && $redacted['keep'] === 'v');

// ===========================================================================
// 3. 静态一致性：节点 ↔ 控制器动作 ↔ 路由 ↔ 角色规则
// ===========================================================================
section('[3] 静态一致性：install.php 节点 ↔ 控制器动作 ↔ 路由动词 ↔ 角色规则');

$installSrc = (string)@file_get_contents(__DIR__ . '/../../scripts/install.php');
$routeSrc = (string)@file_get_contents(__DIR__ . '/../../config/route.php');
$apiSources = [
    'api\\PushController' => (string)@file_get_contents(__DIR__ . '/../../app/controller/api/PushController.php'),
    'api\\ActionController' => (string)@file_get_contents(__DIR__ . '/../../app/controller/api/ActionController.php'),
];

$nodeAliases = [
    'push', 'actions',
    'push.create', 'push.history', 'push.tplList', 'push.tplSave', 'push.tplDelete',
    'action.invoke', 'action.result',
];

$nodes = [];
preg_match_all(
    "/'(push|actions|push\\.create|push\\.history|push\\.tplList|push\\.tplSave|push\\.tplDelete"
    . "|action\\.invoke|action\\.result)'\\s*=>\\s*\\[[^]]*'key'\\s*=>\\s*'([^']+)'/",
    $installSrc,
    $nm,
    PREG_SET_ORDER
);
foreach ($nm as $hit) {
    $nodes[$hit[1]] = str_replace('\\\\', '\\', $hit[2]);
}

$missingNodes = array_values(array_diff($nodeAliases, array_keys($nodes)));
check('★ install.php 里 9 个 P3 节点齐全（含 2 个菜单节点）', $missingNodes === [],
    $missingNodes === [] ? '解析到 ' . count($nodes) . ' 个' : '缺 ' . implode('、', $missingNodes));

$badNodes = [];
foreach ($nodes as $alias => $key) {
    if (!preg_match('/^(.*)@([a-zA-Z]+)$/', $key, $m)) {
        // 菜单节点：key 就是控制器全类名，没有 @action
        if (!str_contains($key, 'controller')) {
            $badNodes[] = $alias . '（key 形态异常：' . $key . '）';
        }
        continue;
    }
    $src = '';
    foreach ($apiSources as $needle => $content) {
        if (str_contains($m[1], $needle)) {
            $src = $content;
        }
    }
    if ($src === '' || !preg_match('/function\s+' . preg_quote($m[2], '/') . '\s*\(/', $src)) {
        $badNodes[] = $alias . ' → ' . $key;
    }
}
check('★ 每个 {控制器}@{action} 都能在控制器里找到真实方法', $badNodes === [], implode('、', $badNodes));

$viewerBlock = '';
preg_match('/\$viewerRules\s*=\s*\[(.*?)\];/s', $installSrc, $vm);
$viewerBlock = (string)($vm[1] ?? '');
check('★ 只读角色不含任何 P3 写权限点',
    $viewerBlock !== ''
    && !str_contains($viewerBlock, 'push.create')
    && !str_contains($viewerBlock, 'push.tplSave')
    && !str_contains($viewerBlock, 'push.tplDelete')
    && !str_contains($viewerBlock, 'action.invoke')
    && !str_contains($viewerBlock, 'action.result')
    && !str_contains($viewerBlock, "\$nodeIds['actions']"));
check('★ 只读角色含 P3 只读三项（push / push.history / push.tplList）',
    $viewerBlock !== ''
    && str_contains($viewerBlock, "\$nodeIds['push']")
    && str_contains($viewerBlock, "\$nodeIds['push.history']")
    && str_contains($viewerBlock, "\$nodeIds['push.tplList']"));

$routeExpect = [
    "#Route::post\('/push',\s*\[PushApiController::class,\s*'create'\]\)#" => 'POST /api/push → create',
    "#Route::get\('/push/history',\s*\[PushApiController::class,\s*'history'\]\)#" => 'GET /api/push/history → history',
    "#Route::get\('/push/templates',\s*\[PushApiController::class,\s*'templateList'\]\)#" => 'GET /api/push/templates → templateList',
    "#Route::post\('/push/templates',\s*\[PushApiController::class,\s*'templateSave'\]\)#" => 'POST /api/push/templates → templateSave',
    "#Route::delete\('/push/templates/\{id\}',\s*\[PushApiController::class,\s*'templateDelete'\]\)#" => 'DELETE /api/push/templates/{id} → templateDelete',
    "#Route::post\('/action',\s*\[ActionApiController::class,\s*'invoke'\]\)#" => 'POST /api/action → invoke',
    "#Route::get\('/action/\{requestId\}',\s*\[ActionApiController::class,\s*'result'\]\)#" => 'GET /api/action/{requestId} → result',
];
foreach ($routeExpect as $pattern => $label) {
    check('★ 路由 ' . $label, (bool)preg_match($pattern, $routeSrc));
}

check('★ 两个 P3 页面路由都挂了 AdminAuth',
    (bool)preg_match(
        "#Route::get\('/push',\s*\[PushController::class,\s*'index'\]\)\s*->middleware\(\[AdminAuth::class\]\)#",
        $routeSrc
    )
    && (bool)preg_match(
        "#Route::get\('/actions',\s*\[ActionController::class,\s*'index'\]\)\s*->middleware\(\[AdminAuth::class\]\)#",
        $routeSrc
    ));
check('★ 四个 P3 控制器都已 disableDefaultRoute（默认路径 ≠ 显式路径 ⇒ 不禁就是 4 个免鉴权入口）',
    str_contains($routeSrc, 'Route::disableDefaultRoute(PushController::class);')
    && str_contains($routeSrc, 'Route::disableDefaultRoute(ActionController::class);')
    && str_contains($routeSrc, 'Route::disableDefaultRoute(PushApiController::class);')
    && str_contains($routeSrc, 'Route::disableDefaultRoute(ActionApiController::class);'));

// ---------------------------------------------------------------------------
// 3b. DB 实际状态
//
// ★ 这一节专治一个真实踩过的盲区：上面的「静态一致性」只读 `install.php` 源码，
//   而**节点只有在 `php scripts/install.php` 跑过之后才进 DB**。
//   P3 落地时实测 `wa_rules` 里仍只有 P1 的 5 个节点 —— 静态检查全绿，
//   而非超管访问 `/push`、`/actions` 一律 403，超管因 `rules='*'` 完全看不出来。
//   ⇒ 必须有一节拿 DB 真值对拍。
// ---------------------------------------------------------------------------

$dbRules = null;
$dbRoles = null;
try {
    $dbRules = Db::table('wa_rules')->pluck('id', 'key')->toArray();
    $dbRoles = Db::table('wa_roles')->get();
} catch (Throwable $e) {
    note('DB 节点一致性', 'wa_rules / wa_roles 不可读：' . $e->getMessage());
}

if (is_array($dbRules)) {
    $missingInDb = [];
    foreach ($nodes as $alias => $key) {
        if (!isset($dbRules[$key])) {
            $missingInDb[] = $alias . ' → ' . $key;
        }
    }
    check('★ 9 个 P3 节点都已写进 wa_rules（install.php 跑过才生效，静态检查发现不了）',
        $missingInDb === [],
        $missingInDb === []
            ? 'wa_rules 共 ' . count($dbRules) . ' 条'
            : '缺 ' . count($missingInDb) . ' 个：' . implode('；', $missingInDb)
              . ' —— 运行 php scripts/install.php');

    $roleIds = [];
    // ⚠ `support\Db::table()->get()` 在本项目的取数模式下返回 **array of array**（不是 stdClass），
    //   故统一走 `(array)$r` + 键访问，不写 `$r->rules`（实测会 "Attempt to read property on array"）。
    // ⚠ `(array)$collection` 拿到的是 Collection 的**保护属性数组**（只有 `items` 一个键），
    //   不是元素列表 —— 必须先 `->all()`。这是 Collection 特有的坑，别再用强制转换。
    $roleRows = $dbRoles instanceof \Illuminate\Support\Collection ? $dbRoles->all() : (array)$dbRoles;
    foreach ($roleRows as $r) {
        $r = (array)$r;
        $ids = array_values(array_filter(array_map('trim', explode(',', (string)($r['rules'] ?? '')))));
        $roleIds[(string)($r['name'] ?? '')] = array_map('intval', $ids);
    }

    $viewerIds = null;
    $operatorIds = null;
    foreach ($roleIds as $name => $ids) {
        if (str_contains($name, '只读')) {
            $viewerIds = $ids;
        } elseif (str_contains($name, '运维')) {
            $operatorIds = $ids;
        }
    }

    $idOf = static function (string $alias) use ($nodes, $dbRules): int {
        return (int)($dbRules[$nodes[$alias] ?? ''] ?? 0);
    };

    if (is_array($viewerIds)) {
        $mustHave = ['push', 'push.history', 'push.tplList'];
        $mustNotHave = ['push.create', 'push.tplSave', 'push.tplDelete', 'actions', 'action.invoke', 'action.result'];

        $lack = [];
        foreach ($mustHave as $a) {
            if (!in_array($idOf($a), $viewerIds, true)) {
                $lack[] = $a;
            }
        }
        $leak = [];
        foreach ($mustNotHave as $a) {
            if (in_array($idOf($a), $viewerIds, true)) {
                $leak[] = $a;
            }
        }

        check('★ DB 里只读角色确实拿到 P3 只读三项', $lack === [],
            $lack === [] ? '' : '缺 ' . implode('、', $lack));
        check('★ DB 里只读角色确实拿不到 P3 写权限与动作调试', $leak === [],
            $leak === [] ? '' : '越权拿到 ' . implode('、', $leak));
        check('  只读角色节点数 = 21（行为日志页 + audit.list；新增阶段须同步更新本断言）', count($viewerIds) === 21,
            '实际 ' . count($viewerIds) . ' 个');
    } else {
        note('DB 只读角色', 'wa_roles 里找不到「只读」角色');
    }

    if (is_array($operatorIds)) {
        $lackOp = [];
        foreach ($nodeAliases as $a) {
            if (!in_array($idOf($a), $operatorIds, true)) {
                $lackOp[] = $a;
            }
        }
        check('★ DB 里运维角色拿到全部 9 个 P3 节点', $lackOp === [],
            $lackOp === [] ? '' : '缺 ' . implode('、', $lackOp));
        // P4 追加了 4 个运维动作节点（ops.kick / ops.revoke / ops.unbind / ops.forceOffline）
        check('  运维角色节点数 = 39（行为日志页 + audit.list；新增阶段须同步更新本断言）', count($operatorIds) === 39,
            '实际 ' . count($operatorIds) . ' 个');

        // 只读角色**一个运维节点都不能有** —— 它们会改变别人的连接状态且不可撤销
        $opsLeak = [];
        foreach (['ops.kick', 'ops.revoke', 'ops.unbind', 'ops.forceOffline'] as $a) {
            if (isset($dbRules[$nodes[$a] ?? '']) && in_array((int)$dbRules[$nodes[$a]], $viewerIds, true)) {
                $opsLeak[] = $a;
            }
        }
        if (is_array($viewerIds)) {
            check('★ DB 里只读角色拿不到任何 P4 运维节点', $opsLeak === [],
                $opsLeak === [] ? '' : '越权拿到 ' . implode('、', $opsLeak));
        }
    } else {
        note('DB 运维角色', 'wa_roles 里找不到「运维」角色');
    }
}

// ===========================================================================
// 4. 默认路由回归 + 未登录（HTTP，无需登录）
// ===========================================================================
section('[4] 默认路由回归与未登录拦截');

$pushJs = http('GET', '/static/push.js');
$adminOnline = $pushJs['status'] === 200 && strlen($pushJs['body']) > 1000;

if (!$adminOnline) {
    note('HTTP 层检查', '后台未在线或静态资源不可达（HTTP ' . $pushJs['status'] . '）');
} else {
    check('GET /static/push.js → 200', strlen($pushJs['body']) > 1000, strlen($pushJs['body']) . ' bytes');
    $pushCss = http('GET', '/static/push.css');
    check('GET /static/push.css → 200',
        $pushCss['status'] === 200 && strlen($pushCss['body']) > 500,
        'HTTP ' . $pushCss['status'] . ' ' . strlen($pushCss['body']) . ' bytes');
    $actionJs = http('GET', '/static/action.js');
    check('GET /static/action.js → 200',
        $actionJs['status'] === 200 && strlen($actionJs['body']) > 1000,
        'HTTP ' . $actionJs['status'] . ' ' . strlen($actionJs['body']) . ' bytes');
    check('action.js 里没有 innerHTML（XSS 纪律）', !preg_match('/\.innerHTML\s*=/', $actionJs['body']));
    check('★ push.js / action.js 里没有 setInterval 族（两页都不允许周期轮询）',
        !preg_match('/\b(setInterval|setImmediate)\s*\(/', $pushJs['body'])
        && !preg_match('/\b(setInterval|setImmediate)\s*\(/', $actionJs['body']));

    $jar = sys_get_temp_dir() . '/gw_p3_' . getmypid() . '.txt';
    @unlink($jar);

    // ⚠ 口径同 P2：被 `disableDefaultRoute` 禁掉的路径**不**走 webman 默认 404，
    //   而落到 webman-admin 的异常处理器，于是「未鉴权拿不到数据」有三种合法表现：
    //     404 / HTTP 200 + body code=404 / 401（别名被相邻显式路由先匹配）。
    //   三者安全语义等价，故按**集合**判定；写回 `status === 404` 会假红。
    $probe = [
        '/push/index' => '推送管理页控制器的默认路径',
        '/actions/index' => '动作调试页控制器的默认路径',
        '/api/push/index' => '推送 API 控制器的默认路径',
        '/api/action/index' => '动作 API 控制器的默认路径',
        '/api/push/templates/0' => '模板删除的默认路径形态',
    ];
    foreach ($probe as $path => $desc) {
        $res = http('GET', $path, [], ['Accept: application/json'], $jar);
        $code = (int)(jsonBody($res['body'])['code'] ?? 0);
        check('★ 未登录 ' . $path . ' → 不可达（404/401/200+code404）：' . $desc,
            in_array($res['status'], [404, 401], true) || ($res['status'] === 200 && $code === 404),
            'HTTP ' . $res['status'] . ' code=' . ($code !== 0 ? $code : '-'));
    }

    foreach (['/push', '/actions'] as $path) {
        $res = http('GET', $path, [], ['Accept: text/html'], $jar);
        check('★ 未登录 GET ' . $path . ' → 302 到登录页',
            $res['status'] === 302, 'HTTP ' . $res['status'] . ' Location=' . ($res['headers']['location'] ?? '-'));
    }

    foreach (['/api/push', '/api/push/templates', '/api/action'] as $path) {
        $res = http('POST', $path, ['x' => '1'], ['Accept: application/json'], $jar);
        check('★ 未登录 POST ' . $path . ' → 401/403/302',
            in_array($res['status'], [401, 403, 302], true), 'HTTP ' . $res['status']);
    }
    $del = http('DELETE', '/api/push/templates/1', [], ['Accept: application/json'], $jar);
    check('★ 未登录 DELETE /api/push/templates/1 → 401/403/302',
        in_array($del['status'], [401, 403, 302], true), 'HTTP ' . $del['status']);

    // =======================================================================
    // 5. HTTP 层（登录后）
    // =======================================================================
    section('[5] HTTP 层：页面骨架、校验分支、补查语义（登录后）');

    $adminUser = (string)($env['ADMIN_BOOTSTRAP_USER'] ?? '');
    $adminPass = (string)($env['ADMIN_BOOTSTRAP_PASS'] ?? '');

    if ($adminUser === '' || $adminPass === '') {
        note('登录后检查', '.env 缺 ADMIN_BOOTSTRAP_USER/PASS');
    } elseif (!login($jar, $adminUser, $adminPass)) {
        check('登录（含验证码）', false, $adminUser . ' 登录失败');
    } else {
        check('登录（含验证码）', true, $adminUser);

        // ---- 5.1 页面骨架 ----
        $pages = [
            '/push' => ['push-config', '/static/push.js', '/static/push.css', 'sec-create'],
            '/actions' => ['action-config', '/static/action.js', '/static/action.css', 'sec-invoke'],
        ];
        foreach ($pages as $path => [$cfgId, $js, $cssAsset, $sectionId]) {
            $page = http('GET', $path, [], ['Accept: text/html'], $jar);
            $html = $page['body'];
            check('登录后 GET ' . $path . ' → 200', $page['status'] === 200, 'HTTP ' . $page['status']);
            check('  ' . $path . ' 含 #' . $cfgId . ' 注入块', str_contains($html, 'id="' . $cfgId . '"'));
            check('  ' . $path . ' 引用 ' . $js . ' 与 ' . $cssAsset,
                str_contains($html, $js) && str_contains($html, $cssAsset));
            check('  ' . $path . ' 含 #' . $sectionId . ' 主区块', str_contains($html, 'id="' . $sectionId . '"'));
            check('  ' . $path . ' 无 meta refresh', !str_contains($html, 'http-equiv="refresh"'));
        }

        // ---- 5.2 /api/push 的校验分支（全部在入队前失败，不产生副作用）----
        $pushCases = [
            'target_type 非法' => ['target_type' => 'topic', 'target' => FIX_TARGET_UID, 'payload' => ['x' => 1]],
            'target 为空' => ['target_type' => 'uid', 'target' => '', 'payload' => ['x' => 1]],
            'target 含控制字符' => ['target_type' => 'uid', 'target' => "a\nb", 'payload' => []],
            'payload 非对象' => ['target_type' => 'uid', 'target' => FIX_TARGET_UID, 'payload' => 'not-json'],
            'offline_mode 非法' => [
                'target_type' => 'uid',
                'target' => FIX_TARGET_UID,
                'payload' => [],
                'offline_mode' => 'nope',
            ],
            'msg_id 超长' => [
                'target_type' => 'uid',
                'target' => FIX_TARGET_UID,
                'payload' => [],
                'msg_id' => str_repeat('x', Pusher::MSG_ID_MAX_LEN + 1),
            ],
        ];
        foreach ($pushCases as $label => $body) {
            $res = http('POST', '/api/push', $body, ['Accept: application/json'], $jar);
            $b = jsonBody($res['body']);
            check('★ /api/push ' . $label . ' → 400 + 4007',
                $res['status'] === 400 && (int)($b['code'] ?? 0) === 4007,
                'HTTP ' . $res['status'] . ' code=' . ($b['code'] ?? '?'));
        }

        // ★ 超限载荷必须被后台拦下（第 6 节的反证 A 说明「放过去会怎样」）
        $overRes = http('POST', '/api/push', [
            'target_type' => 'uid',
            'target' => FIX_TARGET_UID,
            'payload' => ['big' => str_repeat('中', $max)],
        ], ['Accept: application/json'], $jar);
        $overBody = jsonBody($overRes['body']);
        check('★ /api/push 超限载荷 → 400 + 4007（绝不返回 200 accepted）',
            $overRes['status'] === 400 && (int)($overBody['code'] ?? 0) === 4007,
            'HTTP ' . $overRes['status'] . ' code=' . ($overBody['code'] ?? '?') . ' max=' . $max);

        // ---- 5.3 /api/push/history 的筛选白名单（非法条件被静默丢弃）----
        $histClean = http('GET', '/api/push/history?page=1&size=20', [], ['Accept: application/json'], $jar);
        $histCleanData = dataOf(jsonBody($histClean['body']));
        check('GET /api/push/history → 200 + code=0',
            $histClean['status'] === 200 && (int)(jsonBody($histClean['body'])['code'] ?? -1) === 0,
            'HTTP ' . $histClean['status']);
        $histKeys = ['items', 'total', 'page', 'size', 'pages', 'filters', 'summary', 'statuses', 'size_max', 'record_note'];
        $missingHist = array_values(array_filter(
            $histKeys,
            static fn (string $k): bool => !array_key_exists($k, $histCleanData)
        ));
        check('  历史响应含字段：' . implode('/', $histKeys), $missingHist === [],
            $missingHist === [] ? '' : '缺 ' . implode(',', $missingHist));
        check('★ 历史页带「受理记录」口径说明（不得宣称已投递/已去重）',
            str_contains((string)($histCleanData['record_note'] ?? ''), '受理'),
            mb_substr((string)($histCleanData['record_note'] ?? ''), 0, 40));

        // ★ 反证：非法筛选**不报错**，直接被服务端丢掉 ——
        //   所以前端必须自己预校验，否则用户会以为「筛了但没生效」（这是本页的一个已修缺陷）。
        $histDirty = http(
            'GET',
            '/api/push/history?page=1&size=20&target=' . rawurlencode("bad\x01id") . '&status=nope&foo=1',
            [],
            ['Accept: application/json'],
            $jar
        );
        $histDirtyData = dataOf(jsonBody($histDirty['body']));
        check('★ 反证：非法筛选被静默丢弃（返回 200 且 filters 里没有它们）',
            $histDirty['status'] === 200
            && !array_key_exists('target', (array)($histDirtyData['filters'] ?? []))
            && !array_key_exists('foo', (array)($histDirtyData['filters'] ?? [])),
            'filters=' . json_encode($histDirtyData['filters'] ?? null));
        check('  被丢弃的筛选不改变结果集（total 与不带筛选一致）',
            (int)($histDirtyData['total'] ?? -1) === (int)($histCleanData['total'] ?? -2));

        $tplList = http('GET', '/api/push/templates', [], ['Accept: application/json'], $jar);
        check('GET /api/push/templates → 200 + code=0',
            $tplList['status'] === 200 && (int)(jsonBody($tplList['body'])['code'] ?? -1) === 0,
            'HTTP ' . $tplList['status']);

        // ---- 5.4 /api/action 的校验分支与补查语义 ----
        $invBad = http('POST', '/api/action', ['action' => 'nope', 'uid' => FIX_TARGET_UID], ['Accept: application/json'], $jar);
        $invBadBody = jsonBody($invBad['body']);
        check('★ /api/action 未知动作 → 400 + 4007',
            $invBad['status'] === 400 && (int)($invBadBody['code'] ?? 0) === 4007,
            'HTTP ' . $invBad['status'] . ' code=' . ($invBadBody['code'] ?? '?'));
        check('  拒绝文案里列出允许的动作清单', is_array(dataOf($invBadBody)['allowed'] ?? null));

        $sessInv = http('POST', '/api/action', ['action' => 'session', 'uid' => FIX_TARGET_UID], ['Accept: application/json'], $jar);
        $sessBody = jsonBody($sessInv['body']);
        check('★ /api/action session（http=false）→ 400 + 4007',
            $sessInv['status'] === 400 && (int)($sessBody['code'] ?? 0) === 4007,
            'HTTP ' . $sessInv['status'] . ' code=' . ($sessBody['code'] ?? '?'));

        $noUid = http('POST', '/api/action', ['action' => 'echo'], ['Accept: application/json'], $jar);
        $noUidBody = jsonBody($noUid['body']);
        check('★ /api/action 缺 uid → 400 + 4007（6 个动作的 auth 均为 true）',
            $noUid['status'] === 400 && (int)($noUidBody['code'] ?? 0) === 4007,
            'HTTP ' . $noUid['status'] . ' code=' . ($noUidBody['code'] ?? '?'));

        $badId = http('GET', '/api/action/bad%20id!', [], ['Accept: application/json'], $jar);
        check('★ GET /api/action/{非法 id} → 400 + 4007',
            $badId['status'] === 400 && (int)(jsonBody($badId['body'])['code'] ?? 0) === 4007,
            'HTTP ' . $badId['status']);

        // ★ 反证 C：补查未命中**不是** 404
        $miss = http('GET', '/api/action/' . FIX . 'nosuchid0001', [], ['Accept: application/json'], $jar);
        $missBody = jsonBody($miss['body']);
        $missData = dataOf($missBody);
        check('★ 反证C：补查未命中 → HTTP 200 + code=0（不是 404，否则前端会把它当接口故障并打断补查）',
            $miss['status'] === 200 && (int)($missBody['code'] ?? -1) === 0,
            'HTTP ' . $miss['status'] . ' code=' . ($missBody['code'] ?? '?'));
        check('★ 反证C：state=expired 且解释里明说「不区分」两种含义',
            ($missData['state'] ?? '') === 'expired'
            && str_contains((string)($missData['note'] ?? ''), '不区分'),
            'state=' . var_export($missData['state'] ?? null, true));
        check('★ 反证C：expired 的重发判定是 unknown / 待确认（**不是** safe）',
            ($missData['resend'] ?? '') === 'unknown' && ($missData['resend_label'] ?? '') === '待确认',
            'resend=' . var_export($missData['resend'] ?? null, true)
            . ' label=' . var_export($missData['resend_label'] ?? null, true));
        check('  补查不落审计（audited=false —— 每次轮询都写会把审计表刷满）',
            ($missData['audited'] ?? null) === false);
        check('  补查响应带 resend_note（前端不编词）', (string)($missData['resend_note'] ?? '') !== '');

        // ---- 5.5 viewer 动态矩阵：只读角色拿不到任何 P3 写权限 ----
        $viewerUser = (string)($env['ADMIN_VIEWER_USER'] ?? '');
        $viewerPass = (string)($env['ADMIN_VIEWER_PASS'] ?? '');
        if ($viewerUser === '' || $viewerPass === '') {
            note('viewer 动态矩阵', '未提供 ADMIN_VIEWER_USER / ADMIN_VIEWER_PASS（可选）');
        } else {
            $vjar = sys_get_temp_dir() . '/gw_p3v_' . getmypid() . '.txt';
            @unlink($vjar);
            if (login($vjar, $viewerUser, $viewerPass)) {
                foreach (['/api/push/history', '/api/push/templates'] as $path) {
                    $res = http('GET', $path, [], ['Accept: application/json'], $vjar);
                    check('viewer GET ' . $path . ' → 200', $res['status'] === 200, 'HTTP ' . $res['status']);
                }
                $denied = [
                    'POST /api/push' => http('POST', '/api/push', [
                        'target_type' => 'uid', 'target' => 'x', 'payload' => [],
                    ], ['Accept: application/json'], $vjar),
                    'POST /api/action' => http('POST', '/api/action', [
                        'action' => 'echo', 'uid' => 'x',
                    ], ['Accept: application/json'], $vjar),
                    'POST /api/push/templates' => http('POST', '/api/push/templates', [
                        'name' => FIX . '-v', 'target_type' => 'uid', 'payload' => [],
                    ], ['Accept: application/json'], $vjar),
                    'DELETE /api/push/templates/1' => http('DELETE', '/api/push/templates/1', [], ['Accept: application/json'], $vjar),
                ];
                foreach ($denied as $label => $res) {
                    check('★ viewer ' . $label . ' → 403（只读角色不得有 P3 写权限）',
                        $res['status'] === 403, 'HTTP ' . $res['status']);
                }
                $roActions = http('GET', '/actions', [], ['Accept: text/html'], $vjar);
                check('★ viewer GET /actions → 403（动作调试只给运维）',
                    $roActions['status'] === 403, 'HTTP ' . $roActions['status']);
                $roPush = http('GET', '/push', [], ['Accept: text/html'], $vjar);
                check('  viewer GET /push → 200（只读角色可看历史与模板）',
                    $roPush['status'] === 200, 'HTTP ' . $roPush['status']);
            } else {
                check('viewer 登录', false, $viewerUser . ' 登录失败');
            }
            @unlink($vjar);
        }

        @unlink($jar);
    }
}

// ===========================================================================
// 6. MySQL 层与「语义反证」（需 --seed）
// ===========================================================================
section('[6] 真实链路：落库、静默丢弃、幂等不可观测（需 --seed）');

if (!$seed) {
    note('真实链路检查', '未加 --seed —— 本节会向真实目标投递消息，故默认不执行');
} else {
    $dbOk = true;
    try {
        Db::table('push_task')->count();
    } catch (Throwable $e) {
        $dbOk = false;
        note('真实链路检查', 'MySQL 不可用：' . $e->getMessage());
    }

    if (!$dbOk) {
        // 已 SKIP
    } else {
        // 夹具自检：`request_id` 列宽 16。超长会以「Data too long」致命退出而不是干净 FAIL，
        // 故先量一遍 —— 把「常量漂移」变成一条可诊断的断言。
        $widths = [
            'FIX_REQ_ACC' => strlen(FIX_REQ_ACC),
            'FIX_REQ_REJ' => strlen(FIX_REQ_REJ),
            'FIX_MSG_ID' => strlen(FIX_MSG_ID),
        ];
        foreach ($widths as $name => $len) {
            $limit = $name === 'FIX_MSG_ID' ? 64 : 16;
            check('夹具自检：' . $name . ' 不超过列宽 ' . $limit, $len <= $limit, '实际 ' . $len . ' 字符');
        }

        try {
            seedFixture();
            echo '  夹具已建立（push_task 2 行，全部 ' . FIX . '-*）' . PHP_EOL;

            // ---- 6.1 分页 / 筛选 / 汇总 ----
            $noFilter = PushRepository::page([], 1, 20, 20);
            check('PushRepository::page 返回夹具行', $noFilter['total'] >= 2, 'total=' . $noFilter['total']);
            check('  分页字段自洽（pages = ceil(total/size)）',
                (int)$noFilter['pages'] === (int)ceil($noFilter['total'] / max(1, $noFilter['size'])),
                'pages=' . $noFilter['pages'] . ' total=' . $noFilter['total'] . ' size=' . $noFilter['size']);

            // ★ 服务端侧的同一条反证：非法筛选静默丢弃，不报错
            $dirty = ['target' => "bad\x01id", 'msg_id' => str_repeat('x', 65), 'status' => 'nope', 'foo' => '1'];
            check('★ 非法筛选被 appliedFilters 静默丢弃（不报错 ⇒ 前端必须自己拦）',
                PushRepository::appliedFilters($dirty) === [],
                'applied=' . json_encode(PushRepository::appliedFilters($dirty)));
            $dirtyPage = PushRepository::page($dirty, 1, 20, 20);
            check('  带脏筛选的结果与不带筛选完全一致（= 条件被丢弃）',
                $dirtyPage['total'] === $noFilter['total'],
                $dirtyPage['total'] . ' vs ' . $noFilter['total']);

            check('★ validDate 拒绝「格式合法但日期非法」（不静默顺延成 3 月 3 日）',
                PushRepository::validDate('2026-02-31') === ''
                && PushRepository::validDate('2026-02-28') === '2026-02-28');

            $summary = PushRepository::summary();
            check('summary 与 page 的 total 一致', $summary['total'] === $noFilter['total'],
                'summary=' . $summary['total'] . ' page=' . $noFilter['total']);
            check('  rejected = total - accepted',
                $summary['rejected'] === $summary['total'] - $summary['accepted'], json_encode($summary));

            // ---- 6.2 模板 CRUD 往返 ----
            $tplId = TemplateRepository::save(null, [
                'name' => FIX_TPL_NAME,
                'target_type' => 'device',
                'payload' => ['tpl' => FIX],
                'offline_mode' => 'queue',
                'remark' => 'p3 验收夹具',
            ], FIX_OPERATOR);
            check('★ 模板新建返回自增 id', $tplId > 0, 'id=' . $tplId);
            check('★ nameTaken() 能识别重名（保存前必查唯一键）', TemplateRepository::nameTaken(FIX_TPL_NAME));

            $found = TemplateRepository::find($tplId);
            check('  find() 能取回刚建的模板', is_array($found) && ($found['name'] ?? '') === FIX_TPL_NAME);
            check('  模板以 payload（解码）+ payload_raw（原文）两种形态回带',
                is_array($found) && array_key_exists('payload', $found) && array_key_exists('payload_raw', $found));

            $updated = TemplateRepository::save($tplId, [
                'name' => FIX_TPL_NAME,
                'target_type' => 'client',
                'payload' => ['tpl' => 'updated'],
                'offline_mode' => 'drop',
                'remark' => 'p3 验收夹具（已更新）',
            ], FIX_OPERATOR);
            check('  更新走同一个 save()（id 非空即更新）', $updated === $tplId, 'id=' . $updated);
            $afterUpdate = TemplateRepository::find($tplId);
            check('  更新后 target_type 已变为 client',
                is_array($afterUpdate) && ($afterUpdate['target_type'] ?? '') === 'client');

            check('★ 删除返回 true', TemplateRepository::delete($tplId));
            check('★ 删除后 find() 返回 null（硬删除，名字可复用）', TemplateRepository::find($tplId) === null);
            check('  nameTaken() 在删除后回到 false', !TemplateRepository::nameTaken(FIX_TPL_NAME));

            // ---- 6.3 ★ 反证 A：payload 超限的静默丢弃 ----
            $client = GatewayPushClient::fromConfig();
            $health = $client->health();
            if (!($health['ok'] ?? false)) {
                note('反证 A/B 与端到端推送', '主项目 API 不可达（' . substr((string)($health['msg'] ?? ''), 0, 60) . '）');
            } else {
                $beforeFail = (int)(counters($reader, $redisOk)['push_fail'] ?? 0);
                $beforeDedup = (int)(counters($reader, $redisOk)['push_dedup'] ?? 0);

                $overLimit = $client->push([
                    'target_type' => 'uid',
                    'target' => FIX_TARGET_UID,
                    'payload' => ['big' => str_repeat('中', $max)],
                    'offline_mode' => 'drop',
                ]);
                check('★ 反证A：直连主项目发**超限**载荷 → 主项目仍回受理（code=0）',
                    (bool)($overLimit['ok'] ?? false),
                    'HTTP ' . var_export($overLimit['status'] ?? null, true)
                    . ' code=' . var_export($overLimit['code'] ?? null, true)
                    . ' msg=' . substr((string)($overLimit['msg'] ?? ''), 0, 60));

                // 计数增量才是「丢弃真的发生了」的证据 —— 需要 business 角色消费队列
                $afterFail = $beforeFail;
                if ($redisOk) {
                    for ($i = 0; $i < 12 && $afterFail <= $beforeFail; $i++) {
                        usleep(300000);
                        $afterFail = (int)($reader->counter()['push_fail'] ?? $beforeFail);
                    }
                }
                if (!$redisOk) {
                    note('反证A 的计数增量', 'Redis 不可达，无法读 metrics:counter');
                } elseif ($afterFail > $beforeFail) {
                    check('★ 反证A：push_fail 计数增加 —— 证明主项目**丢弃**了它而不是投递',
                        true, $beforeFail . ' → ' . $afterFail);
                } else {
                    note('反证A 的计数增量', 'push_fail 未变化 —— business 角色可能未在线（本项需它消费队列）');
                }

                // ---- 6.4 ★ 反证 B：幂等的不可观测性 ----
                $dedupJob = [
                    'target_type' => 'uid',
                    'target' => FIX_TARGET_UID,
                    'payload' => ['probe' => 'dedup'],
                    'msg_id' => FIX_MSG_ID,
                    'offline_mode' => 'drop',
                ];
                $first = $client->push($dedupJob);
                $second = $client->push($dedupJob);

                check('★ 反证B：同 msg_id 连发两次 → 两次都回受理',
                    (bool)($first['ok'] ?? false) && (bool)($second['ok'] ?? false),
                    '1st=' . var_export($first['code'] ?? null, true)
                    . ' 2nd=' . var_export($second['code'] ?? null, true));

                $firstData = dataOf($first);
                $secondData = dataOf($second);
                $firstKeys = array_keys($firstData);
                $secondKeys = array_keys($secondData);
                sort($firstKeys);
                sort($secondKeys);
                check('★ 反证B：两次响应的**键集合完全一致**（没有任何字段能区分「被去重」）',
                    $firstKeys !== [] && $firstKeys === $secondKeys, implode('/', $firstKeys));
                check('★ 反证B：两次的 code 与 msg 也一致（只能靠 request_id 区分是哪一次）',
                    ($first['code'] ?? null) === ($second['code'] ?? null)
                    && ($first['msg'] ?? null) === ($second['msg'] ?? null),
                    'request_id: ' . ($firstData['request_id'] ?? '-') . ' vs ' . ($secondData['request_id'] ?? '-'));

                if ($redisOk) {
                    $afterDedup = $beforeDedup;
                    for ($i = 0; $i < 12 && $afterDedup <= $beforeDedup; $i++) {
                        usleep(300000);
                        $afterDedup = (int)($reader->counter()['push_dedup'] ?? $beforeDedup);
                    }
                    if ($afterDedup > $beforeDedup) {
                        check('★ 反证B：push_dedup 计数增加 —— 去重真的发生了，而两次响应长得一模一样',
                            true, $beforeDedup . ' → ' . $afterDedup);
                    } else {
                        note('反证B 的计数增量', 'push_dedup 未变化 —— business 角色可能未在线，或该 msg_id 已在幂等窗口内');
                    }
                }
            }

            // ---- 6.5 端到端 /api/push + 落库核对（走后台，不走客户端直连）----
            $jarE2e = sys_get_temp_dir() . '/gw_p3e_' . getmypid() . '.txt';
            @unlink($jarE2e);
            $adminUser = (string)($env['ADMIN_BOOTSTRAP_USER'] ?? '');
            $adminPass = (string)($env['ADMIN_BOOTSTRAP_PASS'] ?? '');
            if ($adminUser === '' || !login($jarE2e, $adminUser, $adminPass)) {
                note('端到端 /api/push', '无法登录后台（.env 缺 ADMIN_BOOTSTRAP_USER/PASS，或后台未在线）');
            } else {
                $pushRes = http('POST', '/api/push', [
                    'target_type' => 'uid',
                    'target' => FIX_TARGET_UID,
                    'payload' => ['e2e' => FIX],
                    'msg_id' => FIX . 'e2emsgid000001',
                    'offline_mode' => 'drop',
                ], ['Accept: application/json'], $jarE2e);
                $pushBody = jsonBody($pushRes['body']);
                $pushData = dataOf($pushBody);

                check('★ POST /api/push 端到端 → 200 + code=0',
                    $pushRes['status'] === 200 && (int)($pushBody['code'] ?? -1) === 0,
                    'HTTP ' . $pushRes['status'] . ' code=' . ($pushBody['code'] ?? '?')
                    . ' msg=' . substr((string)($pushBody['msg'] ?? ''), 0, 80));
                check('  响应含 request_id / msg_id / payload_bytes / offline_mode',
                    isset($pushData['request_id'], $pushData['msg_id'], $pushData['payload_bytes'], $pushData['offline_mode']));
                check('★ offline_mode 回带的是**实际生效值**（未传时回落服务端默认）',
                    (string)($pushData['offline_mode'] ?? '') !== '' && isset($pushData['offline_label']),
                    'offline_mode=' . var_export($pushData['offline_mode'] ?? null, true));
                check('  响应带 3 条口径说明（不得宣称已投递）',
                    count((array)($pushData['notes'] ?? [])) === 3);

                $rid = (string)($pushData['request_id'] ?? '');
                if ($rid === '') {
                    note('落库核对', '响应里没有 request_id');
                } else {
                    $row = Db::table('push_task')->where('request_id', $rid)->first();
                    $rowArr = (array)$row;
                    check('★ 受理记录已落库（request_id = ' . $rid . '）', $rowArr !== [], '字段数=' . count($rowArr));
                    check('  落库的 offline_mode 与响应一致（原样落库，不二次推断）',
                        (string)($rowArr['offline_mode'] ?? '') === (string)($pushData['offline_mode'] ?? ''));
                    check('  落库的 payload_bytes 与响应一致',
                        (int)($rowArr['payload_bytes'] ?? -1) === (int)($pushData['payload_bytes'] ?? -2),
                        'db=' . var_export($rowArr['payload_bytes'] ?? null, true)
                        . ' resp=' . var_export($pushData['payload_bytes'] ?? null, true));
                    check('  落库 status = accepted',
                        (string)($rowArr['status'] ?? '') === PushRepository::STATUS_ACCEPTED);
                }
            }
            @unlink($jarE2e);

            // ---- 6.6 审计已落库 ----
            $auditPush = (int)Db::table('admin_audit_log')
                ->where('action', Auditor::ACTION_PUSH_CREATE)
                ->where('target', 'like', FIX . '%')
                ->count();
            check('★ 推送创建已落审计（P3 起提前启用 admin_audit_log）', $auditPush > 0,
                'push.create 行数=' . $auditPush);
        } finally {
            unseedFixture();

            $leftTask = (int)Db::table('push_task')->where('target', 'like', FIX . '%')->count();
            check('★ 夹具清理干净（push_task 无 p3test-* 残留）', $leftTask === 0, '残留 ' . $leftTask . ' 行');
            $leftTpl = (int)Db::table('push_template')->where('name', 'like', FIX . '%')->count();
            check('★ 夹具清理干净（push_template 无 p3test-* 残留）', $leftTpl === 0, '残留 ' . $leftTpl . ' 行');
        }
    }
}

// ===========================================================================
// 汇总
// ===========================================================================
echo PHP_EOL . '=== 汇总：PASS ' . $pass . ' / FAIL ' . $fail . ' / SKIP ' . $skip . ' ===' . PHP_EOL;

exit($fail > 0 ? 1 : 0);
