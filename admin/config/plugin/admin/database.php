<?php
/**
 * `plugin.admin.database` 的镜像。
 *
 * 为什么需要这一份：
 * webman/admin 内部按 `config('plugin.admin.database...')` 读取连接信息
 * （`plugin/admin/app/controller/Crud.php:92` 取 prefix、
 *   `plugin/admin/app/controller/AccountController.php:255` 用其存在性判定「是否已安装」，
 *   为空时会抛 `请重启webman`）。
 * 而 webman/database 的 `Initializer::init(config('database'))`（`src/Initializer.php:113`）
 * 只认根 `config('database')`。两处必须同源，故此处直接 require 根配置，杜绝手工复制漂移。
 *
 * 注意：**不要**把连接信息写死在这里。改连接只改 .env，两个键自动同步。
 */

return require config_path() . '/database.php';
