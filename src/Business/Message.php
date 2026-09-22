<?php
/**
 * 统一 JSON 报文编解码与合法性校验
 *
 * 双协议（WebSocket / UDP）共用同一报文结构，字段保持一致：
 * {
 *   "cmd":       "指令：auth / ping / data / push / ack / error",
 *   "seq":       "客户端消息序号，服务端原样回传",
 *   "ts":        1690000000,     // 客户端时间戳（秒）
 *   "uid":       "用户ID，鉴权后必填",
 *   "device_id": "设备ID，鉴权后必填",
 *   "token":     "鉴权 Token（auth 指令与 UDP 报文需要）",
 *   "sign":      "报文签名，算法见 sign()",
 *   "data":      {}              // 业务数据体
 * }
 *
 * UDP 无连接，客户端身份完全依赖报文内的 device_id + token；
 * 网关层通过 verify() 完成签名与时效校验（纯本地计算，无 IO 依赖）。
 *
 * 签名算法（客户端需按同一规则实现）：
 *   base = cmd|seq|ts|device_id|token|canonicalize(data)
 *   sign = hex(hmac_sha256(base, secret))
 * 其中 canonicalize(data) 为「递归按键名升序 + 紧凑 JSON」，键顺序无关。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Business;

use JsonException;

class Message
{
    /* ---------------------- 指令 ---------------------- */
    const CMD_AUTH  = 'auth';
    const CMD_ACK   = 'ack';
    const CMD_PING  = 'ping';
    const CMD_PONG  = 'pong';
    const CMD_DATA  = 'data';
    const CMD_PUSH  = 'push';
    const CMD_ERROR = 'error';

    /* ---------------------- 错误码 ---------------------- */
    const CODE_OK            = 0;
    const CODE_BAD_PACKET    = 4000;
    const CODE_BAD_SIGN      = 4001;
    const CODE_BAD_TIMESTAMP = 4002;
    const CODE_UNAUTHORIZED  = 4003;
    const CODE_AUTH_FAILED   = 4004;
    const CODE_TOKEN_EXPIRED = 4005;
    const CODE_UNKNOWN_CMD   = 4006;
    const CODE_PARAM_MISSING = 4007;
    const CODE_RATE_LIMIT    = 4008;
    const CODE_SERVER_ERROR  = 5000;

    /**
     * 报文最大字节数
     */
    const MAX_PACKET_SIZE = 65535;

    /**
     * 错误码文案
     *
     * @var array
     */
    protected static $codeMessages = array(
        self::CODE_OK            => 'ok',
        self::CODE_BAD_PACKET    => '报文格式错误',
        self::CODE_BAD_SIGN      => '签名校验失败',
        self::CODE_BAD_TIMESTAMP => '时间戳偏差超出允许范围',
        self::CODE_UNAUTHORIZED  => '连接未鉴权',
        self::CODE_AUTH_FAILED   => '鉴权失败',
        self::CODE_TOKEN_EXPIRED => 'Token 已过期',
        self::CODE_UNKNOWN_CMD   => '未知指令',
        self::CODE_PARAM_MISSING => '缺少必要参数',
        self::CODE_RATE_LIMIT    => '请求频率超限',
        self::CODE_SERVER_ERROR  => '服务端内部错误',
    );

    /* ---------------------------------------------------------------------
     | 编解码
     --------------------------------------------------------------------- */

    /**
     * 编码为 JSON 字符串
     *
     * @param mixed $packet
     * @return string
     */
    public static function encode($packet)
    {
        if (!is_array($packet)) {
            $packet = self::error(self::CODE_BAD_PACKET, '待编码数据不是数组');
        }
        $json = json_encode($packet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '{}' : $json;
    }

    /**
     * 解码并做基础结构校验
     *
     * 注意：仅做 JSON 与结构层面的校验，签名与时效校验由 verify() 完成。
     *
     * @param mixed  $raw
     * @param string $error 输出错误原因
     * @return array|null 校验失败返回 null
     */
    public static function decode($raw, &$error = null)
    {
        $error = '';

        if (!is_string($raw) || $raw === '') {
            $error = '空报文';
            return null;
        }
        if (strlen($raw) > self::MAX_PACKET_SIZE) {
            $error = '报文长度超限';
            return null;
        }

        $packet = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error = 'JSON 解析失败：' . json_last_error_msg();
            return null;
        }
        if (!is_array($packet)) {
            $error = '报文主体必须是 JSON 对象';
            return null;
        }

        $cmd = isset($packet['cmd']) ? $packet['cmd'] : '';
        if (!is_string($cmd) || $cmd === '') {
            $error = '缺少 cmd 字段';
            return null;
        }

        // 字段归一化，下游无需反复判空
        $packet += array(
            'seq'       => '',
            'ts'        => 0,
            'uid'       => '',
            'device_id' => '',
            'token'     => '',
            'sign'      => '',
            'data'      => [],
        );
        if (!is_array($packet['data'])) {
            $packet['data'] = array('value' => $packet['data']);
        }
        $packet['cmd'] = (string)$cmd;

        return $packet;
    }

    /* ---------------------------------------------------------------------
     | 报文构造
     --------------------------------------------------------------------- */

    /**
     * 构造标准报文
     *
     * @param string $cmd
     * @param array  $data
     * @param array  $extra 附加/覆盖字段
     * @return array
     */
    public static function packet($cmd, $data = [], array $extra = array())
    {
        $packet = array(
            'cmd'  => (string)$cmd,
            'seq'  => '',
            'ts'   => time(),
            'data' => $data,
        );
        foreach ($extra as $key => $value) {
            $packet[$key] = $value;
        }
        return $packet;
    }

    /**
     * 构造回执报文
     *
     * @param string $seq
     * @param array  $data
     * @return array
     */
    public static function ack($seq = '', $data = array())
    {
        return self::packet(self::CMD_ACK, $data, array('seq' => (string)$seq));
    }

    /**
     * 构造错误报文
     *
     * @param int    $code
     * @param string $msg  为空时取默认文案
     * @param string $seq
     * @param string $ref  触发错误的来源指令
     * @return array
     */
    public static function error($code, $msg = '', $seq = '', $ref = '')
    {
        return self::packet(self::CMD_ERROR, array(
            'code' => (int)$code,
            'msg'  => $msg !== '' ? $msg : self::codeMessage($code),
        ), array(
            'seq' => (string)$seq,
            'ref' => (string)$ref,
        ));
    }

    /**
     * 错误码默认文案
     *
     * @param int $code
     * @return string
     */
    public static function codeMessage($code)
    {
        return isset(self::$codeMessages[$code]) ? self::$codeMessages[$code] : '未知错误';
    }

    /* ---------------------------------------------------------------------
     | 签名与校验
     --------------------------------------------------------------------- */

    /**
     * 业务数据规范化字符串：递归按键名升序 + 紧凑 JSON
     *
     * @param mixed $data
     * @return string
     * @throws JsonException
     */
    public static function canonicalize($data)
    {
        if (!is_array($data)) {
            return (string)$data;
        }
        self::recursiveKsort($data);
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '' : $json;
    }

    /**
     * 递归按键名升序排列
     *
     * @param array $data
     * @return void
     */
    protected static function recursiveKsort(array &$data)
    {
        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value)) {
                self::recursiveKsort($value);
            }
        }
        unset($value);
    }

    /**
     * 生成报文签名
     *
     * @param array  $packet
     * @param string $secret
     * @return string
     */
    public static function sign(array $packet, $secret)
    {
        $base = implode('|', array(
            isset($packet['cmd']) ? (string)$packet['cmd'] : '',
            isset($packet['seq']) ? (string)$packet['seq'] : '',
            isset($packet['ts']) ? (string)$packet['ts'] : '',
            isset($packet['device_id']) ? (string)$packet['device_id'] : '',
            isset($packet['token']) ? (string)$packet['token'] : '',
            self::canonicalize($packet['data'] ?? array()),
        ));
        return hash_hmac('sha256', $base, (string)$secret);
    }

    /**
     * 报文合法性校验：签名 + 时效
     *
     * 纯本地计算，无 Redis 交互，可在网关进程安全调用。
     *
     * @param array $packet
     * @param array $authConfig app.auth 配置
     * @return array ['ok' => bool, 'code' => int, 'msg' => string]
     */
    public static function verify(array $packet, array $authConfig)
    {
        if (empty($authConfig['sign_enable'])) {
            return array('ok' => true, 'code' => self::CODE_OK, 'msg' => 'ok');
        }

        $secret = isset($authConfig['secret']) ? (string)$authConfig['secret'] : '';
        if ($secret === '') {
            return array('ok' => false, 'code' => self::CODE_BAD_SIGN, 'msg' => '服务端未配置签名密钥');
        }

        // 时效校验
        $ts    = (int)(isset($packet['ts']) ? $packet['ts'] : 0);
        $skew  = (int)(isset($authConfig['clock_skew']) ? $authConfig['clock_skew'] : 300);
        if ($ts > 0 && $skew > 0 && abs(time() - $ts) > $skew) {
            return array('ok' => false, 'code' => self::CODE_BAD_TIMESTAMP, 'msg' => '时间戳偏差超出允许范围');
        }

        // 签名校验（hash_equals 防时序攻击）
        $sign = isset($packet['sign']) ? (string)$packet['sign'] : '';
        if ($sign === '') {
            return array('ok' => false, 'code' => self::CODE_BAD_SIGN, 'msg' => '缺少 sign 字段');
        }
        if (!hash_equals(self::sign($packet, $secret), $sign)) {
            return array('ok' => false, 'code' => self::CODE_BAD_SIGN, 'msg' => '签名校验失败');
        }

        return array('ok' => true, 'code' => self::CODE_OK, 'msg' => 'ok');
    }
}
