<?php
/**
 * 用例 E / G / I：在线投递族
 *
 *   [E] 定向推送（在线）：uid 目标 -> 在线连接收到 push 报文
 *   [G] 推送幂等：同一 msg_id 重复提交 -> 仅投递一次
 *   [I] UDP 定向推送：业务进程 -> 出站队列 -> 网关 sendto
 *
 * 三者共同的判定前提是「目标当前在线」，区别只在承载通道与观测点：
 *   E 观察 WS 直投，G 观察去重后的投递次数，I 观察 UDP 出站反向通道。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Tests\E2E;

use GatewayPush\Business\Message;
use GatewayPush\Business\Push;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\AsyncUdpConnection;
use Workerman\Timer;

final class CasePushOnline
{
    /**
     * 用例 E：定向推送（在线投递）
     *
     * @param Harness $h
     *
     * @return void
     */
    public static function wsDirect(Harness $h)
    {
        $c    = $h->ctx('E');
        $connE = new AsyncTcpConnection($h->wsAddress);
        $eFed  = false;

        $connE->onConnect = function ($con) use ($h, $c) {
            echo "[E] WebSocket 已连接\n";
            $con->send($h->encode($h->buildPacket(Message::CMD_AUTH, 'e-auth-1', [
                'uid'       => $c['uid'],
                'device_id' => $c['device_id'],
                'token'     => $c['token'],
            ])));
            echo "[E] -> auth\n";
        };

        $connE->onMessage = function ($con, $raw) use ($h, &$eFed, $c) {
            echo "[E] <- {$raw}\n";
            $packet = json_decode($raw, true);
            if (!is_array($packet) || !isset($packet['cmd'])) {
                return;
            }

            // 鉴权通过后立即投递一条推送任务，验证「在线直达」链路
            if ($packet['cmd'] === Message::CMD_ACK && !$eFed) {
                $eFed = true;
                Push::enqueue('uid', $c['uid'], ['case' => 'E', 'value' => 7], [
                    'msg_id' => $c['msg_id'],
                    'source' => 'e2e',
                ]);
                echo "[E] -> 已提交推送任务（uid 目标，msg_id {$c['msg_id']}）\n";

                return;
            }

            if ($packet['cmd'] === Message::CMD_PUSH) {
                $h->state['E'] = true;

                $receivedId = isset($packet['msg_id']) ? (string)$packet['msg_id'] : '';
                if ($receivedId !== $c['msg_id']) {
                    $h->state['E']     = false;
                    $h->state['E_msg'] = "msg_id 不一致（期望 {$c['msg_id']}，实际 {$receivedId}）";
                }
                $con->close();
                $h->finish();

                return;
            }

            if ($packet['cmd'] === Message::CMD_ERROR) {
                $h->state['E']     = false;
                $h->state['E_msg'] = isset($packet['data']['msg']) ? (string)$packet['data']['msg'] : '鉴权阶段返回错误';
                $con->close();
                $h->finish();
            }
        };

        $connE->onClose = function () use ($h) {
            if ($h->state['E'] === 'pending') {
                $h->state['E']     = false;
                $h->state['E_msg'] = '流程完成前连接被关闭';
                $h->finish();
            }
        };

        $connE->connect();
    }

    /**
     * 用例 G：推送幂等去重
     *
     * @param Harness $h
     *
     * @return void
     */
    public static function idempotent(Harness $h)
    {
        $c      = $h->ctx('G');
        $connG  = new AsyncTcpConnection($h->wsAddress);
        $gFed   = false;
        $gCount = 0;

        $connG->onConnect = function ($con) use ($h, $c) {
            echo "[G] WebSocket 已连接\n";
            $con->send($h->encode($h->buildPacket(Message::CMD_AUTH, 'g-auth-1', [
                'uid'       => $c['uid'],
                'device_id' => $c['device_id'],
                'token'     => $c['token'],
            ])));
            echo "[G] -> auth\n";
        };

        $connG->onMessage = function ($con, $raw) use ($h, &$gFed, &$gCount, $c) {
            $packet = json_decode($raw, true);
            if (!is_array($packet) || !isset($packet['cmd'])) {
                return;
            }

            if ($packet['cmd'] === Message::CMD_ACK && !$gFed) {
                $gFed = true;
                // 同一 msg_id 连续提交两次，期望业务侧仅投递一次
                Push::enqueue('uid', $c['uid'], ['case' => 'G', 'n' => 1], ['msg_id' => $c['msg_id'], 'source' => 'e2e']);
                Push::enqueue('uid', $c['uid'], ['case' => 'G', 'n' => 2], ['msg_id' => $c['msg_id'], 'source' => 'e2e']);
                echo "[G] -> 已提交两次相同 msg_id（{$c['msg_id']}）\n";

                Timer::add(2.0, function () use ($h, &$gCount, $con) {
                    if ($gCount === 1) {
                        $h->state['G'] = true;
                    } else {
                        $h->state['G']     = false;
                        $h->state['G_msg'] = "收到 {$gCount} 条推送，期望 1 条（幂等去重未生效）";
                    }
                    $con->close();
                    $h->finish();
                }, [], false);

                return;
            }

            if ($packet['cmd'] === Message::CMD_PUSH) {
                $gCount++;
                echo "[G] <- push（累计 {$gCount} 条）\n";
            }
        };

        $connG->onClose = function () use ($h) {
            if ($h->state['G'] === 'pending') {
                $h->state['G']     = false;
                $h->state['G_msg'] = '连接已关闭但未完成幂等判定';
                $h->finish();
            }
        };

        $connG->connect();
    }

    /**
     * 用例 I：UDP 定向推送（出站通道）
     *
     * UDP 的 client_id 不在 Gateway 连接表内，服务端推送必须经
     * 「业务进程 -> 出站队列 -> UDP 网关 sendto」闭环，本用例验证该反向通道。
     *
     * @param Harness $h
     *
     * @return void
     */
    public static function udpOutbound(Harness $h)
    {
        $c         = $h->ctx('I');
        $udpI      = new AsyncUdpConnection($h->udpAddress);
        $iReported = false;
        $iAttempt  = 0;

        // 必须按引用捕获自身（&$sendReport）：闭包体在**赋值之前**求值，
        // 若外层 use 列表不含它，内层闭包捕获到的将是一个新建的 null 变量，
        // 重传时触发 "Value of type null is not callable" 并中断整个用例。
        $sendReport = function () use ($h, $udpI, $c, &$iAttempt, &$iReported, &$sendReport) {
            if ($iAttempt >= 3 || $iReported) {
                return;
            }
            $iAttempt++;
            $payload = $h->encode($h->buildPacket(Message::CMD_DATA, 'i-udp-' . $iAttempt, [
                'uid'       => $c['uid'],
                'device_id' => $c['device_id'],
                'token'     => $c['token'],
                'data'      => ['type' => 'udp-report'],
            ]));
            $udpI->send($payload);
            echo "[I] -> 上报报文（第 {$iAttempt} 次，用于建立 UDP 应用层会话）\n";

            Timer::add(1.0, function () use (&$sendReport, &$iReported) {
                if (!$iReported) {
                    $sendReport();
                }
            }, [], false);
        };

        $udpI->onConnect = function ($con) use ($h, $sendReport) {
            echo "[I] UDP 通道已就绪\n";
            Timer::add(0.2, $sendReport, [], false);

            // 内部超时：UDP 允许丢包，但本地回环下不应丢失，超时即判定失败
            Timer::add(6.0, function () use ($h) {
                if ($h->state['I'] === 'pending') {
                    $h->state['I']     = false;
                    $h->state['I_msg'] = 'UDP 出站推送未在 6 秒内到达客户端';
                    $h->finish();
                }
            }, [], false);
        };

        $udpI->onMessage = function ($con, $raw) use ($h, $c, &$iReported) {
            $packet = json_decode($raw, true);
            if (!is_array($packet) || !isset($packet['cmd'])) {
                return;
            }

            // 会话建立后提交推送任务，验证 UDP 出站通道。
            // 注意时序：ack 由 UDP 网关在收到报文时**立即**回复，而 UDP 应用层会话
            // 由业务进程消费 queue:udp:in 后**异步**建立，二者存在毫秒级竞态。
            // 若在 ack 瞬间入队，推送任务可能先于会话绑定被消费，从而被判定为离线。
            // 此处延迟提交，确保会话已就绪（生产环境下推送由外部系统发起，不存在该竞态）。
            if ($packet['cmd'] === Message::CMD_ACK && !$iReported) {
                $iReported = true;
                echo "[I] <- ack（UDP 应用层会话已建立）\n";

                Timer::add(0.6, function () use ($c) {
                    Push::enqueue('uid', $c['uid'], ['case' => 'I', 'value' => 'udp-push'], [
                        'msg_id'       => $c['msg_id'],
                        'offline_mode' => 'drop',
                        'source'       => 'e2e',
                    ]);
                    echo "[I] -> 推送任务已提交（uid 目标，期望经 UDP 出站通道下发）\n";
                }, [], false);

                return;
            }

            if ($packet['cmd'] === Message::CMD_PUSH) {
                $receivedId = isset($packet['msg_id']) ? (string)$packet['msg_id'] : '';
                if ($receivedId !== $c['msg_id']) {
                    $h->state['I']     = false;
                    $h->state['I_msg'] = "UDP 补投 msg_id 不一致（期望 {$c['msg_id']}，实际 {$receivedId}）";
                } else {
                    $h->state['I'] = true;
                }
                echo "[I] <- push（UDP 出站通道打通）\n";
                $con->close();
                $h->finish();
            }
        };

        $udpI->connect();
    }
}
