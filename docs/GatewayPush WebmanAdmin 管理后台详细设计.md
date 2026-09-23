# GatewayPush WebmanAdmin 管理后台详细设计

> **定位**：本文件是管理后台的**可实施详细设计**，把《GatewayPush 管理后台设计方案》的
> 0~8 章收敛为「目录结构 / DDL / 接口契约 / 复用点 / 测试计划」五件可直接落地的东西。
>
> **权威顺序：代码 > `docs/GatewayPush 对外接口文档.md` > `README.md` > 本文件。**
> 本文件中的每个契约都标注了真源位置（`文件:行号`）；与代码冲突时以代码为准并修正本文件。
>
> ⚠ **本文档 §3 涉及对主项目（`src/`、`config/`）的 6 处改动，均处于「设计已定、代码未动」状态**，
> 落地前需按项目约定逐项确认。**§0.4 已给出这 6 处的推荐取值、替代方案与连带成本**；
> 其中「运维动作的信任域」**已于 2026-09-23 拍板为选项 A**（见 §0.4 末节、§9.2 的 R11）。

---

## 目录

- [0. 前置结论（含对原方案的实测修订）](#0-前置结论含对原方案的实测修订)
- [1. 复用点矩阵](#1-复用点矩阵)
- [2. 架构与部署](#2-架构与部署)
- [3. 主项目侧改动详细设计](#3-主项目侧改动详细设计)
- [4. 接口契约](#4-接口契约)
- [5. 数据层](#5-数据层)
- [6. RBAC 权限点矩阵](#6-rbac-权限点矩阵)
- [7. P0~P5 分期详细设计](#7-p0p5-分期详细设计)
- [8. 测试计划](#8-测试计划)
- [9. 风险与未决项](#9-风险与未决项)
- [10. 变更同步约定](#10-变更同步约定)
- [11. 实施记录与实测偏差（2026-09-23）](#11-实施记录与实测偏差2026-09-23)

---

## 0. 前置结论（含对原方案的实测修订）

### 0.1 六处修订（原方案假设 vs 代码实测）

设计前对主项目做了逐项核对，**有 6 处原方案的假设与代码不符或不可行**，必须先修订，
否则 P3/P4 阶段会返工：

| # | 原方案表述 | 实测事实 | 影响 |
|---|---|---|---|
| **R1** | `/push` 的 `target_type` 含 `topic` / `all?` | ❌ **只有 `uid` / `device` / `client`**（`src/Business/Push.php:53-55`，`resolveTarget` 兜底回 `uid`，`Push.php:1008-1012`）。主题广播走 `Push::enqueueTopic()`（`Push.php:307`），**当前无 HTTP 入口** | M3「按主题推送」一期**不做**；如需二期，须新增 HTTP 入口（属对外接口变更，成本高） |
| **R2** | 后台复制 `Auth::tokenFingerprint` 直写 `auth:revoked:*` | ⚠️ 该方法是 **`protected`**（`src/Business/Auth.php:330`），后台无法调用；算法 = `substr(hash('sha256', $token), 0, 32)` | 放弃「后台直写」方案，改走 §3.3 的新动作（与原方案 §6.3 的决策一致，但理由更硬） |
| **R3** | 后台内置 `GatewayClient` 连 Register 踢线（备选 B） | ❌ 项目**未引入 `workerman/gatewayclient` 包**（`composer.json` require 无此项，`vendor/workerman/` 下无该目录）。`Lib\Gateway::closeClient()`（`vendor/.../Lib/Gateway.php:821`）是 **BusinessWorker 内的静态门面**，依赖 `setBusinessWorker()` 注入的连接池 | 备选 B **直接不可行**（不是「偏离原则」，是技术上做不到）。踢线只能在 **business 进程内**执行 |
| **R4** | 踢线需新增 HTTP 端点 | ✅ 有更优解：**新增业务动作 + 动作通道白名单**，复用现有签名 / 双防线 / 限流 / 回执 / 指标全链路，**零新增端点、零新增队列、零新增 Redis 键** | §3.1 是本次设计的**核心技术决策** |
| **R5** | 踢线对 UDP 一样生效 | ❌ UDP 的 `clientId = udp:{ip}:{port}` **不在 Gateway 连接表内**（`src/Gateway/Bootstrap.php:164` 注释明示），`closeClient()` 对其无效 | 踢线**仅对 WS 生效**；UDP 无连接语义，只能靠 `revoke` 让后续包验签失败 |
| **R6** | 会话管理读取 `session:{clientId}` 的字段 | 实际字段为 `client_id / uid / device_id / protocol / client_ip / client_port / gateway / connect_at / last_active`（`src/Business/Session.php:88-98`），另有 `offline_at`（`Session.php:173` 由 `markOffline()` 写入）；**另有独立心跳键 `heartbeat:{clientId}`**（原方案漏，`Session.php:105`） | §5.1 键映射表按实测字段给出 |

### 0.2 已决策项（固化，后续不再讨论）

| 决策点 | 结论 |
|---|---|
| 定位 | 与现有 Dashboard 并存；Dashboard 服务「大屏/探针」，后台服务「人操作」，入口互链 |
| 功能范围 | M1 监控 / M2 会话 / M3 推送 / M4 配置 / M5 运维 全覆盖 |
| 管理端账号 | WebmanAdmin 自带 RBAC（MySQL），与推送系统 `AUTH` 体系**完全隔离** |
| 集成方式 | 读走 Redis，写走现有 HTTP API；**不侵入 GatewayPush 核心** |
| 前端 | Layui + PHP 模板（WebmanAdmin 默认） |
| 踢线 | **主项目新增 `kick` 动作能力**（落地形态见 §3.2，与原方案「加 HTTP 端点」的差异见 R4） |
| Token 撤销 / 设备解绑 | 与 kick 一并做成新动作，**后台不直写 `auth:*`** |
| 推送历史深度 | 接受「后台自己的受理记录（`accepted`）+ 不承诺逐条投递回执」 |
| 角色编排 | 后台**不纳入** `bin/start.*` 的第 7 角色，一期独立 `admin/` 目录手动起 |
| 管理员规模 | 超管 / 运维 / 只读 三角色够用 |
| 网络暴露 | 一期回环 `127.0.0.1:8292`，内网跳板访问 |
| **运维动作的信任域** | **接受「持 `API_SECRET` 即拥有 `kick` / `revoke` / `unbind` 权」**（选项 A，**2026-09-23 拍板**）。`API_SECRET` 认定为**管理面凭证**，不得下发给业务调用方；落地清单与升级触发条件见 §0.4 |

### 0.3 主项目改动清单（⚠ 待确认，共 6 处）

| # | 文件 | 改动 | 规模 |
|---|---|---|---|
| C1 | `src/Business/ActionRunner.php` | 在 `run()` 的 HTTP 白名单后**增加反向通道白名单**（`channels` 声明） | ~12 行 |
| C2 | `src/Api/Bootstrap.php` | `handleAction()` 的前置白名单**增加同源通道判定**（保持「入队前拒绝」语义） | ~10 行 |
| C3 | `config/actions.php` | 新增 `channels` 字段说明 + 3 个运维动作声明 | ~45 行 |
| C4 | `src/Business/Action/{KickAction,RevokeTokenAction,UnbindDeviceAction}.php` | 3 个新处理器 | 3 个新文件 |
| C5 | `config/app.php` | `monitor.metrics` 追加 `action_kick` / `action_revoke` / `action_unbind` | 1 行 |
| C6 | 文档 | `docs/GatewayPush 对外接口文档.md` §8.1 / §8.5、`README.md` 动作章节 | 同步 |

> **C1 + C2 是安全前提，不可省略。** 理由见 §3.1。

### 0.4 六处改动的推荐取舍（含已决策项）

> 本节 C1~C6 的取值是**建议**；其中「运维动作的信任域」**已于 2026-09-23 拍板为选项 A**（见本节末）。
> P0~P3 期间 C1~C6 **一处都不动**（保持零侵入）。

**总原则：全做，但捆绑为 P4 的一次性交付。** 若最终决定不做任何写操作（踢线/撤销/解绑），
则 C1~C6 **整体归零**，后台定位回落为「只读管理台」——这不影响 P0~P3 的任何设计。

| # | 建议 | 关键理由 | 可接受的降级 |
|---|---|---|---|
| C1 | ✅ **做，且范围要扩大**（不止 `run()` 里加判定） | 现有 `http` 字段是**单向**的（只表达「额外开放 HTTP」），**无法表达「只开 HTTP」**；`session` 动作（`config/actions.php:87-90`，声明中无 `http` 键）已印证这一点。故运维动作必须新增**反向**字段 | 无。这是 C3 / C4 的安全前提 |
| C2 | ✅ 做，但实现方式改为**调用 C1 新增的访问器** | Api 侧不要复制 `in_array` 判定，应调 `ActionRunner::channelExposed($action, ActionContext::CHANNEL_HTTP)`，与既有 `httpExposed()`（`ActionRunner.php:244`）对称。落点天然在 `Api/Bootstrap.php:526` 之后（`$decl` 已取到） | 可降级为「不做」：仅损失「入队前拒绝」的体验，会在**执行期**才报 4006（后台会看到 `202` 已受理、补查才知失败）。**功能上仍然安全** —— C1 是执行方裁定 |
| C3 | ✅ 做，但**必须加两道防呆** | ① `channels` **绝不可**写进 `defaults`（`config/actions.php:66`）—— 一旦写进去等于**全部动作同时收紧**且不报错（`ActionRunner.php:151` 是 `array_merge($defaults, $decl)`，未声明该键的动作会继承） ② 值写**字符串** `'http'` 而非 `ActionContext::CHANNEL_HTTP` —— `config/*.php` 现有文件**零 `use` 语句**，引类常量会让配置文件依赖 autoload / 类加载顺序 | 无 |
| C4 | ✅ 3 个处理器一起做 | C3 的声明已就位，拆开无收益；`revoke` / `unbind` 各自只调一个 public 方法（`Auth::revoke` / `Auth::unbindDevice`） | 可只做 `kick`（唯一的新能力），`revoke` / `unbind` 延后 |
| C5 | ✅ 做（1 行纯追加） | `config/app.php:278-281` 的注释**已明文约定**「新增动作时需同步追加」；漏登记 = 面板指标字典静默漂移 | 无 |
| C6 | ✅ 必须做，且**比原设计多一处** | 除 §8.1 / §8.5 / README 外，**HTTP 动作清单会出现在对外响应里**（`Api/Bootstrap.php:214` 输出的 `http_actions`）—— 声明 `'http' => true` 后该字段自动多 3 项，属对外契约变化 | 无（项目硬约束：改对外接口须同步 README + 对外接口文档） |

#### C1 的实现要点（建议补进 §3.1）

除 `run()` 内的判定外，**同时新增与 `httpExposed() / httpActions()` 对称的访问器**，供 C2 与后台复用：

```php
/** 动作是否向指定通道开放（未声明 channels = 全通道开放） */
public static function channelExposed(string $action, string $channel): bool;

/** 动作声明的通道白名单；未声明时返回 null（= 全通道） */
public static function allowedChannels(string $action): ?array;
```

同步在 `ActionRunner::load()` 的归一化段（`ActionRunner.php:151-164`）加一行：把 `channels` 归一化为
`list<string>`（去重 + 只保留 ws、udp、http 三个合法值），非法值视为**未声明**（放行）并记
`Logger::warn` —— 与 `http` 字段「宽松声明、归一化为 bool」的既有风格一致
（见 `ActionRunnerTest::testHttpExposureIsBoolTypedEvenWhenDeclaredLoosely`）。

#### 未计入 §0.3 的连带成本（做 C1~C6 时必付）

| 项 | 内容 | 规模 |
|---|---|---|
| T1 | `tests/Unit/ActionRunnerTest.php` **已有**完整的「HTTP 通道白名单」区块（`:291-345`，5 个用例）—— `channels` 需补**对称区块**：未声明放行 / 声明 `[http]` 时 ws 拒 / udp 拒 / http 放行 / 非法值视同未声明 | ~6 个用例 |
| T2 | `tests/E2E/CaseActionRouting.php`（或 `CaseActionUdp.php`）补 1 条：**WS 通道调 `kick` → 4006** —— 验证 C1 真正生效的关键用例，纯 WS 不依赖新角色 | 1 用例 |
| T3 | PHPStan level 6：`channels` 的 phpdoc 元素类型须标 `list<string>`，否则新增 `missingType.iterableValue` 告警 —— **新告警不得进 baseline**（见 `AGENTS.md`） | 标注 |
| T4 | `tests/Api/http_demo.php`（13 场景）的动作清单 | 与 C6 合并 |

#### ✅ 运维动作的信任域 —— 已决策：接受该等式（选项 A，2026-09-23）

运维动作声明为 `auth => false` + `channels => [http]`，其**实际语义**是：

> **持 `API_SECRET`（或其回退值 `AUTH_SECRET`）者 = 拥有踢线 / 撤销 / 解绑权。**

这是「最小改动」与「最小权限」之间的固有张力。**决策：一期接受该等式（选项 A）**。

| 选项 | 做法 | 代价 | 状态 |
|---|---|---|---|
| **A** | 接受该等式，并把 `API_SECRET`**明确认定为管理面凭证**、在 `.env` 注释与运维文档里写明「不得下发给业务调用方」 | 0 行代码 | ✅ **已选（2026-09-23）** |
| B（备选留档） | 运维动作加**动作级密钥**：Api 侧对「声明了 `channels` 的动作」额外校验 `X-Admin-Key`（独立 `.env` 项） | Api 侧 ~15 行 + 新增 1 个 .env 项 + 客户端 `AdminApi` 相应支持 | ⏸ 触发条件满足时升级（见下） |
| C（已否决） | 运维动作 `auth => true`，靠 uid 区分 | ❌ **语义错误**：运维端没有 uid，且 uid 是**被操作对象**而非**操作者身份** | ❌ 永不采用 |

**选 A 的论证（决策依据，非推测）**：

1. `/push` 本身已具备「向任意 uid 投递任意消息」的能力 —— 其破坏力与「踢线」同量级。
   换言之，**引入 kick 并未扩大持有 `API_SECRET` 者的实际权限面**，只是让既有权限显性化。
2. 部署形态为单机 + 回环 API（`API_LISTEN=http://127.0.0.1:8290`），业务调用方与后台处于同一信任域。
3. 选项 B 的 `X-Admin-Key` 只有在「`API_SECRET` 需分发给 ≥2 个互不信任主体」时才有收益，
   当前不成立 —— 提前引入属无效复杂度。

##### A 的落地清单（随 C1~C6 一并做，**本次未改动任何代码/配置**）

| # | 文件:落点 | 内容 | 规模 |
|---|---|---|---|
| **A1** | `.env.example:265-266`（`API_SECRET` 注释） | 追加一句：「本密钥同时是**管理面凭证** —— 持有它即可调用 `kick` / `revoke` / `unbind`，不得下发给业务调用方」 | 1~2 行 |
| **A2** | `README.md` 的 `.env` 变量表 | 同步 A1 的表述（项目硬约束：改 `.env` 变量说明须同步 README） | 1~2 行 |
| **A3** | `docs/GatewayPush 对外接口文档.md` §2.3（或动作章的 `channels` 说明处） | 补一条告警块：「HTTP 通道的 `API_SECRET` 即管理面凭证；声明 `channels = [http]` 的动作只应暴露给管理端」 | 数行 |
| **A4** | 后台 `admin/docs/deploy.md`（P0 阶段创建） | 写明：后台**只持** `ADMIN_API_SECRET`；该值**不得与任何业务调用方共享**；后台不得提供「按调用方切换密钥」的入口 | 数行 |
| **A5** | 后台 `AdminApiClient` 设计约束 | 密钥读取路径**唯一**：`ADMIN_API_SECRET` → 留空回退主项目 `API_SECRET`；禁止多密钥池 | 设计约束 |

##### 升级触发条件与护栏（选项 A → B）

以下条件**任一成立**时，必须重新评估并升级到选项 B（`X-Admin-Key`）：

1. `/push` 或 `/action` 的调用方出现 **≥2 个互不信任的主体**（例如外部业务方也要直连 API）；
2. `API_LISTEN` 从 `127.0.0.1` **变更为非回环地址**（`0.0.0.0` / 具体网卡 / 经反代对外）；
3. 需要**按调用方归属**做独立限流或独立审计（当前审计只记「谁在后台点的按钮」，无法区分「哪个调用方踢的线」）。

> 该护栏已登记为 §9.2 的 **R11**（风险台账），确保日后改 `API_LISTEN` 时会被复查。

---

## 1. 复用点矩阵

原方案 §4.1 / §8 提了两条路径（`autoload psr-4` 映射 `../src/`，或抽共享包）。
**实测结论：两条都不需要** —— 后台是独立 webman 应用，跑在**独立进程 + 独立 PHP 进程生命周期**里，
而 `RedisKeys`（常量类）与 `Auth`（workerman 回调风格静态类）的复用代价差异极大：

| 复用对象 | 能否直接复用 | 理由与做法 |
|---|---|---|
| `GatewayPush\Common\RedisKeys` | ✅ **可复用（推荐）** | 纯常量 + 静态方法、零 IO、零 workerman 依赖。后台 `composer.json` 加 `"GatewayPush\\": "../src/"` 的 psr-4 映射即可。**单文件零副作用的理想复用点** |
| `GatewayPush\Client\Protocol\Signer` | ❌ **不该复用** | 它是**报文层**（WS/UDP）签名：`hmac_sha256("cmd\|seq\|ts\|device_id\|token\|canonicalize(data)")`（`client/src/Protocol/Signer.php:41-44`）。后台只需 **HTTP 层**签名，基串完全不同（见下） |
| `GatewayPush\Client\Service\AdminApi` | ✅ **扩展复用（推荐）** | 已存在（`client/src/Service/AdminApi.php`），**当前只封装 `/push` `/stats` `/health` 三个方法**，缺 `/action` 与 `/action/{id}`。后台所用的是同一套 HTTP 签名，**在 client 侧补齐两个方法即可，杜绝第三份签名实现** |
| `GatewayPush\Client\Transport\HttpTransport` | ⚠️ 不建议复用 | 基于 workerman `AsyncTcpConnection` 手写原始 HTTP 报文（`HttpTransport.php:5-15` 注释说明为何不能用 `http://` 协议）。webman 自带 `support\Http`/Guzzle 风格的同步客户端更好用；**复用点应停在「签名算法」，而非传输层** |
| `GatewayPush\Business\Auth` | ❌ 不可复用 | workerman 回调风格（`RedisClient::get($k, fn)`）、静态配置、`$config` 为 `protected`；且**关键方法 `tokenFingerprint()` 是 protected**（R2）。后台**不得**复制该算法 |
| `GatewayPush\Business\Monitor` | ❌ 不可复用 | 同上（静态类 + 异步回调 + 依赖 `Task`/`Session`）。后台读指标走 `GET /stats` 或直读 `metrics:*` 键 |
| `GatewayPush\Common\RoleCatalog` | ⚠️ 不必复用 | 后台要走的是 `php start.php roles` 的 **JSON 输出契约**（`src/Console/Commands.php:48`），不是 PHP 类。**这是对外契约，不随实现变** |
| `GatewayPush\Common\Env` | ❌ 不可复用 | 它依赖 phpdotenv 加载主项目 `.env`，后台有自己的配置体系（webman `config/`） |

**底线（沿用主项目红线 ㉝）**：后台**禁止手写 `'session:'` / `'queue:'` 等字面量**。
做法二选一：

- **首选**：psr-4 映射 `GatewayPush\Common\` 后引用 `RedisKeys::session($id)`；
- **兜底**（若映射不可行）：在后台内建 `RedisKeysMirror` 常量类，并**复制主项目
  `tests/Unit/RedisKeysTest.php` 的金标断言**做漂移检测 —— 两份常量对不上即测试失败。

### 1.1 两套签名的准确区分（最易搞混）

| 层 | 算法 | 密钥 | 出现位置 |
|---|---|---|---|
| 报文层（WS/UDP） | `hmac_sha256("cmd\|seq\|ts\|device_id\|token\|canonicalize(data)")` | `AUTH_SECRET` | `src/Business/Message.php`，客户端封装 `client/src/Protocol/Signer.php` |
| **HTTP 层** | `hex(hmac_sha256("{X-Timestamp}\|{原始请求体}", api.secret))` | `API_SECRET`（留空回退 `AUTH_SECRET`） | `src/Api/Bootstrap.php`；客户端实现在 `client/src/Service/AdminApi.php:189-196` |

后台只用**第二行**。关键细节（`docs/GatewayPush 对外接口文档.md` §2.3）：

- 参与签名的是**原始请求体字节**，不是重新序列化的数组；
- `GET` 请求体为空串 → 签名基串为 `"{ts}|"`；
- 时间窗 `API_SIGN_TTL`(300s)，超出 → `401` / `4002`；
- **不要自己拼 `json_encode`**：后台侧必须「编码一次、签名它、发送它」，避免「签的 body ≠ 发的 body」。

### 1.2 服务端能力复用

| 能力 | 入口 | 契约来源 |
|---|---|---|
| 存活探测 | `GET /health`（免签） | 对外接口文档 §6.3 |
| 指标快照 | `GET /stats`（需签） | §6.4；`gauge` field 命名规则见线上同节 |
| 指标快照（免签） | `GET http://127.0.0.1:8291/metrics.json` | §7；比 `/stats` 多 `meta` 段（`now`/`interval`/`ttl`/`enable`/`refresh`），**后台展示新鲜度优先用它** |
| 定向推送 | `POST /push` | §6.5 |
| 业务动作 | `POST /action` + `GET /action/{id}` | §6.6 / §6.7 |
| 角色启用清单 | `php start.php roles` → `{"roles":[{"role","enabled","env"}]}` | `src/Console/Commands.php:32-67` |
| 端口探测 | `php start.php info <roles>` | README「启动横幅」章 |

> **后台**必须**自行**判角色在线（`netstat` + `/health`），不能只信 `roles` 的 `enabled` —— 后者是
> 「配置是否开启」，不等于「进程是否在跑」。

---

## 2. 架构与部署

### 2.1 数据流

```
┌──────────────────────────────────────────────────────────────┐
│  WebmanAdmin 管理后台（独立应用，127.0.0.1:8292）              │
│  webman + Layui + MySQL（RBAC / 审计 / 推送历史）              │
└──────┬──────────────────────────────┬────────────────────────┘
       │ ① 只读                        │ ② 写（走现有 HMAC API）
       ▼                              ▼
┌──────────────────┐        ┌─────────────────────────────┐
│  Redis           │        │  Api :8290（HMAC 验签）      │
│  gwpush:* 键空间 │        │  GET  /health  /stats       │
│  （推送系统唯一   │        │  POST /push    /action      │
│    状态存储）     │        │  GET  /action/{id}          │
└────────▲─────────┘        └──────────▲──────────────────┘
         │                             │
         │                    ┌────────┴─────────┐
         │                    │ queue:action:in  │ ← 动作入队
         │                    └────────┬─────────┘
┌────────┴─────────────────────────────▼──────────────────────┐
│  GatewayPush 既有 6 角色（默认不改；仅 §3 的 6 处待确认改动） │
│  register:1238 │ gateway:8282 │ udp:8283 │ business │ api:8290 │ dashboard:8291 │
└──────────────────────────────────────────────────────────────┘
```

**关键点**：写操作**不直连 Redis**，而是走 `POST /action` → `queue:action:in` → **business 进程**
执行（`kick` 必须在 business 进程内执行，见 R3）。

### 2.2 进程与端口

| 项 | 值 | 说明 |
|---|---|---|
| 监听 | `127.0.0.1:8292` | 避开 8290/8291；**必须回环**（上游无 TLS） |
| 进程 | webman 独立进程 | 与 GatewayWorker 不同入口、不同框架、崩溃互不影响 |
| 启动 | `cd admin && php start.php start` | 一期**不进** `bin/start.*` 编排 |
| 停止 | `cd admin && php start.php stop` | 同样**不走**主项目脚本（避免与主项目 stop 语义混淆） |
| PHP | 8.2 ~ 8.5 | 与主项目同区间；**禁用 8.3+ 语法**（沿用主项目口径） |
| MySQL | 8.x，库名 `gateway_push_admin` | 仅后台使用；**推送系统运行不依赖 MySQL**（后台挂了不影响推送） |
| Redis | 复用主项目同一实例同一 DB | 主项目 `REDIS_DB` 可非 0，后台必须读同一个库 |

> **Windows 端口复核**（主项目红线 ㊳/㉖）：启后台前用 `netstat -a -n` 确认 8292 空闲。
> Windows 不拒绝重复 bind，两套后台实例叠加不会报错，只会表现为「页面数据随机来自两个库」。

### 2.3 目录结构

```
admin/                                  # 自洽子项目（类比 client/）
├── composer.json                       # webman + webman-admin + mysql + psr-4 映射
├── start.php                           # webman 入口（后台自有，不改主项目 start.php）
├── .env.example                        # 后台自有配置模板（不含任何推送密钥）
├── config/
│   ├── server.php                      # listen = 127.0.0.1:8292
│   ├── database.php                    # MySQL（仅后台库）
│   ├── redis.php                       # 复用主项目 Redis（host/port/db/prefix）
│   ├── gateway_push.php                # 主项目对接配置（api_url / api_secret / roles_cmd / log_dir）
│   ├── route.php                       # Layui 页面路由 + /api/* JSON API 路由
│   ├── plugin/webman/admin/            # webman-admin 插件配置
│   └── middleware.php                  # 鉴权中间件注册
├── app/
│   ├── controller/
│   │   ├── PageController.php          # 渲染 Layui 页面（薄壳）
│   │   └── Api/
│   │       ├── MonitorController.php   # M1
│   │       ├── SessionController.php   # M2
│   │       ├── PushController.php      # M3
│   │       ├── ConfigController.php    # M4
│   │       └── OpsController.php       # M5
│   ├── model/
│   │   ├── PushTask.php
│   │   ├── PushTemplate.php
│   │   ├── AuditLog.php
│   │   └── Setting.php
│   ├── service/
│   │   ├── GatewayPushClient.php       # 封装 AdminApi（签名 + 错误分层归一化）
│   │   ├── RedisReader.php             # 只读 Redis 门面（唯一允许读 gwpush:* 的入口）
│   │   ├── SessionInspector.php        # M2 会话聚合（会话/心跳/订阅/离线队列）
│   │   ├── MetricsAggregator.php       # M1 指标聚合与派生率计算
│   │   ├── RoleProbeService.php        # roles JSON + netstat + /health 三源合一
│   │   ├── LogTailService.php          # 只读日志尾读与过滤
│   │   ├── SecretMasker.php            # 密钥脱敏（前 4 后 4）
│   │   └── Auditor.php                 # 写操作审计落库（唯一入口）
│   └── middleware/
│       ├── AdminAuth.php               # WebmanAdmin 登录态
│       └── Permission.php              # 权限点校验（§6）
├── view/                               # Layui 模板
│   ├── dashboard/index.html
│   ├── monitor/metrics.html
│   ├── session/list.html  session/detail.html
│   ├── push/create.html   push/history.html   push/queue.html
│   ├── config/env.html    config/roles.html
│   └── ops/logs.html      ops/redis.html      ops/audit.html
├── tests/
│   ├── Unit/                           # 纯逻辑单测（复用主项目的假 DOM / 假传输层思路）
│   │   ├── SignerParityTest.php        # ★ 与 client AdminApi 签名同源（金标向量）
│   │   ├── MetricsRateTest.php
│   │   ├── SecretMaskerTest.php
│   │   ├── AuditorTest.php
│   │   └── RedisKeysMirrorTest.php     # 仅走兜底方案时需要
│   └── Integration/
│       └── ApiContractTest.php         # 对 /health /stats /push /action 的真实调用（需服务在线）
├── docs/
│   └── deploy.md                       # 后台部署与 MySQL 备份策略
└── runtime/                            # 后台自己的 runtime（已 gitignore）
```

**目录语义约定**（沿用主项目口径）：`admin/runtime/` **只放产物**（日志/pid），人工资产不放。

---

## 3. 主项目侧改动详细设计

### 3.1 动作通道白名单（`channels`）—— 安全前提

#### 问题

`docs/GatewayPush 对外接口文档.md` §8.5 明确：**「WS / UDP 侧凡注册即可用」**，
`'http' => true` 只是**额外**开放 HTTP 通道。`src/Business/ActionRunner.php:318-332` 的第二道防线
也是**单向**的（只拦「HTTP 未开放」）：

```php
// ActionRunner.php:321 —— 现状：只判 HTTP 是否开放，不判其它通道是否允许
if ($channel === ActionContext::CHANNEL_HTTP && empty($decl['http'])) { /* 4006 */ }
```

**后果**：若把 `kick` / `revoke` / `unbind` 直接登记进 `config/actions.php`，
则**任何已鉴权的普通客户端**（持自己合法 Token 的 ws/udp 连接）都能调用 `kick` 踢任意 clientId ——
这是**严重提权漏洞**，比「改主项目代码」的代价大得多。

> **两个信任域问题必须分开看**：本节解决的是「**WS/UDP 通道**不得触达运维动作」（终端客户端提权）；
> 另一个是「**HTTP 通道内**，持 `API_SECRET` 者即可调用运维动作」——
> 该问题**已决策：接受**（选项 A，决策依据与落地清单见 §0.4 末节）。

#### 解法：给动作声明加反向白名单

在 `config/actions.php` 的动作声明中新增可选字段 `channels`（**缺省 = 全通道，完全向后兼容**）：

```php
'channels' => [ActionContext::CHANNEL_HTTP],   // 仅 HTTP 可调，WS/UDP 一律 4006
```

**C1 — `src/Business/ActionRunner.php`**，紧接 `run()` 的 HTTP 白名单之后（现 318-332 行）插入：

```php
// 反向通道白名单：声明了 channels 的动作只在这些通道开放 —— 与上面那道互为反向，
// 缺省时不做限制（既有 7 个动作完全不受影响）。
// 运维动作（kick / revoke / unbind）必须声明 channels=[http]，否则任何已鉴权客户端
// 都能借 WS/UDP 通道调用，等于把管理面能力开放给所有终端用户。
$allowed = isset($decl['channels']) && is_array($decl['channels']) ? $decl['channels'] : null;
if ($allowed !== null && !in_array($channel, $allowed, true)) {
    self::fail(
        $clientId,
        $packet,
        $channel,
        Message::CODE_UNKNOWN_CMD,          // 4006：未知指令 / 动作未开放该通道
        '动作未开放该通道：' . $action,
        $action
    );

    return;
}
```

**C2 — `src/Api/Bootstrap.php`**，`handleAction()`（现 486 行起）的**入队前**白名单
（与现有「未知动作 / 未开放 HTTP → `400`+`4006`」同一处逻辑）追加：

```php
if (isset($decl['channels']) && is_array($decl['channels'])
    && !in_array(ActionContext::CHANNEL_HTTP, $decl['channels'], true)) {
    // 400 / 4006：动作未开放 HTTP 通道
}
```

> **为什么两道都要改**：`ActionRunner` 侧是**执行方裁定**（防「持 Redis 凭证者直接写 `queue:action:in`」，
> 见对外接口文档 §8.5 引用的硬约束 ㉗）；Api 侧是为了保持**「入队前拒绝」**的既有体验
> （否则后台会看到「已受理但执行时 4006」这种别扭的错误分层）。
> 这正是现有 `http` 白名单的双防线模式，本改动是它的**镜像**。

**回归影响**：既有 7 个动作**全部未声明 `channels`** → 行为完全不变。
`tests/Unit/ActionRunnerTest.php`（若存在通道相关断言）需补 3 条：未声明放行 / 声明 http 时 ws 拒绝 / 声明 http 时 http 放行。

### 3.2 `kick` 动作（踢线）

**为什么必须在 business 进程内**：见 R3 —— `Lib\Gateway::closeClient()` 依赖 BusinessWorker 的连接池，
而 business 进程已持有到所有 Gateway 的反向连接（`src/Business/Push.php:42` 已 `use GatewayWorker\Lib\Gateway as GatewayClient;`）。

**处理器**：`src/Business/Action/KickAction.php`

```
动作名  kick
channels [http]
auth    false          ← 调用方是持 API_SECRET 的运维端，无 uid 语义
params  client_id  可选 string max_len=128
        uid        可选 string max_len=64
        reason     可选 string max_len=128（仅落日志）
约束    client_id 与 uid 至少提供一个，否则 4007
```

**处理逻辑**：

1. 若给了 `uid` 未给 `client_id`：`SMEMBERS RedisKeys::uidClients($uid)` 展开为 clientId 列表
   （UDP 无连接语义，可能为空）；
2. 逐个判定：**`udp:` 前缀直接跳过**并计入 `skipped`（R5）；
3. 其余调 `GatewayClient::closeClient($cid)`，计入 `closed`；
4. 回执 `data`：
   ```json
   {"action":"kick","uid":"1001","requested":2,"closed":1,"skipped":1,
    "skipped_reasons":["udp:1.2.3.4:5678"],"at":1789983030}
   ```
5. 记 `Logger::warn`（运维敏感操作）+ `Monitor::incr('action_kick')`。

**语义边界（必须在后台 UI 上写明）**：

| 事实 | 说明 |
|---|---|
| **只断 TCP，不撤 Token** | `closeClient()` 触发 gateway `onClose` → business `Session::markOffline()` **保留会话供重连**（`Session.php:161`）。客户端可立刻用同一 Token 重连 |
| **要「踢下线且禁止重连」= kick + revoke 组合** | 后台 UI 的「强制下线」按钮应**串行**做两步：先 `revoke`（按 token），再 `kick`（按 clientId）。顺序不能反 —— 先踢后撤，客户端已在重连窗口内 |
| **UDP 无踢线** | R5。UDP 侧只能 revoke，且该 Token 的**下一个包**才会被拒（无连接可断） |

### 3.3 `revoke` 动作（Token 撤销）

**处理器**：`src/Business/Action/RevokeTokenAction.php`

```
动作名  revoke
channels [http]
auth    false
params  token  必填 string max_len=2048
        ttl    可选 int，0 = 取 AUTH_TOKEN_TTL（7200）
```

处理：`Auth::revoke($token, $ttl)`（`src/Business/Auth.php:227`，**public，可直接调用**），
回执 `{"action":"revoke","fingerprint":"a1b2…","ttl":7200,"at":…}`。

> **只接受明文 `token`，不接受 `fingerprint`**：指纹是 `sha256(token)` 前 32 位（R2），
> 后台若允许传指纹，等于开放「按猜测指纹撤销」的接口面。后台的会话详情页可从
> **`session:{clientId}` 取不到 token**（会话 Hash 不含 token）—— 因此
> **「撤销」操作在 UI 上必须以「粘贴 Token」为输入形态**，或由业务系统侧发起。
> ⚠ 这是原方案 §3.2 表格里「Token 撤销名单 `KEYS auth:revoked:*`」无法反向推出 token 的
> 必然结果，**UI 设计必须据此调整**。

### 3.4 `unbind` 动作（设备解绑）

**处理器**：`src/Business/Action/UnbindDeviceAction.php`

```
动作名  unbind
channels [http]
auth    false
params  uid  必填 string max_len=64
```

处理：`Auth::unbindDevice($uid)`（`Auth.php:302`，public），即 `DEL auth:bind:{uid}`。
回执 `{"action":"unbind","uid":"1001","unbound":true,"at":…}`。

**语义**：解绑后该 uid **下一次鉴权会重新「首绑胜出」**，即允许换设备登录。

### 3.5 指标登记（C5）

`config/app.php` 的 `monitor.metrics`（现 269-288 行）追加：

```php
'action_kick', 'action_revoke', 'action_unbind',
```

> 该清单是**结构性配置**，声明「需要采集的指标名」；漏登记不会报错，只是 `Monitor::incr()` 的
> 内存桶照样累加进而照样刷 Redis —— 但**面板与文档的指标字典会与实际不一致**，属静默漂移，故必须同步。

### 3.6 改动落点汇总（文件:行号）

| # | 落点 | 现状 | 动作 |
|---|---|---|---|
| C1 | `src/Business/ActionRunner.php:318-332` | HTTP 单向白名单 | 其后插入反向 `channels` 判定 |
| C2 | `src/Api/Bootstrap.php:486`（`handleAction`） | 入队前白名单 | 追加 `channels` 含 http 判定 |
| C3 | `config/actions.php:32-40`（声明字段说明） | 无 `channels` | 补字段说明；`:76` 的 actions 段追加 3 条 |
| C4 | `src/Business/Action/` | 7 个处理器 | 新增 3 个 |
| C5 | `config/app.php:269-288` | metrics 清单 | 追加 3 项 |
| C6 | `docs/` + `README.md` | — | §8.1 动作总表加 3 行、§8.5 补 `channels` 说明 |

---

## 4. 接口契约

### 4.1 复用接口（无需改动）

后台直接消费以下既有接口，契约以 `docs/GatewayPush 对外接口文档.md` 为准，本节只列**后台侧用法**：

| 接口 | 后台用途 | 注意 |
|---|---|---|
| `GET /health` | 存活卡片（10s 轮询，免签） | 响应 `{"service":"gateway-push-api","time":…}` |
| `GET /stats` | 指标卡（5s） | 需签；`GET` 签名基串 `"{ts}\|"` |
| `GET :8291/metrics.json` | 指标 + `meta`（新鲜度） | 免签；**比 `/stats` 多 `meta` 段**，M1 首选 |
| `POST /push` | M3 发起推送 | **只看 `HTTP 200` + `code=0`**，表示「已入队」 |
| `POST /action` | M2/M4 运维动作 | **判成败看响应体 `code`**；`202` → 轮询 `GET /action/{id}` |
| `GET /action/{id}` | 补查回执 | `404`+`4004` 有两义（仍在执行 / 已过期），`ACTION_RESULT_TTL` 仅 60s |

**错误分层（最易误判）**：

```
入队前失败  → 4xx/5xx（未知动作 400+4006 / 队列积压 503+5030 / 验签 401+4001）
执行后失败  → 200 + 业务码（如 4007 缺参，data.status=failed）
超窗        → 202 + status=pending（不是失败！）
```

后台的 `GatewayPushClient` **必须**把 `(httpStatus, code, data.status)` 三元组归一化为
`['ok'=>bool,'code'=>int,'msg'=>string,'status'=>string,'data'=>array]`，
**UI 不得直接展示 HTTP 状态码为成败**。

### 4.2 新增动作的调用契约（P4 起）

```bash
TS=$(date +%s)
BODY='{"action":"kick","params":{"uid":"1001","reason":"admin-ops"}}'
SIGN=$(printf '%s|%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$API_SECRET" -r | cut -d' ' -f1)

curl -s -X POST http://127.0.0.1:8290/action \
  -H 'Content-Type: application/json' -H "X-Timestamp: $TS" -H "X-Sign: $SIGN" \
  --max-time 20 -d "$BODY"
```

成功响应（`200` / `code=0` / `status=done`）：

```json
{"code":0,"msg":"ok","ts":1789983030,
 "data":{"request_id":"f96e53866166af3e","status":"done",
         "result":{"action":"kick","uid":"1001","requested":2,"closed":1,"skipped":1,"at":1789983030},
         "packet":{...}}}
```

失败阶梯与既有动作完全一致（`400 4006` 未开放通道 / `401 4003` 缺 uid 但 `auth=false` 故不触发 /
`401 4001|4002` 验签 / `429 4029` 限流 / `503 5030` 队列积压 / `200 + 4007` 参数缺失）。

### 4.3 后台自身 JSON API

统一前缀 `/api`，全部经 `AdminAuth` + `Permission` 中间件；写接口额外落 `admin_audit_log`。
响应统一为 `{"code":0,"msg":"ok","data":{...}}`（与主项目信封一致，便于前端复用解析逻辑）。

| 方法 | 路径 | 权限点 | 说明 |
|---|---|---|---|
| GET | `/api/monitor/summary` | `admin.monitor.view` | 聚合 `gauge`/`counter`/`queue`/`health` |
| GET | `/api/monitor/series?range=1h\|6h\|24h` | `admin.monitor.view` | 时间序列（取自 `metrics:counter:{date}` 的多日 Hash） |
| GET | `/api/sessions?page=&size=&uid=&protocol=` | `admin.session.view` | 在线会话分页（`SMEMBERS online:clients` → 分页 → `HGETALL session:{cid}`） |
| GET | `/api/sessions/{clientId}` | `admin.session.view` | 会话详情（会话 + 心跳 + 订阅 + 离线队列长度 + 绑定状态） |
| GET | `/api/sessions/by-uid/{uid}` | `admin.session.view` | 按 uid 反查（`SMEMBERS uid:clients:{uid}`） |
| GET | `/api/sessions/by-device/{deviceId}` | `admin.session.view` | 按设备反查（`GET device:client:{deviceId}`） |
| POST | `/api/sessions/kick` | `admin.session.kick` | 转签调 `POST /action {action:kick}` |
| POST | `/api/auth/revoke` | `admin.auth.revoke` | 转签调 `POST /action {action:revoke}` |
| POST | `/api/auth/unbind` | `admin.auth.unbind` | 转签调 `POST /action {action:unbind}` |
| POST | `/api/push` | `admin.push.create` | 转签调 `POST /push`；成功即写 `push_task` |
| GET | `/api/push/history?page=&target=&status=&from=&to=` | `admin.push.history` | 读 `push_task` |
| POST | `/api/push/template` · GET `/api/push/templates` | `admin.push.create` | 模板 CRUD（后台自有数据） |
| GET | `/api/push/queue/{uid}?page=` | `admin.push.queue.view` | `LRANGE push:offline:{uid}`（只读） |
| GET | `/api/config/env` | `admin.config.view` | 只读脱敏配置视图 |
| GET | `/api/config/secrets` | `admin.config.secret.view` | 密钥状态（默认**关闭**，需单独授权） |
| GET | `/api/config/roles` | `admin.config.view` | 角色/端口/探活三源合一 |
| GET | `/api/ops/logs?role=&date=&keyword=&lines=` | `admin.log.view` | 只读尾读（白名单路径，见 §9-R4） |
| GET | `/api/ops/redis/scan` | `admin.redis.inspect` | 键巡检：预期键 vs 实际存在 |
| GET | `/api/ops/audit?page=&admin_id=&action=&from=&to=` | `admin.audit.view` | 读 `admin_audit_log` |
| GET | `/api/ops/restart-guide?role=` | `admin.ops.restartGuide` | 生成重启命令**文本**（不代执行） |

---

## 5. 数据层

### 5.1 Redis 读路径（逐模块键映射）

**全部键名必须经 `RedisKeys` 引用（红线 ㉝）**。实际键 = `REDIS_PREFIX`（`config/app.php:89`，默认 `gwpush:`）+ 逻辑键名。

| 模块 | 逻辑键 | 类型 | 用途 | 真源 |
|---|---|---|---|---|
| M1 | `metrics:gauge` | Hash | 瞬时指标，按 PID 成字段 | `RedisKeys::METRICS_GAUGE` |
| M1 | `metrics:counter:{YYYYMMDD}` | Hash | 当日累加指标 | `RedisKeys::metricsCounter()` |
| M1 | `online:clients` / `online:ws` / `online:udp` | Set | 在线数（`SCARD`） | `RedisKeys::online($protocol)` |
| M1 | `queue:udp:in` / `queue:action:in` / `queue:udp:out` / `queue:push:out` | List | 队列深度（`LLEN`） | `QUEUE_*` 四常量 |
| M2 | `online:clients` | Set | 会话分页源 | 同上 |
| M2 | `session:{clientId}` | Hash | 会话主体（字段见 §5.1.1） | `RedisKeys::session()` |
| M2 | `heartbeat:{clientId}` | String | 最近活跃时间戳 | `RedisKeys::heartbeat()` |
| M2 | `uid:clients:{uid}` | Set | 按 uid 反查 | `RedisKeys::uidClients()` |
| M2 | `device:client:{deviceId}` | String | 按设备反查 | `RedisKeys::deviceClient()` |
| M2 | `auth:bind:{uid}` | String | 设备绑定（首绑胜出） | `RedisKeys::authBind()` |
| M2 | `auth:revoked:{fingerprint}` | String | 撤销名单（`SCAN`，**不可逆推 Token**） | `RedisKeys::authRevoked()` |
| M2 | `subscribe:uid:{uid}` / `subscribe:topic:{topic}` | Set | 订阅双向索引 | `RedisKeys::subscribeUid/Topic()` |
| M2/M3 | `push:offline:{uid}` | List | 离线队列（`LLEN` / `LRANGE`） | `RedisKeys::pushOffline()` |
| M3 | `push:dedup:{md5(msgId)}` | String | 幂等标记（只读判重） | `RedisKeys::pushDedup()` |
| M5 | `action:report:{topic}` | String | report 累计计数 | `RedisKeys::actionReport()` |
| M5 | `rl:{dim}:{md5(id)}` / `api:rate:{md5(ip)}:{min}` | Hash/String | 限流桶 | `RedisKeys::rateBucket/rateApi()` |
| M5 | `health:probe` | String | 连通性探测（仅 `EXISTS`） | `RedisKeys::HEALTH_PROBE` |

> **`SCAN` 纪律**：M2/M5 需要「按前缀列举」的场景（`auth:revoked:*`、键巡检）**必须用 `SCAN` + `COUNT`**，
> 禁 `KEYS`（单线程阻塞，主项目在线服务共享同一 Redis 实例）。

#### 5.1.1 `session:{clientId}` Hash 字段（实测）

| field | 类型 | 说明 |
|---|---|---|
| `client_id` | string | 连接标识；UDP 为 `udp:{ip}:{port}` |
| `uid` | string | 用户 ID |
| `device_id` | string | 设备 ID |
| `protocol` | string | `ws` / `udp` |
| `client_ip` / `client_port` | string | 来源地址 |
| `gateway` | string | 承载网关标识 |
| `connect_at` | int | 建连时间戳 |
| `last_active` | int | 最近活跃（`touch()` 更新） |
| `offline_at` | int | **仅 `markOffline()` 写入**；存在即表示「断连但会话保留」 |

来源：`src/Business/Session.php:88-98`（`bind()`）、`:173`（`markOffline()`）。

#### 5.1.2 `metrics:gauge` field 规则（实测）

| field | 含义 |
|---|---|
| `report_at` | 最近上报时间戳（**全局新鲜度判据**） |
| `conn_total` / `conn_ws` / `conn_udp` | 在线连接数（仅 worker 0 写） |
| `pid_at:{pid}` | 该进程最近上报 —— **进程存活判据** |
| `proc:{pid}` | `{"role":"gateway","worker_id":0}`（`role` 来自 `APP_ROLE`） |
| `tasks:{pid}` | `{"worker_id":0,"jobs":{…}}` |
| `memory_bytes:{pid}` | 进程内存（字节） |

**两个阈值不可互换**（`src/Business/Monitor.php:345-360` 有长篇注释，`MonitorTest` 钉成金标）：

- **展示判据** `interval × 2`（默认 10s）→ 面板标「已退出」；
- **删除判据** `MONITOR_TTL`（600s）→ 采集侧 `purgeExitedProcesses()` 主动 `HDEL`。

后台 M1 的进程列表**必须**用 `interval × 2` 判灰，**不得**照抄 600s。

**改 field 前缀的连带面**（若后台要解析）：PHP 写入侧（`Monitor.php:47-50` 四常量）+ PHP 清理侧（`staleFields()`）
+ 主项目面板 JS 正则，三处同改。后台**只读解析**时把前缀写成常量引用，勿散落字面量。

#### 5.1.3 counter 指标名清单（实测）

来源：`config/app.php:269-288`（结构性真源）+ 各 Bootstrap 的 `Monitor::incr()` 调用点。

| 分组 | 指标名 |
|---|---|
| 连接 | `conn_total` `conn_ws` `conn_udp` `conn_open` `conn_close` `conn_error` `buffer_full` `buffer_drain` |
| 报文 | `msg_in` `msg_out` `msg_fail` `udp_msg_in` |
| 鉴权 | `auth_success` `auth_fail` `heartbeat_timeout` |
| 推送 | `push_in` `push_out` `push_fail` `push_offline` `push_replay` `push_dedup` `push_ack` `push_topic` `push_topic_targets` |
| UDP 出站 | `udp_out` `udp_out_queued` `udp_out_fail` |
| 动作（聚合） | `action_in` `action_ok` `action_fail` `action_timeout` |
| 动作（分动作） | `action_echo` `action_session` `action_report` `action_subscribe` `action_unsubscribe` `action_topics` `action_notify` |
| 动作（HTTP 分通道） | `action_http_in` `action_http_ok` `action_http_fail` `action_http_timeout` `action_http_dequeue` |
| 限流 | `rate_limit_hit` `rate_limit_ip` `rate_limit_conn` `rate_limit_uid` `rate_limit_ping` |
| **本次新增** | `action_kick` `action_revoke` `action_unbind` |

**派生率（后台计算，服务端不提供）**：

```
推送成功率 ≈ push_out / (push_out + push_fail)
鉴权成功率 ≈ auth_success / (auth_success + auth_fail)
动作成功率 ≈ action_ok    / (action_ok + action_fail)
```

⚠ 分母为 0 时必须显示 `—` 而非 `0%`；且这些是**当日累计**，跨日 0 点后曲线会「断崖归零」
（counter 按日期分键，无跨日聚合）—— 后台的「24h 曲线」需自行拼接 `metrics:counter:{昨天}` 与 `{今天}`。

### 5.2 Redis 写边界

| 允许 | 禁止 |
|---|---|
| **无**（一期监控/会话/队列全只读） | 任何 `session:*` / `online:*` / `uid:clients:*` / `device:client:*` 的业务状态改写 |
| 后台自身缓存使用**独立前缀** `gwa:`（或独立 DB） | `queue:*` 的 `RPOP` / `DEL`（除二期显式 purge 功能且带审计） |
| — | `metrics:*` / `auth:*` 的任何写入（`auth:*` 由 §3.3/§3.4 的动作在主项目侧写） |

**强制约束**：

1. 后台**不得**提供「直连 Redis 的通用命令执行」入口（否则审计形同虚设）；
2. 所有读走 `RedisReader` 门面，写走 `GatewayPushClient` —— 两个类之外**禁止**出现 Redis/HTTP 调用；
3. 复用主项目的 `RedisKeyLiteralSniff` 嗅探器（若后台与主项目同仓，直接纳入 `phpcs.xml.dist` 的检查范围），
   并在后台 CI 里跑一次。

### 5.3 MySQL DDL

库名 `gateway_push_admin`，`utf8mb4` / `utf8mb4_unicode_ci`，MySQL 8.x。

#### 5.3.1 webman-admin 自带表（**不重复设计**）

由 webman-admin 的迁移脚本创建，按其规范使用：

```
admin_user  admin_role  admin_permission  admin_user_role  admin_role_permission
admin_log   （webman-admin 自带操作日志，与我们的 admin_audit_log 分工见下）
```

> **分工**：webman-admin 自带日志记「谁登录了、访问了哪些页面」；`admin_audit_log` 记
> **「谁对推送系统做了哪个写操作」**（业务语义审计）。两者都要保留。

#### 5.3.2 后台自有表

```sql
-- ---------------------------------------------------------------------
-- 推送任务受理记录（M3）
-- 定位：/push 是「入队即返回」的异步受理，服务端不落逐条投递历史，
--       因此这里只记录「后台提交过什么、服务端怎么受理的」。
--       status 的终态是 accepted（受理成功）或 rejected（受理失败），
--       刻意不引入 delivered —— 服务端本身不承诺逐条投递回执。
-- ---------------------------------------------------------------------
CREATE TABLE `push_task` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id`    CHAR(16)        NOT NULL COMMENT '后台本地请求 ID（bin2hex(random_bytes(8))）',
  `target_type`   VARCHAR(16)     NOT NULL COMMENT 'uid|device|client',
  `target`        VARCHAR(191)    NOT NULL,
  `payload`       JSON            NOT NULL,
  `payload_bytes` INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT '序列化后字节数，对照 PUSH_PAYLOAD_MAX(4096)',
  `msg_id`        VARCHAR(64)     NOT NULL DEFAULT '' COMMENT '参与服务端幂等去重',
  `offline_mode`  VARCHAR(8)      NOT NULL DEFAULT '' COMMENT '实际生效值（服务端回带）',
  `http_status`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `code`          INT             NOT NULL DEFAULT 0 COMMENT '响应体业务码，0=受理成功',
  `msg`           VARCHAR(255)    NOT NULL DEFAULT '',
  `status`        VARCHAR(16)     NOT NULL DEFAULT 'accepted' COMMENT 'accepted|rejected',
  `operator_id`   INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'admin_user.id',
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_request_id` (`request_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_target` (`target_type`, `target`),
  KEY `idx_operator` (`operator_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='后台发起的推送受理记录';

-- ---------------------------------------------------------------------
-- 推送模板（M3，后台自有数据）
-- ---------------------------------------------------------------------
CREATE TABLE `push_template` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(64)  NOT NULL,
  `target_type`  VARCHAR(16)  NOT NULL COMMENT 'uid|device|client',
  `payload`      JSON         NOT NULL,
  `offline_mode` VARCHAR(8)   NOT NULL DEFAULT '',
  `remark`       VARCHAR(255) NOT NULL DEFAULT '',
  `created_by`   INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='推送载荷模板';

-- ---------------------------------------------------------------------
-- 写操作审计（M5）
-- 定位：所有「会改变推送系统状态」的后台操作必须落此行，且只允许经 Auditor 写入。
-- 与 webman-admin 自带日志的分工：本表记业务语义（对谁做了什么），自带日志记访问行为。
-- ---------------------------------------------------------------------
CREATE TABLE `admin_audit_log` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`    INT UNSIGNED    NOT NULL DEFAULT 0,
  `admin_name`  VARCHAR(64)     NOT NULL DEFAULT '',
  `action`      VARCHAR(64)     NOT NULL COMMENT 'push.create|session.kick|auth.revoke|auth.unbind|...',
  `target_type` VARCHAR(16)     NOT NULL DEFAULT '' COMMENT 'uid|device|client|token|system',
  `target`      VARCHAR(191)    NOT NULL DEFAULT '' COMMENT '目标标识；token 类只记指纹',
  `params`      JSON            NULL COMMENT '请求参数（密钥类字段已脱敏/省略）',
  `result`      VARCHAR(16)     NOT NULL DEFAULT 'ok' COMMENT 'ok|failed',
  `code`        INT             NOT NULL DEFAULT 0 COMMENT '服务端返回的业务码',
  `msg`         VARCHAR(255)    NOT NULL DEFAULT '',
  `ip`          VARCHAR(45)     NOT NULL DEFAULT '',
  `user_agent`  VARCHAR(255)    NOT NULL DEFAULT '',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_time` (`admin_id`, `created_at`),
  KEY `idx_action_time` (`action`, `created_at`),
  KEY `idx_target` (`target_type`, `target`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='后台对推送系统的写操作审计';

-- ---------------------------------------------------------------------
-- 后台自身设置（轮询间隔 / 告警阈值 / 展示偏好）
-- 键值对形态：新增配置无需 DDL
-- ---------------------------------------------------------------------
CREATE TABLE `admin_settings` (
  `k`          VARCHAR(64) NOT NULL,
  `v`          TEXT        NOT NULL,
  `remark`     VARCHAR(255) NOT NULL DEFAULT '',
  `updated_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='后台自身设置';

-- 初始值
INSERT INTO `admin_settings` (`k`, `v`, `remark`) VALUES
  ('monitor.poll_interval',     '5',    '监控页轮询间隔（秒）'),
  ('monitor.queue_warn_depth',  '1000', '队列深度告警阈值'),
  ('monitor.gauge_stale_secs',  '10',   '进程存活展示判据 = MONITOR_INTERVAL × 2'),
  ('session.page_size',         '20',   '会话列表分页大小'),
  ('ops.log_tail_lines',        '500',  '日志尾读默认行数');
```

> **不存**：推送系统 Token 明文、完整 Secret、业务会话状态、任何 `gwpush:*` 镜像。

### 5.4 配置与密钥

后台 `admin/.env`：

```ini
# --- 后台自身 ---
ADMIN_APP_ENV=dev
ADMIN_DB_HOST=127.0.0.1
ADMIN_DB_PORT=3306
ADMIN_DB_NAME=gateway_push_admin
ADMIN_DB_USER=gw_admin
ADMIN_DB_PASS=...

# --- 对接主项目 ---
# 留空则回退 AUTH_SECRET（与服务端 apiSecret() 行为一致）
ADMIN_API_SECRET=
ADMIN_API_URL=http://127.0.0.1:8290
ADMIN_DASHBOARD_URL=http://127.0.0.1:8291
ADMIN_ROLES_CMD=php ../start.php roles
ADMIN_LOG_DIR=../runtime/logs
ADMIN_PROJECT_ROOT=..

# --- Redis（只读）---
ADMIN_REDIS_HOST=127.0.0.1
ADMIN_REDIS_PORT=6379
ADMIN_REDIS_DB=9
ADMIN_REDIS_PREFIX=gwpush:
```

**三条硬纪律**：

1. **`ADMIN_API_SECRET` 不进 MySQL**（只从环境变量注入），后台 UI 的「密钥状态」页只展示
   `是否为空 / 是否回退 AUTH_SECRET / 脱敏值`；
2. `ADMIN_REDIS_DB` / `ADMIN_REDIS_PREFIX` **必须与主项目一致** —— 本机主项目为 `REDIS_DB=9`
   （DB0 有历史残留 `gwpush:*` 键），后台配错会读到「空数据」而非报错（**静默错误**，
   故 `/api/ops/redis/scan` 必须在启动时做一次「预期键存在性」自检）；
3. **不做 `.env` 热重载编辑器**。主项目 6 个角色均为常驻进程、`onWorkerStart` 一次性加载配置、
   **无热重载**（`config/app.php` + `.env.example` 口径）。后台只提供「只读视图 + 变更引导」，
   引导内容形如：`需改的键 → .env 对应行 → 改完需重启的角色 → 验证命令`。

---

## 6. RBAC 权限点矩阵

| 权限点 | 说明 | 超管 | 运维 | 只读 |
|---|---|---|---|---|
| `admin.monitor.view` | 监控仪表盘 | ✅ | ✅ | ✅ |
| `admin.session.view` | 会话列表 / 详情 | ✅ | ✅ | ✅ |
| `admin.session.kick` | **踢线** | ✅ | ✅ | ❌ |
| `admin.auth.revoke` | **撤销 Token** | ✅ | ✅ | ❌ |
| `admin.auth.unbind` | **解绑设备** | ✅ | ✅ | ❌ |
| `admin.push.create` | 发起推送 | ✅ | ✅ | ❌ |
| `admin.push.history` | 推送历史 | ✅ | ✅ | ✅ |
| `admin.push.queue.view` | 离线队列只读 | ✅ | ✅ | ✅ |
| `admin.push.queue.purge` | **清空离线队列**（二期） | ✅ | ❌ | ❌ |
| `admin.config.view` | 配置只读视图 | ✅ | ✅ | ✅ |
| `admin.config.secret.view` | **密钥状态**（默认关，需单独授） | ✅ | ❌ | ❌ |
| `admin.ops.restartGuide` | 重启命令引导 | ✅ | ✅ | ❌ |
| `admin.log.view` | 日志查看 | ✅ | ✅ | ✅ |
| `admin.redis.inspect` | Redis 键巡检 | ✅ | ✅ | ✅ |
| `admin.audit.view` | 审计日志 | ✅ | ❌ | ❌ |
| `admin.system.*` | 管理员 / 角色管理（webman-admin） | ✅ | ❌ | ❌ |

**三角色定义**：

- `超管`（role_admin）：全部权限，含 `admin.system.*`；
- `运维`（role_ops）：日常运维全开，但**不含**密钥查看、审计查看、管理员管理；
- `只读`（role_viewer）：仅 `*.view` 类，**零写权限**。

---

## 7. P0~P5 分期详细设计

### P0 骨架（侵入性 **0**）

| 项 | 内容 |
|---|---|
| 交付物 | `admin/` webman + webman-admin 初始化；MySQL 建库与迁移；登录 / RBAC；Redis 连通；`GET /health` `/stats` 打通 |
| 任务 | ① `composer create-project` webman；② 装 webman-admin；③ 写 `config/gateway_push.php` + `config/redis.php`；④ 建库 + 跑 §5.3 DDL；⑤ 建 3 个角色 + 权限点；⑥ `GatewayPushClient` 骨架（签名 + 错误分层归一化）；⑦ `RedisReader` 骨架（含 `RedisKeys` 复用或镜像） |
| 验收 | 登录后能看到 `/health` 与 `/stats` 的健康卡片；`/api/ops/redis/scan` 自检通过（预期键存在性） |
| 风险 | psr-4 映射 `../src/` 是否可行（webman 的 autoload 与主项目 vendor 是否冲突）—— **P0 第一天必须验证**，失败即切镜像方案 |

### P1 监控（侵入性 **0**）

| 项 | 内容 |
|---|---|
| 交付物 | M1 仪表盘：关键卡片（在线数/消息量/推送成功率/鉴权成功率/错误率）+ 队列深度 + 进程存活表 + 与 Dashboard 互链 |
| 数据源 | `metrics:gauge` + `metrics:counter:{today}` + `/health` + `metrics:counter:{yesterday}`（拼接 24h 曲线） |
| 任务 | ① `MetricsAggregator`（含派生率、分母为 0 处理、跨日拼接）；② 进程存活判定用 `interval×2`；③ Layui 图表（轻量，不上构建链）；④ 队列深度阈值标红（读 `admin_settings`） |
| 验收 | 每个数字可与 `http://127.0.0.1:8291/metrics.json` 逐项对齐（**这是唯一可验证的锚点**） |
| 风险 | `MONITOR_ENABLE=false` 时采集整条链路短路（不只是文案），后台必须显式展示「指标采集已关闭」而非显示 0 |
| **实测修订** | ⚠ 本行推翻前两行两处：**`metrics:counter:{yesterday}` 跨日拼接不可行**（主项目不存历史，见 **D17**）；**Layui 2.8.12 无 chart 模块**故图表改**手绘 SVG**（见 **D16**）。另：轮询改为**分层快慢 tick** 属故障模式隔离决策（**D19**）。落地见 **§11.4** |

### P2 会话只读（侵入性 **0**）

| 项 | 内容 |
|---|---|
| 交付物 | 会话列表（分页）/ 详情 / uid·device 反查 / 订阅列表 / 离线队列只读 / Token 撤销名单（指纹） |
| 任务 | ① `SessionInspector`：`SMEMBERS online:clients` → 分页 → `HGETALL session:{cid}`（**注意 N+1**，用 pipeline 合批）；② 反查三路；③ 订阅双向索引；④ 离线队列 `LLEN` + `LRANGE` 分页；⑤ 详情页展示 §5.1.1 全字段 |
| 验收 | 与真实客户端联调一致：客户端连上后 5s 内在列表可见；断开后（`markOffline`）能区分「保留会话」与「已回收」 |
| 风险 | `online:clients` 是 Set，**分页需先 `SMEMBERS` 再切片**（大集合下内存压力）—— 一期规模可控，二期若超 1 万在线，改 `SSCAN` 游标分页 |
| **实测修订** | ⚠ 本行三处被实现推翻，落地见 **§11.5**：① **`/api/sessions/{clientId}` 不存在**，实际是 `/api/session/{clientId}`（单复数刻意区分，见 **D24**）；② 范围枚举**不止 `online`**，实际有 `scope=online\|retained\|all`，后两者必须 `SCAN`（见 **D22**）；③ 另补 2 个未列出的只读端点 `/api/sessions/subscriptions`、`/api/auth/revoked`（见 **D24** 同段路由表） |

### P3 推送管理（侵入性 **0**）

| 项 | 内容 |
|---|---|
| 交付物 | 发起推送（转签）/ 推送历史表 / 动作调用 / 模板 CRUD |
| 任务 | ① `GatewayPushClient::push()`（补 `AdminApi` 缺失的 `/action` 与 `/action/{id}` 两个方法）；② 表单 → 服务端签名 → `POST /push`；③ `msg_id` 自动生成（16 位 hex）；④ 写 `push_task`；⑤ 模板 CRUD；⑥ 跳转「去 Dashboard 看投递指标」 |
| 验收 | 对照 `php tests/Api/http_demo.php` 与 `postman/GatewayPush.postman_collection.json` 的断言口径；同 `msg_id` 重复提交 → 服务端 `push_dedup` 生效，后台提示「已去重」 |
| 风险 | **`target_type` 只有 `uid`/`device`/`client`（R1）** —— UI 不得出现「按主题推送」选项，否则是虚假功能 |

### P4 写操作（侵入性 **1~2 处主项目改动**）

| 项 | 内容 |
|---|---|
| 交付物 | kick / revoke / unbind 三个运维动作 + 审计落库 + 「强制下线（revoke+kick 串行）」组合按钮 |
| 前置 | **C1~C5 全部落地并回归主项目门禁**（§3） |
| 任务 | ① 主项目加 `channels` 白名单（C1/C2）；② 3 个处理器（C4）；③ `config/actions.php` 声明（C3）；④ metrics 登记（C5）；⑤ 后台 `SessionController` / `AuthController` 转签调用；⑥ `Auditor` 落库；⑦ e2e 补用例（§8.3） |
| 验收 | ① 被 kick 的 WS 连接 **TCP 真断**（`netstat` 复核 + 客户端收到 close）；② 被 revoke 的 Token 后续请求回 `4001`（WS/UDP）/ 包被静默丢弃（UDP）；③ **普通客户端从 WS 调 `kick` 回 `4006`**（安全前提的验收点，必须有） |
| 风险 | 顺序陷阱：UI 上「强制下线」若先 kick 后 revoke，客户端会在重连窗口内用同一 Token 重连成功 —— **必须 revoke 先行** |

### P5 运维（侵入性 **0~微**）

| 项 | 内容 |
|---|---|
| 交付物 | 日志只读尾读 / Redis 键巡检 / 配置只读脱敏 / 密钥轮换引导 / 角色状态与重启命令生成 |
| 任务 | ① `LogTailService`（白名单路径 + 尾读 N 行 + 关键字过滤，**不提供删除**）；② 键巡检（`RedisKeys` 清单 vs 实际 `SCAN`）；③ `SecretMasker`（前 4 后 4）；④ 密钥轮换 checklist 生成；⑤ `RoleProbeService` 三源合一（`roles` JSON + `netstat` + `/health`） |
| 验收 | 审计可追溯全部写操作；日志/配置页**无任何写入口** |
| 风险 | 日志路径穿越（`role` / `date` 参数注入）—— **必须白名单化**：`role ∈ RoleCatalog`、`date` 匹配 `^\d{4}-\d{2}-\d{2}$`，并用 `realpath()` 复核落在 `runtime/logs/` 内 |

### 回归要求（每期）

```bash
# 主项目门禁（不变）
composer analyse && composer test && composer lint && composer lint:self && composer cs:check

# 后台单列
cd admin && composer test && composer analyse && composer test:frontend
```

P1 后新增的 `composer test:frontend` 是**运行期前端渲染校验**（144 项，无需浏览器 / jsdom / 服务端），
与 `composer test`（PHPUnit，静态契约）互补；本地若已起后台，可另跑 `composer test:acceptance`。
**改过 `dashboard.js` 或视图后这两个都要跑** —— 只跑 PHPUnit 只能证明「名字都对」，证明不了「渲染正确」。

P4 若落地 `channels` 与 3 个动作，**必须**补跑：
`composer test:sign`、`composer demo:http`、`composer test:e2e`。

---

## 8. 测试计划

### 8.1 后台自身（`admin/tests/`）

| 类型 | 用例 | 说明 |
|---|---|---|
| 单测 | `SignerParityTest` | ★ **同源金标**：用固定 `(ts, body, secret)` 三元组，断言后台签名结果 == `client/src/Service/AdminApi` 的签名结果。这是防「第三份签名实现漂移」的唯一硬约束 |
| 单测 | `MetricsRateTest` | 派生率：分母为 0 → `—`；跨日拼接；`MONITOR_ENABLE=false` 时的展示语义 |
| 单测 | `SecretMaskerTest` | 前 4 后 4；长度 < 8 时全掩；空值 |
| 单测 | `AuditorTest` | 落库字段完整性；token 类目标**只落指纹** |
| 单测 | `RedisKeysMirrorTest` | 仅走镜像方案时需要：逐常量与主项目 `RedisKeys` 对照 |
| 单测 | `LogTailServiceTest` | 路径穿越防御：`../`、绝对路径、非法 role/date 全部拒绝 |
| 集成 | `ApiContractTest` | 需主项目 `api`+`business` 在线：`/health`、`/stats`（含错误签名 `401/4001`）、`/push`、`/action`、`/action/{id}` |
| 前端 | `dashboard_autorefresh_check.js`（可选） | 沿用主项目做法：注入假 DOM 验证轮询语义 |

**环境隔离**：集成测试**必须能跳过**（主项目未起时不失败），
判据沿用主项目口径 —— 以空密钥请求 `/stats`，`200` 即「免签模式已开启」，
相关断言标 **SKIP 而非失败**（主项目四处工具已统一此口径，2026-09-23 补齐）。

### 8.2 主项目回归（P4 后）

| 命令 | 期望 |
|---|---|
| `composer analyse` | 0 errors（**新代码零容忍，不得追加 baseline**） |
| `composer test` | 全绿；`ActionRunner` 通道断言新增 3 条 |
| `composer lint` | exit 0；后台若在同仓，`src/` 红线不因新动作新增字面量 |
| `composer lint:self` | 8/0（若新增 Redis 键则 `RedisKeyLiteralSniff::$prefixes` 需同步） |
| `composer cs:check` | 0 of N（改过 docblock tag 必须跑一次 `composer cs`） |
| `composer test:docs` | 数字核对（本文件若被纳入核对清单需同步） |

> **P4 改动的门禁风险**（主项目已发生过三次同类事故，教训是「门禁变红先问最近有没有工具/合并改动过工作区」）：
> ① `composer cs` 会写出混合行尾 → 必须先统一 LF 再 `composer lint`；
> ② `channels` 新字段进 `config/actions.php` 的 docblock → `phpdoc_align` 列对齐必然失配 → 必须跑 `composer cs`；
> ③ 新增 3 个处理器后 **`config/app.php` 的 metrics 清单必须同步**，否则面板指标字典与实际静默漂移。

### 8.3 e2e 新增用例（主项目 `tests/e2e_check.php`）

| 用例 | 断言 |
|---|---|
| Q（新增）| `kick` 踢掉一个在线 WS 连接：kick 后 `isOnline(clientId)` 为假；客户端收到连接关闭 |
| R（新增）| `revoke` 后同一 Token 重新 `auth` → `4001`（`CODE_BAD_SIGN`）或 `4004`（鉴权失败，按实现口径） |
| S（新增）| `unbind` 后换 device_id 重新鉴权 → 通过（首绑胜出重新生效） |
| T（新增，**安全**）| **从 WS 通道调 `kick` → `4006`**；从 UDP 通道调 → 静默（UDP 特性） |

> e2e 两条既有边界仍需遵守：**每轮必须换全新 uid**；**保序：用例注册顺序不可打乱**。

### 8.4 手工验收（P4 里程碑）

| 步骤 | 操作 | 期望 |
|---|---|---|
| 1 | 起全 6 角色（`bin/dev/boot_all.sh` 或 `bin\start.bat`），`netstat` 确认每端口 1 个 PID | 无重复实例（红线 ㊳） |
| 2 | 客户端 A 用 Token_A 连 WS | 后台会话列表可见 |
| 3 | 后台「强制下线」A | revoke 先执行 → kick 后执行；A 的 TCP 断开；A 用 Token_A 重连失败 |
| 4 | 后台「解绑设备」A 的 uid | `AUTH_BIND` 键消失；A 换 device_id 可重新鉴权 |
| 5 | 审计页 | 步骤 3/4 均有记录，含操作人/IP/时间/结果 |
| 6 | 只读角色登录 | 上述按钮全部不可见（不是「点了报错」） |

---

## 9. 风险与未决项

### 9.1 已消解的（原方案提出，本次给出结论）

| 原风险 | 结论 |
|---|---|
| 硬缺口：强制踢线 | 消解 —— §3.2 的 `kick` 动作（在 business 进程内执行） |
| `/push` target 类型与预期不符 | 已核定（R1）：只有 `uid`/`device`/`client`，**UI 不出现主题推送** |
| 两份签名实现漂移 | 消解 —— 复用 `client/src/Service/AdminApi` 的签名（补齐 `/action` 方法），`SignerParityTest` 钉住 |
| MySQL 引入增加运维面 | 可接受 —— 仅后台库，推送系统运行不依赖它；备份策略写入 `admin/docs/deploy.md` |
| 端口/实例叠加（Windows 红线） | 用 `netstat` 复核 8292；后台不进默认角色编排，文档写清 |
| 审计绕过 | 写操作**只允许**经 `Auditor`；后台**不提供**通用 Redis 执行入口 |

### 9.2 仍存在的风险

> 注：本表的 **R4~R11** 与 §0.1 的 **R1~R6** 是**两套独立编号**（前者 = 尚未消解的风险，
> 后者 = 对原方案的实测修订）。跨节引用时请写明所在章节，避免误引。

| # | 风险 | 应对 |
|---|---|---|
| **R4** | **后台只读 Redis 与主项目的键空间耦合**：主项目改键名（红线 ㉝ 要求配 `RENAME` 迁移 + 全角色重启），后台若未同步会**读到空数据而非报错** | ① `RedisKeys` 复用/镜像 + 金标对照测试；② 后台启动自检「预期键存在性」；③ 变更同步约定（§10） |
| R5 | **PostgreSQL / MySQL 与 Redis 的一致性**：`push_task` 记 `accepted`，但真实投递结果无从校验 | UI 文案明确「受理记录，非投递回执」；提供「去 Dashboard 看 `push_out`/`push_fail` 增量」的入口 |
| R6 | **后台自身成为攻击面**：管理面通常比业务面更值钱 | ① 必须回环监听；② 一期不做公网暴露；③ 后台**不持有** `AUTH_SECRET`（只持 `ADMIN_API_SECRET`）；④ 写操作全部审计 |
| R7 | **`channels` 白名单被误删/误改** | `config/actions.php` 的 3 条运维动作声明上写死注释「改动即开放提权面」；e2e 用例 T 专门回归这一点 |
| R8 | **UDP 侧无法踢线**（R5 修订） | UI 明确标注「UDP 连接不支持踢线，请用撤销 Token」；不给出会失败的操作按钮 |
| R9 | **`online:clients` 大集合分页** | 一期用 `SMEMBERS` + 内存切片；> 1 万在线改 `SSCAN` 游标 |
| R10 | **`ACTION_RESULT_TTL` 仅 60s** | `202` 后补查窗口很窄，后台必须即时轮询（沿用 api 自身的退避轮询节奏），失败即提示重新发起 |
| **R11** | **选项 A 的残余风险（§0.4 已决策接受）**：持 `API_SECRET` 者即拥有踢线 / 撤销 / 解绑权。若 `API_LISTEN` 改为**非回环地址**，或出现 **≥2 个互不信任的 API 调用方**，该等式即变成真实的提权面 | ① 触发条件与升级路径（选项 B：`X-Admin-Key`）见 §0.4「升级触发条件与护栏」；② **任何改动 `API_LISTEN` 的变更评审都必须复查本节**；③ 落地清单 A1~A5 未完成前，C1~C6 不得上线 |

### 9.3 未决项（不阻塞 P0~P3，需在 P4 前定）

| # | 未决项 | 影响 |
|---|---|---|
| U1 | `kick` 的入参主键：以 `client_id` 为唯一入口，还是允许 `uid` 展开？ | 影响 UI（会话列表行内踢 vs 用户维度批量踢）；建议**两者都支持**（§3.2 已按此设计） |
| U2 | 「强制下线」是否需要二次确认 + 理由必填？ | 审计合规性；建议**必填 reason** |
| U3 | 是否需要 HTTPS / 域名？ | 一期回环可接受；若跨机访问即需 Nginx 反代 + Basic Auth |
| U4 | 会话详情页如何获得 Token 以支持「撤销」按钮？ | 会话 Hash **不含 token**（§3.3）；建议 UI 形态为「粘贴 Token」，或由业务系统侧发起撤销 |
| U5 | `admin_push.queue.purge`（清空离线队列）是否放二期？ | 一期**只读**；开启前需明确「清空即丢消息」的确认流程 |
| ~~U6~~ | ~~`API_SECRET` 的信任域~~ → ✅ **已定（2026-09-23）：选项 A** —— 接受「持 `API_SECRET` 者即可踢线」，并把 `API_SECRET` 明确为管理面凭证。落地清单 A1~A5、升级触发条件见 §0.4 | **已消解**；残余风险登记为 §9.2 的 R11 |
| U7 | C1~C6 的落地节奏：随 P4 一次性交付，还是提前单独做 C1+C2 打安全地基？ | 提前做则需在无任何调用方的情况下先释放 `channels` 能力（空跑）；随 P4 做则改动与需求同批验收。**建议随 P4**（§0.4 总原则） |

---

## 10. 变更同步约定

| 改动 | 需同步 |
|---|---|
| 新增/调整业务动作（含 `channels` 字段语义） | `docs/GatewayPush 对外接口文档.md` §8.1 / §8.5；`README.md` 动作章节；本文件 §3 |
| 改 Redis 键结构 | `src/Common/RedisKeys.php`（唯一真源）+ `README.md` 第 10 章 + **本文件 §5.1** + 后台镜像/对照测试 |
| 改 HTTP 接口契约 | 对外接口文档 §6 + 本文件 §4.1 |
| 改指标名 | `config/app.php` 的 `monitor.metrics` + 本文件 §5.1.3 + 后台指标字典 |
| 改角色清单/端口 | `src/Common/RoleCatalog.php` + `bin/start.*` + 本文件 §2.2 |
| 改主项目 PHP 下限 | 主项目 `docs/代码质量工具链说明.md` §11.8 的 9 处清单 **+ 后台 `composer.json`** |
| 改运维动作的**信任域**（选项 A → B，引入 `X-Admin-Key`） | `.env.example` 的 `API_SECRET` 段注释 + `README.md` 的 `.env` 变量表 + 对外接口文档 §2.3 + 后台 `admin/docs/deploy.md` + 本文件 §0.4 / §9.2 R11 |

---

## 11. 实施记录与实测偏差（2026-09-23）

> §11.1~§11.3 为 **P0**；§11.4 为 **P1**；§11.5 为 **P2**；§11.6 为 **P3**；**§11.7 为 P4**。偏差表 **D1~D35 累计**，冲突一律以本表为准。

### 11.1 P0 状态

| 项 | 状态 |
|---|---|
| `admin/` 骨架 + 依赖 | ✅ 已落地（webman-framework 2.2.4 / workerman 5.2.2 / `webman/admin` **2.1.8**） |
| psr-4 复用 `GatewayPush\Common\RedisKeys` | ✅ **已实测验证**（`ReflectionClass::getFileName()` 指向主项目 `src/Common/RedisKeys.php`） |
| 后台自有配置（database / redis / gateway_push） | ✅ 已落地 |
| `RedisReader`（只读门面）+ `GatewayPushClient`（HTTP 签名） | ✅ 已落地 |
| 签名同源金标测试 | ✅ `tests/Unit/SignerParityTest.php`：6 tests / 24 assertions 全绿 |
| 后台门禁 | ✅ PHPStan L6 **0 errors**（无 baseline）· PHPUnit 全绿 · 冒烟 13 PASS |
| MySQL 建库建号 + 迁移（`install.sql` + `database/001_gw_tables.sql`） | ✅ **已完成**（本机 MySQL **8.0.46**；库 `gateway_push_admin`；专用账号 `gw_admin`，仅授权本库） |
| RBAC 三角色 + 权限点 | ✅ **已完成**（`wa_rules` 共 66 节点 = 插件菜单 61 + GatewayPush 5；角色：超管 `*`、运维 5 节点、只读 3 节点） |
| 安装 / 初始化脚本 | ✅ `admin/scripts/install.php`（7 步、幂等、可重复执行；含「已安装标记」重建） |
| 登录 + 健康卡片页 + 4 个只读 API | ✅ **已完成**（`php windows.php` 起 :8292；登录后 `/dashboard` 可见健康卡片） |
| 未登录 / 越权访问防护 | ✅ 已实测：页面 302 → 登录页；API 返回真实 **HTTP 401 / 403** |
| 后台 ↔ 主项目数据交叉验证 | ✅ 后台 Redis 直读（gauge 12 项 / counter 空）与主项目 `GET /stats` **逐项一致** |

#### 11.1.1 P0 验收结果（2026-09-23 实测）

| 验收项 | 结果 |
|---|---|
| 后台监听 | ✅ `127.0.0.1:8292` LISTENING（回环，与设计一致） |
| `GET /` | ✅ 302 → `/app/admin` |
| `GET /app/admin` | ✅ 200，渲染**登录页**（非安装页 ⇒「已安装」标记生效） |
| 登录（带验证码） | ✅ `{"code":0,"msg":"登录成功"}` |
| `GET /dashboard` | ✅ 200；健康卡片：在线数 / Redis 延迟 / 键总数（**DB 9 · gwpush:**）/ 队列深度 / 键自检 / gauge 12 项 / counter |
| `GET /api/monitor/summary` | ✅ `redis.ok=true db=9 prefix=gwpush: selfcheck=true`；`api.ok=true status=200 has_secret=false`（回退 `AUTH_SECRET`，符合设计） |
| `GET /api/ops/redis/scan` | ✅ 骨架键自检 4 项；`metrics:gauge` exists=hash ttl≈600 |
| 权限矩阵（viewer = 只读角色） | ✅ `/dashboard`、`/api/monitor/*` → **200**；`/api/ops/redis/scan`、`/api/ops/api/probe` → **403** |
| 未登录保护 | ✅ 页面 302 → `/app/admin`；API（`Accept: application/json`）→ **HTTP 401** + `{"code":401,"msg":"请登录"}` |
| 后台门禁 | ✅ PHPStan L6 **0 errors**（无 baseline） · PHPUnit **6 tests / 24 assertions** · 冒烟 **13 PASS / 0 FAIL** |
| 主项目门禁未被污染 | ✅ `composer lint` 0 errors 且结果中 0 处 `admin/`；`git status` 仅 `docs/` 改动 + `admin/` 未跟踪，`src/` 与 `config/` 零改动 |

### 11.2 P0 与本文档前文的偏差（**以本表为准**）

| # | 前文表述 | 实测事实 | 影响 |
|---|---|---|---|
| **D1** | §7 写「装 webman-admin」 | composer 包名是 **`webman/admin`**（不是 `workerman/webman-admin`，后者 404）；实装 **v2.1.8** | 安装命令按 `composer require -W webman/admin` |
| **D2** | §5.3.1 列 `admin_user` / `admin_role` / `admin_permission` / `admin_user_role` / `admin_role_permission` / `admin_log` | 实际是 **`wa_*` 七表**：`wa_admins` `wa_admin_roles` `wa_roles` `wa_rules` `wa_options` `wa_uploads` `wa_users`。**没有** `admin_log` 表 | 后台自有表设计不受影响；「与自带表分工」的表述需改 |
| **D3** | §6 的 16 个权限点形如 `admin.monitor.view` | RBAC 是**控制器维度**：权限键 = `{controller}@{action}`，存 `wa_rules.key`；`wa_roles.rules` 存 **rule ID 列表**（`*` = 全权），非键名列表。鉴权实现在 `plugin/admin/api/Auth.php:40-125` | **16 个语义权限点须改写为 `app\controller\...@action` 形态**；`wa_rules` 同时承担菜单树（含 `href`/`icon`/`type`/`pid`） |
| **D4** | §5.4 假定 `.env` 直接可用 | webman 骨架**不带 phpdotenv**；`support/bootstrap.php:45` 的判据是 `class_exists('Dotenv\Dotenv')` → 未装时 **`.env` 静默失效**（配置全取默认值，不报错） | `composer.json` **必须** require `vlucas/phpdotenv`（已加） |
| **D5** | §2.3 目录树示意 `config/plugin/webman/admin/` | 插件配置覆盖的正确落点是 **`config/plugin/admin/`**，且**必须**配套 `config/plugin/admin/app.php` 带 `enable => true`，否则被 `Config::loadFromDir()` 静默跳过。加载顺序（`vendor/workerman/webman-framework/src/support/App.php:155-165`）：`config/` 先 → `plugin/*/config/` **后**，故**插件自带同名文件会反向覆盖项目侧** | ⚠ 先前结论「**切勿**手工创建 `plugin/admin/config/database.php`」**已被 D10 修正** —— 该文件**必须存在**（是「已安装」判据），但内容应写成**转发**，凭据不得落在插件目录 |
| **D6** | §4.2 建议「复用 `client` 的 Signer 逻辑」/ 曾拟用 `workerman/redis` | `workerman/redis` 是**异步回调**式，在 webman 控制器里取同步结果很别扭 → 改用 **`webman/redis` + `predis/predis`**（本机无 phpredis）。⚠ `client` 键必须放 `config/redis.php` 的**顶层**：`support/Redis::instance()` 读的是 `config('redis')['client']`，放在 `redis.default` 里会被**静默忽略**并回退 phpredis（报 `Class "Redis" not found`） | 签名仍是两份实现，漂移由 `SignerParityTest` 锁死 |
| **D7** | 未预见 | **环境变量 `http_proxy` 会破坏对主项目 API 的访问**：Guzzle 遵循它，代理在**复用连接的第 2 个请求**上返回 400 → 表现为同一连接 `200/400/200/400` 交替，极易误判成主项目 API 的长连接缺陷（裸 socket 直连 8290 连发 3 次全部 200，API 正常） | `GatewayPushClient` 已显式 `'proxy' => ''`；**新增任何访问主项目的客户端都要同样处理** |
| **D8** | 曾担心 `admin/` 会被主项目工具扫到 | 实测主项目三套工具**均显式列目录**（phpcs 列 `src`/`client/*`/`config`/`tests`/`start.php`；php-cs-fixer `Finder->in([...])`；PHPStan `paths` 列 3 项），**都不扫仓库根** → `admin/` 落根目录**不会污染主项目门禁**，「侵入性 0」成立，无需加排除项 | — |
| **D9** | §5.3 写「库 `utf8mb4` / `utf8mb4_unicode_ci`，MySQL 8.x」 | ①本机 MySQL 实为 **8.0.46**（设计时探到的 5.7.26 已被更换）；②**webman-admin 自带 7 张 `wa_*` 表在 `install.sql` 里每张都写死 `COLLATE=utf8mb4_general_ci`** | 库默认与后台自有 4 表**统一为 `utf8mb4_general_ci`**（若用 unicode_ci，跨表 JOIN/UNION 会报 `Illegal mix of collations`，且只在跑到具体 SQL 时才暴露）。已同步 `config/database.php` 与 `database/001_gw_tables.sql` |
| **D10** | D5 曾写「**切勿**手工创建 `plugin/admin/config/database.php`」 | 该文件**必须存在**：`IndexController.php:39` 与 `InstallController.php:31` 都用 `is_file()` 判「是否已安装」，缺失时后台根路径直接渲染**安装页**。但它在插件目录内（已 gitignore、会被 composer 覆盖），且安装器写入的是**明文连接参数** | 本项目做法：插件侧文件**只做一层转发**（`return require config_path().'/database.php';`），凭据仍只存在于版本库内的 `config/database.php`（读 `.env`）；`scripts/install.php` 步骤 0 会**全量覆写**它，故插件重装后可一键重建 |
| **D11** | §7「P0 骨架：登录/RBAC」未提验证码 | 登录**强制校验验证码**（`AccountController::login` 比对 `session('captcha-login')`，不符直接返回「验证码错误」） | 自动化验收路径：先 `GET /app/admin/account/captcha/login` 建立 session → 从 `runtime/sessions/session_{PHPSID}`（file 驱动、PHP serialize 格式）读出明文 → 再 POST 登录。**同理：管理员账号只能经脚本/SQL 创建，纯 API 走不通** |
| **D12** | §7 写「webman 独立进程（`php start.php start`）」 | Windows 下 `php start.php start` 立即退出，提示 `Please run 'windows.php' on windows system.` | Windows 启动方式 = **`php windows.php`**（前台常驻 + 文件变更热重载）；`start.php start` 仅 Unix 可用 |
| **D13** | §7「P0：MySQL 迁移」隐含 `install.sql` 可直接执行 | `install.sql` **不幂等**：`wa_options` / `wa_roles` 用的是**裸 `INSERT` + 固定主键**，非空库重复执行必报 `1062 Duplicate entry`。官方安装页 step1 在「表已存在」时也只会要求「强制覆盖（= `DROP TABLE`）」 | 本项目**不走安装页**，由 `scripts/install.php` 复刻其动作并加幂等语义：按「表是否齐全」决定是否执行，执行前把 `INSERT INTO` 改写为 `INSERT IGNORE INTO` |
| **D14** | 未预见（视图层未设计） | 视图引擎 `Raw` 的默认后缀是 **`.html`**（`config('view.options.view_suffix')` 默认 `html`）**但内容是原生 PHP**（`Raw::render()` 直接 `include`）；且根视图路径是 **`app_path()/view/`**（即 `admin/app/view/`），不是 `admin/view/` | 页面文件为 `admin/app/view/dashboard/index.html`（内写 PHP），控制器 `view('dashboard/index', $vars)` |
| **D15** | 未预见 | webman 的 `json()` 助手把 HTTP 状态码**硬编码为 200**（`support/helpers.php:183-186`，签名里没有 status 参数） | `AdminAuth::deny()` 改为**自建 Response**，同时给出真实状态码（401/403）与业务码 `code`；返回 `200 + code=401` 属「用 200 掩盖失败」，会让前端无法按状态码统一拦截 |
| **D16**（P1） | §7 P1 任务 ③ 写「Layui 图表（轻量，不上构建链）」 | **Layui 2.8.12 没有 chart 模块** —— `grep -c chart layui.js` = **0**（chart 自 layui 2.x 起被拆为独立 `layui-chart`，本项目未引入）。照原方案写必然是运行期 `undefined` | 趋势图改**手绘 SVG**（零依赖、无构建链，反而更贴「轻量」原意）：`viewBox="0 0 300 88"` + `preserveAspectRatio="none"` 铺满容器，**必须配 `vector-effect="non-scaling-stroke"`**，否则非等比缩放会把描边一起拉变形 |
| **D17**（P1） | §7 P1 数据源写 `metrics:counter:{yesterday}`「拼接 24h 曲线」 | **主项目没有任何历史指标存储**：Redis 只有当前 `metrics:gauge` 与**按日分桶**的 `metrics:counter:{Ymd}`（保留 7 天，但只有「当日累计」一个数），`GET /stats` 也只回当前快照。要画 24h 曲线，得先在主项目新增采样表 + 采样进程 | 趋势改为**浏览器端环形缓冲**（`history_points` 点，默认 120），**刷新即清零**。UI 必须如实标注「本次会话（页面打开以来）」——把「会话内趋势」说成「24 小时趋势」是虚假功能 |
| **D18**（P1） | §7 P1 交付物写「推送成功率/鉴权成功率/错误率」，措辞暗示是瞬时速率 | `metrics:counter:{Ymd}` 是 `HINCRBY` **累加**的**当日累计**值 → 由它派生的比率口径是「**今日累计 %**」，不是瞬时成功率 | 派生率表上方固定写明口径 = 今日累计；`den = 0` 时值为 **`null` → 显示 `—`（无样本）**，与 `0.00%`（有样本、零失败）**语义不同、不可合并**。今日累计口径另有一个直接后果：零点后子键从 0 开始，`cur - prev` 为负 ⇒ **必须识别日切并清空趋势**，否则会画出一条负速率折线 |
| **D19**（P1） | §7 未提轮询分层；实现时容易顺手把 `/health` `/stats` 并进同一个 tick | **分层是故障模式隔离，不是性能优化**：`GatewayPushClient` 的 `connect_timeout=3s`、总超时 `8s`，若把主项目 HTTP 并进 5s 快 tick，主项目一慢/一挂，**面板会在最需要它的时候正好卡住** | 快 tick（5s）→ `/api/monitor/live`：**只碰 Redis**，返回值里**刻意不含 `api` 段**（验收脚本 `p1_acceptance.php` 已把这条钉成硬断言，防止后人"顺手"合并）；慢 tick（30s）→ `/api/monitor/summary`：才做 `selfCheck + dbsize + /health + /stats` |
| **D20**（P1） | 未预见 | **未注册的路由返回 HTTP 200**，响应体才是 `{"code":404,"msg":"404 Not Found","data":[]}`（webman-admin 插件的异常处理器把 404 包成了 200）—— 与 D15 同类问题 | 前端判成败**一律看响应体 `code`**，不看 HTTP 状态码（`fetchJson()` 已是此约定）。这也意味着「HTTP 200」在本项目**不等于**成功 |
| **D21**（P1） | P0 用 `static` 属性缓存 `admin_settings`，并声称「调参不需要重启后台」 | **webman 是常驻进程，PHP 静态属性跨请求存活**（与 PHP-FPM 每请求重置不同）→ 该缓存**永不过期**，P0 的承诺是假的：改了库里的阈值，页面一直到重启都不变 | 加 `CACHE_TTL = 5`（键存 `[时间戳, 数据]`），并**把失败结果也缓存**（避免 MySQL 挂掉时每个请求都去撞）。⚠ **常驻进程里凡是 `static` 缓存都必须自带 TTL**，否则等于把配置冻结在启动那一刻 |

### 11.3 P0 其他实测要点（易再犯）

- **`process.php` 里不能写 `config('...')`**：该文件本身被 `Config::load()` 递归 include，此刻配置尚未就绪，会取到 `null`。监听地址需**直接读 `getenv()`**。
- **`config('plugin.admin.database')` 必须非空**：`plugin/admin/app/controller/AccountController.php:255` 用它判断「是否已安装」，为空会抛「请重启webman」。
- **根 `config/database.php` 才是 Eloquent 的真源**：`vendor/webman/database/src/Initializer.php:113` 是**文件级**执行 `Initializer::init(config('database', []))`。`config/plugin/admin/database.php` 只是镜像（本项目用 `require` 保证同源）。
- **`plugin/admin/` 是 composer 包纯副本**（`Install.php` 的 `pathRelation` = `['plugin/admin' => 'plugin/admin']`，经 `copy_dir` 重建）→ **已 gitignore**，不得手工修改。
- **`.env` 中含空格的值必须加引号**，否则 phpdotenv 抛 `Encountered unexpected whitespace`。
- **`app/process/` 是 webman 骨架脚手架**，不从属于本项目维护；PHPStan 已将其排除（并在配置里写明「若真正定制则该排除必须删除」）。
- **PHP 块注释里勿出现形如 `plugin/*/config/` 的路径** —— 其中的 `*/` 会**提前闭合块注释**，后半句变成代码，报 `Parse error: unexpected identifier "config"`。本项目已实际踩过：该文件同时导致 `windows.php` 启动失败。注释里要写通配路径时改用 `{插件名}` 占位。
- **`PDO::ATTR_EMULATE_PREPARES => false` 时同名占位符不可重复**：`... VALUES (:now, :now)` 会报 `SQLSTATE[HY093] Invalid parameter number`，必须写成 `:created_at` / `:updated_at` 两个不同占位符。
- **`install.sql` 不是幂等的**（见 D13）—— 任何「重跑安装」的工具都需自行补 `IF NOT EXISTS` / `INSERT IGNORE` 语义，不能直接 `exec` 整个文件。
- **插件侧与项目侧的配置同名时，插件侧胜**（加载顺序决定，见 D5）。判断某个 `config('plugin.x.*')` 到底从哪来，要看 `Config::loadFromDir()` 的两次扫描顺序，不能只看文件是否存在。
- **`RedisReader::dbIndex()` / `prefix()` 必须由后台自读配置**：主项目 `/health` 的 data 里**没有** DB 号字段（早期版本误取它，导致页面恒显示「DB 0」，而实际连的是 DB 9 —— 典型的静默错配）。

### 11.4 P1 实施记录与实测偏差（2026-09-23）

> P1 相对 P0 的唯一结构性变化：**页面由「服务端整页渲染 + `<meta refresh>`」改为「服务端只出骨架 + 浏览器轮询渲染」**。
> 驱动原因是 D17（趋势必须跨刷新累积，而主项目无历史指标存储）；整页重建还会丢掉 DOM 状态，
> 并在主项目 API 卡住时把整个页面一起拖死。**实时日志 tail 经拍板不做**（红线 ㉙ 同向：阻塞式读取不入该链路）。

#### 11.4.1 状态

| 项 | 状态 |
|---|---|
| `MetricsDeriver`（纯函数派生层，**零 IO**、阈值注入） | ✅ 9 条派生率 + 进程存活判定 + 4 类告警来源；阈值可被 `admin_settings` 覆盖，**非法值静默回落默认** |
| 分层轮询（快 5s **只碰 Redis** / 慢 30s 含主项目 HTTP） | ✅ 新增 `GET /api/monitor/live`；`/api/monitor/summary` 保留 |
| 前端（骨架 + 自绘 SVG + 环形缓冲） | ✅ `admin/public/static/dashboard.js`（784 行）+ `dashboard.css`；零外部依赖、无构建链 |
| 四项轮询优化 | ✅ 单 tick 分发（两个定时器） / 链式 `setTimeout` / 失败指数退避（封顶 60s） / `visibilitychange` 隐藏即停并在途作废 |
| RBAC | ✅ 新增权限点 `mon.live`（`wa_rules` **id 120**，type 2）；只读角色 rules = `64,120,65,66` |
| 配置项 | ✅ `admin_settings` **7 行**（新增 `monitor.slow_interval`、`monitor.ratio_thresholds`） |
| P0 遗留缺陷修复 | ✅ `Settings` 静态缓存永不失效 → 加 `CACHE_TTL = 5`（**D21**） |
| 后台门禁 | ✅ PHPStan L6 **0 errors** · PHPUnit **40 tests / 187 assertions** · 前端渲染校验 **144 项全绿** |
| P1 验收脚本 | ✅ `tests/Manual/p1_acceptance.php`：**41 PASS / 0 FAIL / 1 SKIP**（viewer 越权矩阵需可选环境变量，默认 SKIP） |
| 主项目零改动 | ✅ `src/`、`config/`、`tests/` 零改动；`composer lint` 结果中 **0 处** `admin/` |

#### 11.4.2 交付文件

| 文件 | 变更 | 说明 |
|---|---|---|
| `admin/app/service/MetricsDeriver.php` | **新增** | 纯函数派生层。9 条派生率用表驱动（`RATIOS` 常量：`{label, formula, num, den, kind, warn, bad}`）；3 个 phpstan 类型别名（`RatioRow`/`ProcessRow`/`AlertRow`）由测试 `@phpstan-import-type` 复用，保证只有一处真源 |
| `admin/app/service/MonitorAggregator.php` | 重写 | 新增 `live()`（**Redis-only**）、`derived()`、`reportAt()`；`collect()` 仍返回 `ts/redis/api/derived` |
| `admin/app/service/Settings.php` | 修改 | `CACHE_TTL = 5` + 缓存失败结果；新增 `json()` 读取器（供 `ratio_thresholds` 用） |
| `admin/app/controller/api/MonitorController.php` | 修改 | 新增 `live()` |
| `admin/app/controller/DashboardController.php` | 重写 | **不再采集数据**，只注入 `config`（含 `HISTORY_POINTS = 120`） |
| `admin/app/view/dashboard/index.html` | 重写 | 纯骨架：无数据、无 `<meta refresh>`；`#dashboard-config` 以 `JSON_HEX_TAG\|JSON_HEX_AMP\|JSON_HEX_APOS\|JSON_HEX_QUOT` 注入 |
| `admin/public/static/dashboard.js` | **新增** | 784 行 IIFE，见 §11.4.3 |
| `admin/public/static/dashboard.css` | **新增** | 沿用 P0 调色板，补 `.chart` `.bars` `.bar-*` `.jobs` |
| `admin/config/route.php` | 修改 | `/api` 组内增 `/monitor/live` |
| `admin/scripts/install.php` | 修改 | 增 `mon.live` 权限点（id 120）；**只读角色必须同步加该 rule**，漏加的症状是「页面永远停在骨架 + 浏览器控制台一个 403」 |
| `admin/database/001_gw_tables.sql` | 修改 | `admin_settings` 种子 5 → **7 行**；`monitor.ratio_thresholds` 刻意留 `'{}'`，使默认阈值**只存在于 `MetricsDeriver::RATIOS` 一处** |
| `admin/tests/Unit/MetricsDeriverTest.php` | **新增** | 33 tests：边界「到点即算」、缺分母 → `null` 而非 `0.0`、不可信时间戳、乱序 JSON、告警 4 源 |
| `admin/tests/Unit/DashboardContractTest.php` | **新增** | 7 tests：视图 ↔ JS 静态契约（JS 引用的 DOM id 全部存在、`cfg.*` 键全部由控制器注入、无 `meta refresh`、无 `innerHTML =`、无 `setInterval`、恰好 2 处 `window.setTimeout(`） |
| `admin/tests/Frontend/dashboard_render_check.js` | **新增** | **144 项**运行期渲染校验，见 §11.4.4 |
| `admin/tests/Manual/p1_acceptance.php` | **新增** | 三段式验收（服务层真连 Redis / HTTP 层走 curl + 验证码登录 / RBAC 静态断言 + 可选越权矩阵） |
| `admin/composer.json` | 修改 | 增 `test:frontend`（跑渲染校验）与 `test:acceptance`（跑 P1 验收脚本） |

#### 11.4.3 关键不变量（改 `dashboard.js` / `live()` 前必读）

1. **`live()` 里不许出现任何主项目 HTTP 调用。** `GatewayPushClient` 的 `connect_timeout=3s`、总超时 `8s`；
   把它并进 5s 快 tick，主项目一慢或一挂，面板就在**最需要它的时候**正好卡住（D19）。
   验收脚本已把「`live()` 返回值不含 `api` 段」钉成硬断言。
2. **`counter` 是「今日累计」，不是瞬时速率**（D18）。派生率一律标注为今日累计口径。
3. **`den = 0` ⇒ 值 `null` ⇒ 显示 `—`**，与 `0.00%` 严格区分；**这条必须同时体现在文本与结构上**
   （`span.tag` vs `b`），只比文本会漏掉「把 null 抹成 0」的回归（§11.4.4 的变异 D）。
4. **边界方向：一律「到点即算」。** 进程存活 `age_secs <= staleSecs`（与主项目 `Monitor::staleFields()`
   的 `$at >= $deadline` 同向）；队列积压 `depth >= warnDepth`；比率档位 `value >= threshold`。
5. **`report_at` 必须同时出现在 `redisSnapshot()` 的成功与 catch 两个分支**，否则慢 tick 会把页面上的
   「指标上报于」擦回 `—`（快 tick 正常、慢 tick 一到就丢，很容易误判成快慢 tick 冲突）。
6. **`stopAll()` 里 `liveSeq/slowSeq` 的自增不可省。** 看似冗余（`fetchJson` 自己也会自增），
   但它真正防的是**响应在页面隐藏期间落地**这条路径：此时没有新请求去顶替，序号不变 ⇒ 不被判陈旧
   ⇒ 响应会一路走到续排分支，把后台标签页「复活」成继续轮询，直接推翻「隐藏即停」的承诺。
   **这正是变异测试发现的盲区**（§11.4.4 变异 B）。
7. **服务端返回的任何字符串只走 `textContent` / `createTextNode`**，绝不进 `innerHTML`；
   SVG 属性只放数值，故 `setAttribute` 安全。
8. **判成败看响应体 `code`，不看 HTTP 状态码**（D20）。
9. **趋势只能在浏览器端累积**（D17），UI 必须写「本次会话」，且零点后 `cur - prev < 0` 要**识别为日切并清空历史**，
   而不是把负速率画进图里。

#### 11.4.4 测试工程化：变异测试（10/10 检出）

新增的 `admin/tests/Frontend/dashboard_render_check.js` 不用浏览器、不用 jsdom、不需要服务端：
它把 `dashboard.js` 的 IIFE **整体真跑一遍**，只把 `document` / `window` / `fetch` 三个自由变量换成受控替身
（假 DOM 直接由视图 HTML 里的 `id="..."` 列表构建，因此**视图删 id 会立刻暴露**；假 `setTimeout` 只登记不执行，
由测试用 `fire('runLive')` 手动推进一跳，于是退避倍数、定时器计数都成了可断言对象）。

为确认它「不是空转」，对被测文件注入 10 个变异体逐个验证检出能力：

| # | 变异 | 被检出的断言 | 结论 |
|---|---|---|---|
| A | （原始，无变异） | — | **144/144 全绿** |
| B | `stopAll()` 去掉 `liveSeq/slowSeq` 自增 | 「S9 隐藏期间落地的响应不渲染 / 不续排」 | ✅ 2 FAIL |
| C | `backoff()` 恒返回 `baseMs` | S7/S8 的退避间隔 10s/20s/40s/60s | ✅ 6 FAIL |
| D | `renderRatios` 的 null 分支被抹平（只输出 `<b>`） | 「无样本单元格用静默 tag 呈现」 | ✅ 1 FAIL（**补了结构断言后才检出**） |
| E | `window.setTimeout` → `setInterval` | 45 项连带失败 + 「全程未使用 setInterval」 | ✅ 45 FAIL |
| F | 日切检测恒 `false`（负增量照画） | S6 日切告警与趋势清空 | ✅ 4 FAIL |
| G | 告警详情改走 `innerHTML` | 「S11 全程零 innerHTML 赋值」等 | ✅ 6 FAIL |
| H | 残留进程不再标 `bad` | 「已退出进程状态标签 / 样式」 | ✅ 2 FAIL |
| I | 队列阈值 `>=` 改 `>` | 「深度恰等于阈值时即判积压」 | ✅ 1 FAIL |
| J | 移除 `series()` 的 `isFinite` 过滤（NaN 进图） | 「S3 首个采样点速率显示 —」 | ✅ 1 FAIL |

> **B / H / I 三个变异最初是漏检的**，即裸跑「全绿」并不等于覆盖到位：
> B 暴露出我把「尚未恢复」与「恢复后旧响应」两类陈旧场景混为一谈，漏掉了**隐藏期间落地**这条唯一
> 能区分死活的路径；H 暴露出只断言了行淡出、没断言状态标签本身；I 暴露出用例里深度恰好等于阈值的
> **边界点**根本没出现。三者补测后重新检出。
> D 则是「只比文本、不比结构」导致——`null` 与 `0.00%` 在文本上都是可分辨的，
> 但「静默 tag」与「加粗数值」的差别只有结构断言能看见。
> **结论：这类前端校验必须配变异测试，否则「PASS」只是「没崩」。**

### 11.5 P2 实施记录与实测偏差（2026-09-23）

> §7 P2 的任务项 ①~⑤ 全部落地，**侵入性 0**（主项目 `src/`、`config/`、`tests/` 零改动）。
> 交付面见下表；偏差 **D22~D25** 为本次新增。

#### 交付物与门禁

| 层 | 文件 | 门禁结果 |
|---|---|---|
| 服务层 | `app/service/SessionInspector.php`（M2 聚合，纯静态判定层） | `SessionInspectorTest` **63 tests / 272 assertions** |
| 数据门面 | `app/service/RedisReader.php`（补 `scanKeys` / `sessions` 批量 / `revoked` 等只读方法） | 与 `RedisReader` 一并纳入只读红线的源码扫描 |
| API 控制器 | `app/controller/api/SessionController.php`（7 个只读端点） | |
| 页面控制器 | `app/controller/SessionController.php`（P2 页面 + `SIZE_OPTIONS` 契约常量） | `SessionContractTest` **16 tests / 78 assertions** |
| 视图 / 前端 | `app/view/session/index.html` · `public/static/session.{js,css}` | `session_render_check.js`（假 DOM）**122 项全 PASS**；P1 的 `dashboard_render_check.js` 仍 **144 项** |
| 契约测试 | `tests/Unit/SessionContractTest.php`（DOM id / cfg key / 路由 / 只读 / 无定时器 4 类不变量） | 同上 |
| 验收脚本 | `tests/Manual/p2_acceptance.php --seed`（4 层：服务层真 Redis 夹具 / 节点一致性 / HTTP / RBAC） | **64 PASS / 0 FAIL / 1 SKIP** |
| 后端总门禁 | `phpunit` · `phpstan`（L6，无 baseline） | **83 tests / 361 assertions** · **0 errors** |

**变异测试**（P1 起确立的纪律，用于证明新门禁真的会咬）：前端 7 组注入（删截断提示 / 保留会话当在线 /
离线队列用页内下标 / 丢掉 `offline_size` / 按字符而非字节算长度 / 加一个轮询定时器 / 抹掉
「空态 vs 配错」的区分）**7/7 被抓**；后端 6 组（删 `disableDefaultRoute` / 在读路径加
`Redis::del` / 改 DOM id / 改端点路径 / 删 cfg 注入 / 在读路径加 `sRem`）**6/6 被抓**。

#### 本次新增偏差

| # | 前文表述 | 实测事实 | 影响 |
|---|---|---|---|
| **D22** | §5.1 隐含「`session:{cid}` 就代表连接存在」，§7 P2 也未提 `offline_at` 的判读 | **`markOffline()` 只摘在线索引、不删会话键**：`src/Business/Session.php:161-186` 做的是 `sRem(online:clients)` + `sRem(online:{protocol})` + `del(heartbeat:{cid})` + `hSet(session,'offline_at')`，**保留** `session:{cid}` / `uid:clients:{uid}` / `device:client:{deviceId}`。⚠ 更反直觉的是 **`offline_at` 是粘性字段**：`bind()` 只 `hMSet` 9 个业务字段、全 `src/Business/` 内**没有任何 `hDel('offline_at')`**（只有 `Monitor.php` 调 `hDel`），而 UDP 的 clientId 是 `udp:{ip}:{port}` ⇒ **同一客户端重连会复用同一 clientId**，于是「断开过再活跃」的连接会**长期带着 `offline_at`** | ① **在线判据只能用 `online:clients` 的成员资格**，用 `offline_at` 会把活跃 UDP 连接误判为离线（`SessionInspector::stateOf()` 已按此实现，并用单测 `testStickyOfflineAtDoesNotMakeAnOnlineSessionLookOffline` 钉死）；② 「已断开但保留」**没有索引**，只能 `SCAN session:*` ⇒ 列表范围必须有 `retained` / `all` 两个额外枚举；③ 反查入口（`uid:clients` / `device:client:`）是**唯一不靠 SCAN 就能看到保留会话**的路径 |
| **D23** | §5.1 只写了「只读 Redis」，未提 SCAN 的前缀语义 | **Predis 的 `KeyPrefixProcessor` 映射表里有 `KEYS`/`SSCAN`/`HSCAN`/`ZSCAN`，唯独没有 `SCAN`** ⇒ `MATCH` 模式**不会**被自动加前缀，而**返回的键带前缀**。实测依据：`MATCH=gwpush:*` 能命中 `gwpush:metrics:gauge`（若被二次加前缀则必然空）。另：`illuminate/redis` v12.69.2 的 `pipeline()` **只定义在 `PhpRedisConnection` 子类**上，基类 `Connection` 没有，`Connection::__call()` 会把未知方法转成 `command($method)` ⇒ `Redis::connection()->pipeline($cb)` 会被当成一条名为 `PIPELINE` 的 Redis 命令而报错 | ① `RedisReader::scanKeys()` **手工拼** `prefix . $logicalPattern`、返回前**手工剥离**前缀；② 批量读取走 `Redis::connection()->client()->pipeline($cb)`（phpredis 与 predis **都在原始客户端上**有 `pipeline`，且前缀在 pipeline 内部照常生效），失败由 `batch()` 退化为逐键读取 —— **只影响往返次数，不影响正确性** |
| **D24** | §4.3 的路由表（`/api/sessions/{clientId}` 等）与 §6 的 16 个语义权限点 | P2 实际落地路由**与 §4.3 有三处形状差异**：① 详情是 **`/api/session/{clientId}`（单数）**，与列表 `/api/sessions`（复数）**刻意区分**，避免与 `/api/sessions/by-uid/{uid}` 之类的静态段路由产生前缀歧义；② 新增 §4.3 未列的 **`/api/sessions/subscriptions`** 与 **`/api/auth/revoked`**；③ 列表多了 `scope` / `page` / `size` 之外的 `protocol` 过滤（与 §4.3 一致）但**没有** `uid` 之外的维度。权限键仍为 `{FQCN}@{action}` 形态（`wa_rules.key`），**不是** §6 的 `admin.session.view` | ① §4.3 路由表按本节为准；② §6 权限点矩阵的**键名形态**须整体改写（**D3 已记**，P2 只补了 8 个 `sess.*` 节点 + 1 个页面菜单节点，`install.php` 幂等可重跑）；③ 页面控制器与 API 控制器**同名不同命名空间**（`app\controller\SessionController` vs `app\controller\api\SessionController`），权限键字符串**不可混用** |
| **D25** | 未预见（**本次修的缺陷**，属最容易静默的一类） | **`RedisReader::scanKeys()` 与 `RedisReader::sessions()` 处于两个不同「键空间」**：前者返回**已剥离 Redis 前缀的逻辑键名**（`session:ws-abc`），后者收 **clientId**（`ws-abc`）并**自行**拼 `RedisKeys::session()` ⇒ 直接对接会得到 `session:session:ws-abc`，**全部读空**。表现为 `scope=retained` 返回 0 条而 `scan.scanned=1`（SCAN 明明扫到了键），**不报错、不打日志** | 新增**唯一转换点** `SessionInspector::clientIdsFromKeys()`（纯函数，可单测），并在 `list()` 的 docblock 写明；单测用「`RedisKeys::session()` 往返必须回到同一个键」做契约断言（`testClientIdsFromKeysRoundTripMatchesRedisKeysSession`），而非只断言字符串被切掉。⚠ **凡「扫描产物」交给「按 clientId 取数」的函数前，必须先过这一层** —— 同类边界（`heartbeat:` / `uid:clients:` 等）未来照此办理 |
| **D26**（P2 加固，用户报出） | §11.5 的 `disableDefaultRoute` 段覆盖了**本项目自己的 5 个控制器**，但漏了 webman **脚手架自带**的 `app\controller\IndexController` | 该控制器是 `composer create-project` 的产物，**本项目从未在 `route.php` 注册过它**，于是它的三个公有动作**只经默认路由暴露、零鉴权**（实测：未带任何 Cookie 全部 **HTTP 200**）：`/index/index`（内嵌 `workerman.net` 欢迎页 iframe，白送一条指纹）、`/index/view`（渲染脚手架视图）、**`/index/json`**（返回 `{"code":0,"msg":"ok"}`）。⚠ `/index/json` 最危险：它的信封与本项目成功响应**形状完全一致**，未鉴权即可拿到一个「看起来成功」的响应，会**误导健康探针与扫描器**。另有一处连带影响：**根路径 `/` 的默认路由恰好落在 `IndexController::index` 上**，禁用后若无人显式接管，`/` 会从 200 变成 **404**（对运维是倒退）| ① 补 `Route::disableDefaultRoute(IndexController::class);`（第 6 行；**仍不得**一刀切全局禁用 —— webman-admin 的 `/app/admin/*` 靠默认路由解析）；② `/` 已由显式闭包路由接管为 `302 → /app/admin`，新增断言守住；③ **把「新增控制器必须禁用默认路由」从注释升级为门禁** —— 新增 `tests/Unit/RouteGuardTest.php`（6 用例：逐个扫 `app/controller/**` 比对禁用清单 / 反向断言禁用目标只落在自己的控制器上 / 脚手架具名锚点 / 不得全局禁用 / 每个显式路由必须被 `AdminAuth` 覆盖（语句自带或落在 `/api` 组区间内）/ `/` 必须是显式重定向），**6 组变异体全部被抓**；④ `p2_acceptance.php` 增 6 项检查（3 条静态 + 3 条线上探针 + 根路径），**70 PASS / 0 FAIL**；⑤ 线上反向对照已取证：注掉该行 → 1~3 秒内 `/index/*` 立刻变回未鉴权 **200**，`/index/json` 实回 `{"code":0,"msg":"ok"}` |

#### P2 刻意不做（宁缺勿假）

1. **不提供「本会话是否已被撤销」标志**。撤销名单键是 `auth:revoked:{sha256(token) 前 32 位}`（`Auth::tokenFingerprint()`），
   会话 Hash 里**没有指纹字段**、服务端**从不存 Token 原文** ⇒ 由 clientId/uid **不可反推**。
   只在 `/api/auth/revoked` 里列出名单本身，由人工核对指纹；说明文案由后端常量
   `SessionInspector::REVOKE_NOTE` **下发**（避免前端各自编词）。
2. **页面不轮询**。本页是**检索型**视图（与 P1 的**状态型**仪表盘不同），无定时器由
   `SessionContractTest::testScriptHasNoPollingTimers` 与前端 S1 场景双重钉住；
   历史遗留的 `meta refresh` 同样被禁。
3. **不提供写操作**。kick / revoke / unbind / 清空离线队列全部属 **P4**，本页只有 GET；
   读路径的「零写命令」由 `SessionContractTest::testReadPathContainsNoRedisWriteCommands`
   用 `php_strip_whitespace()` 扫源码守住（注释里提到 `sRem`/`hDel` 不会误报）。

---

---

### 11.6 P3 实施记录与实测偏差（2026-09-23）

> §7 P3 的任务项全部落地，**侵入性 0**（主项目 `src/`、`config/`、`tests/` 零改动）。
> 偏差 **D27~D31** 为本次新增。四项形态选择由用户拍板：
> **两页（`/push` + `/actions`）** · **审计提前到 P3 启用** · **复用 P2 离线队列端点** · **只读角色仅历史 + 模板查看**。

#### 交付物与门禁

| 层 | 文件 | 门禁结果 |
|---|---|---|
| 服务层 | `Pusher.php`（`Message::encode()` 同源字节预校验 + 4 条口径 NOTE） · `PushRepository.php`（分页 / 筛选 / 汇总） · `TemplateRepository.php`（CRUD） · `ActionCatalog.php`（6 个 `http=true` 动作白名单 + 元信息） · `ActionOutcome.php`（六态归一 + `RESEND_*` 三值） · `Perm.php`（权限求值，纯渲染期） · `Auditor.php` | `PushContractTest` **18 tests / 135 assertions** · `ActionContractTest` **21 tests** · `ActionOutcomeTest` 补 3 条常量不变量 |
| API 控制器 | `app/controller/api/PushController.php`（5 端点） · `app/controller/api/ActionController.php`（2 端点，含 `result()` 复用于补查） | 路由动词由契约测试静态钉住 |
| 页面控制器 | `app/controller/PushController.php` · `app/controller/ActionController.php`（`POLL_DELAYS_MS` 契约常量） | 同上 |
| 视图 / 前端 | `app/view/push/index.html` · `app/view/action/index.html` · `public/static/{push,action}.{js,css}` | `push_render_check.js` **122 项** · `action_render_check.js` **103 项**；P1 的 **144 项**与 P2 的 **122 项** 无回归 |
| 共用 harness | `tests/Frontend/lib/fake_env.js`（假 DOM：`confirm` 可注入、计时器**既记账又入队**、可逐项推进） | 两个 P3 校验共用；两个既有校验**刻意不动** |
| 验收脚本 | `tests/Manual/p3_acceptance.php`（只读 **88 PASS / 0 FAIL / 2 SKIP**；`--seed` **123 PASS / 0 FAIL / 2 SKIP**） | 4 层：服务层 / 模板 / HTTP / 三条线上反证 |
| 后端总门禁 | `phpunit` · `phpstan`（L6，无 baseline） | **201 tests / 1053 assertions** · **0 errors** |

**变异测试**：3 组前端注入（删 `<select>` 默认值 / 删 `clearTimeout` / 用 `retryable` 冒充 `resend_*`）
→ **31 / 2 / 9 项失败全部被抓**，证明新校验不是恒真的。

#### 本次新增偏差

| # | 前文表述 | 实测事实 | 影响 |
|---|---|---|---|
| **D27** | §7 P3 写「后台提示**已去重**」，并拟新增端点 `/api/push/queue/{uid}` | ① **去重不可观测**：`Push::enqueue()` 用 `setNxEx(pushDedup)`，非首次仅 `Monitor::incr('push_dedup')`，**响应体键集合与首次完全一致**。线上反证：同 `msg_id` 连发两次，两次 `code=0`、键集合相同、**没有任何字段能区分**；② `/api/push/queue/{uid}` 与 P2 已落地的 `/api/sessions/offline/{uid}` **同源** | ① 「后台提示已去重」**不可实现**，改为后端下发 `Pusher::DEDUP_NOTE` 明示「去重是静默的、本次是否被去重不可判定」，验收脚本用**反证**（两次响应无差异）把它钉成事实；② **不新增端点**，直接复用 P2 的（用户已确认，登记为偏差） |
| **D28** | 未预见（**主项目侧语义缺陷**，后台只能绕开、不碰主项目） | `PUSH_PAYLOAD_MAX`(4096) 的判定点在 **business 进程**（`src/Business/Push.php:421 dispatch()`），即 `/push` 已返回 `200 accepted` **之后**；超限只 `incr('push_fail')` + `Logger::warn` ⇒ **静默丢弃、调用方零反馈**。线上反证：直连主项目发 **10KB** 载荷 → 仍回 `HTTP 200 / code=0 / msg=accepted`，但 `push_fail` 计数 **0 → 1** | 后台用 `Message::encode()` 做**逐字节同源**预校验（服务端判据是 `strlen()`，故必须**紧凑重新编码**才计数 —— 否则 3KB 格式化载荷会被报成 6KB 并误拦）；回执一律写「**已受理**」不写「已投递」，并下发 `PAYLOAD_DROP_NOTE` 说明「超额在服务端被静默丢弃」。验收脚本以 `push_fail` 增量作为**反证** |
| **D29** | `ActionOutcome::$retryable` 被当成「可重试」在界面上渲染 | `retryable` 的定义是「**补查**是否可重试」（只对 `pending` 为真），与「**重发**动作是否安全」**是两件事**：`rejected` 从未入队 ⇒ 重发**安全**；`transport` 未取到响应 ⇒ **不要自动重发**；`expired` 两义 ⇒ **待确认**。**一个布尔表达不了三种结论** | 新增三值 `RESEND_SAFE/UNSAFE/UNKNOWN` + `resend_label` / `resend_tone` / `resend_note` **由后端下发**（前端只渲染不编词，与「文案不得在前端复述」的既有纪律一致）；`retryable` 保留原义不变，二者在 `ActionOutcomeTest` 中分别钉住 |
| **D30** | 未预见（**本次修的缺陷**，两页同源） | `action.js` 的 `fillActions()` 只 `appendChild` option、**从不给 `node.value` 赋值**。真浏览器会自动选中首项，**假 DOM 不会** ⇒ 首屏 `val('a-action') === ''` ⇒ `validate()` 命中「动作不在 HTTP 开放清单内」⇒ **首屏直接拒发、一个请求都不发**（29 项校验失败的单一根因）。`/push` 侧漏掉的后果更隐蔽：主项目对未知 `target_type` **兜底回落成 `uid`**，推送目标类型被**悄悄换掉且全程零报错** | ① `fillActions()` 填充后显式 `node.value = names[0]`；② `push.js` 的 `fillSelect()` 同口径补「**无前缀项时**显式选中首项」，**带 `prefixText` 的筛选下拉不设默认**（那里的空串本身就是「不限」，塞值会让筛选默认收窄、用户看到的历史条数莫名变少）；③ 两页各自的首屏初值由渲染校验钉住 |
| **D31** | 未预见（**本次修的缺陷**） | `stopPoll()` 只靠 `poll.seq += 1` 作废旧链，**不真的取消定时器** ⇒ 「手动补查作废自动链」在**语义**上成立、在**实现**上是假的：队列里还挂着定时器，回调只是被 seq 挡住。假 DOM 的 `clearTimeout` 原为空实现 ⇒ 「已作废」**不可断言** | ① 补 `window.clearTimeout(poll.handle)` 并持有句柄；② 假 DOM 的 `clearTimeout` 改为**真的出队**，使「作废」成为可断言的事实（A10 场景）；③ 两处修复各有一组变异体验证（删 `clearTimeout` → 2 项失败） |

#### P3 其他实测要点（易再犯）

1. **`push_task.request_id` 是 `CHAR(16)`**（值来自 `bin2hex(random_bytes(8))`）：验收脚本夹具曾写 20 字符，直接
   `SQLSTATE 22001 / 1406 Data too long` **致命退出**（不是干净 FAIL）。已加「夹具自检」先量列宽，把常量漂移变成可诊断的断言。
   同理 **`operator_id` 是 `INT UNSIGNED`**：夹具取负数会 `1264 out of range`，改取 `0`（真实行恒 `> 0`）；
   ⚠ 但清理**只按 `target`/`name` 的 `p3test%` 前缀**，绝不按 `operator_id` —— 那会连带删掉任何 `operator_id = 0` 的行。
2. **`POST /action` 的四种响应形态不可按 HTTP 判成败**：`200+code0+done` / `200+业务码+failed`（**HTTP 是 200 却失败**） /
   `202+pending`（**超窗不是失败**）/ `4xx|5xx`（入队前失败）。另有 `expired`：**服务端对 `404 + 4004` 刻意不区分**
   「仍在执行」与「已过 `ACTION_RESULT_TTL` 被回收」⇒ 后台**同样不替用户猜**（线上反证 C：补查未命中回 `HTTP 200 + code=0`
   而非 404，否则前端会把它当接口故障并打断补查）。
3. **定时器政策三级分工**（与 P1/P2 一致性）：`/dashboard` 周期轮询 → `/sessions` + `/push` **零定时器** →
   `/actions` **恰好一个** `setTimeout` 递归退避（`POLL_DELAYS_MS = [1000,2000,4000,8000,8000]`，总 23s
   < `ACTION_RESULT_TTL`×1000 且 ≤ 其 80%，由 `ActionContractTest::testBackoffWindowFitsInsideResultTtl` 钉住）。
   「启动退避」必须从渲染函数里**抽出来**（`maybeStartPoll()`），只在 `invoke` 成功后调一次 ——
   否则补查响应仍是 `pending` 时会重置计数，形成**无限退避**（延迟恒为第一个值、次数永远耗尽不了、请求量翻几倍）。
4. **动作白名单只能列 `http=true` 的 6 个**：`session` 未开放 HTTP，放进清单等于给用户一个**必然失败**的选项；
   `requires_uid` 由后端元信息驱动，**不许前端写死**「uid 必填」（否则 P4 的 `auth=false` 动作会被前端挡住）。
   另 `'http' => true` 是「**额外**开放 HTTP」而非「**仅限** HTTP」（主项目 `ActionRunner.php:321` 只单向拦），与红线 ㊸ 同向。

#### P3 刻意不做（宁缺勿假）

1. **不宣称投递结果**。受理成功只意味着「主项目已入队」，是否送达取决于目标是否在线、载荷是否在服务端被丢弃（**D28**）——
   回执统一写「已受理」并附三条口径说明。
2. **不提供「按主题推送」**。主项目 `Push::TARGET_*` 只有 `uid`/`device`/`client`，主题广播走 `Push::enqueueTopic()`
   且**无 HTTP 入口** ⇒ UI 里不得出现该选项（`NO_TOPIC_NOTE` + 契约测试双钉，对应 §7 风险 R1）。
3. **不做动作的重发按钮**。`report` 累加计数、`notify` 会再推一条 ⇒ 自动重发产生**重复业务效果**；
   只在回执里如实给出 `resend_label`（安全 / 不要重发 / 待确认），由人工判断。
所有写操作（kick / revoke / unbind / 清空离线队列）属 **P4**。*

*下一步为 **P3 推送管理**（发起推送转签、推送历史 `push_task`、动作调用、模板 CRUD）。*

---
*本文件为设计方案，落地实现以代码为准。§3 的 6 处主项目改动（C1~C6）需逐项确认后再实施；
其推荐取值见 §0.4；「运维动作的信任域」已于 2026-09-23 拍板为**选项 A**
（落地清单 A1~A5 尚未执行，须在 C1~C6 上线前完成，见 §9.2 的 R11）。*

***P0 已完成（2026-09-23）**：`admin/` 骨架、RBAC 三角色、健康卡片页与 4 个只读 API 全部实测通过，
主项目 `src/`、`config/` 零改动。*

***P1 已完成（2026-09-23）**：监控仪表盘改为「服务端骨架 + 浏览器分层轮询（5s Redis-only / 30s 含主项目 HTTP）
+ 自绘 SVG + 环形缓冲」；新增 `MetricsDeriver` 纯函数派生层与 `mon.live` 权限点；
后台门禁 PHPStan L6 **0 errors** / PHPUnit **40 tests** / 前端渲染校验 **144 项**，
P1 验收脚本 **41 PASS / 0 FAIL / 1 SKIP**。**主项目 `src/`、`config/`、`tests/` 仍为零改动。** §11 的实施记录与实测偏差 **D1~D21 覆盖前文表述，冲突以 §11 为准**。*

***P1 明确不做**：实时日志 tail（需另开一条 SSE 通道，见红线 ㉙ 同向的阻塞式读取约束），
若要上须作为独立阶段单独立项。*

***P2 已完成（2026-09-23）**：会话只读面板（列表 `scope=online|retained|all` / 页内抽屉详情 /
uid·device 反查 / 订阅双向 / 离线队列只读 / Token 撤销名单）+ 修掉 P1 遗留的
**默认路由鉴权绕过**（`Route::disableDefaultRoute()` 按控制器精确禁用，见 **D24**）；
随后按用户报出的漏项加固：关掉 webman **脚手架欢迎页控制器**留下的免鉴权入口
（`/index/index`、`/index/view`、**`/index/json`**），并把「新增控制器必须禁用默认路由」
**从注释升级为门禁** `tests/Unit/RouteGuardTest.php`（见 **D26**，6 组变异体全部被抓）。
后端 PHPStan L6 **0 errors** / PHPUnit **89 tests / 383 assertions** /
前端渲染校验 **122 项**（P1 的 144 项无回归）/ P2 验收脚本 **70 PASS / 0 FAIL / 1 SKIP**；
线上已做**反向对照取证**（注掉禁用行 → 1~3 秒内 `/index/*` 立刻变回未鉴权 200）。
**主项目 `src/`、`config/`、`tests/` 仍为零改动。** 实施记录与实测偏差 **D1~D26 覆盖前文表述，冲突以 §11 为准**。*

***P2 明确不做**：实时日志 tail（仍缓做）；「本会话是否已撤销」标志（不可判定，见 §11.5 末）。*

***P3 已完成（2026-09-23）**：推送管理与动作调试两页 —— `/push`（发起推送 / 推送历史 / 模板管理）与
`/actions`（动作调试器，只列 6 个 `http=true` 动作）；`admin_audit_log` **提前到 P3 启用**
（`push.create` / `push.template.save` / `push.template.delete` / `action.invoke` 四条全部落库，补查**刻意不落**）；
离线队列**复用 P2 端点**未新增；只读角色范围 = **仅历史 + 模板查看**。
三条「不可观测 / 静默」已由**线上反证**定案并写进后端 NOTE：**去重不可判定**（**D27**）、
**超限载荷被主项目静默丢弃**（**D28**，10KB 仍回 `code=0` 而 `push_fail` 0→1）、
**补查未命中不区分「执行中」与「已回收」**（反证 C，`HTTP 200 + code=0`）。
后端 PHPStan L6 **0 errors** / PHPUnit **201 tests / 1053 assertions** /
前端渲染校验 **491 项**（144 + 122 + 122 + 103，P1/P2 无回归）/ P3 验收脚本
**只读 88 PASS · `--seed` 123 PASS，均 0 FAIL**，3 组前端变异体全部被抓。
**主项目 `src/`、`config/`、`tests/` 仍为零改动。** 实施记录与实测偏差 **D1~D31 覆盖前文表述，冲突以 §11 为准**。*

***P3 刻意不做**：不宣称投递结果（只写「已受理」）；不提供「按主题推送」（无 HTTP 入口）；
不做动作重发按钮（`report` 累加计数 / `notify` 再推一条 ⇒ 重复业务效果）。*

### 11.7 P4 实施记录与实测偏差（2026-09-24）

> §7 P4 的任务项全部落地：**§3 的 6 处主项目改动（C1~C6）已实施**（本阶段起主项目侧不再零改动，
> 但全部属于设计内改动、无未经确认的偏差）；「运维动作的信任域」按 §9.2 的 **选项 A** 落地
> （`API_SECRET` 兼任管理面凭证，等式「持有 API_SECRET ⇒ 可踢任意人」在 API_LISTEN 回环 + 单一可信调用方下决策接受）。
> 偏差 **D32~D35** 为本次新增。

#### 主项目侧交付（C1~C6）

| 项 | 文件 | 说明 |
|---|---|---|
| C1/C2 通道白名单 | `src/Business/ActionRunner.php`（`channelExposed()` + `declaredChannels()`）· `src/Api/Bootstrap.php`（入队侧同口径判定） | **双防线**：执行侧裁定（动作队列是 Redis 键，持 Redis 凭证者可直写）+ 入队侧拒绝（只是体验）；**缺省必须全放行**（既有 7 个动作未声明，收紧即线上行为变更），未注册动作返回 false |
| C3 动作声明 | `config/actions.php`：`kick` / `revoke` / `unbind`，均 `'channels' => [http]`、`'auth' => false`、`'http' => true` | ⚠ 红线 ㊸ 落地：运维动作必须声明 `channels`，否则任何已鉴权终端客户端都能经 WS 踢任意 clientId（终端提权） |
| C4 处理器 | `src/Business/Action/KickAction.php` · `RevokeTokenAction.php` · `UnbindDeviceAction.php` | kick：`udp:` 前缀计 `skipped`（不在 Gateway 连接表，`closeClient()` 无效）；revoke：指纹**闭包外算好**（不捕获明文进闭包作用域）；unbind：幂等 |
| C5 指标 | `config/app.php` `monitor.metrics` + `action_kick/action_revoke/action_unbind` | 不另加 `action_http_*` 分通道计数 |
| C6 文档 | 对外接口文档 §8/§9.4 · README · `.env.example`（三处同步） | §9.4 =「管理面凭证（API_SECRET 的第二重身份）」三个升级触发条件 |

#### 后台侧交付

| 层 | 文件 | 门禁 |
|---|---|---|
| 服务层 | `OpsAction.php`（转签 + `CAVEATS` 三条「不做什么」+ `fingerprint()`=sha256 前 32 位，**第四处**指纹实现，四处必须同口径） | `OpsActionContractTest` **12 tests / 61 assertions**（含明文 token 不外泄三条断言） |
| API | `app/controller/api/OpsActionController.php`（kick / revoke / unbind / forceOffline，HTTP 恒 200） | 路由动词由契约测试钉住 |
| 路由 / 权限 | `config/route.php`（4 条 POST + `disableDefaultRoute`）· `scripts/install.php`（节点 id 138~141，只读角色**一个都不给**） | p4 验收拿 **DB 真值**对拍（`wa_rules` 141 条） |
| 会话页 | `SessionController.php`（cfg 注入 `ops_*` + `perms`）· `app/view/session/index.html`（运维操作区）· `public/static/session.js`（`runOps` / `renderOpsResult` / `applyOpsPerms`，零定时器） | `SessionContractTest` **19 tests / 105 assertions** · `session_render_check.js` **152 项**（P4 前 122 项无回归） |
| 验收 | `tests/Manual/p4_acceptance.php` + `composer test:acceptance:p4` | 默认 **22 PASS / 0 FAIL / 2 SKIP**；`--live` **46 PASS / 0 FAIL / 1 SKIP**（真实调四端点 + 审计落库核对，目标 `p4test-*`） |
| 总门禁 | 主项目：PHPStan **0 errors** · PHPUnit **533 tests / 1542 assertions** · **e2e 17 用例全过（含 [Q] 运维动作通道隔离）**；后台：PHPStan **0 errors** · PHPUnit **217 tests / 1155 assertions** · 前端 **152 项** | |

**变异测试**：① 主项目删 `kick` 的 `channels` → 契约测试 1 条失败；② 后台删 `disableDefaultRoute` + 只读角色拿 `ops.unbind` → 2 条失败；③ 视图改名 `ops-token` id → 契约 2 条 + 前端 3 条失败；④ 删 kick 空值拦截 → S19 2 条失败；⑤ 删 partial 展示行 → S22 1 条失败。新校验**全部真会咬**。

#### 本次新增偏差

| # | 前文表述 | 实测事实 | 影响 |
|---|---|---|---|
| **D32** | `forceOffline` 审计 params 拟记 `has_token`（「是否提供了 token」这一事实） | `Auditor::REDACT_PATTERN` 按**子串**匹配（含 `token` 即整体打码，宁枉勿纵）⇒ 实测落库为 `has_token: "***"` —— 「禁止重连那一半到底做没做」这个**最关键的审计事实**被脱敏吞掉 | 键名改 **`revoke_applied`**（布尔事实、不含任何凭证内容、不触发脱敏）。不放宽 `REDACT_PATTERN`：误伤只损失可读性、漏掉真密钥是安全事故，两侧不对称 |
| **D33** | §7 未预见：P4 三个动作 `http=true`，但 `/actions` 调试页**不该**给运维动作一个一键踢人按钮 | `ActionCatalogTest::testHttpExposedSetMatchesMainProjectBothWays` 的双向比对是 `names()` ⊆ 清单 ⊆ `names() ∪ 未开放`，主项目多 3 个 `http=true` 动作后两条路都不对（既不能进清单、也不是「未开放」） | `ActionCatalog` 新增第三类 **`NOT_IN_DEBUGGER`**（与 `NOT_HTTP_EXPOSED` 语义区分：前者 HTTP 开放但调试器不该列），比对口径改为 `names() ∪ NOT_IN_DEBUGGER` |
| **D34** | 未预见（验收脚本首跑踩） | `wa_rules.key` 存的是**控制器@动作**，`ops.kick` 只是 `install.php` 里的别名 —— 拿别名查 DB **恒缺**（明明跑过 install.php 却报「缺 4 个」）；同理 revoke 审计行的 `target` 是**指纹**，按 `p4test%` 前缀查恒为 0 行 | p4 验收按「值」查节点；revoke 审计行改按「action + 本轮开始时间」捞；清理时同时按前缀与时间兜（只删本轮行，不碰历史） |
| **D35** | §7 未预见（P2→P4 语义升级的连带） | `SessionContractTest::testScriptOnlyIssuesGetRequests` 钉死「本页零写方法」，P4 后必然红 —— 但**放宽必须收得很紧**，否则「写操作从哪发起」就没人管 | 断言改为：`method: 'POST'` 全文**恰好一处**且必须落在 `postJson()` 内；`runOps()` 调用点数 = 运维按钮数 + 1（多出即存在非按钮触发的写操作）；路由动词按端点判定（`/api/ops-action/*` 必须 POST，其余必须 GET）；`opsVal`/`bind` 补进 `ID_ACCESSORS`（新取 id 辅助漏登记会让整组输入框逃过契约检查） |

#### P4 语义边界（UI 与回执一律如实表达，不许概括成败）

1. `kick` 只断 TCP 不撤 Token（客户端可立即重连）；`revoke` 不断连接（WS 下次鉴权 / UDP 下一包才生效）；
   `unbind` 不踢线；UDP 无踢线（计 `skipped` 不计 `failed`）。
2. **「强制下线」必须 revoke → kick 串行**，顺序反了客户端会落在重连窗口内用同一 Token 重连成功。
3. `revoke` 只接受**明文** token（指纹单向，服务端不存明文）⇒ 只能人工粘贴；它因此是本页唯一会流经后台的
   长期凭证 —— 输入框 `type="password"`、只进 POST body 不进 URL、审计只落指纹（契约测试三道断言钉死）。
4. 未给 token 的 force-offline **如实回 `partial=true`** + `partial_note`（不假装完成「禁止重连」），
   前端刻意**不在客户端拦** —— 拦了用户会误以为「强制下线做不了」。

---


***P4 已完成（2026-09-24）**：运维操作区上线 —— 会话页新增 kick / revoke / unbind / 「强制下线」（revoke→kick 串行）
四端点，`admin_audit_log` 全部落库；**§3 的 6 处主项目改动（C1~C6）已实施**：`channels` 反向通道白名单
（执行侧 + 入队侧双防线，红线 ㊸ 落地）、3 个运维动作处理器、`config/actions.php` 声明、metrics 登记、
三份文档同步；信任域按**选项 A**（`API_SECRET` 兼任管理面凭证）。安全前提的唯一端到端验收点 =
主项目 e2e 用例 **[Q] 运维动作通道隔离**（已鉴权普通客户端经 WS 调三动作全部 4006）。
主项目门禁：PHPStan **0 errors** / PHPUnit **533 tests / 1542 assertions** / e2e **17 用例全过**；
后台门禁：PHPStan **0 errors** / PHPUnit **217 tests / 1155 assertions** / 前端 **152 项** /
P4 验收默认 **22 PASS** · `--live` **46 PASS**，均 0 FAIL；5 组变异体全部被抓。
实施记录与实测偏差 **D1~D35 覆盖前文表述，冲突以 §11 为准**（详见 §11.7）。*

***P4 明确不做**：`/actions` 调试页**不列**运维动作（`NOT_IN_DEBUGGER`，一键踢人不属于调试器）；
审计页对 revoke 的检索**按指纹**（明文不可得，这是设计而非缺陷）。*

***P5 已完成（2026-09-24，侵入性 0 —— 主项目零改动）**：后台新增 `/ops` 运维页 +
3 个只读端点（日志尾读 / 角色状态三源合一 / 密钥轮换引导 + 脱敏现状）；`SecretMasker`
（前 4 后 4、<8 全掩、空值原样）与审计侧 `Auditor::redact()`（宁枉勿纵）是**两套口径**；
日志 role 白名单与主项目 `RoleCatalog` 靠 `P5OpsServiceTest` 正则对照防漂移。
同日还完成：viewer 演示账号（install.php 步骤 6b 幂等创建，消除三份验收的 SKIP）、
脚手架 `IndexController` 彻底删除（D36）、`purge_offline` 运维动作（第五个运维节点，
通道隔离已并入 e2e [Q]，D37）。

**D36**：脚手架 `IndexController` 由 `disableDefaultRoute` 改为**文件删除**；
`RouteGuardTest::testScaffoldWelcomeControllerIsRemoved` 改钉「类文件不存在 + route.php 无代码引用」。
**D37**：`purge_offline` 使运维动作从 4 个变 5 个 —— 后续新增运维动作时，
需同步四处：`config/actions.php` 声明 + admin `OpsAction`/控制器/路由/节点 + 两份契约测试的清单 + e2e [Q] 的动作列表。*
