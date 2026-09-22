<?php
/**
 * 报文签名 —— 服务端 `GatewayPush\Business\Message` 的薄适配
 *
 * 签名算法（客户端与服务端必须完全一致）：
 *   base = cmd|seq|ts|device_id|token|canonicalize(data)
 *   sign = hex(hmac_sha256(base, secret))
 *
 * `canonicalize` = 递归按键名升序 + 紧凑 JSON（`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`），
 * 因此 `data` 的键顺序不影响签名 —— 这是最容易跨实现跑偏的地方，故直接复用服务端实现。
 *
 * 两条容易误判的事实（均由单测固定）：
 *   1. **`uid` 不参与签名**。签名基串里没有 uid，报文字段 uid 可被篡改；
 *      UDP 侧身份只能取自 `token` 载荷（服务端密钥 HMAC 保护）。
 *   2. **WS 通道不校验签名**，`sign` 仅 UDP 通道（`UDP_SIGN_ENABLE`）必需。
 *      客户端的做法是：一律算、一律带上 —— 对 WS 无副作用，对 UDP 才有效。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Protocol;

use GatewayPush\Business\Message;
use JsonException;

/**
 * 报文签名（服务端 Message 的薄适配）
 *
 * uid 不参与签名；WS 通道不校验签名，客户端一律计算并携带。
 */
final class Signer
{
    /**
     * 生成报文签名
     *
     * @param array  $packet
     * @param string $secret
     *
     * @return string 64 位十六进制
     */
    public static function sign(array $packet, $secret)
    {
        return Message::sign($packet, (string)$secret);
    }

    /**
     * 业务数据规范化字符串：递归按键名升序 + 紧凑 JSON
     *
     * @param mixed $data
     *
     * @return string
     *
     * @throws JsonException 数据无法编码为 JSON 时抛出
     */
    public static function canonicalize($data)
    {
        return Message::canonicalize($data);
    }

    /**
     * 拼出签名基串（排障用：可直接看出「签的是什么」）
     *
     * 与 `Message::sign()` 内部的拼接规则逐字对应；`SignerTest` 中有断言把二者绑定，
     * 一旦任一侧漂移即测试失败。
     *
     * @param array $packet
     *
     * @return string
     *
     * @throws JsonException data 无法编码为 JSON 时抛出
     */
    public static function baseString(array $packet)
    {
        return implode('|', [
            isset($packet['cmd']) ? (string)$packet['cmd'] : '',
            isset($packet['seq']) ? (string)$packet['seq'] : '',
            isset($packet['ts']) ? (string)$packet['ts'] : '',
            isset($packet['device_id']) ? (string)$packet['device_id'] : '',
            isset($packet['token']) ? (string)$packet['token'] : '',
            self::canonicalize($packet['data'] ?? []),
        ]);
    }

    /**
     * 本地验签 + 时效校验
     *
     * 复用服务端 `Message::verify()`，等价于「服务端会怎么判我这条报文」的本地预演，
     * 适合在 UDP 链路排障时先自证报文合法，再怀疑网络。
     *
     * @param array  $packet
     * @param string $secret
     * @param int    $clockSkew 允许的时间戳偏差（秒），<=0 表示不校验
     *
     * @return array ['ok'=>bool,'code'=>int,'msg'=>string]
     */
    public static function verify(array $packet, $secret, $clockSkew = 300)
    {
        return Message::verify($packet, [
            'sign_enable' => true,
            'secret'      => (string)$secret,
            'clock_skew'  => (int)$clockSkew,
        ]);
    }
}
