<?php
/**
 * 用例 F / K：离线补投族
 *
 *   [F] 离线缓存与重连补投：目标离线时入队 -> 上线后自动补投（WS 通道）
 *   [K] UDP 离线补投：UDP 会话重建时经出站队列补投（offline=1）
 *
 * 两者的共同前提是「任务提交时目标不在线」，差异在补投承载通道：
 * F 走 WS 直投，K 走 UDP 出站队列。相位设计也一致 ——
 * 先在无连接状态下入队，再建立连接 / 重建会话以触发补投。
 *
 * 兼容 PHP 8.0 ~ 8.5
 */

namespace GatewayPush\Tests\E2E;

use GatewayPush\Business\Message;
use GatewayPush\Business\Push;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\AsyncUdpConnection;
use Workerman\Timer;

final class CasePushOffline
{
    /**
     * 用例 F：离线缓存与重连补投（WS）
     *
     * @param Harness $h
     * @return void
     */
    public static function offlineCache(Harness $h)
    {
        $c = $h->ctx('F');

        // 阶段一：目标离线时提交推送任务（应落入 push:offline:{uid}）
        Push::enqueue('uid', $c['uid'], array('case' => 'F', 'value' => 'offline'), array(
            'msg_id'       => $c['msg_id'],
            'offline_mode' => 'queue',
            'source'       => 'e2e',
        ));
        echo "[F] -> 离线推送任务已提交（uid 目标，离线策略 queue）\n";

        // 阶段二：设备上线，鉴权成功后应自动补投
        $connF = new AsyncTcpConnection($h->wsAddress);

        $connF->onConnect = function ($con) use ($h, $c) {
            echo "[F] WebSocket 已连接\n";
            $con->send($h->encode($h->buildPacket(Message::CMD_AUTH, 'f-auth-1', array(
                'uid'       => $c['uid'],
                'device_id' => $c['device_id'],
                'token'     => $c['token'],
            ))));
            echo "[F] -> auth（等待离线消息补投）\n";
        };

        $connF->onMessage = function ($con, $raw) use ($h, $c) {
            echo "[F] <- {$raw}\n";
            $packet = json_decode($raw, true);
            if (!is_array($packet) || !isset($packet['cmd'])) {
                return;
            }

            if ($packet['cmd'] === Message::CMD_PUSH) {
                $isOffline  = isset($packet['offline']) ? (int)$packet['offline'] : 0;
                $receivedId = isset($packet['msg_id']) ? (string)$packet['msg_id'] : '';

                if ($receivedId !== $c['msg_id']) {
                    $h->state['F']     = false;
                    $h->state['F_msg'] = "补投 msg_id 不一致（期望 {$c['msg_id']}，实际 {$receivedId}）";
                } elseif ($isOffline !== 1) {
                    $h->state['F']     = false;
                    $h->state['F_msg'] = '补投报文未标记 offline=1';
                } else {
                    $h->state['F'] = true;
                }
                $con->close();
                $h->finish();
                return;
            }

            if ($packet['cmd'] === Message::CMD_ERROR) {
                $h->state['F']     = false;
                $h->state['F_msg'] = isset($packet['data']['msg']) ? (string)$packet['data']['msg'] : '鉴权阶段返回错误';
                $con->close();
                $h->finish();
            }
        };

        $connF->onClose = function () use ($h) {
            if ($h->state['F'] === 'pending') {
                $h->state['F']     = false;
                $h->state['F_msg'] = '连接已关闭但未收到离线补投报文';
                $h->finish();
            }
        };

        // 稍晚建连，确保离线任务已先入队
        Timer::add(0.3, function () use ($connF) {
            $connF->connect();
        }, array(), false);
    }

    /**
     * 用例 K：UDP 离线补投
     *
     * @param Harness $h
     * @return void
     */
    public static function udpOfflineBackfill(Harness $h)
    {
        $c = $h->ctx('K');

        // 阶段一：uidK 无任何在线连接时提交，应落入 push:offline:{uidK}
        Push::enqueue('uid', $c['uid'], array('case' => 'K', 'value' => 'udp-offline'), array(
            'msg_id'       => $c['msg_id'],
            'offline_mode' => 'queue',
            'source'       => 'e2e',
        ));
        echo "[K] -> UDP 离线推送任务已提交（目标无在线连接，预期落入离线列表）\n";

        $udpK      = new AsyncUdpConnection($h->udpAddress);
        $kReported = false;
        $kAttempt  = 0;

        // 阶段二：UDP 客户端上报 -> 会话重建 -> 业务进程补投（经出站队列 sendto 回客户端）
        $sendReportK = function () use ($h, $udpK, $c, &$kAttempt, &$kReported) {
            if ($kAttempt >= 3 || $kReported) {
                return;
            }
            $kAttempt++;
            $udpK->send($h->encode($h->buildPacket(Message::CMD_DATA, 'k-udp-' . $kAttempt, array(
                'uid'       => $c['uid'],
                'device_id' => $c['device_id'],
                'token'     => $c['token'],
                'data'      => array('type' => 'udp-offline-report'),
            ))));
            echo "[K] -> 上报报文（第 {$kAttempt} 次，重建 UDP 会话以触发补投）\n";

            Timer::add(1.0, function () use (&$sendReportK, &$kReported) {
                if (!$kReported) {
                    $sendReportK();
                }
            }, array(), false);
        };

        $udpK->onConnect = function ($con) use ($h, $sendReportK) {
            echo "[K] UDP 通道已就绪\n";

            // 延迟上报：先留出时间让业务进程把首个推送任务写入离线列表；
            // 若会话先建立，任务会走在线直投（offline=0），用例即失去意义。
            Timer::add(1.5, $sendReportK, array(), false);

            Timer::add(9.0, function () use ($h) {
                if ($h->state['K'] === 'pending') {
                    $h->state['K']     = false;
                    $h->state['K_msg'] = 'UDP 离线补投未在 9 秒内到达客户端';
                    $h->finish();
                }
            }, array(), false);
        };

        $udpK->onMessage = function ($con, $raw) use ($h, $c, &$kReported) {
            $packet = json_decode($raw, true);
            if (!is_array($packet) || !isset($packet['cmd'])) {
                return;
            }

            if ($packet['cmd'] === Message::CMD_ACK) {
                $kReported = true;
                echo "[K] <- ack（UDP 会话已重建，等待离线补投）\n";
                return;
            }

            if ($packet['cmd'] === Message::CMD_PUSH) {
                $receivedId = isset($packet['msg_id']) ? (string)$packet['msg_id'] : '';
                $isOffline  = isset($packet['offline']) ? (int)$packet['offline'] : 0;

                if ($receivedId !== $c['msg_id']) {
                    $h->state['K']     = false;
                    $h->state['K_msg'] = "补投 msg_id 不一致（期望 {$c['msg_id']}，实际 {$receivedId}）";
                } elseif ($isOffline !== 1) {
                    $h->state['K']     = false;
                    $h->state['K_msg'] = '补投报文未标记 offline=1';
                } else {
                    $h->state['K'] = true;
                    echo "[K] <- push（offline=1，UDP 离线补投通道打通）\n";
                }
                $con->close();
                $h->finish();
            }
        };

        $udpK->connect();
    }
}
