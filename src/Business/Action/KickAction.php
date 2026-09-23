<?php
/**
 * 业务动作：kick（踢线）
 *
 * ---------------------------------------------------------------------
 * 为什么必须在 business 进程内
 * ---------------------------------------------------------------------
 * `GatewayClient::closeClient()` 依赖 BusinessWorker 与 Gateway 之间的连接池，
 * 而 business 进程已持有到所有 Gateway 的反向连接。Api 进程没有这条连接，
 * 因此踢线**不能**在 HTTP 进程内直接完成，必须经动作队列转交 business。
 *
 * ---------------------------------------------------------------------
 * 三条语义边界（后台 UI 必须如实写明，不得让用户误以为踢了就下线了）
 * ---------------------------------------------------------------------
 * 1. **只断 TCP，不撤 Token。** closeClient 触发 gateway 的 onClose →
 *    `Session::markOffline()` **保留会话供重连**，客户端可立刻用同一 Token 重连成功。
 * 2. **要「踢下线且禁止重连」= revoke 先行 + kick 后行。**
 *    顺序反了（先 kick 后 revoke）时客户端正好落在重连窗口内，会用同一 Token 重连成功。
 * 3. **UDP 无踢线。** UDP 没有连接实体（clientId 是 `udp:{ip}:{port}`，不在 Gateway 连接表里），
 *    `closeClient()` 对它无效。UDP 侧只能 revoke，且该 Token 的**下一个包**才会被拒。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Business\Action;

use GatewayPush\Business\ActionContext;
use GatewayPush\Business\ActionInterface;
use GatewayPush\Business\Message;
use GatewayPush\Business\Monitor;
use GatewayPush\Business\Push;
use GatewayPush\Business\Session;
use GatewayPush\Common\Logger;
use GatewayWorker\Lib\Gateway as GatewayClient;

/**
 * 业务动作 kick：断开指定连接（运维动作，仅限 HTTP 通道）
 *
 * 参数二选一：`client_id`（单条）或 `uid`（该 uid 的全部在线连接）。
 */
class KickAction implements ActionInterface
{
    /**
     * @param ActionContext $ctx
     *
     * @return void
     */
    public function handle(ActionContext $ctx): void
    {
        Monitor::incr('action_kick');

        $clientId = trim((string)$ctx->param('client_id', ''));
        $uid      = trim((string)$ctx->param('uid', ''));
        $reason   = trim((string)$ctx->param('reason', ''));

        // 至少要有一个定位维度，否则拒绝（不静默兜底成「踢自己」）
        if ($clientId === '' && $uid === '') {
            Monitor::incr('action_fail');
            $ctx->replyError(Message::CODE_PARAM_MISSING, 'client_id 与 uid 至少提供一个');

            return;
        }

        $targets = $clientId !== '' ? [$clientId] : [];

        if ($uid === '') {
            self::doKick($ctx, $targets, '', $reason);

            return;
        }

        // uid 形态：展开为该 uid 的全部 clientId（Session 维护的是 uid:clients: 集合）
        Session::findByUid($uid, function ($clientIds) use ($ctx, $targets, $uid, $reason) {
            $merged = array_values(array_unique(array_merge($targets, is_array($clientIds) ? $clientIds : [])));
            self::doKick($ctx, $merged, $uid, $reason);
        });
    }

    /**
     * 逐个踢线并汇总
     *
     * @param ActionContext      $ctx
     * @param array<int, string> $clientIds
     * @param string             $uid
     * @param string             $reason
     *
     * @return void
     */
    private static function doKick(ActionContext $ctx, array $clientIds, string $uid, string $reason): void
    {
        $closed  = 0;
        $skipped = [];
        $failed  = [];

        foreach ($clientIds as $cid) {
            $cid = (string)$cid;
            if ($cid === '') {
                continue;
            }

            // UDP 没有连接实体，closeClient 对它无效 —— 计入 skipped 而不是失败，
            // 否则运维会误判「踢线失败」，实际是「这个通道压根没有线可踢」。
            if (str_starts_with($cid, Push::UDP_PREFIX)) {
                $skipped[] = $cid;

                continue;
            }

            try {
                GatewayClient::closeClient($cid);
                $closed++;
            } catch (\Throwable $e) {
                // closeClient 在本机未连上 Gateway 时会抛 —— 逐个兜住，不让一条失败中断整批
                $failed[] = $cid;
                Logger::warn('kick 执行失败', ['client_id' => $cid, 'error' => $e->getMessage()]);
            }
        }

        // 运维敏感操作：一律留痕（不落 token / payload，只落定位维度与结果计数）
        Logger::warn('运维动作 kick 已执行', [
            'uid'        => $uid,
            'requested'  => count($clientIds),
            'closed'     => $closed,
            'skipped'    => count($skipped),
            'failed'     => count($failed),
            'reason'     => $reason,
            'channel'    => $ctx->channel(),
            'operator'   => $ctx->uid(),
        ]);

        if ($failed !== []) {
            Monitor::incr('action_fail');
        }

        $ctx->reply([
            'action'      => 'kick',
            'uid'         => $uid,
            'requested'   => count($clientIds),
            'closed'      => $closed,
            'skipped'     => count($skipped),
            'failed'      => count($failed),
            'skipped_ids' => $skipped,
            'failed_ids'  => $failed,
            'reason'      => $reason,
            'at'          => time(),
            // 语义提示随回执下发 —— 调用方（后台）不自行编词，避免文档与实现漂移
            'note'        => '仅断开 TCP 连接，Token 仍然有效：客户端可立即重连。'
                . '如需禁止重连，请先执行 revoke 再执行 kick。',
        ]);
    }
}
