<?php
/**
 * 用例 P：HTTP 动作调用
 *
 *   验的是「HTTP 驱动 BusinessWorker 执行业务动作」这条新链路的完整闭环：
 *
 *     POST /action
 *       -> 验签 + 白名单校验（api 进程）
 *       -> RPUSH queue:action:in
 *       -> action-queue-consume 定时任务取批（business 进程）
 *       -> ActionRunner::run(channel=http)
 *       -> ActionReply::store -> action:result:{request_id}
 *       -> api 轮询取回 -> HTTP 响应
 *
 * 校验点（覆盖正常路径 + 三类失败路径）：
 *   1. echo   -> 200 / code 0，params 原样回显，且 channel 回报为 http
 *   2. report -> 两次调用同一主题，total 递增 —— 证明动作**真的在业务进程执行**
 *                且状态落到了 Redis（而非 api 进程返回了一个自造的假回执）
 *   3. session -> 400 / 4006（未开放 HTTP 通道）
 *   4. 未知动作 -> 400 / 4006
 *   5. report 缺 topic -> **200 / 4007** —— 动作级错误经回程桥透出，
 *                 即「HTTP 传输成功、业务失败」的分层语义
 *   6. GET /action/{未知 id} -> 404
 *
 * 该用例与用例 H 一样在事件循环启动前**同步**执行（Harness::httpRequest 为
 * 阻塞实现），因此不参与超时保护。前置条件：api 与 business 两个角色都在运行。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Tests\E2E;

final class CaseHttpAction
{
    /**
     * 单次 HTTP 请求超时（秒）
     *
     * POST /action 为同步等待语义，服务端最长等待 API_ACTION_WAIT_MS（默认 6s），
     * 客户端超时必须留出余量，否则会把「服务端仍在正常等待」误判为请求失败。
     */
    const TIMEOUT = 20;

    /**
     * @param Harness $h
     * @return void
     */
    public static function run(Harness $h)
    {
        $errors = [];
        $ctx    = $h->ctx('P');

        self::checkEcho($h, $ctx, $errors);
        self::checkReportPersisted($h, $ctx, $errors);
        self::checkRejections($h, $ctx, $errors);
        self::checkMissingResult($h, $errors);

        if ($errors) {
            $h->state['P']     = false;
            $h->state['P_msg'] = implode('；', $errors);
        } else {
            $h->state['P'] = true;
        }

        echo '[P] HTTP 动作调用用例：' . ($h->state['P'] === true ? "通过\n" : "失败 - {$h->state['P_msg']}\n");
    }

    /* ---------------------------------------------------------------------
     | 校验项
     | --------------------------------------------------------------------- */

    /**
     * echo：正常路径 + params 回显 + 通道回报
     *
     * @param Harness $h
     * @param array   $ctx
     * @param array   $errors
     * @return void
     */
    private static function checkEcho(Harness $h, array $ctx, array &$errors)
    {
        $params = array('probe' => 'http-action-e2e', 'n' => 42);

        $res = self::callAction($h, array(
            'action' => 'echo',
            'uid'    => $ctx['uid'],
            'params' => $params,
        ));

        if (!$res['ok']) {
            $errors[] = 'POST /action 不可达（api 角色未启动？）：' . $res['error'];
            return;
        }

        if ($res['status'] !== 200 || (int)$res['json']['code'] !== 0) {
            $errors[] = sprintf('echo 未被正常受理：HTTP %d，响应 %s', $res['status'], $res['body']);
            return;
        }

        $data   = isset($res['json']['data']) ? $res['json']['data'] : [];
        $result = isset($data['result']) && is_array($data['result']) ? $data['result'] : [];

        if (!isset($data['status']) || $data['status'] !== 'done') {
            $errors[] = 'echo 响应缺少 status=done';
        }
        if (empty($data['request_id'])) {
            $errors[] = 'echo 响应缺少 request_id';
        }
        if (!isset($result['params']) || $result['params'] !== $params) {
            $errors[] = 'echo 未原样回显 params：' . json_encode(isset($result['params']) ? $result['params'] : null);
        }
        // 通道必须回报为 http —— 这一项直接验证 ActionRunner 的前缀表判定正确
        if (!isset($result['channel']) || $result['channel'] !== 'http') {
            $errors[] = 'echo 回报的 channel 不是 http：' . (isset($result['channel']) ? $result['channel'] : '(缺失)');
        }
    }

    /**
     * report：连调两次，total 必须递增
     *
     * 这是本用例最关键的一项 —— 它同时证明了三件事：
     *   1. 动作真的在业务进程里跑（api 无法自造一个带累加语义的回执）；
     *   2. 累加状态落到了 Redis（两次请求之间进程可能不同）；
     *   3. 回程桥把**业务数据体**原样带回来了，没有在传递中丢字段。
     *
     * @param Harness $h
     * @param array   $ctx
     * @param array   $errors
     * @return void
     */
    private static function checkReportPersisted(Harness $h, array $ctx, array &$errors)
    {
        $payload = array(
            'action' => 'report',
            'uid'    => $ctx['uid'],
            'params' => array('topic' => $ctx['topic'], 'count' => 1),
        );

        $first = self::callAction($h, $payload);
        if (!$first['ok'] || $first['status'] !== 200 || (int)$first['json']['code'] !== 0) {
            $errors[] = sprintf('report 首次调用失败：HTTP %d，响应 %s', $first['status'], $first['body']);
            return;
        }

        $second = self::callAction($h, $payload);
        if (!$second['ok'] || $second['status'] !== 200 || (int)$second['json']['code'] !== 0) {
            $errors[] = sprintf('report 二次调用失败：HTTP %d，响应 %s', $second['status'], $second['body']);
            return;
        }

        $t1 = self::resultField($first, 'total');
        $t2 = self::resultField($second, 'total');

        if ($t1 === null || $t2 === null) {
            $errors[] = 'report 回执缺少 total 字段';
            return;
        }
        if ($t2 !== $t1 + 1) {
            $errors[] = sprintf('report 计数未按预期递增：首次 total=%s，二次 total=%s', var_export($t1, true), var_export($t2, true));
            return;
        }

        echo "     report 主题 {$ctx['topic']} 计数 {$t1} -> {$t2}（证明动作在业务进程执行且落库）\n";
    }

    /**
     * 三类拒绝路径：未开放通道 / 未知动作 / 参数不合规
     *
     * @param Harness $h
     * @param array   $ctx
     * @param array   $errors
     * @return void
     */
    private static function checkRejections(Harness $h, array $ctx, array &$errors)
    {
        // session 依赖 clientId 语义，声明中刻意未开放 HTTP
        $res = self::callAction($h, array('action' => 'session', 'uid' => $ctx['uid']));
        if ($res['status'] !== 400 || (int)$res['json']['code'] !== 4006) {
            $errors[] = sprintf(
                '未开放 HTTP 的动作未被拒绝：HTTP %d，业务码 %s（期望 400 / 4006）',
                $res['status'],
                isset($res['json']['code']) ? $res['json']['code'] : '(无)'
            );
        }

        $res = self::callAction($h, array('action' => 'no_such_action', 'uid' => $ctx['uid']));
        if ($res['status'] !== 400 || (int)$res['json']['code'] !== 4006) {
            $errors[] = sprintf(
                '未知动作未被拒绝：HTTP %d，业务码 %s（期望 400 / 4006）',
                $res['status'],
                isset($res['json']['code']) ? $res['json']['code'] : '(无)'
            );
        }

        // report 的 topic 为必填 —— 参数校验在业务进程执行，错误经回程桥透出
        $res = self::callAction($h, array('action' => 'report', 'uid' => $ctx['uid'], 'params' => array()));
        if ($res['status'] !== 200) {
            $errors[] = sprintf(
                '动作级参数错误应以 HTTP 200 + 业务码返回，实际 HTTP %d',
                $res['status']
            );
        } elseif ((int)$res['json']['code'] !== 4007) {
            $errors[] = sprintf(
                '缺少必填参数未被拒绝：业务码 %s（期望 4007）',
                isset($res['json']['code']) ? $res['json']['code'] : '(无)'
            );
        } elseif (!isset($res['json']['data']['status']) || $res['json']['data']['status'] !== 'failed') {
            $errors[] = '动作级错误响应的 data.status 不是 failed';
        }
    }

    /**
     * GET /action/{id} 对不存在的 request_id 应回 404
     *
     * @param Harness $h
     * @param array   $errors
     * @return void
     */
    private static function checkMissingResult(Harness $h, array &$errors)
    {
        // 合法格式但必然不存在（16 个 f 不会与 bin2hex(8) 的随机值撞车到需要担心）
        $missing = 'ffffffffffffffff';
        $ts      = time();

        $res = Harness::httpRequest(
            'GET',
            $h->apiAddress . '/action/' . $missing,
            array(
                'X-Timestamp' => $ts,
                'X-Sign'      => hash_hmac('sha256', $ts . '|', $h->secret),
            ),
            '',
            self::TIMEOUT
        );

        if ($res['status'] !== 404) {
            $errors[] = sprintf('补查不存在的动作结果应回 404，实际 HTTP %d', $res['status']);
        }
    }

    /* ---------------------------------------------------------------------
     | 辅助
     | --------------------------------------------------------------------- */

    /**
     * 调用 POST /action 并自动签名
     *
     * @param Harness $h
     * @param array   $body
     * @return array
     */
    private static function callAction(Harness $h, array $body)
    {
        $raw = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts  = time();

        return Harness::httpRequest(
            'POST',
            $h->apiAddress . '/action',
            array(
                'Content-Type' => 'application/json',
                'X-Timestamp'  => $ts,
                'X-Sign'       => hash_hmac('sha256', $ts . '|' . $raw, $h->secret),
            ),
            (string)$raw,
            self::TIMEOUT
        );
    }

    /**
     * 取出响应里 data.result 的某个字段（缺失返回 null）
     *
     * @param array  $res
     * @param string $key
     * @return mixed
     */
    private static function resultField(array $res, $key)
    {
        if (!isset($res['json']['data']['result']) || !is_array($res['json']['data']['result'])) {
            return null;
        }
        $result = $res['json']['data']['result'];

        return array_key_exists($key, $result) ? $result[$key] : null;
    }
}
