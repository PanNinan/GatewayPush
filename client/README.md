# GatewayWorker 客户端 SDK 与调试器

> 设计方案见仓库根目录 `GatewayWorker 客户端SDK与调试器设计方案.md`。
> 当前进度：**P0 ~ P6 全部完成**（2026-09-21）。P3 UDP 传输、P4 HTTP 管理端、P5 重连与离线补投验收、
> P6 CLI 调试器与客户端侧 e2e 均已交付，门禁 `composer test` + `composer analyse` 全绿。

## 为什么放在同一仓库

客户端复用服务端的两个**纯计算、无 IO** 的类，实现「协议零漂移」：

| 服务端类 | 客户端用途 |
|---|---|
| `GatewayPush\Business\Message` | 报文编解码、签名、`canonicalize`、报文构造 |
| `GatewayPush\Business\Auth` | Token 签发与本地校验 |

`canonicalize`（递归键名升序 + 紧凑 JSON）是最容易在跨实现时跑偏的地方，
直接调用同一份实现即天然一致。将来若要抽成独立 composer 包，
只需替换 `Protocol/` 适配层的实现，上层无需改动。

## 目录结构（P6 现状）

```
client/
├── src/
│   ├── Error/
│   │   ├── ErrorCode.php          错误码常量（报文码 / HTTP 码 / 客户端本地码）
│   │   └── ClientException.php    统一异常
│   ├── Protocol/
│   │   ├── Codec.php              编解码适配 + data 业务信封 + UDP 双层 ack 判别
│   │   ├── Signer.php             签名 / canonicalize / 本地验签
│   │   └── TokenIssuer.php        Token 签发 / 解析 / 校验
│   ├── Transport/
│   │   ├── TransportInterface.php 统一传输接口（含 onOpen，见设计稿 §12 偏离说明）
│   │   ├── WsTransport.php        ws://（AsyncTcpConnection 薄封装）
│   │   ├── UdpTransport.php       udp://（延迟首包 + 应用层重传，P3）
│   │   └── HttpTransport.php      一次性 tcp 请求（供 AdminApi，P4）
│   ├── Session/
│   │   ├── SessionManager.php     鉴权状态机 / seq / pending / 心跳 / 重连 / 下行分发 / sendAck
│   │   └── PendingRequest.php     单次请求上下文
│   ├── Service/
│   │   ├── AbstractApi.php        回调包装基类（ok, data, error 三元组）
│   │   ├── EchoApi.php            echo（params=* 透传）
│   │   ├── SessionApi.php         session（会话摘要）
│   │   ├── ReportApi.php          report（UDP 侧静默不回执）
│   │   ├── SubscribeApi.php       subscribe / unsubscribe / topics
│   │   ├── NotifyApi.php          notify（触发对自身推送）
│   │   └── AdminApi.php           HTTP 管理端 /push /stats /health（P4）
│   ├── Cli/
│   │   ├── CommandParser.php      参数解析（纯函数，可单测）
│   │   └── Debugger.php           调试器引擎：一次性命令 / REPL / listen（P6）
│   └── Event/
│       └── PushReceiver.php       push 下行解析 + 业务回调 + 自动回 ack
├── bin/gwclient.php               CLI 入口
└── tests/
    ├── Support/                   FakeTransport / FakeUdpConnection / FakeHttpConnection /
    │                              FakeTcpConnection / FakeTimers（测试共享基建）
    ├── Unit/                      11 个测试类
    └── E2E/ClientE2E.php          与服务端 e2e 同口径的 A~O 用例（P6）
```

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

## 业务动作与推送（P2）

`Service/` 把 7 个服务端动作封装为 API（均基于 `SessionManager::request()`），
回调统一三元组签名：`function (bool $ok, array $data, ?array $error): void`。

```php
use GatewayPush\Client\Event\PushReceiver;
use GatewayPush\Client\Service\EchoApi;
use GatewayPush\Client\Service\NotifyApi;
use GatewayPush\Client\Service\ReportApi;
use GatewayPush\Client\Service\SessionApi;
use GatewayPush\Client\Service\SubscribeApi;

$echo      = new EchoApi($session);
$session_  = new SessionApi($session);
$report    = new ReportApi($session);
$subscribe = new SubscribeApi($session);
$notify    = new NotifyApi($session);

$echo->send(array('hello' => 'world'), function ($ok, $data, $error) {
    if ($ok) { print_r($data['params']); }   // 回执业务载荷
    else     { echo $error['code'], $error['msg']; }
});

$report->report('metric.cpu', 5, array('avg' => 0.8));        // WS 回执；UDP 静默（cb 只会收到本地超时）
$subscribe->subscribe('topic.a');
$subscribe->topics();
$notify->notify(array('x' => 1), 'msg-001', 'queue');         // 目标恒为自身 uid
```

### 接收推送（自动回 ack）

```php
$receiver = new PushReceiver($session);   // 构造即接管 onPush 分发
$receiver->onPush(function (array $payload, array $meta) {
    // $payload = 推送载荷（push 报文 data）
    // $meta    = {msg_id, seq, source, offline, pushed_at, ts}
    if ($meta['offline'] === 1) {
        // offline=1 为重连补投，可与实时消息区分
    }
});
// 收到 push 后自动回 cmd=ack（data.msg_id 对齐），无需业务代码参与；
// $receiver->ackedCount() 可观察回执数量。
```

要点：

| 项 | 说明 |
|---|---|
| notify 闭环 | `notify()` 成功受理后，服务端向自身 uid 推送，`PushReceiver` 收到并自动回执 |
| msg_id 幂等 | 相同 `msg_id` 600s 内去重；重复触发请换 msg_id（留空由服务端生成） |
| report 通道差异 | WS 同步回执；UDP 静默不回执（`cb` 只会收到本地超时，属预期） |
| publish 不存在 | 服务端刻意不开放客户端广播，客户端亦不提供 |
| 错误语义 | `$error = ['code' => int, 'msg' => string]`；服务端错误为 4000~5000，本地超时为 10001 |

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

## UDP 通道（P3）

`UdpTransport` 对 `AsyncUdpConnection` 的封装，处理 UDP 的两个固有问题：

| 问题 | 处理 |
|---|---|
| 首包静默丢失（硬约束⑩） | 建连后延迟 `first_send_delay`（默认 0.2s）再发首包，期间上行入队 |
| 无传输层确认 | 应用层重传：`retransmit_interval`（默认 1.2s）× `max_attempts`（默认 4），收到业务回执即停 |
| 回执分层（硬约束⑳） | 传输层 ack（`data` 空且无 `action`）不结算业务 pending，继续等业务回执 |

```php
use GatewayPush\Client\Transport\UdpTransport;

$transport = new UdpTransport('udp://127.0.0.1:8283', array(
    'first_send_delay'     => 0.2,
    'retransmit_interval'  => 1.2,
    'max_attempts'         => 4,
));
$session = new SessionManager(array(/* ... */), $transport);
```

可观测：`inflightCount()` / `queuedCount()` / `droppedCount()`。

## HTTP 管理端（P4）

```php
use GatewayPush\Client\Service\AdminApi;

$api = new AdminApi('http://127.0.0.1:8290', 'API_SECRET', 5.0);

$api->push('uid', 'alice', array('title' => 'hi'), array('msg_id' => 'm-1', 'offline_mode' => 'queue'),
    function ($ok, $data, $error) { /* $error['status'] 为 HTTP 状态码，如 401 */ });
$api->stats(function ($ok, $data, $error) { /* 指标快照 */ });
$api->health(function ($ok, $data, $error) { /* 免鉴权存活探测 */ });
```

- 验签：`hex(hmac_sha256("{X-Timestamp}|{rawBody}", secret))`，随 `X-Timestamp` / `X-Sign` 头发送；
- 目标类型仅 `uid` / `device` / `client` 三类；主题广播不对外暴露。

## 重连与离线补投（P5 实测结论）

| 项 | 结论 |
|---|---|
| 断线检出 | 网关进程终止 → `ready → reconnecting`，退避重试 |
| 建连失败 | workerman 客户端**建连失败只触发 onError 不触发 onClose** —— `WsTransport` 已在 onError 中补发 close 信号，否则状态机会卡在 connecting |
| 重连后 | 自动重鉴权，回执 `reconnected=1`（会话按 `device_id` 恢复） |
| 离线补投 | 鉴权成功后由 `Push::replayOffline` 补投，客户端侧 `PushReceiver` 的 `$meta['offline'] === 1` |
| 设备绑定首胜 | 同 uid 换 `device_id` 报 `4004 设备不匹配`；换设备前须清 `gwpush:auth:bind:{uid}`（本机 `REDIS_DB=9`） |

## CLI 调试器（P6）

```bash
php client/bin/gwclient.php help                       # 帮助
php client/bin/gwclient.php --uid=alice ping           # 一次性命令
php client/bin/gwclient.php --uid=alice echo '{"a":1}'
php client/bin/gwclient.php --uid=alice notify '{"hi":1}'
php client/bin/gwclient.php --uid=alice push '{"t":1}' --to=bob --msg-id=m-1
php client/bin/gwclient.php --uid=alice push '{"t":1}' --bad-sign    # 验证 401
php client/bin/gwclient.php --uid=alice stats | health
php client/bin/gwclient.php --uid=alice shell          # REPL
php client/bin/gwclient.php --uid=alice listen topic.a # 挂机接收推送
```

常用选项：`--uid` `--device` `--secret` `--proto=ws|udp` `--ws` `--udp` `--api` `--timeout` `--hb`。

REPL 行为：

| 项 | 行为 |
|---|---|
| 提示符 | `(disconnected)> ` / `(connecting)> ` / `(ready)> ` —— 随状态迁移变化 |
| 推送不打断输入 | 异步输出先清当前行再打印，随后重绘提示符 |
| 断线 | 自动重连（指数退避），重连后自动重鉴权 |
| 设备身份 | 未指定 `--device` 时按 uid 派生（`gwcli-<md5前8位>`），避免触发设备绑定首胜拦截 |

> Windows 控制台不支持对 stdin 做 `stream_select`，此时输入降级为阻塞读：
> 推送会在下一次回车时渲染，功能不受影响；建议先执行 `chcp 65001` 以免中文乱码。

## 端到端对齐（P6）

```bash
composer test:client-e2e      # php client/tests/E2E/ClientE2E.php
```

与 `tests/e2e_check.php` 同口径的 15 个用例（A~O），前置条件相同（五角色 + Redis）。
客户端视角的四处必要调整已写入该文件头部注释：B 走裸传输层、D 用错误密钥签发、
K 改为「建会话前入队」、O 以订阅关系闭环对齐（HTTP 不支持主题广播目标）。

## 里程碑

| 阶段 | 内容 | 状态 |
|---|---|---|
| P0 | `Protocol/` 三件套 + 单测 | ✅ 已完成 |
| P1 | `WsTransport` + `SessionManager` | ✅ 已完成（单测 21 例 + 实测：auth/ping-pong/echo/40s 反向心跳存活全通过） |
| P2 | 7 个动作 API + `PushReceiver` + 自动 ack | ✅ 已完成（单测 16 例 + 实测：echo/subscribe/notify→push→auto-ack/topics/unsubscribe 全通过） |
| P3 | `UdpTransport` | ✅ 已完成（单测 + 实测：auth/report/echo 通、篡改签名 4001、重传可见） |
| P4 | `AdminApi`（`/push` `/stats` `/health`） | ✅ 已完成（单测 + 实测：验签通过可推送、错签 401、stats 返回指标） |
| P5 | 重连 + 会话恢复 + 离线补投 | ✅ 已完成（实测：`reconnected=1`、`offline=1` 补投、设备绑定首胜三项全通过） |
| P6 | CLI 调试器（含 REPL）+ 客户端侧 e2e | ✅ 已完成（单测 7 例 + 客户端 e2e 15/15 连续两轮全绿） |
