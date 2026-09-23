<?php
/**
 * HTTP 接口（独立进程，role=api）
 *
 * ---------------------------------------------------------------------
 * 职责边界
 * ---------------------------------------------------------------------
 * 只做三件事：验签 -> 参数校验 -> 任务入队，随后按接口语义返回。
 * 不持有 Gateway 连接、不直接投递、不感知会话状态 —— 真正的执行由业务进程
 * 消费队列后完成（Push::dispatch / ActionRunner::run），保证指标统计与
 * 幂等控制只有一条执行路径。
 *
 * 因此本进程与 Gateway / BusinessWorker 之间不产生任何内部通信依赖，
 * 只需 Redis 连接；即便后端短暂不可用，接口仍可正常受理并缓冲任务。
 *
 * ---------------------------------------------------------------------
 * 两类接口的差异（重要）
 * ---------------------------------------------------------------------
 *   推送类（/push）—— 异步受理。入队即返回 accepted，不关心投递结果。
 *   动作类（/action）—— **同步等待**。动作有返回值，调用方需要它，
 *                       故入队后轮询 action:result:{request_id} 取回结果；
 *                       超窗降级为 202，可经 GET /action/{id} 补查。
 *
 * 也因此两者的错误码语义不同：
 *   入队前的校验失败（验签 / 参数 / 白名单 / 队列积压）→ HTTP 4xx/5xx；
 *   动作执行后的业务失败（4006 未知动作 / 4007 参数不合规 / 5000 超时）
 *   → **HTTP 200**，业务码在响应体 code 字段。理由：HTTP 调用本身是成功的，
 *   失败的是被调用的动作 —— 与 8.6 节报文码的「传输与业务分离」原则一致。
 *
 * ---------------------------------------------------------------------
 * 接口清单
 * ---------------------------------------------------------------------
 *   GET  /health          免鉴权，存活探测
 *   GET  /stats           需鉴权，返回 Redis 中的指标快照
 *   POST /push            需鉴权，提交定向推送任务
 *   POST /action          需鉴权，调用业务动作（同步等待结果）
 *   GET  /action/{id}     需鉴权，补查动作结果
 *
 * ---------------------------------------------------------------------
 * 鉴权
 * ---------------------------------------------------------------------
 *   X-Timestamp  Unix 秒，与服务端偏差需在 api.sign_ttl 内（防重放）
 *   X-Sign       hex(hmac_sha256("{X-Timestamp}|{原始请求体}", api.secret))
 *
 * 采用原始请求体参与签名（而非解析后的数组），避免键序/转义差异导致的验签失败。
 *
 * 与 WS / UDP 的鉴权**没有任何关系**：`AUTH_ENABLE` / `AUTH_SIGN_ENABLE`
 * 作用于报文层（Auth::enabled / Message::verify），本进程完全不读这两个开关。
 * 唯一的耦合是 api.secret 留空时回退复用 auth.secret —— 只共用密钥，不共用开关。
 *
 * 本地调试可经 `API_SIGN_ENABLE=false` 免签，但**仅在 listen 绑定回环地址时生效**
 * （见 signEnabled()）；绑非回环地址时该开关被忽略，强制验签。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Api;

use GatewayPush\Business\ActionReply;
use GatewayPush\Business\ActionRunner;
use GatewayPush\Business\Message;
use GatewayPush\Business\Monitor;
use GatewayPush\Business\Push;
use GatewayPush\Common\Logger;
use GatewayPush\Common\RedisClient;
use GatewayPush\Common\RedisKeys;
use GatewayPush\Common\WorkerEvents;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Timer;
use Workerman\Worker;

/**
 * HTTP 接口进程（role=api）：验签 → 参数校验 → 任务入队
 *
 * 不持有 Gateway 连接、不感知会话状态，只依赖 Redis；动作类接口同步等待结果。
 */
class Bootstrap
{
    /** 业务返回码 */
    public const CODE_OK           = 0;
    public const CODE_BAD_PARAM    = 4000;
    public const CODE_BAD_SIGN     = 4001;
    public const CODE_EXPIRED      = 4002;
    public const CODE_NOT_FOUND    = 4004;
    public const CODE_RATE_LIMIT   = 4029;
    public const CODE_SERVER_ERROR = 5000;

    /**
     * 动作队列积压超限（准入控制）
     *
     * 与 /push 的差异：/push 只入队便返回，积压仅记告警；动作有同步等待的
     * 调用方，每个在途请求占着一条 HTTP 连接，因此积压时必须拒绝而非继续受理。
     */
    public const CODE_OVERLOAD = 5030;

    /** 结果轮询的起始 / 上限间隔（毫秒） */
    public const POLL_MIN_MS = 10;
    public const POLL_MAX_MS = 150;

    /**
     * 访问日志中请求体最大记录长度（字节）
     *
     * Logger 单条 context 上限约 2000 字符，头 + 元信息约占数百字符，
     * 体截到 1KB 后整条日志仍能完整落盘，不会被 stringifyContext 硬截。
     */
    public const ACCESS_LOG_BODY_MAX = 1024;

    /**
     * 访问日志中需脱敏的请求头（小写名）
     *
     * 签名 / 凭据类头一旦明文进日志，翻日志即可重放或仿冒调用方。
     * X-Timestamp 不在此列 —— 它本身是公开值，且与 X-Sign 对照才有意义。
     *
     * @var array<int, string>
     */
    protected static array $redactHeaders = ['x-sign', 'authorization', 'cookie', 'proxy-authorization'];

    /**
     * api 配置（app.api）
     *
     * @var array<string, mixed>
     */
    protected static array $config = [
        'enable'      => true,
        'listen'      => 'http://127.0.0.1:8290',
        'name'        => 'GW-API',
        'secret'      => '',
        'sign_enable' => true,
        'sign_ttl'    => 300,
        'rate'        => 600,
        'body_max'    => 65536,
        'action_wait' => 6000,
    ];

    /**
     * app.php 配置（Redis 等）
     *
     * @var array<string, mixed>
     */
    protected static array $appConfig = [];

    /**
     * business.php 配置（动作队列）
     *
     * @var array<string, mixed>
     */
    protected static array $businessConfig = [];

    /**
     * 已开放 HTTP 通道的动作中最长的回执超时（秒）
     *
     * 仅用于启动时校验「等待窗 > 动作超时」，取值 0 表示无动作开放 HTTP。
     *
     * @var int
     */
    protected static int $actionTimeoutMax = 0;

    /**
     * 初始化 HTTP 接口进程
     *
     * @param array<string, mixed> $appConfig      config/app.php
     * @param array<string, mixed> $businessConfig config/business.php
     * @param array<string, mixed> $actionConfig   config/actions.php（用于 HTTP 白名单与超时校验）
     *
     * @return void
     */
    public static function init(array $appConfig, array $businessConfig, array $actionConfig = [])
    {
        if (!self::roleEnabled('api')) {
            return;
        }

        self::$appConfig      = $appConfig;
        self::$businessConfig = $businessConfig;
        self::$config         = array_merge(self::$config, $appConfig['api']);

        if (empty(self::$config['enable'])) {
            return;
        }

        Push::init($appConfig['push'], $businessConfig['push_queue']);

        // 动作表在本进程只用于「入队前的白名单校验」，真正的参数校验与执行
        // 仍在业务进程 —— 避免出现第二处执行语义。装载是幂等的，重复装载无副作用。
        ActionRunner::load($actionConfig);
        ActionReply::init($businessConfig['action_queue'] ?? []);
        self::$actionTimeoutMax = self::longestActionTimeout();

        $worker = new Worker(self::$config['listen']);
        $worker->name  = self::$config['name'];
        $worker->count = 1;   // 接口层无状态且轻量，单进程足够；Windows 下亦强制为 1

        // Worker('http://...') 会自动使用 Workerman\Protocols\Http，
        // onMessage 收到的即为已解析的 Request 对象
        $worker->onMessage = [self::class, 'onRequest'];

        $worker->onWorkerStart = function ($worker) {
            Logger::useChannel('api');

            RedisClient::init(self::$appConfig['redis']);
            Monitor::init(self::$appConfig['monitor']);

            $secret = self::apiSecret();
            $signOn = self::signEnabled();
            $socket = $worker->getSocketName();

            Logger::info('HTTP 接口已启动', [
                'listen'       => $socket,
                'sign_enable'  => $signOn ? 1 : 0,
                'sign_ttl'     => (int)self::$config['sign_ttl'],
                'rate_limit'   => (int)self::$config['rate'],
                'secret_set'   => $secret !== '' ? 1 : 0,
                'http_actions' => ActionRunner::httpActions(),
                'action_wait'  => (int)self::$config['action_wait'],
                'result_ttl'   => ActionReply::ttl(),
            ]);

            if (!$signOn) {
                Logger::warn('接口验签已关闭（本地调试），所有请求无需签名即可调用', [
                    'listen' => $socket,
                    'tip'    => '仅回环监听可关闭验签，请勿用于生产环境',
                ]);
            } elseif (empty(self::$config['sign_enable'])) {
                // 配置想关但被护栏拦下 —— 必须明确告知，否则调用方会困惑
                // 「为什么我关了验签还是要签名」
                Logger::warn('API_SIGN_ENABLE=false 未生效：监听地址非回环，已强制开启验签', [
                    'listen' => $socket,
                ]);
            } elseif ($secret === '') {
                Logger::warn('接口密钥为空，所有请求都会被拒绝。请配置 API_SECRET（留空时会回退复用 AUTH_SECRET）');
            }

            // 等待窗必须大于动作回执超时，否则动作尚在时限内、Api 已先行返回 202
            if (self::$actionTimeoutMax > 0) {
                $waitMs = (int)self::$config['action_wait'];
                if ($waitMs <= self::$actionTimeoutMax * 1000) {
                    Logger::warn('API_ACTION_WAIT_MS 不大于动作回执超时，HTTP 动作可能先返回 202', [
                        'action_wait_ms'   => $waitMs,
                        'action_timeout_s' => self::$actionTimeoutMax,
                    ]);
                }
            }
        };

        $worker->onWorkerStop = function ($worker) {
            Logger::info('HTTP 接口正在停止', ['id' => $worker->id]);
            RedisClient::closeAll();
        };

        // HTTP 短连接存在发送缓冲，背压事件一并绑定
        WorkerEvents::bind($worker, self::$config['name']);
    }

    /**
     * 请求入口
     *
     * @param mixed $connection
     * @param mixed $request    Workerman\Protocols\Http\Request
     *
     * @return void
     */
    public static function onRequest($connection, $request)
    {
        try {
            if (!$request instanceof Request) {
                $connection->send(self::json(500, self::CODE_SERVER_ERROR, '协议解析异常', null, 500));

                return;
            }

            // 访问日志：方法 / 路径 / 查询串 / 客户端 IP / 脱敏请求头 / 截断请求体。
            // 放在路由与鉴权之前 —— 401 / 404 / 429 等失败请求同样要有完整入站记录，
            // 否则排障时最需要上下文的恰恰是这些「没走到业务」的请求。
            self::accessLog($request);

            $method = $request->method();
            $path   = $request->path();

            // 存活探测：免鉴权，供负载均衡 / 容器探针使用
            if ($method === 'GET' && $path === '/health') {
                $connection->send(self::json(200, self::CODE_OK, 'ok', [
                    'service' => 'gateway-push-api',
                    'time'    => time(),
                ]));

                return;
            }

            $auth = self::authenticate($request);
            if ($auth !== null) {
                $connection->send($auth);

                return;
            }

            if ($method === 'GET' && $path === '/stats') {
                self::handleStats($connection);

                return;
            }

            if ($method === 'POST' && $path === '/push') {
                self::handlePush($connection, $request);

                return;
            }

            if ($method === 'POST' && $path === '/action') {
                self::handleAction($connection, $request);

                return;
            }

            if ($method === 'GET' && str_starts_with($path, '/action/')) {
                self::handleActionResult($connection, substr($path, strlen('/action/')));

                return;
            }

            $connection->send(self::json(404, self::CODE_NOT_FOUND, '接口不存在：' . $method . ' ' . $path, null, 404));
        } catch (\Throwable $e) {
            Logger::exception($e, 'api.on_request');
            $connection->send(self::json(500, self::CODE_SERVER_ERROR, '服务端内部错误', null, 500));
        }
    }

    /**
     * 判断监听地址是否绑定回环
     *
     * 纯函数（不读静态状态），便于单测直接覆盖各类 listen 写法。
     *
     * 声明为 public 是为让 start.php 的环境自检与启动横幅复用**同一份**判定 ——
     * 「能不能免签」这件事一旦出现两套实现，就必然出现"启动提示已关闭、实际仍在验签"
     * 这类自相矛盾的输出。
     *
     * 刻意不用 parse_url()：它对「省略协议」（workerman 允许 0.0.0.0:8290 这类写法）
     * 与「裸 IPv6」（::1）的解析结果不稳定，前者靠补 scheme 能救、后者直接取不到
     * host。这是安全相关的判定，可预测性优先于写法上的"标准"，故手工解析。
     *
     * @param string $listen 形如 http://127.0.0.1:8290 / 0.0.0.0:8290 / http://[::1]:8290
     *
     * @return bool
     */
    public static function isLoopbackHost($listen)
    {
        $rest = trim((string)$listen);
        if ($rest === '') {
            // 判定不了就按「非回环」处理，即保留验签 —— 出错时偏向安全侧
            return false;
        }

        // 剥掉协议头，得到 host[:port] / [::1]:port
        $rest = preg_replace('#^[a-z][a-z0-9+.\-]*://#i', '', $rest);
        $rest = strtolower(trim((string)$rest));

        if (str_starts_with($rest, '[')) {
            // IPv6 字面量：方括号内即 host
            $end  = strpos($rest, ']');
            $host = $end === false ? substr($rest, 1) : substr($rest, 1, $end - 1);
        } elseif (substr_count($rest, ':') === 1) {
            // 有且仅有一个冒号 → host:port
            $host = substr($rest, 0, (int)strrpos($rest, ':'));
        } else {
            // 无端口，或裸 IPv6（冒号不止一个且无方括号）
            $host = $rest;
        }

        if ($host === 'localhost' || $host === '::1') {
            return true;
        }

        // 127.0.0.0/8 整段都是回环，不止 127.0.0.1
        return str_starts_with($host, '127.');
    }

    /* ---------------------------------------------------------------------
     | 接口实现
     | --------------------------------------------------------------------- */

    /**
     * 推送任务受理
     *
     * @param mixed   $connection
     * @param Request $request
     *
     * @return void
     *
     * @throws \JsonException 请求体不是合法 JSON 时抛出
     */
    protected static function handlePush($connection, Request $request)
    {
        $body = $request->rawBody();

        $job = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($job)) {
            $connection->send(self::json(400, self::CODE_BAD_PARAM, '请求体必须是合法 JSON 对象'));

            return;
        }

        $targetType = isset($job['target_type']) ? strtolower(trim((string)$job['target_type'])) : '';
        $target     = isset($job['target']) ? trim((string)$job['target']) : '';
        $payload    = $job['payload'] ?? [];

        if (!in_array($targetType, [Push::TARGET_UID, Push::TARGET_DEVICE, Push::TARGET_CLIENT], true)) {
            $connection->send(self::json(400, self::CODE_BAD_PARAM, 'target_type 必须是 uid / device / client 之一'));

            return;
        }
        if ($target === '') {
            $connection->send(self::json(400, self::CODE_BAD_PARAM, 'target 不能为空'));

            return;
        }
        if (!is_array($payload)) {
            $connection->send(self::json(400, self::CODE_BAD_PARAM, 'payload 必须是 JSON 对象或数组'));

            return;
        }

        $opts = [
            'msg_id'       => isset($job['msg_id']) ? (string)$job['msg_id'] : '',
            'offline_mode' => isset($job['offline_mode']) ? (string)$job['offline_mode'] : '',
            'source'       => 'http',
        ];

        Push::enqueue($targetType, $target, $payload, $opts, function ($ok) use ($connection, $targetType, $target, $opts, $payload) {
            if (!$ok) {
                $connection->send(self::json(500, self::CODE_SERVER_ERROR, '推送任务入队失败', null, 500));

                return;
            }

            Logger::debug('HTTP 推送已受理', [
                'target_type'  => $targetType,
                'target'       => $target,
                'msg_id'       => $opts['msg_id'],
                'offline_mode' => $opts['offline_mode'],
                'payload'      => $payload,
            ]);

            $connection->send(self::json(200, self::CODE_OK, 'accepted', [
                'target_type'  => $targetType,
                'target'       => $target,
                'msg_id'       => $opts['msg_id'],
                'offline_mode' => $opts['offline_mode'] !== '' ? $opts['offline_mode'] : Push::offlineMode(),
            ]));
        });
    }

    /**
     * 指标快照
     *
     * @param mixed $connection
     *
     * @return void
     */
    protected static function handleStats($connection)
    {
        Monitor::snapshot(function ($snapshot) use ($connection) {
            $connection->send(self::json(200, self::CODE_OK, 'ok', $snapshot));
        });
    }

    /* ---------------------------------------------------------------------
     | 业务动作接口
     | --------------------------------------------------------------------- */

    /**
     * 动作调用受理（POST /action）
     *
     * 流程：解析校验 -> 白名单 -> 入队 -> 轮询取回结果。
     * 入队前的失败一律给出 4xx/5xx；动作自身的失败以 HTTP 200 + 业务码返回
     * （见类注释「两类接口的差异」）。
     *
     * @param mixed   $connection
     * @param Request $request
     *
     * @return void
     *
     * @throws \Exception random_bytes() 熵源异常
     *                    （8.2+ 抛 Random\RandomException，其为 \Exception 子类；
     *                    此处标注基类，以兼容项目 PHP 8.1 下限）
     */
    protected static function handleAction($connection, Request $request)
    {
        $body = $request->rawBody();

        try {
            $job = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $connection->send(self::json(400, self::CODE_BAD_PARAM, '请求体不是合法 JSON：' . $e->getMessage()));

            return;
        }
        if (!is_array($job)) {
            $connection->send(self::json(400, self::CODE_BAD_PARAM, '请求体必须是合法 JSON 对象'));

            return;
        }

        $action = isset($job['action']) ? trim((string)$job['action']) : '';
        if ($action === '') {
            $connection->send(self::json(400, self::CODE_BAD_PARAM, 'action 不能为空'));

            return;
        }

        // 白名单前置：未知动作 / 未开放 HTTP 的动作都不必进队列
        if (!ActionRunner::has($action)) {
            $connection->send(self::json(400, Message::CODE_UNKNOWN_CMD, '未知业务动作：' . $action));

            return;
        }
        if (!ActionRunner::httpExposed($action)) {
            $connection->send(self::json(
                400,
                Message::CODE_UNKNOWN_CMD,
                '动作未开放 HTTP 通道：' . $action . '（可用：' . implode(' / ', ActionRunner::httpActions()) . '）'
            ));

            return;
        }

        $decl = ActionRunner::declaration($action);
        $uid      = isset($job['uid']) ? trim((string)$job['uid']) : '';
        $deviceId = isset($job['device_id']) ? (string)$job['device_id'] : '';

        // uid 由请求方在 body 中给出，但它参与 HMAC 签名覆盖 —— 即「密钥持有者
        // 可代表任意 uid 发起动作」，与 /push 同权，不构成提权。
        if (!empty($decl['auth']) && $uid === '') {
            $connection->send(self::json(401, Message::CODE_UNAUTHORIZED, '该动作要求身份，请提供 uid'));

            return;
        }

        $params = $job['params'] ?? [];
        if (!is_array($params)) {
            $connection->send(self::json(400, self::CODE_BAD_PARAM, 'params 必须是 JSON 对象'));

            return;
        }

        $packet = Message::packet(Message::CMD_DATA, [
            'action' => $action,
            'params' => $params,
        ], [
            'uid'       => $uid,
            'device_id' => $deviceId,
        ]);

        $requestId = bin2hex(random_bytes(8));

        self::admitAction($requestId, $packet, function ($code, $msg) use (
            $connection,
            $requestId,
            $action,
            $uid,
            $deviceId,
            $params
        ) {
            if ($code !== self::CODE_OK) {
                $connection->send(self::json(503, $code, $msg, null, 503));

                return;
            }

            Logger::debug('HTTP 动作已受理', [
                'request_id' => $requestId,
                'action'     => $action,
                'uid'        => $uid,
                'device_id'  => $deviceId,
                'params'     => $params,
            ]);

            self::waitForActionResult($connection, $requestId, $action);
        });
    }

    /**
     * 动作结果补查（GET /action/{id}）
     *
     * @param mixed  $connection
     * @param string $requestId
     *
     * @return void
     */
    protected static function handleActionResult($connection, $requestId)
    {
        $requestId = trim((string)$requestId);

        if (!ActionReply::validRequestId($requestId)) {
            $connection->send(self::json(400, self::CODE_BAD_PARAM, 'request_id 格式非法', null, 400));

            return;
        }

        ActionReply::fetch($requestId, function ($packet) use ($connection, $requestId) {
            if (!is_array($packet)) {
                $connection->send(self::json(404, self::CODE_NOT_FOUND, '动作结果不存在或已过期', [
                    'request_id' => $requestId,
                    'status'     => 'pending',
                    'hint'       => '任务可能仍在执行中，或结果已超过 ACTION_RESULT_TTL 被回收',
                ], 404));

                return;
            }

            $connection->send(self::actionResponse($requestId, $packet));
        });
    }

    /**
     * 动作任务入队（含队列积压准入控制）
     *
     * 先 LLEN 再 RPUSH，两步之间存在微小竞态 —— 这是准入控制的固有代价，
     * 但方向是安全的：并发下最多多放行几条，不会让队列无界增长。
     *
     * @param string               $requestId
     * @param array<string, mixed> $packet
     * @param callable             $cb        function(int $code, string $msg)  0 表示受理成功
     *
     * @return void
     */
    protected static function admitAction($requestId, array $packet, callable $cb)
    {
        $conf = self::$businessConfig['action_queue'] ?? [];

        if (empty($conf['enable'])) {
            $cb(self::CODE_SERVER_ERROR, '动作队列未启用（ACTION_QUEUE_ENABLE=false）');

            return;
        }

        $key    = (string)$conf['key'];
        $maxLen = (int)$conf['max_len'];

        $enqueue = function () use ($key, $requestId, $packet, $cb) {
            $job = [
                'request_id' => $requestId,
                'packet'     => $packet,
                'uid'        => isset($packet['uid']) ? (string)$packet['uid'] : '',
                'device_id'  => isset($packet['device_id']) ? (string)$packet['device_id'] : '',
                'source'     => 'http',
                'channel'    => 'http',
                'enqueue_at' => microtime(true),
            ];

            $raw = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($raw === false) {
                Logger::error('HTTP 动作任务序列化失败', ['request_id' => $requestId]);
                $cb(self::CODE_SERVER_ERROR, '动作任务序列化失败');

                return;
            }

            RedisClient::rPush($key, $raw, function ($result) use ($requestId, $cb) {
                if (!is_int($result)) {
                    Logger::error('HTTP 动作任务入队失败', ['request_id' => $requestId]);
                    $cb(self::CODE_SERVER_ERROR, '动作任务入队失败');

                    return;
                }
                $cb(self::CODE_OK, '');
            });
        };

        if ($maxLen <= 0) {
            $enqueue();

            return;
        }

        RedisClient::lLen($key, function ($len) use ($maxLen, $enqueue, $cb, $key) {
            if (is_int($len) && $len >= $maxLen) {
                Logger::warn('动作队列积压超限，请求被拒绝', [
                    'queue' => $key,
                    'len'   => $len,
                    'max'   => $maxLen,
                ]);
                $cb(self::CODE_OVERLOAD, '动作队列积压超限，请稍后重试');

                return;
            }
            $enqueue();
        });
    }

    /**
     * 轮询取回动作结果
     *
     * 轮询而非阻塞订阅（BLPOP / SUBSCRIBE）的原因：本项目 Redis 连接池
     * 无顺序保证（见硬约束 ⑮），阻塞式取用会独占连接，并发在途请求一旦
     * 超过 pool_size 即耗尽连接池。轮询的代价只是若干次 GET，可忽略。
     *
     * 超窗后不视为失败：任务仍在执行，结果稍后写入 action:result:{id}，
     * 调用方可凭 request_id 经 GET /action/{id} 补查。
     *
     * @param mixed  $connection
     * @param string $requestId
     * @param string $action     仅用于日志
     *
     * @return void
     */
    protected static function waitForActionResult($connection, $requestId, $action)
    {
        $waitMs   = max(0, (int)self::$config['action_wait']);
        $deadline = microtime(true) + $waitMs / 1000;
        $interval = self::POLL_MIN_MS;

        // $timerId 在下方闭包内被赋值，静态分析无法跨闭包推断其可空性，
        // 故显式声明类型，避免「与 null 比较恒为假」的误报
        /** @var null|int $timerId */
        $timerId  = null;
        $finished = false;

        // 客户端提前断开时立即停止轮询，避免为已离开的调用方空转
        $stop = function () use (&$timerId) {
            if ($timerId !== null) {
                Timer::del($timerId);
                $timerId = null;
            }
        };
        $connection->onClose = function () use ($stop, &$finished) {
            $finished = true;
            $stop();
        };

        $poll = function () use (&$poll, &$timerId, &$interval, &$finished, $connection, $requestId, $action, $deadline, $stop) {
            if ($finished) {
                return;
            }

            ActionReply::fetch($requestId, function ($packet) use (&$poll, &$timerId, &$interval, &$finished, $connection, $requestId, $action, $deadline, $stop) {
                if ($finished) {
                    return;
                }

                if (is_array($packet)) {
                    $finished = true;
                    $stop();
                    $connection->send(self::actionResponse($requestId, $packet));

                    return;
                }

                if (microtime(true) >= $deadline) {
                    $finished = true;
                    $stop();
                    Logger::warn('HTTP 动作未在等待窗内完成，降级为 202', [
                        'request_id' => $requestId,
                        'action'     => $action,
                        'waited_ms'  => (int)self::$config['action_wait'],
                    ]);
                    $connection->send(self::json(202, self::CODE_OK, 'accepted', [
                        'request_id' => $requestId,
                        'action'     => $action,
                        'status'     => 'pending',
                        'result_url' => '/action/' . $requestId,
                    ], 202));

                    return;
                }

                // 退避：5 次以内的密集轮询足以覆盖「处理器同步回执」的常见路径，
                // 之后逐步放宽，避免长尾请求持续打满 Redis
                $interval = min(self::POLL_MAX_MS, (int)ceil($interval * 1.5));
                $timerId  = Timer::add($interval / 1000, $poll, [], false);
            });
        };

        $poll();
    }

    /**
     * 把动作回执报文包装为 HTTP 响应
     *
     * 成功（cmd=ack）→ HTTP 200 / code 0，动作数据体置于 data.result；
     * 失败（cmd=error）→ **HTTP 200** / code 取报文内的业务码，理由见类注释。
     *
     * @param string               $requestId
     * @param array<string, mixed> $packet
     *
     * @return Response
     */
    protected static function actionResponse($requestId, array $packet)
    {
        $cmd  = isset($packet['cmd']) ? (string)$packet['cmd'] : '';
        $data = isset($packet['data']) && is_array($packet['data']) ? $packet['data'] : [];

        if ($cmd === Message::CMD_ACK) {
            return self::json(200, self::CODE_OK, 'ok', [
                'request_id' => $requestId,
                'status'     => 'done',
                'result'     => $data,
                'packet'     => $packet,
            ]);
        }

        $code = isset($data['code']) ? (int)$data['code'] : self::CODE_SERVER_ERROR;
        $msg  = isset($data['msg']) ? (string)$data['msg'] : '动作执行失败';

        return self::json(200, $code, $msg, [
            'request_id' => $requestId,
            'status'     => 'failed',
            'packet'     => $packet,
        ]);
    }

    /**
     * 已开放 HTTP 通道的动作中最长的回执超时（秒）
     *
     * @return int
     */
    protected static function longestActionTimeout()
    {
        $max = 0;
        foreach (ActionRunner::httpActions() as $name) {
            $decl = ActionRunner::declaration($name);
            if (is_array($decl) && (int)$decl['timeout'] > $max) {
                $max = (int)$decl['timeout'];
            }
        }

        return $max;
    }

    /* ---------------------------------------------------------------------
     | 鉴权
     | --------------------------------------------------------------------- */

    /**
     * 请求鉴权
     *
     * @param Request $request
     *
     * @return null|Response 通过校验返回 null，否则返回错误响应
     */
    protected static function authenticate(Request $request)
    {
        $free   = !self::signEnabled();
        $secret = self::apiSecret();

        // 免签模式下密钥不参与校验，故「未配置密钥」不再是拒绝理由
        if (!$free && $secret === '') {
            return self::json(500, self::CODE_SERVER_ERROR, '服务端未配置接口密钥', null, 500);
        }

        // 限流前置：避免无效请求持续消耗验签开销。
        // 免签模式下同样保留 —— 限流是防误压/防扫描的最后一道闸，与鉴权是两件事。
        if (!self::rateLimit($request)) {
            Logger::warn('HTTP 请求频率超限', [
                'method' => $request->method(),
                'path'   => $request->path(),
                'ip'     => self::clientIp($request),
                'rate'   => (int)self::$config['rate'],
            ]);

            return self::json(429, self::CODE_RATE_LIMIT, '请求频率超限', null, 429);
        }

        if ($free) {
            return null;
        }

        $timestamp = self::header($request, 'x-timestamp');
        $sign      = self::header($request, 'x-sign');

        if ($timestamp === '' || $sign === '') {
            Logger::warn('接口验签失败', [
                'reason' => 'missing_headers',
                'method' => $request->method(),
                'path'   => $request->path(),
                'ip'     => self::clientIp($request),
            ]);

            return self::json(401, self::CODE_BAD_SIGN, '缺少 X-Timestamp 或 X-Sign 请求头', null, 401);
        }

        $ts = (int)$timestamp;
        if ($ts <= 0) {
            Logger::warn('接口验签失败', [
                'reason' => 'bad_timestamp',
                'method' => $request->method(),
                'path'   => $request->path(),
                'ip'     => self::clientIp($request),
            ]);

            return self::json(401, self::CODE_BAD_SIGN, 'X-Timestamp 非法', null, 401);
        }

        $ttl = (int)self::$config['sign_ttl'];
        if ($ttl > 0 && abs(time() - $ts) > $ttl) {
            Logger::warn('接口验签失败', [
                'reason' => 'timestamp_expired',
                'method' => $request->method(),
                'path'   => $request->path(),
                'ip'     => self::clientIp($request),
                'ts'     => $ts,
                'now'    => time(),
            ]);

            return self::json(401, self::CODE_EXPIRED, '请求时间戳超出允许窗口', null, 401);
        }

        $expect = hash_hmac('sha256', $timestamp . '|' . $request->rawBody(), $secret);
        if (!hash_equals($expect, strtolower($sign))) {
            Logger::warn('接口验签失败', [
                'reason' => 'signature_mismatch',
                'method' => $request->method(),
                'path'   => $request->path(),
                'ip'     => self::clientIp($request),
                'ts'     => $ts,
            ]);

            return self::json(401, self::CODE_BAD_SIGN, '签名校验失败', null, 401);
        }

        return null;
    }

    /**
     * 接口验签是否启用
     *
     * 关闭验签需同时满足两个条件：
     *   ① 配置显式关闭（api.sign_enable = false，即 API_SIGN_ENABLE=false）
     *   ② 监听地址为回环（127.0.0.0/8 / ::1 / localhost）
     *
     * 条件 ② 是刻意设的硬护栏：/push 可推任意消息、/action 可执行已开放动作，
     * 一旦接口对外监听，无鉴权就等于业务入口裸奔。故即便 .env 被误改，
     * 只要不是本机回环监听就仍然强制验签 —— 不因一处配置失误而开口子。
     *
     * @return bool
     */
    protected static function signEnabled()
    {
        // 必须显式判键是否存在：empty() 区分不了「键缺失」与「显式 false」，
        // 而键缺失（早期 .env 未含该项、或调用方传入精简配置）的语义是「开启」。
        // 只判 empty() 会让缺键等同于关闭 —— 一个静默扩大开放面的失配。
        $explicitOff = array_key_exists('sign_enable', self::$config)
            && empty(self::$config['sign_enable']);

        if (!$explicitOff) {
            return true;
        }

        return !self::isLoopbackHost(isset(self::$config['listen']) ? (string)self::$config['listen'] : '');
    }

    /**
     * 接口密钥：api.secret 留空时回退复用 auth.secret
     *
     * @return string
     */
    protected static function apiSecret()
    {
        $secret = (string)self::$config['secret'];
        if ($secret === '') {
            $secret = isset(self::$appConfig['auth']['secret']) ? (string)self::$appConfig['auth']['secret'] : '';
        }

        return $secret;
    }

    /**
     * 单 IP 滑动分钟限流
     *
     * @param Request $request
     *
     * @return bool 是否放行
     */
    protected static function rateLimit(Request $request)
    {
        $limit = (int)self::$config['rate'];
        if ($limit <= 0) {
            return true;
        }

        $ip   = self::clientIp($request);
        $slot = (int)floor(time() / 60);
        $key  = RedisKeys::rateApi($ip, $slot);

        // 同步语义：单进程内用静态计数兜底，避免依赖异步回调造成误判。
        // 键含分钟槽位，跨槽后旧键永不再被读取 —— 不清即在常驻进程里无界累积。
        static $local     = [];
        static $localSlot = -1;
        if ($slot !== $localSlot) {
            $localSlot = $slot;
            $local     = [];
        }
        if (!isset($local[$key])) {
            $local[$key] = 0;
        }
        $local[$key]++;

        if ($local[$key] > $limit) {
            return false;
        }

        RedisClient::incr($key, 1, function ($count) use ($key) {
            if ((int)$count === 1) {
                RedisClient::expire($key, 120);
            }
        });

        return true;
    }

    /* ---------------------------------------------------------------------
     | 内部辅助
     | --------------------------------------------------------------------- */

    /**
     * 请求访问日志（debug）
     *
     * @param Request              $request
     * @param string               $stage   日志标题后缀，便于多阶段对照
     * @param array<string, mixed> $extra   额外字段（如业务侧 request_id）
     *
     * @return void
     */
    protected static function accessLog(Request $request, $stage = '', array $extra = [])
    {
        $context = array_merge(self::requestLogContext($request), $extra);
        Logger::debug($stage === '' ? 'HTTP 请求' : 'HTTP ' . $stage, $context);
    }

    /**
     * 构建访问日志上下文（纯组装，不写日志）
     *
     * - headers：全量保留但对签名 / 凭据类做脱敏；
     * - body：截到 ACCESS_LOG_BODY_MAX，超长时附原始长度，避免 Logger
     *   的 context 上限把整条日志硬截成半截 JSON；
     * - query：原始查询串（GET 补查 / action/{id} 常靠它定位参数）。
     *
     * @param Request $request
     *
     * @return array<string, mixed>
     */
    protected static function requestLogContext(Request $request)
    {
        $body = $request->rawBody();
        $len  = strlen($body);
        if ($len > self::ACCESS_LOG_BODY_MAX) {
            $body = substr($body, 0, self::ACCESS_LOG_BODY_MAX)
                . '...(truncated, total=' . $len . ')';
        }

        return [
            'method'  => $request->method(),
            'path'    => $request->path(),
            'query'   => $request->queryString(),
            'ip'      => self::clientIp($request),
            'headers' => self::redactHeaders($request->header()),
            'body'    => $body,
            'body_len' => $len,
        ];
    }

    /**
     * 请求头脱敏
     *
     * 保留头名与长度信息（便于判断「签没带」与「签带了但错了」），
     * 值只留前 8 字符 —— 足够比对是否与本地算出的签名同源，又无法直接重放。
     *
     * @param mixed $headers header() 原样返回值（array|null|string 混杂）
     *
     * @return array<string, string>
     */
    protected static function redactHeaders($headers)
    {
        if (!is_array($headers)) {
            return [];
        }

        $safe = [];
        foreach ($headers as $name => $value) {
            $key = strtolower((string)$name);
            if (is_array($value)) {
                // workerman 对重复头会收成数组，取首个标量拼接即可
                $parts = [];
                foreach ($value as $item) {
                    if (is_scalar($item)) {
                        $parts[] = (string)$item;
                    }
                }
                $raw = implode(',', $parts);
            } else {
                $raw = is_scalar($value) ? (string)$value : '';
            }

            $safe[$key] = in_array($key, self::$redactHeaders, true)
                ? self::redactSecret($raw)
                : $raw;
        }

        return $safe;
    }

    /**
     * 凭据类值脱敏：留前 8 字符 + 总长
     *
     * @param string $value
     *
     * @return string
     */
    protected static function redactSecret($value)
    {
        $value = (string)$value;
        $len   = strlen($value);
        if ($len <= 8) {
            return '***len=' . $len;
        }

        return substr($value, 0, 8) . '***len=' . $len;
    }

    /**
     * 大小写不敏感读取请求头
     *
     * @param Request $request
     * @param string  $name
     *
     * @return string
     */
    protected static function header(Request $request, $name)
    {
        $headers = $request->header();
        if (!is_array($headers)) {
            return '';
        }
        foreach ($headers as $key => $value) {
            if (strcasecmp((string)$key, (string)$name) === 0) {
                return is_array($value) ? (string)reset($value) : (string)$value;
            }
        }

        return '';
    }

    /**
     * 客户端 IP
     *
     * @param Request $request
     *
     * @return string
     */
    protected static function clientIp(Request $request)
    {
        $forwarded = self::header($request, 'x-forwarded-for');
        if ($forwarded !== '') {
            $parts = explode(',', $forwarded);

            return trim($parts[0]);
        }

        // 单元测试里 Request 未挂 connection；线上由 workerman 赋值
        if ($request->connection === null) {
            return '';
        }

        return $request->connection->getRemoteIp();
    }

    /**
     * 构造 JSON 响应
     *
     * @param int                       $status HTTP 状态码
     * @param int                       $code   业务码
     * @param string                    $msg
     * @param null|array<string, mixed> $data
     * @param null|int                  $http
     *
     * @return Response
     */
    protected static function json($status, $code, $msg, $data = null, $http = null)
    {
        $body = [
            'code' => (int)$code,
            'msg'  => (string)$msg,
            'ts'   => time(),
        ];
        if ($data !== null) {
            $body['data'] = $data;
        }

        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            $payload = '{"code":5000,"msg":"response encode failed"}';
        }

        return new Response($http ?? $status, [
            'Content-Type' => 'application/json; charset=utf-8',
        ], $payload);
    }

    /**
     * 判断当前启动角色是否包含接口进程
     *
     * 与 Business / Gateway 两处同名方法保持一致：角色名由调用方给出，
     * 不得硬编码 —— 否则他处误传角色名会静默返回错误结果。
     *
     * @param string $role
     *
     * @return bool
     */
    protected static function roleEnabled($role)
    {
        $current = defined('APP_ROLE') ? APP_ROLE : 'all';

        return $current === 'all' || $current === $role;
    }
}
