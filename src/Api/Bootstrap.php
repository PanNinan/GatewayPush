<?php
/**
 * HTTP 推送接口（独立进程，role=api）
 *
 * ---------------------------------------------------------------------
 * 职责边界
 * ---------------------------------------------------------------------
 * 只做三件事：验签 -> 参数校验 -> 推送任务入队，随后立即返回。
 * 不持有 Gateway 连接、不直接投递、不感知会话状态 —— 真正的推送由业务进程
 * 消费队列后执行（Push::dispatch），保证指标统计与幂等控制只有一条执行路径。
 *
 * 因此本进程与 Gateway / BusinessWorker 之间不产生任何内部通信依赖，
 * 只需 Redis 连接；即便推送后端短暂不可用，接口仍可正常受理并缓冲任务。
 *
 * ---------------------------------------------------------------------
 * 接口清单
 * ---------------------------------------------------------------------
 *   GET  /health          免鉴权，存活探测
 *   GET  /stats           需鉴权，返回 Redis 中的指标快照
 *   POST /push            需鉴权，提交定向推送任务
 *
 * ---------------------------------------------------------------------
 * 鉴权
 * ---------------------------------------------------------------------
 *   X-Timestamp  Unix 秒，与服务端偏差需在 api.sign_ttl 内（防重放）
 *   X-Sign       hex(hmac_sha256("{X-Timestamp}|{原始请求体}", api.secret))
 *
 * 采用原始请求体参与签名（而非解析后的数组），避免键序/转义差异导致的验签失败。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Api;

use GatewayPush\Business\Monitor;
use GatewayPush\Business\Push;
use GatewayPush\Common\Logger;
use GatewayPush\Common\RedisClient;
use GatewayPush\Common\WorkerEvents;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

class Bootstrap
{
    /** 业务返回码 */
    const CODE_OK           = 0;
    const CODE_BAD_PARAM    = 4000;
    const CODE_BAD_SIGN     = 4001;
    const CODE_EXPIRED      = 4002;
    const CODE_NOT_FOUND    = 4004;
    const CODE_RATE_LIMIT   = 4029;
    const CODE_SERVER_ERROR = 5000;

    /**
     * api 配置（app.api）
     *
     * @var array
     */
    protected static $config = array(
        'enable'   => true,
        'listen'   => 'http://127.0.0.1:8290',
        'name'     => 'GW-API',
        'secret'   => '',
        'sign_ttl' => 300,
        'rate'     => 600,
        'body_max' => 65536,
    );

    /**
     * app.php 配置（Redis 等）
     *
     * @var array
     */
    protected static $appConfig = array();

    /**
     * 初始化 HTTP 接口进程
     *
     * @param array $appConfig      config/app.php
     * @param array $businessConfig config/business.php
     * @return void
     */
    public static function init(array $appConfig, array $businessConfig)
    {
        if (!self::roleEnabled('api')) {
            return;
        }

        self::$appConfig  = $appConfig;
        self::$config     = array_merge(self::$config, $appConfig['api']);

        if (empty(self::$config['enable'])) {
            return;
        }

        Push::init($appConfig['push'], $businessConfig['push_queue']);

        $worker = new Worker(self::$config['listen']);
        $worker->name  = self::$config['name'];
        $worker->count = 1;   // 接口层无状态且轻量，单进程足够；Windows 下亦强制为 1

        // Worker('http://...') 会自动使用 Workerman\Protocols\Http，
        // onMessage 收到的即为已解析的 Request 对象
        $worker->onMessage = array(self::class, 'onRequest');

        $worker->onWorkerStart = function ($worker) {
            RedisClient::init(self::$appConfig['redis']);
            Monitor::init(self::$appConfig['monitor']);

            $secret = self::apiSecret();

            Logger::info('HTTP 推送接口已启动', array(
                'listen'      => $worker->getSocketName(),
                'sign_ttl'    => (int)self::$config['sign_ttl'],
                'rate_limit'  => (int)self::$config['rate'],
                'secret_set'  => $secret !== '' ? 1 : 0,
            ));

            if ($secret === '') {
                Logger::warn('接口密钥为空，所有请求都会被拒绝。请配置 API_SECRET（留空时会回退复用 AUTH_SECRET）');
            }
        };

        $worker->onWorkerStop = function ($worker) {
            Logger::info('HTTP 推送接口正在停止', array('id' => $worker->id));
            RedisClient::closeAll();
        };

        // HTTP 短连接存在发送缓冲，背压事件一并绑定
        WorkerEvents::bind($worker, self::$config['name']);
    }

    /**
     * 请求入口
     *
     * @param mixed $connection
     * @param mixed $request Workerman\Protocols\Http\Request
     * @return void
     */
    public static function onRequest($connection, $request)
    {
        try {
            if (!$request instanceof Request) {
                $connection->send(self::json(500, self::CODE_SERVER_ERROR, '协议解析异常', null, 500));
                return;
            }

            $method = $request->method();
            $path   = $request->path();

            // 存活探测：免鉴权，供负载均衡 / 容器探针使用
            if ($method === 'GET' && $path === '/health') {
                $connection->send(self::json(200, self::CODE_OK, 'ok', array(
                    'service' => 'gateway-push-api',
                    'time'    => time(),
                )));
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

            $connection->send(self::json(404, self::CODE_NOT_FOUND, '接口不存在：' . $method . ' ' . $path, null, 404));
        } catch (\Throwable $e) {
            Logger::exception($e, 'api.on_request');
            $connection->send(self::json(500, self::CODE_SERVER_ERROR, '服务端内部错误', null, 500));
        }
    }

    /* ---------------------------------------------------------------------
     | 接口实现
     | --------------------------------------------------------------------- */

    /**
     * 推送任务受理
     *
     * @param mixed   $connection
     * @param Request $request
     * @return void
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
        $payload    = $job['payload'] ?? array();

        if (!in_array($targetType, array(Push::TARGET_UID, Push::TARGET_DEVICE, Push::TARGET_CLIENT), true)) {
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

        $opts = array(
            'msg_id'       => isset($job['msg_id']) ? (string)$job['msg_id'] : '',
            'offline_mode' => isset($job['offline_mode']) ? (string)$job['offline_mode'] : '',
            'source'       => 'http',
        );

        Push::enqueue($targetType, $target, $payload, $opts, function ($ok) use ($connection, $targetType, $target, $opts) {
            if (!$ok) {
                $connection->send(self::json(500, self::CODE_SERVER_ERROR, '推送任务入队失败', null, 500));
                return;
            }
            $connection->send(self::json(200, self::CODE_OK, 'accepted', array(
                'target_type'  => $targetType,
                'target'       => $target,
                'msg_id'       => $opts['msg_id'],
                'offline_mode' => $opts['offline_mode'] !== '' ? $opts['offline_mode'] : Push::offlineMode(),
            )));
        });
    }

    /**
     * 指标快照
     *
     * @param mixed $connection
     * @return void
     */
    protected static function handleStats($connection)
    {
        Monitor::snapshot(function ($snapshot) use ($connection) {
            $connection->send(self::json(200, self::CODE_OK, 'ok', $snapshot));
        });
    }

    /* ---------------------------------------------------------------------
     | 鉴权
     | --------------------------------------------------------------------- */

    /**
     * 请求鉴权
     *
     * @param Request $request
     * @return Response|null 通过校验返回 null，否则返回错误响应
     */
    protected static function authenticate(Request $request)
    {
        $secret = self::apiSecret();
        if ($secret === '') {
            return self::json(500, self::CODE_SERVER_ERROR, '服务端未配置接口密钥', null, 500);
        }

        // 限流前置：避免无效请求持续消耗验签开销
        if (!self::rateLimit($request)) {
            return self::json(429, self::CODE_RATE_LIMIT, '请求频率超限', null, 429);
        }

        $timestamp = self::header($request, 'x-timestamp');
        $sign      = self::header($request, 'x-sign');

        if ($timestamp === '' || $sign === '') {
            return self::json(401, self::CODE_BAD_SIGN, '缺少 X-Timestamp 或 X-Sign 请求头', null, 401);
        }

        $ts = (int)$timestamp;
        if ($ts <= 0) {
            return self::json(401, self::CODE_BAD_SIGN, 'X-Timestamp 非法', null, 401);
        }

        $ttl = (int)self::$config['sign_ttl'];
        if ($ttl > 0 && abs(time() - $ts) > $ttl) {
            return self::json(401, self::CODE_EXPIRED, '请求时间戳超出允许窗口', null, 401);
        }

        $expect = hash_hmac('sha256', $timestamp . '|' . $request->rawBody(), $secret);
        if (!hash_equals($expect, strtolower($sign))) {
            Logger::warn('接口验签失败', array(
                'path' => $request->path(),
                'ip'   => self::clientIp($request),
            ));
            return self::json(401, self::CODE_BAD_SIGN, '签名校验失败', null, 401);
        }

        return null;
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
     * @return bool 是否放行
     */
    protected static function rateLimit(Request $request)
    {
        $limit = (int)self::$config['rate'];
        if ($limit <= 0) {
            return true;
        }

        $ip  = self::clientIp($request);
        $key = 'api:rate:' . md5($ip) . ':' . floor(time() / 60);

        // 同步语义：单进程内用静态计数兜底，避免依赖异步回调造成误判
        static $local = array();
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
     * 大小写不敏感读取请求头
     *
     * @param Request $request
     * @param string  $name
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
     * @return string
     */
    protected static function clientIp(Request $request)
    {
        $forwarded = self::header($request, 'x-forwarded-for');
        if ($forwarded !== '') {
            $parts = explode(',', $forwarded);
            return trim($parts[0]);
        }
        return (string)$request->connection->getRemoteIp();
    }

    /**
     * 构造 JSON 响应
     *
     * @param int         $status HTTP 状态码
     * @param int         $code   业务码
     * @param string      $msg
     * @param array|null  $data
     * @param int|null    $http
     * @return Response
     */
    protected static function json($status, $code, $msg, $data = null, $http = null)
    {
        $body = array(
            'code' => (int)$code,
            'msg'  => (string)$msg,
            'ts'   => time(),
        );
        if ($data !== null) {
            $body['data'] = $data;
        }

        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            $payload = '{"code":5000,"msg":"response encode failed"}';
        }

        return new Response($http ?? $status, array(
            'Content-Type' => 'application/json; charset=utf-8',
        ), $payload);
    }

    /**
     * 判断当前启动角色是否包含接口进程
     *
     * 与 Business / Gateway 两处同名方法保持一致：角色名由调用方给出，
     * 不得硬编码 —— 否则他处误传角色名会静默返回错误结果。
     *
     * @param string $role
     * @return bool
     */
    protected static function roleEnabled($role)
    {
        $current = defined('APP_ROLE') ? APP_ROLE : 'all';
        return $current === 'all' || $current === $role;
    }
}
