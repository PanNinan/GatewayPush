<?php

/**
 * P0 验收冒烟脚本（手工执行，**不在** PHPUnit 套件内）。
 *
 * 用法：
 *     cd admin && php tests/Manual/p0_acceptance.php
 *
 * 覆盖 P0 的验收口径（设计文档 §7「P0 骨架」）：
 *   1. 配置装载：四个关键配置键均非空（database / plugin.admin.database / redis / gateway_push）
 *   2. Redis 连通：ping + 延迟 + dbsize + 预期键存在性自检（**防静默错误的关键兜底**）
 *   3. 主项目 API 连通：/health（免签）与 /stats（需签）的 HTTP 状态 + 响应体业务码
 *   4. 指标一致性：把 Redis 直读值与 /stats 返回逐项对照（P1 的锚点在此预演）
 *   5. psr-4 复用：确认 RedisKeys 来自主项目 src/，而非副本
 *
 * 退出码：0 = 全绿；1 = 有 FAIL。
 */

use app\service\GatewayPushClient;
use app\service\RedisReader;
use GatewayPush\Common\RedisKeys;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../support/bootstrap.php';

$pass = 0;
$fail = 0;
$warn = 0;

/**
 * @param string $label
 * @param bool   $ok
 * @param string $detail
 */
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
    global $warn;
    $warn++;
    echo "  [SKIP] {$label}" . ($detail !== '' ? "  {$detail}" : '') . PHP_EOL;
}

echo PHP_EOL . '=== P0 验收冒烟（GatewayPush 管理后台）===' . PHP_EOL;
echo 'PHP ' . PHP_VERSION . ' / ' . date('Y-m-d H:i:s') . PHP_EOL . PHP_EOL;

// ---------------------------------------------------------------------------
// 1. 配置装载
// ---------------------------------------------------------------------------
echo '[1] 配置装载' . PHP_EOL;

$dbConfig = config('database');
check('config(database) 非空', is_array($dbConfig) && !empty($dbConfig['connections']));

$pluginDb = config('plugin.admin.database');
check(
    'config(plugin.admin.database) 非空',
    is_array($pluginDb) && !empty($pluginDb['connections']),
    '（为空时 webman/admin 会抛「请重启webman」）'
);

$redisConfig = config('redis.default');
check('config(redis.default) 非空', is_array($redisConfig) && !empty($redisConfig['host']));

$gpConfig = config('gateway_push');
check('config(gateway_push) 非空', is_array($gpConfig) && !empty($gpConfig['api_url']));

check(
    'database 与 plugin.admin.database 同源',
    is_array($dbConfig) && is_array($pluginDb)
        && ($dbConfig['connections']['mysql'] ?? null) === ($pluginDb['connections']['mysql'] ?? null),
    '（两者必须一致，否则后台内部与 Eloquent 会连到不同库）'
);

echo '      redis:      ' . ($redisConfig['host'] ?? '?') . ':' . ($redisConfig['port'] ?? '?')
    . ' db=' . var_export($redisConfig['database'] ?? null, true)
    . ' prefix=' . var_export($redisConfig['prefix'] ?? null, true)
    . ' client=' . var_export($redisConfig['client'] ?? null, true) . PHP_EOL;
echo '      db:         ' . ($dbConfig['connections']['mysql']['host'] ?? '?')
    . ':' . ($dbConfig['connections']['mysql']['port'] ?? '?')
    . ' name=' . ($dbConfig['connections']['mysql']['database'] ?? '?')
    . ' user=' . (($dbConfig['connections']['mysql']['username'] ?? '') === '' ? '(空)' : $dbConfig['connections']['mysql']['username'])
    . PHP_EOL;
echo '      api_url:    ' . ($gpConfig['api_url'] ?? '?') . PHP_EOL;
echo '      api_secret: ' . (($gpConfig['api_secret'] ?? '') === '' ? '(空 → 服务端回退 AUTH_SECRET)' : '(已配置)') . PHP_EOL;
echo '      redis db 匹配主项目 REDIS_DB=9: '
    . ((int)($redisConfig['database'] ?? -1) === 9 ? 'YES' : 'NO（⚠ 会读到空数据且不报错）') . PHP_EOL;
echo PHP_EOL;

// ---------------------------------------------------------------------------
// 2. psr-4 复用验证（P0 第一天必须验证项）
// ---------------------------------------------------------------------------
echo '[2] psr-4 复用（主项目真源，非副本）' . PHP_EOL;

$reflect = new ReflectionClass(RedisKeys::class);
$file = (string)$reflect->getFileName();
$expectedSuffix = DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Common' . DIRECTORY_SEPARATOR . 'RedisKeys.php';
check(
    'RedisKeys 来自主项目 src/Common/RedisKeys.php',
    substr($file, -strlen($expectedSuffix)) === $expectedSuffix,
    '实际: ' . $file
);
check('RedisKeys::session() 可用', RedisKeys::session('ws:probe') === 'session:ws:probe');
echo PHP_EOL;

// ---------------------------------------------------------------------------
// 3. Redis 连通与自检
// ---------------------------------------------------------------------------
echo '[3] Redis（只读通道）' . PHP_EOL;

$reader = new RedisReader();

try {
    $ping = $reader->ping();
    check('Redis ping', $ping['ok'], $ping['ok'] ? $ping['latency_ms'] . 'ms' : $ping['msg']);
} catch (Throwable $e) {
    $ping = ['ok' => false];
    check('Redis ping', false, $e->getMessage());
}

if ($ping['ok']) {
    try {
        echo '      dbsize:     ' . $reader->dbSize() . PHP_EOL;
        echo '      online:     ' . $reader->onlineCount() . PHP_EOL;

        $queues = $reader->queueDepths();
        $parts = [];
        foreach ($queues as $k => $v) {
            $parts[] = $k . '=' . $v;
        }
        echo '      queues:     ' . implode('  ', $parts) . PHP_EOL;

        $gauge = $reader->gauge();
        echo '      gauge:      ' . count($gauge) . ' 个 field' . PHP_EOL;

        $counter = $reader->counter();
        echo '      counter:    ' . count($counter) . ' 个 field（今日）' . PHP_EOL;

        $selfCheck = $reader->selfCheck();
        echo PHP_EOL . '      —— 预期键存在性自检（防 DB/PREFIX 配错导致的静默空数据）——' . PHP_EOL;
        foreach ($selfCheck['checks'] as $c) {
            echo sprintf(
                "      %-22s exists=%-5s type=%-5s ttl=%-4s expect=%-5s %s",
                $c['key'],
                $c['exists'] ? 'yes' : 'no',
                $c['type'],
                (string)$c['ttl'],
                $c['expect'],
                $c['hint']
            ) . PHP_EOL;
        }
        check('预期键自检（骨架键至少命中一个）', $selfCheck['ok'], $selfCheck['hint']);
    } catch (Throwable $e) {
        check('Redis 读操作', false, get_class($e) . ': ' . $e->getMessage());
    }
} else {
    note('Redis 后续检查', 'ping 失败，跳过');
}
echo PHP_EOL;

// ---------------------------------------------------------------------------
// 4. 主项目 API 连通
// ---------------------------------------------------------------------------
echo '[4] 主项目 HTTP API' . PHP_EOL;

$client = new GatewayPushClient(
    (string)($gpConfig['api_url'] ?? 'http://127.0.0.1:8290'),
    (string)($gpConfig['api_secret'] ?? '')
);

$health = $client->health();
check(
    'GET /health',
    $health['ok'],
    'HTTP ' . $health['status'] . ' code=' . $health['code'] . ($health['msg'] !== '' ? ' msg=' . $health['msg'] : '')
);
if ($health['ok'] && $health['data']) {
    echo '      health.data: ' . json_encode($health['data'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

$stats = $client->stats();
check(
    'GET /stats',
    $stats['ok'],
    'HTTP ' . $stats['status'] . ' code=' . $stats['code'] . ($stats['msg'] !== '' ? ' msg=' . $stats['msg'] : '')
);
if (!$stats['ok']) {
    if ((int)$stats['code'] === GatewayPushClient::CODE_BAD_SIGN) {
        echo '      ↑ code=4001 验签失败：若主项目为免签模式（API_SIGN_ENABLE=false 且回环监听），' . PHP_EOL
            . '        说明本后台配了错的密钥；若为非免签模式，需在 .env 填正确的 ADMIN_API_SECRET。' . PHP_EOL;
    }
    if ((int)$stats['code'] === GatewayPushClient::CODE_TRANSPORT) {
        echo '      ↑ 连不上主项目 API：确认 bin\start.bat 已起 api 角色（8290）。' . PHP_EOL;
    }
}

// 指标交叉比对（P1 的锚点预演）
if ($ping['ok'] && $stats['ok']) {
    $apiGauge = $stats['data']['gauge'] ?? [];
    $apiCounter = $stats['data']['counter'] ?? [];
    $sameGauge = count($apiGauge) === count($reader->gauge());
    $sameCounter = count($apiCounter) === count($reader->counter());
    check('Redis 直读 gauge field 数 == /stats 返回', $sameGauge, 'redis=' . count($reader->gauge()) . ' api=' . count($apiGauge));
    check('Redis 直读 counter field 数 == /stats 返回', $sameCounter, 'redis=' . count($reader->counter()) . ' api=' . count($apiCounter));
} else {
    note('指标交叉比对', '依赖 Redis 与 /stats 同时可用');
}
echo PHP_EOL;

// ---------------------------------------------------------------------------
// 汇总
// ---------------------------------------------------------------------------
echo '=== 汇总：PASS ' . $pass . ' / FAIL ' . $fail . ' / SKIP ' . $warn . ' ===' . PHP_EOL;

exit($fail > 0 ? 1 : 0);
