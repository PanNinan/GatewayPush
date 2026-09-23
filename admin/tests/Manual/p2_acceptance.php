<?php

/**
 * P2 验收脚本（手工执行，**不在** PHPUnit 套件内：它依赖后台服务与主项目在线）。
 *
 * 用法：
 *     cd admin && php tests/Manual/p2_acceptance.php            # 只读模式：不写任何 Redis 键
 *     cd admin && php tests/Manual/p2_acceptance.php --seed     # 额外建立**一次性夹具**后清理
 *
 * P2 的交付面是「会话只读面板（列表 / 详情 / 反查 / 订阅 / 撤销名单）」，验收分四层：
 *
 *   1. **服务层**（依赖 Redis）：`SessionInspector` 对**真实** Redis 数据能正确判定
 *      `online` / `retained` / `gone`，并给出有界 SCAN 的截断标记。
 *      `--seed` 会写入 3 个一次性夹具 clientId（在线 / 保留 / 从未建连）+ 1 个 uid 的
 *      离线队列 / 订阅 / 撤销指纹，跑完**无条件清理**。夹具是最能钉住语义的一层：
 *      尤其是「会话 Hash 里带着 `offline_at` 但在 `online:clients` 内」这个**真实反例**，
 *      只有写进真 Redis 才能验证「`offline_at` 是粘性字段、不能作在线判据」。
 *
 *   2. **默认路由回归**（HTTP 层）：`/sessions/index`、`/api/sessions/index` 必须**不可达** ——
 *      这是 2026-09-23 修掉的那类鉴权洞（默认路径与显式路径不同时，显式路由上的
 *      `AdminAuth` 压根不参与匹配）。
 *      ⚠ 断言按**集合**判定（`404` / `401` / `200+code=404`），不是单值 `404`：
 *      被禁用的默认路由会落到 webman-admin 的异常处理器，JSON 请求下表现为
 *      `HTTP 200 + {"code":404}`；而**别名**路径（如 `/api/session/index`）会被相邻的
 *      `/api/session/{clientId}` 先匹配，由 `AdminAuth` 给出 401。三者在安全语义上等价。
 *   3. **HTTP 层**（依赖后台在线）：静态资源、未登录 302/401/403、登录后页面骨架与
 *      7 个只读端点的结构，以及「写方法一律不可用」。
 *   4. **RBAC 与节点一致性**：`wa_rules` 里 7 个 `sess.*` 节点 + 页面菜单节点都在，
 *      且每个 `{控制器}@{action}` 都能在对应控制器里找到真实方法
 *      （节点写了但动作不存在 = 点了菜单才 500，属最难查的一类）。
 *
 * 若后台未在线，第 2~4 层整体标 SKIP（不影响退出码）；`.env` 缺
 * `ADMIN_VIEWER_USER/PASS` 时 viewer 动态矩阵同样标 SKIP。
 *
 * 退出码：0 = 全绿（SKIP 不算失败）；1 = 有 FAIL。
 *
 * ⚠ 本脚本是后台子项目里**唯一**会写 Redis 的地方，且必须：
 *   - 仅在显式 `--seed` 时写；
 *   - 只用 `p2test` 前缀的夹具键；
 *   - 在 `finally` 里无条件清理（含 `online:clients` 的成员摘除）。
 * 生产代码侧的只读边界由 `tests/Unit/SessionContractTest::testReadPathContainsNoRedisWriteCommands` 守住。
 */

use app\service\RedisReader;
use app\service\SessionInspector;
use GatewayPush\Common\RedisKeys;
use support\Redis;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../support/bootstrap.php';

const ADMIN_HOST = '127.0.0.1';
const ADMIN_PORT = 8292;
const SESSION_DIR = __DIR__ . '/../../runtime/sessions';

/** 夹具命名空间：全部键与成员都带这个前缀，便于确认「清理干净」 */
const FIX = 'p2test';
const FIX_ONLINE = FIX . '-online';
const FIX_RETAINED = FIX . '-retained';
const FIX_GONE = FIX . '-gone';
const FIX_UID = FIX . '-uid';
const FIX_DEVICE = FIX . '-dev';
const FIX_TOPIC = FIX . '-topic';
const FIX_FINGERPRINT = 'p2testfingerprint00000000000000';

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
 * @param array<string, string> $post
 * @param list<string>          $headers
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
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 3,
    ];

    if ($post !== []) {
        $options[CURLOPT_POSTFIELDS] = http_build_query($post);
        $options[CURLOPT_HTTPHEADER] = array_merge($headers, ['Content-Type: application/x-www-form-urlencoded']);
    }

    if ($jar !== null) {
        $options[CURLOPT_COOKIEJAR] = $jar;
        $options[CURLOPT_COOKIEFILE] = $jar;
    }

    curl_setopt_array($ch, $options);

    $raw = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headerBlock = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    $parsed = [];
    foreach (explode("\r\n", $headerBlock) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $parsed[strtolower(trim($k))] = trim($v);
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

/**
 * 从 session 文件里读出验证码（webman session 为 file 驱动 + PHP serialize 格式）。
 *
 * 登录**强制校验验证码**，所以自动化验收必须走「先 GET captcha 建立 session →
 * 读明文 → 再 POST 登录」这条路。
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
 * 建立夹具（**只在 `--seed` 时调用**）。
 *
 * 三个 clientId 刻意覆盖三种状态，且第 1 个带 `offline_at`：
 *   - `p2test-online`   ：在 `online:clients` 内 + **带着 offline_at** ← 粘性字段反例；
 *                          同时进 `uid:clients`（`markOffline()` 不清理该索引，故这种情况真实存在）
 *   - `p2test-retained` ：只有 session Hash，不在 `online:clients`（= 断开但保留）
 *   - `p2test-gone`     ：什么都不写（= 已回收 / 从未建连）
 */
function seedFixture(): void
{
    $now = time();

    Redis::hMSet(RedisKeys::session(FIX_ONLINE), [
        'client_id' => FIX_ONLINE,
        'uid' => FIX_UID,
        'device_id' => FIX_DEVICE,
        'protocol' => 'ws',
        'client_ip' => '127.0.0.1',
        'client_port' => '59999',
        'gateway' => 'gateway:8299',
        'connect_at' => (string)($now - 600),
        'last_active' => (string)($now - 5),
        // ★ 关键：断过一次又重连的会话会长期带着它（主项目 bind() 不清理该字段）
        'offline_at' => (string)($now - 300),
    ]);

    Redis::hMSet(RedisKeys::session(FIX_RETAINED), [
        'client_id' => FIX_RETAINED,
        'uid' => FIX_UID,
        'device_id' => FIX_DEVICE,
        'protocol' => 'udp',
        'client_ip' => '127.0.0.1',
        'client_port' => '59998',
        'gateway' => 'gateway:8298',
        'connect_at' => (string)($now - 900),
        'last_active' => (string)($now - 800),
        'offline_at' => (string)($now - 700),
    ]);

    // 在线索引：只有 online 那个进集合（集合成员资格是权威在线判据）
    Redis::sAdd(RedisKeys::ONLINE_CLIENTS, FIX_ONLINE);
    Redis::sAdd(RedisKeys::online('ws'), FIX_ONLINE);
    Redis::set(RedisKeys::heartbeat(FIX_ONLINE), (string)$now);

    // uid 索引：刻意**两个都加** —— 复现「markOffline() 不清理 uid:clients」的真实形态，
    // 从而验证「按 uid 反查能看到保留会话」这条设计结论。
    Redis::sAdd(RedisKeys::uidClients(FIX_UID), FIX_ONLINE, FIX_RETAINED);
    Redis::set(RedisKeys::deviceClient(FIX_DEVICE), FIX_ONLINE);

    // 离线队列（3 条）+ 订阅双向索引 + 撤销指纹
    Redis::del(RedisKeys::pushOffline(FIX_UID));
    Redis::rPush(RedisKeys::pushOffline(FIX_UID), 'p2test-msg-1', 'p2test-msg-2', 'p2test-msg-3');

    Redis::del(RedisKeys::subscribeUid(FIX_UID));
    Redis::sAdd(RedisKeys::subscribeUid(FIX_UID), FIX_TOPIC);
    Redis::del(RedisKeys::subscribeTopic(FIX_TOPIC));
    Redis::sAdd(RedisKeys::subscribeTopic(FIX_TOPIC), FIX_UID);

    Redis::set(RedisKeys::authRevoked(FIX_FINGERPRINT), '1', 'EX', 600);
}

/** 清理夹具：**无条件执行**，且覆盖所有被写过的键与集合成员 */
function unseedFixture(): void
{
    $keys = [
        RedisKeys::session(FIX_ONLINE),
        RedisKeys::session(FIX_RETAINED),
        RedisKeys::heartbeat(FIX_ONLINE),
        RedisKeys::uidClients(FIX_UID),
        RedisKeys::deviceClient(FIX_DEVICE),
        RedisKeys::pushOffline(FIX_UID),
        RedisKeys::subscribeUid(FIX_UID),
        RedisKeys::subscribeTopic(FIX_TOPIC),
        RedisKeys::authRevoked(FIX_FINGERPRINT),
    ];

    foreach ($keys as $key) {
        Redis::del($key);
    }

    // 共享集合：只摘掉自己加的成员，绝不动其他成员
    Redis::sRem(RedisKeys::ONLINE_CLIENTS, FIX_ONLINE, FIX_RETAINED);
    Redis::sRem(RedisKeys::online('ws'), FIX_ONLINE);
    Redis::sRem(RedisKeys::online('udp'), FIX_RETAINED);
}

/* ===========================================================================
 * 主流程
 * =========================================================================== */

$seed = in_array('--seed', array_slice($argv, 1), true);

// ★ 安全闸：夹具只允许打在**回环** Redis 上。
//   本脚本是后台子项目里唯一会写 Redis 的地方，一旦被人拿去对着生产跑
//   `--seed`，就会往生产的 `online:clients` 里临时塞一个成员（虽然会清理，
//   但清理逻辑本身也可能因断言失败中断）。宁可拒绝执行，也不要冒这个险。
$redisHost = (string)(getenv('ADMIN_REDIS_HOST') ?: '127.0.0.1');
$isLoopback = in_array($redisHost, ['127.0.0.1', 'localhost', '::1'], true);
if ($seed && !$isLoopback) {
    echo PHP_EOL . '[ABORT] --seed 只允许对回环 Redis 执行；当前 ADMIN_REDIS_HOST=' . $redisHost . PHP_EOL;
    echo '        若确需对非回环实例建立夹具，请手工设置 ADMIN_REDIS_HOST=127.0.0.1 后重试。' . PHP_EOL;
    exit(2);
}

echo PHP_EOL . '=== P2 验收（GatewayPush 管理后台 · 会话只读面板）===' . PHP_EOL;
echo 'PHP ' . PHP_VERSION . ' / ' . date('Y-m-d H:i:s')
    . ' / Redis ' . $redisHost
    . ' / 夹具：' . ($seed ? '启用（p2test-*，跑完清理）' : '关闭（--seed 可启用）') . PHP_EOL;

// ===========================================================================
// 1. 服务层
// ===========================================================================
section('[1] 服务层：SessionInspector 对真实 Redis');

$reader = new RedisReader();
$ping = $reader->ping();

check('Redis 连通', $ping['ok'], $ping['ok'] ? $ping['latency_ms'] . 'ms' : $ping['msg']);
check('Redis 库号与主项目一致（ADMIN_REDIS_DB）',
    RedisReader::dbIndex() === (int)(getenv('ADMIN_REDIS_DB') ?: 0),
    'db=' . RedisReader::dbIndex() . ' prefix=' . RedisReader::prefix());

$inspector = new SessionInspector();

if (!$ping['ok']) {
    note('服务层后续检查', 'Redis 不可用');
} elseif (!$seed) {
    note('夹具相关检查（在线 / 保留 / 已回收三态、按 uid 反查、离线队列、撤销指纹）',
        '未启用 --seed；如需完整语义验收请加 --seed');
} else {
    try {
        seedFixture();

        $now = time();

        // ---- 在线判定：★ 带 offline_at 但在集合内 → 必须判 online ----
        $online = $inspector->detail(FIX_ONLINE, [], $now);
        check('★ 夹具写入成功（会话 Hash 带 offline_at 且在 online:clients 内）',
            $online['found'] === true, 'found=' . var_export($online['found'], true));
        check('★ 带 offline_at 的在线会话被判为 online（offline_at 是粘性字段，不可作在线判据）',
            $online['state'] === SessionInspector::STATE_ONLINE,
            'state=' . $online['state']);
        check('offline_at 仍如实展示为「最后断开」',
            $online['offline_secs'] !== null && $online['offline_secs'] >= 290,
            'offline_secs=' . var_export($online['offline_secs'], true));
        check('心跳独立键可读', $online['heartbeat'] !== null && $online['heartbeat_age_secs'] <= 5,
            'heartbeat_age=' . var_export($online['heartbeat_age_secs'], true));

        // ---- 保留 vs 已回收 ----
        $retained = $inspector->detail(FIX_RETAINED, [], $now);
        check('不在集合内但键存在 → retained',
            $retained['found'] === true && $retained['state'] === SessionInspector::STATE_RETAINED,
            'state=' . $retained['state']);

        $gone = $inspector->detail(FIX_GONE, [], $now);
        check('★ 键不存在 → found=false 且 state=gone（与 retained 刻意区分）',
            $gone['found'] === false && $gone['state'] === SessionInspector::STATE_GONE,
            'found=' . var_export($gone['found'], true) . ' state=' . $gone['state']);

        // ---- 列表三态 ----
        $onlineList = $inspector->list(['scope' => 'online'], $now);
        $onlineIds = array_column($onlineList['items'], 'client_id');
        check('scope=online 含在线夹具、不含保留夹具',
            in_array(FIX_ONLINE, $onlineIds, true) && !in_array(FIX_RETAINED, $onlineIds, true),
            'online_total=' . $onlineList['online_total'] . ' items=' . implode(',', $onlineIds));
        check('★ scope=online 不 SCAN（scan 为 null）', $onlineList['scan'] === null);

        $retainedList = $inspector->list(['scope' => 'retained'], $now);
        $retainedIds = array_column($retainedList['items'], 'client_id');
        check('scope=retained 含保留夹具、不含在线夹具',
            in_array(FIX_RETAINED, $retainedIds, true) && !in_array(FIX_ONLINE, $retainedIds, true),
            'items=' . implode(',', $retainedIds));
        check('★ scope=retained 走 SCAN 且回带 scanned/truncated',
            is_array($retainedList['scan'])
            && isset($retainedList['scan']['scanned'], $retainedList['scan']['truncated']),
            'scanned=' . ($retainedList['scan']['scanned'] ?? '?')
            . ' truncated=' . var_export($retainedList['scan']['truncated'] ?? null, true));

        $allList = $inspector->list(['scope' => 'all', 'size' => 100], $now);
        $allIds = array_column($allList['items'], 'client_id');
        check('scope=all 同时含在线与保留',
            in_array(FIX_ONLINE, $allIds, true) && in_array(FIX_RETAINED, $allIds, true));
        check('列表按「在线优先」排序（首条为在线夹具）',
            ($allList['items'][0]['client_id'] ?? '') === FIX_ONLINE,
            'first=' . ($allList['items'][0]['client_id'] ?? '?'));

        // ---- uid 过滤 / 分页 ----
        $byUidList = $inspector->list(['scope' => 'all', 'uid' => FIX_UID], $now);
        check('scope=all + uid 过滤只返回该 uid',
            array_column($byUidList['items'], 'uid') === [FIX_UID, FIX_UID]
            || array_unique(array_column($byUidList['items'], 'uid')) === [FIX_UID],
            'total=' . $byUidList['total']);

        $paged = $inspector->list(['scope' => 'all', 'page' => 1, 'size' => 1], $now);
        check('分页 size=1 只返回 1 条但 total 仍是全量',
            count($paged['items']) === 1 && $paged['total'] >= 2,
            'items=' . count($paged['items']) . ' total=' . $paged['total'] . ' pages=' . $paged['pages']);

        $oversize = $inspector->list(['scope' => 'online', 'size' => 99999], $now);
        check('★ size 超限被夹到 SIZE_MAX',
            $oversize['size'] === SessionInspector::SIZE_MAX,
            'size=' . $oversize['size']);

        // ---- 反查 ----
        $byUid = $inspector->findByUid(FIX_UID, $now);
        $byUidIds = array_column($byUid['items'], 'client_id');
        check('★ 按 uid 反查能看到「保留」会话（uid:clients 不随断开清理）',
            in_array(FIX_ONLINE, $byUidIds, true) && in_array(FIX_RETAINED, $byUidIds, true),
            'items=' . implode(',', $byUidIds));

        $byDevice = $inspector->findByDevice(FIX_DEVICE, $now);
        check('按设备反查命中当前 clientId',
            array_column($byDevice['items'], 'client_id') === [FIX_ONLINE],
            'items=' . implode(',', array_column($byDevice['items'], 'client_id')));

        $byDeviceMiss = $inspector->findByDevice(FIX . '-no-such-device', $now);
        check('按不存在的设备反查返回空 + 可读提示',
            $byDeviceMiss['total'] === 0 && $byDeviceMiss['hint'] !== '');

        // ---- 离线队列 ----
        $queue = $inspector->offlineQueue(FIX_UID, 1, 2);
        check('离线队列 len 正确', $queue['len'] === 3, 'len=' . $queue['len']);
        check('离线队列首页切片正确（size=2 → 2 条）',
            count($queue['items']) === 2 && $queue['items'][0] === 'p2test-msg-1',
            implode(',', $queue['items']));
        check('离线队列分页数正确（3 条 / 每页 2 → 2 页）', $queue['pages'] === 2, 'pages=' . $queue['pages']);
        $queue2 = $inspector->offlineQueue(FIX_UID, 2, 2);
        check('离线队列第 2 页取到剩余 1 条',
            count($queue2['items']) === 1 && $queue2['items'][0] === 'p2test-msg-3');

        // ---- 订阅 ----
        $subs = $inspector->subscriptions(FIX_UID, FIX_TOPIC);
        check('订阅双向都对上',
            $subs['topics'] === [FIX_TOPIC] && $subs['subscribers'] === [FIX_UID],
            'topics=' . implode(',', $subs['topics']) . ' subscribers=' . implode(',', $subs['subscribers']));

        // ---- 撤销名单 ----
        $revoked = $inspector->revoked();
        $fingerprints = array_column($revoked['items'], 'fingerprint');
        check('撤销名单含刚写入的指纹', in_array(FIX_FINGERPRINT, $fingerprints, true),
            '共 ' . count($revoked['items']) . ' 条');
        check('撤销名单带 scanned / truncated（SCAN 有界）',
            $revoked['scanned'] > 0 && is_bool($revoked['truncated']),
            'scanned=' . $revoked['scanned'] . ' truncated=' . var_export($revoked['truncated'], true));

        $hit = null;
        foreach ($revoked['items'] as $item) {
            if ($item['fingerprint'] === FIX_FINGERPRINT) {
                $hit = $item;
            }
        }
        check('撤销条目的 TTL 被读出（限期 → permanent=false）',
            is_array($hit) && $hit['permanent'] === false && $hit['ttl'] > 0,
            'ttl=' . var_export($hit['ttl'] ?? null, true));

        // ---- 入参校验 ----
        check('超长 uid 被 validId 拒绝', SessionInspector::validId(str_repeat('x', 129)) === false);
        check('含控制字符的 id 被拒绝', SessionInspector::validId("a\nb") === false);

        // ---- 空态区分（清掉夹具后必然为空，此处只验 hint 字段形态）----
        check('未命中查询带可读 hint（空态 ≠ 静默留白）',
            $byDeviceMiss['hint'] !== '' && $byDeviceMiss['skeleton_ok'] === true);
    } finally {
        unseedFixture();
        echo '  夹具已清理（p2test-* 全部删除，online:clients 成员已摘除）' . PHP_EOL;

        $leftover = 0;
        foreach ([FIX_ONLINE, FIX_RETAINED] as $clientId) {
            if ($reader->sessionExists($clientId)) {
                $leftover++;
            }
        }
        check('★ 夹具清理干净（会话键不再存在）', $leftover === 0, '残留 ' . $leftover . ' 个');
        check('★ 夹具清理后不在在线集合内',
            !in_array(FIX_ONLINE, $reader->onlineClientIds(), true));
    }
}

// ===========================================================================
// 2. 静态一致性：权限节点 ↔ 控制器动作
// ===========================================================================
section('[2] 节点一致性：install.php 的 wa_rules.key ↔ 控制器真实动作');

$installSrc = (string)@file_get_contents(__DIR__ . '/../../scripts/install.php');
$apiSrc = (string)@file_get_contents(__DIR__ . '/../../app/controller/api/SessionController.php');
$pageSrc = (string)@file_get_contents(__DIR__ . '/../../app/controller/SessionController.php');

$nodes = [];
if (preg_match_all("/'(sess\\.[a-zA-Z]+|auth\\.revoked)'\\s*=>\\s*\\['title'[^]]*'key'\\s*=>\\s*'([^']+)'/", $installSrc, $nm)) {
    foreach ($nm[2] as $i => $key) {
        $nodes[$nm[1][$i]] = str_replace('\\\\', '\\', $key);
    }
}
check('install.php 里解析出 7 个 sess/auth 节点', count($nodes) === 7, '实际 ' . count($nodes));

$badNodes = [];
foreach ($nodes as $alias => $key) {
    if (!preg_match('/^(.*)@([a-zA-Z]+)$/', $key, $m)) {
        $badNodes[] = $alias . '（key 形态异常）';
        continue;
    }
    // 动作必须在对应控制器的源码里真实存在
    $src = str_contains($m[1], '\\api\\') ? $apiSrc : $pageSrc;
    if (!preg_match('/function\s+' . preg_quote($m[2], '/') . '\s*\(/', $src)) {
        $badNodes[] = $alias . ' → ' . $key;
    }
}
check('★ 每个节点的 {控制器}@{action} 都能在控制器里找到真实方法',
    $badNodes === [], implode('、', $badNodes));

check('★ 页`/sessions` 的菜单节点已登记（key = app\\controller\\SessionController）',
    str_contains($installSrc, "'app\\\\controller\\\\SessionController'"),
    '漏登记的症状是「登录后点菜单 403」，不是白屏');
check('★ 只读角色包含 7 个会话节点',
    substr_count($installSrc, "\$nodeIds['sess.") >= 6
    && str_contains($installSrc, "\$nodeIds['auth.revoked']"));

// 路由：唯一写入口径的静态守卫（写操作属后续阶段）
$routeSrc = (string)@file_get_contents(__DIR__ . '/../../config/route.php');
check('★ /sessions 页面路由已挂 AdminAuth',
    (bool)preg_match(
        "#Route::get\('/sessions',\s*\[SessionController::class,\s*'index'\]\)\s*->middleware\(\[AdminAuth::class\]\)#",
        $routeSrc
    ));
check('★ 两个 Session 控制器都已 disableDefaultRoute',
    str_contains($routeSrc, 'Route::disableDefaultRoute(SessionController::class);')
    && str_contains($routeSrc, 'Route::disableDefaultRoute(SessionApiController::class);'));
check('★ 会话相关路由全部是 GET（本页一期只读）',
    !preg_match("#Route::(post|put|delete|patch)\(\s*'/sessions?#", $routeSrc));

// 脚手架欢迎页控制器：2026-09-23 发现三个零鉴权默认路径 → 先 disableDefaultRoute 关闭；
// 2026-09-24 已**彻底删除**控制器文件与 app/view/index/ 视图目录。
// 全量覆盖（app/controller/** 逐个比对）由 tests/Unit/RouteGuardTest.php 守，此处只做点名确认。
check('★ 脚手架欢迎页控制器已彻底移除（类文件不存在且 route.php 无代码引用）',
    !is_file(__DIR__ . '/../../app/controller/IndexController.php')
    && !preg_match('/^(use\s+app\\\\controller\\\\IndexController;|Route::disableDefaultRoute\(IndexController::class\);)/m', $routeSrc));
check('★ 未全局禁用默认路由（全禁会让 webman-admin 的 /app/admin/* 整片 404）',
    !preg_match('/Route::disableDefaultRoute\(\s*\)\s*;/', $routeSrc)
    && !preg_match("/Route::disableDefaultRoute\(\s*''\s*\)\s*;/", $routeSrc));

// ===========================================================================
// 3. HTTP 层
// ===========================================================================
section('[3] HTTP 层（依赖后台 8292 在线）');

$up = http('GET', '/static/session.js');

if ($up['status'] !== 200 || strlen($up['body']) < 1000) {
    note('HTTP 层检查', '后台未在线或静态资源不可达（HTTP ' . $up['status'] . '）');
} else {
    check('GET /static/session.js → 200', strlen($up['body']) > 1000, strlen($up['body']) . ' bytes');
    $css = http('GET', '/static/session.css');
    check('GET /static/session.css → 200', $css['status'] === 200 && strlen($css['body']) > 500,
        'HTTP ' . $css['status'] . ' ' . strlen($css['body']) . ' bytes');
    check('JS 里没有 innerHTML（XSS 纪律）', !preg_match('/\.innerHTML\s*=/', $up['body']));
    check('★ JS 里没有任何定时器（按需取数，不轮询）',
        !preg_match('/\b(setTimeout|setInterval)\s*\(/', $up['body']));

    $jar = sys_get_temp_dir() . '/gw_p2_' . getmypid() . '.txt';
    @unlink($jar);

    // ---- 未登录 ----
    $page = http('GET', '/sessions', [], ['Accept: text/html'], $jar);
    check('未登录 GET /sessions → 302 到登录页', $page['status'] === 302,
        'HTTP ' . $page['status'] . ' Location=' . ($page['headers']['location'] ?? '-'));

    $api = http('GET', '/api/sessions', [], ['Accept: application/json'], $jar);
    check('未登录 GET /api/sessions → 401/403', in_array($api['status'], [401, 403], true),
        'HTTP ' . $api['status']);

    // ---- ★ 默认路由回归（2026-09-23 修的鉴权洞）----
    //
    // ⚠ 口径说明（实测，**不要**改回 `status === 404`）：被 `Route::disableDefaultRoute()`
    //   禁掉的默认路由不会走 webman 的默认 404 页，而是落到 **webman-admin 的异常处理器**
    //   （`App::getFallback()`，见 `vendor/workerman/webman-framework/src/App.php:160-200`），
    //   于是同一个「未鉴权拿不到数据」的结论有**三种合法表现**：
    //     a) 裸 404                    —— HTML 请求走默认错误页；
    //     b) HTTP 200 + body code=404  —— JSON 请求走 admin 的 JSON 错误封装（最常见）；
    //     c) 401                       —— **别名**路径被相邻的显式路由先匹配，例如
    //                                     `/api/session/index` 会命中 `/api/session/{clientId}`
    //                                     （clientId="index"），由 AdminAuth 先拦下。
    //   三者都证明「未登录取不到数据」，故断言按**集合**而非单值；详情里打印实际观测值。
    $probe = [
        '/sessions/index' => '页面控制器的默认路径',
        '/api/sessions/index' => 'API 控制器的默认路径',
        '/api/session/index' => '详情控制器别名（被 /api/session/{clientId} 先匹配）',
        // 脚手架欢迎页控制器（2026-09-23 追加修复）：三个动作全部只经默认路由暴露，
        // 其中 `/index/json` 返回 `{"code":0,"msg":"ok"}` —— 与本项目成功信封形状一致。
        '/index/index' => '脚手架欢迎页（内嵌 workerman.net iframe）',
        '/index/view' => '脚手架视图渲染',
        '/index/json' => '脚手架 JSON 探针（未鉴权拿到 code:0 的成功信封）',
    ];
    foreach ($probe as $path => $desc) {
        $res = http('GET', $path, [], ['Accept: application/json'], $jar);
        $code = (int)(jsonBody($res['body'])['code'] ?? 0);
        $ok = in_array($res['status'], [404, 401], true) || ($res['status'] === 200 && $code === 404);

        check('★ 未登录 ' . $path . ' → 不可达（404/401/200+code404）：' . $desc, $ok,
            'HTTP ' . $res['status'] . ' code=' . ($code !== 0 ? $code : '-'));
    }

    // 根路径必须仍是「302 → /app/admin」而不是 404：
    // `/` 的默认路由恰好落在脚手架控制器的 index 动作上，禁用之后若没人显式接管就会变 404。
    $root = http('GET', '/', [], ['Accept: text/html'], $jar);
    check('★ 未登录 GET / → 302 到 /app/admin（禁用脚手架后根路径不得变 404）',
        $root['status'] === 302 && str_contains((string)($root['headers']['location'] ?? ''), '/app/admin'),
        'HTTP ' . $root['status'] . ' Location=' . ($root['headers']['location'] ?? '-'));

    // ---- 写方法一律不可用 ----
    // 同上：POST 到只注册了 GET 的路径，admin 的异常处理器同样可能给 `200 + code=404`，
    // 但**必须**是 code=404；若出现 200 + code=0 那就是真的被写入了，断言会红。
    $post = http('POST', '/api/sessions', ['x' => '1'], ['Accept: application/json'], $jar);
    $postCode = (int)(jsonBody($post['body'])['code'] ?? 0);
    check('POST /api/sessions 不可用（405/404；admin JSON 口径可能是 200+code404）',
        in_array($post['status'], [404, 405], true) || ($post['status'] === 200 && $postCode === 404),
        'HTTP ' . $post['status'] . ' code=' . ($postCode !== 0 ? $postCode : '-'));

    // ---- 登录后 ----
    $env = $_ENV + $_SERVER;
    $adminUser = (string)($env['ADMIN_BOOTSTRAP_USER'] ?? '');
    $adminPass = (string)($env['ADMIN_BOOTSTRAP_PASS'] ?? '');

    if ($adminUser === '' || $adminPass === '') {
        note('登录后检查', '.env 缺 ADMIN_BOOTSTRAP_USER/PASS');
    } elseif (!login($jar, $adminUser, $adminPass)) {
        check('登录（含验证码）', false, $adminUser . ' 登录失败');
    } else {
        check('登录（含验证码）', true, $adminUser);

        $page = http('GET', '/sessions', [], ['Accept: text/html'], $jar);
        $html = $page['body'];
        check('登录后 GET /sessions → 200', $page['status'] === 200, 'HTTP ' . $page['status']);
        check('页面含 #session-config 注入块', str_contains($html, 'id="session-config"'));
        check('页面引用 /static/session.js 与 .css',
            str_contains($html, '/static/session.js') && str_contains($html, '/static/session.css'));
        check('页面含抽屉容器（详情为页内展开）', str_contains($html, 'id="drawer"'));
        check('页面无 meta refresh', !str_contains($html, 'http-equiv="refresh"'));

        $map = [
            '/api/sessions?scope=online' => ['total', 'page', 'size', 'pages', 'scope', 'online_total', 'skeleton_ok', 'hint'],
            '/api/sessions?scope=all' => ['scan'],
        ];
        foreach ($map as $path => $keys) {
            $res = http('GET', $path, [], ['Accept: application/json'], $jar);
            $body = jsonBody($res['body']);
            $data = is_array($body['data'] ?? null) ? $body['data'] : [];
            check('GET ' . $path . ' → 200 + code=0',
                $res['status'] === 200 && (int)($body['code'] ?? -1) === 0,
                'HTTP ' . $res['status'] . ' code=' . ($body['code'] ?? '?'));
            $missingKeys = array_values(array_filter($keys, static fn (string $k): bool => !array_key_exists($k, $data)));
            check('  响应含字段：' . implode('/', $keys), $missingKeys === [],
                $missingKeys === [] ? '' : '缺 ' . implode(',', $missingKeys));
        }

        // 详情：不存在的 clientId 必须 404 + code 4004，且带 found=false
        $detail = http('GET', '/api/session/p2test-not-exist', [], ['Accept: application/json'], $jar);
        $detailBody = jsonBody($detail['body']);
        check('★ 详情：不存在的 clientId → HTTP 404 + code 4004',
            $detail['status'] === 404 && (int)($detailBody['code'] ?? 0) === 4004,
            'HTTP ' . $detail['status'] . ' code=' . ($detailBody['code'] ?? '?'));
        check('★ 404 响应体仍带 found=false（前端据此区分「已回收」与「取数失败」）',
            ($detailBody['data']['found'] ?? null) === false);

        // 入参校验：超长 uid → 400 + 4007
        $bad = http('GET', '/api/sessions/by-uid/' . str_repeat('x', 200), [], ['Accept: application/json'], $jar);
        check('★ 超长 uid → HTTP 400 + code 4007',
            $bad['status'] === 400 && (int)(jsonBody($bad['body'])['code'] ?? 0) === 4007,
            'HTTP ' . $bad['status']);

        // 订阅端点：两个参数都缺 → 400
        $noArg = http('GET', '/api/sessions/subscriptions', [], ['Accept: application/json'], $jar);
        check('订阅端点缺 uid/topic → HTTP 400',
            $noArg['status'] === 400, 'HTTP ' . $noArg['status']);

        // viewer 动态矩阵
        $viewerUser = (string)($env['ADMIN_VIEWER_USER'] ?? '');
        $viewerPass = (string)($env['ADMIN_VIEWER_PASS'] ?? '');
        if ($viewerUser === '' || $viewerPass === '') {
            note('viewer 动态矩阵', '未提供 ADMIN_VIEWER_USER / ADMIN_VIEWER_PASS（可选）');
        } else {
            $vjar = sys_get_temp_dir() . '/gw_p2v_' . getmypid() . '.txt';
            @unlink($vjar);
            if (login($vjar, $viewerUser, $viewerPass)) {
                foreach (['/api/sessions', '/api/sessions?scope=all', '/api/auth/revoked', '/sessions'] as $path) {
                    $res = http('GET', $path, [], ['Accept: application/json'], $vjar);
                    check('viewer GET ' . $path . ' → 200', $res['status'] === 200, 'HTTP ' . $res['status']);
                }
                $denied = http('GET', '/api/ops/api/probe', [], ['Accept: application/json'], $vjar);
                check('viewer GET /api/ops/api/probe → 403（密钥状态仍只给运维）',
                    $denied['status'] === 403, 'HTTP ' . $denied['status']);
            } else {
                check('viewer 登录', false, $viewerUser . ' 登录失败');
            }
            @unlink($vjar);
        }
    }

    @unlink($jar);
}

// ===========================================================================
// 汇总
// ===========================================================================
echo PHP_EOL . '=== 汇总：PASS ' . $pass . ' / FAIL ' . $fail . ' / SKIP ' . $skip . ' ===' . PHP_EOL;

exit($fail > 0 ? 1 : 0);
