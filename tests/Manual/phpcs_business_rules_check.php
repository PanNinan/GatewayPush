<?php
/**
 * phpcs 业务红线自检 —— 两个自定义嗅探器
 *
 * 为什么需要它：
 *   1) 自定义嗅探器不在任何门禁里（phpcs 不扫 tools/，PHPStan 也不分析 tools/），
 *      改坏了没人会发现；
 *   2) RedisKeyLiteralSniff 的前缀清单是**手写副本**，RedisKeys 新增常量后
 *      一旦忘记同步，红线就悄悄失效了。这里用反射做漂移检测。
 *
 * 覆盖三件事：
 *   ① 漂移检测：前缀清单必须覆盖 RedisKeys 全部字符串常量
 *   ② 正例：违规样本必须被报出
 *   ③ 作用域/豁免矩阵：6 条路径的期望命中数
 *
 * 用法：php tests/Manual/phpcs_business_rules_check.php
 * 退出码 0 = 全绿。
 *
 * 不放进 PHPUnit 套件的原因：它要起外部进程调 phpcs，属「环境校验脚本」，
 * 与 tests/Manual/ 下其它 P3~P5 探针同类，按需单独跑。
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require $root . '/vendor/squizlabs/php_codesniffer/autoload.php';

use GatewayPush\Common\RedisKeys;
use PHP_CodeSniffer\Standards\GatewayPush\Sniffs\Common\RedisKeyLiteralSniff;
use PHP_CodeSniffer\Standards\GatewayPush\Sniffs\PHP\ForbiddenCallSniff;

require_once $root . '/tools/phpcs/Sniffs/PHP/ForbiddenCallSniff.php';
require_once $root . '/tools/phpcs/Sniffs/Common/RedisKeyLiteralSniff.php';

$passed = 0;
$failed = 0;

/**
 * 断言并打印
 *
 * 用闭包而非函数：计数经引用捕获（&$passed / &$failed），
 * 避免 `global` —— 本项目遵守 Squiz.PHP.GlobalKeyword 禁则，
 * 自检脚本自身也必须过关。调用点写 `$check(...)`。
 */
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    if ($ok === true) {
        $passed++;
        echo "  [PASS] $label\n";
        return;
    }

    $failed++;
    echo "  [FAIL] $label";
    echo $detail === '' ? "\n" : "  —— $detail\n";
};

/**
 * 用 phpcs 跑一段样本，返回各红线命中次数
 *
 * @param string $stdinPath 伪装的被扫描路径（phpcs 的 --stdin-path）
 * @param string $code      样本源码
 *
 * @return array<string, int> ['forbidden' => n, 'redis' => n]
 */
function scanSample(string $stdinPath, string $code): array
{
    $root = dirname(__DIR__, 2);

    $cmd = sprintf(
        '%s %s --standard=%s --stdin-path=%s --report=full --no-colors -',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($root . '/vendor/bin/phpcs'),
        escapeshellarg($root . '/phpcs.xml.dist'),
        escapeshellarg($stdinPath)
    );

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, $root);

    if (is_resource($proc) === false) {
        return ['forbidden' => -1, 'redis' => -1];
    }

    fwrite($pipes[0], $code);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    return [
        'forbidden' => substr_count((string) $out, '常驻进程禁则'),
        'redis' => substr_count((string) $out, 'Redis 键名禁止'),
    ];
}

echo "===== phpcs 业务红线自检 =====\n\n";

/* ------------------------------------------------------------------
 * ① 漂移检测
 * ------------------------------------------------------------------ */
echo "[1] 漂移检测（嗅探器清单 vs 项目真源）\n";

$redisSniff = new RedisKeyLiteralSniff();
$forbiddenSniff = new ForbiddenCallSniff();

$constants = (new ReflectionClass(RedisKeys::class))->getConstants();
$uncovered = [];

foreach ($constants as $name => $value) {
    if (is_string($value) === false) {
        continue;
    }

    $covered = false;
    foreach ($redisSniff->prefixes as $prefix) {
        if (str_starts_with($value, $prefix) === true) {
            $covered = true;
            break;
        }
    }

    if ($covered === false) {
        $uncovered[] = "RedisKeys::$name = '$value'";
    }
}

$check(
    $uncovered === [],
    'RedisKeys 全部字符串常量都被 RedisKeyLiteralSniff::$prefixes 覆盖（共 ' . count($constants) . ' 个常量）',
    '未覆盖：' . implode('、', $uncovered) . ' —— 请同步 tools/phpcs/Sniffs/Common/RedisKeyLiteralSniff.php'
);

$expectedForbidden = ['exit', 'die', 'sleep', 'usleep', 'pcntl_fork'];
$missing = array_diff($expectedForbidden, array_keys($forbiddenSniff->forbidden));

$check(
    $missing === [],
    'ForbiddenCallSniff 覆盖 AGENTS.md 禁则全部 5 项（exit/die/sleep/usleep/pcntl_fork）',
    '缺失：' . implode('、', $missing)
);

/* ------------------------------------------------------------------
 * ② 正例 / ③ 作用域矩阵
 * ------------------------------------------------------------------ */
$sample = <<<'PHP'
<?php
class Probe
{
    public function a(): void
    {
        exit(1);
    }

    public function b(): void
    {
        sleep(1);
        usleep(1);
        pcntl_fork();
    }

    public function c(): string
    {
        return 'session:' . 'x';
    }

    public function d(): string
    {
        return "queue:udp:in";
    }
}
PHP;

$abs = str_replace('\\', '/', $root);

// 路径 => [期望禁则数, 期望键名数, 说明]
$matrix = [
    $abs . '/src/Probe.php' => [4, 2, 'src/ 应全查'],
    $abs . '/client/src/Transport/WsTransport.php' => [4, 2, 'client/src/ 应全查'],
    $abs . '/start.php' => [0, 0, 'CLI 入口不在 src/ 下，天然不查'],
    $abs . '/tests/Probe.php' => [0, 0, '测试代码不在范围内'],
    $abs . '/client/src/Cli/Debugger.php' => [0, 2, 'Debugger.php 只豁免「禁则」，键名仍查'],
    $abs . '/src/Common/RedisKeys.php' => [4, 0, 'RedisKeys.php 只豁免「键名」，禁则仍查'],
];

echo "\n[2] 作用域与豁免矩阵\n";

foreach ($matrix as $path => $expect) {
    $got = scanSample($path, $sample);
    $rel = str_replace($abs . '/', '', $path);

    $check(
        $got['forbidden'] === $expect[0] && $got['redis'] === $expect[1],
        sprintf('%-46s 禁则=%d 键名=%d', $rel, $got['forbidden'], $got['redis']),
        sprintf('期望 禁则=%d 键名=%d；%s', $expect[0], $expect[1], $expect[2])
    );
}

/* ------------------------------------------------------------------ */
echo "\n===============================\n";
printf("结论：%d 通过 / %d 失败\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
