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
| 改质量工具链（phpcs / php-cs-fixer / PHPStan 的规则、排除项、门禁） | **`docs/代码质量工具链说明.md`** —— 四套工具的机制、职责边界、每条排除项的实测依据、已知坑位 |

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
.github/workflows/ci.yml  CI：static(单一 PHP) / test(8.2~8.5 矩阵) / e2e(Redis+全角色)
.gitattributes            行尾规范化（*.bat/*.cmd/*.ps1 → CRLF；*.sh/*.php → LF），见 §6
runtime/                  运行时产物：logs/ pid/ phpstan/（已 gitignore，勿放人工资产）
```

> ⚠ **不要为迎合编码规范第七章（`app/{Services,...}` 分层）去重组 `src/`。**
> 本项目按「运行角色」分层，与 `src/Common/RoleCatalog.php` 一一对应；上述重组会同时波及
> composer autoload 前缀、RoleCatalog、`bin/start.*`、`phpstan.neon`、`phpunit.xml` 五处。

## 4. 质量门禁（改完必跑）

```bash
composer analyse      # PHPStan L6，114 文件（含 tests）；两份 baseline 冻结存量 → 必须 0 errors
composer test         # PHPUnit：467 tests / 1317 assertions
composer lint         # phpcs 审计（注释/命名/业务红线）；只读，仅 error 影响退出码
composer lint:self    # 两个自定义 phpcs 嗅探器自检（漂移检测 + 作用域/豁免矩阵）
composer cs:check     # php-cs-fixer 排版体检（dry-run，只报不改；落地用 composer cs）
composer test:e2e     # 端到端 16 用例（A~P），需 4~5 个角色在线
```

以下四个**不在 PHPUnit 套件内**（套件只扫 `tests/Unit` 与 `client/tests/Unit`），需单独执行，
退出码 `0` = 全绿：

```bash
node tests/Frontend/dashboard_autorefresh_check.js   # 面板自动刷新语义（注入假 DOM，无需服务端）
node tests/Api/api_sign_check.js                     # HTTP 验签 8 形态（需 api + business 在线）
php  tests/Api/http_demo.php                         # HTTP 接口示例 13 场景（需 api + business 在线）
php  tests/Manual/phpcs_business_rules_check.php     # phpcs 自定义嗅探器自检（= composer lint:self）
```

- **改完代码先清 PHPStan 结果缓存再跑全量 `analyse`** —— 结果缓存会掩盖既有错误。清缓存用
  `php vendor/bin/phpstan clear-result-cache --memory-limit=512M`；
  **不要用 `rm -rf runtime/phpstan`**（本机安全策略对批量删除会直接拦截）。
- 新增告警必须修，**不得追加进任何 baseline**。两份 baseline 的分工：
  `phpstan-baseline.neon`（生产代码，8 条）/ `phpstan-tests-baseline.neon`（测试存量，324 条目/339 条）。
- **重构修掉真实告警后，必须同步删掉 baseline 里对应的失效条目**。失效条目不删，PHPStan 会以
  `ignore.unmatched (non-ignorable)` 报错，门禁同样变红 —— 已发生过一次（2026-09-22，7 条）。
- **⚠ `level` 与 baseline 必须同源**：baseline 用哪个 level 生成，`phpstan.neon` 的
  `parameters.level` 就得是哪个值。不一致会触发成百上千条 `ignore.unmatched (non-ignorable)`，
  门禁直接红——已发生过一次（baseline 以 level 6 生成，而配置仍为 level 5 → 351 errors）。
  **不要用 `composer baseline` 重新生成生产代码基线**：它会连同新引入的告警一起冻结。
- **⚠ `phpVersion` 同样会牵动 baseline**：它决定 PHPStan 按哪个 PHP 版本推断语言特性，
  改动会让「依赖高版本类型」的冻结条目失配。2026-09-22 把下限抬到 8.2（`phpVersion: 80200`）时，
  `@throws Random\RandomException`（`src/Business/Auth.php`）由「非法类型」变为合法类型，
  对应 ignore 必须删除 —— 生产侧 baseline 9 → 8 条。
- **改 PHP 下限不是「改一个数字」**：连带有 9 处要同步（`composer.json` / `composer.lock` /
  `phpstan.neon` / `config/app.php` 的 `php_min` / 两个启动脚本的回落值 / 全量源文件头注释 /
  `Logger.php` / CI 矩阵 / 文档），完整清单与「必须复跑的三件事」见
  `docs/代码质量工具链说明.md` §11.8。
- **当前 level = 6**（2026-09-22 由 5 提升）。提级前量化：level 6 全量 538 errors / 66 文件，
  **100% 是 `missingType.*`，零语义告警**；生产侧 265 条已补 phpdoc 清零，测试侧 273 条冻结进
  `phpstan-tests-baseline.neon`。**level 6 不新增逻辑类检查**，别指望它多抓 bug。
- 补标注纪律：**只加 phpdoc、不加原生返回类型**（后者会改运行期行为）；默认
  `array<string, mixed>`，`$keys`/`$members` 这类列表用 `array<int|string, mixed>`。
  **替换既有 tag 时只替换 `array` 这一个词** —— 整段替换会丢掉 `null|`、`|string` 分支。
- `phpstan-strict-rules` / `phpstan-phpunit` 是**显式 `includes`** 的（未装 `extension-installer`）；
  只装 composer 包不写 `includes`，规则一条都不会生效。同理 `phpstan.neon` 的 `scanFiles`
  必须列 `tools/phpcs/Sniffs/*.php` —— phpcs 的 composer.json **没有 autoload 段**，
  不声明它们，`tests/Manual/phpcs_business_rules_check.php` 一实例化就报 `class.notFound`。

- **`composer cs` 落地排版后必须复跑 `composer test`**：`StartupBannerTest`、`LoggerTest` 等用例
  是**读源码做正则断言**（锁「时机与结构」，运行期断言覆盖不到），而 `array(...)`→`[...]`、
  `! empty(`→`!empty(` 这类纯排版改动会把锚点打散 —— 2026-09-22 曾因此红过 3 个用例。
  锚点应写成**容忍两种等价写法**的形式（它们锁结构，不该对排版有观点）。
  详见 `docs/代码质量工具链说明.md` §8.6。

- **CI 在 `.github/workflows/ci.yml`**，三个作业按**外部依赖**划分（不按快慢）：
  `static`（单一 PHP 8.2：`composer validate --strict` → `analyse` → `lint` → `lint:self` → `cs:check`）/
  `test`（PHP **8.2~8.5 矩阵**，8.5 为实验性 `continue-on-error`）/
  `e2e`（Redis 7 service + 全 6 角色：`e2e_check` → `test:client-e2e` → `api_sign_check.js` → `demo:http`）。
  **CI 直接调上面同一套 composer script，不另写一套命令**；触发器同时挂 `main` 与 `master`
  （默认分支是 `main`，但活跃推送在 `master`，只挂一个会永不触发）。
- **`composer cs` 之后必须把 PHP 文件统一回 LF，再跑 `composer lint`**：`line_ending => false`
  之下 fixer 会写出**混合行尾**（它改写的行落成 LF、未触碰的行保留 CRLF），phpcs 会因此
  吐出上百条指向注释的假阳性（2026-09-22 实测 **155 errors**）。
  ⚠ **不得触碰** `bin/start.bat` / `bin/start.ps1` 的 CRLF（项目硬约束）——
  该约束已由根目录 `.gitattributes` 的 `eol=crlf` 跨机器保证，见 §6。
- ⚠ **别把「`cs:check` 报的一堆文件」直接归因成行尾**：必须**先统一行尾、再跑 `cs:check`**，
  剩下的才是真排版问题。2026-09-22 的 41 个里，**33 个是补标注引发 `phpdoc_align` 列对齐失配的真问题**、
  1 个是 `escape_implicit_backslashes` 真违规、只有 7 个是行尾假象 —— 整批归因行尾会让 CI 首跑就红。
- **改过任何 docblock 的 tag 类型（补标注 / 换类型）→ 必须跑一次 `composer cs`**：
  `@param` 类型变长（`array` → `array<string, mixed>`）会让同组 `@param` 的列对齐必然失配。
  两条配套纪律详见 `docs/代码质量工具链说明.md` §8.7 / §8.8。

### 4.1 风格工具分工（越界即长期噪声）

| 工具 | 职责 | 会写文件吗 | 配置文件 |
|---|---|---|---|
| **php-cs-fixer** | **排版**：空白 / 换行 / 缩进 / 括号 / 引号 / 可见性声明 / phpdoc 标签顺序与对齐 / 语法现代化 | ✅ 仅 `composer cs` | `.php-cs-fixer.dist.php` |
| **phpcs** | **审计**：注释的完整性与正确性 / 命名 / 业务红线（`exit`·`sleep`·`pcntl_fork`·Redis 键字面量） | ❌ 只读不写 | `phpcs.xml.dist` |

- **排版只允许 `composer cs` 落地，禁止用 `phpcbf`**：两个工具会对同一段代码反向修
  （Squiz 要求 long form `integer`，fixer 的 `phpdoc_scalar` 立刻改回 `int`，来回震荡）。
- 两边都刻意关掉了一批规则，**改规则前先读文件头的「界外」清单**：
  `.php-cs-fixer.dist.php` 有四个界外（注释内容 / 运行语义 / 项目压倒性约定 / 结构改动），
  `phpcs.xml.dist` 文末有四类「刻意排除」。这些排除项都带实测数据，不要凭直觉删。
- `tools/phpcs/Sniffs/` 是本项目自定义嗅探器。`RedisKeyLiteralSniff::$prefixes` 是
  `RedisKeys` 常量的**手写副本**，**新增 Redis 键后必须同步**，否则红线静默失效 ——
  `composer lint:self` 用反射做漂移检测专门兜这一点。
- 角色/端口/键名这类硬编码守卫也已嗅探器化：`ForbiddenCallSniff` 覆盖 `exit`·`die`·`sleep`·
  `usleep`·`pcntl_fork`（作用域 `src/`、`client/src/`，豁免 `client/src/Cli/Debugger.php`）。

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
| `*.php` | LF | 无 BOM（`composer cs` 落地后须统一回 LF） |
| `bin/start.ps1` | CRLF | UTF-8 **带 BOM** |
| `bin/start.bat` | CRLF | **纯 ASCII**（不得含中文） |

**行尾由根目录 `.gitattributes` 强制，不依赖 `core.autocrlf`**（2026-09-23 落地）：

```
* text=auto
*.sh text eol=lf     *.php text eol=lf
*.bat text eol=crlf  *.cmd text eol=crlf  *.ps1 text eol=crlf
```

- 不能简写成 `* text=auto eol=lf` —— 那会让两个 Windows 脚本在 Linux 检出下变成 LF，而
  「`.bat` 必须 CRLF」是硬约束。必须显式开 `eol=crlf` 例外。
- **`.gitattributes` 的 `eol` 只管 checkout，索引恒为 LF**（`text` 属性下 git 从不把 CRLF 存进索引）。
  故此前 `bin/start.bat` 显示 `i/lf w/crlf` **不是缺陷** —— 缺陷是「CRLF 只靠本机 `autocrlf=true` 凑出来」，
  Linux 检出 / `autocrlf=input` 的克隆会拿到 LF 脚本。加属性后这条依赖被切断。
- 验证方式（与 `core.autocrlf` 无关）：`git config core.autocrlf false` → 删除文件 →
  `git checkout -- 文件` → 复核仍为 CRLF 且 `start.ps1` BOM 仍在。

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
