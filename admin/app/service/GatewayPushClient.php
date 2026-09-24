<?php
/**
 * admin · 服务层 —— GatewayPushClient。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace app\service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * GatewayPush 主项目 **HTTP 层** API 客户端（同步）。
 *
 * 与报文层签名的区别（最易搞混，见设计文档 §1.1）：
 * - 本类只用 **HTTP 层**签名：`hex(hmac_sha256("{X-Timestamp}|{原始请求体}", API_SECRET))`；
 * - 报文层（WS/UDP）签名基串完全不同（`cmd|seq|ts|device_id|token|canonicalize(data)`），
 *   **不要**把两者混用（报文层的实现在 `GatewayPush\Client\Protocol\Signer`）。
 *
 * 签名口径与主项目**逐字节同源**（`src/Api/Bootstrap.php:907`）：
 *   `$expect = hash_hmac('sha256', $timestamp . '|' . $request->rawBody(), $secret);`
 * 因此本实现必须满足两条：
 *   ① 参与签名的 body 是**即将发送的那串字节**，不是「重新序列化的数组」；
 *   ② 签名后不得再改动 body。→ 故本类只编码一次（`encode()`），签名与发送共用同一字符串。
 * `tests/Unit/SignerParityTest.php` 用固定 (ts, body, secret) 三元组与主项目
 * `client/src/Service/AdminApi` 的私有 `sign()` 做反射比对，锁死这条同源约束。
 *
 * 错误分层（沿用主项目口径，最易误判）：
 * - **入队前失败** → HTTP 4xx/5xx（未知动作、未开放通道、队列积压）；
 * - **执行后业务失败** → HTTP 200 + 响应体业务码（如缺参 code=4007）。
 *   → 判断成败**必须看响应体 code**，不能只看 HTTP 状态码。
 * 本类统一归一化为 `['ok','status','code','msg','data']`，调用方只看 `ok`/`code` 即可。
 */
final class GatewayPushClient
{
    // ---------------------------------------------------------------------
    // 错误码：与主项目 client/src/Error/ErrorCode.php 同值。
    // 刻意在此重复声明而非跨项目 require：后台与 client 是两个独立 composer 工程，
    // 让后台运行时依赖 client 的传输层会引入 workerman 异步栈（Guzzle 同步栈已足够）。
    // 漂移风险由 SignerParityTest 的同源断言 + 本条注释兜住。
    // ---------------------------------------------------------------------
    public const CODE_OK = 0;
    public const CODE_BAD_PARAM = 4000;
    public const CODE_BAD_SIGN = 4001;
    public const CODE_EXPIRED = 4002;

    /** 动作声明 `auth=true` 但请求未给 `uid`（`src/Api/Bootstrap.php:546`），P3 动作页必然遇到 */
    public const CODE_UNAUTHORIZED = 4003;
    public const CODE_NOT_FOUND = 4004;

    /** 未知指令 / 动作未开放该通道（HTTP 下即「动作未开放 HTTP 通道」） */
    public const CODE_UNKNOWN_CMD = 4006;
    public const CODE_RATE_LIMIT = 4029;
    public const CODE_SERVER_ERROR = 5000;

    /** 传输层失败（连不上 / 超时），对应 client 的 CLIENT_TRANSPORT */
    public const CODE_TRANSPORT = 10002;

    /** @var string 主项目 API 基址（无尾斜杠） */
    private string $apiUrl;

    /** @var string 签名密钥（空 = 服务端回退 AUTH_SECRET） */
    private string $secret;

    /** @var float 请求超时秒 */
    private float $timeout;

    /** @var Client HTTP 客户端 */
    private Client $http;

    /**
     * @param string $apiUrl  主项目 API 基址，如 http://127.0.0.1:8290
     * @param string $secret  API_SECRET；留空表示由服务端回退 AUTH_SECRET
     * @param float  $timeout 请求超时(秒)
     */
    public function __construct(string $apiUrl, string $secret, float $timeout = 8.0)
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->secret = $secret;
        $this->timeout = $timeout;
        $this->http = new Client([
            'base_uri' => $this->apiUrl . '/',
            'timeout' => $this->timeout,
            'connect_timeout' => (float)min(3.0, $this->timeout),
            'http_errors' => false, // 4xx/5xx 由本类自行分层，不让 Guzzle 抛异常
            // ⚠ 强制直连，**忽略环境里的 http_proxy / HTTP_PROXY**。
            // 实测踩坑（2026-09-23）：本机环境存在 http_proxy=http://127.0.0.1:59666，
            // Guzzle（经 ext-curl）会遵循它，把回环请求以「绝对形式 URI + Proxy-Connection」
            // 发给该代理；而该代理在**复用连接的第 2 个请求**上返回 `400 Bad Request`。
            // 现象极具迷惑性：同一连接上 200/400/200/400 交替，看起来像主项目 API 的长连接缺陷
            // —— 实际用裸 socket 直连 8290 连发 3 次全部 200，API 完全正常。
            // 因此这里显式置空，保证后台打主项目永远不经过任何代理。
            'proxy' => '',
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'GatewayPush-Admin/1.0',
            ],
        ]);
    }

    /**
     * 从后台配置构造（`config/gateway_push.php` 的 `api_url` / `api_secret`）。
     *
     * 集中在此而不是让每个控制器各写一遍：**密钥来源只能有一处**。
     * 若有人另写一条「直接读 env」的路径，在 `.env` 键改名后会静默拿到空密钥 ——
     * 表现是「所有写操作都 401」，而比对的双方都是合法字符串，查起来很费劲。
     */
    public static function fromConfig(): self
    {
        $url = config('gateway_push.api_url', 'http://127.0.0.1:8290');
        $secret = config('gateway_push.api_secret', '');

        return new self(
            is_scalar($url) ? (string)$url : 'http://127.0.0.1:8290',
            is_scalar($secret) ? (string)$secret : ''
        );
    }

    /**
     * 计算 HTTP 层签名。
     *
     * 公开可见（而非 private）是**刻意**的：SignerParityTest 需要对它做逐字节断言。
     *
     * @param int    $ts      unix 秒，与服务端 `X-Timestamp` 必须一致
     * @param string $rawBody 原始请求体字节；GET 等无体请求传空串（基串为 "{ts}|"）
     *
     * @return string 小写 hex；secret 为空时返回空串（与服务端 apiSecret() 的「未配置」语义对齐）
     */
    public function sign(int $ts, string $rawBody): string
    {
        if ($this->secret === '') {
            return '';
        }

        return hash_hmac('sha256', $ts . '|' . $rawBody, $this->secret);
    }

    /**
     * 请求体编码。
     *
     * flags 必须与主项目 client 一致（`client/src/Service/AdminApi.php:207`），
     * 否则同一份数据在两侧产出不同字节 → 签名「自洽但双方不一致」。
     *
     * @param array<string, mixed> $job
     */
    public function encode(array $job): string
    {
        $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '{}' : $json;
    }

    /**
     * 组装签名请求头。
     *
     * @return array<string, string>
     */
    public function headers(string $rawBody, ?int $ts = null): array
    {
        $ts ??= time();

        return [
            'X-Timestamp' => (string)$ts,
            'X-Sign' => $this->sign($ts, $rawBody),
            'Content-Type' => 'application/json; charset=utf-8',
        ];
    }

    // ---------------------------------------------------------------------
    // 业务方法（P0 health / stats；P3 补 push / action；/action/{id} 见 actionResult）
    // ---------------------------------------------------------------------

    /**
     * 存活探测（免签）。
     *
     * @return array{ok: bool, status: int, code: int, msg: string, data: array<string, mixed>}
     */
    public function health(): array
    {
        return $this->request('GET', '/health');
    }

    /**
     * 指标快照（需签）。
     *
     * @param array<string, mixed> $query
     *
     * @return array{ok: bool, status: int, code: int, msg: string, data: array<string, mixed>}
     */
    public function stats(array $query = []): array
    {
        return $this->request('GET', '/stats', null, $query);
    }

    /**
     * 发起一次定向推送（**异步受理**）。
     *
     * **只看 `HTTP 200` + `code=0`** —— 那只表示「已入队」，真实投递由 business 进程异步完成，
     * 服务端**不承诺任何逐条投递回执**（设计文档 R5）。
     *
     * ⚠ 两个由调用方兜住的语义（本类**无法**从响应里区分，详见 {@see Pusher} 的文案常量）：
     *   ① **去重是静默的**：`Push::enqueue()` 的 `setNxEx` 非首次只做
     *      `Monitor::incr('push_dedup')` + `Logger::debug`，响应与首次逐字段相同 ⇒
     *      **「本次是否被去重」不可判定**；
     *   ② **payload 超限会在业务进程里静默丢弃**：`PUSH_PAYLOAD_MAX` 的判定点在
     *      `Push::dispatch()`（`src/Business/Push.php:421`），即本方法**已经返回调用方之后**；
     *      超限只记 `push_fail` + warn 日志 ⇒ 必须先过 `Pusher::payloadBytes()` 同源预估。
     *
     * @param array{target_type: string, target: string, payload: array<mixed>, msg_id: string, offline_mode: string} $job
     *                                                                                                                     字段与顺序无关；`offline_mode` 传空串表示取服务端默认值
     *
     * @return array{ok: bool, status: int, code: int, msg: string, data: array<string, mixed>}
     *                                                                                          `data` 为服务端回带：`{target_type, target, msg_id, offline_mode}`，
     *                                                                                          其中 **`offline_mode` 是实际生效值**（未传时回带服务端默认值），应原样落库
     */
    public function push(array $job): array
    {
        return $this->request('POST', '/push', $job);
    }

    /**
     * 调用一个业务动作（**同步等待**，服务端最长阻塞 `API_ACTION_WAIT_MS`）。
     *
     * 响应有四种形态、其中两种的 HTTP 状态码与成败方向相反 ⇒
     * **调用方必须经 {@see ActionOutcome::of()} 归一后再判成败**，不要自己看 HTTP 状态码。
     *
     * ⚠ 超时必须大于服务端的等待窗（`ActionCatalog::WAIT_MS_MIRROR` = 6000ms），
     * 否则本类会先于服务端放弃，把「超窗转 202」误报成连接失败。
     * `$timeout` 默认 8s 满足该关系；若传入更小的值，构造方必须自行确认。
     *
     * @param array{action: string, uid?: string, device_id?: string, params?: array<string, mixed>} $job
     *
     * @return array{ok: bool, status: int, code: int, msg: string, data: array<string, mixed>}
     */
    public function action(array $job): array
    {
        return $this->request('POST', '/action', $job);
    }

    /**
     * 取动作执行结果（`ACTION_RESULT_TTL` 仅 60s，调用方须在窗口内即时补查）。
     *
     * ⚠ 未命中时服务端返回 `404` + `4004`，且**刻意不区分**「仍在执行」与「已被回收」
     * （见 `src/Api/Bootstrap.php:600` 的 hint）—— 归一后一律是
     * `ActionOutcome::STATE_EXPIRED`，不要在前端猜成 `pending`。
     *
     * @return array{ok: bool, status: int, code: int, msg: string, data: array<string, mixed>}
     */
    public function actionResult(string $requestId): array
    {
        return $this->request('GET', '/action/' . rawurlencode($requestId));
    }

    /**
     * 发起一次 HTTP 请求并归一化结果。
     *
     * @param null|array<string, mixed> $job   请求体；null 表示无体（GET）
     * @param array<string, mixed>      $query query string
     *
     * @return array{ok: bool, status: int, code: int, msg: string, data: array<string, mixed>}
     */
    public function request(string $method, string $path, ?array $job = null, array $query = []): array
    {
        // ⚠ 编码一次，签名它，发送它 —— 不得二次序列化（否则「签的 body ≠ 发的 body」）。
        $body = $job !== null ? $this->encode($job) : '';
        $url = ltrim($path, '/');
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        try {
            $response = $this->http->request($method, $url, [
                'body' => $body === '' ? null : $body,
                'headers' => $this->headers($body),
            ]);
        } catch (GuzzleException $e) {
            return $this->fail(0, self::CODE_TRANSPORT, '连接主项目 API 失败：' . $e->getMessage());
        }

        $status = $response->getStatusCode();
        $raw = (string)$response->getBody();

        // 免签模式下 /health 可能返回非 JSON；一律按「响应不是合法 JSON」处理，不抛异常。
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return $this->fail($status, self::CODE_SERVER_ERROR, '响应不是合法 JSON（HTTP ' . $status . '）');
        }

        $code = isset($json['code']) ? (int)$json['code'] : self::CODE_SERVER_ERROR;
        $msg = isset($json['msg']) ? (string)$json['msg'] : '';
        $data = isset($json['data']) && is_array($json['data']) ? $json['data'] : $json;
        $ok = $status >= 200 && $status < 300 && $code === self::CODE_OK;

        if (!$ok && $msg === '') {
            $msg = '请求失败（HTTP ' . $status . '）';
        }

        return [
            'ok' => $ok,
            'status' => $status,
            'code' => $code,
            'msg' => $msg,
            'data' => $data,
        ];
    }

    /** @return string 当前基址（供运维页展示，不含密钥） */
    public function apiUrl(): string
    {
        return $this->apiUrl;
    }

    /** @return bool 是否配置了密钥（**不**返回密钥本身，供密钥状态页展示） */
    public function hasSecret(): bool
    {
        return $this->secret !== '';
    }

    /**
     * @return array{ok: bool, status: int, code: int, msg: string, data: array<string, mixed>}
     */
    private function fail(int $status, int $code, string $msg): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'msg' => $msg, 'data' => []];
    }
}
