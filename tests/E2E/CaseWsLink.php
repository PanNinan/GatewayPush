<?php
/**
 * 用例 A / B：WebSocket 链路族
 *
 *   [A] WebSocket 正常鉴权链路：连接 -> auth 鉴权 -> ack -> ping -> pong
 *   [B] WebSocket 越权拦截：未鉴权直接发送业务指令 -> 返回 4003 并断开
 *
 * 两者共用基准身份（CLI 传入的 uid / device_id）。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Tests\E2E;

use GatewayPush\Business\Message;
use Workerman\Connection\AsyncTcpConnection;

final class CaseWsLink
{
    /**
     * 用例 A：WebSocket 正常鉴权链路
     *
     * @param Harness $h
     * @return void
     */
    public static function authLink(Harness $h)
    {
        $c = $h->ctx('A');

        $connA = new AsyncTcpConnection($h->wsAddress);

        $connA->onConnect = function ($con) use ($h, $c) {
            echo "[A] WebSocket 已连接\n";
            $con->send($h->encode($h->buildPacket(Message::CMD_AUTH, 'a-auth-1', array(
                'uid'       => $c['uid'],
                'device_id' => $c['device_id'],
                'token'     => $c['token'],
                'data'      => array('client' => 'e2e-check'),
            ))));
            echo "[A] -> auth（含签名）\n";
        };

        $connA->onMessage = function ($con, $raw) use ($h) {
            echo "[A] <- {$raw}\n";
            $packet = json_decode($raw, true);
            if (!is_array($packet) || !isset($packet['cmd'])) {
                return;
            }
            if ($packet['cmd'] === Message::CMD_ACK) {
                $con->send($h->encode($h->buildPacket(Message::CMD_PING, 'a-ping-1', array())));
                echo "[A] -> ping\n";
                return;
            }
            if ($packet['cmd'] === Message::CMD_PONG) {
                $h->state['A'] = true;
                $con->close();
                $h->finish();
                return;
            }
            if ($packet['cmd'] === Message::CMD_ERROR) {
                $h->state['A']     = false;
                $h->state['A_msg'] = isset($packet['data']['msg']) ? (string)$packet['data']['msg'] : '未知错误';
                $con->close();
                $h->finish();
            }
        };

        $connA->onError = function ($con, $code, $msg) use ($h) {
            $h->state['A']     = false;
            $h->state['A_msg'] = "连接错误 {$code}: {$msg}";
            $h->finish();
        };

        $connA->onClose = function () use ($h) {
            if ($h->state['A'] === 'pending') {
                $h->state['A']     = false;
                $h->state['A_msg'] = '流程完成前连接被关闭';
                $h->finish();
            }
        };

        $connA->connect();
    }

    /**
     * 用例 B：WebSocket 越权拦截
     *
     * @param Harness $h
     * @return void
     */
    public static function unauthorizedProbe(Harness $h)
    {
        $connB = new AsyncTcpConnection($h->wsAddress);

        $connB->onConnect = function ($con) use ($h) {
            echo "[B] WebSocket 已连接（不发送 auth）\n";
            $con->send($h->encode($h->buildPacket(Message::CMD_DATA, 'b-data-1', array(
                'uid'       => 'probe-uid',
                'device_id' => 'probe-device',
                'data'      => array('probe' => 1),
            ))));
            echo "[B] -> data（未鉴权越权探测）\n";
        };

        $connB->onMessage = function ($con, $raw) use ($h) {
            echo "[B] <- {$raw}\n";
            $packet = json_decode($raw, true);
            if (is_array($packet) && isset($packet['cmd']) && $packet['cmd'] === Message::CMD_ERROR) {
                $code = isset($packet['data']['code']) ? (int)$packet['data']['code'] : 0;
                if ($code === Message::CODE_UNAUTHORIZED) {
                    $h->state['B'] = true;
                } else {
                    $h->state['B']     = false;
                    $h->state['B_msg'] = "返回错误码 {$code}，期望 " . Message::CODE_UNAUTHORIZED;
                }
            }
        };

        $connB->onClose = function () use ($h) {
            if ($h->state['B'] === 'pending') {
                $h->state['B']     = false;
                $h->state['B_msg'] = '连接已关闭但未收到越权拦截提示';
            }
            $h->finish();
        };

        $connB->connect();
    }
}
