<?php
/**
 * HTTP 管理端 API —— POST /push、GET /stats、GET /health
 *
 * 服务端验签规则（src/Api/Bootstrap.php）：
 *   X-Timestamp = Unix 秒（与服务端偏差须在 api.sign_ttl 内，防重放）
 *   X-Sign      = hex(hmac_sha256("{X-Timestamp}|{原始请求体}", api.secret))
 *   原始请求体参与签名（而非解析后的数组），避免键序/转义差异导致验签失败；
 *   api.secret 留空时服务端回退复用 auth.secret，客户端构造时同样回退。
 *
 * GET 请求的请求体为空串，签名基串为 "{ts}|"。
 *
 * 回调三元组与 Service/*Api 一致：function (bool $ok, array $data, ?array $error): void
 *   $ok    = HTTP 2xx 且业务码 code === 0
 *   $data  = 响应 data 字段（非 2xx 时为响应体数组原样）
 *   $error = ['status'=>int, 'code'=>int, 'msg'=>string]（传输失败 code=10002）
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Service;

use GatewayPush\Client\Error\ErrorCode;
use GatewayPush\Client\Transport\HttpTransport;

final class AdminApi
{
    /**
     * @var HttpTransport
     */
    private $transport;

    /**
     * 接口密钥（服务端 api.secret；留空回退 auth.secret）
     *
     * @var string
     */
    private $secret;

    /**
     * @param string             $apiUrl    http://host:port
     * @param string             $apiSecret 接口密钥（留空回退 auth.secret）
     * @param float              $timeout   请求超时（秒）
     * @param HttpTransport|null $transport 缺省按 url/timeout 构造
     */
    public function __construct($apiUrl, $apiSecret, $timeout = 5.0, HttpTransport $transport = null)
    {
        $this->transport  = $transport !== null
            ? $transport
            : new HttpTransport($apiUrl, $timeout);
        $this->secret = (string)$apiSecret;
    }

    /**
     * 提交定向推送任务
     *
     * @param string     $targetType uid / device / client
     * @param string     $target     目标标识
     * @param array      $payload    推送载荷
     * @param array      $opts       msg_id / offline_mode（可选）
     * @param callable|null $cb      function (bool $ok, array $data, ?array $error): void
     * @return void
     */
    public function push($targetType, $target, array $payload, array $opts = array(), $cb = null)
    {
        $job = array(
            'target_type' => (string)$targetType,
            'target'      => (string)$target,
            'payload'     => $payload,
        );
        if (isset($opts['msg_id']) && (string)$opts['msg_id'] !== '') {
            $job['msg_id'] = (string)$opts['msg_id'];
        }
        if (isset($opts['offline_mode']) && (string)$opts['offline_mode'] !== '') {
            $job['offline_mode'] = (string)$opts['offline_mode'];
        }

        $this->call('POST', '/push', $job, $cb);
    }

    /**
     * 指标快照
     *
     * @param callable|null $cb function (bool $ok, array $data, ?array $error): void
     * @return void
     */
    public function stats($cb = null)
    {
        $this->call('GET', '/stats', null, $cb);
    }

    /**
     * 存活探测（免鉴权，但客户端仍统一携带验签头）
     *
     * @param callable|null $cb function (bool $ok, array $data, ?array $error): void
     * @return void
     */
    public function health($cb = null)
    {
        $this->call('GET', '/health', null, $cb);
    }

    /* ---------------------------------------------------------------------
     | 内部实现
     --------------------------------------------------------------------- */

    /**
     * 统一请求出口：签名、发送、响应归一化
     *
     * @param string       $method
     * @param string       $path
     * @param array|null   $job   null 表示无请求体（GET）
     * @param callable|null $cb
     * @return void
     */
    private function call($method, $path, array $job = null, $cb = null)
    {
        $body = $job !== null ? $this->encode($job) : '';
        $ts   = time();

        $headers = array(
            'X-Timestamp' => (string)$ts,
            'X-Sign'      => $this->sign($ts, $body),
        );

        $this->transport->request($method, $path, $body, $headers, function (array $response) use ($cb) {
            if ($cb === null) {
                return;
            }

            if ($response['error'] !== '' || $response['status'] === 0) {
                call_user_func($cb, false, array(), array(
                    'status' => 0,
                    'code'   => ErrorCode::CLIENT_TRANSPORT,
                    'msg'    => $response['error'] !== '' ? $response['error'] : '传输失败',
                ));
                return;
            }

            $json = $response['json'];
            if (!is_array($json)) {
                call_user_func($cb, false, array(), array(
                    'status' => $response['status'],
                    'code'   => ErrorCode::HTTP_SERVER_ERROR,
                    'msg'    => '响应不是合法 JSON（HTTP ' . $response['status'] . '）',
                ));
                return;
            }

            $code    = isset($json['code']) ? (int)$json['code'] : ErrorCode::HTTP_SERVER_ERROR;
            $msg     = isset($json['msg']) ? (string)$json['msg'] : '';
            $is2xx   = $response['status'] >= 200 && $response['status'] < 300;
            $ok      = $is2xx && $code === ErrorCode::HTTP_OK;
            $data    = isset($json['data']) && is_array($json['data']) ? $json['data'] : $json;

            if ($ok) {
                call_user_func($cb, true, $data, null);
                return;
            }

            call_user_func($cb, false, $data, array(
                'status' => $response['status'],
                'code'   => $code,
                'msg'    => $msg !== '' ? $msg : '请求失败（HTTP ' . $response['status'] . '）',
            ));
        });
    }

    /**
     * 签名：hex(hmac_sha256("{ts}|{rawBody}", secret))
     *
     * @param int    $ts
     * @param string $rawBody
     * @return string 密钥为空时返回空串（服务端会以 500 拒绝，与「未配置密钥」语义对齐）
     */
    private function sign($ts, $rawBody)
    {
        if ($this->secret === '') {
            return '';
        }

        return hash_hmac('sha256', $ts . '|' . $rawBody, $this->secret);
    }

    /**
     * 请求体编码（compact JSON，服务端按原始体验签，客户端只须保证自洽）
     *
     * @param array $job
     * @return string
     */
    private function encode(array $job)
    {
        $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '{}' : $json;
    }
}
