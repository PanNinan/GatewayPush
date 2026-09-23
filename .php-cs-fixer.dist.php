<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

/*
 * =====================================================================
 * php-cs-fixer —— 只管「排版」
 * =====================================================================
 * 与 phpcs.xml.dist 分工，两者**职责不得重叠**：
 *
 *   php-cs-fixer（本文件）  排版：空白 / 换行 / 缩进 / 括号 / 引号 / 类型与可见性声明 /
 *                          phpdoc 的「标签顺序与对齐」/ 语法现代化
 *   phpcs（phpcs.xml.dist）审计：注释的完整性与正确性 / 命名 / 业务红线
 *
 * 三条硬边界（相应地显式关掉下面 $boundary 里的规则）：
 *   1. 不新增、不删除 phpdoc 标签，不改写注释文字
 *      —— 注释「有没有、对不对」归 phpcs 裁决；两边同时管必然打架
 *      —— 反例：默认规则会删掉 `@param int $x`（与本项目 phpdoc 风格冲突），
 *         会给中文摘要补英文句点，还会把行内 `@var` 注解降级成普通注释，
 *         从而让 PHPStan 直接失明
 *   2. 不加 `declare(strict_types=1)`
 *      —— 它按**调用方文件**生效，逐文件加会让同一函数在不同调用点行为不一致
 *         （非严格模式自动 int→string，严格模式抛 TypeError）；本项目下限 8.2，
 *         兼容 8.2~8.5，依赖 Workerman 生态的宽松转换
 *   3. 不改运行时语义、不翻动行尾
 *      —— `mt_rand()`→`random_int()` 是熵源与异常行为的变更，不是排版
 *   4. 不新增 use、不缩短类名引用、不动文件头与注释的留白风格
 *      —— 默认会把 `GatewayPush\Gateway\Bootstrap::init()` 改写成 `Bootstrap::init()`
 *         并自动补 use 语句；start.php 同时引用 4 个同名 `Bootstrap`，只补 1 个反而更难读。
 *         另外默认要求 `<?php` 后必须空一行、phpdoc 后不得空行，与本项目 117/117 现状相反
 */

/**
 * 越界即关：这些规则要么与 phpcs 抢地盘，要么会改变运行语义
 *
 * @var array<string, bool|array<string, mixed>> $boundary
 */
$boundary = [
    // —— 界外①：注释内容归 phpcs ——
    'phpdoc_to_comment' => false,                  // `/** @var X $y */` → `/* */`，PHPStan 失明
    'phpdoc_summary' => false,                     // 给摘要补句点：中文注释变成「……读取.」
    'phpdoc_add_missing_param_annotation' => false, // 补 @param：注释「有无」不该由排版工具决定
    'no_superfluous_phpdoc_tags' => false,         // 删 @param/@return：与 phpcs 完整性审计对撞
    'phpdoc_no_empty_return' => false,             // 删 @return void
    'phpdoc_no_useless_inheritdoc' => false,       // 删 @inheritDoc
    'php_unit_internal_class' => false,            // 给测试类补 @internal：属「新增注释内容」
    'php_unit_test_class_requires_covers' => false, // 补 @covers：新增注释内容，且改动 PHPUnit 覆盖率语义
    'phpdoc_var_without_name' => false,            // 删 `@var Foo $bar` 里的 `$bar`：属「删除注释内文字」
    // —— 界外②：运行语义 / 类型声明 ——
    'declare_strict_types' => false,
    'random_api_migration' => false,               // mt_rand→random_int 改变熵源与失败行为
    'void_return' => false,                        // 补 `: void` 是「新增原生类型声明」不是排版；
                                                   // 本项目刻意用 phpdoc 承载类型，且会与 phpcs 的 @return 审计交叉
    'protected_to_private' => false,               // 改可见性＝改语义：静态分析若漏判外部继承即破坏扩展点
    // —— 界外③：保留项目既有压倒性约定（避免无收益的成片翻动）——
    'cast_spaces' => ['space' => 'none'],          // 既有 `(string)$x` 752 处 : 带空格 2 处
    'increment_style' => ['style' => 'post'],      // 既有 `$i++` 36 处 : `++$i` 1 处
    'concat_space' => ['spacing' => 'one'],        // 既有 `' . '` 947 处 : `'.'` 0 处
                                                   // @Symfony 里 `'concat_space' => true` 显式覆盖了
                                                   // @PER-CS 的 one（源码注明 overrides），此处设回
    'binary_operator_spaces' => [
        'default' => 'at_least_single_space',      // 保留赋值号垂直对齐：78/117 文件、467 行既有；
    ],                                             // 默认 single_space 会把对齐全部打散
    'blank_line_after_opening_tag' => false,       // @PSR12 要求 `<?php` 后空行；本项目 117/117 紧贴
    'no_blank_lines_after_phpdoc' => false,        // 既有：文件头注释与代码之间保留空行分隔
    // —— 界外④：结构 / 工具层不该插手 ——
    'yoda_style' => false,                         // @Symfony 默认 true，会把 `$x === 0` 全改 `0 === $x`
    'line_ending' => false,                        // 行尾统一交给 git（本机 client/ 检出为 CRLF）
    'fully_qualified_strict_types' => false,       // @PhpCsFixer 配了 import_symbols:true —— 会自动**新增
                                                   // use 语句**并把 FQCN 缩短成短名。start.php 同时引用
                                                   // 4 个同名 `Bootstrap`，只给其中一个加 use 反而更难读；
                                                   // 且「加 use」是结构改动不是排版，归人工/phpcs 管
    'global_namespace_import' => false,            // 同类：也会自动新增 `use Foo\Bar;` 把 FQCN 换短名
];

return (new Config())
    // risky 允许：@auto:risky 里 modernize_strpos / use_arrow_functions / no_alias_functions
    // 这类现代化迁移是安全的；真正会改语义的（见 $boundary）已逐条关掉
    ->setRiskyAllowed(true)
    ->setRules([
        '@auto' => true,        // PER-CS + 依 composer.json 下限（8.2）推导的迁移规则
        '@auto:risky' => true,  // 现代写法迁移
        '@PhpCsFixer' => true,  // 项目级排版细则（= @PER-CS + @Symfony + 额外项）
    ] + $boundary)
    /*
     * Finder 只列**项目源码**目录。
     * 不要写成 ->in(__DIR__)：那会把 runtime/phpstan 的生成产物一并扫进来
     * （实测 118 个源文件被放大成 1011 个，全量 dry-run 直接跑不完）。
     */
    ->setFinder(
        (new Finder())
            ->in([
                __DIR__ . '/src',
                __DIR__ . '/client',
                __DIR__ . '/config',
                __DIR__ . '/tests',
            ])
            ->append([__DIR__ . '/start.php'])
            ->name('*.php')
    );
