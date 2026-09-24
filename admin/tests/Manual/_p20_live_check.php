<?php

declare(strict_types=1);

/**
 * 2.0 两项（指标趋势 / uid 排查）live 验收：登录运维账号 → 逐端点核对真实数据。
 * 一次性脚本，跑完即删；登录/请求封装复制自 p3_acceptance（避免改其公共结构）。
 */

require __DIR__ . '/../../vendor/autoload.php';

const BASE = 'http://127.0.0.1:8292';
const SESSION_DIR = __DIR__ . '/../../runtime/sessions';

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  [PASS] {$name}\n";
    } else {
        $fail++;
        echo "  [FAIL] {$name}  {$detail}\n";
    }
}

function http(string $method, string $path, array $body = [], array $headers = [], ?string &$jar = null): array
{
    $ch = curl_init(BASE . $path);
    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HEADER => true,
    ];
    if ($method === 'POST') {
        $opt[CURLOPT_POST] = true;
        $opt[CURLOPT_POSTFIELDS] = http_build_query($body);
    }
    if ($jar !== null && is_file($jar)) {
        $opt[CURLOPT_COOKIEFILE] = $jar;
    }
    if ($jar !== null) {
        $opt[CURLOPT_COOKIEJAR] = $jar;
    }
    $opt[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opt);
    $raw = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $pos = (int)strpos($raw, "\r\n\r\n");

    return ['status' => $status, 'body' => substr($raw, $pos + 4)];
}

function jsonBody(string $raw): array
{
    /** @var mixed $j */
    $j = json_decode($raw, true);

    return is_array($j) ? $j : [];
}

function readCaptcha(string $jar): string
{
    $cookie = (string)file_get_contents($jar);
    if (!preg_match('/PHPSID\s+(\S+)/', $cookie, $m)) {
        return '';
    }
    $file = SESSION_DIR . '/session_' . $m[1];
    if (!is_file($file)) {
        return '';
    }

    /** @var mixed $data */
    $data = @unserialize((string)file_get_contents($file), ['allowed_classes' => false]);
    if (!is_array($data)) {
        return '';
    }
    foreach ($data as $k => $v) {
        if (is_string($k) && strtolower($k) === 'captcha-login' && is_string($v)) {
            return $v;
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

    http('POST', '/app/admin/account/login', [
        'username' => $user, 'password' => $pass, 'captcha' => $captcha,
    ], ['Accept: application/json'], $jar);

    return true;
}

// ---- 登录 ----
$env = parseAdminEnv();
$user = (string)(getenv('ADMIN_BOOTSTRAP_USER') ?: 'admin');
$pass_ = (string)(getenv('ADMIN_BOOTSTRAP_PASS') ?: '');
$jar = __DIR__ . '/../../runtime/_p20_cookie.txt';
@unlink($jar);
login($jar, $user, $pass_);

echo "=== 指标趋势（/api/metrics/*）===\n";
$r = http('GET', '/api/metrics/latest', [], [], $jar);
$b = jsonBody($r['body']);
check('latest HTTP 200 + code 0', $r['status'] === 200 && (int)($b['code'] ?? -1) === 0, "status={$r['status']} body=" . substr($r['body'], 0, 120));
$row = $b['data']['row'] ?? null;
check('latest 有采样行（采样进程已落库）', is_array($row) && isset($row['sampled_at'], $row['counters']), json_encode($row));
check('latest counters 含 msg_in', is_array($row) && isset($row['counters']['msg_in']));

$r = http('GET', '/api/metrics/range?minutes=30&points=60', [], [], $jar);
$b = jsonBody($r['body']);
$rows = $b['data']['rows'] ?? [];
check('range HTTP 200 + code 0', $r['status'] === 200 && (int)($b['code'] ?? -1) === 0, "status={$r['status']}");
check('range 返回多行', is_array($rows) && count($rows) >= 1, 'count=' . count($rows));
if (count($rows) >= 2) {
    $last = $rows[count($rows) - 1];
    check('range 行含 rates 差分结构', isset($last['rates']) && is_array($last['rates']));
    check('range 行含 queues JSON', isset($last['queues']) && is_array($last['queues']));
}

// ---- uid 排查（复用的既有端点）----
echo "=== uid 排查（既有端点）===\n";
$r = http('GET', '/api/sessions/by-uid/nonexistent-uid-x', [], [], $jar);
$b = jsonBody($r['body']);
check('by-uid 未知 uid 返回空列表 + hint（而非 500）', $r['status'] === 200 && (int)($b['code'] ?? -1) === 0
    && (($b['data']['total'] ?? -1) === 0), "status={$r['status']}");

$r = http('GET', '/api/sessions/offline/nonexistent-uid-x', [], [], $jar);
$b = jsonBody($r['body']);
check('offline 未知 uid 返回 len=0', $r['status'] === 200 && (int)($b['code'] ?? -1) === 0 && ($b['data']['len'] ?? -1) === 0);

$r = http('GET', '/api/sessions/subscriptions?uid=nonexistent-uid-x', [], [], $jar);
$b = jsonBody($r['body']);
check('subscriptions 未知 uid 返回空 topics', $r['status'] === 200 && ($b['data']['topics'] ?? ['x']) === []);

@unlink($jar);
echo "\n结论: {$pass} PASS / {$fail} FAIL\n";

exit($fail === 0 ? 0 : 1);

/** 读 admin/.env（含特殊字符，parse_ini_file 不可用） */
function parseAdminEnv(): array
{
    \Dotenv\Dotenv::createUnsafeMutable(__DIR__ . '/../..')->safeLoad();

    return $_ENV;
}
