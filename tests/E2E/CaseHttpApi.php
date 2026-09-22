<?php
/**
 * 用例 H：HTTP 接口
 *
 *   健康探测 / 验签通过 / 验签拒绝
 *
 * 该用例在事件循环启动前**同步**执行（Harness::httpRequest 为阻塞实现），
 * 因此不参与超时保护；若 api 角色未启动，会在结果汇总中标记为 SKIP。
 *
 * 服务端处于**免签模式**（API_SIGN_ENABLE=false 且监听回环地址）时，
 * 「伪造签名被拒」这一断言不成立，用例会先探测模式并跳过它。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Tests\E2E;

final class CaseHttpApi
{
    /**
     * @param Harness $h
     *
     * @return void
     */
    public static function run(Harness $h)
    {
        $hErrors = [];

        $health = Harness::httpRequest('GET', $h->apiAddress . '/health');
        if (!$health['ok']) {
            $h->state['H']     = false;
            $h->state['H_msg'] = '接口不可达（api 角色未启动？）：' . $health['error'];
            $hErrors[]         = $h->state['H_msg'];
        } elseif ($health['status'] !== 200 || !is_array($health['json']) || (int)$health['json']['code'] !== 0) {
            $hErrors[] = "健康探测异常：HTTP {$health['status']}，响应 {$health['body']}";
        }

        if (!$hErrors) {
            // 验签模式探测：不带签名请求 /stats
            //   验签开启 → 401 / 4001；免签模式（API_SIGN_ENABLE=false + 回环监听）→ 200
            // 免签下「伪造签名被拒」不成立，须跳过而非判失败 —— 否则本地调试环境
            // 会持续报用例 H 失败，把真正的回归淹没在噪声里。
            $probe    = Harness::httpRequest('GET', $h->apiAddress . '/stats');
            $freeMode = $probe['ok'] && $probe['status'] === 200;
            if ($freeMode) {
                echo "      API_SIGN_ENABLE=false 且监听回环：免签模式，跳过「伪造签名被拒」断言\n";
            }

            $pushBody = json_encode([
                'target_type' => 'uid',
                'target'      => $h->ctx('H')['uid'],
                'payload'     => ['from' => 'http-e2e'],
                'msg_id'      => 'e2e-http-' . bin2hex(random_bytes(4)),
            ], JSON_UNESCAPED_UNICODE);

            $timestamp = time();
            $goodSign  = hash_hmac('sha256', $timestamp . '|' . $pushBody, $h->secret);

            $accepted = Harness::httpRequest('POST', $h->apiAddress . '/push', [
                'Content-Type' => 'application/json',
                'X-Timestamp'  => $timestamp,
                'X-Sign'       => $goodSign,
            ], $pushBody);

            if (!$accepted['ok'] || $accepted['status'] !== 200
                || !is_array($accepted['json']) || (int)$accepted['json']['code'] !== 0) {
                $hErrors[] = sprintf('合法签名请求被拒绝：HTTP %d，响应 %s', $accepted['status'], $accepted['body']);
            }

            if (!$freeMode) {
                $denied = Harness::httpRequest('POST', $h->apiAddress . '/push', [
                    'Content-Type' => 'application/json',
                    'X-Timestamp'  => $timestamp,
                    'X-Sign'       => str_repeat('0', 64),
                ], $pushBody);

                $deniedCode = is_array($denied['json']) && isset($denied['json']['code']) ? (int)$denied['json']['code'] : 0;
                if ($denied['status'] !== 401 || $deniedCode !== 4001) {
                    $hErrors[] = sprintf('伪造签名未被拒绝：HTTP %d，业务码 %d（期望 401 / 4001）', $denied['status'], $deniedCode);
                }
            }
        }

        if ($hErrors) {
            $h->state['H']     = false;
            $h->state['H_msg'] = implode('；', $hErrors);
        } else {
            $h->state['H'] = true;
        }

        echo '[H] HTTP 接口用例：' . ($h->state['H'] === true ? "通过\n" : "失败 - {$h->state['H_msg']}\n");
    }
}
