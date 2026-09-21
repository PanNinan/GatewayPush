<?php
/**
 * 监控面板（独立进程，role=dashboard）
 *
 * ---------------------------------------------------------------------
 * 职责边界
 * ---------------------------------------------------------------------
 * 只做一件事：把 Redis 中已有的监控指标渲染成可读页面。
 *
 * 本进程是**纯只读**的 —— 不写任何 Redis 键、不持有推送密钥、不参与
 * 业务链路，因此它的故障与资源占用都不会影响推送与网关。这一点与
 * Api 进程刻意分开：Api 的职责是「验签 -> 校验 -> 入队」，塞入展示逻辑
 * 会破坏其权限边界（详见 Api\Bootstrap 头部说明）。
 *
 * ---------------------------------------------------------------------
 * 数据来源（全部由 Business 进程的定时任务写入，见 Business\Monitor）
 * ---------------------------------------------------------------------
 *   metrics:gauge            Hash  瞬时指标 + 各进程内存 + 定时任务健康度，TTL 短
 *   metrics:counter:YYYYMMDD Hash  当日累加指标，保留 7 天
 *
 * ---------------------------------------------------------------------
 * 接口清单
 * ---------------------------------------------------------------------
 *   GET /              自包含 HTML 页面（零外链，可离线打开）
 *   GET /metrics.json  页面同源使用的 JSON 快照
 *
 * 两个接口均免鉴权，安全边界由监听地址承担（默认仅 127.0.0.1）。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Dashboard;

use GatewayPush\Business\Monitor;
use GatewayPush\Common\Logger;
use GatewayPush\Common\RedisClient;
use GatewayPush\Common\WorkerEvents;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

class Bootstrap
{
    /** 业务返回码（与 Api 进程保持同一取值空间，便于统一排查） */
    const CODE_OK           = 0;
    const CODE_NOT_FOUND    = 4004;
    const CODE_SERVER_ERROR = 5000;

    /**
     * dashboard 配置（app.dashboard）
     *
     * @var array
     */
    protected static $config = array(
        'enable'    => true,
        'listen'    => 'http://127.0.0.1:8291',
        'name'      => 'GW-DASH',
        'view_path' => '',
        'refresh'   => 30,
    );

    /**
     * app.php 配置（Redis / 监控等）
     *
     * @var array
     */
    protected static $appConfig = array();

    /**
     * 页面模板缓存
     *
     * 记录 mtime 以便模板被修改后自动失效 —— 调整面板样式是高频操作，
     * 不该每次都要求重启进程。每个请求多一次 stat，代价可忽略。
     *
     * @var array{content: string, mtime: int}
     */
    protected static $page = array('content' => '', 'mtime' => 0);

    /**
     * 初始化监控面板进程
     *
     * @param array $appConfig config/app.php
     * @return void
     */
    public static function init(array $appConfig)
    {
        if (!self::roleEnabled('dashboard')) {
            return;
        }

        self::$appConfig = $appConfig;
        self::$config    = array_merge(self::$config, $appConfig['dashboard']);

        if (empty(self::$config['enable'])) {
            return;
        }

        $worker = new Worker(self::$config['listen']);
        $worker->name  = self::$config['name'];
        $worker->count = 1;   // 展示层无状态，单进程足够；Windows 下亦强制为 1

        $worker->onMessage = array(self::class, 'onRequest');

        $worker->onWorkerStart = function ($worker) {
            Logger::useChannel('dashboard');

            RedisClient::init(self::$appConfig['redis']);
            Monitor::init(self::$appConfig['monitor']);

            Logger::info('监控面板已启动', array(
                'listen'  => $worker->getSocketName(),
                'refresh' => (int)self::$config['refresh'],
            ));

            $page = self::pagePath();
            if (!is_file($page)) {
                Logger::warn('监控面板模板缺失，页面将返回 500', array('path' => $page));
            }
        };

        $worker->onWorkerStop = function ($worker) {
            Logger::info('监控面板正在停止', array('id' => $worker->id));
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

            if ($method === 'GET' && ($path === '/' || $path === '/index.html')) {
                $connection->send(self::html());
                return;
            }

            if ($method === 'GET' && $path === '/metrics.json') {
                self::handleMetrics($connection);
                return;
            }

            $connection->send(self::json(404, self::CODE_NOT_FOUND, '接口不存在：' . $method . ' ' . $path, null, 404));
        } catch (\Throwable $e) {
            Logger::exception($e, 'dashboard.on_request');
            $connection->send(self::json(500, self::CODE_SERVER_ERROR, '服务端内部错误', null, 500));
        }
    }

    /* ---------------------------------------------------------------------
     | 接口实现
     | --------------------------------------------------------------------- */

    /**
     * 指标快照
     *
     * 在 Monitor::snapshot() 的基础上附加 meta 段：页面需要知道上报周期与
     * gauge 的 TTL，才能判断当前数据是「新鲜」「滞后」还是「已过期」。
     *
     * @param mixed $connection
     * @return void
     */
    protected static function handleMetrics($connection)
    {
        Monitor::snapshot(function ($snapshot) use ($connection) {
            $monitor = self::$appConfig['monitor'] ?? array();

            $snapshot['meta'] = array(
                'now'      => time(),
                'interval' => isset($monitor['interval']) ? (int)$monitor['interval'] : 60,
                'ttl'      => isset($monitor['ttl']) ? (int)$monitor['ttl'] : 600,
                'enable'   => !empty($monitor['enable']),
                'refresh'  => (int)self::$config['refresh'],
            );

            $connection->send(self::json(200, self::CODE_OK, 'ok', $snapshot));
        });
    }

    /**
     * 返回页面模板
     *
     * @return Response
     */
    protected static function html()
    {
        $path = self::pagePath();

        // workerman 常驻进程没有 PHP 的请求边界，stat 缓存不会被自动清理，
        // filemtime() 会一直返回进程首次 stat 时的旧值，导致「模板已改、mtime
        // 不变、永不重载」。必须显式清理，否则下面的 mtime 失效逻辑形同虚设。
        clearstatcache(true, $path);

        $mtime = is_file($path) ? (int)filemtime($path) : 0;
        if ($mtime !== self::$page['mtime']) {
            $content = $mtime > 0 ? file_get_contents($path) : false;
            self::$page = array(
                'content' => ($content === false) ? '' : $content,
                'mtime'   => $mtime,
            );
        }

        if (self::$page['content'] === '') {
            return self::json(500, self::CODE_SERVER_ERROR, '页面模板缺失：' . $path, null, 500);
        }

        return new Response(200, array(
            'Content-Type'  => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
        ), self::$page['content']);
    }

    /* ---------------------------------------------------------------------
     | 内部辅助
     | --------------------------------------------------------------------- */

    /**
     * 页面模板绝对路径
     *
     * @return string
     */
    protected static function pagePath()
    {
        $dir = (string)self::$config['view_path'];
        return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'index.html';
    }

    /**
     * 判断当前启动角色是否包含面板进程
     *
     * 与 Business / Gateway / Api 三处同名方法保持一致：角色名由调用方给出，
     * 不得硬编码。
     *
     * @param string $role
     * @return bool
     */
    protected static function roleEnabled($role)
    {
        $current = defined('APP_ROLE') ? APP_ROLE : 'all';
        return $current === 'all' || $current === $role;
    }

    /**
     * 构造 JSON 响应
     *
     * @param int         $status
     * @param int         $code
     * @param string      $msg
     * @param mixed       $data
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
}
