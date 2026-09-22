<?php
/**
 * 业务动作 API 基类 —— 统一包装 SessionManager::request() 的结算回调
 *
 * 回调签名（设计稿 §5.4）：function (bool $ok, array $data, ?array $error): void
 *
 *   $ok = true   $data = 服务端回执业务载荷（ack 报文的 data），$error = null
 *   $ok = false  $data / $error 均含错误信息：
 *                  服务端 error 报文 → code/msg 取自报文（4000~5000）
 *                  本地超时           → code = CLIENT_TIMEOUT (10001)
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Client\Service;

use GatewayPush\Client\Error\ErrorCode;
use GatewayPush\Client\Session\SessionManager;

/**
 * 业务动作 API 基类
 *
 * 统一包装 SessionManager::request() 的结算回调；子类只声明动作名与参数。
 */
abstract class AbstractApi
{
    /**
     * @var SessionManager
     */
    protected $session;

    /**
     * @param SessionManager $session 须已处于 ready 状态才能发请求
     */
    public function __construct(SessionManager $session)
    {
        $this->session = $session;
    }

    /**
     * 发送动作请求并包装回调
     *
     * @param string               $action  动作名（须在服务端 config/actions.php 登记）
     * @param array<string, mixed> $params  动作参数
     * @param null|callable        $cb      function (bool $ok, array $data, ?array $error): void
     * @param null|float           $timeout 覆盖全局超时
     *
     * @return string 本请求 seq
     */
    protected function call($action, array $params, $cb = null, $timeout = null)
    {
        return $this->session->request($action, $params, function ($ok, $packet) use ($cb) {
            if ($cb === null) {
                return;
            }

            $data = isset($packet['data']) && is_array($packet['data']) ? $packet['data'] : [];

            if ($ok) {
                $cb(true, $data, null);

                return;
            }

            // 失败：服务端 error 报文携带 code/msg；本地超时 packet 为空
            $code = isset($data['code']) ? (int)$data['code'] : ErrorCode::CLIENT_TIMEOUT;
            $msg  = isset($data['msg']) && (string)$data['msg'] !== ''
                ? (string)$data['msg']
                : ErrorCode::message($code);

            $cb(false, $data, ['code' => $code, 'msg' => $msg]);
        }, $timeout);
    }
}
