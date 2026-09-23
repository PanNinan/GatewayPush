<?php
/**
 * 端到端链路自检 —— 入口
 *
 * 用法：
 *   php tests/e2e_check.php <uid> [device_id] [timeout]
 *
 * 前置条件：
 *   1. Redis 可用
 *   2. 已启动 register / gateway / udp / business 四个角色
 *      （HTTP 用例还需 api 角色；若 api 未启动，用例 H 会标记为跳过）
 *
 * 注意：UDP 会话在 Redis 中不会因客户端退出而自动失效（UDP 无断连事件），
 * 而推送用例按「传入 uid + 用例后缀」复用同一身份。因此**连续多轮使用同一个
 * uid 运行会污染结果**：上一轮遗留的 UDP 会话会被判定为在线，导致离线补投
 * 用例（K）拿不到补投。每轮请使用新的 uid，例如递增的 e2e-uid-0001/0002。
 *
 * ---------------------------------------------------------------------
 * 文件布局
 * ---------------------------------------------------------------------
 *   tests/e2e_check.php          本文件：入口，装配与启动
 *   tests/E2E/Harness.php        公共设施：环境装配 / 用例状态 / 报文与客户端工具
 *   tests/E2E/CaseWsLink.php     [A][B]  WebSocket 链路族
 *   tests/E2E/CaseUdpLink.php    [C][D]  UDP 链路族
 *   tests/E2E/CasePushOnline.php [E][G][I] 在线投递族
 *   tests/E2E/CasePushOffline.php[F][K]  离线补投族
 *   tests/E2E/CaseActionRouting.php [J][M][Q] 动作分发与契约、运维动作通道隔离
 *   tests/E2E/CaseActionUdp.php  [N]  UDP 通道业务动作
 *   tests/E2E/CaseRateLimit.php  [L]  报文级限流
 *   tests/E2E/CaseSubscribe.php  [O]  订阅与广播闭环
 *   tests/E2E/CaseHttpApi.php    [H]  HTTP 接口（同步，事件循环前执行）
 *   tests/E2E/CaseHttpAction.php [P]  HTTP 动作调用（同步，事件循环前执行）
 *
 * ---------------------------------------------------------------------
 * 校验用例
 * ---------------------------------------------------------------------
 *   [A] WebSocket 正常链路：连接 -> auth 鉴权 -> ack -> ping -> pong
 *   [B] WebSocket 越权拦截：未鉴权直接发送业务指令 -> 返回 4003 并断开
 *   [C] UDP 正常链路：合法签名报文 -> 收到 ack 回执
 *   [D] UDP 签名拦截：篡改签名报文 -> 返回 4001
 *   [E] 定向推送（在线）：uid 目标 -> 在线连接收到 push 报文
 *   [F] 离线缓存与重连补投：目标离线时入队 -> 上线后自动补投
 *   [G] 推送幂等：同一 msg_id 重复提交 -> 仅投递一次
 *   [H] HTTP 接口：健康探测 / 验签通过 / 验签拒绝
 *   [I] UDP 定向推送：业务进程 -> UDP 出站队列 -> 网关 sendto
 *   [J] 指令路由表：data.action（echo / session）分发与 4006 / 4007 错误分支
 *   [K] UDP 离线补投：UDP 会话重建时经出站队列补投（offline=1）
 *   [L] 报文级限流：单连接连发超量报文 -> 部分放行、部分 4008 拒绝
 *   [M] 业务动作契约：参数校验白名单 / 4006 未知动作 / 4007 参数错误
 *   [N] UDP 通道业务动作：echo 经出站队列回执；report 按声明静默不回执
 *   [O] 订阅与广播闭环：subscribe -> enqueueTopic -> push -> unsubscribe
 *   [P] HTTP 动作调用：POST /action -> 队列 -> BusinessWorker -> 回程键 -> 响应
 *   [Q] 运维动作通道隔离：已鉴权客户端经 WS 调 kick / revoke / unbind -> 全部 4006
 *       （P4 安全前提的**唯一**端到端验收点 —— 返回非 4006 即终端提权成立）
 *
 * 退出码：0 = 全部通过，1 = 存在失败项
 */

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';

use GatewayPush\Common\RedisClient;
use GatewayPush\Tests\E2E\CaseActionRouting;
use GatewayPush\Tests\E2E\CaseActionUdp;
use GatewayPush\Tests\E2E\CaseHttpAction;
use GatewayPush\Tests\E2E\CaseHttpApi;
use GatewayPush\Tests\E2E\CasePushOffline;
use GatewayPush\Tests\E2E\CasePushOnline;
use GatewayPush\Tests\E2E\CaseRateLimit;
use GatewayPush\Tests\E2E\CaseSubscribe;
use GatewayPush\Tests\E2E\CaseUdpLink;
use GatewayPush\Tests\E2E\CaseWsLink;
use GatewayPush\Tests\E2E\Harness;
use Workerman\Worker;

$harness = Harness::boot($argv);
$harness->printHeader();

// 事件循环启动前同步执行；api 角色未启动时用例 H / P 会在汇总中标记为失败
CaseHttpApi::run($harness);
CaseHttpAction::run($harness);

// workerman 默认把框架日志落在「入口脚本所在目录」（$argv[0] 同级），会让
// tests/ 里凭空多出一个 tests/workerman.log。显式收敛到 runtime/logs，
// 与服务端 start.php 的 Worker::$logFile 同一处，运行时产物不散落在源码树里。
$logDir = BASE_PATH . '/runtime/logs';
if (!is_dir($logDir) && !@mkdir($logDir, 0o755, true) && !is_dir($logDir)) {
    fwrite(STDERR, "[WARN] 日志目录创建失败：{$logDir}\n");
}
Worker::$logFile = $logDir . '/e2e_check.log';

$worker = new Worker();

$worker->onWorkerStart = function () use ($harness) {
    RedisClient::init($harness->appConfig['redis']);

    // 用例注册顺序与拆分前的单文件脚本严格一致 ——
    // 多个用例共享同一事件循环，注册顺序会影响建连与定时器的相对时序。
    CaseWsLink::authLink($harness);                  // A
    CaseWsLink::unauthorizedProbe($harness);         // B
    CaseUdpLink::normalLink($harness);               // C
    CaseUdpLink::badSign($harness);                  // D
    CasePushOnline::wsDirect($harness);              // E
    CasePushOffline::offlineCache($harness);         // F
    CasePushOnline::idempotent($harness);            // G
    CasePushOnline::udpOutbound($harness);           // I
    CaseActionRouting::routeTable($harness);         // J
    CasePushOffline::udpOfflineBackfill($harness);   // K
    CaseRateLimit::burst($harness);                  // L
    CaseActionRouting::actionContract($harness);     // M
    CaseActionUdp::silentReport($harness);           // N
    CaseSubscribe::broadcastLoop($harness);          // O
    CaseActionRouting::opsChannelGuard($harness);    // Q

    $harness->registerTimeoutGuard();
};

Worker::runAll();
