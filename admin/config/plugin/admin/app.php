<?php
/**
 * webman/admin 插件配置的**项目侧覆盖入口**。
 *
 * 为什么必须有这个文件：
 * `Config::loadFromDir()` 对「相对 config/ 的嵌套路径」要求同级存在 app.php 且 `enable` 为真，
 * 才会把 `config/plugin/admin/*.php` 注册为 `plugin.admin.*`。缺了它，
 * `config/plugin/admin/database.php` 会被**静默忽略**。
 *
 * 覆盖顺序（已核 `vendor/workerman/webman-framework/src/support/App.php:155-165`）：
 *   1) 先 `Config::load(config_path())` —— 递归加载 config/，含本目录 → 得到 plugin.admin.database
 *   2) 后 `Config::load('plugin/admin/config', ..., 'plugin.admin')` —— 插件默认值**后加载**
 *      ⚠ 因此插件自带的同名文件会覆盖本目录。插件当前**没有** database.php，
 *      故本覆盖生效。**切勿**再手工创建 `plugin/admin/config/database.php`（后台 Web 安装页
 *      会创建它，届时本覆盖将失效）。
 *
 * 这里只放「必须覆盖 / 必须存在」的键，不放业务配置。
 */
return [
    'enable' => true,
];
