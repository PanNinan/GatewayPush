<?php
/**
 * 用例 O：订阅与广播闭环
 *
 * subscribe -> Push::enqueueTopic -> 收到 push -> topics 校验 -> unsubscribe
 *
 * 广播入口由测试进程侧直接调用，模拟外部系统按主题投递。
 * 使用独立主题（含随机后缀），避免跨轮次互相污染。
 *
 * 兼容 PHP 8.0 ~ 8.5
 */

namespace GatewayPush\Tests\E2E;

use GatewayPush\Business\Message;
use GatewayPush\Business\Push;
use Workerman\Connection\AsyncTcpConnection;

final class CaseSubscribe
{
    /**
     * @param Harness $h
     * @return void
     */
    public static function broadcastLoop(Harness $h)
    {
        $c     = $h->ctx('O');
        $connO = new AsyncTcpConnection($h->wsAddress);
        $oStep = 0;

        $connO->onConnect = function ($con) use ($h, $c) {
            echo "[O] WebSocket 已连接\n";
            $con->send($h->encode($h->buildPacket(Message::CMD_AUTH, 'o-auth-1', array(
                'uid'       => $c['uid'],
                'device_id' => $c['device_id'],
                'token'     => $c['token'],
            ))));
            echo "[O] -> auth\n";
        };

        $connO->onMessage = function ($con, $raw) use ($h, $c, &$oStep) {
            $packet = json_decode($raw, true);
            if (!is_array($packet) || !isset($packet['cmd'])) {
                return;
            }

            $data = isset($packet['data']) && is_array($packet['data']) ? $packet['data'] : array();
            $act  = isset($data['action']) ? (string)$data['action'] : '';

            $fail = function ($msg) use ($h, $con) {
                $h->state['O']     = false;
                $h->state['O_msg'] = $msg;
                $con->close();
                $h->finish();
            };
            $send = function ($seq, array $dataBody) use ($h, $con, $c) {
                $con->send($h->encode($h->buildPacket(Message::CMD_DATA, $seq, array(
                    'uid'       => $c['uid'],
                    'device_id' => $c['device_id'],
                    'data'      => $dataBody,
                ))));
            };

            switch ($oStep) {
                case 0:
                    if ($packet['cmd'] !== Message::CMD_ACK) {
                        $fail('鉴权阶段返回 ' . $packet['cmd']);
                        return;
                    }
                    $oStep = 1;
                    $send('o-sub-1', array('action' => 'subscribe', 'params' => array('topic' => $c['topic'])));
                    echo "[O] -> data/action=subscribe topic={$c['topic']}\n";
                    return;

                case 1:
                    if ($packet['cmd'] !== Message::CMD_ACK || $act !== 'subscribe'
                        || (int)(isset($data['subscribers']) ? $data['subscribers'] : 0) < 1) {
                        $fail('订阅回执异常：' . $raw);
                        return;
                    }
                    $oStep = 2;
                    echo "[O] <- 订阅成功（订阅者 {$data['subscribers']}），触发主题广播\n";

                    // 由测试进程侧调用广播入口，模拟外部系统按主题投递
                    Push::enqueueTopic($c['topic'], array('case' => 'O', 'hello' => 'world'), array('source' => 'e2e'));
                    return;

                case 2:
                    if ($packet['cmd'] !== Message::CMD_PUSH) {
                        $fail('未收到主题广播推送，实际 ' . $packet['cmd']);
                        return;
                    }
                    if (!isset($data['hello']) || (string)$data['hello'] !== 'world') {
                        $fail('广播报文内容不符：' . substr((string)$raw, 0, 120));
                        return;
                    }
                    $oStep = 3;
                    $send('o-topics-1', array('action' => 'topics'));
                    echo "[O] <- 收到主题广播；查询订阅列表\n";
                    return;

                case 3:
                    $topics = isset($data['topics']) && is_array($data['topics']) ? $data['topics'] : array();
                    if ($packet['cmd'] !== Message::CMD_ACK || $act !== 'topics'
                        || !in_array($c['topic'], $topics, true)) {
                        $fail('订阅列表未包含 ' . $c['topic'] . '：' . substr((string)$raw, 0, 120));
                        return;
                    }
                    $oStep = 4;
                    $send('o-unsub-1', array('action' => 'unsubscribe', 'params' => array('topic' => $c['topic'])));
                    echo "[O] -> data/action=unsubscribe\n";
                    return;

                case 4:
                    if ($packet['cmd'] !== Message::CMD_ACK || $act !== 'unsubscribe') {
                        $fail('取消订阅回执异常：' . $raw);
                        return;
                    }
                    $oStep = 5;
                    $send('o-topics-2', array('action' => 'topics'));
                    echo "[O] -> data/action=topics（校验已移除）\n";
                    return;

                case 5:
                    $topics = isset($data['topics']) && is_array($data['topics']) ? $data['topics'] : array();
                    if (in_array($c['topic'], $topics, true)) {
                        $fail('取消订阅后主题仍在列表中');
                        return;
                    }
                    $h->state['O'] = true;
                    echo "[O] 订阅 -> 广播 -> 取消 闭环完成\n";
                    $con->close();
                    $h->finish();
                    return;
            }
        };

        $connO->connect();
    }
}
