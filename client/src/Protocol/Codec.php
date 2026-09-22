<?php
/**
 * 报文编解码 —— 服务端 `GatewayPush\Business\Message` 的薄适配
 *
 * 刻意**不重写** JSON / 字段归一化逻辑，而是转发到服务端同一个类：
 * 编解码口径（`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`、字段归一化规则、
 * `data` 标量包装）天然与服务端逐字节一致，不会出现跨实现偏差。
 *
 * 报文八字段：
 *   {"cmd":"","seq":"","ts":0,"uid":"","device_id":"","token":"","sign":"","data":{}}
 *
 * `data` 业务信封（服务端约定，缺 action → 4007，未注册 → 4006）：
 *   {"cmd":"data","data":{"action":"<名>","params":{...}}}
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Protocol;

use GatewayPush\Business\Message;

/**
 * 报文编解码（服务端 Message 的薄适配）
 *
 * 直接转发到服务端同一个类，保证编解码口径逐字节一致，不重写 JSON 逻辑。
 */
final class Codec
{
    /**
     * 编码为 JSON 字符串
     *
     * @param mixed $packet
     *
     * @return string
     */
    public static function encode($packet)
    {
        return Message::encode($packet);
    }

    /**
     * 解码并做基础结构校验（不校验签名与时效，那是 `Signer::verify()` 的职责）
     *
     * @param mixed  $raw
     * @param string $error 输出错误原因
     *
     * @return null|array 校验失败返回 null
     */
    public static function decode($raw, &$error = null)
    {
        return Message::decode($raw, $error);
    }

    /**
     * 构造标准报文
     *
     * @param string $cmd
     * @param array  $data
     * @param array  $extra 附加/覆盖字段
     *
     * @return array
     */
    public static function packet($cmd, array $data = [], array $extra = [])
    {
        return Message::packet($cmd, $data, $extra);
    }

    /**
     * 构造回执报文（`seq` 原样回传）
     *
     * @param int|string $seq
     * @param array      $data
     *
     * @return array
     */
    public static function ack($seq = '', array $data = [])
    {
        return Message::ack($seq, $data);
    }

    /**
     * 构造错误报文
     *
     * @param int        $code
     * @param string     $msg  为空时取默认文案
     * @param int|string $seq
     * @param string     $ref  触发错误的来源指令
     *
     * @return array
     */
    public static function error($code, $msg = '', $seq = '', $ref = '')
    {
        return Message::error($code, $msg, $seq, $ref);
    }

    /* ---------------------------------------------------------------------
     | 业务数据信封
     --------------------------------------------------------------------- */

    /**
     * 构造业务指令报文
     *
     * @param string $action 动作名（须已在服务端 config/actions.php 登记）
     * @param array  $params 动作参数
     *
     * @return array
     */
    public static function dataPacket($action, array $params = [])
    {
        return Message::packet(Message::CMD_DATA, [
            'action' => (string)$action,
            'params' => $params,
        ]);
    }

    /**
     * 取出报文中承载的动作名（非 data 报文或缺失时返回空串）
     *
     * @param array $packet
     *
     * @return string
     */
    public static function actionOf(array $packet)
    {
        if (!isset($packet['data']) || !is_array($packet['data'])) {
            return '';
        }

        return isset($packet['data']['action']) && is_string($packet['data']['action'])
            ? $packet['data']['action']
            : '';
    }

    /**
     * 取出报文中承载的动作参数（缺失时返回空数组）
     *
     * @param array $packet
     *
     * @return array
     */
    public static function paramsOf(array $packet)
    {
        if (!isset($packet['data']) || !is_array($packet['data'])) {
            return [];
        }

        return isset($packet['data']['params']) && is_array($packet['data']['params'])
            ? $packet['data']['params']
            : [];
    }

    /**
     * 是否为传输层 ack
     *
     * UDP 回执分两层（服务端 §「UDP 回执分两层，勿混判」）：
     *   传输层 —— 网关收包即回，`data` 为空且**无** `action` 字段；
     *   业务层 —— 动作执行结果，带 `data.action`。
     *
     * @param array $packet
     *
     * @return bool
     */
    public static function isTransportAck(array $packet)
    {
        $cmd = isset($packet['cmd']) ? (string)$packet['cmd'] : '';

        return $cmd === Message::CMD_ACK && self::actionOf($packet) === '';
    }
}
