# GatewayPush 实时数据推送服务

基于 [workerman](https://github.com/walkor/workerman) + [GatewayWorker](https://github.com/walkor/GatewayWorker) 的  
**WebSocket + UDP 双协议**实时数据推送服务。面向「单对一定向推送」场景（一个用户/设备对应一条有效连接），  
支撑上万长连接；除 Redis 外无其他外部依赖。

```
WebSocket 长连接（实时双向）  UDP 轻量上报（低开销、可丢包）  HTTP 接口（服务端下发 / 动作调用）
                    ↓                    ↓                        ↓
              ┌──────────────────────────────────────────────────────────┐
              │            统一报文 / 统一路由 / 统一推送出口              │
              │        认证 · 限流 · 会话 · 幂等 · 离线补投 · 指标          │
              └──────────────────────────────────────────────────────────┘
                    ↑                    ↑                        ↑
              同一批动作处理器：WS 直回 · UDP 走出站队列 · HTTP 走回程键
```



> **双协议 / 三通道**：网络传输仍是 WS + UDP 两套协议；业务动作的**触发通道**有三条  
> （WS / UDP / HTTP），差异只在「回执如何回到调用方」，处理器代码完全共用。  
> HTTP 是唯一「有同步等待的调用方」的通道，因此多一条 `action:result:{request_id}` 回程路径。

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

| 能力            | 实现方式                                                                                       | 关键位置                                                |
| ------------- | ------------------------------------------------------------------------------------------ | --------------------------------------------------- |
| **双协议接入**     | 同一份报文结构、同一张指令路由表、同一批业务动作处理器，WS 与 UDP 行为差异只在「回执下发方式」                                        | `src/Business/ActionRunner.php`                     |
| **单对一定向推送**   | 三种目标类型：`uid`（该用户全部在线连接）/ `device`（精确到单台设备）/ `client`（精确到单条连接）；同设备新连接上线时自动踢掉旧连接             | `src/Business/Push.php`                             |
| **离线消息补投**    | 目标离线时写 `push:offline:{uid}`，重连后自动补投（至少一次语义）                                                | `Push::handleOffline()` / `Push::replayOffline()`   |
| **推送幂等**      | 按 `msg_id` 做 `setNxEx` 去重，窗口可配                                                             | `Push::dispatch()`                                  |
| **声明式业务动作**   | 新动作 = 写一个类 + 在 `config/actions.php` 登记一行，不改框架代码                                            | `config/actions.php`                                |
| **统一鉴权**      | 自包含 HMAC Token（`base64url(payload).base64url(hmac)`），本地校验无 IO；另有 Redis 撤销名单与设备绑定校验         | `src/Business/Auth.php`                             |
| **报文签名**      | `hmac_sha256("cmd\|seq\|ts\|device_id\|token\|canonicalize(data)", secret)`，UDP 无连接场景的身份基石 | `src/Business/Message.php`                          |
| **两级限流**      | L1 网关内存令牌桶（每 IP，零 IO，抗洪水）+ L2 Redis 令牌桶（每连接 / 每用户，跨进程共享）                                   | `src/Common/RateLimiter.php`                        |
| **会话保持**      | 会话全量落 Redis，节点无本地状态；断连保留会话、重连按 `device_id` 恢复                                              | `src/Business/Session.php`                          |
| **监控指标**      | 业务侧仅进程内累加（零 IO），定时任务批量刷 Redis；HINCRBY 保证多进程安全                                              | `src/Business/Monitor.php`                          |
| **只读监控面板**    | 独立进程渲染自包含 HTML 页面，零外链、不写 Redis、不持业务密钥                                                      | `src/Dashboard/Bootstrap.php`                       |
| **HTTP 推送接口** | 独立进程只做「验签 → 校验 → 入队」，不持有 Gateway 连接，故障域与网关隔离                                               | `src/Api/Bootstrap.php`                             |
| **三通道动作执行**   | 同一批动作处理器可经 WS / UDP / HTTP 触发，差异全部由 `reply` 声明表达，处理器零通道分支；HTTP 通道经「动作队列 + 回程键」实现同步语义       | `src/Business/ActionRunner.php` / `ActionReply.php` |
| **跨平台脚本**     | Linux/Windows 各自的管理脚本，处理终端中文编码、按角色编排进程                                                     | `bin/`                                              |

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

| 角色          | 进程名              | 默认监听                       | 进程数(Linux) | 职责                                                  |
| ----------- | ---------------- | -------------------------- | ---------- | --------------------------------------------------- |
| `register`  | `Register`       | `127.0.0.1:1238`           | 1（强制）      | Gateway 与 BusinessWorker 的地址发现 + 内部通信鉴权             |
| `gateway`   | `GW-WS`          | `websocket://0.0.0.0:8282` | 4          | 长连接接入、原生心跳、死连接清理、报文转发                               |
| `udp`       | `GW-UDP`         | `udp://0.0.0.0:8283`       | 2          | UDP 收包、L1 限流、签名校验、入队、出站 sendto                      |
| `business`  | `BusinessWorker` | —                          | 4          | 全部业务：鉴权、会话、动作执行、推送、指标                               |
| `api`       | `GW-API`         | `http://127.0.0.1:8290`    | 1          | HTTP 接口：验签 → 校验 → 入队（`/push`）；动作调用同步等待回执（`/action`） |
| `dashboard` | `GW-DASH`        | `http://127.0.0.1:8291`    | 1          | 只读监控面板                                              |
| `all`       | —                | —                          | —          | 一次装配以上全部（**仅 Linux**）                               |

> **职责边界是硬约束**：网关进程只做网络调度，不承载业务逻辑；UDP 网关不碰业务，  
> 协议校验后经 Redis 队列交给业务进程；面板进程只读 Redis，不写任何键；  
> API 进程只写推送队列，不持有 Gateway 连接。这样做的目的是让每个进程的**权限与故障域**  
> 都保持最小。

---

## 3. 目录结构

```
GatewayPush/
├── bin/                              服务管理脚本（跨平台，处理终端编码）
│   ├── start.sh                      Linux / macOS
│   ├── start.bat                     Windows 入口（纯 ASCII，仅转发到 ps1）
│   ├── start.ps1                     Windows 实现（UTF-8 带 BOM）
│   └── dev/boot_all.sh               仅本机开发：逐角色拉起全部角色并常驻（非生产入口）
├── config/
│   ├── app.php                       全局：运行约束 / 日志 / Redis / 鉴权 / 会话 / 推送 / API / 面板 / 限流 / 订阅 / 监控
│   ├── gateway.php                   网关层：Register / WebSocket / UDP / 心跳
│   ├── business.php                  业务层：进程参数 / UDP 队列 / 动作队列 / 推送队列 / 定时任务注册表
│   └── actions.php                   业务动作声明清单（声明式，改这里不改框架）
├── resources/
│   └── dashboard/index.html          监控面板页面（自包含，零外链）
├── runtime/                          运行时目录（.gitignore 排除，只放产物不放人工资产）
│   ├── logs/                         {role}_YYYY-MM-DD.log / error_YYYY-MM-DD.log / workerman.log / stdout.log
│   │   └── archive/                  {YYYY-MM}.tar.gz（开启 LOG_ARCHIVE_ENABLE 后由 log-archive 产生）
│   ├── pid/                          workerman_{role}.pid（Linux）/ win_{role}.pid（Windows 承载窗口）
│   └── phpstan/                      PHPStan 分析缓存（tmpDir）
├── src/
│   ├── Api/Bootstrap.php             HTTP 接口进程（/health /stats /push /action /action/{id}）
│   ├── Business/
│   │   ├── Action/                   7 个内置业务动作实现
│   │   │   ├── EchoAction.php        原样回显
│   │   │   ├── SessionAction.php     查询会话摘要
│   │   │   ├── ReportAction.php      数据上报
│   │   │   ├── SubscribeAction.php   订阅主题
│   │   │   ├── UnsubscribeAction.php 取消订阅
│   │   │   ├── TopicsAction.php      查询已订阅主题
│   │   │   └── NotifyAction.php      触发向本人推送
│   │   ├── ActionContext.php         动作上下文：身份 / 已校验参数 / 统一回执（三通道）
│   │   ├── ActionInterface.php       动作契约
│   │   ├── ActionReply.php           HTTP 通道回程桥（action:result:{request_id}）
│   │   ├── ActionRunner.php          通道无关的动作执行器 + HTTP 白名单
│   │   ├── Auth.php                  Token 签发 / 校验 / 撤销 / 设备绑定
│   │   ├── Bootstrap.php             BusinessWorker 入口 + 事件处理 + 三条队列消费
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
│   │   ├── RedisKeys.php             Redis 键空间唯一声明处（全部逻辑键名）
│   │   └── WorkerEvents.php          Worker 事件统一绑定（含背压观测）
│   ├── Console/                      CLI 入口支撑（被 start.php 调用；内部不出现 exit）
│   │   ├── EnvChecker.php            运行环境自检（报告文本 + 布尔结论）
│   │   ├── Banner.php                启动信息横幅（服务清单 + 端口探测）
│   │   ├── PortProbe.php             端口占用探测（Windows 走 netstat 快照）
│   │   ├── Commands.php              roles / env:init / usage 三个子命令
│   │   ├── PushCommand.php           push 子命令（临时起单 Worker 驱动异步入队）
│   │   ├── SecretGuard.php           密钥占位值 / 弱值判定
│   │   └── Text.php                  等宽终端显示宽度与补位
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
│   ├── tests/E2E/ClientE2E.php        客户端端到端（覆盖服务端 A~O 共 15 用例，服务端另有 P）
│   └── README.md                      客户端使用说明与里程碑
├── tests/
│   ├── E2E/                          端到端用例（Harness + 10 个 Case 模块）
│   ├── Unit/                         单元测试（13 个纯函数/零 IO 组件）
│   ├── Api/                          HTTP 侧独立脚本：http_demo.php（调用示例）/ api_sign_check.js（验签断言）
│   ├── Frontend/                     面板侧独立脚本：dashboard_autorefresh_check.js（注入假 DOM）
│   ├── Manual/                       手工验收脚本（P3/P4/P5 里程碑，需服务在线，见其 README）
│   ├── bootstrap.php
│   └── e2e_check.php                 e2e 入口
├── postman/
│   └── GatewayPush.postman_collection.json   可直接导入的 HTTP 接口集合（9 个请求，内置自动签名）
├── .github/
│   └── workflows/ci.yml              CI：静态门禁 + PHP 8.2~8.5 单元测试矩阵 + Redis 端到端（见 §13.7）
├── .gitattributes                    行尾规范化：`*.bat`/`*.cmd`/`*.ps1` → CRLF，`*.sh`/`*.php` → LF
├── AGENTS.md                         AI 协作入口（红线 / 门禁 / 目录速查，供任意 AI 工具对齐）
├── start.php                         统一启动入口：命令分发 + 环境自检 + 启动（实现见 src/Console/）
├── composer.json                    依赖与脚本
├── phpstan.neon                     静态分析配置（level 6 + 扩展 include + 刻意关闭的 strictRules）
├── phpstan-baseline.neon            生产代码存量基线（只减不增）
├── phpstan-tests-baseline.neon      测试代码存量基线（提级到 level 6 时重新生成，只减不增）
├── phpunit.xml
├── .env.example                     配置模板（含全部变量的说明）
└── docs/                             设计与接口文档
    ├── images/dashboard/             监控面板截图（调试期留存）
    ├── GatewayPush 对外接口文档.md      面向调用方的字段级接口契约
    ├── GatewayPush 客户端SDK与调试器设计方案.md
    ├── Workerman V2 GatewayPush 实时数据推送服务技术方案文档.md
    └── Workerman 框架 AI 编码规范.md
```

---

## 4. 环境要求

| 项          | 要求                                                     | 说明                                                                                                                 |
| ---------- | ------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------ |
| PHP        | **>= 8.2**（已验证上限 8.5）                                  | 下限由 **dev 工具链**的传递依赖决定（php-cs-fixer → `symfony/*` 7.x 要求 >= 8.2），非项目代码约束；代码本身不使用 8.3+ 独有语法，`phpstan.neon` 的 `phpVersion: 80200` 用于拦截误用 |
| 必需扩展       | `json`、`openssl`、`sockets`                             | `openssl` 用于 HMAC，`sockets` 供 workerman 使用                                                                         |
| Linux 必需扩展 | `pcntl`、`posix`                                        | 多进程模型依赖；Windows 无此二扩展，自动降级为单进程                                                                                     |
| 建议扩展       | `event`、`redis`、`mbstring`                             | 缺失仅告警：`event` 提升事件循环性能，`redis` 供扩展加速，`mbstring` 使参数长度按**字符**计数                                                     |
| Redis      | 支持 Lua (`EVAL`) 与 `SET NX EX` 的版本（>= 2.6.12），生产建议 5.0+ | 唯一外部依赖。原子取批与令牌桶判定均依赖 Lua 脚本                                                                                        |
| Composer   | 任意近期版本                                                 | 用于安装依赖                                                                                                             |


> **下限已于 2026-09-22 由 8.1 提到 8.2。** 触发原因是 **dev 工具链**，不是代码：
> `friendsofphp/php-cs-fixer` 传递依赖的 `symfony/*` 7.x 要求 `>=8.2`，而 lock 是在 8.2 上解析的，
> 于是 CI 原先的 8.1 腿连 `composer install` 都过不去。运行时依赖（`workerman/workerman` 5.x 等）
> 其实只要求 `>=8.1`。
>
> **PHP 8.1 及以下无法运行本项目**：Composer 会在 `vendor/composer/platform_check.php`
> 直接抛 `RuntimeException` 拦截，连 `start.php` 都进不去。
> 8.2 ~ 8.5 由 CI 的 `test` 作业真实覆盖（见 §13.7），8.5 暂挂 `continue-on-error`。

---

## 5. 快速开始

### 5.1 安装依赖

```bash
git clone <repo> GatewayPush
cd GatewayPush
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
GatewayPush 推送服务 - 运行环境自检
======================================================================
PHP 版本  : 8.2.9 (cli)
操作系统  : WINNT [单进程模式]
启动角色  : all
----------------------------------------------------------------------
[OK  ] PHP 版本 >= 8.2.0
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

#### 启动信息输出

`start` / `restart` 在装配 Worker 之前会打印一段启动信息，用于启动后立刻确认  
「跑的是哪份配置、哪个版本的框架、哪些组件」：

```text
GatewayPush 实时数据推送服务 - 启动信息
======================================================================
PHP 版本  : 8.2.9 (cli) / Linux [多进程模式]
启动角色  : all
启动模式  : DAEMON
环境配置  : dev（.env -> .env.local）
时区      : Asia/Shanghai
运行目录  : runtime/  (日志 runtime/logs/，进程 runtime/pid/)
框架版本  : workerman v5.2.2 / gateway-worker v3.1.4
依赖版本  : workerman/redis v2.0.6 / vlucas/phpdotenv v5.7.0
----------------------------------------------------------------------
服务清单  :
  角色        进程名            监听                                进程数  状态
  register    Register          text://127.0.0.1:1238               1       -
  gateway     GW-WS             websocket://0.0.0.0:8282            1       -
  udp         GW-UDP            udp://0.0.0.0:8283                  1       -
  business    BusinessWorker    -（注册中心 127.0.0.1:1238）        1       -
  api         GW-API            http://127.0.0.1:8290               1       -
  dashboard   GW-DASH           http://127.0.0.1:8291               1       -
======================================================================
```

`php start.php info [角色列表]` 可随时单独打印同一份信息，并把「状态」列换成  
实时端口探测结果：

```bash
php start.php info                    # 全部角色
php start.php info gateway,udp        # 只看网关与 UDP
./bin/start.sh info                   # 经 Linux 管理脚本（等价透传）
bin\start.bat info                    # 经 Windows 管理脚本
```

> **为什么不直接用 workerman 自带的启动横幅**：它的版本行与 WORKERS 表全程走  
> `Worker::log()`，而该方法首行即判断 `!$daemonize` —— 守护模式（`-d`）下整块输出  
> 只落 `runtime/logs/workerman.log`，终端上完全看不到。本项目选择在  
> `Worker::runAll()` **之前**打印：此时尚未 daemonize，STDOUT 仍连接终端，因此  
> 前台与守护两种模式都能看到。
>
> Windows 下 `bin\start.bat start` 让每个角色开独立窗口，横幅打印在各自窗口里；  
> 管理脚本会在主窗口额外补打印一份汇总，范围取本次实际启动的角色。
>
> 需要静默时加 `-q`：`php start.php start -d -q`（与 workerman 的静默语义一致）。
>
> 「状态」列依赖端口探测：`start` 时进程尚未启动，该列恒为 `-`；`info` 时才是真实结果。  
> Windows 的 socket 默认允许重复 bind，`bind` 探测无法判定占用，故该平台改用 `netstat`  
> 快照判定（`exec` 被禁用时退回 `bind`）。

### 5.5 验证服务

```bash
# 1) 查看进程状态
./bin/start.sh status          # Linux
bin\start.bat status           # Windows

# 2) 生成一个调试 Token
php start.php token 1001 dev-001

# 3) 跑端到端自检（16 个用例，覆盖双协议全链路）
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

| 命令       | Linux `./bin/start.sh`         | Windows `bin\start.bat`        | 说明                                                        |
| -------- | ------------------------------ | ------------------------------ | --------------------------------------------------------- |
| 启动全部     | `start`                        | `start`                        | Linux 守护模式；Windows 开 6 个窗口                                |
| 启动核心     | —                              | `start core`                   | 除 `dashboard` 外的 5 个角色                                    |
| 启动单角色    | `start <角色>`                   | `start <角色>`                   | Linux 前台运行（Ctrl+C 退出）；Windows 独立窗口                        |
| 停止       | `stop [角色\|all]`               | `stop [all\|角色]`               | 不带参数 = 全部                                                 |
| 重启       | `restart [角色\|all]`            | `restart [all\|角色]`            | 先停后启                                                      |
| 平滑重启     | `reload [角色\|all]`             | `reload`                       | Linux 仅重载业务代码，长连接不中断；Windows 语义受限，见 6.6                   |
| 状态       | `status` / `svc-status`        | `status` / `svc-status`        | PID / 内存 / 运行时长 / 监听地址                                    |
| 看日志      | `log [-f] [类型] [行数]`           | `log [-f] [类型] [行数]`           | 类型：`workerman`(默认) / `info` / `warn` / `error` / `stdout` |
| 环境自检     | `check`                        | `check`                        | 透传到 `php start.php check`                                 |
| 启动信息     | `info [角色列表]`                  | `info [角色列表]`                  | 透传到 `php start.php info`；`start` 时自动打印                    |
| 生成配置     | `env:init`                     | `env:init`                     | 透传到 `php start.php env:init`                              |
| 生成 Token | `token <uid> [device] [ttl]`   | `token <uid> [device] [ttl]`   | 透传                                                        |
| 提交推送     | `push <类型> <目标> [payload] ...` | `push <类型> <目标> [payload] ...` | 透传                                                        |
| 帮助       | `help` / `-h` / `--help`       | `help` / `-h` / `--help`       |                                                           |

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

#### `./bin/start.sh log [-f] [通道] [行数]`

```bash
./bin/start.sh log                    # workerman.log 最后 60 行
./bin/start.sh log -f gateway         # 跟随网关进程日志
./bin/start.sh log error 200          # 跨角色错误汇总最后 200 行
./bin/start.sh log stdout             # workerman 的 stdout.log
```

| 通道              | 实际文件                                                                                                                               |
| --------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| `workerman`（默认） | `runtime/logs/workerman.log`                                                                                                       |
| `error`         | `runtime/logs/error_YYYY-MM-DD.log`（**跨角色错误汇总**，自动取最新一个）                                                                           |
| 角色名             | `runtime/logs/{role}_YYYY-MM-DD.log`，可选 `register` / `gateway` / `udp` / `business` / `api` / `dashboard` / `all` / `app`（自动取最新一个） |
| `stdout`        | `runtime/logs/stdout.log`（daemon 模式下 PHP 的屏幕输出）                                                                                    |

> 用 `less` 看日志请加 `-R`，否则中文会显示为乱码（`less` 不自动按 UTF-8 解码）。

#### 环境变量

| 变量         | 作用                                                                                                                                               |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| `PHP_BIN`  | 指定 PHP 可执行文件，默认取 PATH 中的 `php`。**脚本会校验其版本**（低于下限直接拒绝，见 [6.7](#67-php-解释器解析与版本校验两平台)）。例：`PHP_BIN=/www/server/php/82/bin/php ./bin/start.sh check` |
| `NO_COLOR` | 设为任意值可关闭彩色输出                                                                                                                                     |

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

| 项        | Linux                                          | Windows                                                                 |
| -------- | ---------------------------------------------- | ----------------------------------------------------------------------- |
| 存活判据     | `runtime/pid/workerman_{role}.pid` + `kill -0` | 用 `Get-CimInstance Win32_Process` 读命令行**反查 `php.exe`** 匹配 `--role=<角色>` |
| 停止方式     | `php start.php stop --role=xxx`                | 终止上述反查到的 PID + 连带关闭承载窗口（`win_{角色}.pid` 记录的 cmd.exe）                     |
| `reload` | workerman 原生平滑重载                               | 无法做到真正平滑（Windows 无 master 进程模型），退化为重启业务相关角色                             |
| `start`  | 守护模式 `-d`                                      | 每个角色一个独立窗口，`cmd /k` 保持窗口不关闭                                             |
| 端口探测     | `kill -0` / 直接判断                               | `Test-PortListening` + 就绪轮询                                             |

#### 被配置关闭的角色会被自动跳过

`.env` 里把某个角色对应的开关设为 `false`（如 `WS_ENABLE=false`）后，脚本会在**进入就绪
轮询之前**识别并跳过它，其余角色照常启动，退出码仍为 0：

```
==> 启动 5 个角色（每个角色一个窗口，按依赖顺序）
  gateway     已禁用（WS_ENABLE=false），跳过

  register    已在运行（PID 37772）
  udp         已在运行（PID 2472）
  business    已在运行（PID 38828）
  api         已在运行（PID 32652）
  dashboard   已在运行（PID 30892）

[OK]    全部 5 个角色已就绪（1 个角色已禁用，未启动）
```

不加这层过滤会怎样：被关闭角色的进程会立刻以 `@@@no worker inited@@@` 退出（workerman
在 Windows 单 Worker 模式下的行为），而脚本照常进入就绪轮询 → 白等满 25s 就绪超时 →
把「配置关闭」误报成「启动失败」 → 最终以非 0 退出码收尾。一个开关就能让整条启动链失效。

角色级开关与角色的对应关系：

| 角色级开关              | 对应角色      | 关闭后的后果                                        |
| ------------------ | --------- | --------------------------------------------- |
| `REGISTER_ENABLE`  | register  | 无注册中心，其余角色无法完成地址发现（集群部署时才应关闭）                  |
| `WS_ENABLE`        | gateway   | WebSocket 长连接不可用                              |
| `UDP_ENABLE`       | udp       | UDP 上报 / 推送不可用                                |
| `API_ENABLE`       | api       | HTTP 接口与动作调用不可用                               |
| `DASHBOARD_ENABLE` | dashboard | 监控面板不可用（面板本身就是可选组件）                           |

`business` 没有独立开关 —— 它是消息处理的唯一载体，关闭它等于服务整体不可用。

启用状态由 `php start.php roles` 提供，脚本**不自行解析 `.env`**：  
`.env < .env.{APP_ENV} < .env.local < 真实环境变量` 的叠加语义只有 `Env` 类能还原，  
脚本再实现一遍必然与之漂移（脚本读 `.env` 取监听地址属于「展示用」，而启用状态会直接  
决定启不启动某个进程，错不得）。

`status` 会把这类角色标为「已禁用」并列出开关名，与「已停止」（进程曾存在、当前不在）  
区分开 —— 否则排查时会把配置关闭误读成进程异常。

### 6.4 `php start.php` 内置命令

这些命令由 `start.php` 自身实现，不经 workerman：

| 命令         | 用法                                                                                         | 说明                                          |
| ---------- | ------------------------------------------------------------------------------------------ | ------------------------------------------- |
| `help`     | `php start.php help`                                                                       | 打印用法（等价 `-h` / `--help`）                    |
| `check`    | `php start.php check`                                                                      | **仅执行环境自检**，不启动服务。退出码 0/1                   |
| `info`     | `php start.php info [角色列表]`                                                                | **打印启动信息**：环境 / 框架版本 / 服务清单（含端口探测）。只读，不启动服务 |
| `roles`    | `php start.php roles`                                                                      | 输出各角色的启用清单（JSON），供管理脚本判断哪些角色被配置关闭。只读，不启动服务 |
| `env:init` | `php start.php env:init`                                                                   | 生成 `.env`，自动注入随机密钥                          |
| `token`    | `php start.php token <uid> [device_id] [ttl]`                                              | 生成调试用 Token                                 |
| `push`     | `php start.php push <uid\|device\|client> <target> [payload-json] [msg_id] [offline_mode]` | 提交一条定向推送任务                                  |

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

| 参数    | 必填 | 说明                                               |
| ----- | -- | ------------------------------------------------ |
| 第 1 个 | 是  | 目标类型：`uid` / `device` / `client`                 |
| 第 2 个 | 是  | 目标值                                              |
| 第 3 个 | 否  | 业务数据体（JSON 对象），默认 `{}`                           |
| 第 4 个 | 否  | `msg_id`，传入后参与幂等去重；不传则不去重                        |
| 第 5 个 | 否  | 离线策略覆盖：`drop` / `queue`；不传则用 `PUSH_OFFLINE_MODE` |

> **关键语义**：本命令**只负责入队**，不做实际投递。因此它**可以在服务未启动时执行** ——  
> 任务会在服务起来后被业务进程消费补投。真实投递由 `BusinessWorker` 的  
> `push-queue-consume` 定时任务完成。

> Windows 下注意引号：`bin\start.bat push uid 1001 "{\"title\":\"hi\"}" msg-1`。

### 6.5 workerman 内置命令

以下命令由 workerman 自身处理（`start.php` 未实现，交由框架）：

| 命令                  | 说明                |
| ------------------- | ----------------- |
| `start [-d]`        | 启动；`-d` 为守护模式     |
| `stop [-g]`         | 停止；`-g` 为优雅停止     |
| `restart [-d] [-g]` | 重启                |
| `reload [-g]`       | 平滑重载代码            |
| `status [-d]`       | 查看进程状态；`-d` 为实时刷新 |
| `connections`       | 查看连接详情            |

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
而是必需的一步：项目的 PHP 下限由**依赖**决定（当前为 8.2，来自 dev 工具链传递依赖的 `symfony/*` 7.x），  
Composer 生成的 `vendor/composer/platform_check.php` 会在 `autoload` 阶段直接抛 `RuntimeException`。  
如果不提前拦截，用户看到的是一段 Composer 堆栈，而不是「版本过低」这句人话。

**下限的真源**：`config/app.php` 的 `php_min`（当前 `8.2.0`）。两个脚本都从该文件读取，  
不另立一份；解析失败时回落到 `8.2.0`。

|               | Linux（`bin/start.sh`）                | Windows（`bin/start.ps1`）                                                                                                 |
| ------------- | ------------------------------------ | ------------------------------------------------------------------------------------------------------------------------ |
| 解释器来源         | `PHP_BIN` 环境变量，缺省取 PATH 中的 `php`     | `-PhpPath` 参数，缺省按候选顺序自动解析                                                                                                |
| 候选顺序          | 不适用（单值）                              | ① PATH 中的 `php.exe` / `php`；② **遍历 PATH 的每一个目录**找 `php.exe`；③ `D:\phpstudy_pro\Extensions\php\*\php.exe`、`C:\...`（按名称倒序） |
| 探测方式          | `$PHP_BIN -r 'echo PHP_VERSION_ID;'` | 同左                                                                                                                       |
| 版本过低          | **硬失败**，提示 `PHP_BIN=...` 用法          | 逐个候选跳过；`-PhpPath` 显式指定时**硬失败**（不回落到别的解释器）                                                                                |
| 全部候选不合格       | —                                    | 打印候选清单（含各自版本）+ 退出码 1                                                                                                     |
| 探测失败（无法取到版本号） | 仅告警，继续执行                             | 该候选被跳过；若为 `-PhpPath` 则告警后继续                                                                                              |

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
> 跑本项目仍会失败 —— 请把 CLI Interpreter 指向 `>= 8.2` 的解释器。

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
composer cs             # php-cs-fixer 排版自动修复（唯一允许写文件的风格命令）
composer cs:check       # php-cs-fixer 排版体检（--dry-run --diff，只报不改）
composer lint           # phpcs 审计：注释 / 命名 / 业务红线（只读，不写文件）
composer lint:summary   # phpcs 按嗅探器聚合的汇总视图（看趋势用）
composer lint:errors    # phpcs 仅错误（--warning-severity=0）
composer lint:self      # phpcs 自定义嗅探器自检（RedisKeys 漂移检测 + 作用域/豁免矩阵）
composer test           # phpunit
composer test:e2e       # php tests/e2e_check.php
composer test:client-e2e # php client/tests/E2E/ClientE2E.php（覆盖服务端 A~O 共 15 用例，服务端另有 P）
composer test:frontend  # 面板自动刷新语义（注入假 DOM，无需服务端）
composer test:sign      # HTTP 验签 8 形态（需 api + business 在线）
composer test:docs      # 文档关键数字只读核对（baseline 条目 / 测试数 / 过期字面量 / CI 门禁）
composer demo:http      # php tests/Api/http_demo.php（HTTP 接口调用示例，需 api/business 在跑）
```

> **风格工具分工（刻意不重叠）**：**排版**归 `php-cs-fixer`（只有 `composer cs` 会写文件）；
> **注释 / 命名 / 业务红线审计**归 `phpcs`（`composer lint`，只读）。
> **不要用 `phpcbf`** —— 它会与 fixer 对同一段代码反向修（如类型 long form ↔ short form 来回震荡）。
> 两侧刻意关掉的规则都写在配置文件头部：`.php-cs-fixer.dist.php` 的「四个界外」、
> `phpcs.xml.dist` 文末的「四类刻意排除」，每条都附实测数据与理由。

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

| 变量             | 默认值             | 说明                                   |
| -------------- | --------------- | ------------------------------------ |
| `APP_NAME`     | `gateway-push`  | 应用名                                  |
| `APP_ENV`      | `dev`           | `dev` / `test` / `prod`，决定额外加载哪个环境文件 |
| `APP_DEBUG`    | `true`          | 生产建议 `false`                         |
| `APP_TIMEZONE` | `Asia/Shanghai` | 时区                                   |

#### 日志

| 变量              | 默认值     | 说明                                                                |
| --------------- | ------- | ----------------------------------------------------------------- |
| `LOG_LEVEL`     | `debug` | `debug` / `info` / `warn` / `error`                               |
| `LOG_STDOUT`    | `true`  | 是否同时输出到控制台，生产建议 `false`                                           |
| `LOG_KEEP_DAYS` | `30`    | 日志保留天数，超期由 `log-cleanup` 任务清理（进程启动后 1s 补跑一次，此后每 24h 一次） |
| `LOG_MAX_MB`    | `10`    | `workerman.log` 单文件上限（MB）。**超出后原地截断、仅保留后半（前半丢弃），非归档轮转**；`0` = 不轮转 |
| `LOG_ARCHIVE_ENABLE`     | `false` | 是否启用日志归档：超期明文压进 `archive/{YYYY-MM}.tar.gz` 后删除明文（见下）              |
| `LOG_ARCHIVE_AFTER_DAYS` | `7`     | 明文转为归档的天数。**必须小于 `LOG_KEEP_DAYS`**，否则明文先被清理任务删掉、归档拿不到内容       |
| `LOG_ARCHIVE_DIR`        | 空       | 归档目录，留空 = `runtime/logs/archive`                                    |
| `LOG_ARCHIVE_KEEP_DAYS`  | `180`   | 归档包保留天数，超期删除                                                |
| `LOG_ARCHIVE_LEVEL`      | `6`     | gzip 压缩级别 `1`~`9`，越界自动回落 `6`                                   |

日志文件命名：`runtime/logs/{role}_{YYYY-MM-DD}.log` —— 按 **角色** 与日期分割。  
`role` 即进程的日志通道（`register` / `gateway` / `udp` / `business` / `api` / `dashboard`；  
启动期及 Linux `--role=all` 下为 `all`，未指定时为 `app`）。同角色的多个进程共写  
一个文件，行内 `[pid:N]` 用于区分 —— 与 pid 文件 `workerman_{role}.pid` 同一命名维度。

`error` 级日志额外双写一份跨角色汇总通道 `runtime/logs/error_{YYYY-MM-DD}.log`，  
无需按角色逐个翻文件即可速览全局错误。

> 通道在进程启动时定型：改代码或调整角色后需**重启对应角色进程**才会写入新文件，  
> 旧文件停止写入并按 `LOG_KEEP_DAYS` 自然淘汰，不需要迁移。

**日志归档**（`LOG_ARCHIVE_ENABLE=true` 时启用，由 `log-archive` 任务驱动，每 24h 一次、  
进程启动后 1s 补跑一次）把上面这条「按天淘汰」升级为三级生命周期：

| 阶段 | 形态 | 存活期 | 用途 |
| --- | --- | --- | --- |
| 热明文 | `logs/{role}_{YYYY-MM-DD}.log` | `LOG_ARCHIVE_AFTER_DAYS` 天 | 实时排查 |
| 冷归档 | `logs/archive/{YYYY-MM}.tar.gz` | 再保留 `LOG_ARCHIVE_KEEP_DAYS` 天 | 留证 / 审计 |
| 删除 | — | — | — |

包名按**文件自身日期**的月份生成，而非归档发生的月份 —— 9 月 3 日归档 8 月 27 日的日志  
会进 `2026-08.tar.gz`，包内不跨月。包内保留原始文件名，按需单取：

```bash
tar -tzf runtime/logs/archive/2026-09.tar.gz                        # 列出内容
tar -xzf runtime/logs/archive/2026-09.tar.gz api_2026-09-01.log     # 只取某一个
```

格式为 POSIX ustar + gzip，**只依赖 PHP 内置 zlib**（不需要 `zip` / `phar` 扩展）。

> 两点实现约定：
> 1. **先写包成功、再删明文** —— 中途失败时明文会留下，绝不会出现「明文已删、归档包里却没有」的数据空洞；既有的包若已损坏，会跳过本轮并保留明文，且不覆盖该包。
> 2. `log-archive` 必须排在 `log-cleanup` **之前**（两者都在启动后 1s 补跑，按声明顺序触发）。颠倒会让刚超期的明文先被清理任务删掉，归档永远拿不到内容 —— 不报错、不告警，只是归档恒为空。
>
> `LOG_ARCHIVE_ENABLE=false`（默认）时该任务空转一次即返回，`LOG_KEEP_DAYS` 是唯一生效的保留策略。

#### Redis

| 变量                | 默认值         | 说明                  |
| ----------------- | ----------- | ------------------- |
| `REDIS_HOST`      | `127.0.0.1` |                     |
| `REDIS_PORT`      | `6379`      |                     |
| `REDIS_PASSWORD`  | 空           |                     |
| `REDIS_DB`        | `0`         | **多套环境共用同一实例时务必区分** |
| `REDIS_TIMEOUT`   | `2.0`       | 连接超时（秒）             |
| `REDIS_POOL_SIZE` | `8`         | 每进程异步连接数            |
| `REDIS_PREFIX`    | `gwpush:`   | 全局键前缀               |

#### 密钥

| 变量                | 默认值 | 说明                                                                                   |
| ----------------- | --- | ------------------------------------------------------------------------------------ |
| `INTERNAL_SECRET` | 空   | Register / Gateway / BusinessWorker **三方内部通信密钥，必须一致**。留空则回退复用 `AUTH_SECRET`。生产建议独立配置 |
| `AUTH_SECRET`     | 空   | 业务鉴权密钥。由 `env:init` 生成 64 位十六进制值                                                     |

#### 连接鉴权

| 变量                 | 默认值    | 说明                                                    |
| ------------------ | ------ | ----------------------------------------------------- |
| `AUTH_ENABLE`      | `true` | 关闭后连接自动视为已鉴权                                          |
| `AUTH_MODE`        | `hmac` | `hmac` 自包含 Token / `store` Redis 反查                   |
| `AUTH_SIGN_ENABLE` | `true` | 是否校验报文签名（UDP 强烈建议开启）                                  |
| `AUTH_TOKEN_TTL`   | `7200` | Token 默认有效期（秒）                                        |
| `AUTH_CLOCK_SKEW`  | `300`  | 允许时钟偏移（秒），同时用于报文时间戳校验                                 |
| `AUTH_BIND_DEVICE` | `true` | 校验 `uid ↔ device_id` 绑定（首个绑定者胜出）                      |
| `AUTH_FAIL_CLOSE`  | `true` | 鉴权失败立即断开连接（先下发 4003 报文，`CLOSE_DELAY`=0.1s 后再断开，见 9.2） |
| `AUTH_TIMEOUT`     | `15`   | 建连后 N 秒未鉴权则断开                                         |

#### 会话

| 变量                      | 默认值    | 说明                                |
| ----------------------- | ------ | --------------------------------- |
| `SESSION_TTL`           | `7200` | 会话 Redis 过期时间（秒）                  |
| `SESSION_HEARTBEAT_TTL` | `90`   | 心跳超时阈值，需与 `HB_SESSION_TIMEOUT` 一致 |
| `SESSION_RESTORE`       | `true` | 断线重连自动恢复历史会话                      |

#### Register / WebSocket / UDP

| 变量                    | 默认值                        | 说明                                              |
| --------------------- | -------------------------- | ----------------------------------------------- |
| `REGISTER_ENABLE`     | `true`                     |                                                 |
| `REGISTER_LISTEN`     | `127.0.0.1:1238`           | Register 监听地址。集群部署改为 `0.0.0.0:1238` 或内网 IP      |
| `REGISTER_ADDRESS`    | 空                          | BusinessWorker 连接的注册中心地址；留空复用 `REGISTER_LISTEN` |
| `WS_ENABLE`           | `true`                     |                                                 |
| `WS_LISTEN`           | `websocket://0.0.0.0:8282` |                                                 |
| `WS_COUNT`            | `4`                        | WS 网关进程数（Windows 强制 1）                          |
| `WS_LAN_IP`           | `127.0.0.1`                | 集群部署改为本机内网 IP                                   |
| `WS_START_PORT`       | `2300`                     | 内部通信端口起始值，多机部署需错开                               |
| `SSL_ENABLE`          | `false`                    | 生产 WSS 必需                                       |
| `SSL_CERT` / `SSL_PK` | 空                          | 证书与私钥路径                                         |
| `UDP_ENABLE`          | `true`                     |                                                 |
| `UDP_LISTEN`          | `udp://0.0.0.0:8283`       |                                                 |
| `UDP_COUNT`           | `2`                        | UDP 网关进程数（Windows 强制 1）                         |
| `UDP_MAX_PACKET_SIZE` | `8192`                     | 单包上限，超出直接丢弃                                     |

#### UDP 入站 / 出站队列

| 变量                       | 默认值             | 说明                                         |
| ------------------------ | --------------- | ------------------------------------------ |
| `UDP_QUEUE_ENABLE`       | `true`          | UDP 网关 → 业务进程 的解耦队列开关                      |
| `UDP_QUEUE_KEY`          | `queue:udp:in`  | 入站队列 key（与 `gateway.udp.queue.key` **同源**） |
| `UDP_QUEUE_MAX_LEN`      | `10000`         | 队列长度上限，溢出告警                                |
| `UDP_QUEUE_BATCH`        | `100`           | 业务侧单次批量消费条数                                |
| `UDP_QUEUE_INTERVAL`     | `0.05`          | 业务侧消费周期（秒）                                 |
| `UDP_OUT_QUEUE_ENABLE`   | `true`          | 业务进程 → UDP 网关 的出站队列开关                      |
| `UDP_OUT_QUEUE_KEY`      | `queue:udp:out` | 出站队列 key                                   |
| `UDP_OUT_QUEUE_BATCH`    | `200`           | 单次原子弹出条数（Lua 取批）                           |
| `UDP_OUT_QUEUE_INTERVAL` | `0.05`          | 出站消费周期（秒）                                  |

#### 心跳

| 变量                           | 默认值  | 说明                  |
| ---------------------------- | ---- | ------------------- |
| `HB_PING_INTERVAL`           | `25` | Gateway 原生主动心跳间隔（秒） |
| `HB_PING_NOT_RESPONSE_LIMIT` | `2`  | 连续 N 次无上行数据则断开      |
| `HB_SESSION_TIMEOUT`         | `90` | 业务层会话心跳超时阈值（秒）      |
| `HB_CHECK_INTERVAL`          | `10` | 死连接巡检周期（秒）          |

> 实际容忍的无上行数据时长约为 `HB_PING_INTERVAL × HB_PING_NOT_RESPONSE_LIMIT`。

#### 推送

| 变量                    | 默认值              | 说明                            |
| --------------------- | ---------------- | ----------------------------- |
| `PUSH_ENABLE`         | `true`           |                               |
| `PUSH_OFFLINE_MODE`   | `queue`          | `drop` 直接丢弃 / `queue` 缓存待重连补投 |
| `PUSH_OFFLINE_TTL`    | `86400`          | 离线消息保留时长（秒）                   |
| `PUSH_OFFLINE_MAX`    | `100`            | 单用户离线消息条数上限，超出丢弃最旧            |
| `PUSH_REPLAY_BATCH`   | `50`             | 重连补投单批条数                      |
| `PUSH_IDEMPOTENT`     | `true`           | 按 `msg_id` 去重                 |
| `PUSH_IDEMPOTENT_TTL` | `600`            | 去重窗口（秒）                       |
| `PUSH_PAYLOAD_MAX`    | `4096`           | 单条数据体上限（字节），超出拒绝              |
| `PUSH_QUEUE_ENABLE`   | `true`           | 推送出站队列开关                      |
| `PUSH_QUEUE_KEY`      | `queue:push:out` | 推送队列 key                      |
| `PUSH_QUEUE_BATCH`    | `200`            | 单次原子弹出条数                      |
| `PUSH_QUEUE_INTERVAL` | `0.05`           | 消费周期（秒）                       |
| `PUSH_QUEUE_MAX_LEN`  | `10000`          | 积压告警阈值                        |

#### HTTP 接口

| 变量                   | 默认值                     | 说明                                                                                                                                                               |
| -------------------- | ----------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `API_ENABLE`         | `true`                  |                                                                                                                                                                  |
| `API_LISTEN`         | `http://127.0.0.1:8290` | 生产应仅监听内网地址或置于反向代理之后                                                                                                                                              |
| `API_SECRET`         | 空                       | 接口密钥；留空回退复用 `AUTH_SECRET`。<br>⚠️ 本密钥**同时是管理面凭证**：持有它即可调用运维动作 `kick` / `revoke` / `unbind` / `purge_offline`（踢线 / 撤销 Token / 解绑设备 / 清空离线队列），**不得下发给业务调用方**；改动 `API_LISTEN` 为非回环地址前须复查「对外接口文档」§9.4                                                                                                                                        |
| `API_SIGN_ENABLE`    | `true`                  | 接口验签开关。**仅供本地调试**：设为 `false` 后请求无需 `X-Timestamp` / `X-Sign`。<br>⚠️ 关闭**只在监听回环地址时生效**；监听 `0.0.0.0` / 具体网卡 / 域名时该开关被忽略并强制验签（`start.php check` 与启动日志都会告警）。<br>⚠️ 与 `AUTH_ENABLE` / `AUTH_SIGN_ENABLE` **无关** —— 那两个是 WS/UDP 报文层开关，对 HTTP 接口无任何影响；唯一关联是 `API_SECRET` 留空时复用 `AUTH_SECRET`（只共用密钥，不共用开关）                                                                                  |
| `API_SIGN_TTL`       | `300`                   | 请求时间戳有效窗口（秒），防重放；0 = 关闭校验                                                                                                                                        |
| `API_RATE_LIMIT`     | `600`                   | 单 IP 每分钟请求上限；0 = 不限                                                                                                                                              |
| `API_BODY_MAX`       | `65536`                 | 请求体上限（字节）                                                                                                                                                        |
| `API_ACTION_WAIT_MS` | `6000`                  | `POST /action` 同步等待窗（毫秒）。超窗未完成即以 `202` + `request_id` 回落，调用方凭 id 走 `GET /action/{id}` 补查。**必须大于 `ACTION_TIMEOUT × 1000`**，否则 Api 会先超窗、动作永远拿不到同步回执（启动日志会在超窗过小时告警） |

#### 监控面板

| 变量                  | 默认值                     | 说明                     |
| ------------------- | ----------------------- | ---------------------- |
| `DASHBOARD_ENABLE`  | `true`                  |                        |
| `DASHBOARD_LISTEN`  | `http://127.0.0.1:8291` | **默认仅本机**，运维数据不应直接暴露公网 |
| `DASHBOARD_REFRESH` | `5`                     | 页面轮询间隔（秒），0 = 关闭自动刷新   |

#### 限流

算法为**令牌桶**：`rate` = 令牌补充速率（条/秒，即长期平均上限），  
`burst` = 桶容量（允许的瞬时突发条数），`burst` 不得小于 `rate`（小于时按 `rate` 兜底并告警）。  
`rate = 0` 表示关闭该维度。

| 变量                                | 默认值           | 所在层                        |
| --------------------------------- | ------------- | -------------------------- |
| `RATE_LIMIT_ENABLE`               | `true`        | 总开关                        |
| `RATE_LIMIT_CONN_RATE` / `_BURST` | `20` / `40`   | L2 业务层，每连接                 |
| `RATE_LIMIT_UID_RATE` / `_BURST`  | `50` / `100`  | L2 业务层，每用户                 |
| `RATE_LIMIT_IP_RATE` / `_BURST`   | `200` / `400` | **L1 网关层，每 IP（仅 UDP 网关）**  |
| `RATE_LIMIT_PING_RATE` / `_BURST` | `5` / `10`    | 心跳指令独立配额（替代 conn 维度，更严）    |
| `RATE_LIMIT_CLOSE`                | `false`       | 超限是否断开连接（WS 生效；UDP 恒定静默丢弃） |
| `RATE_LIMIT_NOTIFY`               | `true`        | 超限是否回错误报文（UDP 恒定不回，避免反射放大） |
| `RATE_LIMIT_MEM_MAX`              | `20000`       | L1 内存桶数量上限，超出按最久未用淘汰一半     |

#### 业务动作 / 订阅 / 监控

| 变量                      | 默认值               | 说明                                                                          |
| ----------------------- | ----------------- | --------------------------------------------------------------------------- |
| `ACTION_TIMEOUT`        | `5`               | 动作回执超时（秒），超时回 `5000` 并记 `action_timeout`；0 = 关闭保护                           |
| `ACTION_REPORT_TTL`     | `86400`           | `report` 动作统计键保留时长（秒）                                                       |
| `ACTION_QUEUE_ENABLE`   | `true`            | 动作入站队列开关；关闭后 `POST /action` 直接回 `5000`                                      |
| `ACTION_QUEUE_KEY`      | `queue:action:in` | 动作任务队列 key（与 `business.action_queue.key` **同源**）                            |
| `ACTION_QUEUE_BATCH`    | `100`             | 单次原子弹出条数                                                                    |
| `ACTION_QUEUE_INTERVAL` | `0.02`            | 消费周期（秒）。比 `UDP_QUEUE_INTERVAL`（0.05）更密，因为 HTTP 调用方在同步等待                     |
| `ACTION_QUEUE_MAX_LEN`  | `10000`           | 队列积压上限。**超过即拒**（`503` / `5030`），与 `/push` 的「仅告警」不同 —— 动作有同步等待者，积压必须让请求方快速失败 |
| `ACTION_RESULT_TTL`     | `60`              | 动作回执键（`action:result:{request_id}`）保留时长（秒），决定 `GET /action/{id}` 可补查的时间窗    |
| `SUBSCRIBE_ENABLE`      | `true`            | 订阅功能总开关                                                                     |
| `SUBSCRIBE_TTL`         | `0`               | 订阅关系过期时间（秒），0 = 永不过期                                                        |
| `SUBSCRIBE_MAX_TOPICS`  | `100`             | 单用户订阅主题数上限，0 = 不限                                                           |
| `MONITOR_ENABLE`        | `true`            | 指标采集开关                                                                      |
| `MONITOR_INTERVAL`      | `60`              | 指标上报周期（秒）                                                                   |
| `MONITOR_TTL`           | `600`             | 指标数据保留时长（秒）                                                                 |

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

| 字段          | 类型     | 说明                                                                              |
| ----------- | ------ | ------------------------------------------------------------------------------- |
| `cmd`       | string | **必填**。指令名：`auth` / `ping` / `data` / `ack`。（服务端下发另有 `pong` / `push` / `error`） |
| `seq`       | string | 客户端消息序号，服务端在回执中原样带回，客户端据此关联请求                                                   |
| `ts`        | int    | 客户端时间戳（秒）。与服务器偏差超过 `AUTH_CLOCK_SKEW` 时判定 `4002`                                 |
| `uid`       | string | 用户 ID。**不参与签名**，因此 UDP 侧不可信（见 8.5）                                              |
| `device_id` | string | 设备 ID。**参与签名**                                                                  |
| `token`     | string | 鉴权 Token。**参与签名**，是报文内唯一可信的身份来源                                                 |
| `sign`      | string | 报文签名，算法见 8.2                                                                    |
| `data`      | object | 业务数据体。`data` 指令下形如 `{"action":"<动作名>","params":{...}}`                          |

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

| 阶段 | 方法                        | 是否 IO  | 内容                                        |
| -- | ------------------------- | ------ | ----------------------------------------- |
| 1  | `Auth::verifyLocal()`     | 无（纯计算） | 签名校验（`hash_equals` 防时序攻击）+ 过期时间 + 签发时间合理性 |
| 2  | `Auth::isRevoked()`       | Redis  | 查询撤销名单 `auth:revoked:{fingerprint}`       |
| 3  | `Auth::checkDeviceBind()` | Redis  | 校验 `uid ↔ device_id` 绑定（首次绑定者胜出）          |

> 撤销名单的键是 **Token 的 SHA-256 前 32 位指纹**，明文 Token 不落盘。

### 8.4 指令（cmd）清单

| cmd     | 方向 | 需要鉴权   | 说明                                            | 回执                                                        |
| ------- | -- | ------ | --------------------------------------------- | --------------------------------------------------------- |
| `auth`  | 上行 | 否（白名单） | 提交 Token 完成鉴权                                 | `ack`（含 `uid` / `device_id` / `protocol` / `reconnected`） |
| `ping`  | 上行 | 否（白名单） | 心跳                                            | `pong`（回带 `seq`）                                          |
| `data`  | 上行 | 是      | 业务动作入口，`data.action` 指定动作名                    | `ack` 或 `error`（由动作决定，见 8.7）                              |
| `ack`   | 上行 | 是      | 客户端对下行 `push` 报文的确认                           | 无（仅记 `push_ack` 指标）                                       |
| `pong`  | 上行 | 否      | 对服务端原生心跳的响应                                   | 无                                                         |
| `pong`  | 下行 | —      | 对客户端 `ping` 的响应                               | —                                                         |
| `push`  | 下行 | —      | 服务端定向推送的业务报文                                  | 客户端应回 `ack`（服务端据此计 `push_ack`）                            |
| `error` | 下行 | —      | 错误报文，带 `data.code` / `data.msg` 与 `ref`（触发指令） | —                                                         |

**鉴权前白名单**：`AUTH_ENABLE=true` 时，未鉴权连接只允许 `auth` 与 `ping`  
（定义于 `config/app.php` 的 `auth.allow_cmds`），其他指令一律回 `4003`。

### 8.5 身份可信来源（安全关键）

两条通道的身份来源不同，**不可混用**：

| 通道        | 可信来源                                      | 原因                                                                 |
| --------- | ----------------------------------------- | ------------------------------------------------------------------ |
| WebSocket | 鉴权时写入的**进程内映射** `$authed[clientId] = uid` | 报文里的 `uid` 由客户端自填且**不在签名覆盖范围内**，采信即等于允许冒充                          |
| UDP       | 报文 **Token 载荷**中的 `uid`                   | UDP 无连接实体、无鉴权映射。签名基串不含 `uid`（可篡改），但 `token` 参与签名且其载荷由服务端密钥 HMAC 保护 |

Token 不可信时返回空串（**拒绝放行**），而不是回退到报文中的 `uid`。  
仅在鉴权整体关闭（`AUTH_ENABLE=false`）且报文确实不带 Token 时才回退报文字段。

> 这条规则的实际作用：UDP 报文即使被篡改 `uid`，也无法冒充他人 —— 因为动作级鉴权  
> 检查的是 Token 解析出的 `uid`，而 Token 无法伪造。

### 8.6 错误码

| 码      | 常量                   | 文案          | 典型触发场景                          |
| ------ | -------------------- | ----------- | ------------------------------- |
| `0`    | `CODE_OK`            | ok          | 成功                              |
| `4000` | `CODE_BAD_PACKET`    | 报文格式错误      | 空报文 / 非 JSON / 缺 `cmd` / 长度超限   |
| `4001` | `CODE_BAD_SIGN`      | 签名校验失败      | 签名不匹配 / 缺 `sign` / 服务端未配置密钥     |
| `4002` | `CODE_BAD_TIMESTAMP` | 时间戳偏差超出允许范围 | 客户端与服务器时钟偏差 > `AUTH_CLOCK_SKEW` |
| `4003` | `CODE_UNAUTHORIZED`  | 连接未鉴权       | 未鉴权就发业务指令 / 鉴权超时                |
| `4004` | `CODE_AUTH_FAILED`   | 鉴权失败        | Token 结构非法 / 签名错 / 被撤销 / 设备不匹配  |
| `4005` | `CODE_TOKEN_EXPIRED` | Token 已过期   | `exp` 已过                        |
| `4006` | `CODE_UNKNOWN_CMD`   | 未知指令        | 指令或 `data.action` 未注册           |
| `4007` | `CODE_PARAM_MISSING` | 缺少必要参数      | 参数校验失败（缺失 / 类型错 / 越界）           |
| `4008` | `CODE_RATE_LIMIT`    | 请求频率超限      | L2 限流拒绝（WS 回错误；UDP 静默丢弃）        |
| `5000` | `CODE_SERVER_ERROR`  | 服务端内部错误     | 处理器抛异常 / 动作回执超时                 |

**HTTP 接口的业务码独立**（`src/Api/Bootstrap.php`）：

| 码      | HTTP | 含义                                 |
| ------ | ---- | ---------------------------------- |
| `0`    | 200  | 成功                                 |
| `4000` | 400  | 参数错误                               |
| `4001` | 401  | 验签失败 / 缺少 `X-Timestamp` 或 `X-Sign` |
| `4002` | 401  | 时间戳超出允许窗口                          |
| `4004` | 404  | 接口不存在                              |
| `4029` | 429  | 请求频率超限                             |
| `5000` | 500  | 服务端内部错误                            |

### 8.7 业务动作（data.action）

上行格式：

```json
{ "cmd": "data", "seq": "c-0002", "ts": 1690000000, "device_id": "dev-001", "token": "...", "sign": "...",
  "data": { "action": "report", "params": { "topic": "etc.pass", "count": 3 } } }
```

内置 **11 个**动作（声明于 `config/actions.php`）：7 个面向客户端 + **4 个运维动作**（P4 新增，
`kick` / `revoke` / `unbind` / `purge_offline`）。同一个动作可经**三条通道**触发，  
差异全部由声明表达，处理器代码不含任何通道判断：

| 动作            | 参数                                                                                       | 回执方式（WS / UDP / HTTP）            | HTTP 通道 | 回执内容                                                                                      |
| ------------- | ---------------------------------------------------------------------------------------- | -------------------------------- | ------- | ----------------------------------------------------------------------------------------- |
| `echo`        | `*`（原样透传）                                                                                | `sync` / `sync` / `sync`         | ✅       | `{action, channel, protocol, params, at}`                                                 |
| `session`     | 无                                                                                        | `sync` / `sync` / —              | ❌ 未开放   | `{action, client_id, uid, device_id, protocol, channel, online, connect_at, online_secs}` |
| `report`      | `topic`(必填, 1~~64 字符, 字符集 `[A-Za-z0-9_:.\-]`)、`count`(int, 1~~10000, 默认 1)、`value`(json) | `sync` / **`none`（静默）** / `sync` | ✅       | `{action, topic, accepted, total, at}`                                                    |
| `subscribe`   | `topic`(同上)                                                                              | `sync` / `sync` / `sync`         | ✅       | `{action, uid, topic, subscribers, at}`                                                   |
| `unsubscribe` | `topic`(同上)                                                                              | `sync` / `sync` / `sync`         | ✅       | `{action, uid, topic, subscribers, at}`                                                   |
| `topics`      | 无                                                                                        | `sync` / `sync` / `sync`         | ✅       | `{action, uid, topics[], count, at}`                                                      |
| `notify`      | `value`(json)、`msg_id`(string, ≤64)、`offline_mode`(`""`/`drop`/`queue`)                  | `sync` / `sync` / `sync`         | ✅       | `{action, target, msg_id, queued, at}`                                                    |
| `kick`        | `client_id`(≤128) 与 `uid`(≤64) **二选一**、`reason`(≤128, 可选)                              | ❌ / ❌ / `sync`（**仅限 HTTP**）    | ✅       | `{action, uid, requested, closed, skipped, failed, skipped_ids[], failed_ids[], reason, at, note}` |
| `revoke`      | `token`(**必填**, ≤2048, 只接受明文)、`ttl`(int, 0~~2592000)                                   | ❌ / ❌ / `sync`（**仅限 HTTP**）    | ✅       | `{action, fingerprint, ttl, at, note}`                                                    |
| `unbind`      | `uid`(**必填**, ≤64)                                                                      | ❌ / ❌ / `sync`（**仅限 HTTP**）    | ✅       | `{action, uid, unbound, at, note}`                                                        |

**三条通道的回执落地方式完全不同**：

| 通道   | `sync` 的含义                                                                 | 报文形式                           |
| ---- | -------------------------------------------------------------------------- | ------------------------------ |
| WS   | 结果直接下发当前连接                                                                 | `{cmd:"ack", seq, data:{...}}` |
| UDP  | 结果写 `queue:udp:out` → 网关 `sendto`（**不是直接回包**，UDP clientId 不在 Gateway 连接表内） | 同上                             |
| HTTP | 结果写 `action:result:{request_id}` → Api 轮询取回 → HTTP 响应体                     | 见 9.9                          |

**关于 `report` 的 UDP 静默**：UDP 上报通常高频且客户端不关心单条结果，回执会造成  
双向流量放大。它的处理器代码**不含任何通道判断** —— 是否下发完全由 `config/actions.php`  
的 `reply` 声明表达。这是「同一处理器、不同通道不同回执策略」的标准示范。

**关于 `session` 不开放 HTTP**：本动作的语义锚点是「当前连接」（`clientId`），而 HTTP  
通道下没有连接实体（`clientId` 为 `http:{request_id}`），调用无意义 —— 需按 uid 查会话请  
读 `session:*` 键或新增专用动作。

**HTTP 通道的开启语义**：`echo` / `report` / `subscribe` / `unsubscribe` / `topics` /  
`notify` 六个动作在声明中显式写了 `'http' => true`；其余动作**默认拒绝**。这是因为  
HTTP 调用方持有接口密钥即代表任意 uid 发起动作（显式授权），故采用「默认拒绝、逐动作  
开启」的白名单语义。白名单有两道防线：Api 侧入队前拦截（`400` / `4006`），  
`ActionRunner::run()` 内再拦一次（动作队列是 Redis 键，任何持有 Redis 凭证者都可直接写入任务）。

**两个方向的通道白名单（新增动作必读）**：

| 声明字段 | 方向 | 缺省 | 用途 |
| ---- | ---- | ---- | ---- |
| `http => true` | **额外**开放 HTTP | 不开放 | 「客户端动作不该被 HTTP 调」 |
| `channels => [...]` | **只**在这些通道开放 | 全通道放行 | 「运维动作不该被客户端调」 |

⚠️ **`http` 是单向的**，它只表达「额外开放 HTTP」，**不表达「仅限 HTTP」** ——
WS / UDP 侧**凡注册即可用**。因此 `kick` / `revoke` / `unbind` / `purge_offline` 四个运维动作**必须**
同时声明 `channels => [ActionContext::CHANNEL_HTTP]`；漏写即等于把管理面能力开放给所有
终端用户（任何持自己合法 Token 的客户端都能经 WS 踢掉任意 `client_id`），且**不报错、不告警**。
该约束已由 `tests/Unit/OpsActionContractTest.php` 钉成硬断言。

**三个运维动作的语义边界**（最易误解，调用前必读）：

| 动作 | 做什么 | **不做什么** |
| ---- | ---- | ---- |
| `kick` | 断开 TCP 连接 | **不撤 Token** —— 触发 `markOffline()` **保留会话供重连**，客户端可立即重连成功 |
| `revoke` | 把 Token 加进撤销名单 | **不断开已有连接** —— WS 侧该 Token 的下一次鉴权才回 `4001`，UDP 侧下一个包被丢弃 |
| `unbind` | 删除 `auth:bind:{uid}` | **不踢线** —— 已在线的旧设备不受影响，仍在线、仍可推送 |

⇒ 「踢下线且禁止重连」= **先 `revoke`、后 `kick`**（顺序反了时客户端正好落在重连窗口内，
会用同一 Token 重连成功，表现为「明明执行了却没效果」）。
UDP **无踢线**（无连接实体，`closeClient()` 对其无效），该形态计入 `skipped` 而非 `failed`。

⚠️ 三个运维动作的 `auth` 显式为 `false`：它们的 uid 是**操作对象**（入参），不是调用方身份。
调用方身份由 HTTP 接口密钥担保 —— **持有 `API_SECRET` 即拥有踢线 / 撤销 / 解绑权**
（详见「对外接口文档」§9.4「管理面凭证」，该等式在什么条件下变成真实提权面也写在那节）。

**动作级参数校验**（`ParamValidator`，白名单语义）：

| 支持类型 | `string` / `int` / `float` / `bool` / `array` / `json`                                       |
| ---- | -------------------------------------------------------------------------------------------- |
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

| 步骤 | 方法                           | 关键行为                                                                                                                                                                        |
| -- | ---------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1  | `onConnect($clientId)`       | 记 `conn_open`；鉴权开启时注册**鉴权超时定时器**（`AUTH_TIMEOUT` 秒后仍未鉴权则断开并回 `4003`）                                                                                                         |
| 2  | `onMessage($clientId, $raw)` | 记 `msg_in`；已鉴权则 `Session::touch()` 刷新心跳（**任意上行数据都算活跃**）；`Message::decode()` 解码，失败回 `4000`                                                                                   |
| 3  | `guardRate()`                | L2 限流：心跳走 `ping` 维度、业务走 `conn` 维度，已鉴权再叠加 `uid` 维度；多个桶**在一次 Redis 往返内原子判定**（任一不足即整单拒绝且均不扣减，防配额泄漏）；超限回 `4008`                                                                 |
| 4  | `dispatch()`                 | 鉴权白名单校验（未鉴权且非 `auth`/`ping` → `4003`）；`Router::command($cmd)` 查表；未注册 → `4006`；处理器异常统一兜底为 `5000`                                                                             |
| 5  | `handleAuth()`               | `verifyLocal`（同步）→ `isRevoked`（异步）→ `checkDeviceBind`（异步）→ `bindSession`                                                                                                    |
| 6  | `bindSession()`              | `Session::restore()` 按 `device_id` 找历史会话：命中且 `client_id` 不同 → **踢掉旧连接**（保证设备唯一在线）；`Session::bind()` 写会话；WebSocket 额外 `Gateway::bindUid()` 使 `sendToUid` 可用；回 `ack`；最后补投离线消息 |

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

| 场景                             | 使用的通道                                                                                                       | 说明                                                                                                                                                                                                    |
| ------------------------------ | ----------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 指令回执（`ack` / `pong` / `error`） | `Gateway::sendToClient`                                                                                     | 精确到连接                                                                                                                                                                                                 |
| 业务动作回执                         | `ActionContext::reply()` → `sender` → `Bootstrap::respond()`                                                | 由动作执行器统一构造                                                                                                                                                                                            |
| 定向推送（WS 目标）                    | `Gateway::sendToClient`（`via=session`）或 `Gateway::sendToUid`（`via=native`）                                  | 见 9.6                                                                                                                                                                                                 |
| 主动断开                           | 先 `Gateway::sendToClient($errPacket)`，延迟 `Bootstrap::CLOSE_DELAY`（0.1s）后再 `Gateway::closeClient($clientId)` | **刻意不用 close 的「附带消息」通道** —— workerman 5.x 的 `TcpConnection::close()` 在 `send()` 之后若发送缓冲为空会立即 `destroy()` → `fclose()`，实测 30 轮命中 6 轮以 **RST** 收场，已写入的报文被一并丢弃（客户端读到 0 字节）。拆成两步后由 TCP 顺序性保证「报文先到、FIN 后到」 |

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

| 层级      | 触发点    | 报文特征                                                                 |
| ------- | ------ | -------------------------------------------------------------------- |
| 传输层 ack | 网关收包即回 | `{"cmd":"ack","seq":"...","data":[]}` —— **`data` 为空且无 `action` 字段** |
| 业务层回执   | 动作执行完成 | 带 `data.action`（如 `{"cmd":"ack","data":{"action":"echo",...}}`）      |

> 判定方式：先 `isset($data['action'])` 判断层级。

**UDP 的业务指令过滤**：业务进程只放行 `data` 与 `ack` 两类指令。  
`auth` 所需的会话绑定已在 ⑨ 完成；`ping` 已由网关即时回执 —— 再走一遍会造成重复回执与重复补投。

**限流的两层分工**：

| 层  | 位置                            | 维度                          | 实现              | 超限处置                  |
| -- | ----------------------------- | --------------------------- | --------------- | --------------------- |
| L1 | UDP 网关 `onUdpMessage`，**验签前** | 每来源 IP                      | 进程内内存桶，**零 IO** | **静默丢弃**（不回错误，避免反射放大） |
| L2 | 业务进程 `handleUdpJob`           | 每虚拟连接 `udp:ip:port` + 每 uid | Redis 令牌桶       | **静默丢弃**              |

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

| 关键点           | 说明                                                                             |
| ------------- | ------------------------------------------------------------------------------ |
| 复用网关自身 socket | 用 `stream_socket_sendto` 在 unconnected socket 上指定目标地址，**无建连竞态、无额外 fd 开销**      |
| 多进程并发安全       | 取批由 Lua 脚本保证原子性，`UDP_COUNT > 1` 不会重复消费                                         |
| 业务动作的 UDP 回执  | 走**同一条**出站通道（`Push::sendToUdpClient()`），因此 `Bootstrap::send()` 的调用方无需感知协议差异    |
| 兼容性处理         | workerman 5.x 用 `getMainSocket()`，4.x 用 `getSocket()`，代码按 `method_exists` 择优取值 |

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

| 入口       | 用法                                         | 说明                   |
| -------- | ------------------------------------------ | -------------------- |
| HTTP 接口  | `POST http://127.0.0.1:8290/push`          | 外部系统调用，需 HMAC 验签     |
| Redis 队列 | `RPUSH gwpush:queue:push:out '<job-json>'` | 外部系统可直接写队列，绕开 HTTP 层 |
| 运维命令     | `php start.php push <类型> <目标> ...`         | 调试与手工触发              |
| 业务动作     | `data.action = notify`                     | 客户端请求服务端向**本人**推送    |

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

| 通道        | 判据                                                    |
| --------- | ----------------------------------------------------- |
| WebSocket | `Gateway::isOnline($clientId)` —— 以 Gateway 连接表为准，准实时 |
| UDP       | 会话存在且 `offline_at` 为空 —— UDP 无连接实体，只能靠会话标记            |

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

| 字段        | 说明                                                             |
| --------- | -------------------------------------------------------------- |
| `seq`     | 等于 `msg_id`；调用方未提供时服务端生成 `p-{16位随机十六进制}`                       |
| `offline` | `0` 实时投递 / `1` 重连补投                                            |
| `source`  | 来源标识：`http` / `cli` / `direct` / `action.notify` / 外部写队列时的自定义值 |

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

| 设计要点                          | 说明                                           |
| ----------------------------- | -------------------------------------------- |
| 离线列表按 **uid** 聚合（不是 clientId） | 断线重连后 `clientId` 必然变化，而 `uid` 稳定             |
| 语义为「**至少一次**」                 | 补投给首个恢复的连接；客户端需按 `msg_id` 去重                 |
| 触发条件天然幂等                      | WebSocket 只在鉴权成功时触发一次；UDP 只在会话从「不存在」变「存在」时触发 |
| 单轮上限                          | 单次最多补投 `PUSH_REPLAY_BATCH` 条，积压多时靠下轮继续       |

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

Api 进程（`role=api`，默认 `http://127.0.0.1:8290`）对外提供 5 个接口。

#### 公共前置（除 `/health` 外的全部接口）

```
① 限流前置（单 IP 滑动分钟窗口，API_RATE_LIMIT=600/min）
     进程内静态计数快速拒绝 + Redis INCR 跨进程计数
     超出 → 429 / 4029
② 读请求头 X-Timestamp / X-Sign
     缺失 → 401 / 4001
③ 时间戳窗口校验 |now - ts| > API_SIGN_TTL(300) → 401 / 4002
④ 验签 hash_hmac('sha256', "{X-Timestamp}|{原始请求体}", api.secret)
     ⚠ 使用**原始请求体**而非解析后数组，避免键序/转义差异导致验签失败
     hash_equals 比对 → 失败 401 / 4001
```

> `GET` 请求无请求体，签名基于**空串**计算：`hmac_sha256("{ts}|", secret)`。

#### POST /push —— 异步受理

```
⑤ 解析请求体，校验 target_type / target / payload 类型
└─ ⑥ Push::enqueue(...) → RPUSH queue:push:out → 回 200 {"code":0,"msg":"accepted",...}
```

响应 `200` **只表示任务已入队**，真实投递由业务进程异步完成（见 9.6）。

#### POST /action —— 同步等待业务动作执行

```
⑤ 解析请求体，取 action / uid / device_id / params
⑥ 白名单前置：未知动作 或 未开放 HTTP 的动作 → 400 / 4006（不必进队列）
     config/actions.php 中 http=true 的六个动作：echo / report / subscribe / unsubscribe / topics / notify
⑦ 鉴权：动作声明 auth=true 且未提供 uid → 401 / 4003
⑧ 准入控制：LLEN queue:action:in ≥ ACTION_QUEUE_MAX_LEN → 503 / 5030
     （与 /push 的「仅告警」不同 —— 动作有同步等待者，积压必须让调用方快速失败）
⑨ RPUSH queue:action:in {request_id, packet, uid, device_id, source, channel, enqueue_at}
     request_id = bin2hex(random_bytes(8))，由 Api 生成并即时返回
        │
        ▼  business 进程定时任务 action-queue-consume（每 0.02s，Lua 原子取批 100 条）
     校验 request_id 合法性 → ActionRunner::run(clientId="http:{request_id}", channel=http)
        │
        ├─ 再次校验 HTTP 白名单（第二道防线）
        ├─ 执行处理器（与 WS/UDP 完全同一套处理器，零改动）
        └─ 回执 → ActionReply::store → SETNX action:result:{request_id} EX ACTION_RESULT_TTL(60)
              │
              ▼  api 进程退避轮询（10ms 起、×1.5 放大、上限 150ms）
          命中 → 200 {"code":0,"data":{"request_id","status":"done","result","packet"}}
          超窗（API_ACTION_WAIT_MS=6000）→ 202 {"data":{"status":"pending","result_url":"/action/{id}"}}
          客户端提前断开 → onClose 立即停掉轮询定时器
```

#### GET /action/{id} —— 回执补查

```
ActionReply::validRequestId() 格式校验（^[A-Za-z0-9_-]{1,64}$）→ 非法 400 / 4000
ActionReply::fetch() → 命中回 200；未就绪/已过期回 404 / 4004
     （响应体 data.status=pending + hint 说明是「仍在执行」还是「已超 TTL 被回收」）
```

#### 请求示例

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

```bash
# 动作调用（同步等待）：echo 原样回显
TS=$(date +%s)
BODY='{"action":"echo","uid":"user-1","params":{"probe":"curl","n":42}}'
SIGN=$(printf '%s|%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$API_SECRET" -r | cut -d' ' -f1)

curl -s -X POST http://127.0.0.1:8290/action \
  -H "Content-Type: application/json" \
  -H "X-Timestamp: $TS" -H "X-Sign: $SIGN" \
  --max-time 20 \
  -d "$BODY"
# => {"code":0,"msg":"ok","data":{"request_id":"f96e53866166af3e","status":"done",
#     "result":{"action":"echo","channel":"http","protocol":"http",
#               "params":{"probe":"curl","n":42},"at":1789983030}, ...}}
```

> 想一次跑完所有分支（含各类错误路径断言），直接用现成脚本：
>
> ```bash
> php tests/Api/http_demo.php          # 13 个场景 / 19 项断言，含签名构造全过程
> php tests/Api/http_demo.php --curl   # 额外打印等价 curl 命令
> node tests/Api/api_sign_check.js     # 8 项验签与错误分支断言
> ```
>
> 可直接导入 Postman 的集合同样覆盖 `/action` 全系列：  
> `postman/GatewayPush.postman_collection.json`（9 个请求，含集合级自动签名脚本）。

#### 接口清单

| 接口             | 方法   | 鉴权    | 语义       | 说明                                            |
| -------------- | ---- | ----- | -------- | --------------------------------------------- |
| `/health`      | GET  | **否** | —        | 存活探测，供负载均衡 / 容器探针使用                           |
| `/stats`       | GET  | 是     | 同步       | 返回 Redis 中的指标快照（`gauge` / `counter` / `task`） |
| `/push`        | POST | 是     | **异步受理** | 提交定向推送任务，入队即返回                                |
| `/action`      | POST | 是     | **同步等待** | 调用业务动作，等待 BusinessWorker 执行结果                 |
| `/action/{id}` | GET  | 是     | 同步       | 按 `request_id` 补查动作回执                         |

> `/stats` 需要 HMAC 验签，浏览器无法安全持有密钥 —— 这是必须单开一个**免鉴权**监控面板  
> 端点（`/metrics.json`）的原因。

#### 本地调试免签（`API_SIGN_ENABLE`）

`API_SIGN_ENABLE=false` 后，接口不再要求 `X-Timestamp` / `X-Sign`：

```bash
curl -s http://127.0.0.1:8290/stats
curl -s -X POST http://127.0.0.1:8290/action \
  -H "Content-Type: application/json" \
  -d '{"action":"echo","uid":"local","params":{"probe":1}}'
```

⚠️ **关闭只在监听回环地址时生效。** `listen` 绑 `0.0.0.0` / 具体网卡 / 域名时该开关会被
忽略并强制验签 —— `/push` 能推任意消息、`/action` 能执行动作，把开关暴露到网络上等于
业务入口裸奔。被护栏拦下时 `php start.php check` 与 api 启动日志都会明确告警。

> **与 `AUTH_ENABLE` / `AUTH_SIGN_ENABLE` 无关** —— 这两个属于 `config/app.php` 的  
> `auth` 段，作用点是 `Auth::enabled()`（连接鉴权）与 `Message::verify()`（八字段报文  
> `sign` 校验），**只作用于 WS / UDP 报文层**。HTTP 验签走独立的 `api` 段，  
> `authenticate()` 里不读 auth 段的任何开关。唯一关联是 `API_SECRET` 留空时回退复用  
> `AUTH_SECRET`：**只共用密钥，不共用开关** —— 这也是「关了 `AUTH_*` 却仍提示缺少  
> `X-Timestamp`」这一常见困惑的来源。

改完需**重启 api 角色**（配置在进程启动时读取）；启动横幅的「接口验签」行会显示实际
生效状态。**四处**自检会先探测模式（以空签名请求 `/stats`，200 即免签），免签时把
「错误签名必须被拒」类断言标记为 **SKIP** 而非失败 —— 口径统一，本地调试不再误报门禁红：

| 自检 | 免签下的表现 |
| --- | --- |
| `node tests/Api/api_sign_check.js` | 第 3、7 项 SKIP |
| `php tests/Api/http_demo.php` | 第 3 场景 SKIP |
| `php client/tests/E2E/ClientE2E.php` | 用例 H 标 SKIP（结论行追加「另 SKIP 1 项，免签模式」，退出码仍为 0） |
| `php tests/e2e_check.php` | 用例 H 标 SKIP |

#### 两类接口的差异（最容易误判的地方）

| 维度     | 推送类 `/push`           | 动作类 `/action`                                              |
| ------ | --------------------- | ---------------------------------------------------------- |
| 调用方预期  | 「收下了就行」               | 「要结果」                                                      |
| 成功判定   | HTTP `200` + `code=0` | HTTP `200` + `code=0` + `data.status=done`                 |
| 失败如何表达 | 入队失败 → `5xx`          | **入队前**失败 → `4xx`/`5xx`；**执行后**业务失败 → **HTTP `200`** + 业务码 |
| 队列积压   | 仅告警，继续入队              | 超 `ACTION_QUEUE_MAX_LEN` 直接 `503` / `5030`                 |
| 超时回落   | 无                     | `202` + `request_id`，靠 `GET /action/{id}` 补查               |

> **`/action` 判断成败必须看响应体的 `code`，不能只看 HTTP 状态码。** 例如 `report` 缺  
> `topic` 会返回 `HTTP 200` + `code=4007` + `data.status=failed` —— 因为「报文被正确受理并  
> 执行完毕」与「业务成功」是两件事，前者用 HTTP 状态表达，后者用业务码表达。

> **轮询而非阻塞订阅**：`BLPOP` / `SUBSCRIBE` 会独占 Redis 连接，而本项目连接池  
> 「无顺序保证」（见 15 节 D 项与硬约束 ⑮），并发在途请求一旦超过 `pool_size` 即耗尽连接池。  
> 轮询的代价只是若干次 `GET`，可忽略 —— 退避序列从 10ms 起、每次 ×1.5、上限 150ms。

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
    ├─ HGETALL + HDEL 清理已退出进程的残留字段（判定见下）
    └─ 仅 worker 0：conn_total / conn_ws / conn_udp（走 SCARD online:clients）
        │
        ▼
  监控面板进程（role=dashboard）只读渲染
```

**为什么业务侧不直接写 Redis**：每报文一次 `HINCRBY` 会把 Redis 变成瓶颈。  
进程内累加 + 定时批量刷入，把 Redis 写入从「每报文」降到「每 60 秒 × 进程数」。

**进程存活判定与残留清理（两个阈值，刻意不同）**：

`metrics:gauge` 的 TTL 是 **key 级**的，而**Hash 的 field 没有独立 TTL** ——  
只要还有任意一个活进程在 `HSET`，`EXPIRE` 就把整个 key 续期，已退出进程的  
`pid_at` / `proc` / `tasks` / `memory_bytes` 四个字段会**永久残留**  
（实测残留时长可达 4780s+，而 `MONITOR_TTL` 仅 600s），面板进程列表越拉越长、  
`HGETALL` 返回体持续膨胀。因此必须由采集侧主动清理：

| 层次       | 判定                                              | 作用                             |
| -------- | ----------------------------------------------- | ------------------------------ |
| 面板（展示）   | `now - pid_at > MONITOR_INTERVAL × 2`（最小 5s）     | 超出即标「已退出」并置灰，秒级灵敏              |
| 采集（删除）   | `pid_at < now - MONITOR_TTL`（即超过宽限期 600s）       | 归入待删列表，随下次 `report()` 一并 `HDEL` |

两者**不可互换**：展示阈值只有秒级，拿来做删除判据会在一次长任务阻塞或 GC 停顿时  
误删活进程字段，表现为面板进程反复闪烁；删除阈值则必须留足宽限。  
清理是幂等的（`HDEL` 重复执行只返回 0），多进程并发触发无副作用。

**采集分布的差异**：

| 进程                        | 上报方式                                      | 说明                                                 |
| ------------------------- | ----------------------------------------- | -------------------------------------------------- |
| BusinessWorker            | `Task` 定时任务 `monitor-report`（scope=all）   | 采集在线数（仅 worker 0）、内存、会话/推送/动作指标                    |
| UDP 网关                    | `Timer::add($monitorInterval, ...)` 自建定时器 | 只产出站维度指标；`report(false)` 跳过在线数采集（该进程无 Session 上下文） |
| Gateway / API / Dashboard | 不上报业务指标                                   | Gateway 由框架托管，API 只写队列，面板只读                        |

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

| 约束                                      | 说明                                                                                                                               |
| --------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| 三方 `secretKey` 必须一致                     | 来源 `app.internal.secret`，留空回退 `app.auth.secret`。**Register 实例需显式赋值 `secretKey`，默认空值会导致所有注册被拒**                                   |
| `GatewayClient::$registerAddress` 需显式设置 | `Lib\Gateway` **不会**自动继承 `BusinessWorker` 的注册中心配置；不设置会连向默认的 `127.0.0.1:1236`，导致推送/踢人静默失效                                         |
| 重启顺序                                    | `register → gateway → business`。杀掉 business 后若不重启 gateway，网关缓存的 BusinessWorker 地址仍指向死进程 → **WS 链路全断，而 UDP 链路正常**（UDP 走 Redis 队列） |

> **诊断线索**：**UDP 通而 WS 不通 ⇒ 查网关注册路由，别查业务逻辑。**

---

## 10. Redis 键空间

所有键都会再拼接 `REDIS_PREFIX`（默认 `gwpush:`）。

**键名的唯一声明处是 `src/Common/RedisKeys.php`** —— 全部逻辑键名（不含全局前缀）在该类中以
常量集中定义，带动态后缀的键（`{clientId}` / `{uid}` / `{md5(msg_id)}` / `{YYYYMMDD}` 等）
经其静态方法拼装，业务代码不再出现键字面量。跨进程共享的队列键由 config 引用同一常量作
`Env::str` 的回落值，使「生产者与消费者指向同一键」这一不变量不可能被破坏。

> 键名受 `tests/Unit/RedisKeysTest.php` 的金标断言保护 —— 任何改动都会使该测试失败，
> 这是刻意设计：改键 = 存量数据失配（在线的会话、挂起的离线消息、已撤销的 Token 名单），
> 必须配 `RENAME` 迁移脚本并全角色重启，不可单独发版。

### 会话与索引

| 键                          | 类型     | TTL           | 说明                                                                                                                                          |
| -------------------------- | ------ | ------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| `session:{clientId}`       | Hash   | `SESSION_TTL` | 会话主体：`client_id` / `uid` / `device_id` / `protocol` / `client_ip` / `client_port` / `gateway` / `connect_at` / `last_active` / `offline_at` |
| `heartbeat:{clientId}`     | String | `SESSION_TTL` | 最近活跃时间戳。**高频写入，单独成键以降低写放大**                                                                                                                 |
| `uid:clients:{uid}`        | Set    | `SESSION_TTL` | uid → clientId 集合（多设备在线）                                                                                                                    |
| `device:client:{deviceId}` | String | `SESSION_TTL` | deviceId → 当前活跃 clientId（单对一定向的定位依据）                                                                                                        |
| `online:clients`           | Set    | 无             | 全量在线 clientId（`SCARD` 得到在线数，**集群下天然全局**）                                                                                                    |
| `online:ws` / `online:udp` | Set    | 无             | 按协议维度的在线集合                                                                                                                                  |

### 鉴权

| 键                                | 类型     | TTL              | 说明                     |
| -------------------------------- | ------ | ---------------- | ---------------------- |
| `auth:revoked:{tokenSha256前32位}` | String | Token 剩余有效期      | Token 撤销名单（不落明文 Token） |
| `auth:bind:{uid}`                | String | `AUTH_TOKEN_TTL` | uid → deviceId 绑定关系    |

### 队列

| 键                 | 类型   | 说明                                      |
| ----------------- | ---- | --------------------------------------- |
| `queue:udp:in`    | List | UDP 网关 → 业务进程（入站）                       |
| `queue:udp:out`   | List | 业务进程 → UDP 网关（出站，网关 sendto）             |
| `queue:push:out`  | List | 推送任务队列（所有推送入口的汇合点）                      |
| `queue:action:in` | List | HTTP 动作任务队列（Api 入队 → BusinessWorker 执行） |

> 三条队列的分工：`queue:udp:in` / `queue:action:in` 是**入站**（网络层 → 业务层），  
> `queue:udp:out` / `queue:push:out` 是**出站**（业务层 → 网络层）。`queue:action:in`  
> 的元素含 `request_id`，这是它与 `queue:udp:in` 的唯一结构差异 —— 因为 HTTP 是唯一  
> 「有同步等待的调用方」的通道，必须有一条回程路径。

### 动作回程（HTTP 通道专用）

| 键                            | 类型     | TTL                         | 说明                                                                                                                          |
| ---------------------------- | ------ | --------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| `action:result:{request_id}` | String | `ACTION_RESULT_TTL`（默认 60s） | 动作回执报文（`Message::encode` 后的报文串）。业务进程用 `SET NX EX` 写入，**首次胜出**，重复写入被静默忽略（保证一个 `request_id` 只有一个终态）；Api 轮询读取后不删除，故 TTL 内可重复读取 |

### 推送

| 键                          | 类型     | TTL                   | 说明                                                        |
| -------------------------- | ------ | --------------------- | --------------------------------------------------------- |
| `push:offline:{uid}`       | List   | `PUSH_OFFLINE_TTL`    | 离线消息缓存，元素含 `payload` / `msg_id` / `source` / `offline_at` |
| `push:dedup:{md5(msg_id)}` | String | `PUSH_IDEMPOTENT_TTL` | 幂等去重标记                                                    |

### 订阅

| 键                         | 类型  | 说明                |
| ------------------------- | --- | ----------------- |
| `subscribe:uid:{uid}`     | Set | 用户订阅的主题集合（正向）     |
| `subscribe:topic:{topic}` | Set | 主题的订阅者 uid 集合（反向） |

### 限流

| 键                         | 类型     | 说明                                                      |
| ------------------------- | ------ | ------------------------------------------------------- |
| `rl:{dim}:{md5(id)}`      | Hash   | Redis 令牌桶（`dim` = `conn` / `uid` / `ping`），由 Lua 脚本原子判定 |
| `api:rate:{md5(ip)}:{分钟}` | String | HTTP 接口单 IP 分钟级计数                                       |

### 指标

| 键                            | 类型   | TTL                 | 说明                                                                            |
| ---------------------------- | ---- | ------------------- | ----------------------------------------------------------------------------- |
| `metrics:counter:{YYYYMMDD}` | Hash | 7 天                 | 当日累加型指标                                                                       |
| `metrics:gauge`              | Hash | `MONITOR_TTL`       | 瞬时指标 + 各进程内存 + 进程身份 + 定时任务健康度                                                 |
| `action:report:{topic}`      | Hash | `ACTION_REPORT_TTL` | `report` 动作的按主题计数（`count` / `last_at` / `last_uid` / `last_dev` / `last_seq`） |

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

| 接口                      | 说明                                                                            |
| ----------------------- | ----------------------------------------------------------------------------- |
| `GET /` 或 `/index.html` | 自包含 HTML 页面（内联 CSS/JS、零外链、可离线打开）                                              |
| `GET /metrics.json`     | 页面同源使用的 JSON 快照，附 `meta` 段（`now` / `interval` / `ttl` / `enable` / `refresh`） |

两者**均免鉴权**，安全边界由监听地址承担。需要远程访问时用 Nginx 反代（附加 Basic Auth）  
或 SSH 隧道，**不要把 8291 直接暴露到公网**。

**页面内容**：

| 区块           | 内容                                                                               |
| ------------ | -------------------------------------------------------------------------------- |
| 概览           | 在线连接数（总/WS/UDP）、上报时间新鲜度判定                                                        |
| 指标卡（11 组）    | 连接 / 消息收发 / 鉴权 / 会话心跳 / 定向推送 / 主题广播 / UDP 出站 / 业务动作 / **动作·HTTP 通道** / 限流 / 发送背压 |
| 未归类指标        | `GROUPS` 未覆盖的 counter 字段自动落入「未归类」卡，不会静默丢失                                        |
| 进程内存 · 上报周期表 | 每个 PID 的内存、角色名（`proc:{pid}`）、存活状态（依据 `pid_at:{pid}`）                             |
| 定时任务表        | 每个 PID 的任务健康度（任务名 / 周期 / 执行次数 / 上次耗时 / 状态）                                       |

**工程要点**：

- **模板按 mtime 缓存失效，改页面不用重启进程**。但必须显式  
  `clearstatcache(true, $path)` —— workerman 常驻进程没有 PHP 的请求边界，  
  stat 缓存不会被自动清理，`filemtime()` 会一直返回进程首次 stat 的旧值，  
  导致 mtime 比较恒等、模板永不重载。
- **数值列右对齐必须同时覆盖 `th.num` 与 `td.num`**，只写 `td.num` 会让表头左对齐、  
  数据右对齐，整列视觉错位。
- 页面存活判定用 `pid_at:{pid}`（阈值 `MONITOR_INTERVAL × 2`），**不**用 gauge 的 key 级 TTL ——  
  后者是删除阈值（`MONITOR_TTL`，600s），两者语义不同不可互换，详见 9.10。

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
    //   auth = true, reply = {ws: sync, udp: sync, http: sync}, timeout = 5
    // http 未声明时取 false（默认不开放 HTTP 调用）
),
```

### 步骤 3：如需经 HTTP 调用，显式开启白名单

```php
// config/actions.php
'order_query' => array(
    'handler' => \GatewayPush\Business\Action\OrderQueryAction::class,
    'params'  => array(/* ... */),
    'http'    => true,     // ← 唯一开关：不写即为 false，POST /action 会回 400 / 4006
),
```

开启前请确认两件事：

| 检查项                                   | 原因                                                                |
| ------------------------------------- | ----------------------------------------------------------------- |
| 该动作**不依赖 `clientId` 语义**              | HTTP 通道下 `clientId` 为 `http:{request_id}`，无连接实体。`session` 就是因此不开放 |
| `timeout < API_ACTION_WAIT_MS / 1000` | 否则 Api 会先超窗回落 `202`，动作永远拿不到同步回执（启动日志会告警）                          |

> 安全边界：HTTP 调用方持有接口密钥即可代表**任意 uid** 发起动作（`uid` 在 body 中给出，  
> 参与 HMAC 签名覆盖），与 `/push` 同权，不构成提权 —— 但这个能力是显式授权的，  
> 所以采用「默认拒绝、逐动作开启」而非「默认开启」。新动作请按需开启，不要批量打开。

### 声明字段说明

| 字段            | 说明                                                                                                                         |
| ------------- | -------------------------------------------------------------------------------------------------------------------------- |
| `handler`     | 处理器类名，必须实现 `ActionInterface`                                                                                               |
| `description` | 说明文案，供 `php start.php check` 与运维接口展示                                                                                       |
| `auth`        | 是否要求已鉴权（默认 `true`）。**UDP 通道没有连接级鉴权闸门，此字段是 UDP 侧唯一的业务鉴权防线**，业务动作不应轻易置 `false`                                               |
| `reply`       | 回执方式，按通道声明：`'sync'` 单值表示三通道相同，或 `['ws'=>'sync','udp'=>'none','http'=>'sync']` 按通道分别声明。**未声明的通道回落 `sync`** —— 故既有的双通道声明无需改动 |
| `http`        | 是否开放 HTTP 通道（默认 **`false`**，`POST /action` 不可调用）。详见步骤 3                                                                    |
| `timeout`     | 回执超时（秒），超时记 `action_timeout` + 告警 + 回 `5000`；`0` 表示关闭保护。经 HTTP 调用时须小于 `API_ACTION_WAIT_MS`                                 |
| `params`      | 参数规则（见 8.7）；`'*'` 表示原样透传（仅供 `echo` 这类回显动作）                                                                                 |
| `options`     | 动作私有配置，处理器经 `$ctx->option('key', $default)` 读取                                                                             |

### 回执抑制语义（重要）

声明 `reply = none` 时，`reply()` / `replyError()` **不会真正下发**，但**仍会把上下文标记为「已回执」**，  
使超时保护不再触发。

这样处理器可以始终按「处理完就回执」的写法实现，是否真正下发完全由配置决定 ——  
同一个处理器在 WS 上可回执、在 UDP 上静默，**不需要写任何 `if` 分支**。

> 例外：**HTTP 通道的错误必须下发**，否则调用方会一直等到超窗（6s）才知道失败。  
> 因此 `emitError()` 只对 UDP 静默，HTTP 与 WS 一样会把错误码写进回程桥 / 直接下发。

### 新增动作的检查清单

1. 处理器类实现 `ActionInterface`
2. 需要采集的指标名加入 `config/app.php` 的 `monitor.metrics` 列表
3. 参数规则声明完整（未声明的入参会被**静默丢弃**）
4. 涉及外部输入的字符串参数务必加 `max_len` + `pattern`（尤其是会拼进 Redis 键的参数）
5. 需要读取他人数据的能力**不要做成入参**（参考 `session` / `topics` 的写法：只能查自己）
6. 需要经 HTTP 调用的，显式声明 `'http' => true`，并确认 `timeout < API_ACTION_WAIT_MS / 1000`
7. 跑 `php start.php check` 确认动作清单与处理器可用性校验通过

---

## 13. 测试与静态分析

### 13.1 命令

```bash
composer analyse        # PHPStan（level 6；生产 baseline 已清空，测试 baseline 301 条目/311 条 = 273 missingType + 38 语义）
composer test           # PHPUnit（519 tests / 1465 assertions；含 client/tests/Unit）
composer lint           # phpcs 审计：注释 / 命名 / 业务红线（只读，不写文件）
composer lint:self      # phpcs 自定义嗅探器自检（RedisKeys 漂移 + 作用域/豁免矩阵）
composer cs:check       # php-cs-fixer 排版体检（只报不改；落地用 composer cs）
composer test:e2e       # 端到端自检（16 个用例）
composer test:client-e2e # 客户端 SDK 端到端（覆盖服务端 A~O 共 15 用例，服务端另有 P；需五角色 + Redis）
```

> **风格工具分工（刻意不重叠）**：**排版**归 `php-cs-fixer`，只有 `composer cs` 会写文件；
> **注释 / 命名 / 业务红线审计**归 `phpcs`（`composer lint`，只读不写）。
> 禁用 `phpcbf` —— 它会与 fixer 对同一段代码反向修（类型 long form ↔ short form 来回震荡）。
> 两侧刻意排除的规则见各自配置文件的头部注释，每条都附实测理由。
>
> `phpcs` 侧另有两条**项目自定义嗅探器**（`tools/phpcs/Sniffs/`）：`ForbiddenCallSniff`
> 拦截 `src/` 与 `client/src/` 内的 `exit`·`die`·`sleep`·`usleep`·`pcntl_fork`；
> `RedisKeyLiteralSniff` 拦截 Redis 键名硬编码。两者由 `composer lint:self` 自检兜底。

> HTTP 侧的验签与动作接口断言另有两个**独立脚本**（不在 PHPUnit 套件内，需服务已启动）：
>
> ```bash
> composer demo:http               # 或 php tests/Api/http_demo.php
> node tests/Api/api_sign_check.js # 验签与错误分支断言（8 项）
> ```

### 13.2 静态分析约束

| 项           | 约束                                                                       |
| ---------- | ------------------------------------------------------------------------ |
| PHPStan 版本 | `^2.0`                                                                   |
| 内存         | **必须带 `--memory-limit=512M`**（本机 php.ini 仅 128M，否则子进程崩溃）；已写入 composer 脚本 |
| 分析范围       | `paths` = `src`、`client/src`、`start.php`、`tests`、`client/tests`（共 119 文件）。**`tests` 必须在列**，否则 `phpstan-phpunit` 的断言 / mock 规则不会生效 |
| 分析口径       | `phpVersion: 80200` —— 刻意锚定在**项目下限**，用于**拦截 8.3+ 语法误用**，保证 8.2 兼容性                 |
| 扩展         | `phpstan-strict-rules` + `phpstan-phpunit`，**在 `includes` 里显式声明**（本项目未装 `phpstan/extension-installer`，不写 `includes` 则规则一条都不生效） |
| strict-rules | `strictRules.allRules: true`，仅刻意关闭 4 条：`disallowedEmpty`、`booleansInConditions`(+`booleansInLoopConditions`)、`dynamicCallOnStaticMethod`（理由见 `phpstan.neon` 内的逐条注释） |
| 收敛策略       | **两份 baseline**：`phpstan-baseline.neon`（生产代码，2026-09-23 语义清洗后**已清空** `ignoreErrors: []`，保留文件维持双 baseline 结构）/ `phpstan-tests-baseline.neon`（测试存量，**301 条目 / 311 条** = 273 missingType + 38 有意语义条目）。两份都**只减不增**；**不为让工具通过而改业务代码** |

> **⚠ `level` 与 baseline 必须同源**：baseline 是用哪个 level 生成的，`parameters.level` 就得是哪个值。
> 二者不一致时，PHPStan 会对每条不再命中的条目报 `ignore.unmatched (non-ignorable)` ——
> 一次就能把门禁刷成红色（曾发生：baseline 以 level 6 生成 362 条，而配置仍为 level 5，
> 结果 `composer analyse` 直接报 351 errors）。
>
> **本项目已于 2026-09-22 由 level 5 升至 level 6。** 提级前的量化：level 6 全量报
> **538 errors / 66 文件，其中 100% 是 `missingType.*`**（`iterableValue` 330 / `return` 167 /
> `parameter` 34 / `property` 7），**零语义告警** —— 即 level 6 的增量只是「数组元素类型
> 标注完整性」，不新增逻辑类检查，风险为零、收益是文档性的。
>
> 处置方式按目录分层：
> - **生产侧（`src` / `client/src` / `start.php`）265 条已全部补齐 phpdoc 标注** → 0 errors；
>   2026-09-23 语义清洗又修掉剩余 8 条历史告警 → **生产 baseline 已清空**（`ignoreErrors: []`，
>   保留文件以维持双 baseline 结构）；
> - **测试侧（`tests` / `client/tests`）273 条 missingType + 38 条有意语义条目（共 301 条目 / 311 条）
>   冻结进 `phpstan-tests-baseline.neon`** ——
>   测试替身补 `: void` 之类收益低且有 TypeError 风险，沿用「测试噪音单独一份」的既有设计。
>
> 补标注纪律：**生产代码可补原生类型**（`: void` / 明确标量 / 数组形参，接口与实现须同步；
> 2026-09-23 已落地属性与方法原生类型）；**测试侧仍只加 phpdoc、不加原生返回类型**
>（冻结进 baseline，见上）。phpdoc 以 `array<string, mixed>` 为主，精确 `array{...}` shape
> 会解锁键存在性检查、可能引出新告警，留作独立后续任务。

> **`ignore.unmatched` 是修复的免费验证器**：baseline 中不再匹配任何实际错误的 `ignore`  
> 条目会触发 `ignore.unmatched (non-ignorable)` 报错。因此「删掉 baseline 条目 → 分析干净通过」  
> 等价于「修复生效且未引入新错误」。

### 13.3 跨版本验证

```bash
# 用不同 PHP 版本各跑一轮（最低 8.2；8.1 及以下无法运行，原因见第 4 节）
/usr/local/php82/bin/php vendor/bin/phpunit
/usr/local/php83/bin/php vendor/bin/phpstan analyse --memory-limit=512M
/usr/local/php85/bin/php tests/e2e_check.php e2e-ver-85
```

### 13.4 端到端自检（e2e）

```bash
php tests/e2e_check.php <uid> [device_id] [timeout]
```

**前置条件**：Redis 可用；已启动 `register` / `gateway` / `udp` / `business`  
（HTTP 用例 H / P 还需 `api`；P 还需 `business`，未启动时对应用例标记 SKIP / 失败）。

**用例清单**（退出码 `0` = 全部通过，`1` = 存在失败）：

| 编号 | 用例             | 覆盖内容                                                                                                                       |
| -- | -------------- | -------------------------------------------------------------------------------------------------------------------------- |
| A  | WebSocket 正常链路 | 连接 → `auth` 鉴权 → `ack` → `ping` → `pong`                                                                                   |
| B  | WebSocket 越权拦截 | 未鉴权直接发业务指令 → `4003` 并断开                                                                                                    |
| C  | UDP 正常链路       | 合法签名报文 → 收到 ack 回执                                                                                                         |
| D  | UDP 签名拦截       | 篡改签名 → `4001`                                                                                                              |
| E  | 定向推送（在线）       | `uid` 目标 → 在线连接收到 `push` 报文                                                                                                |
| F  | 离线缓存与重连补投      | 离线时入队 → 上线后自动补投                                                                                                            |
| G  | 推送幂等           | 同一 `msg_id` 重复提交 → 仅投递一次                                                                                                   |
| H  | HTTP 接口        | 健康探测 / 验签通过 / 验签拒绝                                                                                                         |
| I  | UDP 定向推送       | 业务进程 → `queue:udp:out` → 网关 `sendto`                                                                                       |
| J  | 指令路由表          | `data.action`（`echo` / `session`）分发与 `4006` / `4007` 分支                                                                    |
| K  | UDP 离线补投       | UDP 会话重建时经出站队列补投（`offline=1`）                                                                                              |
| L  | 报文级限流          | 单连接连发超量报文 → 部分放行、部分 `4008`                                                                                                 |
| M  | 业务动作契约         | 参数白名单 / `4006` 未知动作 / `4007` 参数错误                                                                                          |
| N  | UDP 通道业务动作     | `echo` 经出站队列回执；`report` 按声明静默不回执                                                                                           |
| O  | 订阅与广播闭环        | `subscribe` → `enqueueTopic` → `push` → `unsubscribe`                                                                      |
| P  | HTTP 动作调用      | `POST /action` → `queue:action:in` → BusinessWorker → `action:result:{id}` → 响应；含未开放通道 `4006`、参数错误 `200/4007`、补查 `404` 等分支 |

> 用例 H 与 P 为**同步阻塞**执行（在事件循环启动前完成），不参与超时保护；  
> 其余用例运行在 workerman 事件循环内。P 的客户端超时取 20s，因为 `POST /action`  
> 最长会同步等待 `API_ACTION_WAIT_MS`（6s），超时必须留出余量。

**两条使用铁律**：

| 铁律                                            | 原因                                                        |
| --------------------------------------------- | --------------------------------------------------------- |
| **每轮必须换全新 uid**（如 `e2e-uid-0001` / `0002` 递增） | UDP 无断连事件，会话只靠心跳超时回收；上一轮遗留会话会被判定为在线，导致用例 K（离线补投）**确定性失败** |
| **必须独占运行**                                    | 与浏览器压测/截图等并行会引入干扰，产生假失败                                   |

**一条既有缺陷（非本次引入，仍未修）**：

| 缺陷          | 现象                       | 根因      | 规避      |
| ----------- | ------------------------ | ------- | ------- |
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
# OK (519 tests, 1465 assertions)
```

**只测「纯函数 / 零 IO」组件**：

| 覆盖对象             | 内容                                            |
| ---------------- | --------------------------------------------- |
| `ParamValidator` | 类型转换、范围、枚举、白名单丢弃语义                            |
| `Message`        | `canonicalize` / `sign` / `encode` / `decode` |
| `Env`            | 多级加载优先级、类型化读取、空值语义                            |
| `RateLimiter`    | 仅 L1 内存桶                                      |
| `ActionRunner`   | 声明层（装载 / 归一化 / 声明查询 / HTTP 白名单 / 前缀表通道判定）     |
| `ActionReply`    | `clientId` ↔ `request_id` 双向转换、键空间校验、TTL 下界保护 |
| `ActionContext`  | 回执抑制语义                                        |
| `RedisKeys`      | 键名金标（拦截误改）、前缀↔完整键分隔符约定、队列键唯一性、动态后缀编码方式        |
| `RoleCatalog`    | `roles` 命令契约（角色顺序 / `enabled` / `env` 字段）、真实环境变量覆盖语义、开关名与 `config` 双向一致、启动脚本的过滤点 |
| `Monitor`        | `staleFields()` 残留判定（边界保活 / 全局字段与脏字段越界保护 / 四字段同删）、字段前缀金标、面板 JS 前缀一致性、清理调用未被摘除 |
| `Router`         | action / command 注册与查询（空键拒绝、重注册覆盖、数字串键保留、未知键 null） |
| `Auth`           | `verifyLocal` Token 契约（非 string / 空串 / 结构畸形 / 非法签名 / claims 恢复） |
| `Push`           | `init`/getter 幂等、目标与通道判定、key / nonce 校验、入队参数归一 |

> **未覆盖**：`ActionRunner::run()`、`RateLimiter::acquire()`、`Monitor::purgeExitedProcesses()`、
> `Session::bind` / `SessionManager` Redis 路径、`Push` 实际入队 ——
> 它们依赖 workerman 生命周期与异步回调，mock 成本过高（静态类 + 回调），由 e2e 覆盖。
> **coverage 摘要**：本机无 xdebug / pcov，无法生成 phpunit 覆盖率报告；上述清单即
> 「哪些纯函数已测 / 哪些 Redis IO 归 e2e」的结构化替代。

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

### 13.7 CI（GitHub Actions）

配置文件：**`.github/workflows/ci.yml`**（唯一入口）。触发：向 `main` / `master` 推送、面向这两个分支的 PR，以及手动 `workflow_dispatch`。

**设计原则：CI 不发明第二套口径。** workflow 里所有门禁都直接调 `composer.json` 的 script
（`analyse` / `test` / `lint` / `lint:self` / `cs:check` / `test:frontend` / `test:docs` /
`test:client-e2e` / `demo:http`），
**不在 YAML 里重写** `php vendor/bin/phpstan ...` —— 否则出现「本地绿、CI 红」时，
无法区分是环境差异还是命令差异。

| 作业 | PHP | 外部依赖 | 内容 |
| --- | --- | --- | --- |
| `static` | 8.2（单版本） | 无 | `composer validate --strict` → `analyse` → `lint` → `lint:self` → `cs:check` → `test:frontend` → `test:docs` |
| `test` | **8.2 ~ 8.5 矩阵** | 无 | `composer test` |
| `e2e` | 8.2 | **Redis 7 service + 全部 6 个角色** | `tests/e2e_check.php`（用例 A~P）→ `test:client-e2e` → `api_sign_check.js` → `demo:http` |

按**外部依赖**而非耗时划分：`static` 固定单版本是因为静态工具的输出与运行它们的 PHP 版本无关；
`test` **不需要 Redis**（`phpunit.xml` 只覆盖「纯函数 / 无 IO」组件），所以能在矩阵里裸跑；
`e2e` 需要真实端口与 Redis，单列并挂 service container。

- **8.5 为「实验性」**（`continue-on-error`）：phpunit 锁 9.6 且 `phpunit.xml` 开了
  `failOnWarning` / `failOnRisky`，8.5 上的新弃用告警会直接判失败。跑绿后再把
  `experimental` 改 `false`。
- **`e2e` 只 `needs: static`**：矩阵里 8.5 带 `continue-on-error`，而 `needs` 看的是**作业状态**，
  带上 `test` 会让 `e2e` 在 8.5 变红时被静默 skip。
- **`e2e` 启动用 `bin/start.sh start`**（守护化 + preflight + pid 轮询），
  **不要用 `bin/dev/boot_all.sh`**（开发期编排，末尾 `wait` 常驻，会占住步骤直至 timeout）；
  启动后另补一次端口级就绪确认（UDP 8283 无 TCP 监听，需查 `ss -lun`）。
- **`e2e` 传入 run 级唯一 uid**（`e2e-{run_id}-{run_attempt}`）：UDP 无断连事件，
  会话不随客户端退出失效，复用同一 uid 会污染离线补投用例（K）。

> ⚠ **CI 上的 `cs:check` 比本地可信**：CI 检出是 LF（git 索引侧本就是 LF），
> 该步骤**恒应为 0 文件**，一红就是真的排版违规。
> 而本机因 `core.autocrlf=true` 工作区是 CRLF，同一条命令会混入行尾噪声 ——
> 本地结果需先统一行尾再解读，详见 `docs/代码质量工具链说明.md` §8.7 / §8.8。

> ℹ `e2e` 作业依赖 Linux 守护化启动与 Redis service，本机（Windows）无法复现，
> 是**首次运行时最可能需要微调**的部分；`static` / `test` 的命令已逐条本机复跑验证。

---

## 14. 运维手册

### 14.1 启动顺序与依赖

```
register → gateway → udp → business → api → dashboard
```

- 顺序即依赖顺序：所有角色都要向 `register` 注册。
- `bin/start.sh start` / `bin/start.bat start` 已按此顺序编排。

### 14.2 重启策略

| 场景                                      | 操作                            |
| --------------------------------------- | ----------------------------- |
| 仅改动业务代码                                 | `reload`（Linux 真正平滑，网关长连接不中断） |
| 改动 `config/*.php` 结构性配置                 | `restart`                     |
| 改动网关/协议代码                               | `restart`（长连接会断开，客户端需重连）      |
| 改动面板页面 `resources/dashboard/index.html` | **无需重启** —— 模板按 mtime 自动失效    |

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

#### 面板显示「指标采集已关闭」

`MONITOR_ENABLE` 在 `.env` 里已是 `true`，但行为没变 —— 常驻进程**只在启动时加载配置一次**，无热重载。  
面板徽章读的是 **dashboard 进程的启动快照**，且 `Monitor::incr()` / `report()` 首行即短路，  
所以此时采集是**真的完全停摆**，不只是文案问题（直接症状：`report_at` 长时间不更新）。

```bash
bin\start.bat restart dashboard               # 或重启全部角色
curl -s http://127.0.0.1:8291/metrics.json    # 看 data.meta.enable 与 gauge.report_at
```

#### 面板出现多余的「已退出」进程

进程退出后其 4 个字段会保留一段宽限期（默认 600s，即 `MONITOR_TTL`），好让你看到「刚刚退出了谁」；  
超过宽限期未上报的字段会在下一次 `report()` 时被 `HDEL` 清掉（判定见 9.10）。  
若重启后旧进程**长期**堆积不消失，查采集进程有没有清理记录：

```bash
grep "已清理已退出进程" runtime/logs/*.log
```

#### 消息发了没反应

按顺序排查：

| 检查项     | 命令 / 位置                                           |
| ------- | ------------------------------------------------- |
| 报文能否解析  | 搜索日志关键词「报文解析失败」→ 回 `4000`                         |
| 是否被限流   | 搜索「报文超限已拒绝」（按维度每秒采样）→ 回 `4008` 或静默                |
| 是否未鉴权   | 搜索「未鉴权连接尝试业务指令，已拒绝」→ 回 `4003`                     |
| 动作是否注册  | `php start.php check` 看业务动作清单；或搜索「未知业务动作」→ `4006` |
| 参数是否合规  | 搜索「业务动作执行前失败」→ `4007`                             |
| 是否超时未回执 | 搜索「业务动作超时未回执」→ `5000` 并记 `action_timeout`         |
| 服务端是否收到 | 看 `msg_in` 指标是否增长                                 |

> **UDP 侧「错误静默」+「身份缺失拒绝」叠加会制造无日志故障**：UDP 错误一律静默是刻意设计  
> （避免反射放大），但一旦叠加身份拒绝，客户端只见超时、服务端也无异常日志，极易误判为链路故障。  
> **排查 UDP 动作问题必须对照运行日志（`runtime/logs/`），不能依赖客户端超时。**

#### HTTP 提示「缺少 X-Timestamp 或 X-Sign 请求头」

| 想做的事                     | 判断依据                                                                                                                                          |
| ------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------- |
| 是不是「关了 `AUTH_*` 却没生效」     | **两者与 HTTP 验签无关**（属 WS / UDP 报文层）。`authenticate()` 只读 `api` 段，不读 auth 段的任何开关。详见 9.9「本地调试免签」                                                          |
| 本地想免签                    | `API_SIGN_ENABLE=false`，**且** `API_LISTEN` 绑回环地址；改完需重启 api 角色。启动横幅的「接口验签」行显示实际生效状态                                                                   |
| 设了 `false` 却仍要签名         | 看 api 启动日志有无「`API_SIGN_ENABLE=false` 未生效：监听地址非回环」—— 那是护栏拦下，**属预期行为而非故障**                                                                       |
| 密钥到底用的哪个                 | `API_SECRET` 为空时回退 `AUTH_SECRET`（本机即此状态）。验签失败搜日志「接口验签失败」                                                                                       |
| 签名怎么算                    | `X-Sign = hex(hmac_sha256("{X-Timestamp}\|{原始请求体}", secret))`；`GET` 无 body 时对**空串**签名；时间戳窗口 `API_SIGN_TTL`（默认 300s）                      |

> 直接跑 `php tests/Api/http_demo.php`（自动探测模式、打印签名构造全过程）或
> `node tests/Api/api_sign_check.js`，比手工排查快。它们与 `ClientE2E.php`、
> `tests/e2e_check.php` 在免签模式下都会把「错误签名必须被拒」标记为 SKIP，不会误报失败。

#### HTTP 动作调用不成功（POST /action）

先分清失败发生在**入队前**还是**执行后** —— 前者给 HTTP 4xx/5xx，后者给 HTTP `200` + 业务码：

| 检查项         | 命令 / 位置                                                               |
| ----------- | --------------------------------------------------------------------- |
| 动作是否开放 HTTP | 回 `400` / `4006`，msg 里会列出当前可用动作；或 `php start.php check` 看 `http` 声明   |
| 是否已提供 uid   | 白名单内动作 `auth` 均为 true，缺 uid 回 `401` / `4003`                          |
| 队列是否积压      | 回 `503` / `5030`；`LLEN queue:action:in` 对照 `ACTION_QUEUE_MAX_LEN`     |
| 参数是否合规      | HTTP `200` + `code=4007`，`data.status=failed`；搜索「业务动作执行前失败」           |
| 动作是否超时      | HTTP `202` + `status=pending`；搜索「HTTP 动作未在等待窗内完成」或「业务动作超时未回执」         |
| 结果是否已过期     | `GET /action/{id}` 回 `404` / `4004`；对照 `ACTION_RESULT_TTL`（默认 60s）    |
| 消费任务是否在跑    | 看面板定时任务表的 `action-queue-consume` 健康度；`LLEN queue:action:in` 持续不降即是它没跑 |

> **等待窗必须大于动作超时**：`API_ACTION_WAIT_MS`(6000) 应大于白名单动作中最长的  
> `ACTION_TIMEOUT`(5s)，否则 Api 会先超窗回落 `202`，动作的同步回执永远不会被取到。  
> 启动日志会直接告警这一配置错误（「等待窗小于动作超时」）。

#### 推送不达

| 检查项                   | 说明                                                                                              |
| --------------------- | ----------------------------------------------------------------------------------------------- |
| `queue:push:out` 是否积压 | `LLEN gwpush:queue:push:out` —— 持续增长说明业务进程未消费或消费慢                                               |
| 目标是否在线                | `HGETALL gwpush:session:{clientId}` 看 `offline_at`；`SISMEMBER gwpush:online:clients {clientId}` |
| 设备映射是否指向活连接           | `GET gwpush:device:client:{device_id}`                                                          |
| 是否被幂等丢弃               | 搜「推送任务重复，已跳过」+ 看 `push_dedup` 指标                                                                |
| 是否被数据体限额拦下            | 搜「推送数据体超限，已拒绝」+ 看 `push_fail`                                                                   |
| UDP 目标                | `LLEN gwpush:queue:udp:out` 与 `udp_out` / `udp_out_fail` 指标                                     |
| 离线策略                  | `push_offline` 指标 + `LLEN gwpush:push:offline:{uid}`                                            |

### 14.4 生产环境必做

| 项             | 说明                                                                                   |
| ------------- | ------------------------------------------------------------------------------------ |
| 修改密钥          | `AUTH_SECRET` 与 `INTERNAL_SECRET` 必须替换为高强度随机值（`env:init` 会生成）                        |
| 调整文件句柄上限      | 支撑上万长连接必须 `ulimit -n` >= 65535（临时 `ulimit -n 65535`，永久写 `/etc/security/limits.conf`） |
| 关闭调试          | `APP_DEBUG=false`、`LOG_STDOUT=false`、`LOG_LEVEL=info` 或 `warn`                       |
| 启用 WSS        | `SSL_ENABLE=true` + `SSL_CERT` / `SSL_PK`                                            |
| 收紧监听地址        | `API_LISTEN` / `DASHBOARD_LISTEN` 保持 `127.0.0.1`，通过反向代理暴露                            |
| 设置 Redis 密码   | `REDIS_PASSWORD`，并考虑 `bind` 限制来源                                                     |
| 更换 `REDIS_DB` | 多套环境共用同一 Redis 实例时必须区分                                                               |

### 14.5 Windows 特别说明

| 事实                  | 影响                                                                              |
| ------------------- | ------------------------------------------------------------------------------- |
| workerman 不解析命令     | `stop` / `restart` / `reload` / `status` 全部失效且会反向启动实例 → **一律走 `bin\start.bat`** |
| 不写 pid 文件           | `runtime/pid/` 只有管理脚本自己写的 `win_{角色}.pid`（存承载窗口的 cmd.exe PID，仅用于停止时连带关窗）         |
| 单进程模型               | 所有 `*_COUNT` 配置被强制降级为 1（`resolveCount()`），无法测试多进程并发行为                           |
| 不支持 `--role=all`    | `start.php` 会显式拒绝并提示按角色启动                                                       |
| 无 `pcntl` / `posix` | `check` 会输出 `[SKIP] Windows 环境跳过 pcntl / posix 检查`                              |

**终端中文编码的三处联动（缺一处就乱码）**：

| 文件      | 处理                                                                   |
| ------- | -------------------------------------------------------------------- |
| `.bat`  | `chcp 65001` 切换当前控制台代码页（退出时还原原 CP）                                   |
| `.ps1`  | 设置 `[Console]::OutputEncoding` / `InputEncoding` / `$OutputEncoding` |
| 新开的角色窗口 | **会退回系统默认代码页**，故启动命令统一包成 `cmd /k "chcp 65001>nul && ..."`            |

**文件编码是硬约束**（改脚本后必须复核）：

| 文件              | 换行   | BOM           | 原因                                                                          |
| --------------- | ---- | ------------- | --------------------------------------------------------------------------- |
| `bin/start.sh`  | LF   | 无             | CRLF 会让 shebang 变成 `bash\r`，且 `bash -n` 查不出来                                |
| `bin/start.ps1` | CRLF | **UTF-8 BOM** | PowerShell 5.1 缺 BOM 会按 GBK 解析脚本，中文先烂                                       |
| `bin/start.bat` | CRLF | 无             | **必须纯 ASCII** —— CMD 按当前代码页解码、却按**字节**推进文件指针，多字节字符会让两者错位、从字符中间恢复读取并把残片当命令执行 |

> **以上行尾由根目录 `.gitattributes` 强制**（2026-09-23 落地），不再依赖 `core.autocrlf`：
> `* text=auto` 为默认，`*.sh` / `*.php` 显式 `eol=lf`，`*.bat` / `*.cmd` / `*.ps1` 显式
> `eol=crlf`。此前索引里 `bin/start.bat` 是 LF（`git ls-files --eol` 显示 `i/lf w/crlf`），
> CRLF 只靠本机 `autocrlf=true` 凑出来 —— **Linux 检出或 `autocrlf=input` 的克隆会拿到 LF 脚本**，
> 硬约束跨机器并不成立。注意 `eol` 只管 checkout，**索引恒为 LF**（`text` 属性下 git 从不把
> CRLF 存进索引），故不存在「需要把索引改成 CRLF」这回事。

---

## 15. 集群就绪度

**结论：无状态基础成立，但集群并非零改造。** 落地前需完成 4 项改造 + 1 项启动校验。

| 项 | 缺口                                                                                     | 改造方向                                        |
| - | -------------------------------------------------------------------------------------- | ------------------------------------------- |
| A | runtime 三路径硬编码 `$basePath/runtime`（`config/app.php:49-51`），pid 仅按角色命名（`start.php:182`） | `NODE_ID` 化 runtime 与 pid 路径，加 `--node=` 参数 |
| B | `lan_ip` + `start_port` 是 clientId 唯一性的前提，但**无校验**（`config/gateway.php:39`）            | `check` 增加集群内唯一性校验，冲突时拒绝启动                  |
| C | UDP 出站队列是单条共享 List，任意节点可消费并 sendto → 源 IP 与入口节点不一致                                     | 出站队列节点亲和：`queue:udp:out:{NODE_ID}`          |
| D | 定时任务 `scope=first` 语义是「每机 worker 0」，集群下会重复执行                                           | 全局唯一任务加分布式锁（可复用 `setNxEx`）                  |

**已就绪的部分**：

| 能力      | 说明                                                                                              |
| ------- | ----------------------------------------------------------------------------------------------- |
| 精确寻址    | clientId 内嵌节点地址（`Context.php:116`）                                                              |
| 无本地状态   | 会话 / 映射 / 队列 / 指标全在 Redis                                                                       |
| 原子取批    | `popBatch` 用 Lua 保证多进程/多节点并发安全                                                                  |
| 累加安全    | 指标用 `HINCRBY`，跨节点累加不丢                                                                           |
| 在线数天然全局 | `countOnline()` 走 `SCARD online:clients`，而该集合是**集群共享集合**，各节点 `sAdd` 自己的连接，任何节点读到的都是全局值（集群下无需聚合） |

> **已排除的疑点**：曾误判「`conn_total` 跨机互相覆盖」—— 实为 `SCARD` 共享集合，各节点值相同，无偏差。

> **动作回程在集群下天然可用**：`queue:action:in`（任意节点消费）与 `action:result:{request_id}`  
> 都是全局键，不依赖节点亲和 —— 与 UDP 出站队列（上表 C 项，必须做节点亲和）形成对照。  
> 原因是 HTTP 通道没有「源 IP」这一约束：Api 进程只从 Redis 取结果，不参与任何网络投递。

---

## 附：相关文档

| 文档 | 面向 | 内容 |
| --- | --- | --- |
| **`docs/GatewayPush 对外接口文档.md`** | **调用方 / 接入方** | **字段级接口契约**：接入点、三套凭证、逐接口请求/响应字段、业务动作参数 schema、错误码、限额汇总。接入方只需读这一份 |
| `docs/Workerman V2 GatewayPush 实时数据推送服务技术方案文档.md` | 设计者 | 设计决策、取舍理由与演进路线 |
| `docs/GatewayPush 客户端SDK与调试器设计方案.md` | 客户端开发者 | 五层架构与 P0~P6 里程碑 |
| `docs/Workerman 框架 AI 编码规范.md` | 开发者 / AI 协作 | Workerman 底层约束 + PSR-12 + 常驻内存避坑，可作 AI System Prompt |
| `client/README.md` | PHP 客户端使用者 | SDK 用法与状态 |

> 本文件（README）是**使用者手册**：部署、配置、原理、运维。
> 接口字段的权威定义在代码；字段级契约的集中视图在 `docs/GatewayPush 对外接口文档.md`。
> 三者不一致时以代码为准，并须同时修正另外两处。

## License

MIT
