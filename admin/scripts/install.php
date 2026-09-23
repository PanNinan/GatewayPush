#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * GatewayPush 管理后台 —— 安装 / 初始化脚本（幂等，可重复执行）
 *
 * 与 webman-admin 官方安装页（访问 /app/admin → InstallController::step1/step2）的差异：
 *   官方安装页把「已安装」标记写在 plugin/admin/config/database.php（插件目录，会被
 *   `composer update webman/admin` 覆盖），且要求 wa_* 表**不存在**（已存在会让 step1 直接
 *   报「表已存在」并要求强制覆盖 = DROP TABLE）。本脚本面向「配置进版本库 + 手工建库」的
 *   部署方式，因此：
 *   - 连接参数只来自 .env（ADMIN_DB_*），不落明文到插件目录；
 *   - 全部语句幂等（SQL 用 IF NOT EXISTS / INSERT IGNORE，节点与角色按 key / name upsert）。
 *
 * 做的事（按顺序）：
 *   0. 确保 plugin/admin/config/database.php 存在（缺失会导致后台跳「安装页」）
 *   1. 连通性与版本自检
 *   2. 建/校验 wa_* 七张表（复刻 plugin/admin/install.sql）
 *   3. 把 plugin/admin/config/menu.php 导入 wa_rules（插件的菜单与权限节点树）
 *   4. 建后台自有四表（database/001_gw_tables.sql）
 *   5. 建初始超管账号（.env 的 ADMIN_BOOTSTRAP_USER / ADMIN_BOOTSTRAP_PASS），绑定角色 id=1
 *   6. 建 GatewayPush 权限节点 + 「运维 / 只读」两角色
 *
 * 用法：
 *   php admin/scripts/install.php
 */

$root = dirname(__DIR__);
$sep = str_repeat('-', 78);

function out(string $msg = ''): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}

function fail(string $msg): void
{
    fwrite(STDERR, '[FAIL] ' . $msg . PHP_EOL);
    exit(1);
}

// ---------------------------------------------------------------------------
// 引导：autoload + .env
// ---------------------------------------------------------------------------
if (!is_file($root . '/vendor/autoload.php')) {
    fail("未找到 vendor/autoload.php，请先在 admin/ 下执行 composer install");
}
require $root . '/vendor/autoload.php';

if (!is_file($root . '/.env')) {
    fail("未找到 admin/.env，请先复制 .env.example 为 .env 并填写 ADMIN_DB_*");
}
if (!class_exists(\Dotenv\Dotenv::class)) {
    fail("缺少 vlucas/phpdotenv：composer require vlucas/phpdotenv");
}
// createUnsafeMutable + safeLoad：与框架 support/bootstrap.php:45-49 的行为保持一致
\Dotenv\Dotenv::createUnsafeMutable($root)->safeLoad();

// ---------------------------------------------------------------------------
// 步骤 0：确保「已安装标记」文件存在
// ---------------------------------------------------------------------------
out($sep);
out('步骤 0 / 7  安装标记文件');
$markerFile = $root . '/plugin/admin/config/database.php';
// ⚠ 本文件**由脚本全量覆写**（不做「已存在则跳过」）：
//   它只是转发，手工定制没有意义，而一旦手改内容与脚本内置版本漂移，
//   排查成本远高于覆写风险。
//   ⚠ 写 PHP 块注释时切勿出现形如 `plugin/*/config/` 的路径 —— 其中的 `*/`
//     会提前闭合注释，后半句变成代码，报 `unexpected identifier "config"`。
$marker = <<<'PHP'
<?php
/**
 * webman-admin 的「已安装标记」+ 插件侧数据库配置。
 *
 * 由 admin/scripts/install.php 生成，**请勿手工修改**（脚本每次运行都会覆写）。
 * 内容只做转发，真正的连接参数只存在于版本库内的 config/database.php（读 .env）。
 *
 * 为什么必须存在：plugin/admin/app/controller/IndexController.php 用它的存在性
 * 判定「是否已安装」，缺失时后台根路径会渲染安装页。
 */

return require config_path() . '/database.php';

PHP;
$markerExisted = is_file($markerFile);
if ($markerExisted && file_get_contents($markerFile) === $marker) {
    out('  已存在且内容一致，跳过');
} else {
    if (!is_dir(dirname($markerFile)) || file_put_contents($markerFile, $marker) === false) {
        fail("无法写入 {$markerFile}");
    }
    out($markerExisted
        ? '  已覆写为脚本内置版本（消除手工改动与脚本的漂移）'
        : '  已生成 plugin/admin/config/database.php（转发到 config/database.php）');
}

// ---------------------------------------------------------------------------
// 步骤 1：连通性与版本自检
// ---------------------------------------------------------------------------
out($sep);
out('步骤 1 / 7  数据库连通性');
$host = (string)(getenv('ADMIN_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('ADMIN_DB_PORT') ?: 3306);
$name = (string)(getenv('ADMIN_DB_NAME') ?: 'gateway_push_admin');
$user = (string)(getenv('ADMIN_DB_USER') ?: '');
$pass = (string)(getenv('ADMIN_DB_PASS') ?: '');
if ($user === '') {
    fail('admin/.env 缺少 ADMIN_DB_USER');
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]
    );
} catch (Throwable $e) {
    fail(sprintf('连接失败 %s:%d/%s → %s', $host, $port, $name, $e->getMessage()));
}

$version = (string)$pdo->query('select version()')->fetchColumn();
$collation = (string)$pdo->query(
    "select default_collation_name from information_schema.schemata where schema_name = database()"
)->fetchColumn();
printf("  已连接 %s:%d/%s   MySQL %s   库排序规则 %s%s", $host, $port, $name, $version, $collation, PHP_EOL);
if ($collation !== 'utf8mb4_general_ci') {
    out("  ⚠ 库排序规则不是 utf8mb4_general_ci —— 与 wa_* 表（install.sql 写死 general_ci）");
    out("    混排会在跨表 JOIN / UNION 时抛 \"Illegal mix of collations\"，建议：");
    out("    ALTER DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;");
}

// ---------------------------------------------------------------------------
// 步骤 2：wa_* 七张表
// ---------------------------------------------------------------------------
out($sep);
out('步骤 2 / 7  webman-admin 自带表（wa_*）');
$waSql = $root . '/plugin/admin/install.sql';
if (!is_file($waSql)) {
    fail("缺少 {$waSql}（plugin/ 可能未安装，先 composer require webman/admin）");
}
// ⚠ install.sql 本身**不幂等**：wa_options / wa_roles 用的是裸 INSERT + 固定主键，
//   官方安装器只在空库上跑一次（重复执行会 1062 Duplicate entry）。
//   故此处按「表是否齐全」决定是否执行，并在执行前把 INSERT 改写为 INSERT IGNORE，
//   以容忍「部分表已建、种子行已存在」的场景。
$waTables = ['wa_admins', 'wa_admin_roles', 'wa_roles', 'wa_rules', 'wa_options', 'wa_users', 'wa_uploads'];
$existingTables = array_map('strval', $pdo->query('show tables')->fetchAll(PDO::FETCH_COLUMN));
$missingTables = array_values(array_diff($waTables, $existingTables));
if (!$missingTables) {
    out('  wa_* 七表已全部存在，跳过 install.sql');
} else {
    $sql = (string)file_get_contents($waSql);
    $sql = preg_replace('/\bINSERT\s+INTO\b/i', 'INSERT IGNORE INTO', $sql) ?? $sql;
    $pdo->exec($sql);
    printf('  已执行 install.sql，补齐 %d 张表：%s%s', count($missingTables), implode(', ', $missingTables), PHP_EOL);
}

// ---------------------------------------------------------------------------
// 步骤 3：导入插件菜单 → wa_rules
// ---------------------------------------------------------------------------
out($sep);
out('步骤 3 / 7  导入插件菜单树 → wa_rules');

$now = date('Y-m-d H:i:s');

/**
 * 按 key upsert 一条 wa_rules 记录，返回其 id。
 *
 * @param array<string, mixed> $node
 */
function upsertRule(PDO $pdo, array $node, int $pid, string $now): int
{
    $stmt = $pdo->prepare('SELECT id FROM wa_rules WHERE `key` = ? LIMIT 1');
    $stmt->execute([$node['key']]);
    $exists = $stmt->fetchColumn();

    $fields = [
        'title' => (string)($node['title'] ?? ''),
        'icon' => (string)($node['icon'] ?? ''),
        'key' => (string)$node['key'],
        'pid' => $pid,
        'href' => (string)($node['href'] ?? ''),
        'type' => (int)($node['type'] ?? 1),
        'weight' => (int)($node['weight'] ?? 0),
    ];

    if ($exists !== false) {
        $set = implode(', ', array_map(static fn (string $c): string => "`{$c}` = :{$c}", array_keys($fields)));
        $stmt = $pdo->prepare("UPDATE wa_rules SET {$set}, `updated_at` = :updated_at WHERE `id` = :id");
        $stmt->execute($fields + ['updated_at' => $now, 'id' => $exists]);
        return (int)$exists;
    }

    $fields['created_at'] = $now;
    $fields['updated_at'] = $now;
    $cols = implode(', ', array_map(static fn (string $c): string => "`{$c}`", array_keys($fields)));
    $ph = implode(', ', array_map(static fn (string $c): string => ":{$c}", array_keys($fields)));
    $stmt = $pdo->prepare("INSERT INTO wa_rules ({$cols}) VALUES ({$ph})");
    $stmt->execute($fields);
    return (int)$pdo->lastInsertId();
}

/**
 * 递归导入 menu.php 形态的菜单树（与 InstallController::importMenu 语义一致）。
 *
 * @param array<int|string, mixed> $tree
 */
function importMenuTree(PDO $pdo, array $tree, int $pid, string $now): void
{
    if (is_numeric(key($tree)) && !isset($tree['key'])) {
        foreach ($tree as $item) {
            if (is_array($item)) {
                importMenuTree($pdo, $item, $pid, $now);
            }
        }
        return;
    }
    /** @var array<string, mixed> $tree */
    $id = upsertRule($pdo, $tree, $pid, $now);
    foreach (($tree['children'] ?? []) as $child) {
        if (is_array($child)) {
            importMenuTree($pdo, $child, $id, $now);
        }
    }
}

/** @var array<int|string, mixed> $menus */
$menus = include $root . '/plugin/admin/config/menu.php';
importMenuTree($pdo, $menus, 0, $now);
$ruleTotal = (int)$pdo->query('select count(*) from wa_rules')->fetchColumn();
printf("  菜单树导入完成，wa_rules 现有 %d 个节点%s", $ruleTotal, PHP_EOL);

// ---------------------------------------------------------------------------
// 步骤 4：后台自有四表
// ---------------------------------------------------------------------------
out($sep);
out('步骤 4 / 7  后台自有表（push_task / push_template / admin_audit_log / admin_settings）');
$gwSql = $root . '/database/001_gw_tables.sql';
if (!is_file($gwSql)) {
    fail("缺少 {$gwSql}");
}
$pdo->exec((string)file_get_contents($gwSql));
foreach (['push_task', 'push_template', 'admin_audit_log', 'admin_settings'] as $t) {
    $n = (int)$pdo->query("select count(*) from `{$t}`")->fetchColumn();
    printf("  %-18s %d 行%s", $t, $n, PHP_EOL);
}

// ---------------------------------------------------------------------------
// 步骤 5：初始超管账号
// ---------------------------------------------------------------------------
out($sep);
out('步骤 5 / 7  初始超级管理员');
$bootstrapUser = (string)(getenv('ADMIN_BOOTSTRAP_USER') ?: 'admin');
$bootstrapPass = (string)(getenv('ADMIN_BOOTSTRAP_PASS') ?: '');
$adminCount = (int)$pdo->query('select count(*) from wa_admins')->fetchColumn();

if ($adminCount > 0) {
    printf("  wa_admins 已有 %d 个账号，跳过（如需重置见 admin/docs/deploy.md）%s", $adminCount, PHP_EOL);
} else {
    if ($bootstrapPass === '') {
        fail('wa_admins 为空且 .env 未设置 ADMIN_BOOTSTRAP_PASS —— 请先设置初始超管密码');
    }
    $stmt = $pdo->prepare(
        'insert into wa_admins (username, password, nickname, created_at, updated_at) '
        . 'values (:username, :password, :nickname, :created_at, :updated_at)'
    );
    $stmt->execute([
        'username' => $bootstrapUser,
        'password' => password_hash($bootstrapPass, PASSWORD_DEFAULT), // 与 plugin\admin\app\common\Util::passwordHash 一致
        'nickname' => '超级管理员',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $adminId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare('insert into wa_admin_roles (role_id, admin_id) values (1, :admin_id)');
    $stmt->execute(['admin_id' => $adminId]);

    printf("  已创建超管 %s（id=%d），并绑定角色 1（超级管理员）%s", $bootstrapUser, $adminId, PHP_EOL);
    out('  ⚠ 密码来自 admin/.env 的 ADMIN_BOOTSTRAP_PASS，首次登录后请立即修改');
}

// ---------------------------------------------------------------------------
// 步骤 6：GatewayPush 权限节点 + 运维 / 只读角色
// ---------------------------------------------------------------------------
out($sep);
out('步骤 6 / 7  GatewayPush 权限节点与角色');
out('  （权限判定见 plugin/admin/api/Auth::canAccess：wa_rules.key = {控制器全类名}[@{action}]，');
out('    wa_roles.rules = 逗号分隔的 rule id 列表，"*" = 全权）');

// 分组：菜单容器（type=0）
$group = upsertRule($pdo, [
    'title' => 'GatewayPush',
    'icon' => 'layui-icon-chart-screen',
    'key' => 'gatewaypush',
    'href' => '',
    'type' => 0,
    'weight' => 500,
], 0, $now);

// 菜单（type=1，出现在左侧菜单）与按钮级权限点（type=2，仅作权限）
$nodeSpecs = [
    'dashboard' => ['title' => '健康总览', 'key' => 'app\\controller\\DashboardController', 'href' => '/dashboard', 'type' => 1, 'weight' => 100],
    // 会话查询页（P2）。**key 必须是控制器全类名，不带 @action** ——
    // Auth::canAccess 对 action=index 的匹配规则是「任意以 {控制器}@ 开头的 key，或 key 恰等于 {控制器}」，
    // 故页面路由只需这一个节点即可放行；用 @index 亦可，但菜单节点（type=1）按惯例不带 action。
    // ⚠ 漏登记此节点的症状是「登录后点菜单 403」，而不是白屏 —— 极易被误判成路由写错。
    'sessions' => ['title' => '会话查询', 'key' => 'app\\controller\\SessionController', 'href' => '/sessions', 'type' => 1, 'weight' => 92],
    'mon.live' => ['title' => '实时快照（API）', 'key' => 'app\\controller\\api\\MonitorController@live', 'href' => '', 'type' => 2, 'weight' => 95],
    'mon.summary' => ['title' => '指标聚合（API）', 'key' => 'app\\controller\\api\\MonitorController@summary', 'href' => '', 'type' => 2, 'weight' => 90],
    'mon.health' => ['title' => '健康探测（API）', 'key' => 'app\\controller\\api\\MonitorController@health', 'href' => '', 'type' => 2, 'weight' => 80],
    'ops.scan' => ['title' => 'Redis 键巡检（API）', 'key' => 'app\\controller\\api\\OpsController@redisScan', 'href' => '', 'type' => 2, 'weight' => 70],
    // 密钥状态属敏感展示（设计文档 §3.4 的 admin.config.secret.view「默认关，需单独授」），
    // 故只进运维角色，不进只读角色。
    'ops.probe' => ['title' => 'API 与密钥状态（API）', 'key' => 'app\\controller\\api\\OpsController@apiProbe', 'href' => '', 'type' => 2, 'weight' => 60],
    // ---- M2 会话只读（P2）----
    // 全部是只读端点，按 §6「只读角色仅 *.view 类」的口径同时授予「只读」与「运维」。
    // 其中 revoked 只是**不可逆的 Token 指纹**（sha256 前 32 位，服务端不存 Token 原文），
    // 与 §6 里刻意只给超管的 `admin.config.secret.view`（密钥状态）不同级别，
    // 故不按敏感项处理；若日后判定要收紧，只需把下面一行从 $viewerRules 里摘掉。
    'sess.list' => ['title' => '会话列表（API）', 'key' => 'app\\controller\\api\\SessionController@index', 'href' => '', 'type' => 2, 'weight' => 55],
    'sess.detail' => ['title' => '会话详情（API）', 'key' => 'app\\controller\\api\\SessionController@detail', 'href' => '', 'type' => 2, 'weight' => 54],
    'sess.byUid' => ['title' => '按 uid 反查（API）', 'key' => 'app\\controller\\api\\SessionController@byUid', 'href' => '', 'type' => 2, 'weight' => 53],
    'sess.byDevice' => ['title' => '按设备反查（API）', 'key' => 'app\\controller\\api\\SessionController@byDevice', 'href' => '', 'type' => 2, 'weight' => 52],
    'sess.offline' => ['title' => '离线队列只读（API）', 'key' => 'app\\controller\\api\\SessionController@offline', 'href' => '', 'type' => 2, 'weight' => 51],
    'sess.subs' => ['title' => '订阅关系（API）', 'key' => 'app\\controller\\api\\SessionController@subscriptions', 'href' => '', 'type' => 2, 'weight' => 50],
    'auth.revoked' => ['title' => 'Token 撤销名单（API）', 'key' => 'app\\controller\\api\\SessionController@revoked', 'href' => '', 'type' => 2, 'weight' => 49],
];
$nodeIds = [];
foreach ($nodeSpecs as $alias => $spec) {
    $nodeIds[$alias] = upsertRule($pdo, $spec, $group, $now);
    printf("  %-12s id=%-4d %s%s", $alias, $nodeIds[$alias], $spec['key'], PHP_EOL);
}

/**
 * 按 name upsert 角色，rules 用 rule id 列表覆写。
 *
 * @param list<int> $ruleIds
 */
function upsertRole(PDO $pdo, string $name, array $ruleIds, string $now): int
{
    $rules = implode(',', $ruleIds);
    $stmt = $pdo->prepare('SELECT id FROM wa_roles WHERE `name` = ? LIMIT 1');
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        $stmt = $pdo->prepare('UPDATE wa_roles SET `rules` = :rules, `updated_at` = :now WHERE `id` = :id');
        $stmt->execute(['rules' => $rules, 'now' => $now, 'id' => $id]);
        return (int)$id;
    }
    // ⚠ EMULATE_PREPARES=false 时，原生预处理不支持**同名占位符出现多次**，
    //   所以 created_at / updated_at 必须用两个不同的占位符。
    $stmt = $pdo->prepare(
        'INSERT INTO wa_roles (`name`, `rules`, `created_at`, `updated_at`, `pid`) '
        . 'VALUES (:name, :rules, :created_at, :updated_at, NULL)'
    );
    $stmt->execute(['name' => $name, 'rules' => $rules, 'created_at' => $now, 'updated_at' => $now]);
    return (int)$pdo->lastInsertId();
}

// 「只读」角色必须包含 mon.live —— 面板的 5s 快 tick 走的就是它；
// 漏掉会让只读账号打开面板后永远停在骨架（且只在浏览器控制台报 403，极易漏查）。
$viewerRules = [
    $nodeIds['dashboard'],
    // P2 会话查询页：页面本身不含写操作（无 kick / revoke / unbind / 清队列入口），
    // 故与 dashboard 同级归入只读角色。真正需要收紧的是 P4 的写端点，不是这一页。
    $nodeIds['sessions'],
    $nodeIds['mon.live'],
    $nodeIds['mon.summary'],
    $nodeIds['mon.health'],
    // P2 会话只读：同样是「只读角色可见」的一类（见上方 nodeSpecs 的注释）。
    $nodeIds['sess.list'],
    $nodeIds['sess.detail'],
    $nodeIds['sess.byUid'],
    $nodeIds['sess.byDevice'],
    $nodeIds['sess.offline'],
    $nodeIds['sess.subs'],
    $nodeIds['auth.revoked'],
];
$operatorRules = array_merge($viewerRules, [$nodeIds['ops.scan'], $nodeIds['ops.probe']]);

$viewerId = upsertRole($pdo, '只读', $viewerRules, $now);
$operatorId = upsertRole($pdo, '运维', $operatorRules, $now);
printf("  角色「只读」 id=%d  rules=%s%s", $viewerId, implode(',', $viewerRules), PHP_EOL);
printf("  角色「运维」 id=%d  rules=%s%s", $operatorId, implode(',', $operatorRules), PHP_EOL);
out('  角色「超级管理员」 id=1  rules=* （install.sql 自带）');

// ---------------------------------------------------------------------------
// 报告
// ---------------------------------------------------------------------------
out($sep);
out('步骤 7 / 7  结果汇总');
$tables = $pdo->query('show tables')->fetchAll(PDO::FETCH_COLUMN);
printf("  库 %s  共 %d 张表：%s%s", $name, count($tables), implode(' ', $tables), PHP_EOL);
$roleRows = $pdo->query('select id, name, rules from wa_roles order by id')->fetchAll(PDO::FETCH_ASSOC);
foreach ($roleRows as $r) {
    printf("  role #%-2s %-8s rules=%s%s", $r['id'], $r['name'], $r['rules'], PHP_EOL);
}
out('');
out('完成。启动后台：');
out('  cd admin && php start.php start          （前台，Windows 下 Ctrl+C 会整体停止）');
out('  或 bin/start.* 统一编排（后台暂为独立进程，未纳入主项目 6 角色）');
out('  访问 http://127.0.0.1:8292/app/admin   登录后侧栏可见「GatewayPush → 健康总览」');
out($sep);
