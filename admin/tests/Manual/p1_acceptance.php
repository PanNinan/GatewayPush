<?php

/**
 * P1 验收脚本（手工执行，**不在** PHPUnit 套件内：它依赖后台服务与主项目在线）。
 *
 * 用法：
 *     cd admin && php tests/Manual/p1_acceptance.php
 *
 * P1 的交付面是「分层轮询 + 派生率 + 进程表 + 自绘趋势」，因此验收分三层：
 *
 *   1. **服务层**（不依赖 HTTP，但依赖 Redis）：`MetricsDeriver` 对**真实** Redis 数据
 *      能算出派生率与进程表；`MonitorAggregator::live()` 结构完整。
 *   2. **HTTP 层**（依赖后台在线）：静态资源、未登录 401、登录后页面与两个端点，
 *      以及「快 tick 不打主项目 HTTP」这一条**失败模式隔离**的核心断言。
 *   3. **RBAC**：静态断言「只读角色包含 mon.live 节点」—— 这是 P1 最容易漏、
 *      且症状最隐蔽的一处（漏了只表现为「打开面板后永远停在骨架，浏览器控制台 403」）。
 *
 * 若 2 所需的账号在 .env 中给了 `ADMIN_VIEWER_USER` / `ADMIN_VIEWER_PASS`，
 * 会额外跑一次 viewer 的动态 403 矩阵；未提供则标 SKIP（不影响退出码）。
 *
 * 退出码：0 = 全绿（SKIP 不算失败）；1 = 有 FAIL。
 */

use app\service\MetricsDeriver;
use app\service\MonitorAggregator;
use app\service\RedisReader;
use app\service\Settings;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../support/bootstrap.php';

const ADMIN_HOST = '127.0.0.1';
const ADMIN_PORT = 8292;
const SESSION_DIR = __DIR__ . '/../../runtime/sessions';

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
 *   与 `GatewayPushClient` 里的处理同因不同处（见设计文档 §11.2 的 D7）。
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

/**
 * @return array<string, mixed>
 */
function jsonBody(string $body): array
{
    $decoded = json_decode($body, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * 从 session 文件里读出验证码（webman session 为 file 驱动 + PHP serialize 格式）。
 *
 * 登录**强制校验验证码**（`AccountController::login` 比对 `session('captcha-login')`），
 * 所以自动化验收必须走「先 GET captcha 建立 session → 读明文 → 再 POST 登录」这条路，
 * 纯 API 是登录不进去的。
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

    // 键名大小写不去猜：直接按大小写不敏感匹配
    foreach ($data as $key => $value) {
        if (is_string($key) && strtolower($key) === 'captcha-login' && is_string($value)) {
            return $value;
        }
    }

    return '';
}

/**
 * 登录并返回是否成功。
 */
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

    $body = jsonBody($res['body']);

    return (int)($body['code'] ?? -1) === 0;
}

$env = $_ENV + $_SERVER;
$adminUser = (string)($env['ADMIN_BOOTSTRAP_USER'] ?? '');
$adminPass = (string)($env['ADMIN_BOOTSTRAP_PASS'] ?? '');
$viewerUser = (string)($env['ADMIN_VIEWER_USER'] ?? '');
$viewerPass = (string)($env['ADMIN_VIEWER_PASS'] ?? '');

echo PHP_EOL . '=== P1 验收（GatewayPush 管理后台 · 分层轮询与派生率）===' . PHP_EOL;
echo 'PHP ' . PHP_VERSION . ' / ' . date('Y-m-d H:i:s') . PHP_EOL;

// ===========================================================================
// 1. 服务层：派生率与进程表
// ===========================================================================
section('[1] 服务层：MetricsDeriver / MonitorAggregator（依赖 Redis）');

$reader = new RedisReader();
$ping = $reader->ping();

check('Redis 连通', $ping['ok'], $ping['ok'] ? $ping['latency_ms'] . 'ms' : $ping['msg']);

if (!$ping['ok']) {
    note('服务层后续检查', 'Redis 不可用，跳过');
} else {
    $gauge = $reader->gauge();
    $counter = $reader->counter();
    $queues = $reader->queueDepths();
    $now = time();

    echo '      gauge=' . count($gauge) . ' field  counter=' . count($counter) . ' field' . PHP_EOL;

    // ---- 派生率 ----
    $deriver = new MetricsDeriver(Settings::json('monitor.ratio_thresholds'));
    $derived = $deriver->derive(
        $counter,
        $gauge,
        $queues,
        max(1, Settings::int('monitor.queue_warn_depth', 1000)),
        max(1, Settings::int('monitor.gauge_stale_secs', 10)),
        $now
    );

    check('derived 三段齐全（ratios/processes/alerts）',
        isset($derived['ratios'], $derived['processes'], $derived['alerts']));

    check('派生率条数 = 9（规格表全量）', count($derived['ratios']) === 9,
        '实际 ' . count($derived['ratios']));

    // 真实数据下「分母为 0 → null」的语义必须成立（本项目今日可能就没流量）
    $nullish = 0;
    foreach ($derived['ratios'] as $r) {
        if ($r['den'] === 0 && $r['value'] !== null) {
            $fail++;
            echo "  [FAIL] {$r['key']} 分母为 0 但 value 非 null（语义回归）" . PHP_EOL;
        }
        if ($r['value'] === null) {
            $nullish++;
        }
    }
    check('分母为 0 的项一律 value=null（无样本 ≠ 0%）', $fail === 0,
        $nullish . '/' . count($derived['ratios']) . ' 项当前无样本');

    foreach ($derived['ratios'] as $r) {
        printf(
            "      %-14s %8s  %-4s  den=%-8s %s%s",
            $r['key'],
            $r['value'] === null ? '—' : number_format($r['value'], 2) . '%',
            $r['level'],
            (string)$r['den'],
            $r['formula'],
            PHP_EOL
        );
    }

    // ---- 进程表 ----
    $procs = $derived['processes'];
    $alive = array_values(array_filter($procs, static fn (array $p): bool => $p['alive'] === true));

    check('进程表解析出 ≥ 1 个进程', count($procs) > 0,
        '共 ' . count($procs) . ' 个 PID，其中存活 ' . count($alive));
    check('至少 1 个进程存活', count($alive) > 0,
        count($alive) > 0 ? '' : '主项目 MONITOR_ENABLE=false？或 6 角色未启动？');

    foreach ($procs as $p) {
        printf(
            "      pid=%-6d role=%-10s worker=%-4s mem=%-10s age=%-5s %s%s",
            $p['pid'],
            $p['role'],
            (string)$p['worker_id'],
            $p['memory_bytes'] > 0 ? round($p['memory_bytes'] / 1048576, 1) . 'MB' : '—',
            $p['age_secs'] < 0 ? '不可信' : $p['age_secs'] . 's',
            $p['alive'] ? '存活' : '已退出',
            PHP_EOL
        );
        // 身份解析必须成功，否则说明 gauge 字段前缀与主项目脱钩（最严重的静默故障）
        if ($p['role'] === '?') {
            $fail++;
            echo '  [FAIL] pid=' . $p['pid'] . ' 无法解析角色 —— gauge 的 proc: 字段可能已改前缀' . PHP_EOL;
        }
    }

    check('所有进程都能解析出角色（proc: 字段前缀未脱钩）',
        array_filter($procs, static fn (array $p): bool => $p['role'] === '?') === []);

    // ---- live() 结构 ----
    $agg = new MonitorAggregator();
    $live = $agg->live();

    check('live() 三段齐全（ts/redis/derived）', isset($live['ts'], $live['redis'], $live['derived']));

    // ★ 失败模式隔离的核心断言：快 tick 的返回体里**不能有** api 段。
    //   一旦有人图省事把主项目 HTTP 调用并进 live()，这条会立刻变红。
    check(
        '★ live() 不含 api 段（快 tick 不打主项目 HTTP）',
        !array_key_exists('api', $live),
        '若此处失败，说明有人把可能阻塞 8s 的主项目调用并进了高频路径'
    );

    foreach (['ok', 'msg', 'latency_ms', 'online', 'gauge', 'counter', 'queues', 'report_at'] as $key) {
        if (!array_key_exists($key, $live['redis'])) {
            $fail++;
            echo "  [FAIL] live().redis 缺字段：{$key}" . PHP_EOL;
        }
    }
    check('live().redis 字段齐全（8 项）', true);
    check('live().redis.report_at 已解析', $live['redis']['report_at'] > 0,
        'report_at=' . $live['redis']['report_at'] . '（0 表示 gauge 里没有 report_at）');

    // ---- collect() 含 derived 与 report_at ----
    $full = (new MonitorAggregator())->collect();
    check('collect() 含 derived', isset($full['derived']['ratios'], $full['derived']['processes']));
    check('collect().redis 也带 report_at（避免慢 tick 把面板字段擦成 —）',
        isset($full['redis']['report_at']));

    // ---- 阈值设置可解析 ----
    $thresholds = Settings::json('monitor.ratio_thresholds');
    check('monitor.ratio_thresholds 可解析（{} → 空数组，全部走类常量默认）',
        is_array($thresholds), '共 ' . count($thresholds) . ' 项覆盖');
    check('monitor.slow_interval 已入库',
        Settings::int('monitor.slow_interval', 0) > 0,
        (string)Settings::int('monitor.slow_interval', 0) . 's');
}

// ===========================================================================
// 2. HTTP 层
// ===========================================================================
section('[2] HTTP 层（依赖后台 8292 在线）');

$jar = sys_get_temp_dir() . '/gw_p1_' . getmypid() . '.txt';
@unlink($jar);

// ---- 静态资源 ----
$js = http('GET', '/static/dashboard.js');
$css = http('GET', '/static/dashboard.css');

check('GET /static/dashboard.js → 200', $js['status'] === 200 && strlen($js['body']) > 1000,
    'HTTP ' . $js['status'] . ' ' . strlen($js['body']) . ' bytes');
check('GET /static/dashboard.css → 200', $css['status'] === 200 && strlen($css['body']) > 500,
    'HTTP ' . $css['status'] . ' ' . strlen($css['body']) . ' bytes');
check('JS 里没有 innerHTML（XSS 纪律：一律走 textContent）',
    !preg_match('/\.innerHTML\s*=/', $js['body']), '若失败说明引入了 HTML 拼接面');

// ---- 未登录 ----
$live = http('GET', '/api/monitor/live', [], ['Accept: application/json'], $jar);
check('未登录 GET /api/monitor/live → HTTP 401',
    $live['status'] === 401, 'HTTP ' . $live['status']);
check('未登录响应体 code=401', (int)(jsonBody($live['body'])['code'] ?? 0) === 401);

$page = http('GET', '/dashboard', [], ['Accept: text/html'], $jar);
check('未登录 GET /dashboard → 302 到登录页', $page['status'] === 302,
    'HTTP ' . $page['status'] . ' Location=' . ($page['headers']['location'] ?? '-'));

// ---- 登录 ----
if ($adminUser === '' || $adminPass === '') {
    note('登录后检查', '.env 缺 ADMIN_BOOTSTRAP_USER/PASS');
} elseif (!login($jar, $adminUser, $adminPass)) {
    check('登录（含验证码）', false, '账号 ' . $adminUser . ' 登录失败');
} else {
    check('登录（含验证码）', true, '账号 ' . $adminUser);

    // ---- 页面骨架 ----
    $dash = http('GET', '/dashboard', [], ['Accept: text/html'], $jar);
    $html = $dash['body'];

    check('GET /dashboard → 200', $dash['status'] === 200, 'HTTP ' . $dash['status']);
    check('页面含 #dashboard-config 注入块', str_contains($html, 'id="dashboard-config"'));
    check('页面引用 /static/dashboard.js', str_contains($html, '/static/dashboard.js'));
    check('页面引用 /static/dashboard.css', str_contains($html, '/static/dashboard.css'));
    // P1 的核心变化：整页重载已移除，改由 JS 轮询
    check('★ 页面已移除 <meta http-equiv="refresh">（P1 不再整页重载）',
        !str_contains($html, 'http-equiv="refresh"'));

    // ---- 快 tick ----
    $live = http('GET', '/api/monitor/live', [], ['Accept: application/json'], $jar);
    $liveBody = jsonBody($live['body']);

    check('GET /api/monitor/live → 200 + code=0',
        $live['status'] === 200 && (int)($liveBody['code'] ?? -1) === 0,
        'HTTP ' . $live['status'] . ' code=' . ($liveBody['code'] ?? '?'));

    $liveData = is_array($liveBody['data'] ?? null) ? $liveBody['data'] : [];
    check('live.data.derived.ratios 非空', !empty($liveData['derived']['ratios']));
    check('live.data.derived.processes 非空', !empty($liveData['derived']['processes']));
    check('live.data.redis.report_at 有值', (int)($liveData['redis']['report_at'] ?? 0) > 0);
    check('★ live.data 内无 api 段（快 tick 未打主项目 HTTP）',
        !array_key_exists('api', $liveData));

    // ---- 慢 tick ----
    $sum = http('GET', '/api/monitor/summary', [], ['Accept: application/json'], $jar);
    $sumBody = jsonBody($sum['body']);
    $sumData = is_array($sumBody['data'] ?? null) ? $sumBody['data'] : [];

    check('GET /api/monitor/summary → 200 + code=0',
        $sum['status'] === 200 && (int)($sumBody['code'] ?? -1) === 0,
        'HTTP ' . $sum['status'] . ' code=' . ($sumBody['code'] ?? '?'));
    check('summary 含 self_check 与 db_size',
        isset($sumData['redis']['self_check']['checks'], $sumData['redis']['db_size']));
    check('summary 含主项目 api 段（慢 tick 才打）', array_key_exists('api', $sumData));
    check('summary 快慢字段同源：redis.report_at 与 live 一致',
        (int)($liveData['redis']['report_at'] ?? -2) === (int)($sumData['redis']['report_at'] ?? -1),
        'live=' . ($liveData['redis']['report_at'] ?? '?') . ' summary=' . ($sumData['redis']['report_at'] ?? '?'));

    // ---- 与主项目的三方交叉比对 ----
    if ($ping['ok'] && !empty($sumData['api']['stats'])) {
        $apiGauge = $sumData['api']['stats']['gauge'] ?? [];
        check('gauge field 数三方一致（Redis 直读 = live = 主项目 /stats）',
            count($apiGauge) === count($reader->gauge())
            && count($liveData['redis']['gauge'] ?? []) === count($reader->gauge()),
            '主项目=' . count($apiGauge)
            . ' 后台直读=' . count($reader->gauge())
            . ' live=' . count($liveData['redis']['gauge'] ?? []));
    } else {
        note('三方交叉比对', '依赖 Redis 与主项目 /stats 同时可用');
    }

    // ---- 未注册路由（HTTP 200 + code=404：webman-admin 插件异常处理器的口径）----
    $nope = http('GET', '/api/monitor/definitely-not-a-route', [], ['Accept: application/json'], $jar);
    $nopeBody = jsonBody($nope['body']);
    check('未注册路由返回业务码 404（注意：HTTP 仍为 200，属插件异常处理器口径）',
        (int)($nopeBody['code'] ?? 0) === 404,
        'HTTP ' . $nope['status'] . ' code=' . ($nopeBody['code'] ?? '?'));
}

@unlink($jar);

// ===========================================================================
// 3. RBAC：只读角色必须包含 mon.live
// ===========================================================================
section('[3] RBAC：只读角色必须包含 mon.live 节点');

try {
    $dsn = 'mysql:host=' . (string)config('database.connections.mysql.host')
        . ';port=' . (string)config('database.connections.mysql.port')
        . ';dbname=' . (string)config('database.connections.mysql.database')
        . ';charset=utf8mb4';
    $pdo = new PDO(
        $dsn,
        (string)config('database.connections.mysql.username'),
        (string)config('database.connections.mysql.password'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $liveNodeId = $pdo->query(
        "SELECT id FROM wa_rules WHERE `key` = 'app\\\\controller\\\\api\\\\MonitorController@live' LIMIT 1"
    )->fetchColumn();

    check('wa_rules 里存在 mon.live 节点', $liveNodeId !== false, 'id=' . var_export($liveNodeId, true));

    if ($liveNodeId !== false) {
        foreach (['只读', '运维'] as $roleName) {
            $stmt = $pdo->prepare('SELECT `rules` FROM wa_roles WHERE `name` = ? LIMIT 1');
            $stmt->execute([$roleName]);
            $rules = (string)($stmt->fetchColumn() ?: '');

            check(
                "角色「{$roleName}」包含 mon.live（否则面板打开后永远停在骨架）",
                $rules === '*' || in_array((string)$liveNodeId, explode(',', $rules), true),
                'rules=' . $rules
            );
        }
    }
} catch (Throwable $e) {
    note('RBAC 静态断言', 'MySQL 不可用：' . $e->getMessage());
}

// ---- 可选：viewer 动态 403 矩阵 ----
if ($viewerUser === '' || $viewerPass === '') {
    note('viewer 动态 403 矩阵', '未提供 ADMIN_VIEWER_USER / ADMIN_VIEWER_PASS（可选）');
} else {
    $jar = sys_get_temp_dir() . '/gw_p1v_' . getmypid() . '.txt';
    @unlink($jar);

    if (login($jar, $viewerUser, $viewerPass)) {
        check('viewer 登录', true, $viewerUser);

        $codes = [
            '/api/monitor/live' => 200,
            '/api/monitor/summary' => 200,
            '/api/ops/redis/scan' => 403,
            '/api/ops/api/probe' => 403,
        ];
        foreach ($codes as $path => $expect) {
            $res = http('GET', $path, [], ['Accept: application/json'], $jar);
            check("viewer GET {$path} → {$expect}", $res['status'] === $expect, 'HTTP ' . $res['status']);
        }
    } else {
        check('viewer 登录', false, $viewerUser . ' 登录失败');
    }

    @unlink($jar);
}

// ===========================================================================
// 汇总
// ===========================================================================
echo PHP_EOL . '=== 汇总：PASS ' . $pass . ' / FAIL ' . $fail . ' / SKIP ' . $skip . ' ===' . PHP_EOL;

exit($fail > 0 ? 1 : 0);
