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

---

## 2. 前置条件

| 依赖 | 要求 | 核验命令 |
|---|---|---|
| PHP | 8.2 ~ 8.5（**禁用 8.3+ 语法**） | `php -v` |
| 扩展 | `gd` `fileinfo` `pdo_mysql` `curl` `mbstring` `openssl` `sockets` `xml` `zip` | `php -m` |
| Redis 扩展 | **不需要** phpredis —— 走 `predis/predis` 纯 PHP 客户端 | — |
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
| 4 | 后台自有四表：`database/001_gw_tables.sql`（`push_task` / `push_template` / `admin_audit_log` / `admin_settings`） |
| 5 | 首个超管（取 `.env` 的 `ADMIN_BOOTSTRAP_USER` / `ADMIN_BOOTSTRAP_PASS`；`wa_admins` 非空则跳过） |
| 6 | GatewayPush 权限节点 5 个 + 「运维 / 只读」两角色（按 `wa_rules.key` 与 rule id 列表 upsert） |

**为什么不走官方 Web 安装页**：①它的「已安装」标记写在插件目录内，`composer update webman/admin` 会冲掉；
②表已存在时它会要求「强制覆盖（`DROP TABLE`）」；③它会把明文连接参数写进插件目录。
完整依据见设计文档 §11 的 D10 / D13。

### 3.2 管理员账号与角色

| 角色 | `wa_roles.id` | `rules` | 能访问 |
|---|---|---|---|
| 超级管理员 | 1 | `*` | 全部（含 webman-admin 自带的管理面） |
| 运维 | 3 | 14 个节点 | GatewayPush 全部端点（只读 12 个 + `ops/scan` + `ops/probe`） |
| 只读 | 2 | 12 个节点 | `/dashboard`、`/sessions` 两页 + `/api/monitor/*`（含 `mon.live`）+ `/api/sessions/*`、`/api/session/*`、`/api/auth/revoked`；`/api/ops/*` 返回 **403** |

> P2 后「只读」= 12 节点、「运维」= 14 节点（计数可用
> `SELECT id,name,rules FROM wa_roles` 复核）。节点清单的**唯一真源**是
> `scripts/install.php` 的 `$nodeSpecs`，重跑该脚本即幂等对齐（id 不变、只更新 title/key/weight）。

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

## 4. 三条硬纪律（违反即静默故障）

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
composer test           # PHPUnit：tests/Unit（P2 后 83 tests / 361 assertions）
composer analyse        # PHPStan L6，**刻意不引入 baseline**（新代码零容忍）
composer test:frontend  # 运行期前端渲染校验（P1 的 144 项 + P2 的 122 项；无需浏览器 / jsdom / 服务端）
```

`composer test` 与 `composer test:frontend` **不可互相替代**：
前者是**静态契约**（`DashboardContractTest` / `SessionContractTest`：JS 引用的 DOM id 是否都在视图里、
`cfg.*` 是否都由控制器注入、有没有 `innerHTML` 赋值、**有没有定时器**、**读路径有没有 Redis 写命令**），
后者是把 `dashboard.js` / `session.js` **真跑一遍**断言渲染结果
（卡片取值、派生率 `null ≠ 0.00%`、队列条宽、进程表格式化、日切清空、失败退避倍数、
`visibilitychange` 陈旧响应作废；会话侧则是截断回显、空态 vs 配错的区分、抽屉 URL 同步、
离线队列用**全局**下标、UTF-8 **字节**长度校验）。
**只跑前者只能证明「名字都对」，证明不了「渲染正确」。**
后端 API 契约改动另需两个验收脚本（含硬断言「`live()` 不含 `api` 段」「保留会话必须出现在 `scope=retained` 里」）：

```bash
php tests/Manual/p1_acceptance.php    # P1 验收：服务层(真连 Redis) / HTTP 层(curl+验证码登录) / RBAC
php tests/Manual/p2_acceptance.php    # P2 验收（只读，不写任何 Redis 键）
php tests/Manual/p2_acceptance.php --seed   # 额外写入 p2test-* 一次性夹具，跑完**无条件清理**
```

> ⚠ `--seed` 有安全闸：`ADMIN_REDIS_HOST` **必须是回环地址**，否则以退出码 2 直接中止 ——
> 防止有人在连生产 Redis 的机器上跑夹具写入。该脚本是后台子项目里**唯一**会写 Redis 的地方。

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
| `Class "Redis" not found` | `config/redis.php` 的 `client` 键没放**顶层**（放 `redis.default` 里会被静默忽略并回退 phpredis） |
| 只读/运维账号访问新端点一律 403 | 新增端点后忘了往 `wa_rules` 加权限节点（`{控制器全类名}@{action}`）；超管因 `rules='*'` 察觉不到 |

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
