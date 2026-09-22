<?php
/**
 * PHPUnit 引导文件
 *
 * 只做一件事：加载 Composer 自动加载。
 *
 * 刻意不在此处调用 Env::load() —— 单元测试需要可控的环境变量状态，
 * 由各测试用例自行设置 $_ENV 并在 tearDown 中还原。
 * Env 的读取逻辑是「$_ENV -> $_SERVER -> getenv()」，
 * 用例写入 $_ENV 的键优先命中，不受真实 .env 影响。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

require dirname(__DIR__) . '/vendor/autoload.php';
