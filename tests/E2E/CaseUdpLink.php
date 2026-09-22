<?php
/**
 * 用例 C / D：UDP 链路族
 *
 *   [C] UDP 正常链路：合法签名报文 -> 收到 ack 回执
 *   [D] UDP 签名拦截：篡改签名报文 -> 返回 4001
 *
 * 两者共用基准身份。UDP 首个报文可能因 socket 未就绪而静默丢失，
 * 故均通过 Harness::udpSendUntilAck() 走「延迟首包 + 应用层重传」。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Tests\E2E;

use GatewayPush\Business\Message;
use Workerman\Connection\AsyncUdpConnection;

final class CaseUdpLink
{
    /**
     * 用例 C：UDP 正常链路
     *
     * @param Harness $h
     *
     * @return void
     */
    public static function normalLink(Harness $h)
    {
        $c = $h->ctx('C');

        $udpC = new AsyncUdpConnection($h->udpAddress);

        $udpC->onConnect = function ($con) use ($h, $c) {
            echo "[C] UDP 通道已就绪\n";
            $payload = $h->encode($h->buildPacket(Message::CMD_DATA, 'c-udp-1', [
                'uid'       => $c['uid'],
                'device_id' => $c['device_id'],
                'token'     => $c['token'],
                'data'      => ['metric' => 42],
            ]));
            $h->udpSendUntilAck($con, $payload, 'C');
        };

        $udpC->onMessage = function ($con, $raw) use ($h) {
            echo "[C] <- {$raw}\n";
            $packet        = json_decode($raw, true);
            $h->state['C'] = false;
            if (is_array($packet) && isset($packet['cmd'])) {
                if ($packet['cmd'] === Message::CMD_ACK) {
                    $h->state['C'] = true;
                } else {
                    $h->state['C_msg'] = isset($packet['data']['msg'])
                        ? '期望 ack，实际返回 ' . $packet['cmd'] . '：' . $packet['data']['msg']
                        : '期望 ack，实际返回 ' . $packet['cmd'];
                }
            } else {
                $h->state['C_msg'] = '响应不是合法 JSON 报文';
            }
            $con->close();
            $h->finish();
        };

        $udpC->connect();
    }

    /**
     * 用例 D：UDP 签名拦截
     *
     * @param Harness $h
     *
     * @return void
     */
    public static function badSign(Harness $h)
    {
        $c = $h->ctx('D');

        $udpD = new AsyncUdpConnection($h->udpAddress);

        $udpD->onConnect = function ($con) use ($h, $c) {
            echo "[D] UDP 通道已就绪\n";
            $packet = $h->buildPacket(Message::CMD_DATA, 'd-udp-1', [
                'uid'       => $c['uid'],
                'device_id' => $c['device_id'],
                'data'      => ['tampered' => 1],
            ]);
            // 篡改签名，模拟伪造报文
            $packet['sign'] = str_repeat('0', 64);
            $h->udpSendUntilAck($con, $h->encode($packet), 'D');
        };

        $udpD->onMessage = function ($con, $raw) use ($h) {
            echo "[D] <- {$raw}\n";
            $packet        = json_decode($raw, true);
            $h->state['D'] = false;
            if (is_array($packet) && isset($packet['cmd']) && $packet['cmd'] === Message::CMD_ERROR) {
                $code = isset($packet['data']['code']) ? (int)$packet['data']['code'] : 0;
                if ($code === Message::CODE_BAD_SIGN) {
                    $h->state['D'] = true;
                } else {
                    $h->state['D_msg'] = "返回错误码 {$code}，期望 " . Message::CODE_BAD_SIGN;
                }
            } else {
                $h->state['D_msg'] = '未收到签名校验失败的错误报文';
            }
            $con->close();
            $h->finish();
        };

        $udpD->connect();
    }
}
