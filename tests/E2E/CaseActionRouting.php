<?php
/**
 * 用例 J / M：动作分发与契约族
 *
 *   [J] 指令路由表：data.action（echo / session）分发与 4006 / 4007 错误分支
 *   [M] 业务动作契约：参数校验白名单 / 4006 未知动作 / 4007 参数错误
 *
 * J 验证「按 data.action 查表分发」这一路由骨架，
 * M 在此基础上验证声明式动作清单（config/actions.php）的完整执行语义，
 * 并在末步回查 Redis，确认回执与落库一致。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Tests\E2E;

use GatewayPush\Business\Message;
use GatewayPush\Common\RedisClient;
use Workerman\Connection\AsyncTcpConnection;

final class CaseActionRouting
{
    /**
     * 用例 J：指令路由表（data.action 分发）
     *
     * 验证 handleData 已由「空壳回显」改为按 data.action 查表分发：
     *   1) action=echo     -> ack，回显 params
     *   2) action=session  -> ack，返回本连接会话摘要
     *   3) 未注册 action    -> 4006 未知指令
     *   4) 缺 action        -> 4007 缺少参数
     *
     * @param Harness $h
     * @return void
     */
    public static function routeTable(Harness $h)
    {
        $c     = $h->ctx('J');
        $connJ = new AsyncTcpConnection($h->wsAddress);
        $jStep = 0;

        $connJ->onConnect = function ($con) use ($h, $c) {
            echo "[J] WebSocket 已连接\n";
            $con->send($h->encode($h->buildPacket(Message::CMD_AUTH, 'j-auth-1', array(
                'uid'       => $c['uid'],
                'device_id' => $c['device_id'],
                'token'     => $c['token'],
            ))));
            echo "[J] -> auth\n";
        };

        $connJ->onMessage = function ($con, $raw) use ($h, $c, &$jStep) {
            $packet = json_decode($raw, true);
            if (!is_array($packet) || !isset($packet['cmd'])) {
                return;
            }

            $fail = function ($msg) use ($h, $con) {
                $h->state['J']     = false;
                $h->state['J_msg'] = $msg;
                $con->close();
                $h->finish();
            };

            switch ($jStep) {
                case 0:   // 等待鉴权 ack
                    if ($packet['cmd'] !== Message::CMD_ACK) {
                        $fail('鉴权阶段返回 ' . $packet['cmd']);
                        return;
                    }
                    $jStep = 1;
                    $con->send($h->encode($h->buildPacket(Message::CMD_DATA, 'j-echo-1', array(
                        'uid'       => $c['uid'],
                        'device_id' => $c['device_id'],
                        'data'      => array('action' => 'echo', 'params' => array('k' => 'v', 'n' => 1)),
                    ))));
                    echo "[J] -> data / action=echo\n";
                    return;

                case 1:   // 期望 echo 回显
                    $action = isset($packet['data']['action']) ? (string)$packet['data']['action'] : '';
                    $params = isset($packet['data']['params']) ? $packet['data']['params'] : [];
                    if ($packet['cmd'] !== Message::CMD_ACK
                        || $action !== 'echo'
                        || !is_array($params)
                        || !isset($params['k']) || (string)$params['k'] !== 'v') {
                        $fail('echo 回显异常：' . $raw);
                        return;
                    }
                    $jStep = 2;
                    $con->send($h->encode($h->buildPacket(Message::CMD_DATA, 'j-session-1', array(
                        'uid'       => $c['uid'],
                        'device_id' => $c['device_id'],
                        'data'      => array('action' => 'session'),
                    ))));
                    echo "[J] -> data / action=session\n";
                    return;

                case 2:   // 期望会话摘要
                    $action = isset($packet['data']['action']) ? (string)$packet['data']['action'] : '';
                    $uidGot = isset($packet['data']['uid']) ? (string)$packet['data']['uid'] : '';
                    $proto  = isset($packet['data']['protocol']) ? (string)$packet['data']['protocol'] : '';
                    if ($packet['cmd'] !== Message::CMD_ACK || $action !== 'session'
                        || $uidGot !== $c['uid'] || $proto !== 'ws') {
                        $fail(sprintf('session 摘要异常：action=%s uid=%s protocol=%s', $action, $uidGot, $proto));
                        return;
                    }
                    $jStep = 3;
                    $con->send($h->encode($h->buildPacket(Message::CMD_DATA, 'j-unknown-1', array(
                        'uid'       => $c['uid'],
                        'device_id' => $c['device_id'],
                        'data'      => array('action' => 'no_such_action'),
                    ))));
                    echo "[J] -> data / action=no_such_action（期望 4006）\n";
                    return;

                case 3:   // 期望未注册 action -> 4006
                    $code = isset($packet['data']['code']) ? (int)$packet['data']['code'] : 0;
                    if ($packet['cmd'] !== Message::CMD_ERROR || $code !== Message::CODE_UNKNOWN_CMD) {
                        $fail(sprintf('未注册 action 未被拒绝（cmd=%s code=%d，期望 error/4006）', $packet['cmd'], $code));
                        return;
                    }
                    $jStep = 4;
                    $con->send($h->encode($h->buildPacket(Message::CMD_DATA, 'j-noaction-1', array(
                        'uid'       => $c['uid'],
                        'device_id' => $c['device_id'],
                        'data'      => array('params' => array('x' => 1)),
                    ))));
                    echo "[J] -> data / 缺 action（期望 4007）\n";
                    return;

                case 4:   // 期望缺 action -> 4007
                    $code = isset($packet['data']['code']) ? (int)$packet['data']['code'] : 0;
                    if ($packet['cmd'] !== Message::CMD_ERROR || $code !== Message::CODE_PARAM_MISSING) {
                        $fail(sprintf('缺 action 未返回 4007（cmd=%s code=%d）', $packet['cmd'], $code));
                        return;
                    }
                    echo "[J] <- 4006 / 4007 分支均按预期返回\n";
                    $h->state['J'] = true;
                    $con->close();
                    $h->finish();
                    return;
            }
        };

        $connJ->onClose = function () use ($h) {
            if ($h->state['J'] === 'pending') {
                $h->state['J']     = false;
                $h->state['J_msg'] = '流程完成前连接被关闭';
                $h->finish();
            }
        };

        $connJ->connect();
    }

    /**
     * 用例 M：业务动作契约
     *
     * 验证声明式动作清单（config/actions.php）的执行语义：
     *   1) echo  params='*' 透传            -> ack，回显 params 且带 channel 字段
     *   2) report 合法入参                  -> ack，返回 accepted / total
     *   3) report 缺 topic（required）      -> 4007
     *   4) report topic 含非法字符          -> 4007
     *   5) action 未注册                    -> 4006
     *   6) data 缺 action                   -> 4007
     * 末步回查 Redis，确认 report 确实已写入（回执与落库一致）
     *
     * @param Harness $h
     * @return void
     */
    public static function actionContract(Harness $h)
    {
        $c     = $h->ctx('M');
        $connM = new AsyncTcpConnection($h->wsAddress);
        $mStep = 0;

        $connM->onConnect = function ($con) use ($h, $c) {
            echo "[M] WebSocket 已连接\n";
            $con->send($h->encode($h->buildPacket(Message::CMD_AUTH, 'm-auth-1', array(
                'uid'       => $c['uid'],
                'device_id' => $c['device_id'],
                'token'     => $c['token'],
            ))));
            echo "[M] -> auth\n";
        };

        $connM->onMessage = function ($con, $raw) use ($h, $c, &$mStep) {
            $packet = json_decode($raw, true);
            if (!is_array($packet) || !isset($packet['cmd'])) {
                return;
            }

            $data = isset($packet['data']) && is_array($packet['data']) ? $packet['data'] : [];
            $code = isset($data['code']) ? (int)$data['code'] : -1;
            $act  = isset($data['action']) ? (string)$data['action'] : '';

            $fail = function ($msg) use ($h, $con) {
                $h->state['M']     = false;
                $h->state['M_msg'] = $msg;
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

            switch ($mStep) {
                case 0:
                    if ($packet['cmd'] !== Message::CMD_ACK) {
                        $fail('鉴权阶段返回 ' . $packet['cmd']);
                        return;
                    }
                    $mStep = 1;
                    $send('m-echo-1', array(
                        'action' => 'echo',
                        'params' => array('k' => 'v', 'n' => 1, 'deep' => array('a' => 1)),
                    ));
                    echo "[M] -> data/action=echo（params 透传）\n";
                    return;

                case 1:
                    if ($packet['cmd'] !== Message::CMD_ACK
                        || $act !== 'echo'
                        || (isset($data['channel']) ? (string)$data['channel'] : '') !== 'ws'
                        || !isset($data['params']['k']) || (string)$data['params']['k'] !== 'v'
                        || !isset($data['params']['deep']['a'])) {
                        $fail('echo 回显异常（期望透传含 channel=ws）：' . $raw);
                        return;
                    }
                    $mStep = 2;
                    $send('m-report-1', array(
                        'action' => 'report',
                        'params' => array('topic' => $c['topic'], 'count' => 3),
                    ));
                    echo "[M] -> data/action=report（合法入参）\n";
                    return;

                case 2:
                    if ($packet['cmd'] !== Message::CMD_ACK || $act !== 'report'
                        || (int)(isset($data['accepted']) ? $data['accepted'] : 0) !== 3) {
                        $fail('report 回执异常：' . $raw);
                        return;
                    }
                    $mStep = 3;
                    $send('m-report-bad-1', array('action' => 'report', 'params' => array('count' => 1)));
                    echo "[M] -> data/action=report（缺 topic，期望 4007）\n";
                    return;

                case 3:
                    if ($packet['cmd'] !== Message::CMD_ERROR || $code !== Message::CODE_PARAM_MISSING) {
                        $fail(sprintf('缺 required 未返回 4007：cmd=%s code=%d', $packet['cmd'], $code));
                        return;
                    }
                    $mStep = 4;
                    $send('m-report-bad-2', array(
                        'action' => 'report',
                        'params' => array('topic' => 'bad topic!'),
                    ));
                    echo "[M] -> data/action=report（topic 非法字符，期望 4007）\n";
                    return;

                case 4:
                    if ($packet['cmd'] !== Message::CMD_ERROR || $code !== Message::CODE_PARAM_MISSING) {
                        $fail(sprintf('非法 topic 未被拦截：cmd=%s code=%d', $packet['cmd'], $code));
                        return;
                    }
                    $mStep = 5;
                    $send('m-unknown-1', array('action' => 'no_such_action'));
                    echo "[M] -> data/action=no_such_action（期望 4006）\n";
                    return;

                case 5:
                    if ($packet['cmd'] !== Message::CMD_ERROR || $code !== Message::CODE_UNKNOWN_CMD) {
                        $fail(sprintf('未知动作未返回 4006：cmd=%s code=%d', $packet['cmd'], $code));
                        return;
                    }
                    $mStep = 6;
                    $send('m-noaction-1', array());
                    echo "[M] -> data（缺 action，期望 4007）\n";
                    return;

                case 6:
                    if ($packet['cmd'] !== Message::CMD_ERROR || $code !== Message::CODE_PARAM_MISSING) {
                        $fail(sprintf('缺 action 未返回 4007：cmd=%s code=%d', $packet['cmd'], $code));
                        return;
                    }

                    // 回执与落库一致性：report 的回执称已受理 3 条，Redis 计数须相符
                    $key = RedisClient::key('action:report:' . $c['topic']);
                    RedisClient::connection()->hGet($key, 'count', function ($count) use ($h, $con, $c) {
                        $ok = is_numeric($count) && (int)$count >= 3;
                        $h->state['M'] = $ok;
                        if (!$ok) {
                            $h->state['M_msg'] = sprintf(
                                'report 回执成功但 Redis 计数不符（topic=%s，实际 %s）',
                                $c['topic'],
                                var_export($count, true)
                            );
                        }
                        echo $ok
                            ? "[M] 上报计数已落库（count={$count}）\n"
                            : "[M] 上报计数未落库\n";
                        $con->close();
                        $h->finish();
                    });
                    return;
            }
        };

        $connM->connect();
    }
}
