<?php
/**
 * 密钥强度守卫
 *
 * 判断 .env 里的密钥是否仍为占位值或明显弱值。这类值能通过「非空」检查，却等同于
 * 没有鉴权，故启动自检对它们以 FAIL 拦截而非 WARN 提示。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Console;

/**
 * 密钥强度守卫
 *
 * 识别仍为占位值或明显弱值的密钥；启动自检对这类值以 FAIL 拦截而非 WARN 提示。
 */
final class SecretGuard
{
    /**
     * 是否为占位值或明显弱值
     *
     * @param string $secret
     *
     * @return bool
     */
    public static function isPlaceholder(string $secret): bool
    {
        $secret = $secret;

        $placeholders = [
            'change_me', 'changeme', 'change_me_gateway_push_auth_secret',
            'secret', 'password', '123456', 'test', 'demo', 'example',
            'your_secret', 'your-secret', 'todo',
        ];

        if (in_array(strtolower($secret), $placeholders, true)) {
            return true;
        }

        // 单字符重复（如 aaaaaa... / 000000...）
        if (preg_match('/^(.)\1+$/', $secret) === 1) {
            return true;
        }

        return false;
    }
}
