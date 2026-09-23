<?php
/**
 * Token 签发与本地校验 —— 服务端 `GatewayPush\Business\Auth` 的薄适配
 *
 * Token 结构（自包含、无状态）：
 *   base64url(payload) . '.' . base64url(hmac_sha256(payload, secret))
 *   payload = {"uid":"","device_id":"","iat":0,"exp":0,"nonce":""}
 *
 * 生产环境的 Token 通常由业务系统签发；本类用于联调自测与连接前预校验：
 * 拿到 Token 先 `inspect()` 一次，就能在「建连之前」发现过期 / 篡改 / 密钥不符，
 * 而不是连上去被服务端断开后再回头查。
 *
 * **静态类状态注意事项**：`Auth` 是静态类，密钥存在进程级静态属性里。
 * 因此本类在每次调用前都会重新把自己的配置写回 `Auth`，
 * 保证「同进程内存在多个不同密钥的 TokenIssuer」时互不串味。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Client\Protocol;

use GatewayPush\Business\Auth;
use GatewayPush\Client\Error\ClientException;

/**
 * Token 签发与本地校验（服务端 Auth 的薄适配）
 *
 * 用于联调自测与建连前预校验；Auth 是静态类，故每次调用前重写配置以免实例间串味。
 */
final class TokenIssuer
{
    /** 与服务端 `app.auth.token_ttl` 默认值一致 */
    public const DEFAULT_TTL = 7200;

    /** 与服务端 `app.auth.clock_skew` 默认值一致 */
    public const DEFAULT_CLOCK_SKEW = 300;

    /**
     * 密钥（对应服务端 app.auth.secret）
     *
     * @var string
     */
    private string $secret;

    /**
     * 默认有效期（秒）
     *
     * @var int
     */
    private int $defaultTtl;

    /**
     * 允许的签发时间偏差（秒）
     *
     * @var int
     */
    private int $clockSkew;

    /**
     * @param string $secret     与服务端 `app.auth.secret` 一致的密钥
     * @param int    $defaultTtl 默认有效期（秒），<=0 取 7200
     * @param int    $clockSkew  允许的签发时间偏差（秒），<0 取 300
     *
     * @throws ClientException 密钥为空，或有效期 / 偏差为负时抛出
     */
    public function __construct($secret, $defaultTtl = 0, $clockSkew = self::DEFAULT_CLOCK_SKEW)
    {
        $secret = (string)$secret;
        if ($secret === '') {
            throw ClientException::config('Token 密钥不能为空（对应服务端 app.auth.secret）');
        }
        if ((int)$defaultTtl < 0) {
            throw ClientException::config('Token 默认有效期不能为负数');
        }

        $this->secret     = $secret;
        $this->defaultTtl = (int)$defaultTtl > 0 ? (int)$defaultTtl : self::DEFAULT_TTL;
        $this->clockSkew  = (int)$clockSkew >= 0 ? (int)$clockSkew : self::DEFAULT_CLOCK_SKEW;
    }

    /**
     * 默认有效期（秒）
     *
     * @return int
     */
    public function defaultTtl()
    {
        return $this->defaultTtl;
    }

    /* ---------------------------------------------------------------------
     | 签发
     --------------------------------------------------------------------- */

    /**
     * 签发 Token
     *
     * @param array<string, mixed> $claims 至少包含非空 uid，可选 device_id
     * @param int                  $ttl    有效期（秒），<=0 取默认值
     *
     * @return string
     *
     * @throws ClientException uid 缺失或底层熵源不可用
     */
    public function issue(array $claims, $ttl = 0)
    {
        if (!isset($claims['uid']) || (string)$claims['uid'] === '') {
            throw ClientException::config('签发 Token 必须提供非空 uid');
        }
        if ((int)$ttl < 0) {
            throw ClientException::config('Token 有效期不能为负数');
        }

        $this->apply();

        try {
            return Auth::issue($claims, (int)$ttl > 0 ? (int)$ttl : $this->defaultTtl);
        } catch (\Exception $e) {
            // random_bytes 失败在 8.1 抛 Exception、8.2+ 抛 Random\RandomException，
            // 此处统一兜住并转为客户端异常，避免把 SPL 细节泄漏给调用方。
            throw ClientException::internal('Token 签发失败：' . $e->getMessage(), $e);
        }
    }

    /* ---------------------------------------------------------------------
     | 校验
     --------------------------------------------------------------------- */

    /**
     * 完整本地校验（签名 + 时效）
     *
     * @param string $token
     *
     * @return array<string, mixed> ['ok'=>bool,'code'=>int,'msg'=>string,'claims'=>array]
     */
    public function inspect($token)
    {
        $this->apply();

        return Auth::verifyLocal((string)$token);
    }

    /**
     * 是否合法
     *
     * @param string $token
     *
     * @return bool
     */
    public function verify($token)
    {
        $result = $this->inspect($token);

        return !empty($result['ok']);
    }

    /**
     * 校验通过时返回载荷，否则返回空数组
     *
     * @param string $token
     *
     * @return array<string, mixed>
     */
    public function claims($token)
    {
        $result = $this->inspect($token);

        return !empty($result['ok']) && isset($result['claims']) && is_array($result['claims'])
            ? $result['claims']
            : [];
    }

    /**
     * 仅解码载荷，**不验签**（排障用）
     *
     * 用于「Token 是对的吗 / 里面写的什么 / 什么时候过期」这类观察，
     * 结论不可作为安全依据。
     *
     * @param string $token
     *
     * @return array<string, mixed> 结构非法时返回空数组
     */
    public function peek($token)
    {
        $parts = explode('.', (string)$token);
        if (count($parts) !== 2) {
            return [];
        }

        $json  = self::base64UrlDecode($parts[0]);
        $claim = $json === '' ? null : json_decode($json, true);

        return is_array($claim) ? $claim : [];
    }

    /* ---------------------------------------------------------------------
     | 内部辅助
     --------------------------------------------------------------------- */

    /**
     * 把本实例的配置写回静态类 `Auth`
     *
     * `Auth` 为进程级静态状态，不重写就会出现「后建的实例改了密钥、
     * 先建的实例跟着一起用错密钥」的静默失败。
     *
     * @return void
     */
    private function apply()
    {
        Auth::init([
            'secret'     => $this->secret,
            'token_ttl'  => $this->defaultTtl,
            'clock_skew' => $this->clockSkew,
        ]);
    }

    /**
     * URL 安全 Base64 解码（与服务端 Auth 同规则）
     *
     * @param string $data
     *
     * @return string
     */
    private static function base64UrlDecode($data)
    {
        $data = strtr((string)$data, '-_', '+/');
        $pad  = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($data, true);

        return $decoded === false ? '' : $decoded;
    }
}
