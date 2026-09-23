# GatewayPush 客户端 SDK 与调试器设计方案

> 状态：已确认，**P0~P6 全部完成**（2026-09-21）
> 对应服务端：`Workerman V2 GatewayPush 实时数据推送服务技术方案文档.md`
> 使用手册：`README.md`

---

## 0. 决策记录

| 项 | 决策 | 说明 |
|---|---|---|
| 客户端形态 | **SDK + 调试器** | 先做可复用库，再基于同库做 CLI 调试器 |
| 语言栈 | **PHP** | 可复用服务端 `Message` / `Auth` 协议类，协议零漂移 |
| 通道覆盖 | **WS + UDP + HTTP 全量** | 一次对齐「对接服务端所有功能」的目标 |
| 交付位置 | 本仓库 `client/` 目录 | 单仓库单机阶段的最小代价方案 |
| 调试器交互模式 | **REPL + 一次性命令（两者都做）** | 一次性命令适合脚本化单步验证；REPL 适合多步联调，避免反复重连与重复签发 Token |

---

## 1. 目标与范围

### 1.1 目标

交付一套 PHP 客户端，把服务端四类对外入口的全部能力封装为可复用 SDK，并提供基于同一套 SDK 的 CLI 调试器。交付后应能：

- **作为库被业务系统引入**：完成连接、鉴权、心跳、业务动作、接收推送、断线重连、离线补投；
- **作为调试器人工联调**：在无业务代码的情况下走通全部链路，验收口径与 `tests/e2e_check.php` 对齐。

### 1.2 范围边界（明确不做）

| 不做 | 原因 |
|---|---|
| 监控面板页面 | 服务端 `dashboard` 角色已提供（`resources/dashboard/index.html`） |
| 集群部署相关 | 保持单机，集群方案已归档 |
| Token 的持久化存储 | 客户端只持有/签发 Token，不做账号体系 |
| 服务端改造 | 本方案为纯新增，不改动 `src/`、`start.php`、`config/` |

---

## 2. 关键决策：复用服务端协议类（协议零漂移）

服务端的 `Message` 与 `Auth` 是**纯计算、无 IO 依赖**的类，客户端直接复用而非重新实现：

| 复用的服务端类 | 客户端用途 | 收益 |
|---|---|---|
| `Message::encode` / `decode` | 报文编解码与字段归一化 | 与服务端逐字节一致 |
| `Message::sign` / `canonicalize` | UDP 报文签名 | `canonicalize`（递归键名升序 + 紧凑 JSON）不会出现跨实现偏差 |
| `Message::error` / `packet` / `ack` | 报文构造 | 字段结构天然对齐 |
| `Auth::issue` | 调试用 Token 签发 | 签名算法、载荷字段与服务端完全一致 |
| `Auth::verifyLocal` | Token 本地预校验 | 连接前即可发现过期/篡改 |

**实现方式**：客户端命名空间 `GatewayPush\Client\`，在 `Protocol/` 层做**薄适配**（`Codec` / `Signer` / `TokenIssuer` 内部调用服务端类）。将来若要把客户端抽成独立 composer 包，只需替换适配层实现。

> **取舍**：客户端因此依赖服务端仓库的 `src/Business/Message.php` 与 `src/Business/Auth.php`。
> 当前单仓库、单机阶段这是最小代价。若后续要独立分发，再把这两个类抽到共享包
> （如 `gateway-push/protocol`），服务端与客户端同步引用。

**composer 自动加载新增一行**：

```json
"autoload": {
    "psr-4": {
        "GatewayPush\\": "src/",
        "GatewayPush\\Client\\": "client/src/"
    }
}
```

---

## 3. 总体架构

```
┌─ 应用层   App / CLI ──────────────────────────────────┐
│   client/bin/gwclient.php（调试器命令）                │
├─ 服务层   Service ────────────────────────────────────┤
│   EchoApi SessionApi ReportApi SubscribeApi           │
│   NotifyApi AdminApi                                   │
├─ 事件层   Event ──────────────────────────────────────┤
│   PushReceiver（收推送 → 回调 → 自动回 ack）           │
├─ 会话层   Session ────────────────────────────────────┤
│   SessionManager：鉴权状态机 / seq 生成 / 心跳 /       │
│   重连退避 / pending 请求表（seq → 回调 + 超时）       │
├─ 协议层   Protocol ───────────────────────────────────┤
│   Codec │ Signer │ TokenIssuer  （薄适配服务端类）     │
├─ 传输层   Transport ──────────────────────────────────┤
│   WsTransport │ UdpTransport │ HttpTransport           │
└───────────────────────────────────────────────────────┘
```

数据流（上行）：`Service → Session(pending 登记 + seq) → Protocol 编码/签名 → Transport 发送`
数据流（下行）：`Transport 收包 → Protocol 解码 → Session 按 cmd 路由 → Pending 回调 / PushReceiver`

---

## 4. 目录结构

```
client/
├── README.md                      客户端使用说明
├── src/
│   ├── Client.php                 门面：统一入口
│   ├── Config.php                 客户端配置（含校验与默认值）
│   ├── Transport/
│   │   ├── TransportInterface.php 统一传输接口
│   │   ├── WsTransport.php        ws:// （workerman AsyncTcpConnection）
│   │   ├── UdpTransport.php       udp://（workerman AsyncUdpConnection，延迟首包+重传）
│   │   └── HttpTransport.php      http://（管理端，自动补 X-Timestamp/X-Sign）
│   ├── Protocol/
│   │   ├── Codec.php              编解码适配（→ Message）
│   │   ├── Signer.php             签名适配（→ Message::sign）
│   │   └── TokenIssuer.php        Token 签发/解析（→ Auth）
│   ├── Session/
│   │   ├── SessionManager.php     鉴权态/seq/心跳/重连/pending
│   │   └── PendingRequest.php     单次请求上下文
│   ├── Service/
│   │   ├── EchoApi.php
│   │   ├── SessionApi.php
│   │   ├── ReportApi.php
│   │   ├── SubscribeApi.php       subscribe / unsubscribe / topics
│   │   ├── NotifyApi.php
│   │   └── AdminApi.php           /push /stats /health
│   ├── Event/
│   │   └── PushReceiver.php
│   └── Error/
│       ├── ErrorCode.php          4000-4008 / 5000 常量与文案
│       └── ClientException.php    错误/超时统一异常
├── bin/
│   └── gwclient.php               调试器入口
└── tests/
    ├── Unit/                      Protocol / Session 单测
    └── ClientE2E.php              客户端侧端到端自检（覆盖服务端 A~O 共 15 用例，服务端另有 P）
```

---

## 5. 逐层设计

### 5.1 Transport 传输层

| 类 | 底层 | 关键点 |
|---|---|---|
| `WsTransport` | `Workerman\Connection\AsyncTcpConnection('ws://...')` | 事件 `onConnect` / `onMessage` / `onClose` / `onError` 向上抛给 `SessionManager` |
| `UdpTransport` | `AsyncUdpConnection('udp://...')` | **延迟首包 + 应用层重传**（`send()` 返回 true 不代表送达，首包在 `onConnect` 中立即发会静默丢失） |
| `HttpTransport` | `AsyncTcpConnection('http://...')` | 自动计算 `X-Timestamp` / `X-Sign = hex(hmac_sha256("ts|rawBody", api_secret))`；按 `Content-Length` 精确读取（避免 keep-alive 阻塞） |

统一接口：

```php
interface TransportInterface {
    public function connect(): void;
    public function send(string $frame): void;
    public function close(): void;
    public function isConnected(): bool;
    public function onOpen(callable $cb): void;    // P1 落地新增：握手完成信号（15s 鉴权窗口需要）
    public function onMessage(callable $cb): void;
    public function onClose(callable $cb): void;
    public function onError(callable $cb): void;
}
```

### 5.2 Protocol 协议层

| 类 | 方法 | 内部调用 |
|---|---|---|
| `Codec` | `encode(array $packet): string` / `decode(string $raw): ?array` | `Message::encode` / `Message::decode` |
| `Signer` | `sign(array $packet, string $secret): string` / `canonicalize(mixed): string` | `Message::sign` / `Message::canonicalize` |
| `TokenIssuer` | `issue(array $claims, int $ttl): string` / `inspect(string $token): array` | `Auth::issue` / `Auth::verifyLocal` |

> `TokenIssuer` 需先 `Auth::init(['secret' => $secret, 'token_ttl' => $ttl])`。

### 5.3 Session 会话层

**`SessionManager` 职责**

- **鉴权状态机**：`disconnected → connecting → connected → authenticating → ready`
- **seq 生成**：单调递增，字符串类型（服务端原样回传）
- **pending 请求表**：`seq → PendingRequest`（回调 + 超时定时器）
- **心跳**：
  - 主动 `ping`（可配间隔，用于 RTT 与存活探测）
  - **应答服务端反向心跳**：收到 `{"cmd":"ping","ts":0}` 必须回 `pong`（网关心跳 25s/次、漏 2 次断开）
- **重连**：指数退避；重连后自动重发 `auth`（同 `device_id` → 服务端恢复会话并补投离线消息）
- **下行分发**：按 `cmd` 路由 → `ack`/`pong` 回 pending，`push` 交 `PushReceiver`，`error` 触发 `onError`

**`PendingRequest`**

```php
final class PendingRequest {
    public string $seq;
    public float  $sentAt;
    public int    $timerId;
    public $onReply;    // function (array $packet): void
    public $onTimeout;  // function (): void
}
```

### 5.4 Service 业务动作层

| 客户端 API | 报文 | 备注 |
|---|---|---|
| `echo(array $params, callable $cb)` | `{cmd:data, data:{action:echo, params}}` | `params` 透传（服务端 `params='*'`） |
| `session(callable $cb)` | `action=session` | 无参 |
| `report(string $topic, int $count, $value, callable $cb)` | `action=report` | **UDP 下服务端静默不回执**，`cb` 仅本地超时 |
| `subscribe(string $topic, callable $cb)` | `action=subscribe` | |
| `unsubscribe(string $topic, callable $cb)` | `action=unsubscribe` | |
| `topics(callable $cb)` | `action=topics` | |
| `notify($value, string $msgId, string $offlineMode, callable $cb)` | `action=notify` | 目标恒为自身 uid |

回调统一签名：`function (bool $ok, array $data, ?array $error): void`。

### 5.5 Event 事件层

**`PushReceiver`**

- 识别下行 `cmd=push`，解析 `payload` / `msg_id` / `source` / `offline` / `pushed_at`；
- 回调业务 `onPush(payload, meta)`；
- **自动回 `cmd=ack`**（`data.msg_id` 对齐，服务端据此统计投递质量）；
- `offline=1` 标记为「重连补投」，业务可据此区分实时/补投。

### 5.6 AdminApi HTTP 管理端

| 方法 | 服务端接口 | 说明 |
|---|---|---|
| `push(string $targetType, string $target, array $payload, array $opts, callable $cb)` | `POST /push` | `targetType` ∈ `uid`/`device`/`client`；`opts` 支持 `msg_id` / `offline_mode` |
| `stats(callable $cb)` | `GET /stats` | 返回指标快照 |
| `health(callable $cb)` | `GET /health` | 免鉴权 |

### 5.7 Error 错误层

- `ErrorCode`：与服务端 `Message::$codeMessages` 对齐的常量与文案
  （`4000` 报文格式 / `4001` 签名 / `4002` 时间戳 / `4003` 未鉴权 / `4004` 鉴权失败 /
  `4005` Token 过期 / `4006` 未知指令 / `4007` 缺参 / `4008` 限流 / `5000` 服务端错误）。
- `ClientException`：封装 error 报文、传输错误、请求超时三类。

---

## 6. 客户端门面 API（草案）

```php
use GatewayPush\Client\Client;

$client = new Client([
    'ws_url'     => 'ws://127.0.0.1:8282',
    'udp_url'    => 'udp://127.0.0.1:8283',
    'api_url'    => 'http://127.0.0.1:8290',
    'channel'    => 'ws',                 // ws | udp
    'uid'        => 'u1',
    'device_id'  => 'd1',
    'secret'     => '<AUTH_SECRET>',      // 报文签名 / Token
    'api_secret' => '<API_SECRET>',       // HTTP 管理端（留空回退 AUTH_SECRET）
    'token_ttl'  => 7200,
    'reconnect'  => true,
    'heartbeat'  => 20,                   // 主动 ping 间隔（秒），0 = 关闭
    'timeout'    => 5,                    // 单次请求超时（秒）
]);

$client->onPush(function (array $payload, array $meta) { /* ... */ });
$client->onError(function (int $code, string $msg, array $packet) { /* ... */ });
$client->onStateChange(function (string $state) { /* ... */ });

$client->connect();
$client->auth(function (bool $ok, array $data) { /* data: uid/device_id/protocol/reconnected */ });

$client->echo(['hello' => 'world'], fn($ok, $data) => null);
$client->subscribe('topic.a', fn($ok, $data) => null);
$client->notify(['x' => 1], 'msg-1', null, fn($ok, $data) => null);

$client->admin()->push('uid', 'u1', ['title' => 'hi'], ['msg_id' => 'm-1'], fn($ok, $data) => null);

$client->close();
```

---

## 7. 协议对接矩阵（服务端 → 客户端）

| 服务端标准 | 入口 | 客户端落点 |
|---|---|---|
| `cmd=auth` | WS / UDP | `Client::auth()` |
| `cmd=ping` / `cmd=pong` | WS / UDP | `SessionManager` 心跳（主动发 + 应答反向） |
| `cmd=data` + 7 动作 | WS / UDP | `Service/*Api` |
| `cmd=push` | WS / UDP | `PushReceiver` |
| `cmd=ack` | WS / UDP | `PushReceiver` 自动回执 |
| `cmd=error` | WS / UDP | `Client::onError` |
| `POST /push` | HTTP | `AdminApi::push()` |
| `GET /stats` | HTTP | `AdminApi::stats()` |
| `GET /health` | HTTP | `AdminApi::health()` |
| `GET /metrics.json` | Dashboard | 客户端不实现（服务端页面已有） |
| Token 签发 | CLI / `Auth::issue` | `TokenIssuer::issue()` |

---

## 8. 调试器 CLI（`client/bin/gwclient.php`）

**两种模式（均已确认实现）**

- **一次性**：`gwclient.php <cmd> [opts]` —— 建连 → 鉴权 → 执行 → 退出
- **交互（REPL）**：`gwclient.php shell` —— 保持连接，逐条输入命令

REPL 行为约定：

| 项 | 约定 |
|---|---|
| 连接生命周期 | 进 shell 时建连 + 鉴权一次，全程复用；**不随命令断开** |
| 命令语法 | 与一次性模式完全一致（同一个解析器），仅省去 `php gwclient.php` 前缀 |
| 提示符 | `gw[ws:ready] uid=alice> ` —— 实时反映通道与连接状态，避免「以为连着其实断了」 |
| 内置命令 | `help`（命令清单）/ `status`（连接、鉴权、pending、RTT）/ `clear` / `exit`（同 `quit` / Ctrl-D） |
| 断线 | 按 `reconnect` 配置自动重连并重发 `auth`，提示符转 `gw[ws:reconnect]`；重连期间输入的命令进入等待 |
| 异步输出 | 推送（`cmd=push`）与错误回执**不打断输入行**，独立成行输出并保留提示符 |
| 历史 | 不引入 readline 扩展依赖；由终端自身的历史能力承担（Windows 下即 CMD/PowerShell 的行编辑） |

**命令清单**

| 命令 | 说明 |
|---|---|
| `token --uid --device [--ttl]` | 本地签发 Token（调 `Auth::issue`）并打印 |
| `connect [--channel=ws\|udp]` | 建连 + 鉴权，打印结果后退出 |
| `echo --data='{"k":"v"}'` | 回显 |
| `session` | 会话查询 |
| `report --topic --count [--value]` | 数据上报 |
| `subscribe --topic` / `unsubscribe --topic` / `topics` | 订阅关系 |
| `notify [--value] [--msg-id] [--offline]` | 触发对自身推送 |
| `listen [--seconds=N]` | 长驻接收推送（含离线补投），自动回 ack |
| `push --type=uid\|device\|client --target --payload [--msg-id] [--offline]` | HTTP 管理端推送 |
| `stats` / `health` | HTTP 指标 / 健康 |
| `e2e [--uid --device]` | 客户端侧端到端自检（覆盖服务端 A~O 共 15 用例，服务端另有 P） |

**示例**

```bash
php client/bin/gwclient.php token --uid=demo --device=dev01 --ttl=3600
php client/bin/gwclient.php connect --channel=ws
php client/bin/gwclient.php listen --seconds=60
php client/bin/gwclient.php push --type=device --target=dev01 --payload='{"title":"hi"}'
php client/bin/gwclient.php stats
```

---

## 9. 运行与依赖

- **依赖**：复用项目 `vendor/` 中的 `workerman/workerman`（`AsyncTcpConnection` / `AsyncUdpConnection`），无需新增 composer 依赖。
- **入口**：`client/bin/gwclient.php` 自行 `require` 项目 `vendor/autoload.php`。
- **平台**：
  - Windows 下交互式调试建议前台运行；长驻 `listen` 建议放独立窗口。
  - 事件驱动（`Worker::runAll()`）承载，与 `tests/e2e_check.php` 同构。

---

## 10. 里程碑与验收

| 阶段 | 交付 | 验收标准 |
|---|---|---|
| **P0** ✅ | `Protocol/` 三件套 + 单测 | 与服务端 `Message`/`Auth` 输出逐字节一致（含 `canonicalize` 边界：嵌套、键序、中文），**已完成 2026-09-20** |
| **P1** ✅ | `WsTransport` + `SessionManager` | `connect → auth → ack`；`ping → pong`；**应答服务端反向 ping**；`echo` 通 —— **单测 21 例 + 实测 4 项全通过 2026-09-20** |
| **P2** ✅ | 7 个动作 API + `PushReceiver` + 自动 ack | 与 `config/actions.php` 声明一一对应；推送回执对齐 `msg_id` —— **单测 16 例 + 实测 9 项（notify 推送闭环 + 订阅族）全通过 2026-09-20** |
| **P3** ✅ | `UdpTransport` | 合法签名通过；篡改签名 `4001`；**双层 ack 正确判别**；首包重传 —— **已完成 2026-09-21** |
| **P4** ✅ | `AdminApi` | `/health 200`、`/stats 200`、`/push` 验签通过 + 验签失败 `401` —— **已完成 2026-09-21** |
| **P5** ✅ | 重连 + 会话恢复 + 离线补投 | 重连后 `reconnected:1`；补投报文 `offline:1` —— **已完成 2026-09-21** |
| **P6** ✅ | CLI 调试器 + `ClientE2E` | 覆盖服务端 A~O 共 15 用例（服务端另有 P），全部通过 —— **已完成 2026-09-21** |

---

## 11. 风险与边界

| # | 风险 | 应对 |
|---|---|---|
| 1 | 复用服务端类造成耦合 | §2 已给出后续抽包路径；适配层隔离，替换成本低 |
| 2 | UDP 首包静默丢失 | 延迟首包 + 应用层重传（复用 e2e 已有做法） |
| 3 | **15s 鉴权窗口** | 调试器与 SDK 建连后自动抢先发 `auth` |
| 4 | 同 uid+device 重复连接互踢 | 调试时用不同 `device_id`；或先清 `auth:bind:{uid}` |
| 5 | 服务端反向心跳未应答会被判死 | `SessionManager` 默认开启应答 |
| 6 | 限流配额（conn 20/s、uid 50/s、ping 5/s） | 调试器命令间留间隔；超限回 `4008` 且不断连 |
| 7 | `msg_id` 幂等去重（600s） | 调试重复推送时换 `msg_id` |
| 8 | UDP 动作错误静默 | 排障必须对照服务端 `runtime/logs/`，不能只看客户端超时 |

---

## 12. 确认结论与 P0 交付清单

**三项待确认均已裁定（2026-09-20）**：

1. 进入 **P0** —— 先交付 `Protocol/` 层 + 单测；
2. 交付位置为**本仓库 `client/`**；
3. 调试器**两种模式都实现**（REPL 交互 + 一次性命令）。

### P0 实际交付

| 文件 | 内容 |
|---|---|
| `client/src/Protocol/Codec.php` | 编解码 / 报文构造 / `data` 业务信封 / UDP 双层 ack 判别 |
| `client/src/Protocol/Signer.php` | `sign` / `canonicalize` / `baseString`（排障）/ 本地验签 |
| `client/src/Protocol/TokenIssuer.php` | Token 签发 / `inspect` / `claims` / `peek`（不验签） |
| `client/src/Error/ErrorCode.php` | 报文码 + HTTP 业务码 + 客户端本地码（10001+） |
| `client/src/Error/ClientException.php` | 统一异常（报文 / 超时 / 传输 / 配置 / 状态 / 内部） |
| `client/tests/Unit/*.php` | 4 个测试类，87 用例 / 247 断言（P0 时点；现 12 个测试类，以 `composer test` 为准） |
| `client/README.md` | 客户端使用说明（含协议要点速查） |

改动的基础设施（非新增文件）：

- `composer.json`：新增 PSR-4 前缀 `GatewayPush\Client\` → `client/src/`，
  `GatewayPush\Client\Tests\` → `client/tests/`（最长前缀优先，与 `GatewayPush\` 无冲突）；
- `phpunit.xml`：`unit` 测试套件增加 `client/tests/Unit`，覆盖率范围增加 `client/src`；
- `phpstan.neon`：`paths` 增加 `client/src`，新代码同样零容忍。

> 与设计稿的两处偏离（均为落地时的必要修正）：
> ① 增加 `Error/` 层（`TokenIssuer` 需要自有异常类型，且错误码分层是 P1 的前置）；
> ② `TokenIssuer` 在**每次调用前**把配置写回静态类 `Auth` ——
> 否则「后建实例改了密钥、先建实例跟着用错密钥」会静默发生。

### P1 实际交付（2026-09-20）

| 文件 | 内容 |
|---|---|
| `client/src/Transport/TransportInterface.php` | 统一传输接口（**新增 `onOpen`**，见下） |
| `client/src/Transport/WsTransport.php` | `AsyncTcpConnection` 薄封装：回调归一化 + `isConnected` + 断开后丢弃连接实例（不可复用，重连须新建） |
| `client/src/Session/SessionManager.php` | 状态机 / seq / pending 表 / 主动心跳 + **自动应答服务端反向 ping** / 指数退避重连（重连后 auto_auth 重鉴权）/ 下行分发 |
| `client/src/Session/PendingRequest.php` | 请求上下文 |
| `client/tests/Unit/SessionManagerTest.php` | 21 用例：假传输层 + 假计时器（`Timer::add` 在非 workerman 环境抛异常，计时器经构造参数注入） |

**实测验收**（register/gateway/business 三角色 + Redis）：
`auth` → ready；`ping` → pong（RTT 8.2ms）；`data.echo` → ack；
**40s 存活观察**（覆盖 ≥3 个网关反向心跳周期 12.5s/次）连接不断且 echo 可用 —— 反向 ping 被正确应答。

与设计稿/草案的偏离（均为必要修正）：

1. `TransportInterface` 增加 `onOpen()` —— 15s 鉴权窗口要求「握手完成的瞬间」触发 auth，
   无法从 onMessage/onClose 推导该时机；
2. `PendingRequest` 的 `onReply`/`onTimeout` 合并为 `onReply(bool $ok, array $packet)` ——
   超时也是一次结算（ok=false、packet 空），单一出口避免两条回调的触发次序歧义；
3. 结算回调收到**完整回执报文**（业务载荷在 `$packet['data']`）—— 保留 seq/msg_id 等
   元信息供上层（PushReceiver、P2 Service API）使用；
4. `close()` 会取消挂起的重连定时器（重连等待期没有活动连接，onClose 不会再触发，
   须直接落 disconnected）—— 该点由 PHPStan `property.onlyWritten` 告警发现。

### P2 实际交付（2026-09-20）

| 文件 | 内容 |
|---|---|
| `client/src/Service/AbstractApi.php` | 回调包装基类：统一三元组 `($ok, $data, ?$error)`；服务端 error 报文取 code/msg，本地超时 code=CLIENT_TIMEOUT(10001) |
| `client/src/Service/EchoApi.php` | echo（`params='*'` 原样透传） |
| `client/src/Service/SessionApi.php` | session（会话摘要） |
| `client/src/Service/ReportApi.php` | report（topic/count/value；UDP 侧静默由文档声明，客户端不做通道判断） |
| `client/src/Service/SubscribeApi.php` | subscribe / unsubscribe / topics |
| `client/src/Service/NotifyApi.php` | notify（value / msg_id / offline_mode；目标恒为自身 uid） |
| `client/src/Event/PushReceiver.php` | push 解析为 payload+meta → 业务回调 → **自动回 ack**（`data.msg_id` 对齐 `seq`）；`offline=1` 标记补投 |
| `client/src/Session/SessionManager.php`（增强） | ① 新增 `sendAck()`（未 ready 静默跳过）；② **签名统一移至 `sendPacket()`** —— 所有上行报文一致带 sign，P3 UDP 直接复用 |
| `client/tests/Unit/PushReceiverTest.php` | 6 用例（解析 / 自动 ack / msg_id 回退 seq / offline 标记 / 无回调仍回执 / 未 ready 不发） |
| `client/tests/Unit/ServiceApiTest.php` | 10 用例（6 API 报文构造 + 服务端错误呈现 + 本地超时呈现 + 状态守卫） |
| `client/tests/Support/` | FakeTransport / FakeTimers 抽为共享基建（phpunit.xml 以 `<file>` 显式加载） |

**实测验收**（register/gateway/business + Redis）：
`echo` 回显一致 → `subscribe` 成功 → `notify`（自带 msg_id）→ 收 push（payload 解析正确、
msg_id 与请求对齐、offline=0 实时）→ **自动回执 acked=1** → `topics` 含已订主题 → `unsubscribe` 收尾。
全链路 9 项全通过。

与设计稿的偏离：

1. `AbstractApi::call` 失败时 `$error = ['code' => int, 'msg' => string]`（数组而非异常对象）——
   回调三元组不可序列化携带异常栈，数组足以覆盖「服务端错误码 + 本地超时码」两类语义；
2. 签名计算从 auth/request 各自内联收敛到 `SessionManager::sendPacket()` 统一出口
   （P1 遗留的「部分报文带 sign 不一致」问题顺手修复）。

### P3 实际交付（2026-09-21）

| 文件 | 内容 |
|---|---|
| `client/src/Transport/UdpTransport.php` | `AsyncUdpConnection` 封装：延迟首包（默认 0.2s）+ 应用层重传（1.2s × 4）+ 双层 ack 判别 |
| `client/src/Session/SessionManager.php`（增强） | UDP 通道适配：token 随包携带、传输层 ack 不结算业务 pending |
| `client/tests/Unit/UdpTransportTest.php` | 首包延迟、重传、放弃计数、双层 ack 判别 |
| `client/tests/Support/FakeUdpConnection.php` | 假 UDP 连接（可注入、可回放） |

**实测验收**（register/udp/business + Redis）：`auth` → `report`（静默）→ `echo` 回执一致 →
`notify` 触发自身推送（UDP 出站队列）→ 自动回执；篡改签名回 `4001`；重传在 inflight 计数上可见。

> 关键取舍：UDP 的「传输层 ack」由网关在入队成功时回，`Codec::isTransportAck()` 判别后
> 不结算业务 pending —— 否则 `report` 这类**按声明不回执**的动作会被误判为已应答。

### P4 实际交付（2026-09-21）

| 文件 | 内容 |
|---|---|
| `client/src/Transport/HttpTransport.php` | 一次性 TCP 请求：手工构造 HTTP/1.1 报文 + 响应解析（不复用 `Http::encode`，它是服务端响应语义） |
| `client/src/Service/AdminApi.php` | `/push` `/stats` `/health`，验签 `hex(hmac_sha256("{ts}|{rawBody}", secret))` |
| `client/tests/Unit/HttpTransportTest.php`、`AdminApiTest.php` | 报文构造、响应解析、验签头、错误映射 |
| `client/tests/Support/FakeHttpConnection.php` | 假 HTTP 连接 |

**实测验收**（api 角色在线）：`/health` 200、`/stats` 返回指标快照、`/push` 验签通过可推送、
错误密钥返回 `401`（业务码 `4001 签名校验失败`）。

### P5 实际交付（2026-09-21）

| 文件 | 内容 |
|---|---|
| `client/src/Transport/WsTransport.php`（修复） | **建连失败补发 close 信号** —— workerman 客户端建连失败只触发 `onError` 不触发 `onClose`，缺失该补丁时状态机会卡在 `connecting` |
| `client/tests/Unit/WsTransportTest.php` | 覆盖建连失败 / 被动断线 / 重连新建连接 |
| `client/tests/Support/FakeTcpConnection.php` | 假 TCP 连接（含 `failOnConnect` 开关） |

**实测验收**（停网关 → 离线投递 → 起网关）：`ready → reconnecting` 检出 → 退避重试（期间
网关不可达，重试失败继续退避）→ 重连成功自动重鉴权 `reconnected=1` → 补投 `offline=1` →
自动回执 `acked=1`。设备绑定首胜：`4004 设备不匹配` → 清 `gwpush:auth:bind:{uid}` 后换设备成功。

### P6 实际交付（2026-09-21）

| 文件 | 内容 |
|---|---|
| `client/src/Cli/CommandParser.php` | 纯函数参数解析（`--k=v` / `--k v` / 开关 / `--` 字面量） |
| `client/src/Cli/Debugger.php` | 一次性命令 / REPL / listen 三种模式；异步输出不打断输入行 |
| `client/bin/gwclient.php` | 入口：默认参数取自 `config/app.php`、`config/gateway.php` |
| `client/tests/Unit/CliParserTest.php` | 7 用例 |
| `client/tests/E2E/ClientE2E.php` | 覆盖服务端 `tests/e2e_check.php` 的 A~O 共 15 用例（服务端另有 P：HTTP `/action`，客户端 e2e 未纳入） |
| `composer.json` | 新增 `composer test:client-e2e` |

**实测验收**：一次性命令（ping/echo/session/notify/health/stats/push + `--bad-sign`
返回 401）全通；REPL 提示符随状态迁移、推送重绘提示符、quit 正常退出；
`ClientE2E` 连续两轮 15/15 全绿。

与设计稿的偏离：

1. 命令清单以**服务端已声明的动作**为准，未实现 `token` / `connect` 子命令（前者由
   `TokenIssuer` 直接在代码中使用，后者由 `connect()` 自动完成，无需独立子命令）；
2. Windows 控制台不支持对 stdin 做 `stream_select`，REPL 输入降级为阻塞读（推送在
   下一次回车时渲染），已在 `client/README.md` 标注；
3. 客户端 e2e 中 B/D 两例走裸传输层、K 改为「建会话前入队」、O 以订阅关系闭环对齐 ——
   HTTP `/push` 不支持主题广播目标，主题广播属服务端内部能力。
