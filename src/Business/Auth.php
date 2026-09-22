<?php
/**
 * Token 鉴权
 *
 * 鉴权体系（对应文档 4.2）：
 *  - 时机：连接建立后、首次业务通信前强制鉴权；未鉴权连接仅允许白名单指令
 *  - 规则：Token 有效性 / 过期时间 / 设备合法性 三重校验
 *  - 异常：Token 无效或过期直接断开连接并记录错误日志
 *  - 留存：鉴权成功后绑定会话，连接生命周期内无需重复鉴权
 *
 * Token 结构（自包含，无状态，便于集群横向扩展）：
 *   base64url(payload) . '.' . base64url(hmac_sha256(payload, secret))
 *   payload = {"uid":"","device_id":"","iat":0,"exp":0,"nonce":""}
 *
 * 校验分两段执行，兼顾性能与安全：
 *   verifyLocal()      同步，纯计算（签名 + 时效），无 IO
 *   isRevoked()        异步，查询 Redis 撤销名单
 *   checkDeviceBind()  异步，校验 uid <-> device_id 绑定关系
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Business;

use GatewayPush\Common\Logger;
use GatewayPush\Common\RedisClient;
use GatewayPush\Common\RedisKeys;
use Random\RandomException;

class Auth
{
    /**
     * 鉴权配置
     *
     * @var array
     */
    protected static $config = array(
        'enable'       => true,
        'mode'         => 'hmac',
        'secret'       => '',
        'token_ttl'    => 7200,
        'clock_skew'   => 300,
        'bind_device'  => true,
        'fail_close'   => true,
        'auth_timeout' => 15,
        'allow_cmds'   => array('auth', 'ping'),
    );

    /**
     * 初始化
     *
     * @param array $config app.auth 配置
     * @return void
     */
    public static function init(array $config)
    {
        self::$config = array_merge(self::$config, $config);
        if (self::$config['secret'] === '') {
            Logger::warn('鉴权密钥未配置，生产环境必须修改 app.auth.secret');
        }
    }

    /**
     * 鉴权是否启用
     *
     * @return bool
     */
    public static function enabled()
    {
        return !empty(self::$config['enable']);
    }

    /**
     * 判断指令是否允许在鉴权前执行
     *
     * @param string $cmd
     * @return bool
     */
    public static function isAllowedBeforeAuth($cmd)
    {
        return in_array($cmd, (array)self::$config['allow_cmds'], true);
    }

    /**
     * 鉴权超时阈值（秒）
     *
     * @return int
     */
    public static function authTimeout()
    {
        return (int)self::$config['auth_timeout'];
    }

    /**
     * 鉴权失败是否立即断开连接
     *
     * @return bool
     */
    public static function shouldCloseOnFail()
    {
        return !empty(self::$config['fail_close']);
    }

    /* ---------------------------------------------------------------------
     | 签发
     --------------------------------------------------------------------- */

    /**
     * 签发 Token
     *
     * 说明：生产环境的 Token 通常由业务系统（如 ETC 主站）签发，
     * 本方法主要用于联调自测与内部服务调用。
     *
     * @param array $claims 至少包含 uid，可选 device_id
     * @param int $ttl 有效期（秒），0 取配置默认值
     * @return string
     * @throws RandomException
     */
    public static function issue(array $claims, $ttl = 0)
    {
        $now = time();
        $ttl = $ttl > 0 ? (int)$ttl : (int)self::$config['token_ttl'];

        $payload = array_merge(array(
            'uid'       => '',
            'device_id' => '',
            'iat'       => $now,
            'exp'       => $now + $ttl,
            'nonce'     => bin2hex(random_bytes(8)),
        ), $claims);

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $body = self::base64UrlEncode($json);
        $sign = self::base64UrlEncode(self::hash($body));

        return $body . '.' . $sign;
    }

    /* ---------------------------------------------------------------------
     | 校验
     --------------------------------------------------------------------- */

    /**
     * 本地校验：签名 + 时效（同步，无 IO）
     *
     * @param string $token
     * @return array ['ok'=>bool, 'code'=>int, 'msg'=>string, 'claims'=>array]
     */
    public static function verifyLocal($token)
    {
        $fail = function ($code, $msg) {
            return array('ok' => false, 'code' => $code, 'msg' => $msg, 'claims' => array());
        };

        if (!is_string($token) || $token === '') {
            return $fail(Message::CODE_AUTH_FAILED, 'Token 不能为空');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return $fail(Message::CODE_AUTH_FAILED, 'Token 结构非法');
        }
        list($body, $sign) = $parts;

        // 签名校验（hash_equals 防时序攻击）
        if (!hash_equals(self::base64UrlEncode(self::hash($body)), $sign)) {
            return $fail(Message::CODE_AUTH_FAILED, 'Token 签名校验失败');
        }

        $json  = self::base64UrlDecode($body);
        $claim = $json === '' ? null : json_decode($json, true);
        if (!is_array($claim)) {
            return $fail(Message::CODE_AUTH_FAILED, 'Token 载荷解析失败');
        }

        $now  = time();
        $skew = (int)self::$config['clock_skew'];

        if (!empty($claim['exp']) && $now > (int)$claim['exp']) {
            return $fail(Message::CODE_TOKEN_EXPIRED, 'Token 已过期');
        }
        if (!empty($claim['iat']) && $skew > 0 && (int)$claim['iat'] - $now > $skew) {
            return $fail(Message::CODE_AUTH_FAILED, 'Token 签发时间异常');
        }

        return array('ok' => true, 'code' => Message::CODE_OK, 'msg' => 'ok', 'claims' => $claim);
    }

    /**
     * 查询 Token 是否已被撤销（异步）
     *
     * @param string   $token
     * @param callable $cb function(bool $revoked, string $error)
     * @return void
     */
    public static function isRevoked($token, callable $cb)
    {
        RedisClient::get(RedisKeys::authRevoked(self::tokenFingerprint($token)), function ($result, $client = null) use ($cb) {
            $error = '';
            if ($client && method_exists($client, 'error')) {
                $error = $client->error();
            }
            // Redis 异常时按「不可用即拒绝」处理，避免鉴权被绕过
            $revoked = $error !== '' || ! empty($result);
            $cb($revoked, $error);
        });
    }

    /**
     * 撤销 Token（加入黑名单，TTL 与 Token 剩余有效期对齐）
     *
     * @param string        $token
     * @param int           $ttl   0 取配置默认值
     * @param callable|null $cb
     * @return void
     */
    public static function revoke($token, $ttl = 0, callable $cb = null)
    {
        $ttl = $ttl > 0 ? (int)$ttl : (int)self::$config['token_ttl'];
        RedisClient::set(RedisKeys::authRevoked(self::tokenFingerprint($token)), 1, $ttl, function ($result, $client = null) use ($token, $cb) {
            $error = $client && method_exists($client, 'error') ? $client->error() : '';
            if ($error === '') {
                Logger::info('Token 已加入撤销名单', array('fingerprint' => self::tokenFingerprint($token)));
            }
            if ($cb) {
                call_user_func($cb, $error === '');
            }
        });
    }

    /* ---------------------------------------------------------------------
     | 设备绑定
     --------------------------------------------------------------------- */

    /**
     * 校验 uid 与 device_id 的绑定关系（异步）
     *
     * 首次鉴权时按「首个绑定者胜出」写入；后续鉴权必须与已绑定设备一致，
     * 防止同一账号在不同设备上并发接入。
     *
     * @param string   $uid
     * @param string   $deviceId
     * @param callable $cb function(bool $pass, string $msg)
     * @return void
     * @return void
     */
    public static function checkDeviceBind($uid, $deviceId, callable $cb)
    {
        if (empty(self::$config['bind_device'])) {
            $cb(true, 'device bind check disabled');
            return;
        }
        if ($uid === '' || $deviceId === '') {
            $cb(false, 'uid 或 device_id 为空');
            return;
        }

        $key = RedisKeys::authBind($uid);
        RedisClient::get($key, function ($result, $client = null) use ($uid, $deviceId, $key, $cb) {
            $error = $client && method_exists($client, 'error') ? $client->error() : '';
            if ($error !== '') {
                $cb(false, '设备绑定校验失败：' . $error);
                return;
            }
            if (empty($result)) {
                $ttl = (int)self::$config['token_ttl'];
                RedisClient::set($key, $deviceId, $ttl);
                $cb(true, 'device bind created');
                return;
            }
            if ((string)$result !== (string)$deviceId) {
                $cb(false, '设备不匹配，该账号已绑定其他设备');
                return;
            }
            $cb(true, 'device bind matched');
        });
    }

    /**
     * 主动解绑设备（如用户退出登录）
     *
     * @param string        $uid
     * @param callable|null $cb
     * @return void
     */
    public static function unbindDevice($uid, callable $cb = null)
    {
        RedisClient::del(RedisKeys::authBind($uid), $cb);
    }

    /* ---------------------------------------------------------------------
     | 内部辅助
     --------------------------------------------------------------------- */

    /**
     * 计算 Token 主体签名
     *
     * @param string $body
     * @return string 二进制摘要
     */
    protected static function hash($body)
    {
        return hash_hmac('sha256', $body, (string)self::$config['secret'], true);
    }

    /**
     * Token 指纹（用于 Redis key，避免明文 Token 落盘）
     *
     * @param string $token
     * @return string
     */
    protected static function tokenFingerprint($token)
    {
        return substr(hash('sha256', (string)$token), 0, 32);
    }

    /**
     * URL 安全 Base64 编码
     *
     * @param string $data
     * @return string
     */
    protected static function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * URL 安全 Base64 解码
     *
     * @param string $data
     * @return string
     */
    protected static function base64UrlDecode($data)
    {
        $data = strtr($data, '-_', '+/');
        $pad  = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($data, true);
        return $decoded === false ? '' : $decoded;
    }
}
