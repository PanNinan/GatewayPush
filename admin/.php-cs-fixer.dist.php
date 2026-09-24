<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

/*
 * =====================================================================
 * admin php-cs-fixer —— 只管「排版」
 * =====================================================================
 * 与 admin/phpcs.xml.dist 分工，两者**职责不得重叠**：
 *
 *   php-cs-fixer（本文件）  排版：空白 / 换行 / 缩进 / 括号 / 引号 / 类型与可见性声明 /
 *                          phpdoc 的「标签顺序与对齐」/ 语法现代化
 *   phpcs（phpcs.xml.dist）审计：注释的完整性与正确性 / 命名 / 业务红线
 *
 * 边界与主项目根 .php-cs-fixer.dist.php 同源（见该文件 $boundary 注释）：
 * 不改注释内容、不加 declare(strict_types)、不改运行语义、不自动加 use。
 *
 * Finder 只列 admin 自有源码；不要 ->in(__DIR__)（会扫进 vendor / runtime）。
 */

/**
 * 界外规则（与主项目一致，避免两边互为回退）
 *
 * @var array<string, bool|array<string, mixed>> $boundary
 */
$boundary = [
    // —— 界外①：注释内容归 phpcs ——
    'phpdoc_to_comment' => false,
    'phpdoc_summary' => false,
    'phpdoc_add_missing_param_annotation' => false,
    'no_superfluous_phpdoc_tags' => false,
    'phpdoc_no_empty_return' => false,
    'phpdoc_no_useless_inheritdoc' => false,
    'php_unit_internal_class' => false,
    'php_unit_test_class_requires_covers' => false,
    'phpdoc_var_without_name' => false,
    // —— 界外②：运行语义 / 类型声明 ——
    'declare_strict_types' => false,
    'random_api_migration' => false,
    'void_return' => false,
    'protected_to_private' => false,
    // —— 界外③：保留项目既有压倒性约定 ——
    'cast_spaces' => ['space' => 'none'],
    'increment_style' => ['style' => 'post'],
    'concat_space' => ['spacing' => 'one'],
    'binary_operator_spaces' => [
        'default' => 'at_least_single_space',
    ],
    'blank_line_after_opening_tag' => false,
    'no_blank_lines_after_phpdoc' => false,
    // —— 界外④：结构 / 工具层不该插手 ——
    'yoda_style' => false,
    'line_ending' => false,
    'fully_qualified_strict_types' => false,
    'global_namespace_import' => false,
];

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@auto' => true,
        '@auto:risky' => true,
        '@PhpCsFixer' => true,
    ] + $boundary)
    ->setFinder(
        (new Finder())
            ->in([
                __DIR__ . '/app',
                __DIR__ . '/config',
                __DIR__ . '/scripts',
                __DIR__ . '/tests',
            ])
            ->name('*.php')
            // plugin/ 是 vendor 副本；runtime/ 是产物；app/process 是 webman 骨架
            ->exclude(['process'])
            ->notPath('#^plugin/#')
            ->notPath('#^runtime/#')
            // tests/Manual/_* 与 scripts/_* 临时脚本不纳入排版门禁
            // notPath 相对各 ->in() 根：tests 下是 Manual/_*，scripts 下是 _*
            ->notPath('#^Manual/_#')
            ->notPath('#^_#')
    );
