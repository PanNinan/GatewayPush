<?php
/**
 * admin 配置 —— middleware。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

use app\middleware\AccessLog;

/**
 * 全局中间件注册。
 *
 * ⚠ webman 要求「应用名 => 中间件类列表」两层结构，**不是**扁平列表：
 * `Webman\Middleware::load()` 对每个 value 再 `is_array()` 校验，
 * 扁平 `[Cls::class]` 会直接抛 `Bad middleware config`。
 *
 * `'@'` = 根应用全局命名空间 → 最终落在 `instances['']['@']`，
 * 由 `getMiddleware()` 合并进**根应用与 webman-admin 插件**的每一条路由
 * （`$withGlobalMiddleware` 默认 true）。登录/登出/账号等插件操作只有
 * 在这里才能留痕；过滤规则见 {@see AccessLog::shouldSkip}。
 *
 * @return array<string, list<class-string>>
 */
return [
    '@' => [
        AccessLog::class,
    ],
];
