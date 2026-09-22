<?php
/**
 * Worker 事件统一绑定
 *
 * 为全部 Worker 实例补齐「连接级异常」与「发送背压」观测能力，避免各 Bootstrap
 * 重复编写同样的回调。绑定是幂等的，重复调用不会覆盖已有处理器。
 *
 * ---------------------------------------------------------------------
 * 关于 onWorkerError 的说明（重要）
 * ---------------------------------------------------------------------
 * workerman 4.x 提供 Worker::$onWorkerError（进程级错误回调），
 * 但 **workerman 5.x 已将其移除**（5.2.2 全库检索无任何触发点）。
 * 由于 Worker 类带 #[AllowDynamicProperties]，给它赋值不会报错，
 * 但回调永远不会被触发 —— 属于静默失效的死代码，故本项目不绑定该事件。
 *
 * 进程级致命错误的兜底改由以下两条链路承担：
 *   1. Logger::registerHandlers()：error / exception / shutdown 三重处理器
 *      （见 start.php 中 app.log.global_handler 开关）；
 *   2. workerman 自身：Worker::stop() 与协程包装层以 static::log($e)
 *      将未捕获异常写入 runtime/logs/workerman.log。
 *
 * ---------------------------------------------------------------------
 * 事件清单
 * ---------------------------------------------------------------------
 *   onError       连接级错误（SEND_FAIL / 对端关闭等）-> 日志 + conn_error 指标
 *   onBufferFull  发送缓冲触顶（客户端消费慢）-> 日志 + buffer_full 指标
 *   onBufferDrain 发送缓冲排空 -> 日志 + buffer_drain 指标
 *   onWorkerReload 收到平滑重启信号 -> 日志
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Common;

use GatewayPush\Business\Monitor;
use Workerman\Connection\ConnectionInterface;

class WorkerEvents
{
    /**
     * 已绑定的 Worker（name#objectId），保证幂等
     *
     * @var array
     */
    protected static $bound = [];

    /**
     * 为指定 Worker 绑定通用事件
     *
     * @param object $worker Workerman\Worker 及其子类实例
     * @param string $name   进程名，用于日志区分来源
     * @param array  $opts   ['buffer' => bool] 是否绑定背压事件，默认 true
     * @return void
     */
    public static function bind($worker, $name, array $opts = array())
    {
        if (!is_object($worker)) {
            return;
        }

        $key = (string)$name . '#' . spl_object_id($worker);
        if (isset(self::$bound[$key])) {
            return;
        }
        self::$bound[$key] = true;

        self::bindError($worker, $name);

        $withBuffer = !isset($opts['buffer']) || !empty($opts['buffer']);
        if ($withBuffer) {
            self::bindBuffer($worker, $name);
        }

        self::bindReload($worker, $name);
    }

    /**
     * 连接级错误
     *
     * @param object $worker
     * @param string $name
     * @return void
     */
    protected static function bindError($worker, $name)
    {
        $worker->onError = function ($connection, $code, $msg) use ($name) {
            Monitor::incr('conn_error');
            Logger::error('连接发生错误', array(
                'worker' => $name,
                'code'   => (int)$code,
                'msg'    => (string)$msg,
                'peer'   => self::peer($connection),
            ));
        };
    }

    /**
     * 发送缓冲背压
     *
     * 上万长连接场景下，个别客户端消费缓慢会导致其发送缓冲持续增长；
     * 触顶时 workerman 会丢弃新包，此处的日志与指标是容量规划的输入依据。
     *
     * @param object $worker
     * @param string $name
     * @return void
     */
    protected static function bindBuffer($worker, $name)
    {
        $worker->onBufferFull = function ($connection) use ($name) {
            Monitor::incr('buffer_full');
            Logger::warn('发送缓冲已满，客户端消费能力不足', array(
                'worker' => $name,
                'peer'   => self::peer($connection),
            ));
        };

        $worker->onBufferDrain = function ($connection) use ($name) {
            Monitor::incr('buffer_drain');
            Logger::debug('发送缓冲已排空', array(
                'worker' => $name,
                'peer'   => self::peer($connection),
            ));
        };
    }

    /**
     * 平滑重启事件
     *
     * @param object $worker
     * @param string $name
     * @return void
     */
    protected static function bindReload($worker, $name)
    {
        $worker->onWorkerReload = function ($worker) use ($name) {
            Logger::info('Worker 收到平滑重启信号', array(
                'worker' => $name,
                'id'     => isset($worker->id) ? (int)$worker->id : 0,
            ));
        };
    }

    /**
     * 取对端地址（失败时返回空串，本方法不得抛异常）
     *
     * @param mixed $connection
     * @return string
     */
    protected static function peer($connection)
    {
        if (!$connection instanceof ConnectionInterface) {
            return '';
        }
        try {
            return $connection->getRemoteIp() . ':' . $connection->getRemotePort();
        } catch (\Throwable $e) {
            return '';
        }
    }
}
