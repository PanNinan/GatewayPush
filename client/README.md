# GatewayWorker 客户端 SDK 与调试器

> 设计方案见仓库根目录 `GatewayWorker 客户端SDK与调试器设计方案.md`。
> 当前进度：**P0（协议层）、P1（WS 传输 + 会话层）已完成**，P2~P6 待实现。

## 为什么放在同一仓库

客户端复用服务端的两个**纯计算、无 IO** 的类，实现「协议零漂移」：

| 服务端类 | 客户端用途 |
|---|---|
| `GatewayPush\Business\Message` | 报文编解码、签名、`canonicalize`、报文构造 |
| `GatewayPush\Business\Auth` | Token 签发与本地校验 |

`canonicalize`（递归键名升序 + 紧凑 JSON）是最容易在跨实现时跑偏的地方，
直接调用同一份实现即天然一致。将来若要抽成独立 composer 包，
只需替换 `Protocol/` 适配层的实现，上层无需改动。

## 目录结构（P1 现状）

```
client/
├── src/
│   ├── Error/
│   │   ├── ErrorCode.php          错误码常量（报文码 / HTTP 码 / 客户端本地码）
│   │   └── ClientException.php    统一异常
│   ├── Protocol/
│   │   ├── Codec.php              编解码适配 + data 业务信封
│   │   ├── Signer.php             签名 / canonicalize / 本地验签
│   │   └── TokenIssuer.php        Token 签发 / 解析 / 校验
│   ├── Transport/
│   │   ├── TransportInterface.php 统一传输接口（含 onOpen，见设计稿 §12 偏离说明）
│   │   └── WsTransport.php        ws://（AsyncTcpConnection 薄封装）
│   └── Session/
│       ├── SessionManager.php     鉴权状态机 / seq / pending / 心跳 / 重连 / 下行分发
│       └── PendingRequest.php     单次请求上下文
└── tests/Unit/
    ├── CodecTest.php
    ├── SignerTest.php
    ├── TokenIssuerTest.php
    ├── ErrorCodeTest.php
    └── SessionManagerTest.php     假传输层 + 假计时器，21 个用例
```

后续阶段将补齐 `UdpTransport`/`HttpTransport`、`Service/`、`Event/`、`bin/gwclient.php`。

## 快速开始

```bash
composer dump-autoload     # 新增了 PSR-4 前缀 GatewayPush\Client\，首次必需
composer test              # 与服务端单测同一套命令
composer analyse           # PHPStan 同时覆盖 src 与 client/src
```

```php
use GatewayPush\Client\Error\ClientException;
use GatewayPush\Client\Protocol\Codec;
use GatewayPush\Client\Protocol\Signer;
use GatewayPush\Client\Protocol\TokenIssuer;

// 1. 签发 Token（联调用；生产环境 Token 通常由业务系统签发）
$issuer = new TokenIssuer('AUTH_SECRET', 7200);
$token  = $issuer->issue(array('uid' => 'alice', 'device_id' => 'dev1'), 600);

// 2. 连接前先本地预校验，避免「连上去才发现 Token 已过期」
$result = $issuer->inspect($token);         // ['ok','code','msg','claims']
if (!$result['ok']) {
    echo $result['msg'];                    // 过期 / 篡改 / 密钥不符
}

// 3. 构造业务指令报文
$packet = Codec::dataPacket('echo', array('hello' => 'world'));
$packet['uid']       = 'alice';
$packet['device_id'] = 'dev1';
$packet['token']     = $token;
$packet['seq']       = '1';
$packet['ts']        = time();
$packet['sign']      = Signer::sign($packet, 'AUTH_SECRET');   // WS 可省，UDP 必需

$frame = Codec::encode($packet);
```

> `new TokenIssuer($secret, $ttl, $clockSkew)` —— 三个参数依次对应服务端
> `app.auth.secret` / `token_ttl` / `clock_skew`。

## 会话层用法（P1）

`SessionManager` 封装了「建连 → 鉴权 → 心跳 → 业务请求 → 断线重连」的完整会话生命周期。
须运行在 workerman 事件环境中（如 `Worker::runAll()` 之后的回调里）：

```php
use GatewayPush\Client\Session\SessionManager;

$session = new SessionManager(array(
    'ws_url'    => 'ws://127.0.0.1:8282',
    'uid'       => 'alice',
    'device_id' => 'dev1',
    'secret'    => 'AUTH_SECRET',   // 与服务端 app.auth.secret 一致
    'heartbeat' => 20,              // 主动 ping 间隔（秒），0 = 关闭
    'timeout'   => 5.0,             // 单次请求超时（秒）
    'reconnect' => true,            // 断线自动重连（指数退避），重连后自动重鉴权
));

$session->onStateChange(function ($new, $old) { /* disconnected→connecting→connected→authenticating→ready */ });
$session->onError(function (ClientException $e) { /* 服务端 error 报文 / 超时 / 传输错误 */ });
$session->onPush(function (array $packet) { /* cmd=push 下行（P2 将由 PushReceiver 承接） */ });

$session->connect();   // auto_auth=true（默认）：握手完成立即抢发 auth（15s 窗口内）

$session->ping(function ($ok, $packet) use ($session) {
    echo 'RTT=' . $session->lastRtt();
});

$session->request('echo', array('hello' => 'world'), function ($ok, $packet) {
    if ($ok) {
        print_r($packet['data']);          // 完整回执报文，业务载荷在 data
    }
});
```

行为要点：

| 项 | 说明 |
|---|---|
| 鉴权时机 | 默认 `auto_auth=true`，握手完成的瞬间自动发 `auth`；手动模式置 false 后自行调 `auth()` |
| 服务端反向心跳 | 收到 `{"cmd":"ping","ts":0}` 自动回 pong（实测 40s 不被网关断开） |
| 请求-响应关联 | 内部按 seq 维护 pending 表，超时（默认 5s）以 `ok=false` 结算并触发 onError |
| 断线重连 | 指数退避（1s 起步、封顶 15s），重连成功重鉴权后计数清零；用户 `close()` 不触发 |
| 重连等待期关闭 | `close()` 会取消挂起的重连定时器并直接落 disconnected |
| 回调签名 | 统一 `function (bool $ok, array $packet): void`（完整报文，载荷在 `$packet['data']`） |

## 协议要点速查

| 项 | 事实 |
|---|---|
| 报文八字段 | `cmd / seq / ts / uid / device_id / token / sign / data` |
| 签名算法 | `hmac_sha256("cmd|seq|ts|device_id|token|canonicalize(data)", secret)` |
| `uid` 参与签名？ | **不参与**。身份只能取自 `token` 载荷（服务端密钥 HMAC 保护） |
| WS 需要签名？ | 不需要（仅 UDP 网关校验）。客户端做法是一律算、一律带上 |
| 业务指令信封 | `{"cmd":"data","data":{"action":"<名>","params":{...}}}`，缺 action → 4007，未注册 → 4006 |
| UDP 回执分层 | 传输层 ack：`data` 为空且**无** `action`；业务层回执：带 `data.action` |
| 鉴权窗口 | 建连后 **15 秒**内必须发 `auth` |
| 服务端反向心跳 | 网关会下发 `{"cmd":"ping","ts":0}`，**必须应答**（25s/次，漏 2 次断开） |
| 设备绑定 | `uid` 首次鉴权绑定 `device_id`，换设备报「设备不匹配」 |
| 限流 | 连接 20/s（突发 40）、用户 50/s、心跳 5/s；超限回 `4008`，默认不断连 |
| 推送幂等 | 相同 `msg_id` 600s 内去重（多目标须派生独立 `msg_id`） |
| 错误码 | 报文码与 HTTP 业务码**数字重叠但语义不同**（如 `4004`），见 `ErrorCode` |

## 关于复用服务端 `Auth` 的静态状态

`Auth` 是静态类，密钥保存在**进程级静态属性**中。`TokenIssuer` 在每次调用前都会
重新写回自己的配置，因此同一进程内构造多个不同密钥的实例也不会互相串味
（`TokenIssuerTest::testMultipleIssuersDoNotLeakSecret` 固定了该行为）。

**但请勿在业务代码里直接调用 `Auth::init()`**，否则会覆盖客户端实例的配置。

## 调试器（规划中）

`client/bin/gwclient.php` 将提供两种模式，均已确认实现：

- **一次性命令**：`php client/bin/gwclient.php <cmd> [opts]` —— 建连 → 鉴权 → 执行 → 退出
- **REPL 交互**：`php client/bin/gwclient.php shell` —— 保持连接，逐条输入命令，
  适合多步联调（无需每次重连、无需反复签发 Token）

命令清单：`token` / `connect` / `echo` / `session` / `report` / `subscribe` / `unsubscribe` /
`topics` / `notify` / `listen` / `push` / `stats` / `health` / `e2e`。

## 里程碑

| 阶段 | 内容 | 状态 |
|---|---|---|
| P0 | `Protocol/` 三件套 + 单测 | ✅ 已完成 |
| P1 | `WsTransport` + `SessionManager` | ✅ 已完成（单测 21 例 + 实测：auth/ping-pong/echo/40s 反向心跳存活全通过） |
| P2 | 7 个动作 API + `PushReceiver` + 自动 ack | 待办 |
| P3 | `UdpTransport` | 待办 |
| P4 | `AdminApi`（`/push` `/stats` `/health`） | 待办 |
| P5 | 重连 + 会话恢复 + 离线补投（重连/补投骨架已在 P1 就位，待补验收） | 待办 |
| P6 | CLI 调试器（含 REPL）+ 客户端侧 e2e | 待办 |
