# GatewayWorker 实时数据推送服务

基于 [workerman](https://github.com/walkor/workerman) + [GatewayWorker](https://github.com/walkor/GatewayWorker) 的
**WebSocket + UDP 双协议**实时数据推送服务。面向「单对一定向推送」场景（一个用户/设备对应一条有效连接），
支撑上万长连接；除 Redis 外无其他外部依赖。

```
WebSocket 长连接（实时双向）  UDP 轻量上报（低开销、可丢包）  HTTP 接口（服务端下发）
                    ↓                    ↓                        ↓
              ┌──────────────────────────────────────────────────────────┐
              │            统一报文 / 统一路由 / 统一推送出口              │
              │        认证 · 限流 · 会话 · 幂等 · 离线补投 · 指标          │
              └──────────────────────────────────────────────────────────┘
```

---

## 目录

- [1. 核心特性](#1-核心特性)
- [2. 运行架构](#2-运行架构)
- [3. 目录结构](#3-目录结构)
- [4. 环境要求](#4-环境要求)
- [5. 快速开始](#5-快速开始)
- [6. 命令参考](#6-命令参考)
- [7. 配置参考（.env）](#7-配置参考env)
- [8. 通信协议规范](#8-通信协议规范)
- [9. 数据流转说明](#9-数据流转说明)
- [10. Redis 键空间](#10-redis-键空间)
- [11. 监控面板](#11-监控面板)
- [12. 业务动作接入指南](#12-业务动作接入指南)
- [13. 测试与静态分析](#13-测试与静态分析)
- [14. 运维手册](#14-运维手册)
- [15. 集群就绪度](#15-集群就绪度)

---

## 1. 核心特性

| 能力 | 实现方式 | 关键位置 |
|---|---|---|
| **双协议接入** | 同一份报文结构、同一张指令路由表、同一批业务动作处理器，WS 与 UDP 行为差异只在「回执下发方式」 | `src/Business/ActionRunner.php` |
| **单对一定向推送** | 三种目标类型：`uid`（该用户全部在线连接）/ `device`（精确到单台设备）/ `client`（精确到单条连接）；同设备新连接上线时自动踢掉旧连接 | `src/Business/Push.php` |
| **离线消息补投** | 目标离线时写 `push:offline:{uid}`，重连后自动补投（至少一次语义） | `Push::handleOffline()` / `Push::replayOffline()` |
| **推送幂等** | 按 `msg_id` 做 `setNxEx` 去重，窗口可配 | `Push::dispatch()` |
| **声明式业务动作** | 新动作 = 写一个类 + 在 `config/actions.php` 登记一行，不改框架代码 | `config/actions.php` |
| **统一鉴权** | 自包含 HMAC Token（`base64url(payload).base64url(hmac)`），本地校验无 IO；另有 Redis 撤销名单与设备绑定校验 | `src/Business/Auth.php` |
| **报文签名** | `hmac_sha256("cmd\|seq\|ts\|device_id\|token\|canonicalize(data)", secret)`，UDP 无连接场景的身份基石 | `src/Business/Message.php` |
| **两级限流** | L1 网关内存令牌桶（每 IP，零 IO，抗洪水）+ L2 Redis 令牌桶（每连接 / 每用户，跨进程共享） | `src/Common/RateLimiter.php` |
| **会话保持** | 会话全量落 Redis，节点无本地状态；断连保留会话、重连按 `device_id` 恢复 | `src/Business/Session.php` |
| **监控指标** | 业务侧仅进程内累加（零 IO），定时任务批量刷 Redis；HINCRBY 保证多进程安全 | `src/Business/Monitor.php` |
| **只读监控面板** | 独立进程渲染自包含 HTML 页面，零外链、不写 Redis、不持业务密钥 | `src/Dashboard/Bootstrap.php` |
| **HTTP 推送接口** | 独立进程只做「验签 → 校验 → 入队」，不持有 Gateway 连接，故障域与网关隔离 | `src/Api/Bootstrap.php` |
| **跨平台脚本** | Linux/Windows 各自的管理脚本，处理终端中文编码、按角色编排进程 | `bin/` |

---

## 2. 运行架构

### 2.1 进程拓扑

```
                          ┌───────────────────────┐
                          │   Register 注册中心    │
                          │  127.0.0.1:1238       │
                          │  text 协议 / 单进程    │
                          └───────────┬───────────┘
                        注册 + 查询   │
        ┌─────────────────────────────┼─────────────────────────────┐
        │                             │                             │
┌───────▼────────┐         ┌──────────▼─────────┐        ┌──────────▼─────────┐
│  Gateway 网关   │         │    UDP 网关         │        │  BusinessWorker    │
│  GW-WS          │         │    GW-UDP           │        │  BusinessWorker    │
│  websocket://   │         │    udp://           │        │  (4 进程)          │
│  0.0.0.0:8282   │         │    0.0.0.0:8283     │        │                    │
│  (4 进程)       │         │    (2 进程)          │        │  全部业务逻辑       │
└───────┬─────────┘         └──────────┬─────────┘        └──────────┬─────────┘
        │                              │                             │
        │ 长连接持有                    │ 验签后入队                   │ 消费队列 / 处理动作
        │ 原生心跳                      │ Redis List                  │ 会话 / 推送 / 指标
        │                              │                             │
        └──────────────────────────────┴─────────────────────────────┘
                                       │
                              ┌────────▼────────┐
                              │      Redis      │  唯一外部依赖
                              │  会话/索引/队列   │  连接池 8 连接/进程
                              │  指标/限流桶     │
                              └────────┬────────┘
                                       │
              ┌────────────────────────┼────────────────────────┐
              │                        │                        │
    ┌─────────▼─────────┐   ┌──────────▼──────────┐   ┌─────────▼─────────┐
    │  HTTP 接口进程      │   │   监控面板进程       │   │  Windows 承载窗口  │
    │  GW-API            │   │   GW-DASH           │   │  (仅 Windows)      │
    │  127.0.0.1:8290    │   │   127.0.0.1:8291    │   │                    │
    │  只写推送队列        │   │   只读指标          │   │                    │
    └────────────────────┘   └─────────────────────┘   └────────────────────┘
```

### 2.2 角色清单

| 角色 | 进程名 | 默认监听 | 进程数(Linux) | 职责 |
|---|---|---|---|---|
| `register` | `Register` | `127.0.0.1:1238` | 1（强制） | Gateway 与 BusinessWorker 的地址发现 + 内部通信鉴权 |
| `gateway` | `GW-WS` | `websocket://0.0.0.0:8282` | 4 | 长连接接入、原生心跳、死连接清理、报文转发 |
| `udp` | `GW-UDP` | `udp://0.0.0.0:8283` | 2 | UDP 收包、L1 限流、签名校验、入队、出站 sendto |
| `business` | `BusinessWorker` | — | 4 | 全部业务：鉴权、会话、动作执行、推送、指标 |
| `api` | `GW-API` | `http://127.0.0.1:8290` | 1 | HTTP 推送接口：验签 → 校验 → 入队 |
| `dashboard` | `GW-DASH` | `http://127.0.0.1:8291` | 1 | 只读监控面板 |
| `all` | — | — | — | 一次装配以上全部（**仅 Linux**） |

> **职责边界是硬约束**：网关进程只做网络调度，不承载业务逻辑；UDP 网关不碰业务，
> 协议校验后经 Redis 队列交给业务进程；面板进程只读 Redis，不写任何键；
> API 进程只写推送队列，不持有 Gateway 连接。这样做的目的是让每个进程的**权限与故障域**
> 都保持最小。

---

## 3. 目录结构

```
GatewayWorker/
├── bin/                              服务管理脚本（跨平台，处理终端编码）
│   ├── start.sh                      Linux / macOS
│   ├── start.bat                     Windows 入口（纯 ASCII，仅转发到 ps1）
│   └── start.ps1                     Windows 实现（UTF-8 带 BOM）
├── config/
│   ├── app.php                       全局：运行约束 / 日志 / Redis / 鉴权 / 会话 / 推送 / API / 面板 / 限流 / 订阅 / 监控
│   ├── gateway.php                   网关层：Register / WebSocket / UDP / 心跳
│   ├── business.php                  业务层：进程参数 / UDP 队列 / 推送队列 / 定时任务注册表
│   └── actions.php                   业务动作声明清单（声明式，改这里不改框架）
├── resources/
│   └── dashboard/index.html          监控面板页面（自包含，零外链）
├── runtime/                          运行时目录（.gitignore 排除）
│   ├── logs/                         {level}_YYYY-MM-DD.log / workerman.log / stdout.log
│   └── pid/                          workerman_{role}.pid（Linux）/ win_{role}.pid（Windows 承载窗口）
├── src/
│   ├── Api/Bootstrap.php             HTTP 推送接口进程
│   ├── Business/
│   │   ├── Action/                   7 个内置业务动作实现
│   │   │   ├── EchoAction.php        原样回显
│   │   │   ├── SessionAction.php     查询会话摘要
│   │   │   ├── ReportAction.php      数据上报
│   │   │   ├── SubscribeAction.php   订阅主题
│   │   │   ├── UnsubscribeAction.php 取消订阅
│   │   │   ├── TopicsAction.php      查询已订阅主题
│   │   │   └── NotifyAction.php      触发向本人推送
│   │   ├── ActionContext.php         动作上下文：身份 / 已校验参数 / 统一回执
│   │   ├── ActionInterface.php       动作契约
│   │   ├── ActionRunner.php          通道无关的动作执行器
│   │   ├── Auth.php                  Token 签发 / 校验 / 撤销 / 设备绑定
│   │   ├── Bootstrap.php             BusinessWorker 入口 + 事件处理 + 队列消费
│   │   ├── Message.php               报文编解码 / 签名 / 校验 / 错误码
│   │   ├── Monitor.php               指标采集与落库
│   │   ├── ParamValidator.php        参数规则校验（白名单语义）
│   │   ├── Push.php                  定向推送核心（唯一投递出口）
│   │   ├── Router.php                指令路由表（一级 cmd + 二级 action）
│   │   ├── Session.php               会话绑定 / 心跳 / 重连恢复 / 过期清理
│   │   ├── Subscribe.php             订阅关系双向索引
│   │   └── Task.php                  定时任务调度（scope: first / all）
│   ├── Common/
│   │   ├── Env.php                   .env 多级加载 + 类型化读取
│   │   ├── Logger.php                分级日志 + 全局异常捕获 + 过期清理
│   │   ├── RateLimiter.php           两级限流（内存桶 + Redis 桶）
│   │   ├── RedisClient.php           异步 Redis 客户端（连接池 + Lua 脚本）
│   │   └── WorkerEvents.php          Worker 事件统一绑定（含背压观测）
│   ├── Dashboard/Bootstrap.php       监控面板进程
│   └── Gateway/
│       ├── Bootstrap.php             网关层入口 + UDP 报文处理 + 出站队列消费
│       └── UdpProtocol.php           UDP 应用层协议（输入分段 / 编解码）
├── client/                            客户端 SDK（独立于服务端进程，详见 client/README.md）
│   ├── src/Protocol/                  Codec / Signer / TokenIssuer（复用服务端 Message、Auth）
│   ├── src/Transport/                 TransportInterface / WsTransport / UdpTransport / HttpTransport
│   ├── src/Session/                   SessionManager / PendingRequest（鉴权状态机/心跳/重连）
│   ├── src/Service/                   业务动作 API + AdminApi（HTTP /push /stats /health）
│   ├── src/Event/                     PushReceiver（推送接收 + 自动回 ack）
│   ├── src/Cli/                       CommandParser / Debugger（P6 调试器引擎）
│   ├── src/Error/                     错误码与统一异常
│   ├── bin/gwclient.php               CLI 调试器入口（一次性命令 / shell REPL / listen）
│   ├── tests/Unit/                    客户端单元测试
│   ├── tests/E2E/ClientE2E.php        客户端端到端对齐（与服务端 e2e 同口径 A~O）
│   └── README.md                      客户端使用说明与里程碑
├── tests/
│   ├── E2E/                          端到端用例（Harness + 9 个 Case 模块）
│   ├── Unit/                         单元测试（6 个纯函数/零 IO 组件）
│   ├── bootstrap.php
│   └── e2e_check.php                 e2e 入口
├── start.php                         统一启动入口：命令解析 + 角色装配 + 环境自检
├── composer.json                    依赖与脚本
├── phpstan.neon / phpstan-baseline.neon
├── phpunit.xml
├── .env.example                     配置模板（含全部变量的说明）
└── Workman V2 GatewayWorker 实时数据推送服务技术方案文档.md
```

---

## 4. 环境要求

| 项 | 要求 | 说明 |
|---|---|---|
| PHP | **>= 8.1**（已验证上限 8.5） | 下限由 `workerman/workerman` 5.x 的 `require` 决定，非项目代码约束；代码本身不使用 8.2+ 独有语法，`phpstan.neon` 的 `phpVersion: 80100` 用于拦截误用 |
| 必需扩展 | `json`、`openssl`、`sockets` | `openssl` 用于 HMAC，`sockets` 供 workerman 使用 |
| Linux 必需扩展 | `pcntl`、`posix` | 多进程模型依赖；Windows 无此二扩展，自动降级为单进程 |
| 建议扩展 | `event`、`redis`、`mbstring` | 缺失仅告警：`event` 提升事件循环性能，`redis` 供扩展加速，`mbstring` 使参数长度按**字符**计数 |
| Redis | 支持 Lua (`EVAL`) 与 `SET NX EX` 的版本（>= 2.6.12），生产建议 5.0+ | 唯一外部依赖。原子取批与令牌桶判定均依赖 Lua 脚本 |
| Composer | 任意近期版本 | 用于安装依赖 |

> **PHP 版本兼容矩阵尚未完整验证**：声明范围为 8.1 ~ 8.5，但 PHPStan / PHPUnit / e2e
> 目前仅在 PHP 8.2.9 上验证通过。**PHP 8.0 无法运行本项目** —— `workerman/workerman`
> 5.x 全线要求 `>=8.1`，Composer 会在 `vendor/composer/platform_check.php` 直接抛
> `RuntimeException` 拦截，连 `start.php` 都进不去。若你的部署环境使用 8.1 或 8.3+，
> 建议按 [13.3 跨版本验证](#133-跨版本验证) 自行跑一轮。

---

## 5. 快速开始

### 5.1 安装依赖

```bash
git clone <repo> GatewayWorker
cd GatewayWorker
composer install
```

### 5.2 生成配置文件（首次部署必执行）

```bash
php start.php env:init
```

该命令从 `.env.example` 复制生成 `.env`，并对 `AUTH_SECRET` / `INTERNAL_SECRET`
**自动注入 64 位十六进制随机值**。对已存在的 `.env` 只补齐仍为空的密钥项，**不覆盖已有取值**。

### 5.3 环境自检

```bash
php start.php check
```

输出内容涵盖：PHP 版本、必需/可选扩展、运行时目录可写性、注册中心地址一致性、
UDP 队列 key 一致性、`.env` 加载链、密钥强度（含占位值检测）、推送与接口配置、
限流维度、业务动作清单与处理器可用性、端口占用探测。退出码 `0` 通过 / `1` 失败。

典型输出：

```
GatewayWorker 推送服务 - 运行环境自检
======================================================================
PHP 版本  : 8.2.9 (cli)
操作系统  : WINNT [单进程模式]
启动角色  : all
----------------------------------------------------------------------
[OK  ] PHP 版本 >= 8.1.0
[OK  ] 必需扩展 json
...
[OK  ] 注册中心地址一致（gateway: 127.0.0.1:1238 / business: 127.0.0.1:1238）
[OK  ] UDP 队列 key 一致（queue:udp:in）
[OK  ] 环境配置 .env（APP_ENV=dev，右侧覆盖左侧）
[OK  ] 内部通信密钥（Register / Gateway / BusinessWorker 一致性）
...
自检结论：通过
```

> 所有 workerman 命令在启动前都会**强制执行一次自检**，未通过则终止启动并可通过
> `php start.php check` 复检。这可以避免「服务起来了但配置错了」这类最难排查的状态。

### 5.4 启动服务

#### Linux（推荐正式环境）

```bash
./bin/start.sh start          # 守护模式启动全部 6 个角色，随后自动打印状态表
```

或直接调 PHP：

```bash
php start.php start -d        # -d 为 daemon 模式
```

#### Windows（开发环境）

Windows 下 **必须按角色分终端启动**（原因见 [6.6 Windows 强制约束](#66-windows-强制约束)）：

```cmd
bin\start.bat start           :: 全部 6 个角色，每个角色一个独立窗口
bin\start.bat start core      :: 只启动 5 个核心角色（不含监控面板）
bin\start.bat start business  :: 只启动单个角色（调试用）
```

或手工开 6 个终端：

```cmd
php start.php start --role=register
php start.php start --role=gateway
php start.php start --role=udp
php start.php start --role=business
php start.php start --role=api
php start.php start --role=dashboard
```

### 5.5 验证服务

```bash
# 1) 查看进程状态
./bin/start.sh status          # Linux
bin\start.bat status           # Windows

# 2) 生成一个调试 Token
php start.php token 1001 dev-001

# 3) 跑端到端自检（15 个用例，覆盖双协议全链路）
php tests/e2e_check.php e2e-uid-0001

# 4) 打开监控面板（需 dashboard 角色）
#    http://127.0.0.1:8291
```

> ⚠️ **e2e 每轮必须使用全新 uid**。UDP 无断连事件，会话只靠心跳超时回收，
> 同一 uid 连续多轮运行会污染离线补投用例的结果。

---

## 6. 命令参考

### 6.1 两套管理脚本的命令对照

项目的管理脚本只做「进程编排 + 终端编码」，业务判断（自检、角色装配、启动流程）
全部由 `start.php` 负责 —— 两边不重复实现，避免出现两套真源。

| 命令 | Linux `./bin/start.sh` | Windows `bin\start.bat` | 说明 |
|---|---|---|---|
| 启动全部 | `start` | `start` | Linux 守护模式；Windows 开 6 个窗口 |
| 启动核心 | — | `start core` | 除 `dashboard` 外的 5 个角色 |
| 启动单角色 | `start <角色>` | `start <角色>` | Linux 前台运行（Ctrl+C 退出）；Windows 独立窗口 |
| 停止 | `stop [角色\|all]` | `stop [all\|角色]` | 不带参数 = 全部 |
| 重启 | `restart [角色\|all]` | `restart [all\|角色]` | 先停后启 |
| 平滑重启 | `reload [角色\|all]` | `reload` | Linux 仅重载业务代码，长连接不中断；Windows 语义受限，见 6.6 |
| 状态 | `status` / `svc-status` | `status` / `svc-status` | PID / 内存 / 运行时长 / 监听地址 |
| 看日志 | `log [-f] [类型] [行数]` | `log [-f] [类型] [行数]` | 类型：`workerman`(默认) / `info` / `warn` / `error` / `stdout` |
| 环境自检 | `check` | `check` | 透传到 `php start.php check` |
| 生成配置 | `env:init` | `env:init` | 透传到 `php start.php env:init` |
| 生成 Token | `token <uid> [device] [ttl]` | `token <uid> [device] [ttl]` | 透传 |
| 提交推送 | `push <类型> <目标> [payload] ...` | `push <类型> <目标> [payload] ...` | 透传 |
| 帮助 | `help` / `-h` / `--help` | `help` / `-h` / `--help` | |

> `status` 与 `svc-status` 在**管理脚本中**是等价别名（脚本自己实现状态表）。
> 但直接调 `php start.php` 时请使用 `status` —— `svc-status` 不是 workerman 的内置命令。

### 6.2 Linux 脚本命令详解

#### `./bin/start.sh start [角色]`

```bash
./bin/start.sh start            # 守护模式启动全部组件，就绪后自动打印状态表
./bin/start.sh start business   # 前台运行单个角色（排查单个组件时用），Ctrl+C 退出
```

- 不带角色时：校验平台 → 检查是否已在运行（幂等）→ 前置检查（PHP 与 `vendor/autoload.php`）
  → 检查 `ulimit -n`（低于 10240 时告警）→ `php start.php start -d`
  → **等待最多 10 秒确认 pid 文件出现**，然后打印状态表。
- 带角色时：前台运行，日志直接输出到当前终端。
- 启动顺序即依赖顺序：`register → gateway → udp → business → api → dashboard`。

#### `./bin/start.sh status`

```
角色        状态        监听地址                    PID       内存      运行时长    说明
-------------------------------------------------------------------------------------------
register    运行中      127.0.0.1:1238              12345     6.2 MB    00:12:31    注册中心
gateway     运行中      websocket://0.0.0.0:8282    12350     8.1 MB    00:12:29    WebSocket 网关
udp         运行中      udp://0.0.0.0:8283          12355     7.4 MB    00:12:28    UDP 网关
business    运行中      -                           12360     9.8 MB    00:12:26    业务进程
api         运行中      http://127.0.0.1:8290       12380     6.0 MB    00:12:20    HTTP 接口
dashboard   已停止      http://127.0.0.1:8291       -         -         -           监控面板

本机 PID：8888   项目目录：/www/wwwroot/gateway-push
运行中 5 / 6 个角色
```

监听地址列直接读 `.env` 中的对应变量（`REGISTER_LISTEN` / `WS_LISTEN` / `UDP_LISTEN` /
`API_LISTEN` / `DASHBOARD_LISTEN`）。`business` 无监听端口，故显示 `-`。

#### `./bin/start.sh stop [角色|all]`

```bash
./bin/start.sh stop            # 停止全部（分角色启动与单进程启动两种部署都覆盖）
./bin/start.sh stop udp        # 只停 UDP 网关
```

不带参数时会依次停止 6 个角色，最后再停一次 `all`（覆盖「单文件启动全部组件」的部署方式）。

#### `./bin/start.sh reload [角色|all]`

```bash
./bin/start.sh reload          # 平滑重启业务进程，网关长连接不中断
```

仅重载代码，**不重建长连接**。适合业务代码更新后使用。若改动了 `config/*.php` 的
结构性配置（扩展清单、任务注册表等），需要 `restart` 而非 `reload`。

#### `./bin/start.sh log [-f] [类型] [行数]`

```bash
./bin/start.sh log                    # workerman.log 最后 60 行
./bin/start.sh log -f warn            # 跟随告警日志
./bin/start.sh log error 200          # 错误日志最后 200 行
./bin/start.sh log stdout             # workerman 的 stdout.log
```

| 类型 | 实际文件 |
|---|---|
| `workerman`（默认） | `runtime/logs/workerman.log` |
| `info` / `warn` / `error` | `runtime/logs/{level}_YYYY-MM-DD.log`（自动取最新一个） |
| `stdout` | `runtime/logs/stdout.log`（daemon 模式下 PHP 的屏幕输出） |

> 用 `less` 看日志请加 `-R`，否则中文会显示为乱码（`less` 不自动按 UTF-8 解码）。

#### 环境变量

| 变量 | 作用 |
|---|---|
| `PHP_BIN` | 指定 PHP 可执行文件，默认取 PATH 中的 `php`。**脚本会校验其版本**（低于下限直接拒绝，见 [6.7](#67-php-解释器解析与版本校验两平台)）。例：`PHP_BIN=/www/server/php/82/bin/php ./bin/start.sh check` |
| `NO_COLOR` | 设为任意值可关闭彩色输出 |

### 6.3 Windows 脚本命令详解

```cmd
bin\start.bat start                  :: 全部 6 个角色，各开一个窗口
bin\start.bat start core             :: 只启动核心 5 个角色
bin\start.bat start gateway          :: 只启动 WebSocket 网关
bin\start.bat status
bin\start.bat stop
bin\start.bat stop udp
bin\start.bat restart
bin\start.bat reload
bin\start.bat log -f warn
bin\start.bat check
bin\start.bat token 1001 dev-001
bin\start.bat push uid 1001 "{\"title\":\"hello\"}" msg-1
```

也可直接用 PowerShell 并指定 PHP 路径：

```powershell
.\bin\start.ps1 status -PhpPath D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe
```

**Windows 侧与 Linux 的实现差异（这是刻意的，不是缺陷）：**

| 项 | Linux | Windows |
|---|---|---|
| 存活判据 | `runtime/pid/workerman_{role}.pid` + `kill -0` | 用 `Get-CimInstance Win32_Process` 读命令行**反查 `php.exe`** 匹配 `--role=<角色>` |
| 停止方式 | `php start.php stop --role=xxx` | 终止上述反查到的 PID + 连带关闭承载窗口（`win_{角色}.pid` 记录的 cmd.exe） |
| `reload` | workerman 原生平滑重载 | 无法做到真正平滑（Windows 无 master 进程模型），退化为重启业务相关角色 |
| `start` | 守护模式 `-d` | 每个角色一个独立窗口，`cmd /k` 保持窗口不关闭 |
| 端口探测 | `kill -0` / 直接判断 | `Test-PortListening` + 就绪轮询 |

### 6.4 `php start.php` 内置命令

这些命令由 `start.php` 自身实现，不经 workerman：

| 命令 | 用法 | 说明 |
|---|---|---|
| `help` | `php start.php help` | 打印用法（等价 `-h` / `--help`） |
| `check` | `php start.php check` | **仅执行环境自检**，不启动服务。退出码 0/1 |
| `env:init` | `php start.php env:init` | 生成 `.env`，自动注入随机密钥 |
| `token` | `php start.php token <uid> [device_id] [ttl]` | 生成调试用 Token |
| `push` | `php start.php push <uid\|device\|client> <target> [payload-json] [msg_id] [offline_mode]` | 提交一条定向推送任务 |

#### `php start.php token`

```bash
php start.php token 1001
php start.php token 1001 dev-001
php start.php token 1001 dev-001 3600
```

输出：

```
uid       : 1001
device_id : dev-001
ttl       : 7200s
token     : eyJ1aWQiOiIxMDAxIiwiZGV2aWNlX2lkIjoiZGV2LTAwMSIsImlhdCI6MTc1...
```

第三个参数为有效期（秒），省略或传 0 时取 `AUTH_TOKEN_TTL`（默认 7200）。

> 生产环境的 Token 通常由业务系统签发；本命令主要用于联调自测与内部服务调用。
> Token 结构见 [8.3 Token 结构](#83-token-结构)。

#### `php start.php push`

```bash
# 按 uid 推送（该 uid 名下全部在线连接）
php start.php push uid 1001 '{"title":"hi","body":"hello"}'

# 按设备推送（单对一核心场景），带 msg_id 参与幂等去重
php start.php push device dev-001 '{"title":"hi"}' msg-1

# 按连接推送（调试用），并覆盖离线策略为 drop
php start.php push client 7 '{"title":"hi"}' msg-2 drop
```

输出：

```
推送任务已入队，等待业务进程消费
  target_type  : device
  target       : dev-001
  msg_id       : msg-1
  offline_mode : queue
  queue        : queue:push:out
```

| 参数 | 必填 | 说明 |
|---|---|---|
| 第 1 个 | 是 | 目标类型：`uid` / `device` / `client` |
| 第 2 个 | 是 | 目标值 |
| 第 3 个 | 否 | 业务数据体（JSON 对象），默认 `{}` |
| 第 4 个 | 否 | `msg_id`，传入后参与幂等去重；不传则不去重 |
| 第 5 个 | 否 | 离线策略覆盖：`drop` / `queue`；不传则用 `PUSH_OFFLINE_MODE` |

> **关键语义**：本命令**只负责入队**，不做实际投递。因此它**可以在服务未启动时执行** ——
> 任务会在服务起来后被业务进程消费补投。真实投递由 `BusinessWorker` 的
> `push-queue-consume` 定时任务完成。

> Windows 下注意引号：`bin\start.bat push uid 1001 "{\"title\":\"hi\"}" msg-1`。

### 6.5 workerman 内置命令

以下命令由 workerman 自身处理（`start.php` 未实现，交由框架）：

| 命令 | 说明 |
|---|---|
| `start [-d]` | 启动；`-d` 为守护模式 |
| `stop [-g]` | 停止；`-g` 为优雅停止 |
| `restart [-d] [-g]` | 重启 |
| `reload [-g]` | 平滑重载代码 |
| `status [-d]` | 查看进程状态；`-d` 为实时刷新 |
| `connections` | 查看连接详情 |

```bash
php start.php start -d
php start.php status -d
php start.php connections
php start.php reload
```

> `--role=<角色>` 会被 `start.php` 从 `argv` 中提取并剔除，再交给 workerman 解析，
> 因此可以和这些命令自由组合：`php start.php start --role=gateway -d`。

### 6.6 Windows 强制约束

> **Windows 下绝不要执行 `php start.php stop|restart|reload|status`。**

原因：workerman 在非 Unix 平台**完全跳过命令行解析**。

```php
// vendor/workerman/workerman/src/Worker.php:1075
protected static function parseCommand(): void
{
    if (DIRECTORY_SEPARATOR !== '/') {
        return;            // ← 非 Unix 直接返回，整个命令解析被跳过
    }
```

后果是：`php start.php stop` 不会停止任何进程，而是**照常按 `start` 执行，反向启动一个新实例**。
每执行一次就多一个进程、多一份端口占用。表现为：

- UDP 网关 ×2 → 抢同一端口 + 同时消费 `queue:udp:in`
- BusinessWorker ×3 → 重复处理报文 + 重复计数

同时，非 Unix 平台 workerman **不写 pid 文件**（`runtime/pid/` 在 Windows 下恒为空），
这也是 Windows 侧管理脚本改用「读进程命令行反查」的原因。

**正确做法**：

```cmd
bin\start.bat stop            :: 停全部
bin\start.bat stop udp        :: 停单个角色
taskkill /F /PID <pid>        :: 兜底手段
```

### 6.7 PHP 解释器解析与版本校验（两平台）

两个管理脚本都会在**真正调用 `start.php` 之前**先确定 PHP 解释器并校验版本。这不是可选的防御性代码，
而是必需的一步：项目的 PHP 下限由**依赖**决定（`workerman/workerman` 5.x 全线 `>= 8.1`），
Composer 生成的 `vendor/composer/platform_check.php` 会在 `autoload` 阶段直接抛 `RuntimeException`。
如果不提前拦截，用户看到的是一段 Composer 堆栈，而不是「版本过低」这句人话。

**下限的真源**：`config/app.php` 的 `php_min`（当前 `8.1.0`）。两个脚本都从该文件读取，
不另立一份；解析失败时回落到 `8.1.0`。

| | Linux（`bin/start.sh`） | Windows（`bin/start.ps1`） |
|---|---|---|
| 解释器来源 | `PHP_BIN` 环境变量，缺省取 PATH 中的 `php` | `-PhpPath` 参数，缺省按候选顺序自动解析 |
| 候选顺序 | 不适用（单值） | ① PATH 中的 `php.exe` / `php`；② **遍历 PATH 的每一个目录**找 `php.exe`；③ `D:\phpstudy_pro\Extensions\php\*\php.exe`、`C:\...`（按名称倒序） |
| 探测方式 | `$PHP_BIN -r 'echo PHP_VERSION_ID;'` | 同左 |
| 版本过低 | **硬失败**，提示 `PHP_BIN=...` 用法 | 逐个候选跳过；`-PhpPath` 显式指定时**硬失败**（不回落到别的解释器） |
| 全部候选不合格 | — | 打印候选清单（含各自版本）+ 退出码 1 |
| 探测失败（无法取到版本号） | 仅告警，继续执行 | 该候选被跳过；若为 `-PhpPath` 则告警后继续 |

启动时脚本会把**实际选中的解释器**回显一行，便于排查本机多版本共存的情况：

```
      PHP 8.2.9  D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe
```

被跳过的候选也会明确列出：

```
[警告]  已跳过不满足版本要求的 PHP：D:\phpstudy_pro\Extensions\php\php8.0.2nts\php.exe  [8.0.2]
      PHP 8.2.9  D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe
```

> **IDE 会污染 PATH**：PhpStorm 会把「项目默认解释器」注入集成终端的 PATH。
> 若该解释器低于下限，脚本现在会自动跳过它并选用合格候选；但用 IDE 的 PHP 解释器设置
> 跑本项目仍会失败 —— 请把 CLI Interpreter 指向 `>= 8.1` 的解释器。

显式指定解释器：

```bash
PHP_BIN=/www/server/php/82/bin/php ./bin/start.sh check     # Linux
```

```powershell
.\bin\start.ps1 check -PhpPath D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe   # Windows
```

### 6.8 Composer 脚本

```bash
composer start          # php start.php start
composer stop           # php start.php stop
composer restart        # php start.php restart -d
composer reload         # php start.php reload
composer svc-status     # php start.php status
composer check          # php start.php check
composer env-init       # php start.php env:init
composer analyse        # phpstan analyse --memory-limit=512M
composer baseline       # phpstan analyse --memory-limit=512M --generate-baseline
composer test           # phpunit
composer test:e2e       # php tests/e2e_check.php
composer test:client-e2e # php client/tests/E2E/ClientE2E.php（客户端 SDK 同口径 15 用例）
```

---

## 7. 配置参考（.env）

### 7.1 加载优先级

```
代码内默认值
  < .env
  < .env.{APP_ENV}          （如 .env.prod）
  < .env.local
  < .env.{APP_ENV}.local
  < 真实系统环境变量         （容器 / CI 注入，最高优先，不会被 .env 覆盖）
```

**取值约定**：环境相关项与敏感项走 `.env`；**结构性配置**（扩展清单、定时任务注册表、
业务动作清单、指令白名单、指标名列表）保留在 `config/*.php` 代码中 —— 它们不随部署环境变化。

读取统一走 `GatewayPush\Common\Env` 的类型化方法：`Env::str()` / `int()` / `float()` / `bool()` / `list()`。
空值语义为「键存在则返回空串」，**不回落默认值**。

> 项目启动时 `Env::load()` **必须先于** `require config/*.php` 执行。
> 顺序颠倒会让 `config` 里的 `Env::str()` 全部读到默认值（静默失败）。

### 7.2 变量清单

#### 应用基础

| 变量 | 默认值 | 说明 |
|---|---|---|
| `APP_NAME` | `gateway-push` | 应用名 |
| `APP_ENV` | `dev` | `dev` / `test` / `prod`，决定额外加载哪个环境文件 |
| `APP_DEBUG` | `true` | 生产建议 `false` |
| `APP_TIMEZONE` | `Asia/Shanghai` | 时区 |

#### 日志

| 变量 | 默认值 | 说明 |
|---|---|---|
| `LOG_LEVEL` | `debug` | `debug` / `info` / `warn` / `error` |
| `LOG_STDOUT` | `true` | 是否同时输出到控制台，生产建议 `false` |
| `LOG_KEEP_DAYS` | `30` | 日志保留天数，超期由定时任务清理 |

日志文件命名：`runtime/logs/{level}_{YYYY-MM-DD}.log`。

#### Redis

| 变量 | 默认值 | 说明 |
|---|---|---|
| `REDIS_HOST` | `127.0.0.1` | |
| `REDIS_PORT` | `6379` | |
| `REDIS_PASSWORD` | 空 | |
| `REDIS_DB` | `0` | **多套环境共用同一实例时务必区分** |
| `REDIS_TIMEOUT` | `2.0` | 连接超时（秒） |
| `REDIS_POOL_SIZE` | `8` | 每进程异步连接数 |
| `REDIS_PREFIX` | `gwpush:` | 全局键前缀 |

#### 密钥

| 变量 | 默认值 | 说明 |
|---|---|---|
| `INTERNAL_SECRET` | 空 | Register / Gateway / BusinessWorker **三方内部通信密钥，必须一致**。留空则回退复用 `AUTH_SECRET`。生产建议独立配置 |
| `AUTH_SECRET` | 空 | 业务鉴权密钥。由 `env:init` 生成 64 位十六进制值 |

#### 连接鉴权

| 变量 | 默认值 | 说明 |
|---|---|---|
| `AUTH_ENABLE` | `true` | 关闭后连接自动视为已鉴权 |
| `AUTH_MODE` | `hmac` | `hmac` 自包含 Token / `store` Redis 反查 |
| `AUTH_SIGN_ENABLE` | `true` | 是否校验报文签名（UDP 强烈建议开启） |
| `AUTH_TOKEN_TTL` | `7200` | Token 默认有效期（秒） |
| `AUTH_CLOCK_SKEW` | `300` | 允许时钟偏移（秒），同时用于报文时间戳校验 |
| `AUTH_BIND_DEVICE` | `true` | 校验 `uid ↔ device_id` 绑定（首个绑定者胜出） |
| `AUTH_FAIL_CLOSE` | `true` | 鉴权失败立即断开连接（先下发 4003 报文，`CLOSE_DELAY`=0.1s 后再断开，见 9.2） |
| `AUTH_TIMEOUT` | `15` | 建连后 N 秒未鉴权则断开 |

#### 会话

| 变量 | 默认值 | 说明 |
|---|---|---|
| `SESSION_TTL` | `7200` | 会话 Redis 过期时间（秒） |
| `SESSION_HEARTBEAT_TTL` | `90` | 心跳超时阈值，需与 `HB_SESSION_TIMEOUT` 一致 |
| `SESSION_RESTORE` | `true` | 断线重连自动恢复历史会话 |

#### Register / WebSocket / UDP

| 变量 | 默认值 | 说明 |
|---|---|---|
| `REGISTER_ENABLE` | `true` | |
| `REGISTER_LISTEN` | `127.0.0.1:1238` | Register 监听地址。集群部署改为 `0.0.0.0:1238` 或内网 IP |
| `REGISTER_ADDRESS` | 空 | BusinessWorker 连接的注册中心地址；留空复用 `REGISTER_LISTEN` |
| `WS_ENABLE` | `true` | |
| `WS_LISTEN` | `websocket://0.0.0.0:8282` | |
| `WS_COUNT` | `4` | WS 网关进程数（Windows 强制 1） |
| `WS_LAN_IP` | `127.0.0.1` | 集群部署改为本机内网 IP |
| `WS_START_PORT` | `2300` | 内部通信端口起始值，多机部署需错开 |
| `SSL_ENABLE` | `false` | 生产 WSS 必需 |
| `SSL_CERT` / `SSL_PK` | 空 | 证书与私钥路径 |
| `UDP_ENABLE` | `true` | |
| `UDP_LISTEN` | `udp://0.0.0.0:8283` | |
| `UDP_COUNT` | `2` | UDP 网关进程数（Windows 强制 1） |
| `UDP_MAX_PACKET_SIZE` | `8192` | 单包上限，超出直接丢弃 |

#### UDP 入站 / 出站队列

| 变量 | 默认值 | 说明 |
|---|---|---|
| `UDP_QUEUE_ENABLE` | `true` | UDP 网关 → 业务进程 的解耦队列开关 |
| `UDP_QUEUE_KEY` | `queue:udp:in` | 入站队列 key（与 `gateway.udp.queue.key` **同源**） |
| `UDP_QUEUE_MAX_LEN` | `10000` | 队列长度上限，溢出告警 |
| `UDP_QUEUE_BATCH` | `100` | 业务侧单次批量消费条数 |
| `UDP_QUEUE_INTERVAL` | `0.05` | 业务侧消费周期（秒） |
| `UDP_OUT_QUEUE_ENABLE` | `true` | 业务进程 → UDP 网关 的出站队列开关 |
| `UDP_OUT_QUEUE_KEY` | `queue:udp:out` | 出站队列 key |
| `UDP_OUT_QUEUE_BATCH` | `200` | 单次原子弹出条数（Lua 取批） |
| `UDP_OUT_QUEUE_INTERVAL` | `0.05` | 出站消费周期（秒） |

#### 心跳

| 变量 | 默认值 | 说明 |
|---|---|---|
| `HB_PING_INTERVAL` | `25` | Gateway 原生主动心跳间隔（秒） |
| `HB_PING_NOT_RESPONSE_LIMIT` | `2` | 连续 N 次无上行数据则断开 |
| `HB_SESSION_TIMEOUT` | `90` | 业务层会话心跳超时阈值（秒） |
| `HB_CHECK_INTERVAL` | `10` | 死连接巡检周期（秒） |

> 实际容忍的无上行数据时长约为 `HB_PING_INTERVAL × HB_PING_NOT_RESPONSE_LIMIT`。

#### 推送

| 变量 | 默认值 | 说明 |
|---|---|---|
| `PUSH_ENABLE` | `true` | |
| `PUSH_OFFLINE_MODE` | `queue` | `drop` 直接丢弃 / `queue` 缓存待重连补投 |
| `PUSH_OFFLINE_TTL` | `86400` | 离线消息保留时长（秒） |
| `PUSH_OFFLINE_MAX` | `100` | 单用户离线消息条数上限，超出丢弃最旧 |
| `PUSH_REPLAY_BATCH` | `50` | 重连补投单批条数 |
| `PUSH_IDEMPOTENT` | `true` | 按 `msg_id` 去重 |
| `PUSH_IDEMPOTENT_TTL` | `600` | 去重窗口（秒） |
| `PUSH_PAYLOAD_MAX` | `4096` | 单条数据体上限（字节），超出拒绝 |
| `PUSH_QUEUE_ENABLE` | `true` | 推送出站队列开关 |
| `PUSH_QUEUE_KEY` | `queue:push:out` | 推送队列 key |
| `PUSH_QUEUE_BATCH` | `200` | 单次原子弹出条数 |
| `PUSH_QUEUE_INTERVAL` | `0.05` | 消费周期（秒） |
| `PUSH_QUEUE_MAX_LEN` | `10000` | 积压告警阈值 |

#### HTTP 接口

| 变量 | 默认值 | 说明 |
|---|---|---|
| `API_ENABLE` | `true` | |
| `API_LISTEN` | `http://127.0.0.1:8290` | 生产应仅监听内网地址或置于反向代理之后 |
| `API_SECRET` | 空 | 接口密钥；留空回退复用 `AUTH_SECRET` |
| `API_SIGN_TTL` | `300` | 请求时间戳有效窗口（秒），防重放；0 = 关闭校验 |
| `API_RATE_LIMIT` | `600` | 单 IP 每分钟请求上限；0 = 不限 |
| `API_BODY_MAX` | `65536` | 请求体上限（字节） |

#### 监控面板

| 变量 | 默认值 | 说明 |
|---|---|---|
| `DASHBOARD_ENABLE` | `true` | |
| `DASHBOARD_LISTEN` | `http://127.0.0.1:8291` | **默认仅本机**，运维数据不应直接暴露公网 |
| `DASHBOARD_REFRESH` | `5` | 页面轮询间隔（秒），0 = 关闭自动刷新 |

#### 限流

算法为**令牌桶**：`rate` = 令牌补充速率（条/秒，即长期平均上限），
`burst` = 桶容量（允许的瞬时突发条数），`burst` 不得小于 `rate`（小于时按 `rate` 兜底并告警）。
`rate = 0` 表示关闭该维度。

| 变量 | 默认值 | 所在层 |
|---|---|---|
| `RATE_LIMIT_ENABLE` | `true` | 总开关 |
| `RATE_LIMIT_CONN_RATE` / `_BURST` | `20` / `40` | L2 业务层，每连接 |
| `RATE_LIMIT_UID_RATE` / `_BURST` | `50` / `100` | L2 业务层，每用户 |
| `RATE_LIMIT_IP_RATE` / `_BURST` | `200` / `400` | **L1 网关层，每 IP（仅 UDP 网关）** |
| `RATE_LIMIT_PING_RATE` / `_BURST` | `5` / `10` | 心跳指令独立配额（替代 conn 维度，更严） |
| `RATE_LIMIT_CLOSE` | `false` | 超限是否断开连接（WS 生效；UDP 恒定静默丢弃） |
| `RATE_LIMIT_NOTIFY` | `true` | 超限是否回错误报文（UDP 恒定不回，避免反射放大） |
| `RATE_LIMIT_MEM_MAX` | `20000` | L1 内存桶数量上限，超出按最久未用淘汰一半 |

#### 业务动作 / 订阅 / 监控

| 变量 | 默认值 | 说明 |
|---|---|---|
| `ACTION_TIMEOUT` | `5` | 动作回执超时（秒），超时回 `5000` 并记 `action_timeout`；0 = 关闭保护 |
| `ACTION_REPORT_TTL` | `86400` | `report` 动作统计键保留时长（秒） |
| `SUBSCRIBE_ENABLE` | `true` | 订阅功能总开关 |
| `SUBSCRIBE_TTL` | `0` | 订阅关系过期时间（秒），0 = 永不过期 |
| `SUBSCRIBE_MAX_TOPICS` | `100` | 单用户订阅主题数上限，0 = 不限 |
| `MONITOR_ENABLE` | `true` | 指标采集开关 |
| `MONITOR_INTERVAL` | `60` | 指标上报周期（秒） |
| `MONITOR_TTL` | `600` | 指标数据保留时长（秒） |

---

## 8. 通信协议规范

### 8.1 统一报文结构

WebSocket 与 UDP 使用**完全相同**的报文结构（单包一个 JSON 对象，UTF-8）：

```json
{
  "cmd":       "data",
  "seq":       "c-0001",
  "ts":        1690000000,
  "uid":       "1001",
  "device_id": "dev-001",
  "token":     "eyJ1aWQiOiIxMDAxIiwi...",
  "sign":      "3f2a9c...",
  "data":      { "action": "echo", "params": { "hello": "world" } }
}
```

| 字段 | 类型 | 说明 |
|---|---|---|
| `cmd` | string | **必填**。指令名：`auth` / `ping` / `data` / `ack`。（服务端下发另有 `pong` / `push` / `error`） |
| `seq` | string | 客户端消息序号，服务端在回执中原样带回，客户端据此关联请求 |
| `ts` | int | 客户端时间戳（秒）。与服务器偏差超过 `AUTH_CLOCK_SKEW` 时判定 `4002` |
| `uid` | string | 用户 ID。**不参与签名**，因此 UDP 侧不可信（见 8.5） |
| `device_id` | string | 设备 ID。**参与签名** |
| `token` | string | 鉴权 Token。**参与签名**，是报文内唯一可信的身份来源 |
| `sign` | string | 报文签名，算法见 8.2 |
| `data` | object | 业务数据体。`data` 指令下形如 `{"action":"<动作名>","params":{...}}` |

解码时缺失的字段会自动补默认值（`seq`/`uid`/`device_id`/`token`/`sign` → `""`，
`ts` → `0`，`data` → `[]`）；`data` 若不是对象则被包装为 `{"value": <原值>}`。
报文长度上限 `65535` 字节。

### 8.2 签名算法

```
base = cmd | seq | ts | device_id | token | canonicalize(data)
sign = hex(hmac_sha256(base, AUTH_SECRET))
```

其中 `canonicalize(data)` 为「**递归按键名升序排列 + 紧凑 JSON 编码**」：

- 递归对每一层的键做 `ksort`（字符串键升序，与客户端语言的字典序一致）
- JSON 编码**不转义 Unicode**、**不转义斜杠**（等价 `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`）
- 无多余空格（紧凑格式）

示例：

```php
// data = {"b":2, "a":{"d":4, "c":3}}
canonicalize(data) === '{"a":{"c":3,"d":4},"b":2}'

// 六段以 | 连接
base = 'data|c-0001|1690000000|dev-001|eyJ1aWQiOi...|{"a":{"c":3,"d":4},"b":2}'
sign = hash_hmac('sha256', base, $secret);   // 小写十六进制，64 字符
```

**客户端实现要点（互操作关键）：**

1. `canonicalize` 必须**递归**排序，只排顶层是不够的（嵌套对象同样要排）。
2. JSON 编码器必须**不转义**非 ASCII 字符与 `/`。若客户端默认转义（如 `\u4e2d` 或 `\/`），
   必须显式关闭，否则签名永远对不上。
3. `ts` 在基串中按**十进制整数字符串**参与拼接；`seq`、`device_id`、`token` 同样按字符串拼接。
4. `uid` **不在基串内**。
5. 比较使用 `hash_equals`（服务端），客户端只需生成正确值。

JavaScript 参考实现：

```js
function canonicalize(v) {
  if (v === null || typeof v !== 'object' || Array.isArray(v)) {
    return JSON.stringify(v);
  }
  const keys = Object.keys(v).sort();
  return '{' + keys.map(k => JSON.stringify(k) + ':' + canonicalize(v[k])).join(',') + '}';
}

async function sign(packet, secret) {
  const base = [
    packet.cmd, packet.seq, String(packet.ts),
    packet.device_id, packet.token,
    canonicalize(packet.data || {})
  ].join('|');
  const key = await crypto.subtle.importKey(
    'raw', new TextEncoder().encode(secret),
    { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']
  );
  const buf = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(base));
  return [...new Uint8Array(buf)].map(b => b.toString(16).padStart(2, '0')).join('');
}
```

**校验时机**：`AUTH_SIGN_ENABLE=true`（默认）时，UDP 网关在收包后立刻做「时间戳 + 签名」
纯本地校验，不通过直接回错误码且不入队；WebSocket 侧默认不强制逐条验签（TCP 连接本身已鉴权）。

### 8.3 Token 结构

自包含、无状态（便于集群横向扩展）：

```
token = base64url(payload) . '.' . base64url(hmac_sha256(base64url(payload), AUTH_SECRET))
payload = {"uid":"1001","device_id":"dev-001","iat":1690000000,"exp":1690007200,"nonce":"..."}
```

校验分两段，兼顾性能与安全：

| 阶段 | 方法 | 是否 IO | 内容 |
|---|---|---|---|
| 1 | `Auth::verifyLocal()` | 无（纯计算） | 签名校验（`hash_equals` 防时序攻击）+ 过期时间 + 签发时间合理性 |
| 2 | `Auth::isRevoked()` | Redis | 查询撤销名单 `auth:revoked:{fingerprint}` |
| 3 | `Auth::checkDeviceBind()` | Redis | 校验 `uid ↔ device_id` 绑定（首次绑定者胜出） |

> 撤销名单的键是 **Token 的 SHA-256 前 32 位指纹**，明文 Token 不落盘。

### 8.4 指令（cmd）清单

| cmd | 方向 | 需要鉴权 | 说明 | 回执 |
|---|---|---|---|---|
| `auth` | 上行 | 否（白名单） | 提交 Token 完成鉴权 | `ack`（含 `uid` / `device_id` / `protocol` / `reconnected`） |
| `ping` | 上行 | 否（白名单） | 心跳 | `pong`（回带 `seq`） |
| `data` | 上行 | 是 | 业务动作入口，`data.action` 指定动作名 | `ack` 或 `error`（由动作决定，见 8.7） |
| `ack` | 上行 | 是 | 客户端对下行 `push` 报文的确认 | 无（仅记 `push_ack` 指标） |
| `pong` | 上行 | 否 | 对服务端原生心跳的响应 | 无 |
| `pong` | 下行 | — | 对客户端 `ping` 的响应 | — |
| `push` | 下行 | — | 服务端定向推送的业务报文 | 客户端应回 `ack`（服务端据此计 `push_ack`） |
| `error` | 下行 | — | 错误报文，带 `data.code` / `data.msg` 与 `ref`（触发指令） | — |

**鉴权前白名单**：`AUTH_ENABLE=true` 时，未鉴权连接只允许 `auth` 与 `ping`
（定义于 `config/app.php` 的 `auth.allow_cmds`），其他指令一律回 `4003`。

### 8.5 身份可信来源（安全关键）

两条通道的身份来源不同，**不可混用**：

| 通道 | 可信来源 | 原因 |
|---|---|---|
| WebSocket | 鉴权时写入的**进程内映射** `$authed[clientId] = uid` | 报文里的 `uid` 由客户端自填且**不在签名覆盖范围内**，采信即等于允许冒充 |
| UDP | 报文 **Token 载荷**中的 `uid` | UDP 无连接实体、无鉴权映射。签名基串不含 `uid`（可篡改），但 `token` 参与签名且其载荷由服务端密钥 HMAC 保护 |

Token 不可信时返回空串（**拒绝放行**），而不是回退到报文中的 `uid`。
仅在鉴权整体关闭（`AUTH_ENABLE=false`）且报文确实不带 Token 时才回退报文字段。

> 这条规则的实际作用：UDP 报文即使被篡改 `uid`，也无法冒充他人 —— 因为动作级鉴权
> 检查的是 Token 解析出的 `uid`，而 Token 无法伪造。

### 8.6 错误码

| 码 | 常量 | 文案 | 典型触发场景 |
|---|---|---|---|
| `0` | `CODE_OK` | ok | 成功 |
| `4000` | `CODE_BAD_PACKET` | 报文格式错误 | 空报文 / 非 JSON / 缺 `cmd` / 长度超限 |
| `4001` | `CODE_BAD_SIGN` | 签名校验失败 | 签名不匹配 / 缺 `sign` / 服务端未配置密钥 |
| `4002` | `CODE_BAD_TIMESTAMP` | 时间戳偏差超出允许范围 | 客户端与服务器时钟偏差 > `AUTH_CLOCK_SKEW` |
| `4003` | `CODE_UNAUTHORIZED` | 连接未鉴权 | 未鉴权就发业务指令 / 鉴权超时 |
| `4004` | `CODE_AUTH_FAILED` | 鉴权失败 | Token 结构非法 / 签名错 / 被撤销 / 设备不匹配 |
| `4005` | `CODE_TOKEN_EXPIRED` | Token 已过期 | `exp` 已过 |
| `4006` | `CODE_UNKNOWN_CMD` | 未知指令 | 指令或 `data.action` 未注册 |
| `4007` | `CODE_PARAM_MISSING` | 缺少必要参数 | 参数校验失败（缺失 / 类型错 / 越界） |
| `4008` | `CODE_RATE_LIMIT` | 请求频率超限 | L2 限流拒绝（WS 回错误；UDP 静默丢弃） |
| `5000` | `CODE_SERVER_ERROR` | 服务端内部错误 | 处理器抛异常 / 动作回执超时 |

**HTTP 接口的业务码独立**（`src/Api/Bootstrap.php`）：

| 码 | HTTP | 含义 |
|---|---|---|
| `0` | 200 | 成功 |
| `4000` | 400 | 参数错误 |
| `4001` | 401 | 验签失败 / 缺少 `X-Timestamp` 或 `X-Sign` |
| `4002` | 401 | 时间戳超出允许窗口 |
| `4004` | 404 | 接口不存在 |
| `4029` | 429 | 请求频率超限 |
| `5000` | 500 | 服务端内部错误 |

### 8.7 业务动作（data.action）

上行格式：

```json
{ "cmd": "data", "seq": "c-0002", "ts": 1690000000, "device_id": "dev-001", "token": "...", "sign": "...",
  "data": { "action": "report", "params": { "topic": "etc.pass", "count": 3 } } }
```

内置 7 个动作（声明于 `config/actions.php`）：

| 动作 | 参数 | 回执方式 | 回执内容 |
|---|---|---|---|
| `echo` | `*`（原样透传） | WS `sync` / UDP `sync` | `{action, channel, protocol, params, at}` |
| `session` | 无 | 两通道 `sync` | `{action, client_id, uid, device_id, protocol, channel, online, connect_at, online_secs}` |
| `report` | `topic`(必填, 1~64 字符, 字符集 `[A-Za-z0-9_:.\-]`)、`count`(int, 1~10000, 默认 1)、`value`(json) | WS `sync` / **UDP `none`（静默）** | `{action, topic, accepted, total, at}` |
| `subscribe` | `topic`(同上) | 两通道 `sync` | `{action, uid, topic, subscribers, at}` |
| `unsubscribe` | `topic`(同上) | 两通道 `sync` | `{action, uid, topic, subscribers, at}` |
| `topics` | 无 | 两通道 `sync` | `{action, uid, topics[], count, at}` |
| `notify` | `value`(json)、`msg_id`(string, ≤64)、`offline_mode`(`""`/`drop`/`queue`) | 两通道 `sync` | `{action, target, msg_id, queued, at}` |

**关于 `report` 的 UDP 静默**：UDP 上报通常高频且客户端不关心单条结果，回执会造成
双向流量放大。它的处理器代码**不含任何通道判断** —— 是否下发完全由 `config/actions.php`
的 `reply` 声明表达。这是「同一处理器、不同通道不同回执策略」的标准示范。

**动作级参数校验**（`ParamValidator`，白名单语义）：

| 支持类型 | `string` / `int` / `float` / `bool` / `array` / `json` |
|---|---|
| 支持约束 | `required` / `default` / `enum` / `min` / `max` / `min_len` / `max_len` / `pattern` / `trim` |

> - **未声明在规则里的入参一律丢弃**，处理器拿到的一定是已归一化的正确类型值。
> - 缺少 `data.action` → `4007`；动作名未注册 → `4006`；参数不合规 → `4007`。
> - 动作的回执超时（默认 5 秒，`ACTION_TIMEOUT`）到时未回执 → 兜底回 `5000` 并记 `action_timeout`。

---

## 9. 数据流转说明

本节是全文重点。所有链路都按「步骤 → 涉及位置」组织，便于对照源码。

### 9.1 WebSocket 上行链路（客户端 → 服务端）

```
客户端                Gateway(GW-WS)            BusinessWorker
  │                        │                          │
  │── TCP 建连 ───────────>│                          │
  │                        │── onConnect(clientId) ──>│  记录连接、启动鉴权超时定时器
  │                        │                          │
  │── {"cmd":"auth",...} ─>│                          │
  │                        │── 转发（内部 TCP） ──────>│  onMessage
  │                        │                          │   ① 解码 Message::decode
  │                        │                          │   ② L2 限流 guardRate
  │                        │                          │   ③ 鉴权白名单拦截
  │                        │                          │   ④ Router::command('auth')
  │                        │                          │   ⑤ handleAuth
  │                        │                          │      ├ Auth::verifyLocal   （纯计算）
  │                        │                          │      ├ Auth::isRevoked     （Redis）
  │                        │                          │      ├ Auth::checkDeviceBind（Redis）
  │                        │                          │      ├ Session::restore    （按 device_id 找回）
  │                        │                          │      ├ 踢掉同设备旧连接
  │                        │                          │      ├ Session::bind       （写会话 + 索引）
  │                        │                          │      ├ Gateway::bindUid    （原生 uid 路由）
  │                        │                          │      └ Push::replayOffline （补投离线消息）
  │<── {"cmd":"ack",...} ──│<── Gateway::sendToClient ─│
```

**逐步骤说明**（`src/Business/Bootstrap.php`）：

| 步骤 | 方法 | 关键行为 |
|---|---|---|
| 1 | `onConnect($clientId)` | 记 `conn_open`；鉴权开启时注册**鉴权超时定时器**（`AUTH_TIMEOUT` 秒后仍未鉴权则断开并回 `4003`） |
| 2 | `onMessage($clientId, $raw)` | 记 `msg_in`；已鉴权则 `Session::touch()` 刷新心跳（**任意上行数据都算活跃**）；`Message::decode()` 解码，失败回 `4000` |
| 3 | `guardRate()` | L2 限流：心跳走 `ping` 维度、业务走 `conn` 维度，已鉴权再叠加 `uid` 维度；多个桶**在一次 Redis 往返内原子判定**（任一不足即整单拒绝且均不扣减，防配额泄漏）；超限回 `4008` |
| 4 | `dispatch()` | 鉴权白名单校验（未鉴权且非 `auth`/`ping` → `4003`）；`Router::command($cmd)` 查表；未注册 → `4006`；处理器异常统一兜底为 `5000` |
| 5 | `handleAuth()` | `verifyLocal`（同步）→ `isRevoked`（异步）→ `checkDeviceBind`（异步）→ `bindSession` |
| 6 | `bindSession()` | `Session::restore()` 按 `device_id` 找历史会话：命中且 `client_id` 不同 → **踢掉旧连接**（保证设备唯一在线）；`Session::bind()` 写会话；WebSocket 额外 `Gateway::bindUid()` 使 `sendToUid` 可用；回 `ack`；最后补投离线消息 |

> **为什么要踢旧连接**：单对一定向推送的前提是「一个设备只有一条有效连接」。
> 不做这件事，推送会同时投到多条连接，客户端出现重复消息。

### 9.2 WebSocket 下行链路（服务端 → 客户端）

```
BusinessWorker                                   Gateway(GW-WS)          客户端
  │                                                    │                    │
  │ Bootstrap::send($clientId, $packet)                 │                    │
  │   ├ 若 clientId 以 udp: 开头 → Push::sendToUdpClient │（走 9.5 的 UDP 出站）
  │   └ 否则 Gateway::sendToClient(clientId, json) ────>│── WebSocket 帧 ───>│
  │                                                    │                    │
  │ Gateway::sendToUid($uid, json)  ← 原生 uid 路由      │                    │
  │   （跨进程经 Register 转发到目标连接所在的网关进程）    │                    │
```

| 场景 | 使用的通道 | 说明 |
|---|---|---|
| 指令回执（`ack` / `pong` / `error`） | `Gateway::sendToClient` | 精确到连接 |
| 业务动作回执 | `ActionContext::reply()` → `sender` → `Bootstrap::respond()` | 由动作执行器统一构造 |
| 定向推送（WS 目标） | `Gateway::sendToClient`（`via=session`）或 `Gateway::sendToUid`（`via=native`） | 见 9.6 |
| 主动断开 | 先 `Gateway::sendToClient($errPacket)`，延迟 `Bootstrap::CLOSE_DELAY`（0.1s）后再 `Gateway::closeClient($clientId)` | **刻意不用 close 的「附带消息」通道** —— workerman 5.x 的 `TcpConnection::close()` 在 `send()` 之后若发送缓冲为空会立即 `destroy()` → `fclose()`，实测 30 轮命中 6 轮以 **RST** 收场，已写入的报文被一并丢弃（客户端读到 0 字节）。拆成两步后由 TCP 顺序性保证「报文先到、FIN 后到」 |

### 9.3 UDP 上行链路（客户端 → 服务端 → 业务进程）

UDP 是**异步解耦**的：网关收包后立即 ack，业务处理由业务进程消费队列完成。

```
客户端          UDP网关(GW-UDP)                        Redis              BusinessWorker
  │                    │                                 │                      │
  │── UDP 数据报 ─────>│                                 │                      │
  │                    │ ① UdpProtocol::decode           │                      │
  │                    │    （内部异常绝不外抛）           │                      │
  │                    │ ② L1 限流 checkMemory(ip)       │                      │
  │                    │    超限 → 静默丢弃（不回错误）    │                      │
  │                    │ ③ Message::verify（纯本地）      │                      │
  │                    │    失败 → 回 error 报文          │                      │
  │                    │ ④ rPush queue:udp:in ──────────>│                      │
  │<── ack（立即）─────│    {"client_id","packet",...}   │                      │
  │                    │                                 │                      │
  │                    │                                 │<── popBatch(Lua) ────│ ⑤ 定时任务
  │                    │                                 │     100 条/次        │    udp-queue-consume
  │                    │                                 │                      │ ⑥ 解析 job
  │                    │                                 │                      │ ⑦ L2 限流（conn/uid）
  │                    │                                 │                      │    超限 → 静默丢弃
  │                    │                                 │                      │ ⑧ Session::exists 探测
  │                    │                                 │<─────────────────────│    （判断会话是否重建）
  │                    │                                 │                      │ ⑨ Session::bind
  │                    │                                 │                      │ ⑩ 仅放行 data / ack
  │                    │                                 │                      │    → Router → ActionRunner
  │<═══════════════════ 业务回执经出站队列（见 9.4）══════════════════════════════│
```

**为什么网关必须立即 ack**：UDP 无连接、允许丢包；网关的 ack 只表示「**传输层收到包**」，
业务结果由服务端**另行推送**。这与「业务层 ack」是两个层级，客户端必须区分：

| 层级 | 触发点 | 报文特征 |
|---|---|---|
| 传输层 ack | 网关收包即回 | `{"cmd":"ack","seq":"...","data":[]}` —— **`data` 为空且无 `action` 字段** |
| 业务层回执 | 动作执行完成 | 带 `data.action`（如 `{"cmd":"ack","data":{"action":"echo",...}}`） |

> 判定方式：先 `isset($data['action'])` 判断层级。

**UDP 的业务指令过滤**：业务进程只放行 `data` 与 `ack` 两类指令。
`auth` 所需的会话绑定已在 ⑨ 完成；`ping` 已由网关即时回执 —— 再走一遍会造成重复回执与重复补投。

**限流的两层分工**：

| 层 | 位置 | 维度 | 实现 | 超限处置 |
|---|---|---|---|---|
| L1 | UDP 网关 `onUdpMessage`，**验签前** | 每来源 IP | 进程内内存桶，**零 IO** | **静默丢弃**（不回错误，避免反射放大） |
| L2 | 业务进程 `handleUdpJob` | 每虚拟连接 `udp:ip:port` + 每 uid | Redis 令牌桶 | **静默丢弃** |

> L1 必须在验签之前、且不用 Redis —— 洪水场景下限流器自身发起异步 IO 等于放大攻击面，
> 且异步回调会打乱 UDP 的同步 ack 时序。

### 9.4 UDP 出站链路（业务进程 → 网关 → 客户端）

UDP 的 `clientId` 形如 `udp:{ip}:{port}`，**不存在于 GatewayWorker 的连接表内**，
因此 `Gateway::sendToClient()` 对它**静默无效**。出站必须走「队列 + 网关进程 sendto」闭环：

```
BusinessWorker                     Redis                      UDP网关(GW-UDP)          客户端
  │                                  │                              │                    │
  │ Push::deliverUdp()               │                              │                    │
  │   rPush queue:udp:out ──────────>│                              │                    │
  │   {"client_id","frame","uid",    │                              │                    │
  │    "msg_id","queued_at"}         │                              │                    │
  │                                  │<── popBatch(Lua, 200/次) ────│ 定时器 0.05s
  │                                  │                              │ 解析任务
  │                                  │                              │ 解析 client_id → ip:port
  │                                  │                              │ （IPv6 自动补方括号）
  │                                  │                              │ stream_socket_sendto ─>│
  │                                  │                              │ 记 udp_out 指标        │
```

| 关键点 | 说明 |
|---|---|
| 复用网关自身 socket | 用 `stream_socket_sendto` 在 unconnected socket 上指定目标地址，**无建连竞态、无额外 fd 开销** |
| 多进程并发安全 | 取批由 Lua 脚本保证原子性，`UDP_COUNT > 1` 不会重复消费 |
| 业务动作的 UDP 回执 | 走**同一条**出站通道（`Push::sendToUdpClient()`），因此 `Bootstrap::send()` 的调用方无需感知协议差异 |
| 兼容性处理 | workerman 5.x 用 `getMainSocket()`，4.x 用 `getSocket()`，代码按 `method_exists` 择优取值 |

> **注意**：UDP 出站是「**尽力而为**」。`stream_socket_sendto` 返回成功不代表对端收到 ——
> UDP 本身无投递保证，应用层需要自己的 ack 机制（客户端收到 `push` 后回 `ack`，服务端记 `push_ack`）。

### 9.5 UDP 报文限流的完整链路

```
收包
 ├─ L1 checkMemory(DIM_IP, ip)              内存桶，零 IO
 │    超限 → msg_fail / rate_limit_hit / rate_limit_ip 指标 + 采样日志 + 静默丢弃
 │           （日志按维度采样，每秒最多一条 —— 被限流的流量本身就是洪水）
 ├─ Message::verify(packet, auth)
 │    失败 → 回 error(4001/4002) + warn 日志 + 不入队
 ├─ rPush queue:udp:in  → 队列积压超 max_len 时告警
 └─ 回 ack(seq)

（业务进程侧）
 popBatch → handleUdpJob
 ├─ L2 guardUdpRate：conn/uid（心跳走 ping）桶 → 超限静默丢弃 + 采样日志
 ├─ Session::exists → bind（不存在则视为重连，触发离线补投）
 └─ cmd ∈ {data, ack} → Router → ActionRunner
```

### 9.6 定向推送完整链路

**所有推送只有一个出口**：`Push::dispatch()`。这样指标统计、幂等去重、离线缓存三条
横切逻辑各只有一处实现。

#### 触发入口（三选一）

| 入口 | 用法 | 说明 |
|---|---|---|
| HTTP 接口 | `POST http://127.0.0.1:8290/push` | 外部系统调用，需 HMAC 验签 |
| Redis 队列 | `RPUSH gwpush:queue:push:out '<job-json>'` | 外部系统可直接写队列，绕开 HTTP 层 |
| 运维命令 | `php start.php push <类型> <目标> ...` | 调试与手工触发 |
| 业务动作 | `data.action = notify` | 客户端请求服务端向**本人**推送 |

> 四个入口**都只是入队**，真实投递统一由业务进程消费队列后执行。

#### 投递流程

```
queue:push:out
      │ 定时任务 push-queue-consume（每 0.05s，Lua 原子取批 200 条）
      ▼
Push::dispatch($job)
      ├─ ① 参数归一化：target_type（非法值回落 uid）、target、payload、msg_id、offline_mode
      ├─ ② 数据体限额：strlen(json_encode(payload)) > PUSH_PAYLOAD_MAX(4096) → push_fail + 拒绝
      ├─ ③ 幂等去重：msg_id 非空且开关开启 → setNxEx push:dedup:{md5(msg_id)}，TTL 600
      │      已存在 → push_dedup 指标 + 跳过
      └─ ④ resolveTargets(target_type, target)  →  定位在线连接集合
             │
             ├─ uid    → Session::findByUid（Redis uid 索引，覆盖 WS 与 UDP）
             │             索引缺失 → 回退 Gateway::isUidOnline 原生路由
             ├─ device → Session::findByDevice（device:client:{device_id}）
             └─ client → Session::get（直接按 clientId）
             │
             ├─ 无目标且原因 = offline → handleOffline()
             │     offline_mode = drop  → push_offline 指标，丢弃
             │     offline_mode = queue → RPUSH push:offline:{uid} + EXPIRE offline_ttl
             │                           条数超 offline_max → LTRIM 保留最新 N 条并告警
             └─ 有目标 → deliverAll()
                  构造下行报文：{cmd:"push", seq:msg_id|新生成, data:payload,
                                msg_id, source, offline:0, pushed_at}
                  │
                  ├─ channel = ws  → Gateway::sendToUid($uid, $json)   （via=native）
                  │                 或 Gateway::sendToClient($clientId, $json)（via=session）
                  │                 → push_out 指标 + info 日志
                  └─ channel = udp → deliverUdp() → RPUSH queue:udp:out
                                     → udp_out_queued 指标
                                     → 网关 sendto（见 9.4）
```

**在线判定规则**（`Push::isOnline()`）：

| 通道 | 判据 |
|---|---|
| WebSocket | `Gateway::isOnline($clientId)` —— 以 Gateway 连接表为准，准实时 |
| UDP | 会话存在且 `offline_at` 为空 —— UDP 无连接实体，只能靠会话标记 |

#### 下行 `push` 报文结构

```json
{
  "cmd": "push",
  "seq": "msg-1",
  "ts": 1690000000,
  "msg_id": "msg-1",
  "source": "http",
  "offline": 0,
  "pushed_at": 1690000000,
  "data": { "title": "hi", "body": "hello" }
}
```

| 字段 | 说明 |
|---|---|
| `seq` | 等于 `msg_id`；调用方未提供时服务端生成 `p-{16位随机十六进制}` |
| `offline` | `0` 实时投递 / `1` 重连补投 |
| `source` | 来源标识：`http` / `cli` / `direct` / `action.notify` / 外部写队列时的自定义值 |

客户端收到后应回 `{"cmd":"ack","seq":"<msg_id>"}`，服务端记 `push_ack` 指标。

### 9.7 离线消息与重连补投

```
目标离线（offline_mode = queue）
   RPUSH push:offline:{uid}  {"payload":{...},"msg_id":"...","source":"...","offline_at":ts}
   EXPIRE push:offline:{uid} PUSH_OFFLINE_TTL(86400)
   若 LLEN > PUSH_OFFLINE_MAX(100) → LTRIM 保留最新 100 条 + 告警

设备重连
   ┌── WebSocket：鉴权成功后 → Push::replayOffline($uid, $clientId)
   └── UDP      ：报文到达且 Session::exists 返回 false（会话已被回收/过期）
                  → 说明客户端刚恢复 → Push::replayOffline($uid, $clientId)

replayOffline()
   ├─ offline_mode ≠ queue → 直接返回 0
   ├─ UDP 且出站队列未启用 → 明确跳过并告警（而非静默丢弃）
   ├─ popBatch(push:offline:{uid}, PUSH_REPLAY_BATCH=50)   ← Lua 原子取批
   │    避免「取出」与「清理」之间被新消息插入造成丢失
   ├─ 逐条构造下行报文，offline = 1
   │    WS  → Gateway::sendToClient
   │    UDP → deliverUdp → queue:udp:out
   ├─ push_replay 指标（累加实际投递条数）
   └─ 若取出条数 ≥ batch，告警「尚有积压，剩余待下轮」
```

| 设计要点 | 说明 |
|---|---|
| 离线列表按 **uid** 聚合（不是 clientId） | 断线重连后 `clientId` 必然变化，而 `uid` 稳定 |
| 语义为「**至少一次**」 | 补投给首个恢复的连接；客户端需按 `msg_id` 去重 |
| 触发条件天然幂等 | WebSocket 只在鉴权成功时触发一次；UDP 只在会话从「不存在」变「存在」时触发 |
| 单轮上限 | 单次最多补投 `PUSH_REPLAY_BATCH` 条，积压多时靠下轮继续 |

### 9.8 订阅与广播链路

```
① 订阅
   客户端 → {"cmd":"data","data":{"action":"subscribe","params":{"topic":"etc.pass"}}}
     → ActionRunner → SubscribeAction → Subscribe::add(uid, topic)
        SADD subscribe:uid:{uid}   {topic}          （正向索引）
        SCARD 校验是否超 SUBSCRIBE_MAX_TOPICS → 超限则 SREM 回滚
        SADD subscribe:topic:{topic} {uid}          （反向索引）
        回执 {action:"subscribe", uid, topic, subscribers, at}

② 广播（服务端侧触发，客户端不能凭空广播）
   Push::enqueueTopic(topic, payload, opts)
     → Subscribe::subscribers(topic) 取全部订阅者 uid
     → 逐个 uid 调 Push::enqueue()（复用单目标链路：离线缓存 / 幂等 / 指标全部继承）
        ⚠ 若调用方指定了 msg_id，服务端会为每个目标派生 "{msg_id}:{uid}"
          —— 否则多目标共用同一 msg_id 会导致除首个外全部被幂等去重丢弃
     → push_topic / push_topic_targets 指标

③ 查询 / 取消
   topics    → 读 subscribe:uid:{uid} 集合
   unsubscribe → 两个方向同步 SREM
```

> **刻意不开放客户端 `publish` 动作**：那等于允许任意连接借服务端向他人广播，属消息伪造面。
> 广播只能由业务代码 / HTTP 接口 / 运维命令触发。

### 9.9 HTTP 接口链路

```
POST /push
  │
  ├─ ① 限流前置（单 IP 滑动分钟窗口，API_RATE_LIMIT=600/min）
  │     进程内静态计数快速拒绝 + Redis INCR 跨进程计数
  │     超出 → 429 / 4029
  ├─ ② 读请求头 X-Timestamp / X-Sign
  │     缺失 → 401 / 4001
  ├─ ③ 时间戳窗口校验 |now - ts| > API_SIGN_TTL(300) → 401 / 4002
  ├─ ④ 验签 hash_hmac('sha256', "{X-Timestamp}|{原始请求体}", api.secret)
  │     ⚠ 使用**原始请求体**而非解析后数组，避免键序/转义差异导致验签失败
  │     hash_equals 比对 → 失败 401 / 4001
  ├─ ⑤ 解析请求体，校验 target_type / target / payload 类型
  └─ ⑥ Push::enqueue(...) → RPUSH queue:push:out → 回 200 {"code":0,"msg":"accepted",...}
```

**请求示例**：

```bash
TS=$(date +%s)
BODY='{"target_type":"device","target":"dev-001","payload":{"title":"hi"},"msg_id":"m-1"}'
SIGN=$(printf '%s|%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$API_SECRET" -r | cut -d' ' -f1)

curl -s -X POST http://127.0.0.1:8290/push \
  -H "Content-Type: application/json" \
  -H "X-Timestamp: $TS" \
  -H "X-Sign: $SIGN" \
  -d "$BODY"
```

| 接口 | 方法 | 鉴权 | 说明 |
|---|---|---|---|
| `/health` | GET | **否** | 存活探测，供负载均衡 / 容器探针使用 |
| `/stats` | GET | 是 | 返回 Redis 中的指标快照（`gauge` / `counter` / `task`） |
| `/push` | POST | 是 | 提交定向推送任务 |

> `/stats` 需要 HMAC 验签，浏览器无法安全持有密钥 —— 这是必须单开一个**免鉴权**监控面板
> 端点（`/metrics.json`）的原因。

### 9.10 监控指标链路

```
各进程业务代码
  Monitor::incr('msg_in')      ── 进程内内存累加，零 IO
  Monitor::gauge('xxx', $v)    ── 进程内内存赋值
        │
        │  定时任务 monitor-report（每 MONITOR_INTERVAL=60s，scope=all 每进程都注册）
        ▼
  Monitor::report($withOnline)
    ├─ flushCounters()   → HINCRBY metrics:counter:{YYYYMMDD} {metric} {delta}
    │                      EXPIRE 7 天；累加型多进程安全
    ├─ HSET metrics:gauge memory_bytes:{pid}  {当前内存}
    ├─ HSET metrics:gauge pid_at:{pid}        {时间戳}     ← 进程存活判据
    ├─ HSET metrics:gauge proc:{pid}          {"role":..,"worker_id":..}
    ├─ HSET metrics:gauge tasks:{pid}         {"worker_id":..,"jobs":[...]}
    ├─ HSET metrics:gauge report_at           {时间戳}
    ├─ EXPIRE metrics:gauge MONITOR_TTL(600)
    └─ 仅 worker 0：conn_total / conn_ws / conn_udp（走 SCARD online:clients）
        │
        ▼
  监控面板进程（role=dashboard）只读渲染
```

**为什么业务侧不直接写 Redis**：每报文一次 `HINCRBY` 会把 Redis 变成瓶颈。
进程内累加 + 定时批量刷入，把 Redis 写入从「每报文」降到「每 60 秒 × 进程数」。

**为什么需要 `pid_at`**：gauge 的 TTL（600s）是上报周期（60s）的 10 倍，进程退出后
`memory_bytes:{pid}` / `tasks:{pid}` 会残留到 TTL 结束 —— 刚重启时面板上会出现一批「幽灵进程」。
面板必须以 `pid_at:{pid}` 为存活判据（阈值取「连续两个上报周期」）。

**采集分布的差异**：

| 进程 | 上报方式 | 说明 |
|---|---|---|
| BusinessWorker | `Task` 定时任务 `monitor-report`（scope=all） | 采集在线数（仅 worker 0）、内存、会话/推送/动作指标 |
| UDP 网关 | `Timer::add($monitorInterval, ...)` 自建定时器 | 只产出站维度指标；`report(false)` 跳过在线数采集（该进程无 Session 上下文） |
| Gateway / API / Dashboard | 不上报业务指标 | Gateway 由框架托管，API 只写队列，面板只读 |

### 9.11 内部通信链路（Gateway ↔ Register ↔ BusinessWorker）

```
Register (text://127.0.0.1:1238, count=1 强制单进程)

Gateway 启动  ── 以 secretKey 向 Register 注册自己的 listen 地址与内部端口
BusinessWorker 启动 ── 以 secretKey 向 Register 注册自己
Register 校验 secretKey ── 不一致直接拒绝注册
Gateway ── 收到 BusinessWorker 注册后建立内部 TCP 连接（onBusinessWorkerConnected）
Gateway ── 收到客户端报文时，按分发策略选择 BusinessWorker 连接转发（onMessage）
BusinessWorker ── 调 Gateway::sendToClient / sendToUid / closeClient 时经 Register 定位网关进程
```

| 约束 | 说明 |
|---|---|
| 三方 `secretKey` 必须一致 | 来源 `app.internal.secret`，留空回退 `app.auth.secret`。**Register 实例需显式赋值 `secretKey`，默认空值会导致所有注册被拒** |
| `GatewayClient::$registerAddress` 需显式设置 | `Lib\Gateway` **不会**自动继承 `BusinessWorker` 的注册中心配置；不设置会连向默认的 `127.0.0.1:1236`，导致推送/踢人静默失效 |
| 重启顺序 | `register → gateway → business`。杀掉 business 后若不重启 gateway，网关缓存的 BusinessWorker 地址仍指向死进程 → **WS 链路全断，而 UDP 链路正常**（UDP 走 Redis 队列） |

> **诊断线索**：**UDP 通而 WS 不通 ⇒ 查网关注册路由，别查业务逻辑。**

---

## 10. Redis 键空间

所有键都会再拼接 `REDIS_PREFIX`（默认 `gwpush:`）。

### 会话与索引

| 键 | 类型 | TTL | 说明 |
|---|---|---|---|
| `session:{clientId}` | Hash | `SESSION_TTL` | 会话主体：`client_id` / `uid` / `device_id` / `protocol` / `client_ip` / `client_port` / `gateway` / `connect_at` / `last_active` / `offline_at` |
| `heartbeat:{clientId}` | String | `SESSION_TTL` | 最近活跃时间戳。**高频写入，单独成键以降低写放大** |
| `uid:clients:{uid}` | Set | `SESSION_TTL` | uid → clientId 集合（多设备在线） |
| `device:client:{deviceId}` | String | `SESSION_TTL` | deviceId → 当前活跃 clientId（单对一定向的定位依据） |
| `online:clients` | Set | 无 | 全量在线 clientId（`SCARD` 得到在线数，**集群下天然全局**） |
| `online:ws` / `online:udp` | Set | 无 | 按协议维度的在线集合 |

### 鉴权

| 键 | 类型 | TTL | 说明 |
|---|---|---|---|
| `auth:revoked:{tokenSha256前32位}` | String | Token 剩余有效期 | Token 撤销名单（不落明文 Token） |
| `auth:bind:{uid}` | String | `AUTH_TOKEN_TTL` | uid → deviceId 绑定关系 |

### 队列

| 键 | 类型 | 说明 |
|---|---|---|
| `queue:udp:in` | List | UDP 网关 → 业务进程（入站） |
| `queue:udp:out` | List | 业务进程 → UDP 网关（出站，网关 sendto） |
| `queue:push:out` | List | 推送任务队列（所有推送入口的汇合点） |

### 推送

| 键 | 类型 | TTL | 说明 |
|---|---|---|---|
| `push:offline:{uid}` | List | `PUSH_OFFLINE_TTL` | 离线消息缓存，元素含 `payload` / `msg_id` / `source` / `offline_at` |
| `push:dedup:{md5(msg_id)}` | String | `PUSH_IDEMPOTENT_TTL` | 幂等去重标记 |

### 订阅

| 键 | 类型 | 说明 |
|---|---|---|
| `subscribe:uid:{uid}` | Set | 用户订阅的主题集合（正向） |
| `subscribe:topic:{topic}` | Set | 主题的订阅者 uid 集合（反向） |

### 限流

| 键 | 类型 | 说明 |
|---|---|---|
| `rl:{dim}:{md5(id)}` | Hash | Redis 令牌桶（`dim` = `conn` / `uid` / `ping`），由 Lua 脚本原子判定 |
| `api:rate:{md5(ip)}:{分钟}` | String | HTTP 接口单 IP 分钟级计数 |

### 指标

| 键 | 类型 | TTL | 说明 |
|---|---|---|---|
| `metrics:counter:{YYYYMMDD}` | Hash | 7 天 | 当日累加型指标 |
| `metrics:gauge` | Hash | `MONITOR_TTL` | 瞬时指标 + 各进程内存 + 进程身份 + 定时任务健康度 |
| `action:report:{topic}` | Hash | `ACTION_REPORT_TTL` | `report` 动作的按主题计数（`count` / `last_at` / `last_uid` / `last_dev` / `last_seq`） |

### 排查时的常见坑

> **务必带 `REDIS_DB` 参数**。项目默认 `REDIS_DB=0`，但本机开发环境曾使用 DB 9；
> `REDIS_DB=0` 中可能存有一批历史残留的 `gwpush:*` 键，排查时不带 `-n <db>` 会被误导。

```bash
redis-cli -n 0 --scan --pattern 'gwpush:*'
redis-cli -n 0 HGETALL gwpush:metrics:gauge
redis-cli -n 0 LLEN gwpush:queue:push:out
```

---

## 11. 监控面板

独立只读进程（`role=dashboard`），默认 `http://127.0.0.1:8291`。

| 接口 | 说明 |
|---|---|
| `GET /` 或 `/index.html` | 自包含 HTML 页面（内联 CSS/JS、零外链、可离线打开） |
| `GET /metrics.json` | 页面同源使用的 JSON 快照，附 `meta` 段（`now` / `interval` / `ttl` / `enable` / `refresh`） |

两者**均免鉴权**，安全边界由监听地址承担。需要远程访问时用 Nginx 反代（附加 Basic Auth）
或 SSH 隧道，**不要把 8291 直接暴露到公网**。

**页面内容**：

| 区块 | 内容 |
|---|---|
| 概览 | 在线连接数（总/WS/UDP）、上报时间新鲜度判定 |
| 指标卡（10 组） | 连接 / 消息收发 / 鉴权 / 会话心跳 / 定向推送 / 主题广播 / UDP 出站 / 业务动作 / 限流 / 发送背压 |
| 未归类指标 | `GROUPS` 未覆盖的 counter 字段自动落入「未归类」卡，不会静默丢失 |
| 进程内存 · 上报周期表 | 每个 PID 的内存、角色名（`proc:{pid}`）、存活状态（依据 `pid_at:{pid}`） |
| 定时任务表 | 每个 PID 的任务健康度（任务名 / 周期 / 执行次数 / 上次耗时 / 状态） |

**工程要点**：

- **模板按 mtime 缓存失效，改页面不用重启进程**。但必须显式
  `clearstatcache(true, $path)` —— workerman 常驻进程没有 PHP 的请求边界，
  stat 缓存不会被自动清理，`filemtime()` 会一直返回进程首次 stat 的旧值，
  导致 mtime 比较恒等、模板永不重载。
- **数值列右对齐必须同时覆盖 `th.num` 与 `td.num`**，只写 `td.num` 会让表头左对齐、
  数据右对齐，整列视觉错位。
- 页面存活判定用 `pid_at:{pid}` 而非 gauge 的 TTL（原因见 9.10）。

---

## 12. 业务动作接入指南

接入一个新业务动作只需**两步**，不需要改动 `Bootstrap` / `Router` / `ActionRunner` 任何代码。

### 步骤 1：写处理器类

```php
<?php
// src/Business/Action/OrderQueryAction.php

namespace GatewayPush\Business\Action;

use GatewayPush\Business\ActionContext;
use GatewayPush\Business\ActionInterface;
use GatewayPush\Business\Message;
use GatewayPush\Business\Monitor;

class OrderQueryAction implements ActionInterface
{
    public function handle(ActionContext $ctx)
    {
        Monitor::incr('action_order_query');   // 自采指标（需同步加入 config/app.php 的 metrics 列表）

        // 参数已由 ParamValidator 校验并归一化，无需再做类型判断
        $orderNo = (string)$ctx->param('order_no');
        $uid     = $ctx->uid();                // 身份来自可信来源，非报文自填

        if ($uid === '') {
            $ctx->replyError(Message::CODE_UNAUTHORIZED, '缺少用户身份');
            return;
        }

        // 允许异步：Redis 回调里再回执，ActionRunner 负责超时兜底
        \GatewayPush\Common\RedisClient::get('order:' . $orderNo, function ($data) use ($ctx) {
            $ctx->reply(array(
                'action'   => 'order_query',
                'order_no' => $ctx->param('order_no'),
                'found'    => $data ? 1 : 0,
                'data'     => $data,
                'at'       => time(),
            ));
        });
    }
}
```

### 步骤 2：在 `config/actions.php` 登记

```php
'order_query' => array(
    'handler'     => \GatewayPush\Business\Action\OrderQueryAction::class,
    'description' => '查询订单摘要',
    'params'      => array(
        'order_no' => array(
            'type'     => 'string',
            'required' => true,
            'max_len'  => 64,
            'pattern'  => '/^[A-Za-z0-9\-]{1,64}$/',
        ),
    ),
    // reply / auth / timeout 未声明时取 defaults：
    //   auth = true, reply = {ws: sync, udp: sync}, timeout = 5
),
```

### 声明字段说明

| 字段 | 说明 |
|---|---|
| `handler` | 处理器类名，必须实现 `ActionInterface` |
| `description` | 说明文案，供 `php start.php check` 与运维接口展示 |
| `auth` | 是否要求已鉴权（默认 `true`）。**UDP 通道没有连接级鉴权闸门，此字段是 UDP 侧唯一的业务鉴权防线**，业务动作不应轻易置 `false` |
| `reply` | 回执方式，按通道声明：`'sync'` 单值表示两通道相同，或 `['ws'=>'sync','udp'=>'none']` 按通道分别声明 |
| `timeout` | 回执超时（秒），超时记 `action_timeout` + 告警 + 回 `5000`；`0` 表示关闭保护 |
| `params` | 参数规则（见 8.7）；`'*'` 表示原样透传（仅供 `echo` 这类回显动作） |
| `options` | 动作私有配置，处理器经 `$ctx->option('key', $default)` 读取 |

### 回执抑制语义（重要）

声明 `reply = none` 时，`reply()` / `replyError()` **不会真正下发**，但**仍会把上下文标记为「已回执」**，
使超时保护不再触发。

这样处理器可以始终按「处理完就回执」的写法实现，是否真正下发完全由配置决定 ——
同一个处理器在 WS 上可回执、在 UDP 上静默，**不需要写任何 `if` 分支**。

### 新增动作的检查清单

1. 处理器类实现 `ActionInterface`
2. 需要采集的指标名加入 `config/app.php` 的 `monitor.metrics` 列表
3. 参数规则声明完整（未声明的入参会被**静默丢弃**）
4. 涉及外部输入的字符串参数务必加 `max_len` + `pattern`（尤其是会拼进 Redis 键的参数）
5. 需要读取他人数据的能力**不要做成入参**（参考 `session` / `topics` 的写法：只能查自己）
6. 跑 `php start.php check` 确认动作清单与处理器可用性校验通过

---

## 13. 测试与静态分析

### 13.1 命令

```bash
composer analyse        # PHPStan（level 5，baseline 冻结 11 条存量告警）
composer test           # PHPUnit（291 tests / 883 assertions；含 client/tests/Unit）
composer test:e2e       # 端到端自检（15 个用例）
composer test:client-e2e # 客户端 SDK 端到端对齐（A~O 共 15 个用例，需五角色 + Redis）
```

### 13.2 静态分析约束

| 项 | 约束 |
|---|---|
| PHPStan 版本 | `^2.0` |
| 内存 | **必须带 `--memory-limit=512M`**（本机 php.ini 仅 128M，否则子进程崩溃）；已写入 composer 脚本 |
| 分析范围 | `paths` 只含 `src`、`client/src` 与 `start.php`，**不含 `tests/`** |
| 分析口径 | `phpVersion: 80100` —— 刻意设置用于**拦截 8.2+ 语法误用**，保证 8.1 兼容性 |
| 收敛策略 | baseline 冻结存量告警 + 新代码零容忍；**不为让工具通过而改业务代码** |

> **`ignore.unmatched` 是修复的免费验证器**：baseline 中不再匹配任何实际错误的 `ignore`
> 条目会触发 `ignore.unmatched (non-ignorable)` 报错。因此「删掉 baseline 条目 → 分析干净通过」
> 等价于「修复生效且未引入新错误」。

### 13.3 跨版本验证

```bash
# 用不同 PHP 版本各跑一轮（最低 8.1；8.0 无法运行，原因见第 4 节）
/usr/local/php81/bin/php vendor/bin/phpunit
/usr/local/php83/bin/php vendor/bin/phpstan analyse --memory-limit=512M
/usr/local/php85/bin/php tests/e2e_check.php e2e-ver-85
```

### 13.4 端到端自检（e2e）

```bash
php tests/e2e_check.php <uid> [device_id] [timeout]
```

**前置条件**：Redis 可用；已启动 `register` / `gateway` / `udp` / `business`
（HTTP 用例还需 `api`，未启动时用例 H 标记 SKIP）。

**用例清单**（退出码 `0` = 全部通过，`1` = 存在失败）：

| 编号 | 用例 | 覆盖内容 |
|---|---|---|
| A | WebSocket 正常链路 | 连接 → `auth` 鉴权 → `ack` → `ping` → `pong` |
| B | WebSocket 越权拦截 | 未鉴权直接发业务指令 → `4003` 并断开 |
| C | UDP 正常链路 | 合法签名报文 → 收到 ack 回执 |
| D | UDP 签名拦截 | 篡改签名 → `4001` |
| E | 定向推送（在线） | `uid` 目标 → 在线连接收到 `push` 报文 |
| F | 离线缓存与重连补投 | 离线时入队 → 上线后自动补投 |
| G | 推送幂等 | 同一 `msg_id` 重复提交 → 仅投递一次 |
| H | HTTP 接口 | 健康探测 / 验签通过 / 验签拒绝 |
| I | UDP 定向推送 | 业务进程 → `queue:udp:out` → 网关 `sendto` |
| J | 指令路由表 | `data.action`（`echo` / `session`）分发与 `4006` / `4007` 分支 |
| K | UDP 离线补投 | UDP 会话重建时经出站队列补投（`offline=1`） |
| L | 报文级限流 | 单连接连发超量报文 → 部分放行、部分 `4008` |
| M | 业务动作契约 | 参数白名单 / `4006` 未知动作 / `4007` 参数错误 |
| N | UDP 通道业务动作 | `echo` 经出站队列回执；`report` 按声明静默不回执 |
| O | 订阅与广播闭环 | `subscribe` → `enqueueTopic` → `push` → `unsubscribe` |

**两条使用铁律**：

| 铁律 | 原因 |
|---|---|
| **每轮必须换全新 uid**（如 `e2e-uid-0001` / `0002` 递增） | UDP 无断连事件，会话只靠心跳超时回收；上一轮遗留会话会被判定为在线，导致用例 K（离线补投）**确定性失败** |
| **必须独占运行** | 与浏览器压测/截图等并行会引入干扰，产生假失败 |

**一条既有缺陷（非本次引入，仍未修）**：

| 缺陷 | 现象 | 根因 | 规避 |
|---|---|---|---|
| UDP 会话跨轮次污染 | 固定 uid 多轮运行 → 用例 K 确定性失败 | 见上方「铁律」 | 每轮换 uid |

**已修复（2026-09-20）**：B 用例曾偶发失败（「连接已关闭但未收到越权拦截提示」）。
根因不是 close 帧语义，而是 `AUTH_FAIL_CLOSE=true` 时把 4003 交给
`Gateway::closeClient($id, $message)` 的「附带消息」通道 —— workerman 5.x 的
`TcpConnection::close()` 在 `send()` 之后若发送缓冲为空会立即 `destroy()` → `fclose()`，
实测 30 轮裸 socket 探针命中 6 轮以 **RST** 收场，已写入的报文被一并丢弃，客户端读到
0 字节。修法：先独立下发报文、延迟 `Bootstrap::CLOSE_DELAY`（0.1s）再关闭，靠 TCP 的
顺序性保证送达；修复后同样探针 60 轮 100% 送达，e2e 连续 3 轮全绿。详见 9.2 的「主动断开」。

### 13.5 单元测试

```bash
composer test
# OK (291 tests, 883 assertions)
```

**只测「纯函数 / 零 IO」组件**：

| 覆盖对象 | 内容 |
|---|---|
| `ParamValidator` | 类型转换、范围、枚举、白名单丢弃语义 |
| `Message` | `canonicalize` / `sign` / `encode` / `decode` |
| `Env` | 多级加载优先级、类型化读取、空值语义 |
| `RateLimiter` | 仅 L1 内存桶 |
| `ActionRunner` | 仅声明层（装载 / 归一化 / 声明查询） |
| `ActionContext` | 回执抑制语义 |

> **未覆盖**：`ActionRunner::run()`、`RateLimiter::acquire()`、全部 Redis 路径 ——
> 它们依赖 workerman 生命周期与异步回调，mock 成本过高（静态类 + 回调），由 e2e 覆盖。

**写测试的两个硬性坑**（`phpunit.xml` 开了 `beStrictAboutOutputDuringTests` + `failOnWarning` + `failOnRisky`）：

1. **必须先接管 `Logger`**：默认 `path = ''`（会落盘到盘符根目录）且 `stdout = true`，
   不接管会同时污染输出与文件系统。测试内统一：
   ```php
   Logger::init(['path' => sys_get_temp_dir(), 'level' => Logger::ERROR, 'stdout' => false]);
   ```
   级别设为 `ERROR` 可使 `info` / `warn` 在写盘前即被丢弃，零副作用。
2. **限流测试的速率不能随手写**：`checkMemory` 按 `elapsed × rate / 1000` 补令牌，
   `rate = 1000/s` 即 1 令牌/毫秒 —— 此时「连续调用耗尽后被拒」的断言会因调用间隔超过 1ms
   而偶发失败（与机器速度耦合）。**拒绝类断言必须用低速率**（如 `rate = 1/s`）；
   只有「补令牌后放行」类断言才用高速率。

### 13.6 e2e 重构验证方法（可复用）

```bash
git show HEAD:tests/e2e_check.php > runtime/_e2e_old.php    # 提取改动前基线
php runtime/_e2e_old.php <uid1> > _old_out.txt              # 两侧必须用不同 uid
php tests/e2e_check.php   <uid2> > _new_out.txt
diff <(grep -E '^\[(PASS|FAIL|SKIP)\]' _old_out.txt) \
     <(grep -E '^\[(PASS|FAIL|SKIP)\]' _new_out.txt)        # 必须一致
```

**验收口径**：汇总段 15 条标签的**顺序与文案**、头部信息、CLI 接口、退出码必须与原脚本一致；
逐用例 `[X]` 输出行的**到达顺序本就非确定**（回调调度受网络/定时器影响），不作为重构缺陷依据。

---

## 14. 运维手册

### 14.1 启动顺序与依赖

```
register → gateway → udp → business → api → dashboard
```

- 顺序即依赖顺序：所有角色都要向 `register` 注册。
- `bin/start.sh start` / `bin/start.bat start` 已按此顺序编排。

### 14.2 重启策略

| 场景 | 操作 |
|---|---|
| 仅改动业务代码 | `reload`（Linux 真正平滑，网关长连接不中断） |
| 改动 `config/*.php` 结构性配置 | `restart` |
| 改动网关/协议代码 | `restart`（长连接会断开，客户端需重连） |
| 改动面板页面 `resources/dashboard/index.html` | **无需重启** —— 模板按 mtime 自动失效 |

### 14.3 故障排查

#### 服务起不来

```bash
php start.php check                      # 先看自检结论
./bin/start.sh log error 200             # 再看错误日志
./bin/start.sh log stdout 100            # daemon 模式下 PHP 的屏幕输出
```

#### 端口被占用

```bash
netstat -ano | grep 8282                 # Windows
netstat -anp | grep 8282                 # Linux（UDP 端口同样有 PID 列）
```

`php start.php check` 也会做端口占用探测（**仅提示不阻断** —— restart 场景下端口被自身占用属正常）。

#### 出现重复实例

```bash
netstat -ano | grep 8283                 # UDP 网关是否两个 PID 抢同一端口
netstat -ano | grep ':1238' | grep ESTABLISHED   # 谁连上了 register
bin\start.bat status                     # 已按角色归并（读命令行反查的结果）
```

**根因几乎必然是用了 `php start.php stop|restart`**（Windows 下会反向启动新实例，见 6.6）。

#### UDP 通但 WS 不通

```
⇒ 查网关注册路由，别查业务逻辑
```

原因：UDP 走 Redis 队列（不依赖 Gateway 转发），WS 走网关到 BusinessWorker 的内部连接。
杀掉 business 后未重启 gateway，网关缓存的 BusinessWorker 地址仍指向死进程，WS 立即全断。

```bash
bin\start.bat restart gateway      # 重建网关到 BusinessWorker 的路由
```

#### 消息发了没反应

按顺序排查：

| 检查项 | 命令 / 位置 |
|---|---|
| 报文能否解析 | 搜索日志关键词「报文解析失败」→ 回 `4000` |
| 是否被限流 | 搜索「报文超限已拒绝」（按维度每秒采样）→ 回 `4008` 或静默 |
| 是否未鉴权 | 搜索「未鉴权连接尝试业务指令，已拒绝」→ 回 `4003` |
| 动作是否注册 | `php start.php check` 看业务动作清单；或搜索「未知业务动作」→ `4006` |
| 参数是否合规 | 搜索「业务动作执行前失败」→ `4007` |
| 是否超时未回执 | 搜索「业务动作超时未回执」→ `5000` 并记 `action_timeout` |
| 服务端是否收到 | 看 `msg_in` 指标是否增长 |

> **UDP 侧「错误静默」+「身份缺失拒绝」叠加会制造无日志故障**：UDP 错误一律静默是刻意设计
> （避免反射放大），但一旦叠加身份拒绝，客户端只见超时、服务端也无异常日志，极易误判为链路故障。
> **排查 UDP 动作问题必须对照运行日志（`runtime/logs/`），不能依赖客户端超时。**

#### 推送不达

| 检查项 | 说明 |
|---|---|
| `queue:push:out` 是否积压 | `LLEN gwpush:queue:push:out` —— 持续增长说明业务进程未消费或消费慢 |
| 目标是否在线 | `HGETALL gwpush:session:{clientId}` 看 `offline_at`；`SISMEMBER gwpush:online:clients {clientId}` |
| 设备映射是否指向活连接 | `GET gwpush:device:client:{device_id}` |
| 是否被幂等丢弃 | 搜「推送任务重复，已跳过」+ 看 `push_dedup` 指标 |
| 是否被数据体限额拦下 | 搜「推送数据体超限，已拒绝」+ 看 `push_fail` |
| UDP 目标 | `LLEN gwpush:queue:udp:out` 与 `udp_out` / `udp_out_fail` 指标 |
| 离线策略 | `push_offline` 指标 + `LLEN gwpush:push:offline:{uid}` |

### 14.4 生产环境必做

| 项 | 说明 |
|---|---|
| 修改密钥 | `AUTH_SECRET` 与 `INTERNAL_SECRET` 必须替换为高强度随机值（`env:init` 会生成） |
| 调整文件句柄上限 | 支撑上万长连接必须 `ulimit -n` >= 65535（临时 `ulimit -n 65535`，永久写 `/etc/security/limits.conf`） |
| 关闭调试 | `APP_DEBUG=false`、`LOG_STDOUT=false`、`LOG_LEVEL=info` 或 `warn` |
| 启用 WSS | `SSL_ENABLE=true` + `SSL_CERT` / `SSL_PK` |
| 收紧监听地址 | `API_LISTEN` / `DASHBOARD_LISTEN` 保持 `127.0.0.1`，通过反向代理暴露 |
| 设置 Redis 密码 | `REDIS_PASSWORD`，并考虑 `bind` 限制来源 |
| 更换 `REDIS_DB` | 多套环境共用同一 Redis 实例时必须区分 |

### 14.5 Windows 特别说明

| 事实 | 影响 |
|---|---|
| workerman 不解析命令 | `stop` / `restart` / `reload` / `status` 全部失效且会反向启动实例 → **一律走 `bin\start.bat`** |
| 不写 pid 文件 | `runtime/pid/` 只有管理脚本自己写的 `win_{角色}.pid`（存承载窗口的 cmd.exe PID，仅用于停止时连带关窗） |
| 单进程模型 | 所有 `*_COUNT` 配置被强制降级为 1（`resolveCount()`），无法测试多进程并发行为 |
| 不支持 `--role=all` | `start.php` 会显式拒绝并提示按角色启动 |
| 无 `pcntl` / `posix` | `check` 会输出 `[SKIP] Windows 环境跳过 pcntl / posix 检查` |

**终端中文编码的三处联动（缺一处就乱码）**：

| 文件 | 处理 |
|---|---|
| `.bat` | `chcp 65001` 切换当前控制台代码页（退出时还原原 CP） |
| `.ps1` | 设置 `[Console]::OutputEncoding` / `InputEncoding` / `$OutputEncoding` |
| 新开的角色窗口 | **会退回系统默认代码页**，故启动命令统一包成 `cmd /k "chcp 65001>nul && ..."` |

**文件编码是硬约束**（改脚本后必须复核）：

| 文件 | 换行 | BOM | 原因 |
|---|---|---|---|
| `bin/start.sh` | LF | 无 | CRLF 会让 shebang 变成 `bash\r`，且 `bash -n` 查不出来 |
| `bin/start.ps1` | CRLF | **UTF-8 BOM** | PowerShell 5.1 缺 BOM 会按 GBK 解析脚本，中文先烂 |
| `bin/start.bat` | CRLF | 无 | **必须纯 ASCII** —— CMD 按当前代码页解码、却按**字节**推进文件指针，多字节字符会让两者错位、从字符中间恢复读取并把残片当命令执行 |

---

## 15. 集群就绪度

**结论：无状态基础成立，但集群并非零改造。** 落地前需完成 4 项改造 + 1 项启动校验。

| 项 | 缺口 | 改造方向 |
|---|---|---|
| A | runtime 三路径硬编码 `$basePath/runtime`（`config/app.php:49-51`），pid 仅按角色命名（`start.php:182`） | `NODE_ID` 化 runtime 与 pid 路径，加 `--node=` 参数 |
| B | `lan_ip` + `start_port` 是 clientId 唯一性的前提，但**无校验**（`config/gateway.php:39`） | `check` 增加集群内唯一性校验，冲突时拒绝启动 |
| C | UDP 出站队列是单条共享 List，任意节点可消费并 sendto → 源 IP 与入口节点不一致 | 出站队列节点亲和：`queue:udp:out:{NODE_ID}` |
| D | 定时任务 `scope=first` 语义是「每机 worker 0」，集群下会重复执行 | 全局唯一任务加分布式锁（可复用 `setNxEx`） |

**已就绪的部分**：

| 能力 | 说明 |
|---|---|
| 精确寻址 | clientId 内嵌节点地址（`Context.php:116`） |
| 无本地状态 | 会话 / 映射 / 队列 / 指标全在 Redis |
| 原子取批 | `popBatch` 用 Lua 保证多进程/多节点并发安全 |
| 累加安全 | 指标用 `HINCRBY`，跨节点累加不丢 |
| 在线数天然全局 | `countOnline()` 走 `SCARD online:clients`，而该集合是**集群共享集合**，各节点 `sAdd` 自己的连接，任何节点读到的都是全局值（集群下无需聚合） |

> **已排除的疑点**：曾误判「`conn_total` 跨机互相覆盖」—— 实为 `SCARD` 共享集合，各节点值相同，无偏差。

---

## 附：技术方案文档

更完整的设计决策、取舍理由与演进路线见工作区根目录的
`Workman V2 GatewayWorker 实时数据推送服务技术方案文档.md`。

## License

MIT
