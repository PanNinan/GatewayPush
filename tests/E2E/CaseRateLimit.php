<?php
/**
 * 用例 L：报文级限流（令牌桶）
 *
 * 验证思路：单连接在极短时间内连发远超桶容量的报文，期望
 *   - 桶内报文被正常处理（echo 回执，证明「不误伤」）
 *   - 超出部分被 4008 拒绝（证明「确实限流」）
 * 两个条件同时成立才算通过：只拒绝不放行说明配额过严，
 * 只放行不拒绝说明限流失效。
 *
 * 本方使用独立 uid/device，避免污染其它用例的令牌桶。
 *
 * 兼容 PHP 8.0 ~ 8.5
 */

namespace GatewayPush\Tests\E2E;

use GatewayPush\Business\Message;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;

final class CaseRateLimit
{
    /**
     * 连发条数，需显著超过 conn 维度 burst（默认 40）
     */
    const SENT = 100;

    /**
     * @param Harness $h
     * @return void
     */
    public static function burst(Harness $h)
    {
        $c = $h->ctx('L');

        $connL = new AsyncTcpConnection($h->wsAddress);

        $lSent  = self::SENT;
        $lAck   = 0;     // 通过限流的 echo 回执数
        $lLimit = 0;     // 被 4008 拒绝数

        $connL->onConnect = function ($con) use ($h, $c) {
            echo "[L] WebSocket 已连接，发送鉴权\n";
            $con->send($h->encode($h->buildPacket(Message::CMD_AUTH, 'L-auth', array(
                'uid'       => $c['uid'],
                'device_id' => $c['device_id'],
                'token'     => $c['token'],
            ))));
        };

        $connL->onMessage = function ($con, $raw) use ($h, $c, &$lAck, &$lLimit, $lSent) {
            $packet = json_decode($raw, true);
            if (!is_array($packet) || !isset($packet['cmd'])) {
                return;
            }

            // 鉴权回执到达后立即连发，制造瞬时突发
            if ($packet['cmd'] === Message::CMD_ACK && $packet['seq'] === 'L-auth') {
                echo "[L] 鉴权成功，连发 {$lSent} 条 data 报文以触发限流\n";

                for ($i = 1; $i <= $lSent; $i++) {
                    $con->send($h->encode($h->buildPacket(Message::CMD_DATA, 'L-' . $i, array(
                        'uid'       => $c['uid'],
                        'device_id' => $c['device_id'],
                        'token'     => $c['token'],
                        'data'      => array('action' => 'echo', 'params' => array('i' => $i)),
                    ))));
                }

                // 限流判定与回执均为异步，留出收集窗口
                Timer::add(2.5, function () use ($h, $con, &$lAck, &$lLimit, $lSent) {
                    if ($lLimit <= 0) {
                        $h->state['L_msg'] = "连发 {$lSent} 条未触发任何限流拒绝（通过 {$lAck} 条）";
                        $h->state['L']     = false;
                    } elseif ($lAck <= 0) {
                        $h->state['L_msg'] = "全部报文被拒绝（拒绝 {$lLimit} 条），配额可能配置过严";
                        $h->state['L']     = false;
                    } else {
                        $h->state['L'] = true;
                    }
                    echo "[L] 限流统计：放行 {$lAck} 条，拒绝 {$lLimit} 条\n";
                    $con->close();
                    $h->finish();
                }, array(), false);
                return;
            }

            // 业务报文回执（seq 形如 L-<n>）
            if (!preg_match('/^L-\d+$/', (string)$packet['seq'])) {
                return;
            }

            if ($packet['cmd'] === Message::CMD_ACK
                && isset($packet['data']['action'])
                && $packet['data']['action'] === 'echo'
            ) {
                $lAck++;
                return;
            }

            if ($packet['cmd'] === Message::CMD_ERROR
                && isset($packet['data']['code'])
                && (int)$packet['data']['code'] === Message::CODE_RATE_LIMIT
            ) {
                $lLimit++;
                return;
            }

            $h->state['L_msg'] = '收到非预期回执：' . substr((string)$raw, 0, 120);
            $h->state['L']     = false;
            $h->finish();
        };

        $connL->connect();
    }
}
