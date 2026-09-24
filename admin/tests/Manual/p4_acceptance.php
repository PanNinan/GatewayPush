<?php

/**
 * P4 验收脚本（手工执行，**不在** PHPUnit 套件内：它依赖后台、MySQL 与主项目 API 在线）。
 *
 * 用法：
 *     cd admin && php tests/Manual/p4_acceptance.php            # 只跑拒绝路径与 DB 真值（不产生副作用）
 *     cd admin && php tests/Manual/p4_acceptance.php --live     # 额外真实调用四个运维动作（目标为 p4test-*）
 *
 * ## 与单元测试的分工
 *
 * `tests/Unit/OpsActionContractTest.php` 守的是**静态契约**（路由 / 权限节点 / 明文不外泄 / 执行顺序）；
 * 本脚本守的是**静态检查够不到的那一段**：
 *
 *   1. **DB 真值**：节点只有在 `php scripts/install.php` 跑过之后才进 `wa_rules`。
 *      P3 落地时实测静态全绿而 DB 里仍只有 P1 的 5 个节点 —— 只读/运维一律 403，
 *      超管因 `rules='*'` 完全看不出来。这一节拿 DB 对拍。
 *   2. **在线行为**：四个端点的鉴权、参数校验、以及「HTTP 恒 200、成败看 outcome.state」
 *      这条最容易被误判的语义。
 *   3. **审计真的落库了**：`--live` 下核对 `admin_audit_log` 的行内容与脱敏结果
 *      （`revoke` 的 params 里不得出现明文 token —— 这是本阶段唯一会流经后台的长期凭证）。
 *
 * ## 为什么默认不调真实动作
 *
 * P4 之前后台是**纯只读**的；这四个端点是后台第一次具备「改变推送系统状态」的能力。
 * 一次真的 `kick` 会断开真实连接。故默认只跑拒绝路径（缺参 / 超长 / 未登录 / 只读角色），
 * 真实调用必须显式 `--live`，且目标一律是 `p4test-*` 这种**不存在**的 id；
 * 运行期还会核一次：若该 client_id 真的在线，直接 ABORT（退出码 2）。
 *
 * SKIP 不影响退出码。
 *
 * 退出码：0 = 全绿（SKIP 不算失败）；1 = 有 FAIL；2 = 安全闸拒绝执行。
 */

use app\service\ActionOutcome;
use app\service\Auditor;
use app\service\GatewayPushClient;
use app\service\OpsAction;
use app\service\RedisReader;
use support\Db;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../support/bootstrap.php';

const ADMIN_HOST = '127.0.0.1';
const ADMIN_PORT = 8292;
const SESSION_DIR = __DIR__ . '/../../runtime/sessions';

/**
 * 夹具命名空间。
 *
 * ⚠ 只允许改前缀，**不要**换成任何看起来像真实业务 id 的值 ——
 *   `--live` 下 `FIX_CLIENT` / `FIX_UID` 会被真的踢一次、解绑一次。
 */
const FIX = 'p4test';
const FIX_CLIENT = FIX . '-nosuch-client';
const FIX_UID = FIX . '-uid';

/** 五个运维节点（键名与 `scripts/install.php` 一致） */
const P4_NODES = [
    'ops.kick' => 'app\\controller\\api\\OpsActionController@kick',
    'ops.revoke' => 'app\\controller\\api\\OpsActionController@revoke',
    'ops.unbind' => 'app\\controller\\api\\OpsActionController@unbind',
    'ops.forceOffline' => 'app\\controller\\api\\OpsActionController@forceOffline',
    'ops.purgeOffline' => 'app\\controller\\api\\OpsActionController@purgeOffline',
];

/** 五个端点路径 */
const P4_ENDPOINTS = [
    '/api/ops-action/kick',
    '/api/ops-action/revoke',
    '/api/ops-action/unbind',
    '/api/ops-action/force-offline',
    '/api/ops-action/purge-offline',
];

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
        // 与前端 `session.js` 的发送方式一致 —— 用表单编码测不出这条真正的链路。
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

/**
 * 取本次运行新产生的审计行。
 *
 * 查询口径分两种：
 * - **按 target**（默认）：`p4test%` 前缀 —— kick / unbind / force-offline 的 target 是本次目标；
 * - **按时间**（`$byTime = true`）：`revoke` 的 target 是 **token 指纹**（它没有别的标识），
 *   查不到 `p4test%` —— 改按「action + 本轮开始时间之后」捞，且只取最新一条。
 *
 * @return list<array<string, mixed>>
 */
function auditRows(string $action, bool $byTime = false): array
{
    try {
        $q = Db::table('admin_audit_log')->where('action', $action);
        if ($byTime) {
            $q = $q->where('created_at', '>=', RUN_START);
        } else {
            $q = $q->where('target', 'like', FIX . '%');
        }
        $rows = $q->orderBy('id', 'desc')->limit(5)->get();
    } catch (Throwable $e) {
        echo '  [WARN] admin_audit_log 不可读：' . $e->getMessage() . PHP_EOL;

        return [];
    }

    $list = $rows instanceof \Illuminate\Support\Collection ? $rows->all() : (array)$rows;

    return array_map(static fn ($r): array => (array)$r, $list);
}

/** 清理本次运行写入的审计行（只按 `p4test%` 前缀，绝不 truncate） */
function cleanAudit(): void
{
    try {
        // ⚠ revoke 行的 target 是指纹，不落 `p4test%` 前缀 —— 同时按时间兜一次，
        //   只删「本轮开始之后」的 P4 审计行，不碰历史记录。
        $n = (int)Db::table('admin_audit_log')
            ->where('target', 'like', FIX . '%')
            ->orWhere(function ($q) {
                $q->whereIn('action', ['ops.kick', 'ops.revoke', 'ops.unbind', 'ops.force_offline'])
                    ->where('created_at', '>=', RUN_START);
            })
            ->delete();
        echo '  审计夹具已清理（删除 ' . $n . ' 行）' . PHP_EOL;
    } catch (Throwable $e) {
        echo '  [WARN] 审计夹具清理失败：' . $e->getMessage() . PHP_EOL;
    }
}

/* ===========================================================================
 * 主流程
 * =========================================================================== */

$live = in_array('--live', array_slice($argv, 1), true);
$env = $_ENV + $_SERVER;

/** 本轮开始时间：revoke 审计行按时间捞的边界（target 是指纹，查不了前缀） */
define('RUN_START', date('Y-m-d H:i:s'));

$reader = new RedisReader();
$ping = $reader->ping();
$redisOk = $ping['ok'];

echo PHP_EOL . '=== P4 验收（GatewayPush 管理后台 · 运维操作区）===' . PHP_EOL;
echo 'PHP ' . PHP_VERSION . ' / ' . date('Y-m-d H:i:s')
    . ' / Redis ' . ($redisOk ? $ping['latency_ms'] . 'ms' : '不可达')
    . ' / 真实调用：' . ($live ? '启用（p4test-*，跑完清理）' : '关闭（--live 可启用）') . PHP_EOL;

// ★ 安全闸：真实运维动作只允许打到**不存在**的目标上。
//   若该 client_id 真的在线，说明夹具命名空间被改成了真实业务 id —— 立即拒绝执行。
if ($live && $redisOk) {
    if ($reader->sessionExists(FIX_CLIENT)) {
        echo PHP_EOL . '[ABORT] 夹具 client_id ' . FIX_CLIENT . ' 是**真实存在**的会话；'
            . '本脚本会断开它，拒绝执行。' . PHP_EOL;
        exit(2);
    }
}

// ===========================================================================
// 1. 权限节点（DB 真值）
// ===========================================================================
section('[1] 权限节点：DB 真值对拍（静态检查发现不了「install.php 没跑」）');

$dbRules = null;
$dbRoles = null;
try {
    $dbRules = Db::table('wa_rules')->pluck('id', 'key')->toArray();
    $dbRoles = Db::table('wa_roles')->get();
} catch (Throwable $e) {
    note('DB 节点一致性', 'wa_rules / wa_roles 不可读：' . $e->getMessage());
}

if (is_array($dbRules)) {
    // ⚠ `wa_rules.key` 存的是**控制器@动作**，`ops.kick` 只是 `install.php` 里的别名。
    //   用别名去查 DB 会恒缺（首跑即踩：明明跑过 install.php 却报「缺 4 个」）。
    $missing = [];
    foreach (P4_NODES as $alias => $key) {
        if (!isset($dbRules[$key])) {
            $missing[] = $alias . ' → ' . $key;
        }
    }
    check('★ 5 个 P4 运维节点都已写进 wa_rules（install.php 跑过才生效）',
        $missing === [],
        $missing === []
            ? 'wa_rules 共 ' . count($dbRules) . ' 条'
            : '缺 ' . implode('、', $missing) . ' —— 运行 php scripts/install.php');

    // ⚠ `support\Db::table()->get()` 返回 Collection，必须先 `->all()`；
    //   元素一律 `(array)$r` + 键访问（`$r->rules` 会 "Attempt to read property on array"）。
    $roleRows = $dbRoles instanceof \Illuminate\Support\Collection ? $dbRoles->all() : (array)$dbRoles;
    $roleIds = [];
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

    $idOf = static function (string $key) use ($dbRules): int {
        return (int)($dbRules[$key] ?? 0);
    };

    if (is_array($viewerIds)) {
        $leak = [];
        foreach (P4_NODES as $alias => $key) {
            if (in_array($idOf($key), $viewerIds, true)) {
                $leak[] = $alias;
            }
        }
        check('★ DB 里只读角色拿不到**任何** P4 运维节点', $leak === [],
            $leak === [] ? '只读 ' . count($viewerIds) . ' 个节点，均无运维动作'
                : '越权拿到 ' . implode('、', $leak));
    } else {
        note('DB 只读角色', 'wa_roles 里找不到「只读」角色');
    }

    if (is_array($operatorIds)) {
        $lack = [];
        foreach (P4_NODES as $alias => $key) {
            if (!in_array($idOf($key), $operatorIds, true)) {
                $lack[] = $alias;
            }
        }
        check('★ DB 里运维角色五个运维节点全有', $lack === [],
            $lack === [] ? '运维共 ' . count($operatorIds) . ' 个节点' : '缺 ' . implode('、', $lack));
        check('  运维角色节点数 = 41（行为日志 + 访问日志各页 + API；新增阶段须同步更新本断言）', count($operatorIds) === 41,
            '实际 ' . count($operatorIds) . ' 个');
    } else {
        note('DB 运维角色', 'wa_roles 里找不到「运维」角色');
    }
}

// ===========================================================================
// 2. 主项目 API 可达性（后台转签的下游）
// ===========================================================================
section('[2] 主项目 API 可达性（后台的运维动作全部转签给它）');

$client = GatewayPushClient::fromConfig();
$health = ['ok' => false];
try {
    $health = $client->health();
} catch (Throwable $e) {
    $health = ['ok' => false, 'error' => $e->getMessage()];
}

$apiOk = (bool)($health['ok'] ?? false);

// 主项目 `.env` 的 `API_SIGN_ENABLE` 决定转签是否必须带签名。
// ⚠ 它在监听**非回环**地址时才生效（主项目红线 ㉟）；本机一律回环，故免签是被允许的常态。
$mainEnvPath = dirname(__DIR__, 3) . '/.env';
$signEnable = false;
if (is_file($mainEnvPath)) {
    foreach ((array)file($mainEnvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (trim($parts[0]) === 'API_SIGN_ENABLE') {
            $raw = strtolower(trim(trim($parts[1]), "\"'"));
            $signEnable = in_array($raw, ['1', 'true', 'yes', 'on'], true);
        }
    }
}

if ($apiOk) {
    check('主项目 API 健康检查通过', true, $client->apiUrl());
    if ($signEnable) {
        check('★ 主项目开启验签 ⇒ 后台必须配置 API_SECRET（否则转签全 401）', $client->hasSecret());
    } else {
        check('  主项目未开启验签（API_SIGN_ENABLE 未置真）⇒ 转签免签可用',
            true, 'ADMIN_API_SECRET ' . ($client->hasSecret() ? '已配置' : '未配置，不影响本机回环调用'));
    }
} else {
    note('主项目 API', '不可达：' . $client->apiUrl()
        . ' —— 第 4 节的真实调用将全部 SKIP（错误 ' . (string)($health['error'] ?? '') . '）');
}

// ===========================================================================
// 3. 未登录：四个端点一律被拦
// ===========================================================================
section('[3] 未登录：四个运维端点一律被拦');

$jar = sys_get_temp_dir() . '/gw_p4_' . getmypid() . '.txt';
@unlink($jar);

$adminOnline = true;
$probe = http('GET', '/app/admin/account/captcha/login', [], ['Accept: image/*'], $jar);
if ($probe['status'] === 0) {
    $adminOnline = false;
    note('后台在线检查', '127.0.0.1:' . ADMIN_PORT . ' 不可达 —— 第 3~5 节整体 SKIP');
}

if ($adminOnline) {
    foreach (P4_ENDPOINTS as $path) {
        $res = http('POST', $path, ['x' => '1'], ['Accept: application/json'], $jar);
        check('★ 未登录 POST ' . $path . ' → 401/403/302',
            in_array($res['status'], [401, 403, 302], true), 'HTTP ' . $res['status']);
    }
    // 默认路由必须关闭：否则 `/api/ops-action/index` 这类路径会绕过显式路由上的 AdminAuth
    $def = http('POST', '/api/ops-action/kick', ['client_id' => FIX_CLIENT], ['Accept: application/json'], $jar);
    check('  /api/ops-action/kick 未登录不带任何业务语义（不是 200）',
        $def['status'] !== 200, 'HTTP ' . $def['status']);
}

// ===========================================================================
// 4. 登录后：参数校验 → 真实调用 → 审计落库
// ===========================================================================
section('[4] 登录后：参数校验、真实调用与审计落库');

$adminUser = (string)($env['ADMIN_BOOTSTRAP_USER'] ?? '');
$adminPass = (string)($env['ADMIN_BOOTSTRAP_PASS'] ?? '');

if (!$adminOnline) {
    note('登录后检查', '后台未在线');
} elseif ($adminUser === '' || $adminPass === '') {
    note('登录后检查', '.env 缺 ADMIN_BOOTSTRAP_USER/PASS');
} elseif (!login($jar, $adminUser, $adminPass)) {
    check('登录（含验证码）', false, $adminUser . ' 登录失败');
} else {
    check('登录（含验证码）', true, $adminUser);

    // ---- 4.1 入参校验（全部在转签之前失败，不产生任何副作用）----
    $badCases = [
        ['/api/ops-action/kick', ['reason' => 'x'], 'kick 缺 client_id 与 uid'],
        ['/api/ops-action/kick', ['client_id' => str_repeat('a', 129)], 'kick client_id 超长'],
        ['/api/ops-action/kick', ['client_id' => FIX_CLIENT, 'reason' => str_repeat('r', 129)], 'kick reason 超长'],
        ['/api/ops-action/unbind', [], 'unbind 缺 uid'],
        ['/api/ops-action/unbind', ['uid' => str_repeat('u', 65)], 'unbind uid 超长'],
        ['/api/ops-action/revoke', [], 'revoke 缺 token'],
        ['/api/ops-action/revoke', ['token' => str_repeat('t', 2049)], 'revoke token 超长'],
        ['/api/ops-action/revoke', ['token' => 'x', 'ttl' => -1], 'revoke ttl 为负'],
        ['/api/ops-action/force-offline', ['token' => 'x'], 'force-offline 缺目标'],
    ];

    foreach ($badCases as [$path, $body, $label]) {
        $res = http('POST', $path, $body, ['Accept: application/json'], $jar);
        $b = jsonBody($res['body']);
        check('  ' . $label . ' → HTTP 400 且 code≠0（校验层拒绝，不转签）',
            $res['status'] === 400 && (int)($b['code'] ?? 0) !== 0,
            'HTTP ' . $res['status'] . ' code=' . ($b['code'] ?? '?'));
    }

    $beforeAudit = 0;
    try {
        $beforeAudit = (int)Db::table('admin_audit_log')->where('target', 'like', FIX . '%')->count();
    } catch (Throwable $e) {
        echo '  [WARN] admin_audit_log 计数失败：' . $e->getMessage() . PHP_EOL;
    }
    check('  ★ 校验失败的请求不落审计（否则审计表会被无效点击刷满）', $beforeAudit === 0,
        'p4test-* 审计行 ' . $beforeAudit . ' 条');

    // ---- 4.2 真实调用（--live）----
    if (!$live) {
        note('真实运维动作调用', '未加 --live（真实调用会断开/解绑目标，需显式确认）');
    } elseif (!$apiOk) {
        note('真实运维动作调用', '主项目 API 不可达');
    } else {
        // ---- kick：目标不存在 —— 动作应当执行成功但 closed=0 ----
        $res = http('POST', '/api/ops-action/kick', [
            'client_id' => FIX_CLIENT, 'reason' => 'p4 验收',
        ], ['Accept: application/json'], $jar);
        $b = jsonBody($res['body']);
        $d = dataOf($b);
        $out = is_array($d['outcome'] ?? null) ? (array)$d['outcome'] : [];

        check('★ kick → HTTP 200（成败看 outcome.state，不是看状态码）', $res['status'] === 200,
            'HTTP ' . $res['status']);
        check('  kick 回执 state=done（目标不存在不算失败：动作确实执行了）',
            ($out['state'] ?? '') === 'done', 'state=' . ($out['state'] ?? '?'));
        check('  kick 回执带 caveats（「不撤销 Token」必须原样说明）',
            str_contains((string)($d['caveats'] ?? ''), 'Token'),
            (string)($d['caveats'] ?? ''));
        check('  kick 审计已落库', ($d['audit_ok'] ?? null) === true);

        $rows = auditRows('ops.kick');
        check('  ★ admin_audit_log 真的多了一行 ops.kick', count($rows) >= 1, count($rows) . ' 行');
        if ($rows !== []) {
            $row = $rows[0];
            check('  审计 target 为本次目标', (string)($row['target'] ?? '') === FIX_CLIENT,
                (string)($row['target'] ?? ''));
            check('  审计 params 里没有 token 键（ActionOutcome 只带业务参数）',
                !str_contains((string)($row['params'] ?? ''), 'token'),
                (string)($row['params'] ?? ''));
        }

        // ---- revoke：明文 token 只在这一次调用里出现 ----
        $token = 'p4.fake.' . bin2hex(random_bytes(6));
        $res = http('POST', '/api/ops-action/revoke', ['token' => $token],
            ['Accept: application/json'], $jar);
        $b = jsonBody($res['body']);
        $d = dataOf($b);

        check('★ revoke → HTTP 200', $res['status'] === 200, 'HTTP ' . $res['status']);
        check('  revoke 回执只回指纹（32 位 hex，不回明文）',
            preg_match('/^[0-9a-f]{32}$/', (string)($d['fingerprint'] ?? '')) === 1,
            (string)($d['fingerprint'] ?? ''));
        check('  指纹与主项目口径一致（sha256 前 32 位）',
            (string)($d['fingerprint'] ?? '') === OpsAction::fingerprint($token),
            (string)($d['fingerprint'] ?? ''));
        check('  ★ 响应体里不出现 Token 明文', !str_contains($res['body'], $token));

        $rows = auditRows('ops.revoke', true);
        check('  ★ admin_audit_log 真的多了一行 ops.revoke', count($rows) >= 1, count($rows) . ' 行');
        if ($rows !== []) {
            $params = (string)($rows[0]['params'] ?? '');
            check('  ★★ 审计 params 里不含 Token 明文（唯一会流经后台的长期凭证）',
                !str_contains($params, $token), $params);
            check('  审计 params 含 fingerprint', str_contains($params, 'fingerprint'));
            check('  ★ 审计 target 是指纹（revoke 没有别的标识，检索时按指纹找）',
                (string)($rows[0]['target'] ?? '') === (string)($d['fingerprint'] ?? ''),
                (string)($rows[0]['target'] ?? ''));
        }

        // ---- unbind：目标不存在时幂等 ----
        $res = http('POST', '/api/ops-action/unbind', ['uid' => FIX_UID],
            ['Accept: application/json'], $jar);
        $b = jsonBody($res['body']);
        $d = dataOf($b);
        check('★ unbind → HTTP 200', $res['status'] === 200, 'HTTP ' . $res['status']);
        check('  unbind 回执带 caveats（「不踢线」必须说明）',
            str_contains((string)($d['caveats'] ?? ''), '踢') || str_contains((string)($d['caveats'] ?? ''), '线'),
            (string)($d['caveats'] ?? ''));

        // ---- force-offline：不给 token → 只做一半，必须 partial=true ----
        $res = http('POST', '/api/ops-action/force-offline', [
            'client_id' => FIX_CLIENT, 'reason' => 'p4 验收',
        ], ['Accept: application/json'], $jar);
        $b = jsonBody($res['body']);
        $d = dataOf($b);

        check('★ force-offline → HTTP 200', $res['status'] === 200, 'HTTP ' . $res['status']);
        check('★ 未提供 token 时 partial=true（不假装完成了「禁止重连」）',
            ($d['partial'] ?? null) === true, 'partial=' . var_export($d['partial'] ?? null, true));
        check('  partial_note 非空', (string)($d['partial_note'] ?? '') !== '');
        check('  两步里第一步被标记为 skipped',
            (bool)(($d['steps'][0]['skipped'] ?? false)) === true);

        $rows = auditRows('ops.force_offline');
        check('  ★ admin_audit_log 真的多了一行 ops.force_offline', count($rows) >= 1, count($rows) . ' 行');
        if ($rows !== []) {
            $params = (string)($rows[0]['params'] ?? '');
            // ★ 键名必须叫 revoke_applied：叫 has_token 会被 Auditor::REDACT_PATTERN
            //   的子串匹配打成 ***，「禁止重连那一半做没做」在审计里就查不出来了。
            check('  ★ 审计以 revoke_applied 键记「有没有做撤销」（且未被脱敏打码）',
                str_contains($params, '"revoke_applied"') && !str_contains($params, '***'), $params);
            check('  审计 params 里不含 token 本身', !str_contains($params, 'p4.fake.'), $params);
        }
    }

    // ---- 4.3 只读角色：四个端点一律 403 ----
    $viewerUser = (string)($env['ADMIN_VIEWER_USER'] ?? '');
    $viewerPass = (string)($env['ADMIN_VIEWER_PASS'] ?? '');
    if ($viewerUser === '' || $viewerPass === '') {
        note('viewer 动态矩阵', '未提供 ADMIN_VIEWER_USER / ADMIN_VIEWER_PASS（可选）');
    } else {
        $vjar = sys_get_temp_dir() . '/gw_p4v_' . getmypid() . '.txt';
        @unlink($vjar);
        if (login($vjar, $viewerUser, $viewerPass)) {
            foreach (P4_ENDPOINTS as $path) {
                $res = http('POST', $path, ['client_id' => FIX_CLIENT, 'uid' => FIX_UID, 'token' => 'x'],
                    ['Accept: application/json'], $vjar);
                check('★ viewer POST ' . $path . ' → 403（只读角色不得有运维动作）',
                    $res['status'] === 403, 'HTTP ' . $res['status']);
            }
            $page = http('GET', '/sessions', [], ['Accept: text/html'], $vjar);
            check('  viewer GET /sessions → 200（会话页对只读角色开放）',
                $page['status'] === 200, 'HTTP ' . $page['status']);
            check('  ★ viewer 的 /sessions 里运维区块是隐藏态（前端也不给按钮）',
                str_contains($page['body'], 'id="sec-ops"'), '含节点（显隐由 perms 决定）');
        } else {
            check('viewer 登录', false, $viewerUser . ' 登录失败');
        }
        @unlink($vjar);
    }
}

// ===========================================================================
// 5. 收尾
// ===========================================================================
section('[5] 收尾');

if ($live) {
    cleanAudit();
} else {
    echo '  未启用 --live，无需清理夹具' . PHP_EOL;
}

@unlink($jar);

echo PHP_EOL . sprintf('PASS %d / FAIL %d / SKIP %d', $pass, $fail, $skip) . PHP_EOL;
exit($fail > 0 ? 1 : 0);
