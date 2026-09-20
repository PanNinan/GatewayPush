<?php
/**
 * 用例 N：UDP 通道业务动作
 *
 * 验证 UDP 上报的业务报文能走与 WS 完全一致的动作分发：
 *   1) echo  在 UDP 上声明 sync -> 经出站队列收到 ack（证明回执通道打通）
 *   2) report 在 UDP 上声明 none -> 静默处理，不回任何报文
 *   3) 静默 ≠ 不处理：回查 Redis 确认计数已写入
 *
 * 「静默」的判定方式：先发 report 再发 echo#2，若收到 seq 属于 report 的
 * 任何回执即判失败 —— 这比单纯等待超时更精确。
 *
 * ---------------------------------------------------------------------
 * UDP 存在两层回执，必须区分
 * ---------------------------------------------------------------------
 *   传输层 —— UDP 网关收到合法报文即回 ack（data 为空、不含 action 字段），
 *             属于「收包确认」，与业务动作的回执策略无关
 *   业务层 —— 动作执行结果，按 config/actions.php 的 reply 声明发放
 * 若不加区分，会把传输层 ack 误判为业务回执。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Tests\E2E;

use GatewayPush\Business\Message;
use GatewayPush\Common\RedisClient;
use Workerman\Connection\AsyncUdpConnection;
use Workerman\Timer;

final class CaseActionUdp
{
    /**
     * @param Harness $h
     * @return void
     */
    public static function silentReport(Harness $h)
    {
        $c           = $h->ctx('N');
        $udpN        = new AsyncUdpConnection($h->udpAddress);
        $nEcho1Acked = false;

        $udpN->onConnect = function ($con) use ($h, $c, &$nEcho1Acked) {
            echo "[N] UDP 通道已就绪\n";

            // 延迟首包 + 应用层重传：规避 UDP 首个报文在 socket 就绪前的静默丢失
            $attempt = function ($n) use ($h, $con, $c, &$nEcho1Acked, &$attempt) {
                if ($nEcho1Acked || $n > 4) {
                    return;
                }
                $con->send($h->encode($h->buildPacket(Message::CMD_DATA, $c['seq1'], array(
                    'uid'       => $c['uid'],
                    'device_id' => $c['device_id'],
                    'token'     => $c['token'],
                    'data'      => array('action' => 'echo', 'params' => array('phase' => 1)),
                ))));
                echo "[N] -> data/action=echo#1（建立 UDP 应用层会话，第 {$n} 次）\n";

                Timer::add(1.2, function () use ($n, &$attempt) {
                    $attempt($n + 1);
                }, array(), false);
            };

            Timer::add(0.2, function () use (&$attempt) {
                $attempt(1);
            }, array(), false);
        };

        $udpN->onMessage = function ($con, $raw) use ($h, $c, &$nEcho1Acked) {
            $packet = json_decode($raw, true);
            if (!is_array($packet) || !isset($packet['cmd'])) {
                return;
            }

            $seq  = isset($packet['seq']) ? (string)$packet['seq'] : '';
            $data = isset($packet['data']) && is_array($packet['data']) ? $packet['data'] : array();

            // 两层回执的判别依据：业务层回执必带 action 字段
            $isActionReply = isset($data['action']);

            $fail = function ($msg) use ($h, $con) {
                $h->state['N']     = false;
                $h->state['N_msg'] = $msg;
                $con->close();
                $h->finish();
            };

            // 静默策略校验：只有「业务层回执」才违反 report 在 UDP 上的 none 声明，
            // 传输层 ack 恒定存在，不算违规。
            if ($seq === $c['report_seq']) {
                if ($isActionReply) {
                    $fail('report 在 UDP 上声明为静默，却收到业务层回执：' . substr((string)$raw, 0, 120));
                }
                return;
            }

            if ($seq === $c['seq1']) {
                if (!$isActionReply) {
                    return;   // 传输层 ack：收包成功，继续等待业务层回执
                }
                if ($nEcho1Acked) {
                    return;   // 忽略重传产生的重复业务回执
                }
                $nEcho1Acked = true;

                if ($packet['cmd'] !== Message::CMD_ACK
                    || (string)$data['action'] !== 'echo'
                    || (isset($data['channel']) ? (string)$data['channel'] : '') !== 'udp') {
                    $fail('UDP echo 业务回执异常（期望 ack 且 channel=udp）：' . $raw);
                    return;
                }
                echo "[N] <- echo#1 业务回执（UDP 动作回执通道打通，channel=udp）\n";

                // 连发 3 份上报以容忍 UDP 丢包，count 各计 1
                for ($i = 0; $i < 3; $i++) {
                    $con->send($h->encode($h->buildPacket(Message::CMD_DATA, $c['report_seq'], array(
                        'uid'       => $c['uid'],
                        'device_id' => $c['device_id'],
                        'token'     => $c['token'],
                        'data'      => array('action' => 'report', 'params' => array('topic' => $c['topic'], 'count' => 1)),
                    ))));
                }
                echo "[N] -> data/action=report ×3（UDP 声明静默，期望无任何回执）\n";

                // 延迟发 echo#2：若 report 违规回执，必然先于 echo#2 的 ack 到达
                Timer::add(0.8, function () use ($h, $con, $c) {
                    $con->send($h->encode($h->buildPacket(Message::CMD_DATA, $c['seq2'], array(
                        'uid'       => $c['uid'],
                        'device_id' => $c['device_id'],
                        'token'     => $c['token'],
                        'data'      => array('action' => 'echo', 'params' => array('phase' => 2)),
                    ))));
                    echo "[N] -> data/action=echo#2（此刻前若收到 report 回执即为失败）\n";
                }, array(), false);
                return;
            }

            if ($seq === $c['seq2']) {
                if (!$isActionReply) {
                    return;   // 传输层 ack，继续等待业务层回执
                }
                if ($packet['cmd'] !== Message::CMD_ACK) {
                    $fail('echo#2 未返回业务回执：' . $raw);
                    return;
                }
                echo "[N] <- echo#2 业务回执（确认期间未收到 report 业务回执）\n";

                // 静默不等于不处理：核对上报计数确实已写入 Redis
                $check = null;
                $check = function ($attempt) use (&$check, $h, $con, $c) {
                    $key = RedisClient::key('action:report:' . $c['topic']);
                    RedisClient::connection()->hGet($key, 'count', function ($count) use (&$check, $h, $con, $c, $attempt) {
                        $ok = is_numeric($count) && (int)$count >= 1;

                        if ($ok || $attempt >= 3) {
                            $h->state['N'] = $ok;
                            if (!$ok) {
                                $h->state['N_msg'] = sprintf(
                                    'report 静默执行但计数未写入（topic=%s，实际 %s）',
                                    $c['topic'],
                                    var_export($count, true)
                                );
                            }
                            echo $ok
                                ? "[N] 静默上报计数已落库（count={$count}）\n"
                                : "[N] 静默上报计数未落库\n";
                            $con->close();
                            $h->finish();
                            return;
                        }

                        Timer::add(0.4, function () use (&$check, $attempt) {
                            $check($attempt + 1);
                        }, array(), false);
                    });
                };
                $check(1);
                return;
            }
        };

        $udpN->connect();
    }
}
