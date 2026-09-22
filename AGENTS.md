# AGENTS.md — AI 协作入口

本文件是任意 AI 编码工具（WorkBuddy / Claude Code / Cursor / Copilot 等）进入本仓库时的
**第一落点**：先读本文件，再按需展开 `docs/` 下的完整文档。

> **权威顺序：代码 > `docs/GatewayPush 对外接口文档.md` > `README.md`。**
> 三者冲突时以左侧为准，并顺手修正右侧。

---

## 1. 项目定位

`GatewayPush` —— 基于 workerman + GatewayWorker 的实时数据推送服务。

- 协议：**WebSocket + UDP 双协议**，单对一定向推送
- 存储：**仅依赖 Redis**（无 MySQL）
- 部署：单机；集群改造已调研、未落地
- 名称约定：**`GatewayWorker` 在本项目中只表示所依赖的框架**（`workerman/gateway-worker`）。
  叙述与 `use` 语句里出现它属正常，**不要当成遗留旧名去"清理"**。

## 2. 必读文档

| 要做什么 | 先读什么 |
|---|---|
| 写任何服务端代码 | **`docs/Workerman 框架 AI 编码规范.md`** —— 底层约束 + PSR-12 + 常驻内存避坑，可直接作 System Prompt |
| 改对外接口 / 命令 / Redis 键结构 | `docs/GatewayPush 对外接口文档.md`（字段级契约）**与** `README.md`（.env 变量表 / 协议 / 运维）—— **两处必须同步改**，对照表见对外接口文档末尾附录 |
| 理解设计取舍 | `docs/Workerman V2 GatewayPush 实时数据推送服务技术方案文档.md` |
| 改客户端 SDK | `docs/GatewayPush 客户端SDK与调试器设计方案.md` + `client/README.md` |

## 3. 目录速查

```
start.php                 唯一启动入口（命令分发 / 环境自检 / 横幅 / runAll）
src/
  Gateway/                register + WS 网关 + UDP 网关
  Business/               业务进程：Bootstrap / Router / Push / Session / Monitor / Action/
  Api/                    HTTP 接口
  Dashboard/              只读监控面板
  Common/                 RoleCatalog（角色清单真源）/ RedisKeys（键名真源）/ Env / Logger /
                          RedisClient / RateLimiter / Message ...
config/                   app / gateway / business / actions 四份（结构性配置）
bin/
  start.sh|.bat|.ps1      生产管理脚本（启停 / 状态 / 日志 / 就绪轮询）
  dev/boot_all.sh         仅本机开发用：逐角色拉起 6 个角色并常驻
client/                   自洽子项目：客户端 SDK + CLI 调试器（P0~P6 已完成）
tests/
  Unit/                   PHPUnit 套件（含 client/tests/Unit）
  E2E/                    e2e_check.php 的用例族（A~P）
  Api/ Frontend/          独立校验脚本（不在 PHPUnit 套件内，需单独跑）
  Manual/                 手工验收脚本（P3/P4/P5 里程碑，需服务在线）
docs/ postman/ resources/ 文档 / 接口集合 / 面板静态资源
runtime/                  运行时产物：logs/ pid/ phpstan/（已 gitignore，勿放人工资产）
```

> ⚠ **不要为迎合编码规范第七章（`app/{Services,...}` 分层）去重组 `src/`。**
> 本项目按「运行角色」分层，与 `src/Common/RoleCatalog.php` 一一对应；上述重组会同时波及
> composer autoload 前缀、RoleCatalog、`bin/start.*`、`phpstan.neon`、`phpunit.xml` 五处。

## 4. 质量门禁（改完必跑）

```bash
composer analyse      # PHPStan L5，111 文件（含 tests）；两份 baseline 冻结存量 → 必须 0 errors
composer test         # PHPUnit：467 tests / 1317 assertions
composer test:e2e     # 端到端 16 用例（A~P），需 4~5 个角色在线
```

以下三个**不在 PHPUnit 套件内**（套件只扫 `tests/Unit` 与 `client/tests/Unit`），需单独执行，
退出码 `0` = 全绿：

```bash
node tests/Frontend/dashboard_autorefresh_check.js   # 面板自动刷新语义（注入假 DOM，无需服务端）
node tests/Api/api_sign_check.js                     # HTTP 验签 8 形态（需 api + business 在线）
php  tests/Api/http_demo.php                         # HTTP 接口示例 13 场景（需 api + business 在线）
```

- **改完代码先清 PHPStan 结果缓存再跑全量 `analyse`** —— 结果缓存会掩盖既有错误。清缓存用
  `php vendor/bin/phpstan clear-result-cache --memory-limit=512M`；
  **不要用 `rm -rf runtime/phpstan`**（本机安全策略对批量删除会直接拦截）。
- 新增告警必须修，**不得追加进任何 baseline**。两份 baseline 的分工：
  `phpstan-baseline.neon`（生产代码，10 条）/ `phpstan-tests-baseline.neon`（测试存量，57 条目）。
- **⚠ `level` 与 baseline 必须同源**：baseline 用哪个 level 生成，`phpstan.neon` 的
  `parameters.level` 就得是哪个值。不一致会触发成百上千条 `ignore.unmatched (non-ignorable)`，
  门禁直接红——已发生过一次（baseline 以 level 6 生成，而配置仍为 level 5 → 351 errors）。
  **不要用 `composer baseline` 重新生成生产代码基线**：它会连同新引入的告警一起冻结。
- level 刻意停在 5：升到 6 会额外报 ~350 条「数组缺 value 类型」，真修要动约 60 个文件的签名，
  塞 baseline 则等于放弃零容忍。
- `phpstan-strict-rules` / `phpstan-phpunit` 是**显式 `includes`** 的（未装 `extension-installer`）；
  只装 composer 包不写 `includes`，规则一条都不会生效。

## 5. 高频红线（违反即事故）

**常量与键名**

- 所有 Redis 键名**一律引用 `src/Common/RedisKeys.php`**，禁止字面量。
  自检：`grep "'session:\|'queue:\|'push:" src/` 应为空。
- **改键名 = 存量数据失配**：必须配 `RENAME` 迁移并**全角色同时重启**。只重启部分角色会导致
  队列分裂，表现为「请求成功但动作永不执行」。
- 角色清单唯一真源 = `src/Common/RoleCatalog.php`。脚本经 `php start.php roles`（JSON）取真值，
  **不得自行解析 `.env`**（`Env` 的多层叠加语义脚本还原不了）。

**安全**

- **UDP 报文的 uid 只能取自 Token 载荷**。报文里的 `uid` 字段不参与签名，采信即等于允许冒充。
- `API_SIGN_ENABLE` 开关**只在监听回环地址时生效**，且与 `AUTH_ENABLE` / `AUTH_SIGN_ENABLE`
  完全无关（后两者属 WS/UDP 报文层）。判定键是否存在必须用 `array_key_exists`，不用 `empty()`。

**常驻内存**

- `src/` 与 `client/src/` 内**零** `exit` / `die` / `sleep` / `pcntl_fork`。
  `exit` 仅允许出现在 CLI 入口 `start.php` 与 `client/src/Cli/Debugger.php`。
- 主进程不建任何连接：`RedisClient::init()` 必须落在 `onWorkerStart` 内。
- 连接专属定时器在 `onClose` / `unbind` 时 `Timer::del`，否则泄漏。
- 配置**无热重载**：`.env` 改动只对新启动的进程生效，改完必须重启对应角色。

**时序与错误处理**

- `API_ACTION_WAIT_MS`(6000ms) **必须 >** `ACTION_TIMEOUT`(5s)。
- HTTP 取动作结果用**退避轮询**，**禁用 `BLPOP` / `SUBSCRIBE`** —— 阻塞式独占 Redis 连接，
  连接池无顺序保证，会耗尽。
- **HTTP 错误分层最易误判**：入队前失败 → `4xx/5xx`（未知动作 / 未开放通道 `400`+`4006`、
  队列积压 `503`+`5030`）；**执行后业务失败 → `200` + 业务码**（如缺参 `4007`，
  `data.status=failed`）。**判断成败必须看响应体 `code`，不能只看 HTTP 状态码。**
- `emitError()` 只对 UDP 通道静默；HTTP 与 WS 都必须下发错误码。
- UDP 回执分两层：先判 `isset($data['action'])` 区分**传输层 ack** 与**业务回执**。

**日志与保留期**

- `LOG_ARCHIVE_AFTER_DAYS` **必须小于** `LOG_KEEP_DAYS`，否则明文先被清理任务删掉、
  归档永远拿不到内容。违反时 `Logger::archive()` 只告警并跳过本轮，不阻断启动（可选项
  不该让服务起不来）。
- `config/business.php` 的 tasks 数组里 **`log-archive` 必须声明在 `log-cleanup` 之前**：
  两者都靠 `run_at_start` 在启动后 1s 补跑，按**声明顺序**触发。颠倒后不报错、不告警，
  只是归档恒为空。
- 归档写入顺序**不可颠倒**：先写包成功、再删明文。既有包损坏时跳过本轮并保留明文，
  且**不覆盖**该包（否则连带毁掉包内既有历史）。以上三条均已由 `LoggerTest` 钉死。
- 归档只认 `{channel}_{YYYY-MM-DD}.log` 命名；`workerman.log` / `stdout.log` 不入归档
  （它们归 `LOG_MAX_MB` 管）。归档产物为 `archive/{YYYY-MM}.tar.gz`，包名取**文件自身
  日期**的月份而非归档时刻，故跨月归档不串包。

**平台差异（Windows 开发环境）**

- 端口占用探测**必须走 `netstat`**：该平台 socket 默认允许重复 bind，`bind` 判定恒返回
  「未占用」且无任何报错。判 UDP 时**不能加 `LISTENING` 过滤**。
- 这条规则的具体后果：**重复实例不会被拒绝**。已有服务在跑时再启动一套，两套都会绑住
  同一端口（`netstat` 里每个端口出现 **2 个 PID**），推送与 UDP 回执随机落到其中任一套
  —— 表现为 **e2e 用例随机失败**（每次失败的是不同用例，E/G/J/O 轮着来），
  极易误诊成代码缺陷。**启动前务必先 `netstat` 确认端口空闲**。
- **绝不要执行 `php start.php stop|restart|reload|status`** —— 非 Unix 下 workerman 跳过命令解析，
  `stop` 会**反向启动一个新实例**。停角色一律用 `bin\start.bat stop`。
- 单启动文件只能初始化 1 个 Worker 实例，故 `--role=all` 在 Windows 会被入口直接拒绝。

**刻意偏离规范之处（勿"修正"）**

- UDP 网关在 `src/Gateway/Bootstrap.php` 的 `onWorkerStart` 里调用了 `RedisClient::init()`，
  与规范 10.2-1「Gateway 不连 Redis」相悖。这是**有意为之**：UDP 网关必须读写
  `queue:udp:in` / `queue:udp:out`，而 `udp:{ip}:{port}` 不在 Gateway 连接表内、走不了
  `sendToClient`，且 Windows 单 Worker 角色无法再拆。WS 网关仍不碰 Redis，规范本意未破。
  **改该文件前先读 200~250 行注释。**

## 6. 脚本文件编码铁律

| 文件 | 行尾 | 编码 |
|---|---|---|
| `*.sh`（含 `bin/start.sh`、`bin/dev/boot_all.sh`） | LF | 无 BOM |
| `bin/start.ps1` | CRLF | UTF-8 **带 BOM** |
| `bin/start.bat` | CRLF | **纯 ASCII**（不得含中文） |

复核行尾**不要用 `grep -c $'\r'`** —— 在 Git Bash 下 `$'\r'` 会展开成空串，恒等于总行数，
会把 LF 文件误报成「全文 CRLF」。可靠方法：

```bash
tr -cd '\r' < 文件名 | wc -c     # 输出 0 即 LF
git ls-files --eol               # 逐文件列出 index/工作区行尾
```

经验：`Write` 新建文件产出 LF 无 BOM；`Edit` 改既有文件会保留原行尾与 BOM。
即只有**新建或整体重写** `.ps1` / `.bat` 后才需要规整编码。

## 7. 服务启停（本机）

```bash
bin/start.bat start          # Windows 角色编排（推荐；会预检 + 逐角色就绪轮询）
bin/start.bat status         # 查看状态
bash bin/dev/boot_all.sh     # 仅需"把 6 个角色都拉起来并常驻"时（开发用）
```

- 启动顺序：register → gateway → udp → business → api（dashboard 可选）。
- **启动前先确认没有残留实例**：Windows 不会拒绝重复 bind，两套叠加后每个端口会有 2 个 PID，
  故障现象是「e2e 随机失败」而非报错，排查成本极高。先探一次：

  ```bash
  netstat -ano | grep LISTENING | grep -E ':(1238|8282|8290|8291)\b'   # 有输出 = 已有实例在跑
  ```

- 本机 `REDIS_DB=9`（DB0 有历史残留 `gwpush:*` 键）。
- **UDP 通而 WS 不通 ⇒ 先查网关注册路由**。
- 被 `*_ENABLE=false` 关闭的角色会被脚本在就绪轮询前跳过；`bin/start.ps1` 的角色清单取自
  `php start.php roles`，脚本不解析 `.env`。
