<?php
/**
 * session 动作 —— 查询当前连接的会话摘要
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Service;

/**
 * session 动作：查询当前连接的会话摘要
 */
final class SessionApi extends AbstractApi
{
    /**
     * 回执 data 含 clientId / uid / device_id / 在线状态与会话剩余 TTL 等
     * （以服务端 SessionAction 实际返回为准）。
     *
     * @param null|callable $cb function (bool $ok, array $data, ?array $error): void
     *
     * @return string 本请求 seq
     */
    public function get($cb = null)
    {
        return $this->call('session', [], $cb);
    }
}
