# GatewayPush 对外接口文档

> **定位**：本文件是**面向调用方**的字段级接口契约，回答「连哪里、带什么、回什么、错了怎么办」。
> `README.md` 是**使用者手册**（部署、配置、原理、运维），两者的分工不同：
>
> | 需要什么 | 看哪里 |
> |---|---|
> | 接口地址、请求/响应字段、约束与限额、错误码 | **本文件** |
> | 为什么这样设计、数据在进程间怎么流转、Redis 键结构、排障 | `README.md` 第 8 / 9 / 10 / 14 章 |
> | 客户端 SDK 用法（PHP 侧） | `client/README.md` |
>
> **权威顺序**：代码 > 本文件 > README。三者不一致时以代码为准，并须同时修正另外两处。

---

## 目录

1. [接入点总览](#1-接入点总览)
2. [认证体系](#2-认证体系)
3. [公共响应结构与错误码](#3-公共响应结构与错误码)
4. [WebSocket 接口](#4-websocket-接口)
5. [UDP 接口](#5-udp-接口)
6. [HTTP 接口](#6-http-接口)
7. [监控面板接口](#7-监控面板接口)
8. [业务动作契约](#8-业务动作契约)
9. [限额与约束汇总](#9-限额与约束汇总)
10. [联调自检工具](#10-联调自检工具)

---

## 1. 接入点总览

服务由 6 个角色进程组成，**其中只有 3 个直接对外**：

| 角色 | 进程名 | 默认监听 | 协议 | 对外 | 用途 |
|---|---|---|---|---|---|
| `register` | `Register` | `text://127.0.0.1:1238` | text | ❌ 内部 | 注册中心，进程间地址发现 |
| `gateway` | `GW-WS` | `websocket://0.0.0.0:8282` | **WS** | ✅ | 客户端长连接入口 |
| `udp` | `GW-UDP` | `udp://0.0.0.0:8283` | **UDP** | ✅ | 客户端数据报入口 |
| `business` | `GW-Business` | 无监听 | — | ❌ 内部 | 业务动作执行（反向连网关） |
| `api` | `GW-API` | `http://127.0.0.1:8290` | **HTTP** | ✅ | 服务端集成接口（推送 / 动作） |
| `dashboard` | `GW-DASH` | `http://127.0.0.1:8291` | HTTP | ⚠️ 运维 | 只读监控面板 |

对外三条通道的**职责划分**：

```
客户端（长连接 / 数据报）  ──WS / UDP──▶  业务动作（7 个，见第 8 节）
服务端系统（cron / 后台）  ──HTTP──────▶  定向推送 + 业务动作（其中 6 个开放）
```

> `dashboard` 默认仅监听回环地址，属运维内部接口，不应直接暴露到公网。
> 需要远程访问时经 Nginx 反代（附加 Basic Auth）或 SSH 隧道。

**端口与地址均由 `.env` 控制**（`WS_LISTEN` / `UDP_LISTEN` / `API_LISTEN` / `DASHBOARD_LISTEN`），
上方为默认值。生产部署实际值以 `php start.php info` 输出为准。

---

## 2. 认证体系

**三套凭证互不通用**，这是接入时最常见的困惑点：

| 通道 | 凭证 | 载体 | 算法 | 作用域 |
|---|---|---|---|---|
| WS | **Token** | 报文 `token` 字段（`cmd=auth` 时提交） | `Auth::verifyLocal()` | 连接级，鉴权后进程内映射 |
| UDP | **Token** | 报文 `token` 字段（**每包携带**） | 同上 | **报文级**，无连接状态 |
| HTTP | **API Secret** | 请求头 `X-Timestamp` / `X-Sign` | HMAC-SHA256 | 每请求 |

> **`AUTH_ENABLE` / `AUTH_SIGN_ENABLE`（`auth` 段）只作用于 WS / UDP 报文层**，与 HTTP 验签无关。
> HTTP 验签读独立的 `api` 段。唯一关联：`API_SECRET` 留空时回退复用 `AUTH_SECRET` —— **只共用密钥，不共用开关**。
> 这是「关了 `AUTH_*` 却仍提示缺少 `X-Timestamp`」的根因。

### 2.1 Token 结构（WS / UDP 共用）

自包含、无状态：

```
token   = base64url(payload) . '.' . base64url(hmac_sha256(base64url(payload), AUTH_SECRET))
payload = {"uid":"1001","device_id":"dev-001","iat":1690000000,"exp":1690007200,"nonce":"..."}
```

| 字段 | 类型 | 说明 |
|---|---|---|
| `uid` | string | 用户 ID。**UDP 侧唯一可信的身份来源** |
| `device_id` | string | 设备 ID，参与 `uid ↔ device_id` 绑定校验 |
| `iat` | int | 签发时间（秒） |
| `exp` | int | 过期时间（秒）。已过 → `4005` |
| `nonce` | string | 随机串，防重放 |

校验分三段：

| 阶段 | 方法 | IO | 内容 |
|---|---|---|---|
| 1 | `Auth::verifyLocal()` | 无 | 签名（`hash_equals` 防时序攻击）+ `exp` + `iat` 合理性 |
| 2 | `Auth::isRevoked()` | Redis | 撤销名单 `auth:revoked:{fingerprint}` |
| 3 | `Auth::checkDeviceBind()` | Redis | `uid ↔ device_id` 绑定（首次绑定者胜出） |

> 撤销名单的键是 **Token 的 SHA-256 前 32 位指纹**，明文 Token 不落盘。

**生成调试 Token**：

```bash
php start.php token <uid> [device_id] [ttl]
```

### 2.2 报文签名（WS / UDP）

```
sign = hmac_sha256("cmd|seq|ts|device_id|token|canonicalize(data)", AUTH_SECRET)
```

- `canonicalize(data)` = **递归按键名升序** + 紧凑 JSON（无多余空格）
- **`uid` 不参与签名** —— 故 WS/UDP 报文里的 `uid` 不可信，身份一律取自 Token 载荷
- `AUTH_SIGN_ENABLE=false` 时跳过 `sign` 校验；`AUTH_CLOCK_SKEW<=0` 时跳过 `ts` 时效校验
- 金标测试向量固化在 `tests/Unit/SignerTest.php`

### 2.3 HTTP 请求签名

```
X-Sign = hex(hmac_sha256("{X-Timestamp}|{原始请求体}", api.secret))
```

| 项 | 值 |
|---|---|
| 头名 | `X-Timestamp`（秒级时间戳）、`X-Sign`（小写 hex） |
| 参与签名的 body | **原始请求体字节**，不是解析后的数组（避免键序 / 转义差异） |
| `GET` 请求 | 无请求体 → 按**空串**参与：`hmac_sha256("{ts}|", secret)` |
| 时间窗 | `|now - ts| > API_SIGN_TTL`(300s) → `401` / `4002` |
| 密钥来源 | `API_SECRET`；**留空时回退 `AUTH_SECRET`** |

`API_SIGN_ENABLE=false` 可免签，**但仅在 `API_LISTEN` 绑定回环地址时生效**；
绑 `0.0.0.0` / 具体网卡 / 域名时该开关被忽略并强制验签（护栏，避免业务入口裸奔）。
被护栏拦下时 `php start.php check` 与 api 启动日志都会告警。

---

## 3. 公共响应结构与错误码

### 3.1 HTTP 响应信封

**所有 HTTP 接口**（含面板）返回统一信封：

```json
{
  "code": 0,
  "msg": "ok",
  "ts": 1789983030,
  "data": { }
}
```

| 字段 | 类型 | 说明 |
|---|---|---|
| `code` | int | 业务码，`0` = 成功。**判成败必须看此字段**，不能只看 HTTP 状态码 |
| `msg` | string | 文案，可直接展示 |
| `ts` | int | 服务端响应时间（秒） |
| `data` | object | 业务数据体。失败时为 `null`（该键被省略） |

### 3.2 WS / UDP 报文结构

八字段统一报文：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `cmd` | string | ✅ | 指令名：上行 `auth` / `ping` / `data` / `ack` / `pong`；下行另有 `pong` / `push` / `error` |
| `seq` | string | — | 客户端消息序号，回执原样带回，用于关联请求 |
| `ts` | int | — | 客户端时间戳（秒），偏差 > `AUTH_CLOCK_SKEW` → `4002` |
| `uid` | string | — | 用户 ID。**不参与签名**，UDP 侧不可信 |
| `device_id` | string | — | 设备 ID，**参与签名** |
| `token` | string | — | 鉴权 Token，**参与签名**，是唯一可信身份来源 |
| `sign` | string | — | 报文签名，算法见 2.2 |
| `data` | object | — | 业务数据体。`cmd=data` 时形如 `{"action":"<动作名>","params":{...}}` |

### 3.3 错误码总表

业务码（WS / UDP 用于报文 `data.code`，HTTP 用于响应体顶层 `code`）：

| 码 | 常量 | 文案 | 触发场景 |
|---|---|---|---|
| `0` | `CODE_OK` | ok | 成功 |
| `4000` | `CODE_BAD_PACKET` | 报文格式错误 | 空报文 / 非 JSON / 缺 `cmd` / 长度超限 / 参数类型错 |
| `4001` | `CODE_BAD_SIGN` | 签名校验失败 | 签名不匹配 / 缺 `sign` / 服务端未配密钥 |
| `4002` | `CODE_BAD_TIMESTAMP` | 时间戳偏差超出允许范围 | 时钟偏差 > 允许窗口 |
| `4003` | `CODE_UNAUTHORIZED` | 连接未鉴权 | 未鉴权就发业务指令 / 动作要求身份但未提供 |
| `4004` | `CODE_AUTH_FAILED` / `CODE_NOT_FOUND` | 鉴权失败 / 结果不存在 | WS/UDP：Token 非法、被撤销、设备不匹配；HTTP：`GET /action/{id}` 未就绪或已过期 |
| `4005` | `CODE_TOKEN_EXPIRED` | Token 已过期 | `exp` 已过 |
| `4006` | `CODE_UNKNOWN_CMD` | 未知指令 | 指令或 `data.action` 未注册 / 动作未开放该通道 |
| `4007` | `CODE_PARAM_MISSING` | 缺少必要参数 | 参数校验失败（缺失 / 越界） |
| `4008` | `CODE_RATE_LIMIT` | 请求频率超限 | L2 限流拒绝（WS 回错误包且不断开；UDP 静默丢弃） |
| `4029` | — | 请求频率超限 | HTTP 专属：单 IP 超 `API_RATE_LIMIT` |
| `5000` | `CODE_SERVER_ERROR` | 服务端内部错误 | 处理器抛异常 / 动作回执超时 / 入队失败 |
| `5030` | — | 队列积压 | HTTP 专属：`queue:action:in` 长度 ≥ `ACTION_QUEUE_MAX_LEN` |

### 3.4 同一错误在三条通道的呈现差异

**这是排障时最关键的一张表** —— 同一个错误码在不同通道的表现完全不同：

| 场景 | WS | UDP | HTTP |
|---|---|---|---|
| 签名失败 | 下发 `error` 报文 `4001` | **静默丢弃**（无任何回包） | `401` + `code=4001` |
| 限流超限 | 下发 `error` 报文 `4008`，**不断开** | **静默丢弃** | `429` + `code=4029` |
| 参数错误 | 下发 `error` 报文 `4007` | 视动作 `reply` 声明，通常静默 | `200` + `code=4007`（执行后失败） |
| 动作超时 | `error` `5000` | 静默 | `202` + `data.status=pending` |
| 未注册动作 | `error` `4006` | 静默 | `400` + `code=4006`（入队前拒绝） |

> **UDP 的错误静默是设计选择而非缺陷** —— 数据报通道无重传语义，回错误包会放大流量。
> 排查 UDP 问题看 `runtime/logs/gateway_{date}.log` 与 `business_{date}.log`。

---

## 4. WebSocket 接口

### 4.1 连接

| 项 | 值 |
|---|---|
| 地址 | `ws://<host>:8282`（`WS_LISTEN`，默认 `websocket://0.0.0.0:8282`） |
| 路径 | 不校验，任意 path 均可 |
| TLS | 由 `WS_SSL_ENABLE` 控制，开启后为 `wss://`（证书配置见 README 7 章） |
| 建连后鉴权时限 | `AUTH_TIMEOUT`(15s) 内未完成 `auth` → 断开 |
| 鉴权前白名单 | 仅允许 `auth` / `ping`，其他指令 → `4003` |

### 4.2 指令清单

| `cmd` | 方向 | 需鉴权 | 说明 | 回执 |
|---|---|---|---|---|
| `auth` | 上行 | ❌ | 提交 Token | `ack`，`data` 含 `uid` / `device_id` / `protocol` / `reconnected` |
| `ping` | 上行 | ❌ | 应用层心跳 | `pong`（回带 `seq`） |
| `data` | 上行 | ✅ | 业务动作入口 | `ack` 或 `error`（由动作 `reply` 声明决定） |
| `ack` | 上行 | ✅ | 确认下行 `push` | 无（仅记 `push_ack` 指标） |
| `pong` | 上行 | ❌ | 响应服务端原生心跳 | 无 |
| `pong` | 下行 | — | 对客户端 `ping` 的响应 | — |
| `push` | 下行 | — | 定向推送的业务报文 | 客户端应回 `ack` |
| `error` | 下行 | — | 错误报文，含 `data.code` / `data.msg` / `ref` | — |

### 4.3 交互示例

```jsonc
// ① 客户端 → 服务端：鉴权
{"cmd":"auth","seq":"1","ts":1789983030,"device_id":"dev-001",
 "token":"<token>","sign":"<sign>","data":{}}

// ② 服务端 → 客户端：鉴权回执
{"cmd":"ack","seq":"1","data":{"uid":"1001","device_id":"dev-001",
 "protocol":"ws","reconnected":false}}

// ③ 客户端 → 服务端：调用业务动作
{"cmd":"data","seq":"2","ts":1789983030,"uid":"1001","device_id":"dev-001",
 "token":"<token>","sign":"<sign>",
 "data":{"action":"subscribe","params":{"topic":"news.sports"}}}

// ④ 服务端 → 客户端：动作回执
{"cmd":"ack","seq":"2","data":{"action":"subscribe","uid":"1001",
 "topic":"news.sports","subscribers":3,"at":1789983031}}

// ⑤ 服务端 → 客户端：定向推送（客户端须回 ack）
{"cmd":"push","seq":"m-1","ts":1789983040,
 "msg_id":"m-1","source":"http","offline":0,"pushed_at":1789983040,
 "data":{"title":"hi"}}          // ← data 即调用方提交的 payload 原样
```

**下行 `push` 报文扩展字段**（在八字段基础上追加）：

| 字段 | 类型 | 说明 |
|---|---|---|
| `seq` | string | 取 `msg_id`；未提供时服务端生成 `p-<16位hex>` |
| `msg_id` | string | 原样的消息标识 |
| `source` | string | 来源标记：`http` / `ws` / `udp` / 内部调用方 |
| `offline` | int | `1` = 重连补投的离线消息，`0` = 实时投递 |
| `pushed_at` | int | 投递时间戳（秒） |
| `data` | object | 调用方提交的 `payload` **原样**，服务端不包裹 |

> 客户端回 `ack` 时把 `seq` 原样带回即可，服务端据此计 `push_ack` 指标。

**心跳约定**：服务端每 `HB_PING_INTERVAL`(25s) 主动 ping，连续 `HB_PING_NOT_RESPONSE_LIMIT`(2) 次
无响应即判定死连接；业务层心跳超时阈值 `HB_SESSION_TIMEOUT`(90s)。

---

## 5. UDP 接口

### 5.1 接入

| 项 | 值 |
|---|---|
| 地址 | `<host>:8283`（`UDP_LISTEN`，默认 `udp://0.0.0.0:8283`） |
| 单包上限 | `UDP_MAX_PACKET_SIZE`(8192 字节) |
| 连接标识 | 服务端按来源地址生成 `clientId = "udp:{ip}:{port}"` |
| 报文结构 | 与 WS 完全相同的八字段（第 3.2 节） |
| 身份来源 | **报文内 Token 载荷的 `uid`**（无连接级鉴权闸门） |

### 5.2 与 WS 的语义差异

| 维度 | WS | UDP |
|---|---|---|
| 连接状态 | 有（鉴权映射常驻进程内） | **无**，每包独立 |
| 鉴权 | `auth` 一次性握手 | **每包携带 Token 并验签** |
| 首包 | 建连即发 | **必须延迟发送** —— 立即发首包会丢（见 5.3） |
| 回执路径 | 直回客户端 | 经 `queue:udp:out` → 网关 `sendto` |
| 错误 | 下发 `error` 报文 | **静默丢弃**（除传输层 ack） |
| 限流 | L1 内存桶（验签前） + L2 Redis 桶 | 同左 |
| 适合场景 | 需实时双向、需推送 | 高频小数据上报（如 GPS 点位） |

### 5.3 传输层约定（客户端必须实现）

```
① 首包延迟：建连后不要立即发送，先延迟一小段（服务端需先完成地址学习）
② 重传：未在预期时间内收到 ack 则重传，退避递增
③ 双层 ack：
     传输层 ack —— 网关对数据报的确认（重传依据）
     业务层 ack —— 动作处理结果（cmd=ack，经出站队列 sendto）
   ⚠ 两者存在竞态，不可互相替代（硬约束 ⑯）
```

> 客户端侧已有参考实现：`client/src/Transport/UdpTransport.php`（延迟首包 + 重传 + 双层 ack）。

### 5.4 典型链路

```
客户端 ──UDP 数据报──▶ 网关 8283
                        ├─ L1 内存桶限流（验签前，零 IO）
                        ├─ 报文解码 + 验签（失败静默丢弃）
                        └─ RPUSH queue:udp:in
                                │
                        business 进程消费 ──▶ ActionRunner::run(channel=udp)
                                │
                        ┌───────┴────────┐
                   业务回执            错误
                      │                  │
                RPUSH queue:udp:out   （静默，仅落日志）
                      │
                网关定时取批 ──sendto──▶ 客户端
```

---

## 6. HTTP 接口

Api 进程对外提供 **5 个接口**，默认 `http://127.0.0.1:8290`。

### 6.1 公共前置（除 `/health` 外全部适用）

```
① 限流：单 IP 滑动分钟窗口（API_RATE_LIMIT=600/min）
       进程内静态计数快速拒绝 + Redis INCR 跨进程计数
       超出 → 429 / code=4029
② 读请求头 X-Timestamp / X-Sign     缺失 → 401 / code=4001
③ 时间窗校验 |now - ts| > API_SIGN_TTL(300)  → 401 / code=4002
④ 验签 hmac_sha256("{X-Timestamp}|{原始请求体}", api.secret)
       hash_equals 比对              失败 → 401 / code=4001
⑤ 请求体上限 API_BODY_MAX(65536 字节)
```

> `API_SIGN_ENABLE=false` 时跳过 ②③④（**仅回环监听生效**）。

### 6.2 接口清单

| 接口 | 方法 | 鉴权 | 语义 | 说明 |
|---|---|---|---|---|
| `/health` | GET | ❌ 免 | — | 存活探测，供 LB / 容器探针 |
| `/stats` | GET | ✅ | 同步 | 指标快照（`gauge` / `counter` / `task`） |
| `/push` | POST | ✅ | **异步受理** | 提交定向推送任务，入队即返回 |
| `/action` | POST | ✅ | **同步等待** | 调用业务动作，等待执行结果 |
| `/action/{id}` | GET | ✅ | 同步 | 按 `request_id` 补查动作回执 |

---

### 6.3 `GET /health` —— 存活探测

**请求**：无参数、无请求体、**免鉴权**。

**响应** `200`：

```json
{"code":0,"msg":"ok","ts":1789983030,
 "data":{"service":"gateway-push-api","time":1789983030}}
```

| `data` 字段 | 类型 | 说明 |
|---|---|---|
| `service` | string | 固定 `gateway-push-api` |
| `time` | int | 服务端时间戳（秒） |

---

### 6.4 `GET /stats` —— 指标快照

**请求**：无参数、无请求体。签名基于空串计算：`hmac_sha256("{ts}|", secret)`。

**响应** `200`：

```json
{"code":0,"msg":"ok","ts":1789983030,
 "data":{
   "gauge":   {"report_at":"1789983030","memory_bytes:12345":"41943040","..."},
   "counter": {"msg_in":"102400","push_out":"512","..."},
   "task":    {"monitor-report":{"interval":60,"count":30,"skip":0,"fail":0,"last_cost":0.0012,"running":false}}
 }}
```

| `data` 段 | 类型 | 含义 |
|---|---|---|
| `gauge` | object | 覆盖型指标（Redis Hash `metrics:gauge`）。字段名规则见下表 |
| `counter` | object | 累加型指标（Redis Hash `metrics:counter:{YYYYMMDD}`），field 为指标名，value 为当日累加值 |
| `task` | object | **当前 api 进程**的定时任务健康度（`Task::stats()`），非全局 |

**`gauge` 的 field 命名规则**：

| field | 含义 |
|---|---|
| `report_at` | 最近一次指标上报时间戳（全局新鲜度判据） |
| `conn_total` / `conn_ws` / `conn_udp` | 在线连接数（仅 worker 0 写，走 `online:clients` 集合） |
| `pid_at:{pid}` | 该进程最近上报时间戳 —— **进程存活判据** |
| `proc:{pid}` | `{"role":"gateway","worker_id":0}` |
| `tasks:{pid}` | `{"worker_id":0,"jobs":{...}}` |
| `memory_bytes:{pid}` | 该进程内存占用（字节） |

> `pid_at` 的两个阈值**不同且不可互换**：面板展示用 `interval×2`(10s) 判「已退出」，
> 采集侧清理用 `MONITOR_TTL`(600s)。详见 README 9.10。

**常见错误**：`401`/`4001`（缺头或验签失败）、`401`/`4002`（时间窗）、`429`/`4029`（限流）。

> 因 `/stats` 需验签而浏览器无法安全持有密钥，故另设**免鉴权**的面板端点 `/metrics.json`（第 7 节）。

---

### 6.5 `POST /push` —— 定向推送（异步受理）

**请求体**：

| 字段 | 类型 | 必填 | 约束 | 说明 |
|---|---|---|---|---|
| `target_type` | string | ✅ | `uid` / `device` / `client` | 目标类型 |
| `target` | string | ✅ | 非空（`trim` 后） | 目标值。`client` 类型填 `clientId`（如 `udp:{ip}:{port}`），仅调试用 |
| `payload` | object \| array | — | ≤ `PUSH_PAYLOAD_MAX`(4096 字节) | 业务数据体，缺省为 `[]` |
| `msg_id` | string | — | 建议唯一 | 参与幂等去重（`PUSH_IDEMPOTENT`，窗口 `PUSH_IDEMPOTENT_TTL`=600s）。重复发送不二次投递 |
| `offline_mode` | string | — | `""` / `drop` / `queue` | 离线策略。`""` 表示取服务端默认值 `PUSH_OFFLINE_MODE`(默认 `queue`) |

`target_type` 语义：

| 值 | 含义 | 展开为 |
|---|---|---|
| `uid` | 按用户推送 | 该 uid 名下**全部在线连接** |
| `device` | 按设备推送 | 单对一核心场景，`device:client:{device_id}` 索引命中 1 条连接 |
| `client` | 按连接推送 | 直接指定 clientId，调试用 |

`offline_mode` 语义：`drop` = 目标不在线直接丢弃（仅计指标）；`queue` = 写入 `push:offline:{uid}` 列表，设备重连后补投（上限 `PUSH_OFFLINE_MAX`=100 条 / 保留 `PUSH_OFFLINE_TTL`=86400s）。

**请求示例**：

```bash
TS=$(date +%s)
BODY='{"target_type":"device","target":"dev-001","payload":{"title":"hi"},"msg_id":"m-1","offline_mode":"queue"}'
SIGN=$(printf '%s|%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$API_SECRET" -r | cut -d' ' -f1)

curl -s -X POST http://127.0.0.1:8290/push \
  -H "Content-Type: application/json" \
  -H "X-Timestamp: $TS" \
  -H "X-Sign: $SIGN" \
  -d "$BODY"
```

**响应** `200`（**仅表示已入队**，真实投递由业务进程异步完成）：

```json
{"code":0,"msg":"accepted","ts":1789983030,
 "data":{"target_type":"device","target":"dev-001","msg_id":"m-1","offline_mode":"queue"}}
```

| `data` 字段 | 类型 | 说明 |
|---|---|---|
| `target_type` | string | 规范化（小写去空格）后的目标类型 |
| `target` | string | 去空格后的目标值 |
| `msg_id` | string | 原样回带，便于调用方关联 |
| `offline_mode` | string | **实际生效值**（未传时为服务端默认值） |

**错误**：

| HTTP | code | 触发 |
|---|---|---|
| `400` | `4000` | 请求体非合法 JSON 对象 / `target_type` 非法 / `target` 为空 / `payload` 非对象或数组 |
| `401` | `4001` | 缺少 `X-Timestamp` 或 `X-Sign` / 签名不匹配 |
| `401` | `4002` | 时间戳超出允许窗口 |
| `429` | `4029` | 单 IP 请求频率超限 |
| `500` | `5000` | 推送任务入队失败（Redis 故障） |

> 队列积压时 `/push` **仅告警，继续入队** —— 与 `/action` 的「直接拒绝」不同，因推送无同步等待者。

---

### 6.6 `POST /action` —— 调用业务动作（同步等待）

**请求体**：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `action` | string | ✅ | 动作名。必须在 `config/actions.php` 注册**且** `http=true` |
| `uid` | string | 条件必填 | 动作声明 `auth=true` 时必填，否则 `401` / `4003` |
| `device_id` | string | — | 设备 ID，随动作上下文传入 |
| `params` | object | — | 动作参数，缺省 `{}`。各动作 schema 见第 8 节 |

> `uid` 由请求方给出，但它参与 HMAC 签名覆盖 —— 即「**密钥持有者可以代表任意 uid 发起动作**」，
> 与 `/push` 同权，不构成提权。该能力是显式授权的。

**三种响应形态**：

| 形态 | HTTP | `code` | `data` |
|---|---|---|---|
| 执行成功 | `200` | `0` | `{request_id, status:"done", result:{...}, packet:{...}}` |
| 执行后业务失败 | **`200`** | **业务码**（如 `4007`） | `{request_id, status:"failed", packet:{...}}` |
| 超窗未完成 | `202` | `0` | `{request_id, action, status:"pending", result_url:"/action/{id}"}` |

| `data` 字段 | 类型 | 说明 |
|---|---|---|
| `request_id` | string | 16 位 hex（`bin2hex(random_bytes(8))`），补查凭据 |
| `status` | string | `done` / `failed` / `pending` |
| `result` | object | **仅成功时存在**，动作处理器返回的数据体（各动作结构见第 8 节） |
| `packet` | object | 原始动作回执报文（`cmd` / `data` / ...），调试用 |

> ⚠️ **判断成败必须看响应体的 `code`，不能只看 HTTP 状态码。**
> 例如 `report` 缺 `topic` 会返回 **`HTTP 200` + `code=4007` + `status=failed`** ——
> 「报文被正确受理并执行完毕」与「业务成功」是两件事：前者用 HTTP 状态表达，后者用业务码表达。

**请求示例**：

```bash
TS=$(date +%s)
BODY='{"action":"echo","uid":"user-1","params":{"probe":"curl","n":42}}'
SIGN=$(printf '%s|%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$API_SECRET" -r | cut -d' ' -f1)

curl -s -X POST http://127.0.0.1:8290/action \
  -H "Content-Type: application/json" \
  -H "X-Timestamp: $TS" -H "X-Sign: $SIGN" \
  --max-time 20 \
  -d "$BODY"
# => {"code":0,"msg":"ok","ts":...,
#     "data":{"request_id":"f96e53866166af3e","status":"done",
#             "result":{"action":"echo","channel":"http","protocol":"http",
#                       "params":{"probe":"curl","n":42},"at":1789983030},
#             "packet":{...}}}
```

**错误**：

| HTTP | code | 触发 |
|---|---|---|
| `400` | `4000` | 请求体非合法 JSON / `action` 为空 / `params` 非对象 |
| `400` | `4006` | 未知动作，或动作未开放 HTTP 通道（`msg` 会列出可用动作） |
| `401` | `4003` | 动作要求身份但未提供 `uid` |
| `401` | `4001` / `4002` | 验签失败 / 时间窗 |
| `429` | `4029` | 限流 |
| `503` | `5030` | 队列积压 ≥ `ACTION_QUEUE_MAX_LEN`(10000)，**直接拒绝** |

**内部链路**（排障用）：

```
POST /action
  ├─ 白名单前置（第 1 道）：未知动作 / 未开放 HTTP → 400/4006
  ├─ RPUSH queue:action:in {request_id, packet, uid, device_id, source, channel, enqueue_at}
  └─ 退避轮询 action:result:{request_id}（10ms 起、×1.5、上限 150ms，窗 API_ACTION_WAIT_MS=6000）
        │
  business 进程 task action-queue-consume（每 0.02s，Lua 原子取批 100 条）
        ├─ 白名单复检（第 2 道，防止持 Redis 凭证者绕过）
        └─ ActionRunner::run(clientId="http:{request_id}", channel=http)
              └─ SETNX action:result:{request_id} EX ACTION_RESULT_TTL(60)
```

> 取结果用**退避轮询**而非 `BLPOP` / `SUBSCRIBE`：阻塞式取用会独占 Redis 连接，
> 而连接池无顺序保证，并发在途超 `pool_size` 即耗尽连接池。

---

### 6.7 `GET /action/{id}` —— 回执补查

`POST /action` 返回 `202` 后，用返回的 `request_id` 补查结果。

**请求**：路径参数 `{id}` 必须匹配 `^[A-Za-z0-9_-]{1,64}$`。

**响应**：

| HTTP | 情形 | 响应体 |
|---|---|---|
| `200` | 命中 | 同 6.6 的成功 / 业务失败两种形态 |
| `400` | `request_id` 格式非法 | `{"code":4000,"msg":"request_id 格式非法"}` |
| `404` | 未就绪或已过期 | 见下 |

```json
{"code":4004,"msg":"动作结果不存在或已过期","ts":1789983030,
 "data":{"request_id":"f96e53866166af3e","status":"pending",
         "hint":"任务可能仍在执行中，或结果已超过 ACTION_RESULT_TTL 被回收"}}
```

> `404` 有两义（仍在执行 / 已超 TTL 被回收），`hint` 只能提示，无法区分 ——
> 需要区分请结合服务端日志中的 `request_id` 判断。
> 回执保留时长由 `ACTION_RESULT_TTL`(60s) 决定，**补查窗口很窄**。

---

### 6.8 两类接口的差异（最易误判处）

| 维度 | 推送类 `/push` | 动作类 `/action` |
|---|---|---|
| 调用方预期 | 「收下了就行」 | 「要结果」 |
| 成功判定 | `HTTP 200` + `code=0` | `HTTP 200` + `code=0` + `data.status=done` |
| 失败表达 | 入队失败 → `5xx` | **入队前**失败 → `4xx`/`5xx`；**执行后**业务失败 → **`HTTP 200`** + 业务码 |
| 队列积压 | 仅告警，继续入队 | 超 `ACTION_QUEUE_MAX_LEN` → `503`/`5030` 直接拒 |
| 超时回落 | 无 | `202` + `request_id`，靠 `GET /action/{id}` 补查 |

---

## 7. 监控面板接口

`dashboard` 角色（默认 `http://127.0.0.1:8291`）**只读**，不接触推送链路、不持有业务密钥。

| 接口 | 方法 | 鉴权 | 说明 |
|---|---|---|---|
| `/` 或 `/index.html` | GET | ❌ 免 | HTML 面板页面，按 `DASHBOARD_REFRESH`(30s) 自动刷新（`0` = 关闭） |
| `/metrics.json` | GET | ❌ 免 | JSON 指标快照，供页面与外部只读消费 |

**`GET /metrics.json` 响应**：

```json
{"code":0,"msg":"ok","ts":1789983030,
 "data":{
   "gauge":   { ... },
   "counter": { ... },
   "task":    { ... },
   "meta":    {"now":1789983030,"interval":60,"ttl":600,"enable":true,"refresh":30}
 }}
```

| `data` 段 | 说明 |
|---|---|
| `gauge` / `counter` / `task` | 与 `GET /stats` 完全相同（见 6.4） |
| `meta` | 面板判定数据新鲜度所需的元信息（`/stats` **不含**此段） |

| `meta` 字段 | 类型 | 说明 |
|---|---|---|
| `now` | int | 服务端当前时间（秒），用于计算 `now - gauge.report_at` |
| `interval` | int | 上报周期（秒），`MONITOR_INTERVAL` |
| `ttl` | int | `metrics:gauge` 的 TTL（秒），`MONITOR_TTL` |
| `enable` | bool | 采集总开关，`MONITOR_ENABLE`。**`false` 时是采集整条链路短路，不只是文案** |
| `refresh` | int | 页面自动刷新间隔（秒），`DASHBOARD_REFRESH` |

**数据新鲜度判定**（`meta.enable=true` 时）：

| 条件 | 页面显示 |
|---|---|
| `now - report_at ≤ interval×2` | 数据新鲜 |
| `now - report_at ≤ ttl` | 上报滞后 |
| 超出 `ttl` | 数据已过期 |

> **免鉴权是有意为之**：`/stats` 需 HMAC 而浏览器无法安全持有密钥，故单开只读端点；
> 代价是**必须只监听回环地址**（默认已如此）。

---

## 8. 业务动作契约

动作声明在 `config/actions.php`（声明式）。**同一个动作可经三条通道触发，共用同一套处理器**，
差异只体现在回执如何回到调用方 —— 由 `reply` 声明表达，处理器代码内**不含任何通道判断**。

### 8.1 动作总表

| 动作 | `params` | WS / UDP / HTTP 回执 | HTTP 开放 | 说明 |
|---|---|---|---|---|
| `echo` | `*`（原样透传） | `sync` / `sync` / `sync` | ✅ | 联通性验证与压测 |
| `report` | 见 8.2 | `sync` / **`none`（静默）** / `sync` | ✅ | 按主题累加计数 |
| `subscribe` | `topic` | `sync` / `sync` / `sync` | ✅ | 订阅主题 |
| `unsubscribe` | `topic` | `sync` / `sync` / `sync` | ✅ | 取消订阅 |
| `topics` | 无 | `sync` / `sync` / `sync` | ✅ | 查询本人已订阅主题 |
| `notify` | 见 8.2 | `sync` / `sync` / `sync` | ✅ | 向本人推送一条消息（验证推送闭环） |
| `session` | 无 | `sync` / `sync` / — | ❌ **刻意不开放** | 查询当前连接会话摘要 |
| `kick` | 见 8.2 | ❌ / ❌ / `sync` | ✅ **仅限 HTTP** | 【运维】断开指定连接（按 `client_id` 或 `uid`） |
| `revoke` | 见 8.2 | ❌ / ❌ / `sync` | ✅ **仅限 HTTP** | 【运维】撤销一个 Token（按明文 `token`） |
| `unbind` | 见 8.2 | ❌ / ❌ / `sync` | ✅ **仅限 HTTP** | 【运维】解绑 uid 与设备 |

> 全部**非运维**动作 `auth` 默认为 `true`（要求已鉴权）。`session` 不开放 HTTP 的原因是它的语义锚点是
> 「当前连接」，而 HTTP 通道下无连接实体（`clientId = http:{request_id}`），调用无意义。
>
> ⚠ **三个运维动作的 `auth` 显式为 `false`**：它们的 uid 是**操作对象**（入参），
> 不是调用方身份。调用方身份由 HTTP 接口密钥（`API_SECRET`）担保 ——
> **持有该密钥即拥有踢线 / 撤销 / 解绑权**，详见 §9.3「管理面凭证」。

#### 通道白名单的两个方向（`http` 与 `channels`）

| 声明字段 | 方向 | 缺省 | 用途 |
|---|---|---|---|
| `http => true` | **额外**开放 HTTP | 不开放（`false`） | 「客户端动作不该被 HTTP 调」 |
| `channels => [...]` | **只**在这些通道开放 | 全通道放行 | 「运维动作不该被客户端调」 |

`http` 是**单向**的：它只表达「额外开放 HTTP」，**不表达「仅限 HTTP」**。
因此**凡注册即可用**对 WS / UDP 侧始终成立 —— 运维动作必须靠 `channels` 这条反向声明收紧，
否则任何持自己合法 Token 的终端客户端都能经 WS 调 `kick` 踢掉任意 `client_id`，属**终端提权**。

> 判定入口：`ActionRunner::channelExposed()`（执行侧裁定）。执行侧与 Api 侧各判一次，
> 与既有 `http` 白名单的双防线模式对称；`tests/Unit/OpsActionContractTest.php` 把
> 「三个运维动作必须声明 `channels = [http]`、既有动作一律不得声明」钉成硬断言。

### 8.2 参数 schema

**公共参数规则**（ParamValidator）：

| 规则键 | 含义 |
|---|---|
| `type` | `string` / `int` / `json` |
| `required` | 是否必填 |
| `max_len` | 字符串长度上限 |
| `pattern` | 正则约束 |
| `enum` | 枚举取值 |
| `min` / `max` | 数值范围 |
| `default` | 缺省值 |

**`topic` 规则**（在 `report` / `subscribe` / `unsubscribe` 中复用）：

```
type=string  required=true  max_len=64  pattern=/^[A-Za-z0-9_:.\-]{1,64}$/
```

> 主题名会直接参与 Redis 键拼接，故必须限制字符集，避免键空间被污染。

**逐动作参数**：

| 动作 | 参数 | 规则 |
|---|---|---|
| `echo` | — | `*`：原样透传，无校验 |
| `report` | `topic` | 见上（**必填**） |
| | `count` | `int`，`1 ~ 10000`，默认 `1` |
| | `value` | `json`（可选） |
| `subscribe` | `topic` | 见上（**必填**） |
| `unsubscribe` | `topic` | 见上（**必填**） |
| `topics` | — | 无参数 |
| `notify` | `value` | `json`（可选） |
| | `msg_id` | `string`，`max_len=64` |
| | `offline_mode` | `string`，`enum=["","drop","queue"]`，默认 `""` |
| `session` | — | 无参数 |
| `kick` | `client_id` | `string`，`max_len=128`（与 `uid` **二选一**，至少提供一个） |
| | `uid` | `string`，`max_len=64` |
| | `reason` | `string`，`max_len=128`（可选，仅落日志） |
| `revoke` | `token` | `string`，**必填**，`max_len=2048`（只接受**明文**，不接受指纹） |
| | `ttl` | `int`，`0 ~ 2592000`；`0` = 取 `AUTH_TOKEN_TTL`（默认 `7200`） |
| `unbind` | `uid` | `string`，**必填**，`max_len=64` |

> ⚠ `revoke` **只接受明文 `token`**：指纹是 `sha256(token)` 前 32 位（单向），
> 若允许按指纹撤销，等于开放「按猜测指纹撤销任意 Token」的接口面。

### 8.3 回执 `data` 结构

| 动作 | 回执 `data` 字段 |
|---|---|
| `echo` | `{action, channel, protocol, params, at}` |
| `report` | `{action, topic, accepted, total, at}` |
| `subscribe` | `{action, uid, topic, subscribers, at}` |
| `unsubscribe` | `{action, uid, topic, subscribers, at}` |
| `topics` | `{action, uid, topics[], count, at}` |
| `notify` | `{action, target, msg_id, queued, at}` |
| `session` | `{action, client_id, uid, device_id, protocol, channel, online, connect_at, online_secs}` |
| `kick` | `{action, uid, requested, closed, skipped, failed, skipped_ids[], failed_ids[], reason, at, note}` |
| `revoke` | `{action, fingerprint, ttl, at, note}` |
| `unbind` | `{action, uid, unbound, at, note}` |

| 通用字段 | 类型 | 说明 |
|---|---|---|
| `action` | string | 动作名 |
| `at` | int | 处理完成时间戳（秒） |
| `channel` | string | `ws` / `udp` / `http`，实际触发通道 |
| `protocol` | string | 连接协议 |

> 经 HTTP 调用时，上述结构整体位于响应体的 `data.result` 中（见 6.6）。

### 8.4 三条通道的回执去向

| 通道 | 回执如何回到调用方 | 超时 |
|---|---|---|
| WS | 直接下发 `cmd=ack` 报文（失败为 `cmd=error`） | 动作 `timeout`（默认 `ACTION_TIMEOUT`=5s） |
| UDP | 写 `queue:udp:out` → 网关定时取批 → `sendto` | 同上 |
| HTTP | 写 `action:result:{request_id}` → api 轮询取回 | `ACTION_TIMEOUT` < `API_ACTION_WAIT_MS`(6000ms) |

> `API_ACTION_WAIT_MS` **必须大于** `ACTION_TIMEOUT × 1000`，否则 api 会先超窗返回 `202`、
> 而动作的同步回执永远取不到（启动日志会对此告警）。

#### 三个运维动作的语义边界（务必读完再调用）

| 动作 | 做什么 | **不做什么**（最常见的误解） |
|---|---|---|
| `kick` | 断开 TCP 连接 | **不撤 Token** —— `closeClient` 触发 `markOffline()` **保留会话供重连**，客户端可立即用同一 Token 重连成功 |
| `revoke` | 把 Token 加进撤销名单 | **不断开已有连接** —— WS 侧该 Token 的**下一次鉴权**才回 `4001`；UDP 侧**下一个包**被丢弃 |
| `unbind` | 删除 `auth:bind:{uid}` | **不踢线** —— 已在线的旧设备连接不受影响，仍在线、仍可推送 |

**组合语义（顺序不可反）**：

- 「踢下线且禁止重连」= **先 `revoke`、后 `kick`**。顺序反了（先踢后撤）时，
  客户端正好落在重连窗口内，会用同一 Token **重连成功** —— 表现为「明明执行了却没效果」。
- 「换设备且旧的立刻下线」= `unbind` + `kick`（两者无竞态，顺序无所谓）。
- **UDP 无踢线**：UDP 没有连接实体（clientId 是 `udp:{ip}:{port}`，不在 Gateway 连接表内），
  `closeClient()` 对它无效 —— 该形态会被计入 `skipped` 而不是 `failed`（不是失败，是「没有线可踢」）。
> 动作回执超时未到 → 记指标 + 告警 + 回 `5000`；`timeout=0` 表示不启用保护。

### 8.5 新增动作（对调用方的影响）

新增动作需两步（服务端侧）：实现 `ActionInterface` 的处理器类 + 在 `config/actions.php` 登记一行。
**调用方视角**：只有登记了 `'http' => true` 的动作才能经 HTTP 调用（默认 `false`）；
WS / UDP 侧凡注册即可用（受 `auth` 与限流约束）。

> ⚠ **上句对运维动作不成立**：P4 起新增了**反向**声明 `channels`。
> 声明了 `channels => [http]` 的动作（`kick` / `revoke` / `unbind` / `purge_offline`）**只**在 HTTP 通道可用，
> WS / UDP 调用一律回 `4006`（未知指令）。详见 §8.1 的「通道白名单的两个方向」。

> HTTP 动作白名单是**双防线**：`config/actions.php` 声明 + `ActionRunner::run()` 内复检。
> 原因是动作队列是 Redis 键，任何持 Redis 凭证者都能直接写任务，故「能否经 HTTP 调用」
> 必须由**执行方**裁定（硬约束 ㉗）。`channels` 同理，两道都在（`Api/Bootstrap.php` 入队前 +
> `ActionRunner::run()` 执行时）。

---

## 9. 限额与约束汇总

### 9.1 报文与请求体

| 项 | 配置名 | 默认值 | 适用 |
|---|---|---|---|
| UDP 单包上限 | `UDP_MAX_PACKET_SIZE` | `8192` 字节 | UDP |
| HTTP 请求体上限 | `API_BODY_MAX` | `65536` 字节 | HTTP |
| 单条业务数据体上限 | `PUSH_PAYLOAD_MAX` | `4096` 字节 | 推送 `payload` |
| 主题名长度 | — | `1 ~ 64` 字符，`[A-Za-z0-9_:.\-]` | `topic` |
| `msg_id` 长度 | — | ≤ `64` 字符 | 推送 / `notify` |
| `request_id` 格式 | — | `^[A-Za-z0-9_-]{1,64}$` | `/action/{id}` |

### 9.2 时效与超时

| 项 | 配置名 | 默认值 | 说明 |
|---|---|---|---|
| 报文时钟偏差 | `AUTH_CLOCK_SKEW` | `300` s | WS / UDP `ts` 校验 |
| 建连未鉴权断开 | `AUTH_TIMEOUT` | `15` s | WS |
| 应用层心跳超时 | `HB_SESSION_TIMEOUT` | `90` s | WS |
| HTTP 签名时间窗 | `API_SIGN_TTL` | `300` s | HTTP |
| 动作回执超时 | `ACTION_TIMEOUT` | `5` s | 全通道 |
| `/action` 同步等待窗 | `API_ACTION_WAIT_MS` | `6000` ms | **必须 > `ACTION_TIMEOUT×1000`** |
| 动作回执保留 | `ACTION_RESULT_TTL` | `60` s | 决定补查窗口 |
| 离线消息保留 | `PUSH_OFFLINE_TTL` | `86400` s | |
| 推送去重窗口 | `PUSH_IDEMPOTENT_TTL` | `600` s | |
| 指标上报周期 | `MONITOR_INTERVAL` | `60` s | |
| 面板自动刷新 | `DASHBOARD_REFRESH` | `30` s | `0` = 关闭 |

### 9.3 容量与限流

| 项 | 配置名 | 默认值 | 超额行为 |
|---|---|---|---|
| HTTP 单 IP 限流 | `API_RATE_LIMIT` | `600` /min | `429` / `4029` |
| 动作队列积压上限 | `ACTION_QUEUE_MAX_LEN` | `10000` | `/action` → `503` / `5030` 直接拒 |
| 推送队列上限 | — | `10000` | 仅告警，继续入队 |
| 单用户离线消息 | `PUSH_OFFLINE_MAX` | `100` 条 | 丢弃最旧 |
| 单用户订阅主题数 | `SUBSCRIBE_MAX_TOPICS` | `100` | `0` = 不限，超出 → `4007` |
| 重连补投单批 | `PUSH_REPLAY_BATCH` | `50` 条 | — |
| 连接级限流 | — | 见 README 7 章 | L1 内存桶（验签前）+ L2 Redis 桶 |

> 连接级限流两层**不可互换**：L1 内存桶在 UDP 验签前生效（每 IP、零 IO）；
> L2 Redis 桶原子判定不扣减。Redis 故障时 **fail-open**（放行并告警）。

### 9.4 管理面凭证（`API_SECRET` 的第二重身份）

P4 起 `API_SECRET` 有**两重身份**，二者共用同一个密钥、无法拆分：

| 身份 | 担保什么 | 覆盖范围 |
|---|---|---|
| 接口凭证 | 「调用方是被信任的服务端」 | `/push`、`/action`（非运维动作）、`/stats` 等 |
| **管理面凭证** | 「调用方拥有运维权」 | `kick` / `revoke` / `unbind` / `purge_offline` 四个运维动作 |

**等式：`持有 API_SECRET` ⇒ `可踢任意 client_id / 撤销任意 Token / 解绑任意 uid`。**

当前该等式是**决策接受的**（前提：`API_LISTEN` 监听回环地址、且只有一个可信调用方）。
它在下面任一条件成立时即变为**真实的提权面**，须先升级为独立管理面密钥
（`X-Admin-Key` 之类）再放开：

1. `API_LISTEN` 改为非回环地址（`0.0.0.0` / 具体网卡 / 域名）；
2. 出现 **≥2 个互不信任的 API 调用方**；
3. 把 `API_SECRET` 下发给业务调用方（它只需要用户级 Token，不需要本密钥）。

> ⚠ **任何改动 `API_LISTEN` 或新增 API 调用方的评审，都必须复查本节。**

---

## 10. 联调自检工具

| 工具 | 命令 | 覆盖 | 依赖 |
|---|---|---|---|
| HTTP 全场景 demo | `php tests/Api/http_demo.php`（`composer demo:http`） | 13 场景 / 19 断言，含签名构造全过程。`--curl` 打印等价 curl 命令 | api + business 在线 |
| HTTP 验签与错误分支 | `node tests/Api/api_sign_check.js` | 8 形态（正确/空 body/错误签名/免鉴权/`/action` 正常/`session` 拒绝/错签名/补查 404） | api + business 在线 |
| Postman 集合 | 导入 `postman/GatewayPush.postman_collection.json` | 9 个请求，**集合级自动签名** | api + business 在线 |
| 端到端全链路 | `composer test:e2e` | 17 用例（WS / UDP / HTTP / 推送 / 动作 / 限流 / 离线补投 / 运维动作通道隔离） | 全部角色在线；失败先跑 `composer test:e2e:clean` 清残留 |
| 环境自检 | `php start.php check` | 配置、密钥、队列键一致性、端口 | 无 |

---

## 附录：变更同步约定

改动以下任一项时，**必须同步本文件对应章节**（否则调用方会按旧契约接入）：

| 改动 | 需同步章节 |
|---|---|
| 新增 / 调整 HTTP 接口、路径、方法 | 6.2 及对应小节 |
| 改请求 / 响应字段、约束 | 6.3 ~ 6.7、第 9 节 |
| 新增 / 调整业务动作、参数、回执 | 第 8 节 |
| 改错误码、HTTP 状态映射 | 第 3.3 / 3.4 节 |
| 改 Token 结构、签名算法 | 第 2 节 |
| 改默认监听地址 / 端口 | 第 1 节、第 4 / 5 / 6 / 7 节 |

**关键改动还应同步**：`README.md` 对应章节、`client/README.md`（若影响 SDK）、
`tests/Api/http_demo.php` 与 `postman/` 集合（若影响调用形态）。

---

*本文件随代码同步维护。字段级细节以 `src/Api/Bootstrap.php`、`src/Business/Push.php`、
`config/actions.php`、`config/app.php`、`config/gateway.php` 为准。*
