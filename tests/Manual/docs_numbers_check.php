<?php
/**
 * 文档关键数字只读核对
 *
 * 为什么需要它：
 *   README / AGENTS / 工具链说明里的 baseline 条目数、测试数、分析文件数
 *   是三处独立维护的字面量，任何一处改动都容易漏同步 —— 本脚本把它们
 *   变成可机械验证的断言，退出码 0 = 文档与实际一致。
 *
 * 核对项（只读，不跑 PHPStan / PHPUnit，避免把核对变成慢门禁）：
 *   ① 生产 baseline 必须是 ignoreErrors: []（语义清洗后的状态）
 *   ② 测试 baseline 条目数 / 抑制告警数 与文档一致（301 / 311）
 *   ③ 文档中不得残留过期数字（467 tests / 510 tests / 1431 / 324 条目 / 114 文件 / 8 条 等）
 *   ④ composer.json 必须有 test:frontend / test:sign / test:docs 脚本
 *   ⑤ CI 必须调用 test:frontend / test:sign / test:docs
 *
 * 用法：php tests/Manual/docs_numbers_check.php
 * 退出码 0 = 全绿。
 *
 * 不放进 PHPUnit 套件：它读的是文档字面量与配置，属「同步体检」，
 * 与 tests/Manual/ 下其它按需探针同类。
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$passed = 0;
$failed = 0;

/**
 * 断言并打印
 *
 * 用闭包而非函数：计数经引用捕获，避免 global（项目禁 Squiz.PHP.GlobalKeyword）。
 */
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    if ($ok === true) {
        $passed++;
        echo "  [PASS] {$label}\n";

        return;
    }

    $failed++;
    echo "  [FAIL] {$label}";
    echo $detail === '' ? "\n" : "  —— {$detail}\n";
};

echo "== ① 生产 baseline 已清空 ==\n";

$prodPath = $root . '/phpstan-baseline.neon';
$prodRaw  = is_file($prodPath) ? (string)file_get_contents($prodPath) : '';
$check(
    $prodRaw !== '' && (bool)preg_match('/ignoreErrors:\s*\[\]/', $prodRaw),
    'phpstan-baseline.neon 为 ignoreErrors: []',
    $prodRaw === '' ? '文件不存在或为空' : '未匹配到 ignoreErrors: []'
);

echo "\n== ② 测试 baseline 条目数 / 抑制告警数 ==\n";

$testsPath = $root . '/phpstan-tests-baseline.neon';
$testsRaw  = is_file($testsPath) ? (string)file_get_contents($testsPath) : '';

// 条目：ignoreErrors 下 tab 缩进的单独 `-` 行（baseline 中 message 与 `-` 分行）；
// 交叉验证：每条目恰好一行 `message:`；告警数：count: 求和。
$entries  = $testsRaw === '' ? -1 : (int)preg_match_all('/^\t\t-\s*$/m', $testsRaw);
$msgLines = $testsRaw === '' ? -1 : (int)preg_match_all('/^\t\t\tmessage:/m', $testsRaw);
$sum      = 0;
if ($testsRaw !== '' && (bool)preg_match_all('/count:\s*(\d+)/', $testsRaw, $m)) {
    foreach ($m[1] as $c) {
        $sum += (int)$c;
    }
}

$expectedEntries = 301;
$expectedSum     = 311;

$check(
    $entries === $expectedEntries,
    "测试 baseline 条目数 = {$expectedEntries}",
    "实际 {$entries}"
);
$check(
    $msgLines === $expectedEntries,
    "测试 baseline message 行数 = {$expectedEntries}",
    "实际 {$msgLines}"
);
$check(
    $sum === $expectedSum,
    "测试 baseline 抑制告警数 = {$expectedSum}",
    "实际 {$sum}"
);

// 结构拆分：273 missingType 条目 + 28 语义条目（语义 count 合计 38）
$entriesList = $testsRaw === '' ? [] : preg_split('/^\t\t-\s*$/m', $testsRaw);
if (is_array($entriesList)) {
    $entriesList = array_slice($entriesList, 1); // 首段是头注释 + parameters
}
$entriesList   = is_array($entriesList) ? $entriesList : [];
$missingTypeN  = 0;
$semanticN     = 0;
$semanticSum   = 0;
foreach ($entriesList as $body) {
    $idMatch  = preg_match('/identifier:\s*(\S+)/', $body, $im) === 1 ? $im[1] : '';
    $cntMatch = preg_match('/count:\s*(\d+)/', $body, $cm) === 1 ? (int)$cm[1] : 1;
    if (str_starts_with($idMatch, 'missingType.')) {
        $missingTypeN++;
    } else {
        $semanticN++;
        $semanticSum += $cntMatch;
    }
}
$check(
    $missingTypeN === 273 && $semanticN === 28 && $semanticSum === 38,
    '结构 = 273 missingType 条目 + 28 语义条目（count 合计 38）',
    "missingType={$missingTypeN} semanticN={$semanticN} semanticSum={$semanticSum}"
);

echo "\n== ③ 文档不得残留过期数字 ==\n";

$docs = [
    'README.md',
    'AGENTS.md',
    'docs/代码质量工具链说明.md',
];

// 每条：[过期字面量, 说明]
$stale = [
    ['467 tests', '旧测试数（现 519）'],
    ['1317 assertions', '旧断言数（现 1465）'],
    ['443 tests', '旧测试数（现 519）'],
    ['1246 assertions', '旧断言数（现 1465）'],
    ['510 tests', '旧测试数（现 519）'],
    ['1431 assertions', '旧断言数（现 1465）'],
    ['324 条目', '旧测试 baseline 条目（现 301）'],
    ['339 条', '旧测试 baseline 告警（现 311）'],
    ['114 文件', '旧分析文件数（现 119）'],
    ['生产代码 8 条', '生产 baseline 已清空'],
    ['生产代码，8 条', '生产 baseline 已清空'],
    ['（8 条）', '生产 baseline 已清空'],
    ['| 324 | 339 |', '旧 baseline 表行'],
    ['| 9 | 9 |', '旧生产 baseline 表行'],
];

foreach ($docs as $rel) {
    $path = $root . '/' . $rel;
    $raw  = is_file($path) ? (string)file_get_contents($path) : '';
    foreach ($stale as [$needle, $why]) {
        $found = $raw !== '' && str_contains($raw, $needle);
        // 「（8 条）」只在工具链说明的 baseline 表里是过期的；历史叙述中的
        // 「9 → 8 条」保留（那是曾经发生过的事实），故不拦单独的 "9 → 8 条"。
        if ($needle === '（8 条）' && (bool)strpos($raw, '生产侧 baseline 9 → 8 条')) {
            // 若同文件既有历史叙述又无独立表行残留，仍检查表行形态
            if (!(bool)preg_match('/生产代码存量（8 条）/', $raw)) {
                continue;
            }
        }
        $check(
            !$found,
            "{$rel} 无过期字面量：{$needle}",
            $found ? "命中（{$why}）" : ''
        );
    }
}

// 正向锚点：必须出现的最新数字
$positive = [
    'README.md'          => ['519 tests', '301 条目'],
    'AGENTS.md'          => ['519 tests', '301 条目'],
    'docs/代码质量工具链说明.md' => ['519 tests', '301 条目'],
];
foreach ($positive as $rel => $needles) {
    $path = $root . '/' . $rel;
    $raw  = is_file($path) ? (string)file_get_contents($path) : '';
    foreach ($needles as $needle) {
        $check(
            $raw !== '' && str_contains($raw, $needle),
            "{$rel} 含最新字面量：{$needle}",
            '未找到'
        );
    }
}

echo "\n== ④ composer.json 脚本 ==\n";

$composerPath = $root . '/composer.json';
$composerRaw  = is_file($composerPath) ? (string)file_get_contents($composerPath) : '';
$composer     = $composerRaw === '' ? null : json_decode($composerRaw, true);
$isArray      = is_array($composer);
$scripts      = $isArray && isset($composer['scripts']) && is_array($composer['scripts'])
    ? $composer['scripts']
    : [];

foreach (['test:frontend', 'test:sign', 'test:docs'] as $script) {
    $check(
        isset($scripts[$script]),
        "composer.json 含 script：{$script}",
        '缺失'
    );
}

echo "\n== ⑤ CI 调用新门禁 ==\n";

$ciPath = $root . '/.github/workflows/ci.yml';
$ciRaw  = is_file($ciPath) ? (string)file_get_contents($ciPath) : '';
$check(
    $ciRaw !== '' && (bool)preg_match('/composer\s+test:frontend/', $ciRaw),
    'ci.yml 调用 composer test:frontend',
    '未找到'
);
$check(
    $ciRaw !== '' && (bool)preg_match('/composer\s+test:sign/', $ciRaw),
    'ci.yml 调用 composer test:sign',
    '未找到'
);
$check(
    $ciRaw !== '' && (bool)preg_match('/composer\s+test:docs/', $ciRaw),
    'ci.yml 调用 composer test:docs',
    '未找到'
);
$check(
    $ciRaw !== '' && (bool)preg_match('/setup-node/', $ciRaw),
    'ci.yml 含 setup-node（test:frontend 依赖）',
    '未找到'
);

echo "\n== ⑥ 新增单测文件在位 ==\n";

foreach (['tests/Unit/RouterTest.php', 'tests/Unit/AuthTest.php', 'tests/Unit/PushTest.php'] as $rel) {
    $check(is_file($root . '/' . $rel), "存在 {$rel}", '缺失');
}

echo "\n==============================\n";
printf("通过 %d / 失败 %d\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
