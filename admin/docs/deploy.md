# GatewayPush 管理后台 —— 部署与运维

> 本文件是 P0 阶段交付物之一（设计文档 §0.4 的 A4 条款）。
> 对应设计：`docs/GatewayPush WebmanAdmin 管理后台详细设计.md`

---

## 1. 定位与边界

| 项 | 值 |
|---|---|
| 形态 | 独立 webman 应用，位于仓库内 `admin/` 子目录（类比 `client/`） |
| 监听 | `127.0.0.1:8292`（**仅回环**，上游无 TLS） |
| 进程 | 与 GatewayPush 的 6 个角色**不同进程、不同框架入口**，崩溃互不影响 |
| 存储 | 自己的 MySQL 库 `gateway_push_admin`（**只读**主项目 Redis） |
| 编排 | **不进** `bin/start.*` 的 6 角色编排，独立启停 |

**关键不变量：推送系统的运行不依赖本后台。** 后台宕机、MySQL 不可用，都不影响 `GatewayPush` 的
register / gateway / udp / business / api / dashboard 六个角色。

**已交付页面**（每期追加，权限节点同步见 §3.2）：

| 阶段 | 页面 | 主要 API |
|---|---|---|
| P0 | `/dashboard` 健康总览 | `/api/monitor/*`、`/api/ops/redis/scan`、`/api/ops/api/probe` |
| P1 | （同上，改为分层轮询 + 自绘 SVG） | 新增 `/api/monitor/live`（5s 快 tick，**只碰 Redis**） |
| P2 | `/sessions` 会话查询 | `/api/sessions`、`/api/session/{clientId}`、`/api/sessions/by-uid/{uid}`、`/api/sessions/by-device/{deviceId}`、`/api/sessions/subscriptions`、`/api/sessions/offline/{uid}`、`/api/auth/revoked`（**全部 GET，一期只读**） |
| 2.0 | `/audit` 行为日志（读 `admin_audit_log`） | `GET /api/audit/logs`（只读检索；替代已废弃的示例 demo 树） |
| 2.0 | `/access-log` 访问日志（读 `wa_admin_log`） | `GET /api/access-logs`（登录/登出/页面与接口访问；与 `/audit` 并列） |

---

## 2. 前置条件

| 依赖 | 要求 | 核验命令 |
|---|---|---|
| PHP | 8.2 ~ 8.5（**禁用 8.3+ 语法**） | `php -v` |
| 扩展 | `gd` `fileinfo` `pdo_mysql` `curl` `mbstring` `openssl` `sockets` `xml` `zip` | `php -m` |
| Redis 扩展 | 默认 **phpredis**（`ADMIN_REDIS_CLIENT`，需 `extension=redis`）；无扩展环境改回 `predis` 纯 PHP 客户端 | `php -m \| grep -i ^redis$` |
| MySQL | 实测 **8.0.46**（DDL 兼容 5.7+）；库与表排序规则**必须** `utf8mb4_general_ci` | `select version()` |
| Redis | 与主项目**同一实例、同一 DB、同一前缀** | 见 §4 第 2 条 |

> ⚠ `gd` + `fileinfo` 是硬需求：webman/admin 的验证码（`webman/captcha`）与头像上传
> （`intervention/image`）都依赖它们。缺失会在登录页直接报错。

---

## 3. 首次部署

```bash
cd admin

# 1) 依赖
composer install

# 2) 配置（.env 已在 .gitignore 中）
cp .env.example .env
#   编辑 .env：
#     ADMIN_DB_USER / ADMIN_DB_PASS       专用账号（勿用 root）
#     ADMIN_BOOTSTRAP_PASS                首个超管密码（wa_admins 非空后即失效）
#     ADMIN_API_SECRET                    可留空 → 服务端回退 AUTH_SECRET
#     ADMIN_REDIS_DB / ADMIN_REDIS_PREFIX 必须与主项目一致
#   ⚠ 含空格的值必须加双引号

# 3) 建库 + 建号（一次性，用 root 执行；之后后台只用专用账号）
#    CREATE DATABASE `gateway_push_admin`
#      DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
#    CREATE USER 'gw_admin'@'127.0.0.1' IDENTIFIED BY '<强密码>';
#    GRANT ALL PRIVILEGES ON `gateway_push_admin`.* TO 'gw_admin'@'127.0.0.1';
#   ⚠ 排序规则必须是 utf8mb4_general_ci（与 wa_* 表同源，否则跨表 JOIN 报混排错误）

# 4) 迁移 + 初始化（幂等，可反复执行）
php scripts/install.php

# 5) 启服务前**先确认 8292 空闲**（勿跳过：Windows 不拒绝重复 bind，
#    两套实例叠加后表现为「偶发失败」而不是任何报错）
netstat -a -n | grep -E ":8292 .*LISTENING" || echo "8292 空闲"

# 6) 启动
php windows.php              # Windows：前台常驻 + 文件变更热重载
php start.php start          # Linux：生产可加 -d 守护
php start.php stop           # Linux 停止
```

访问 `http://127.0.0.1:8292/app/admin` 登录（访问 `/` 会 302 到此处）。

> ⚠ **Windows 上 `php start.php start` 会直接退出**并提示
> `Please run 'windows.php' on windows system.` —— workerman 5.x 在 Windows 只支持 `windows.php`。
> ⚠ 停服务**不要**用主项目的 `bin\start.bat stop` —— 两者是不同应用，脚本不互通。

### 3.1 `scripts/install.php` 做了什么

| 步 | 动作 |
|---|---|
| 0 | 重建 `plugin/admin/config/database.php`（**转发**到 `config/database.php`；缺失会让后台跳「安装页」） |
| 1 | 连通性 + 库排序规则自检（非 `utf8mb4_general_ci` 时打印 `ALTER DATABASE` 建议） |
| 2 | `wa_*` 七表（复刻 `plugin/admin/install.sql`；按「表是否齐全」决定是否执行，并把裸 `INSERT` 改写为 `INSERT IGNORE`） |
| 3 | 把 `plugin/admin/config/menu.php` 导入 `wa_rules`（插件自带的菜单 / 权限树） |
| 3b | 删除废弃菜单子树（`$obsoleteMenuRoots = ['demos']`；import 只 upsert 不 delete，**且 `menu.php` 是 vendor 副本、composer install 会带回 demos**，故清理真源在此步） |
| 4 | 后台自有表：`database/001_gw_tables.sql`（`push_task` / `push_template` / `admin_audit_log` / `wa_admin_log` / `admin_settings`） |
| 5 | 首个超管（取 `.env` 的 `ADMIN_BOOTSTRAP_USER` / `ADMIN_BOOTSTRAP_PASS`；`wa_admins` 非空则跳过） |
| 6 | GatewayPush 权限节点 **44 个**（含页面 + API；`$nodeSpecs` 全量）+ 「运维 / 只读」两角色（按 `wa_rules.key` 与 rule id 列表 upsert） |

**为什么不走官方 Web 安装页**：①它的「已安装」标记写在插件目录内，`composer update webman/admin` 会冲掉；
②表已存在时它会要求「强制覆盖（`DROP TABLE`）」；③它会把明文连接参数写进插件目录。
完整依据见设计文档 §11 的 D10 / D13。

### 3.2 管理员账号与角色

| 角色 | `wa_roles.id` | `rules` | 能访问 |
|---|---|---|---|
| 超级管理员 | 1 | `*` | 全部（含 webman-admin 自带的管理面） |
| 运维 | 3 | 41 个节点 | GatewayPush 全部页面与端点（含 `/push` 发起推送 · 模板增删改 · `/actions` 动作调试 · `/audit` 行为日志 · `/access-log` 访问日志） |
| 只读 | 2 | 23 个节点 | `/dashboard`、`/sessions`、`/push`（**仅**历史 + 模板查看）、`/metrics`、`/trace`、`/audit`、`/access-log`、`/api/monitor/*`（含 `mon.live`）、`/api/sessions/*`、`/api/session/*`、`/api/auth/revoked`、`/api/push/history`、`/api/push/templates`（GET）、`/api/metric/*`、`/api/audit/logs`、`/api/access-logs`；`/api/ops/*`、`/api/push`（POST）、`/api/action/*` 与模板写操作返回 **403** |

> 角色节点数以 `scripts/install.php` 的 `$viewerRules` / `$operatorRules` 为准
> （当前只读 **23**、运维 **41**；p3/p4 验收脚本有硬断言，改节点必须同步）。
> 计数可用 `SELECT id,name,rules FROM wa_roles` 复核。节点清单的**唯一真源**是
> `scripts/install.php` 的 `$nodeSpecs`，重跑该脚本即幂等对齐（id 不变、只更新 title/key/weight）。
> 只读 = 监测 / 查询 / 审计检索；运维 = 只读之上再加写路径与自检面（见 `$operatorRules`）。
>
> ⚠ **新增阶段后必须重跑 `php scripts/install.php`** —— 节点只存在于该脚本里，不进 DB 则
> 非超管角色一律 403。反之 **P2/P3 的验收脚本只做静态核对**（读 `install.php` 源码），
> **不会发现 DB 落后**（本机实测：P3 落地时 `wa_rules` 仍只有 P1 的 5 个节点）。

- **新建管理员**：登录后走「权限管理 → 账户管理」（webman-admin 自带页面）。
  命令行创建也可，但**没有**免验证码的创建接口。
- **权限节点形态是 `{控制器全类名}[@{action}]`，不是语义键**
  （`plugin/admin/api/Auth.php::canAccess`）—— 新增端点时必须同步往 `wa_rules` 加节点，
  否则只读/运维角色访问会被 403，而超管因 `rules='*'` 察觉不到。
  ⚠ **典型症状是「静默」的**：漏加节点时仪表盘页面**不报错**，只是永远停在骨架
  （所有卡片都是 `—`、趋势显示「采样中…」），**唯一的线索是浏览器控制台里一个 403**。
  P1 的 `mon.live`（`wa_rules` id **120**）就是这一类，`scripts/install.php` 已把它同时加进
  超管/运维/只读三个角色。
- **验证码**：登录**强制**校验（`session('captcha-login')`）。自动化脚本需先
  `GET /app/admin/account/captcha/login` 建 session，再从 `runtime/sessions/session_{PHPSID}`
  （file 驱动、PHP serialize）读明文。

---

## 4. 四条硬纪律（违反即静默故障）

### 1. `ADMIN_API_SECRET` 是本后台的**管理面凭证**，不得下发业务方

P4 起 `kick` / `revoke` / `unbind` 三个运维动作以 `channels => [http]` 开放，
**持 `API_SECRET`（或其回退值 `AUTH_SECRET`）者即可调用它们**。

这是 2026-09-23 的已决策项（设计文档 §0.4「选项 A」）。配套约束：

- 后台**只持** `ADMIN_API_SECRET`，**不得与任何业务调用方共享**；
- 后台**不得**提供「按调用方切换密钥」的入口（禁止多密钥池）；
- 密钥**只从环境变量注入**，不进 MySQL、不进日志、不进审计表的 `params` 字段（密钥类字段须脱敏/省略）。

升级触发条件（任一成立即须改用独立动作密钥 `X-Admin-Key`）见设计文档 §9.2-R11。

### 2. `ADMIN_REDIS_DB` 与 `ADMIN_REDIS_PREFIX` 必须与主项目一致

本机主项目为 `REDIS_DB=9`（DB0 有历史残留 `gwpush:*` 键）+ `REDIS_PREFIX=gwpush:`。

**配错的表现是「读到空数据」而不是报错** —— 页面照常渲染，只是所有数字都是 0，
运维会误判成「系统没有流量」。因此：

```bash
# 用自检兜底（预期键存在性）
cd admin && php tests/Manual/p0_acceptance.php
```

自检会逐个报告 `metrics:gauge` / `online:clients` / `metrics:counter:{今天}` 的存在性、类型与 TTL。
**若 `metrics:gauge` 与 `online:clients` 都不存在**，几乎必然是 DB 或 PREFIX 配错。

### 3. 不做 `.env` 热重载编辑器

主项目 6 个角色均为常驻进程、在 `onWorkerStart` 一次性加载配置，**无热重载**。
后台只提供「只读视图 + 变更引导」（改哪个键 → `.env` 第几行 → 改完重启哪些角色 → 验证命令），
**不代写文件、不代重启**。在线编辑 `.env` 会造成「以为生效了」的假象。

### 4. 新增控制器 / 路由必须同时补两处，否则就是一个**免鉴权入口**

webman 的**默认路由**会把 `/controller/action` 直接解析到控制器的公有动作，
这条路径**完全不经过** `config/route.php` 上挂的 `AdminAuth`
（判定见 `vendor/workerman/webman-framework/src/App.php:172-182`）。
**只要默认路径与显式路径不是同一个 URL，显式路由上的鉴权就是空的。**

历史实证（2026-09-23，两次）：

| 控制器 | 显式路径 | 默认路径（曾免鉴权） |
|---|---|---|
| `api\OpsController` | `/api/ops/redis/scan` | `/api/ops/redisScan`（另含 kebab 变体 `/api/ops/redis-scan`） |
| `api\OpsController` | `/api/ops/api/probe` | `/api/ops/apiProbe` |
| `DashboardController` | `/dashboard` | `/dashboard/index`（**整页 HTML**） |
| `IndexController`（webman **脚手架**自带，本项目从未注册） | — | `/index/index`、`/index/view`、**`/index/json`** —— **2026-09-24 已彻底删除**（控制器 + `app/view/index/`） |

`/index/json` 最危险：它返回 `{"code":0,"msg":"ok"}` —— 与本项目成功响应**形状一致**，
未鉴权即可拿到一个「看起来成功」的响应，会误导健康探针与扫描器；`/index/index` 则内嵌
`workerman.net` 欢迎页 iframe，白送一条指纹。

**规矩**（两条都要做，缺一即洞）：

1. 在 `config/route.php` 的 `disableDefaultRoute` 段**补一行** `Route::disableDefaultRoute(X::class);`；
2. 新路由**必须**挂在 `AdminAuth` 上 —— 页面路由写成
   `Route::get('/x', [XController::class, 'index'])->middleware([AdminAuth::class]);`，
   API 路由写进 `Route::group('/api', …)->middleware([AdminAuth::class])` 组内。

⚠ **不得**一刀切 `Route::disableDefaultRoute()`：webman-admin 插件的 `/app/admin/*`
正是靠默认路由解析，全站禁用会让整个后台 UI 变 404。

这两条已由 **`tests/Unit/RouteGuardTest.php` 机器化守死**（6 用例；6 组变异体全部被抓）：

```bash
php vendor/bin/phpunit --filter RouteGuardTest   # 新增控制器/路由后必跑
```

> 顺带一条易漏的连带影响：脚手架控制器 2026-09-24 删除后，**根路径 `/` 已无默认路由归属**
> （原先恰好落在 `IndexController::index` 上）。故 `/` 必须由一条**显式**路由接管
> （本项目是 `Route::get('/', … redirect('/app/admin'))`），否则访问根路径会从 200 变成 404。
> `RouteGuardTest::testRootPathIsAnExplicitRedirect` 守着这一点；
> `testScaffoldWelcomeControllerIsRemoved` 则钉住「脚手架文件不得重新出现」。

---

## 5. 数据备份

后台自身的全部状态都在 MySQL（`wa_*` + 4 张自有表），**Redis 侧无后台自有数据**，
因此备份策略简单：

```bash
# 逻辑备份（库名按 .env 的 ADMIN_DB_NAME 调整）
mysqldump --single-transaction --routines --triggers \
          --default-character-set=utf8mb4 \
          -h "$ADMIN_DB_HOST" -P "$ADMIN_DB_PORT" -u "$ADMIN_DB_USER" -p \
          gateway_push_admin > "gwadmin-$(date +%Y%m%d-%H%M%S).sql"

# 恢复
mysql -h "$ADMIN_DB_HOST" -u "$ADMIN_DB_USER" -p gateway_push_admin < gwadmin-YYYYmmdd-HHMMSS.sql
```

| 项 | 建议 |
|---|---|
| 频率 | 每日一次（数据变更低频；主要是审计日志与推送历史在长大） |
| 保留 | 最近 30 天 |
| 异地 | 与 GatewayPush 主项目的备份同机位即可（二者无耦合，可独立恢复） |
| 特别说明 | `admin_audit_log` 是**审计证据**，恢复时不得单独丢弃该表 |

> ⚠ `plugin/admin/` 下的内容是 composer 包 `webman/admin` 的**纯副本**（每次
> `composer install` 由 `copy_dir` 重建），**已 gitignore**，不属于备份对象、也**不应手工修改**。
> 要覆盖插件配置，请用 `config/plugin/admin/*.php`。

---

## 6. 门禁

```bash
cd admin
composer test           # PHPUnit：tests/Unit（P3 后 201 tests / 1053 assertions）
composer analyse        # PHPStan L6，**刻意不引入 baseline**（新代码零容忍）
composer test:frontend  # 运行期前端渲染校验（dashboard 144 + session 162 + push 122 + action 103 + ops 42 + metrics 19 + trace 25 + audit 23 + accesslog 26 = **666 项**；无需浏览器 / jsdom / 服务端）
```

`composer test` 与 `composer test:frontend` **不可互相替代**：
前者是**静态契约**（`DashboardContractTest` / `SessionContractTest` / `PushContractTest` /
`ActionContractTest`：JS 引用的 DOM id 是否都在视图里、`cfg.*` 是否都由控制器注入、
有没有 `innerHTML` 赋值、**有没有定时器**（`/push` 零个、`/actions` 恰好一个 `setTimeout`）、
**读路径有没有 Redis 写命令**、写请求是否都用声明过的动词与 `cfg.` 前缀 URL），
后者是把 `dashboard.js` / `session.js` / `push.js` / `action.js` **真跑一遍**断言渲染结果
（卡片取值、派生率 `null ≠ 0.00%`、队列条宽、进程表格式化、日切清空、失败退避倍数、
`visibilitychange` 陈旧响应作废；会话侧则是截断回显、空态 vs 配错的区分、抽屉 URL 同步、
离线队列用**全局**下标、UTF-8 **字节**长度校验）。
**只跑前者只能证明「名字都对」，证明不了「渲染正确」。**
后端 API 契约改动另需两个验收脚本（含硬断言「`live()` 不含 `api` 段」「保留会话必须出现在 `scope=retained` 里」）：

```bash
php tests/Manual/p1_acceptance.php    # P1 验收：服务层(真连 Redis) / HTTP 层(curl+验证码登录) / RBAC
php tests/Manual/p2_acceptance.php    # P2 验收（只读，不写任何 Redis 键）
php tests/Manual/p2_acceptance.php --seed   # 额外写入 p2test-* 一次性夹具，跑完**无条件清理**
php tests/Manual/p3_acceptance.php    # P3 验收（默认只读；88 PASS / 0 FAIL）
php tests/Manual/p3_acceptance.php --seed   # 追加真实链路：落库 + 三条语义反证（**会真实投递消息**）
```

> ⚠ P2 的 `--seed` 有安全闸：`ADMIN_REDIS_HOST` **必须是回环地址**，否则以退出码 2 直接中止 ——
> 防止有人在连生产 Redis 的机器上跑夹具写入。该脚本（与 P3 的 `--seed`）是后台子项目里**仅有的**
> 会写 Redis / 真实投递的地方。
>
> ⚠ P3 的 `--seed` 会**真的向主项目发一条推送**（`p3test-uid` 目标）并写 `push_task` 夹具，
> 故默认不执行；夹具清理**只按 `p3test%` 前缀**删，绝不 `truncate`、也**不按 `operator_id`**
> （`operator_id = 0` 是合法的历史值）。

`tests/Manual/` 与 `tests/Frontend/` 下的脚本需真实服务在线（后者其实不需要），
**刻意不纳入套件**（判据口径与主项目一致：环境不可用时标 SKIP 而非 FAIL），
避免 CI 因主项目未启动而变红：

```bash
php tests/Manual/p0_acceptance.php    # P0 验收冒烟：配置 / Redis / API / 指标交叉比对
php tests/Manual/p1_acceptance.php    # P1 验收：服务层(真连 Redis) / HTTP 层(curl+验证码登录) / RBAC
```

---

## 7. 已知环境陷阱

### 7.1 环境变量 `http_proxy` 会破坏对主项目 API 的访问（已修复）

本机环境存在 `http_proxy=http://127.0.0.1:59666`。Guzzle（经 ext-curl）会遵循它，
把回环请求以「绝对形式 URI + `Proxy-Connection`」发给该代理，而该代理在
**复用连接的第 2 个请求**上返回 `400 Bad Request`。

现象极具迷惑性：同一 TCP 连接上 `200 / 400 / 200 / 400` 交替，
看起来像主项目 API 的长连接缺陷。**实际用裸 socket 直连 8290 连发 3 次全部 200，API 完全正常。**

已修复：`app/service/GatewayPushClient` 显式设置 Guzzle `'proxy' => ''`，保证永远直连。
**新增任何访问主项目的 HTTP 客户端时，必须同样处理。**

### 7.2 Windows 下 `cpu_count()` 会触发被安全策略拦截的程序

`config/process.php` 引用了 `cpu_count()`（webman 骨架默认）。该函数在 Windows 上会尝试调用
`wmic` / `reg.exe`；若这些程序被安全策略列入黑名单，会打印一条拦截提示。
**属正常现象，有回落值，不影响启动。**

### 7.3 `.env` 中含空格的值必须加引号

否则 phpdotenv 抛 `InvalidFileException: Encountered unexpected whitespace`：

```ini
ADMIN_ROLES_CMD="php ../start.php roles"   # 正确
```

### 7.4 PHP 块注释里出现 `*/` 会提前闭合注释（已踩）

注释里写通配路径 `plugin/*/config/` 时，其中的 `*/` 会把块注释**提前闭合**，后半句变成代码，
报 `Parse error: unexpected identifier "config"`。

本项目实际踩过：`plugin/admin/config/database.php` 因此语法错误，**并同时导致后台无法启动**
（`windows.php` 起不来）。注释里要写通配路径时改用 `{插件名}` 占位。

### 7.5 `PDO::ATTR_EMULATE_PREPARES = false` 时同名占位符不可重复（已踩）

```php
// ✗ 报 SQLSTATE[HY093] Invalid parameter number
'... VALUES (:now, :now, NULL)'
// ✓ 用两个不同名字
'... VALUES (:created_at, :updated_at, NULL)'
```

原生预处理不支持同一命名占位符出现多次（只有 emulated 模式才支持）。

### 7.6 其他「静默错误」清单

| 现象 | 真因 |
|---|---|
| 后台根路径显示**安装页** | `plugin/admin/config/database.php` 缺失（被 `composer update` 覆盖）→ 重跑 `php scripts/install.php` |
| 页面所有数字为 **0** | `ADMIN_REDIS_DB` / `ADMIN_REDIS_PREFIX` 与主项目不一致（不报错，只是读不到）→ 查 `GET /api/ops/redis/scan` |
| 「Redis 键总数」曾显示 **DB 0** | 早期版本误取主项目 `/health` 的 `db` 字段（该字段不存在）→ 已改为后台自读配置 |
| 提示「请重启webman」 | `config('plugin.admin.database')` 为空 → 与「安装页」同一根因 |
| `Class "Redis" not found` | `ADMIN_REDIS_CLIENT=phpredis`（默认）但未装 `extension=redis` → `php -m` 确认，或改回 `predis`；`client` 键必须在 `config/redis.php` **顶层**（放 `redis.default` 里会被静默忽略并回退 phpredis） |
| 只读/运维账号访问新端点一律 403 | 新增端点后忘了往 `wa_rules` 加权限节点（`{控制器全类名}@{action}`）；超管因 `rules='*'` 察觉不到 |

### 7.7 后台在 Windows 下**会**热重载（与主项目相反，别搞混）

**实测结论（2026-09-23）**：改 `admin/` 下的文件后，**不必重启后台** ——
`windows.php` 末端的 `while (1) { sleep(1); … }` 主循环每秒调一次
`$monitor->checkAllFilesChange()`，命中即 `taskkill /F /T` 杀死整个进程树并 `popen_processes()` 重生。
**延迟约 1~3 秒**，可观测特征是 **8292 的监听 PID 会变**（`netstat -ano -p TCP | grep 8292`）。

这里有个极易误判的点：`config/process.php` 的 `monitor.constructor.options.enable_file_monitor`
写的是 `!in_array('-d', $argv) && DIRECTORY_SEPARATOR === '/'` —— **Windows 下恒为 `false`**。
但那个选项只作用于 `Monitor::start()` 里 `Timer::add(1, …)` 的注册（Worker 侧），
而 `windows.php` **自己**在主循环里直接调 `checkAllFilesChange()`、**根本不看这个选项**。
⇒「Windows 无热重载」这个结论对**主项目**成立（它走 `bin\start.bat` + workerman 自身入口，
没有这层主循环），对**后台**不成立。

**反过来也是一颗雷**：正因为它会热重载，**临时改坏 `config/route.php` 不会有任何提示**，
1~3 秒后下一个请求就直接按坏配置服务（本次做「注掉禁用行」的反向对照时就是这样——
注掉后 `/index/json` 立刻变回未鉴权 200）。故**改完务必复验**，不要靠「没报错」判断生效。

监控目录见 `config/process.php` 的 `monitorDir`（含 `app/`、`config/`、`support/`、`.env`、插件目录），
后缀 `php|html|htm|env`。

---

## 8. 与主项目的关系速查

| 方向 | 通道 | 说明 |
|---|---|---|
| 读状态 | 直连 Redis（只读） | 键名一律经 `GatewayPush\Common\RedisKeys`，**禁止字面量** |
| 写操作 | `POST /action` → `queue:action:in` → business 执行 | 不直写 Redis（`kick` 只能在 business 进程内执行） |
| 存活探测 | `GET /health`（免签） | — |
| 指标快照 | `GET /stats`（需签）/ `GET http://127.0.0.1:8291/metrics.json`（免签，多 `meta` 段） | 面板新鲜度展示优先用后者 |
| 角色清单 | `php ../start.php roles`（JSON 契约） | **不得自行解析 `.env`** —— 后者只表示「配置是否开启」，不等于「进程是否在跑」 |

---

## 9. 仪表盘（P1）的数据流与两条红线

```
浏览器 /dashboard  ──(1) 只取一次骨架（无数据、无 <meta refresh>）
                    └─(2) /static/dashboard.js + dashboard.css（静态中间件，**无鉴权**）
                            ├─ 快 tick 5s  → GET /api/monitor/live      只碰 Redis，不碰主项目 HTTP
                            └─ 慢 tick 30s → GET /api/monitor/summary   selfCheck + dbsize + /health + /stats
```

- **红线 1：快 tick 里不许出现主项目 HTTP。** `GatewayPushClient` 的 `connect_timeout=3s`、总超时 `8s`，
  并进 5s 快 tick 会让面板「在主项目最慢的时候正好卡住」。`live()` 的返回值**刻意不含 `api` 段**，
  `tests/Manual/p1_acceptance.php` 已把这条钉成硬断言。
- **红线 2：`/static/*` 是无鉴权静态目录。** `dashboard.js` / `dashboard.css` 由 webman 静态中间件直接返回，
  **不得写入任何密钥或内网凭据**；页面数据一律经 `/api/*`（走 `AdminAuth`）取回。
  ⚠ `/api/monitor/nope` 这类未注册路由会返回 **HTTP 200** + `{"code":404,...}`（插件异常处理器包裹），
  因此**判成败一律看响应体 `code`，不看 HTTP 状态码**。

**调参**：`admin_settings` 里 `monitor.*` 改名/改值**无需重启后台**（`Settings` 有 5s 缓存）。
但 `monitor.gauge_stale_secs` 是**面板展示**判据（`MONITOR_INTERVAL × 2`，默认 10s），
**与主项目清理 gauge 残留用的 `MONITOR_TTL`（600s）刻意不同、不可互换** —— 混用会导致
「进程早已退出但面板仍显示存活」或反之。

**删了 `admin_settings` 里的 `monitor.ratio_thresholds` 也没事**：默认阈值只存在于
`MetricsDeriver::RATIOS` 一处，该键刻意留空 `{}`，非法值静默回落默认。

---

## 10. 推送管理与动作调试（P3）

```
浏览器 /push      ── 零定时器 —— 三段：发起推送 / 推送历史 / 模板管理
                     └─ POST /api/push · GET /api/push/history · GET|POST /api/push/templates
                        DELETE /api/push/templates/{id}

浏览器 /actions   ── 恰好一个 setTimeout 递归退避（POLL_DELAYS_MS = 1s/2s/4s/8s/8s，总 23s）
                     └─ POST /api/action → 若 pending → GET /api/action/{requestId} 逐次补查
```

### 10.1 三条「不可观测 / 静默」—— 界面必须如实说，不许替用户猜

| 事实 | 判据 / 反证 | 界面口径 |
|---|---|---|
| **去重不可判定** | 同 `msg_id` 连发两次，两次都回 `code=0`，**响应键集合完全一致** | 只写「已受理」，附 `DEDUP_NOTE`：幂等窗口内的重复会被**静默**去重，本次是否被去重无字段可判 |
| **超限载荷被静默丢弃** | `PUSH_PAYLOAD_MAX` 的判定点在 **business 进程**（`/push` 已返回 200 之后）。实测发 10KB → 仍回 `code=0`，但 `push_fail` 计数 **0 → 1** | 后台用 `Message::encode()` 做**同源字节**预校验（必须**紧凑重新编码**才计数，否则 3KB 格式化载荷被报成 6KB 而误拦）；回执只写「已受理」，**不写已投递** |
| **补查未命中两义** | `GET /action/{id}` 未命中 = `404 + 4004`，服务端**刻意不区分**「仍在执行」与「已过 `ACTION_RESULT_TTL` 被回收」 | 后台同样回 `HTTP 200 + code=0` + `state=expired`（否则前端会把它当接口故障并打断补查），重发判定给 `unknown / 待确认` |

### 10.2 定时器政策（三页分工，改动前先看这行）

| 页 | 定时器 | 理由 |
|---|---|---|
| `/dashboard` | **周期轮询**（5s / 30s） | 状态型视图，用户停留即期望自动刷新 |
| `/sessions` · `/push` | **零定时器** | 检索型视图；`/push` 还会真实投递，自动刷新是事故源 |
| `/actions` | **恰好一个** `setTimeout` 递归退避 | 补查必须有界：延迟来自 `cfg.poll_delays_ms`、次数 = 序列长度、总时长 < `ACTION_RESULT_TTL`×80%（由 `ActionContractTest` 钉住） |

⚠ 启动退避必须**只在 `invoke` 成功后调用一次**（`maybeStartPoll()`）。放进 `renderOutcome()` 会让
补查响应仍是 `pending` 时重置计数 ⇒ **无限退避**（延迟恒为第一个值、请求量翻几倍）。
另 `stopPoll()` 必须**真的** `clearTimeout`（只靠会话号作废旧链是「假作废」）。

### 10.3 审计（`admin_audit_log`，P3 起启用）

落库动作：`push.create` · `push.template.save` · `push.template.delete` · `action.invoke` —— **全部四条**。
**补查（`GET /api/action/{id}`）刻意不落**，否则每次轮询都写会把审计表刷满。
审计只记元数据，**不记 payload 原文**（载荷可能含业务敏感字段，且可达 4KB）。

### 10.4 易踩的坑

1. **`<select>` 必须显式赋初值**。真浏览器会自动选中首项，**假 DOM 不会** —— 漏掉的表现是
   「首屏所有请求被前端 silently 拦掉」；`/push` 侧漏掉更隐蔽：主项目对未知 `target_type`
   **兜底回落成 `uid`**，目标类型被悄悄换掉且全程零报错。
2. **`POST /action` 的成败只能看响应体 `code`**。`failed` 的 HTTP 可能是 **200**，
   `pending` 是 **202 且不是失败**，`4xx/5xx` 才表示入队前就被拒。
3. **动作清单只能列 `http=true` 的 6 个**。`session` 未开放 HTTP（放进清单 = 必然失败）；
   `requires_uid` 由后端元信息驱动，**不许前端写死 uid 必填**。
4. **`push_task.request_id` 是 `CHAR(16)`**、`operator_id` 是 `INT UNSIGNED`
   （写脚本夹具时超长 / 负数会直接 `1406` / `1264` 致命退出，不是干净 FAIL）。

---

## 11. 运维操作区（P4，2026-09-24）

会话页（`/sessions`）新增**运维操作**区块：`kick`（断开连接）· `revoke`（撤销 Token）·
`unbind`（解绑设备）· 「强制下线」（revoke → kick **串行**）。四个端点全部
`POST /api/ops-action/*`、HTTP 恒 200（成败看 `outcome.state`）、全部落审计。
这是后台**第一次**具备「写主项目」的能力 —— 之前的所有页面均纯只读。

### 11.1 语义边界（给运维看的，不许 UI 概括成败）

| 动作 | 做什么 | **不做什么** |
|---|---|---|
| kick | 断开 TCP 连接 | 不撤 Token（对方可立刻重连）；UDP 连接踢不了（计 `skipped`） |
| revoke | 让 Token 失效（下次鉴权生效） | 不断开已有连接 |
| unbind | 清除 uid ↔ 设备绑定 | 不踢线，已在线旧设备不受影响 |
| 强制下线 | revoke → kick 串行 | 未提供 token 时只做一半，**如实回 `partial=true`** |

组合顺序**不可反**：先 kick 后 revoke 会让客户端落在重连窗口内用同一 Token 重连成功。

### 11.2 明文 Token 的处理（本阶段唯一会流经后台的长期凭证）

- `revoke` 只接受**明文**（主项目只存 `sha256` 前 32 位指纹，单向）⇒ 只能人工粘贴，会话详情里取不到；
- 输入框 `type="password"`；只进 POST body，**不进 URL**（否则落浏览器历史 / 访问日志 / Referer）；
- 审计只落**指纹**（`ops.revoke` 行的 `target` 就是指纹 —— 检索按指纹找，明文不可得是设计而非缺陷）；
- 响应体、日志、审计 params 三处均无明文（`OpsActionContractTest` 三道断言钉死）。

### 11.3 权限与审计

- 权限节点：`ops.kick` / `ops.revoke` / `ops.unbind` / `ops.forceOffline`（id 138~141），
  **只读角色一个都没有**；改完节点记得跑 `php scripts/install.php`（静态检查发现不了 DB 落后）。
- 审计动作：`ops.kick` / `ops.revoke` / `ops.unbind` / `ops.force_offline`；入参校验失败**不落**审计。
- ⚠ 审计键名纪律：`Auditor::REDACT_PATTERN` 按**子串**匹配脱敏（含 `token`/`secret`/`key` 即打码）。
  想在 params 里记「是否做了某事」的布尔事实，**键名不得含敏感词** —— `has_token` 会被打成 `***`，
  实测已改为 `revoke_applied`（**D32**）。
- `/actions` 调试页**刻意不列**运维动作（`ActionCatalog::NOT_IN_DEBUGGER`）：一键踢人不属于调试器。

### 11.4 主项目侧配套（红线 ㊸ 落地，勿动）

`config/actions.php` 里三个运维动作必须声明 `'channels' => [http]`（**反向通道白名单**）。
`'http' => true` 是「额外开放 HTTP」而非「仅限 HTTP」—— 不声明 `channels` 的话，
任何已鉴权终端客户端都能经 WS 踢任意 clientId（**终端提权**）。判定点双防线：
`ActionRunner::run()`（执行侧，持 Redis 凭证者可直写队列）+ `Api/Bootstrap.php`（入队侧）。
既有 7 个动作**未声明 = 全放行**，别「顺手补全」—— 那是线上行为变更。

### 11.5 易踩的坑（新增）

1. **`wa_rules.key` 存的是「控制器@动作」**，`ops.kick` 只是 install.php 里的别名 ——
   拿别名查 DB 恒缺（**D34**）；revoke 审计行 `target` 是指纹，按目标前缀查恒为 0 行。
2. **运维回执一律摊开**：`state` / HTTP / 业务码 / `partial` / `caveats` 全部原样展示；
   「HTTP 200」**不等于**成功（动作执行后的业务失败也是 200 + 业务码）。
3. 验收脚本：`composer test:acceptance:p4`（默认只跑拒绝路径与 DB 真值）；
   `php tests/Manual/p4_acceptance.php --live` 会**真实调用**四端点（目标 `p4test-*`，
   运行期核对其不在线，否则 ABORT 退出码 2）。

---

## 12. 运维页（P5，2026-09-24）

新增 `/ops`（`OpsPageController`，**只给运维角色**）+ 3 个只读端点（`OpsController`）：

| 端点 | 节点 | 内容 |
|---|---|---|
| `GET /api/ops/logs` | `ops.logs` | 日志只读尾读：`role`（白名单）/`date`（`Y-m-d`）/`lines`（≤500）/`keyword` |
| `GET /api/ops/roles` | `ops.roles` | 角色状态三源合一：`roles_cmd` JSON + netstat 实测 + `/health` |
| `GET /api/ops/rotation` | `ops.rotation` | 密钥轮换 checklist（**只生成清单，不执行轮换**）+ 密钥现状（前 4 后 4） |

三道安全/一致防线：

1. **日志路径穿越防御**（设计文档 §7-P5 风险项）：role 白名单 + date 形态 + `realpath()` 复核三关；
   文件不存在返回 `not_found=true`（「今天还没有日志」），**不是错误**。role 白名单与主项目
   `RoleCatalog` 由 `P5OpsServiceTest::testRoleWhitelistCoversMainProjectRoleCatalog` 正则对照防漂移 ——
   主项目加角色后若白名单没跟上，测试会红。
2. **netstat 解析口径**：TCP 只认 `LISTEN*`（Linux 是 `LISTEN`、Windows 是 `LISTENING`，**都含** `LISTEN`）；
   UDP **不加** LISTENING 过滤（该平台的 UDP 行没有这个字样）。同端口监听行数 >1 = 疑似两套实例
   叠加（红线 ㊳），运维页会红字提示。
3. **密钥脱敏**：`SecretMasker` 前 4 后 4；**长度 < 8 全掩**；空值原样（打码反而制造「已配置」假象）。
   这与审计侧 `Auditor::redact()`（子串命中即整键打码，宁枉勿纵）是**两套口径**，勿混用。

轮换 API_SECRET 的**三处同步**（轮换清单第 3 步）：主项目 `.env` 的 `API_SECRET`、
后台 `.env` 的 `ADMIN_API_SECRET`（漏掉 = 后台转签全部 401）、其他 HTTP 调用方。

前端：`public/static/ops.js` —— 零定时器（重新探测是显式按钮）、零 innerHTML、前端不编词；
`tests/Frontend/ops_render_check.js`（P5 段 15 项；序4/序5 扩到 31 项）已并入 `composer test:frontend`。
单测：`tests/Unit/P5OpsServiceTest.php`（13 tests / 44 assertions）。

---

## 13. 2.0 首批：指标趋势 + uid 一站式排查（2026-09-24）

### 13.1 指标趋势采样页（`/metrics`）

- **采样**：独立进程 `metric-sampler`（`config/process.php`，count=1）每
  `ADMIN_METRIC_SAMPLE_INTERVAL`（默认 60s）秒经 `MonitorAggregator`（Redis 只读）落一行
  `gw_metric_samples`；counter 全量进 JSON 列，速率由查询侧差分。
- **红线 ㊲ 落点**：`MetricSampler::onWorkerStart` 立即执行一次采样+清理再挂 Timer
  （延迟首跑会让频繁重启环境一次都不跑）。
- **API**：`GET /api/metrics/range?minutes=&points=`（降采样保首尾 + 差分速率）、
  `GET /api/metrics/latest`。降采样/差分是纯函数（`MetricService::downsample/withRates`），
  口径金标在 `MetricServiceTest`：**counter 重置 ⇒ rate=null 绝不输出负速率**。
- **权限**：metricsPage 菜单 + metric.range/latest，只读与运维**同授**（监测属只读能力）。
- **前端**：手写 SVG 折线（零依赖）；手动刷新 + 范围切换，**无自动轮询**（看实时去 dashboard）；
  空 dataset 如实提示不画假图；textContent-only。校验 `tests/Frontend/metrics_render_check.js`。

### 13.2 uid 一站式排查页（`/trace`）

- 输入 uid → **并行**调既有 4 个只读端点（by-uid / offline / subscriptions / by-device），
  **零新增 API**：端点各自的 `sess.*` 权限就是边界，tracePage 菜单节点只管入口可见。
- 各区块独立展示成败（403 / 业务错 / 空结果互不拖累）；device_id 可选反查。
- 校验 `tests/Frontend/trace_render_check.js`（25 项）；live 验收
  `php tests/Manual/_p20_live_check.php`（10 项，登录运维账号实调）。

### 13.3 踩坑记录（新增）

1. **Redis 池心跳 vs 低频进程**：原池配置 `min_connections=1 + heartbeat_interval=50`，
   采样进程 60s 才用一次连接 ⇒ 空闲连接被对端关闭后心跳必败，且池清理走 close() ——
   webman/redis 硬编码 `client()->close()`，predis 无此方法 ⇒ `__call` 当成 CLOSE 命令 ⇒
   每 50s 刷一轮异常堆栈。已改 `min_connections=0 + idle 55s`：
   低 QPS 后台「取用时新建」远比「保活坏连接」可靠。
   **根因侧修复**（2026-09-24）：`app/support/PredisSafeRedisManager` 覆盖 `connection()`，
   closer 按客户端类型分流（predis → `disconnect()`，有 `close()` 的走 `close()`）；
   `app\bootstrap\RedisBootstrap` 在每个 worker 启动时把 `support\Redis::$instance`
   换成本类（含 metric-sampler 等自定义进程）。改 vendor 的 `RedisManager.php`
   会在 `composer update` 时被覆盖，故只在应用层子类化。
2. **自定义进程连接钉死在 Context**：metric-sampler 无请求生命周期，首次取到的连接写入
   non-fiber `Context` 后永不归还；池的 `idle_timeout` / `heartbeat_interval=3600` 都碰不到
   它。对端断开后下一次采样固定 `Redis::ping(): … errno=10054`（predis 时代则是
   `Error while writing bytes`）。HTTP worker 的 Context 随请求销毁，故只有后台采样进程中招。
   **修复**：`PredisSafeRedisManager::connection()` 对 Context 缓存连接做**限频探活**
   （`PROBE_TTL=30s`，每个 Context 键最多每 30s PING 一次）；探活失败立即
   `Pool::closeConnection()` + 清 Context，本次取连接换新。首次从池取出时写入
   `probed_at`，避免对刚创建的连接做无意义 PING。
3. **Windows 双实例恶化**：webman master 误判 worker 死亡重 spawn 时，旧 worker 在
   Windows（不拒绝重复 bind）下仍活着收请求 ⇒ 一套 master 也能出双监听。
   处置：`netstat -ano` 找 8292 全部监听 PID 逐个杀，master 会重 spawn 自己的 worker，
   杀不死的才是真 master。
4. **php-cs-fixer / phpcs**：admin 无独立 lint 脚本，新增文件保持 LF（`.gitattributes`）。

---

## 14. 2.0 序4/序5：队列深度 + 错误聚合 + 配置查看（2026-09-24）

运维页 `/ops` 在 P5 三区块之上追加三块，全部是 **GET + 只读 + 只进运维角色**
（与 `ops.logs` / `ops.rotation` 同级，**不进只读角色**）。

### 14.1 队列深度巡检（§1.2）

- **端点**：`GET /api/ops/queues` → `OpsController@queues`，节点 `ops.queues`。
- **数据**：四条 LLEN 队列（`RedisKeys::QUEUE_*`）+ 离线消息总量
  （`RedisReader::pushOfflineStats`，SCAN `push:offline:*` + 逐键 LLEN）+
  动作回执积压（`actionResultBacklog`，SCAN `action:result:*`）。**有界三件套**
  （轮次/键数上限 + `truncated` 如实上抛），禁 KEYS。
- **阈值**：两档独立设置键，**不复用** `monitor.queue_warn_depth`
  （后者是面板告警，动一处不该牵两处 UI）：
  - `ops.queue_warn_depth`（种子 1000）— UDP / 推送 / 离线总量
  - `ops.action_warn_depth`（种子 100）— action 入站 / 回执积压
  比较用 `>=`（与 `MetricsDeriver::levelOf`「到点即算」同向）。
- **判定**：`QueueInspector::rows()` 纯函数；键名一律 `RedisKeys` 常量
  （聚合展示键用 `push_offline` / `action_result` 下划线形态，避免 phpcs
  键字面量嗅探误伤）。
- **种子**：`database/001_gw_tables.sql` 的 `ops.queue_warn_depth` /
  `ops.action_warn_depth` / `ops.error_lines`（INSERT IGNORE 幂等）。

### 14.2 错误日志聚合（§1.4）

- **端点**：`GET /api/ops/errors?date=&lines=` → `OpsController@errors`，节点 `ops.errors`。
- **口径**：六角色按 `[ERROR]` 关键字过滤计数 + 最新行；`error_{date}.log`
  汇总通道**不过滤关键字、且不计入 `total`**（双写副本，计入会翻倍）。
  `count` 来自尾读窗口 `matched`（≤20000 行），超限 `truncated=true`，
  UI 如实标注「非精确总量」。
- **失败语义**：`not_found`（今天还没日志）≠ 错误；单角色失败不拖垮整表。
- **`LogTailService::resolve` 修正**：role=`error` 曾恒拼 `error.log`
  （主项目实际是 `error_{date}.log`）→ P5 尾读 error 角色恒 not_found。
  已统一为 `{role}_{date}.log`，并由 `ErrorAggregatorTest` fixture 钉死。

### 14.3 配置查看（§2.1，脱敏）

- **端点**：`GET /api/ops/config` → `OpsController@config`，节点 `ops.config`。
- **只读主项目 `.env`**：不解析 `config/*.php` 多层叠加（后台进程加载不到
  主项目 Env 语义，解析出来会是「代码默认值」冒充「生效值」）。
- **白名单 + 脱敏**：`ConfigViewer::GROUPS` 七组；`SECRET_KEYS` 四键走
  `SecretMasker::mask()`（前4后4；空值原样 = 未配置，不打码制造假象）。
  非白名单键直接丢弃。
- **路径三关**：`project_root` realpath → 固定 `.env` 文件名 → realpath 落界复核。
- **mtime**：作「改了未重启」漂移线索（无热重载是本项目高频误判源）。
- **UI 备注**：加载优先级 / 无热重载 / 与 roles 命令的分工，由后端下发。

### 14.4 权限与路由

| 端点 | 节点 | 只读角色 | 运维角色 |
|---|---|---|---|
| `GET /api/ops/queues` | `ops.queues` | ✗ | ✓ |
| `GET /api/ops/errors` | `ops.errors` | ✗ | ✓ |
| `GET /api/ops/config` | `ops.config` | ✗ | ✓ |

三条路由都在 `Route::group('/api', …)->middleware([AdminAuth::class])` 内；
`OpsController` 既有 `disableDefaultRoute` 覆盖新方法。

### 14.5 校验

- 单测：`QueueInspectorTest` / `ConfigViewerTest` / `ErrorAggregatorTest` /
  `OpsPageExtContractTest`（节点登记 / 仅 operator / 路由 GET / SQL 种子 /
  视图 id ↔ JS 绑定 ↔ cfg 键三方对齐）。
- 前端：`tests/Frontend/ops_render_check.js` 扩到 **31 项**（S4 队列标红与
  truncated、S5 not_found 与 XSS、S6 脱敏摊开与 mtime）。
- 门禁：admin `composer test`（278 tests / 1493 assertions，1 skip）+
  `test:frontend` 全绿；主项目 `analyse` / `lint` / `lint:self` / `cs:check` /
  `test` / `test:frontend` / `test:docs` 全绿。
- 主项目 `composer test` 计数同步为 **533 tests / 1555 assertions**
  （README / AGENTS / 工具链说明 / `docs_numbers_check.php` 五处锚点已改）。

### 14.6 踩坑记录（新增）

1. **`str_contains` 没有 offset 形参**（PHP 8.2）：`.env` 解析想「从位置 1
   起找收尾引号」必须用 `strpos($value, $q, 1)`；写成三参会
   `ArgumentCountError` + PHPStan `arguments.count` 双红。
2. **`configured` 的 `||` / `&&` 优先级**：`$configured || in_array(...) && $raw !== ''`
   实际等价于 `$configured`（短路后半段恒被覆盖），属可读性陷阱；已改为
   `$present && $raw !== ''` 显式语义。
3. **admin 无独立 php-cs-fixer**：排版门禁在主项目根目录
   （`composer cs:check` 扫 131 文件含 admin PHP）；admin 新增文件保持 LF 即可。

## 15. 2.0 序7：限流统计 + 版本环境 + 推送送达率（2026-09-24）

三项**全部纯 admin**，零主项目改动。

### 15.1 限流命中巡检（§1.3）

- **端点**：`GET /api/ops/rate` → `OpsController@rate`，节点 `ops.rate`（只进运维）。
- **数据源**（`RateInspector`，只读有界 SCAN）：
  - `rl:{dim}:{md5(id)}` Hash（tokens / ts）—— L2 令牌桶；`tokens <= 0` → bad、`< 1` → warn。
  - `api:rate:{md5(ip)}:{slot}` String —— HTTP 分钟窗；只列最近 `API_WINDOWS=3` 内**有命中**的窗口。
  - `metrics:counter:{Ymd}` 的 `rate_limit_hit` —— 当日累计（跨天归零属正常）。
- **不可逆红线**：主体一律 **md5 前 12 位指纹**（`RedisKeys::rateBucket` / `rateApi` 的键设计）；
  UI 必须原样展示后端下发的 notes，**不得暗示可定位到原始 IP / uid**。
- **L1 进程内桶不在 Redis** —— notes 须说明「本页看不到 L1」，避免运维误以为「没限流」。
- **truncated**：SCAN 超限如实上抛（与队列深度同一纪律）。

### 15.2 版本 / 环境并入 roles（§2.2）

- **无新端点 / 无新节点**：`OpsController@roles` 响应追加 `env` 块
  （`EnvInfoService::view()`），与角色表同区块展示。
- **三源**：PHP runtime（版本 / SAPI / OS）+ 主项目 `composer.lock` 白名单
  （`EnvInfoService::PACKAGES`：workerman 三件 + predis）+ 主项目 `.env` 的 `APP_ENV`
  （复用 `ConfigViewer` 路径三关）。
- **任一源失败不拖垮整包**：该项 `configured=false`，UI 显示「—」，不编默认值。
- **白名单而非全量 lock**：传递依赖全表是噪音，且会把「依赖升级」误读成「主动升级」。

### 15.3 推送送达率（§3.3）

- **无新端点**：`MetricService::RATE_KEYS` 追加
  `push_in / push_out / push_fail / push_offline / push_dedup`。
- **metrics 页第 4 张图** `chart-push`：五条差分速率曲线（条/秒）。
- **当前值表**新增「推送累计」行（in / out / fail / offline / dedup）。
- **口径备注由视图硬编码在图下**（非前端编词 —— 是静态说明）：
  `push_in` = HTTP 受理（含离线缓存 / 去重前）；`push_out` = 实际下发到网关；
  **差值不等于丢失**（离线与去重是设计内路径）；送达率仅作趋势参考，勿当精确 SLA。

### 15.4 权限与路由

| 端点 | 节点 | 只读角色 | 运维角色 |
|---|---|---|---|
| `GET /api/ops/rate` | `ops.rate` | ✗ | ✓ |
| `GET /api/ops/roles`（+env） | `ops.roles`（既有） | ✗ | ✓ |

- 运维角色节点数：**36 → 37**（p3/p4 验收断言已同步）。
- 只读角色节点数：**19 不变**（env 随 roles，rate 不进只读）。
- `OpsPageController` 下发 `rate_url` + `perms.rate`；视图 `sec-rate` 区块。

### 15.5 校验

- 单测：`Seq7ServiceTest`（键解析 / 维度标签 / notes / EnvInfo 形状与白名单）+
  `OpsPageExtContractTest` 扩到含 `ops.rate` / `rate_url` / 视图 id +
  `MetricServiceTest` 的 `RATE_KEYS` 含 push 系列。
- 前端：`ops_render_check.js` 扩到 **42 项**（S1 版本环境 + S7 限流指纹 / 窗口 / 截断 /
  不可逆 notes / XSS）；`metrics_render_check.js` 扩到 **19 项**（chart-push 出线 +
  推送累计行）。
- 门禁：admin `composer test`（**289 tests / 1592 assertions**，1 skip）+
  `test:frontend` + `analyse` 全绿；主项目全量门禁复核。

## 16. 行为日志页 + 废弃 demo 菜单清理（2026-09-24）

> 非 2.0 序号项：按用户拍板落地的菜单/审计清理与真实页替换。

### 16.1 背景与决策

- 侧栏「示例页面」demo 树读假 JSON，与「真实可用后台」冲突；其中 demo605 假装是行为日志。
- 用户拍板：① 新建真实审计页读 `admin_audit_log`；② demos 从 DB 删除（重跑 install 生效）。
- **`plugin/admin/config/menu.php` 不可作为清理真源**：它是 composer 包 `webman/admin`
  的纯副本（`admin/.gitignore` 排除、`composer install` 经 `copy_dir` 重建并带回 demos）。
  本地手改只在下次 `composer install` 前有效；**版本库真源 = `scripts/install.php` 步骤 3b**
  （`$obsoleteMenuRoots = ['demos']` + `deleteRuleTreeByKey`：递归删子树并从 `wa_roles.rules`
  剔除悬空 id，`*` 角色跳过）。`AuditMenuContractTest` 因此**不**断言 menu.php 内容。

### 16.2 交付物

| 类 | 内容 |
|---|---|
| 页面 | `/audit` 行为日志（`AuditPageController` + `app/view/audit/index.html` + `public/static/audit.js` / `audit.css`） |
| API | `GET /api/audit/logs`（`AuditController`，组内声明 `/audit/logs`）：action/result/admin_id/page/size/from/to；action 必须在 `Auditor::ACTIONS` 内否则 4007；DB 失败 503+5010；params 写入时已脱敏 |
| 节点 | `auditPage`（type1, weight 88）+ `audit.list`（type2, weight 87），**只读与运维同授** |
| 安装 | 步骤 3b 清理 demos；`$nodeSpecs` 登记两节点；`$viewerRules` 追加（运维经 `array_merge` 继承） |
| 路由 | `Route::get('/audit', …)->middleware([AdminAuth])` + 组内 `GET /audit/logs`；两控制器 `disableDefaultRoute` |
| 测试 | `AuditMenuContractTest`（install 清理 / 节点 / 路由 / 动作枚举不硬编码）+ `audit_render_check.js` **23 项** |
| 验收断言 | 只读 **19 → 21**、运维 **37 → 39**（p3/p4_acceptance 已同步） |

页面纪律与 metrics/trace 同款：视图只渲染骨架 + `#audit-page-config` JSON；数据经 API；
`Perm::map` 注入 perms；JS 零定时器、textContent only、零 innerHTML；动作枚举从
`Auditor::ACTIONS` 下发不硬编码。

### 16.3 校验

- admin `composer test`（**293 tests / 1614 assertions**，1 skip；较序7 净减 1 = 删掉不可靠的
  menu.php demos 断言、保留 install/路由/节点断言）+ `composer test:frontend`
  （含 `audit_render_check` 23 项）+ `composer analyse` 全绿。
- 主项目 `composer test`（533 / 1555）+ `test:frontend` + `test:docs` 全绿。
- **生效方式**：已装环境重跑 `php scripts/install.php`（步骤 3b 删 demos + 新节点进 DB + 角色 rules 覆写）。

---

## 17. 访问日志（`wa_admin_log` · `/access-log`）

### 17.1 背景与决策

- webman-admin 插件**没有**自带操作日志表（`install.sql` 仅七张 `wa_*`，登录只 `UPDATE wa_admins.login_at`）。
- 用户拍板：新建 `wa_admin_log` 记「谁登录/登出/打开了什么」，与已有 `admin_audit_log`（`/audit`，业务写操作审计）**并列、不合并**。
- **全局中间件**（`config/middleware.php` 注册，**必须**是 `['@' => [AccessLog::class]]` 两层结构 ——
  扁平列表会被 `Webman\Middleware::load()` 判成 `Bad middleware config` 直接抛异常）
  覆盖根应用 + 插件路由，故登录/登出/vendor 账号操作都能留痕。
- 过滤：静态资源 / 验证码 / OPTIONS / 高频轮询 GET（`/api/monitor/live` 等）不落行；全部非 GET + 页面 GET + 认证事件必记。
- 脱敏：`query` / `body` 经 `Auditor::redact()`；登录 `username` 记、`password` 绝不记原文。

### 17.2 交付物

| 类 | 内容 |
|---|---|
| 表 | `database/001_gw_tables.sql` 的 `wa_admin_log`（`utf8mb4_general_ci`，幂等 `IF NOT EXISTS`） |
| 服务 | `app/service/AccessLogger.php`：`record()` 唯一写入口（失败不阻断）+ `envelope()` / `redactQuery()` / `redactBody()` |
| 中间件 | `app/middleware/AccessLog.php`（`config/middleware.php` 注册）：before 捕身份，after 捕 status/code/耗时 |
| 页面 | `/access-log`（`AccessLogPageController` + `app/view/accesslog/index.html` + `public/static/accesslog.js`，复用 `audit.css`） |
| API | `GET /api/access-logs`（`AccessLogController`，组内声明 `/access-logs`）：event/result/method/path/admin_id/page/size/from/to |
| 节点 | `accessPage`（type1, weight 86）+ `access.list`（type2, weight 85），**只读与运维同授** |
| 安装 | 步骤 4 表清单含 `wa_admin_log`；`$nodeSpecs` 登记两节点；`$viewerRules` 追加（运维经 `array_merge` 继承） |
| 路由 | `Route::get('/access-log', …)->middleware([AdminAuth])` + 组内 `GET /access-logs`；两控制器 `disableDefaultRoute` |
| 测试 | `AccessLogMenuContractTest`（节点 / 路由 / 中间件注册 / 表 / 枚举不硬编码）+ `accesslog_render_check.js` **26 项** |
| 验收断言 | 只读 **21 → 23**、运维 **39 → 41**（p3/p4_acceptance 已同步） |

页面纪律与 `/audit` 同款：视图只渲染骨架 + `#accesslog-page-config` JSON；数据经 API；
`Perm::map` 注入 perms；JS 零定时器、textContent only、零 innerHTML；事件/结果枚举从
`AccessLogger::EVENTS` / `RESULTS` 下发不硬编码。

### 17.3 校验

- admin `composer test`（含 `AccessLogMenuContractTest`）+ `composer test:frontend`（含 `accesslog_render_check` 26 项）+ `composer analyse` 全绿。
- **生效方式**：已装环境重跑 `php scripts/install.php`（新表 + 新节点进 DB + 角色 rules 覆写）；改 `.env` / `config/middleware.php` 后需重启 admin 进程。
