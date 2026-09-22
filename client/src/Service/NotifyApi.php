<?php
/**
 * notify 动作 —— 请求服务端向本人推送一条消息（验证推送闭环）
 *
 * 目标恒为调用方自身 uid（服务端不接受任意 uid 入参）。
 * 推送闭环的另一端是 Event\PushReceiver：notify 成功后会收到 cmd=push 下行。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Service;

/**
 * notify 动作：请求服务端向本人推送（验证推送闭环）
 */
final class NotifyApi extends AbstractApi
{
    /**
     * @param mixed         $value       推送载荷（JSON 类型，可空）
     * @param string        $msgId       消息标识（<=64 字符；相同 msg_id 600s 内去重，留空由服务端生成）
     * @param string        $offlineMode 离线处置：''=默认 / 'drop'=丢弃 / 'queue'=入队补投
     * @param callable|null $cb          function (bool $ok, array $data, ?array $error): void
     * @return string 本请求 seq
     */
    public function notify($value = null, $msgId = '', $offlineMode = '', $cb = null)
    {
        return $this->call('notify', array(
            'value'        => $value,
            'msg_id'       => (string)$msgId,
            'offline_mode' => (string)$offlineMode,
        ), $cb);
    }
}
