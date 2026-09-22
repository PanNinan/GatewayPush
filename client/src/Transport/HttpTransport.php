<?php
/**
 * HTTP 传输 —— 管理端（POST /push、GET /stats、GET /health）的单次请求封装
 *
 * 与设计稿 §5.1 草图的偏离（落地修正）：底层用 `AsyncTcpConnection('tcp://...')`
 * 而非 `http://` —— workerman 的 `Http` 协议 encode 是**服务端响应**语义
 * （字符串载荷会被包装成 "HTTP/1.1 200 OK..." 响应帧），客户端发请求必须
 * 自行构造原始 HTTP 报文。响应解析按 Content-Length 精确读取（服务端默认
 * keep-alive，不能依赖 EOF），并在请求头显式带上 Connection: close 让服务端
 * 主动断开，onClose 作为兜底完成信号。
 *
 * 每个请求独占一个连接实例（一次性语义），完成或超时后销毁。
 * 连接对象经构造参数注入工厂创建，单测注入假连接脱离 workerman 事件环境。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Transport;

use GatewayPush\Client\Error\ClientException;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;

/**
 * HTTP 传输：管理端接口的单次请求封装
 *
 * 底层用 AsyncTcpConnection('tcp://...') 自行构造原始报文（workerman 的 Http 协议
 * encode 是服务端响应语义）；每个请求独占一个连接，完成或超时即销毁。
 */
final class HttpTransport
{
    /**
     * 请求超时（秒）
     *
     * @var float
     */
    private $timeout;

    /**
     * 连接工厂 function (string $tcpUrl): object
     *
     * @var callable
     */
    private $connFactory;

    /** @var callable 计时器创建 function (float $interval, bool $persistent, callable $fn): int */
    private $timerAdd;

    /** @var callable 计时器删除 function (int $timerId): void */
    private $timerDel;

    /** @var string */
    private $host;

    /** @var int */
    private $port;

    /**
     * @param string        $baseUrl     http://host:port
     * @param float         $timeout     请求超时（秒）
     * @param null|callable $connFactory 连接工厂（单测注入假连接）
     * @param null|callable $timerAdd    计时器创建
     * @param null|callable $timerDel    计时器删除
     *
     * @throws ClientException URL 非法
     */
    public function __construct(
        $baseUrl,
        $timeout = 5.0,
        ?callable $connFactory = null,
        ?callable $timerAdd = null,
        ?callable $timerDel = null
    ) {
        $baseUrl = (string)$baseUrl;
        $parts   = parse_url($baseUrl);

        if ($parts === false || ($parts['scheme'] ?? '') !== 'http' || ($parts['host'] ?? '') === '') {
            throw ClientException::config('api_url 必须是 http://host[:port] 形式，当前为：' . $baseUrl);
        }

        $this->host       = (string)$parts['host'];
        $this->port       = isset($parts['port']) ? (int)$parts['port'] : 80;
        $this->timeout    = max(0.1, (float)$timeout);

        $self          = $this;
        $this->connFactory = $connFactory !== null
            ? $connFactory
            : fn ($tcpUrl) => new AsyncTcpConnection($tcpUrl);

        $this->timerAdd = $timerAdd !== null
            ? $timerAdd
            : fn ($interval, $persistent, $fn) => Timer::add($interval, $fn, [], $persistent);
        $this->timerDel = $timerDel !== null
            ? $timerDel
            : function ($timerId) {
                Timer::del($timerId);
            };
    }

    /**
     * 发起一次 HTTP 请求（一次性连接）
     *
     * @param string               $method  GET / POST / ...
     * @param string               $path    以 / 开头的路径（如 /push）
     * @param string               $body    原始请求体（GET 为空串）
     * @param array<string, mixed> $headers 附加请求头（键名原样使用）
     * @param callable             $cb      function (array $response): void
     *                                      $response = {status:int, body:string, json:array|null, error:string}
     *                                      error 非空表示传输失败（status=0）
     *
     * @return void
     */
    public function request($method, $path, $body, array $headers, $cb)
    {
        $method = strtoupper((string)$method);
        $path   = '/' . ltrim((string)$path, '/');
        $body   = (string)$body;

        $head = "{$method} {$path} HTTP/1.1\r\n";
        $head .= "Host: {$this->host}:{$this->port}\r\n";
        $head .= "Connection: close\r\n";
        if ($body !== '') {
            $head .= "Content-Type: application/json; charset=utf-8\r\n";
            $head .= 'Content-Length: ' . strlen($body) . "\r\n";
        }
        foreach ($headers as $name => $value) {
            $head .= $name . ': ' . $value . "\r\n";
        }
        $raw = $head . "\r\n" . $body;

        $tcpUrl = 'tcp://' . $this->host . ':' . $this->port;

        // 一次性会话状态（闭包内闭环，互不串扰；用对象承载，便于闭包内更新）
        $ctx          = new \stdClass();
        $ctx->settled = false;
        $ctx->timerId = 0;
        $ctx->conn    = null;

        $settle = function (array $response) use ($ctx, $cb) {
            if ($ctx->settled) {
                return;
            }
            $ctx->settled = true;
            if ($ctx->timerId > 0) {
                ($this->timerDel)($ctx->timerId);
            }
            if ($ctx->conn !== null) {
                $ctx->conn->destroy(); // 一次性连接，不触发 onClose 兜底
                $ctx->conn = null;
            }
            $cb($response);
        };

        try {
            $conn = ($this->connFactory)($tcpUrl);
        } catch (\Throwable $e) {
            $cb(['status' => 0, 'body' => '', 'json' => null, 'error' => '建连失败：' . $e->getMessage()]);

            return;
        }
        $ctx->conn = $conn;

        $conn->onConnect = function () use ($conn, $raw) {
            $conn->send($raw, true); // raw=true：tcp 连接不经过协议 encode
        };

        $conn->onMessage = function ($con, $data) use (&$buffer, $settle) {
            $buffer .= (string)$data;

            $headEnd = strpos($buffer, "\r\n\r\n");
            if ($headEnd === false) {
                return; // 响应头未收全
            }

            $head = substr($buffer, 0, $headEnd);
            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $head, $m) !== 1) {
                return; // 状态行未收全
            }
            $status = (int)$m[1];

            $length = null;
            if (preg_match('/Content-Length:\s*(\d+)/i', $head, $m) === 1) {
                $length = (int)$m[1];
            }

            $body = substr($buffer, $headEnd + 4);
            if ($length === null) {
                return; // 无 Content-Length（chunked/close 分帧），等 onClose 兜底
            }
            if (strlen($body) < $length) {
                return; // 响应体未收全
            }

            $body = substr($body, 0, $length);
            $settle([
                'status' => $status,
                'body'   => $body,
                'json'   => json_decode($body, true),
                'error'  => '',
            ]);
        };

        $conn->onClose = function () use (&$buffer, $settle) {
            // Connection: close 的兜底完成信号（无 Content-Length 场景）
            $headEnd = strpos($buffer, "\r\n\r\n");
            $status  = 0;
            $body    = '';
            if ($headEnd !== false && preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', substr($buffer, 0, $headEnd), $m) === 1) {
                $status = (int)$m[1];
                $body   = substr($buffer, $headEnd + 4);
            }
            $settle([
                'status' => $status,
                'body'   => $body,
                'json'   => $body !== '' ? json_decode($body, true) : null,
                'error'  => $status > 0 ? '' : '连接在收到完整响应前关闭',
            ]);
        };

        $conn->onError = function ($con, $code, $msg) use ($settle) {
            $settle(['status' => 0, 'body' => '', 'json' => null, 'error' => '传输错误 ' . $code . '：' . $msg]);
        };

        // 超时保护
        $ctx->timerId = (int)($this->timerAdd)($this->timeout, false, function () use ($settle, $method, $path) {
            $settle(['status' => 0, 'body' => '', 'json' => null, 'error' => '请求超时：' . $method . ' ' . $path]);
        });

        $conn->connect();
    }
}
