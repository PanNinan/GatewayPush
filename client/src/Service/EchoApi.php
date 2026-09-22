<?php
/**
 * echo 动作 —— 原样回显，联通性验证与压测
 *
 * 服务端 params='*' 原样透传；回执 data = {action:'echo', channel, protocol, params, at}。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Service;

/**
 * echo 动作：原样回显，联通性验证与压测
 */
final class EchoApi extends AbstractApi
{
    /**
     * @param array<string, mixed> $params 任意可 JSON 化载荷（服务端原样回显）
     * @param null|callable        $cb     function (bool $ok, array $data, ?array $error): void
     *
     * @return string 本请求 seq
     */
    public function send(array $params, $cb = null)
    {
        return $this->call('echo', $params, $cb);
    }
}
