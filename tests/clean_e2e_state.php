<?php
/**
 * e2e 前置清理：清掉上一轮遗留的在线索引与离线队列（本机 REDIS_DB=9 测试数据）
 *
 * ---------------------------------------------------------------------
 * 为什么需要它（两个必须清理的理由）
 * ---------------------------------------------------------------------
 * 1. **在线索引残留**：`online:clients` / `online:ws` / `online:udp` 是**实时**集合 ——
 *    连接建立时 `sAdd`、断开时 `sRem`。若进程被强杀（后台任务结束、Ctrl+C、超时中断），
 *    这两步都不会执行，于是「早已不存在的连接」仍被判定为在线。
 *    后果（实测 2026-09-23）：K 用例的 uid 被判在线 ⇒ 消息走**在线投递**而非离线补投，
 *    补投报文的 `offline=1` 永远不出现，用例稳定超时失败：
 *        [FAIL] UDP 离线补投  原因：UDP 离线补投未在 9 秒内到达客户端
 *    同时日志会出现「同一 msg_id 下发给两个 clientId」（一个本轮端口、一个残留端口）——
 *    这是识别该问题最快的特征。
 *
 * 2. **离线队列残留**：`push:offline:{uid}` 里若留着上一轮的消息，下一轮重连会**先补投旧消息**，
 *    用例拿到的 msg_id 与本次提交的不一致：
 *        [FAIL] 离线缓存与重连补投  原因：补投 msg_id 不一致（期望 X，实际 Y）
 *
 * 两者都不是功能缺陷（补投机制本身正常），是**测试环境残留**。故跑 e2e 前先跑本脚本。
 *
 * ---------------------------------------------------------------------
 * 清理范围（精确，不用 KEYS / FLUSHDB）
 * ---------------------------------------------------------------------
 *   - `online:clients` / `online:ws` / `online:udp`：**整个删除**（服务重启后本就该是空的）
 *   - 候选 uid（e2e-* / p3test-*）的：`uid:clients:` `auth:bind:` `push:offline:` `device:client:`
 *   - 候选 uid 的会话键：`session:{cid}` / `heartbeat:{cid}`（clientId 从 `uid:clients` 读出）
 *
 * ⚠ 键名一律经 `RedisKeys` + `RedisClient::*()`：**不要用 `->client()` 拿原始客户端** ——
 *   那会绕过 `RedisClient::key()` 的前缀拼接，删的全是不存在的裸键名（实测删到 0 个）。
 *
 * 用法：php tests/clean_e2e_state.php
 * 兼容 PHP 8.2 ~ 8.5
 */

use GatewayPush\Common\RedisClient;
use GatewayPush\Common\RedisKeys;
use Workerman\Worker;

require __DIR__ . '/../vendor/autoload.php';

$appConfig = require __DIR__ . '/../config/app.php';

/**
 * 候选 uid：e2e 的 uid = e2e-uid-1001[-{用例字母}]；p3 验收夹具 = p3test-uid / p3test-dev
 *
 * @return list<string>
 */
function e2eCandidateUids(): array
{
    $suffixes   = ['', '-A', '-B', '-E', '-F', '-G', '-H', '-I', '-J', '-K', '-L', '-M', '-N', '-O', '-P', '-Q'];
    $candidates = ['p3test-uid', 'p3test-dev'];
    foreach ($suffixes as $s) {
        $candidates[] = 'e2e-uid-1001' . $s;
    }

    return $candidates;
}

/**
 * 收尾
 *
 * @param int $rmOnline
 * @param int $rmIdx
 * @param int $rmSes
 *
 * @return void
 */
function e2eCleanDone(int $rmOnline, int $rmIdx, int $rmSes): void
{
    printf('① 在线索引已删除 %d 个集合（online:clients / online:ws / online:udp）%s', $rmOnline, PHP_EOL);
    printf('② 索引类键已删除 %d 个（uid:clients / auth:bind / push:offline / device:client）%s', $rmIdx, PHP_EOL);
    printf('③ 会话类键已删除 %d 个（session / heartbeat）%s', $rmSes, PHP_EOL);
    echo '清理完成，可直接跑：composer test:e2e' . PHP_EOL;
    Worker::stopAll();
}

$worker        = new Worker();
$worker->count = 1;

$worker->onWorkerStart = function () use ($appConfig) {
    RedisClient::init($appConfig['redis'] ?? []);

    $rmIdx    = 0;
    $rmSes    = 0;
    $uids     = e2eCandidateUids();
    $left     = count($uids);
    $finished = false;

    $maybeDone = function () use (&$left, &$finished, &$rmIdx, &$rmSes) {
        if ($left <= 0 && !$finished) {
            $finished = true;
            e2eCleanDone(1, $rmIdx, $rmSes);
        }
    };

    // ① 在线索引：整体删除
    RedisClient::del([
        RedisKeys::online(),
        RedisKeys::online('ws'),
        RedisKeys::online('udp'),
    ], function ($n) use ($uids, &$left, &$rmIdx, &$rmSes, $maybeDone) {
        $rmOnline = (int)$n;
        echo '① 在线索引已删除 ' . $rmOnline . ' 个集合' . PHP_EOL;

        // ② 逐个候选 uid：先读出 clientId（用于删会话键），再删索引类键
        foreach ($uids as $uid) {
            RedisClient::sMembers(RedisKeys::uidClients($uid), function ($members) use ($uid, &$left, &$rmIdx, &$rmSes, $maybeDone) {
                $cids = is_array($members) ? $members : [];

                $sessionKeys = [];
                foreach ($cids as $cid) {
                    $sessionKeys[] = RedisKeys::session((string)$cid);
                    $sessionKeys[] = RedisKeys::heartbeat((string)$cid);
                }

                RedisClient::del([
                    RedisKeys::uidClients($uid),
                    RedisKeys::authBind($uid),
                    RedisKeys::pushOffline($uid),
                    RedisKeys::deviceClient($uid),
                ], function ($n) use ($sessionKeys, &$left, &$rmIdx, &$rmSes, $maybeDone) {
                    $rmIdx += (int)$n;

                    $stepDown = function () use (&$left, $maybeDone) {
                        $left--;
                        $maybeDone();
                    };

                    if ($sessionKeys === []) {
                        $stepDown();

                        return;
                    }
                    RedisClient::del($sessionKeys, function ($m) use ($stepDown, &$rmSes) {
                        $rmSes += (int)$m;
                        $stepDown();
                    });
                });
            });
        }
    });
};

Worker::runAll();
