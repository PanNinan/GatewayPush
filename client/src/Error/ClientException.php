<?php
/**
 * 客户端统一异常
 *
 * 覆盖三类失败，靠 `getCode()` 区分：
 *   - 服务端下发的 `cmd=error` 报文（code 为 4000~5000）
 *   - 请求超时 / 传输错误 / 配置非法 / 状态非法（code 为 10001+，见 ErrorCode）
 *
 * 携带触发异常的原始报文（若来自服务端），便于调用方与日志排查。
 *
 * 兼容 PHP 8.1 ~ 8.5
 *
 */

namespace GatewayPush\Client\Error;

class ClientException extends \RuntimeException
{
    /**
     * 触发异常的原始报文（本地失败时为空数组）
     *
     * @var array
     */
    protected $packet;

    /**
     * @param int             $code     错误码（报文码或客户端本地码）
     * @param string          $message  错误描述
     * @param array           $packet   原始报文
     * @param \Throwable|null $previous 上游异常
     */
    public function __construct($code, $message, array $packet = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, (int)$code, $previous);
        $this->packet = $packet;
    }

    /**
     * 触发异常的原始报文
     *
     * @return array
     */
    public function packet()
    {
        return $this->packet;
    }

    /**
     * 由服务端 error 报文构造
     *
     * 报文形如：
     *   {"cmd":"error","seq":"1","ref":"data","data":{"code":4003,"msg":"连接未鉴权"}}
     * `data` 非数组或缺少 code 时回落为 5000（服务端内部错误）。
     *
     * @param array $packet
     * @return self
     */
    public static function fromPacket(array $packet)
    {
        $data = isset($packet['data']) && is_array($packet['data']) ? $packet['data'] : [];

        $code = isset($data['code']) ? (int)$data['code'] : ErrorCode::SERVER_ERROR;
        $msg  = isset($data['msg']) && is_string($data['msg']) && $data['msg'] !== ''
            ? $data['msg']
            : ErrorCode::message($code);

        $ref = isset($packet['ref']) && (string)$packet['ref'] !== ''
            ? '（来源指令：' . $packet['ref'] . '）'
            : '';

        return new self($code, $msg . $ref, $packet);
    }

    /**
     * 请求超时
     *
     * @param string $what   超时的请求描述（如 `data.echo`）
     * @param string $seq    请求序号
     * @param float  $waited 已等待秒数
     * @return self
     */
    public static function timeout($what, $seq = '', $waited = 0.0)
    {
        $detail = $seq !== '' ? '，seq=' . $seq : '';
        $detail .= $waited > 0 ? '，已等待 ' . round($waited, 2) . 's' : '';

        return new self(
            ErrorCode::CLIENT_TIMEOUT,
            '请求超时：' . $what . $detail
        );
    }

    /**
     * 传输层错误
     *
     * @param string          $message
     * @param \Throwable|null $previous
     * @return self
     */
    public static function transport($message, ?\Throwable $previous = null)
    {
        return new self(ErrorCode::CLIENT_TRANSPORT, $message, [], $previous);
    }

    /**
     * 配置非法
     *
     * @param string $message
     * @return self
     */
    public static function config($message)
    {
        return new self(ErrorCode::CLIENT_CONFIG, $message);
    }

    /**
     * 状态非法
     *
     * @param string $message
     * @return self
     */
    public static function state($message)
    {
        return new self(ErrorCode::CLIENT_STATE, $message);
    }

    /**
     * 客户端内部错误（底层库抛出且无法归类）
     *
     * @param string          $message
     * @param \Throwable|null $previous
     * @return self
     */
    public static function internal($message, ?\Throwable $previous = null)
    {
        return new self(ErrorCode::CLIENT_INTERNAL, $message, [], $previous);
    }
}
