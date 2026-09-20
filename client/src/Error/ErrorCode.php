<?php
/**
 * 客户端错误码
 *
 * 服务端存在**两套互不相同的错误码空间**，混用会导致排障误判，此处显式分开：
 *
 *   1. 报文错误码（WS / UDP）—— 来自 `GatewayPush\Business\Message`，
 *      直接引用其常量，保证与服务端同源、不会漂移。
 *   2. HTTP 接口业务码 —— 来自 `src/Api/Bootstrap.php`，**与报文码不共享语义**。
 *      例：`4004` 在报文里是「鉴权失败」，在 HTTP 里是「接口不存在」。
 *
 * 另定义客户端本地码（10001+），用于表达「根本没走到服务端」的失败。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Error;

use GatewayPush\Business\Message;

final class ErrorCode
{
    /* ---------------------------------------------------------------------
     | 报文错误码（WS / UDP）—— 与服务端 Message 同源
     --------------------------------------------------------------------- */

    const OK            = Message::CODE_OK;
    const BAD_PACKET    = Message::CODE_BAD_PACKET;
    const BAD_SIGN      = Message::CODE_BAD_SIGN;
    const BAD_TIMESTAMP = Message::CODE_BAD_TIMESTAMP;
    const UNAUTHORIZED  = Message::CODE_UNAUTHORIZED;
    const AUTH_FAILED   = Message::CODE_AUTH_FAILED;
    const TOKEN_EXPIRED = Message::CODE_TOKEN_EXPIRED;
    const UNKNOWN_CMD   = Message::CODE_UNKNOWN_CMD;
    const PARAM_MISSING = Message::CODE_PARAM_MISSING;
    const RATE_LIMIT    = Message::CODE_RATE_LIMIT;
    const SERVER_ERROR  = Message::CODE_SERVER_ERROR;

    /* ---------------------------------------------------------------------
     | HTTP 接口业务码（src/Api/Bootstrap.php）—— 语义与报文码不同
     --------------------------------------------------------------------- */

    const HTTP_OK           = 0;
    const HTTP_BAD_PARAM    = 4000;
    const HTTP_BAD_SIGN     = 4001;
    const HTTP_EXPIRED      = 4002;
    const HTTP_NOT_FOUND    = 4004;
    const HTTP_RATE_LIMIT   = 4029;
    const HTTP_SERVER_ERROR = 5000;

    /* ---------------------------------------------------------------------
     | 客户端本地码（10001+）—— 不出现在网络上
     --------------------------------------------------------------------- */

    /** 请求超时：已发出但未在超时窗口内收到回执 */
    const CLIENT_TIMEOUT = 10001;
    /** 传输层错误：连接失败 / 断开 / 底层 socket 报错 */
    const CLIENT_TRANSPORT = 10002;
    /** 配置非法：缺少密钥、URL 不合法等 */
    const CLIENT_CONFIG = 10003;
    /** 状态非法：未连接就发请求、未鉴权就发业务指令等 */
    const CLIENT_STATE = 10004;
    /** 客户端内部错误：底层库抛出且无法归类（如熵源不可用） */
    const CLIENT_INTERNAL = 10005;

    /**
     * 报文错误码默认文案（与服务端 `Message::codeMessage` 完全一致）
     *
     * @param int $code
     * @return string
     */
    public static function message($code)
    {
        return Message::codeMessage($code);
    }

    /**
     * 是否客户端本地码
     *
     * @param int $code
     * @return bool
     */
    public static function isLocal($code)
    {
        return (int)$code >= 10000;
    }

    /**
     * 客户端本地码文案
     *
     * @param int $code
     * @return string
     */
    public static function localMessage($code)
    {
        $map = array(
            self::CLIENT_TIMEOUT   => '请求超时',
            self::CLIENT_TRANSPORT => '传输层错误',
            self::CLIENT_CONFIG    => '客户端配置非法',
            self::CLIENT_STATE     => '客户端状态非法',
            self::CLIENT_INTERNAL  => '客户端内部错误',
        );

        return isset($map[(int)$code]) ? $map[(int)$code] : '未知客户端错误';
    }
}
