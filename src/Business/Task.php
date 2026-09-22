<?php
/**
 * 定时任务统一管理
 *
 * 设计要点（对应文档 4.5）：
 *  - 基于 workerman 原生 Timer，常驻内存、高精度
 *  - 统一注册入口，避免 Timer::add 散落各处导致定时器泛滥
 *  - 防重入：上一周期未结束时跳过本次，避免任务堆积拖垮进程
 *  - 异常隔离：单个任务异常不影响进程与其他任务
 *  - 超时告警：执行耗时超过配置阈值时输出 WARN 日志
 *
 * 执行范围 scope：
 *  - first：仅在 worker id = 0 的进程注册（全局唯一任务，如会话巡检）
 *  - all  ：每个进程都注册（需要分进程独立统计的任务，如指标上报）
 *
 * 首跑时机 run_at_start（可选，默认 false）：
 *  - workerman 的 Timer::add() 是「延迟首跑」——先等满一个 interval，再执行第一次。
 *    周期越长这个空窗越致命：86400s 的日志清理任务，只要进程活不满 24h 就一次都
 *    不会执行，而且是静默的（任务列表里看得到、统计里 count 恒为 0）。
 *  - 声明 run_at_start = true 的任务，在注册后延迟 START_RUN_DELAY 秒补跑一次，
 *    此后仍按 interval 周期执行。
 *  - 频繁重启的环境下，小时级及以上的周期任务都应评估是否需要它。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Business;

use GatewayPush\Common\Logger;
use Workerman\Timer;

class Task
{
    /**
     * 声明了 run_at_start 的任务，在启动后延迟多久补跑首次执行（秒）
     *
     * 不直接在 start() 里内联调用，是为了不阻塞 onWorkerStart：cleanup() 这类
     * 任务含同步文件 IO，挂在启动路径上会拖慢端口就绪；改为单次延迟定时器后，
     * 既避开启动期的事件风暴，又能复用 execute() 的防重入 / 统计 / 超时能力。
     *
     * @var float
     */
    const START_RUN_DELAY = 1.0;

    /**
     * 任务运行时状态
     *
     * @var array
     */
    protected static $jobs = [];

    /**
     * 当前 worker 进程 id
     *
     * @var int
     */
    protected static $workerId = 0;

    /**
     * 当前 worker 名称
     *
     * @var string
     */
    protected static $workerName = '';

    /**
     * 批量注册任务
     *
     * @param array  $jobConfigs config/business.php 的 tasks 配置
     * @param int    $workerId
     * @param string $workerName
     * @return void
     */
    public static function init(array $jobConfigs, $workerId = 0, $workerName = '')
    {
        self::$workerId   = (int)$workerId;
        self::$workerName = (string)$workerName;

        $started = 0;
        foreach ($jobConfigs as $job) {
            if (!is_array($job) || !self::matchScope($job)) {
                continue;
            }
            if (self::start($job)) {
                $started++;
            }
        }

        Logger::info('定时任务注册完成', array(
            'worker_id'   => self::$workerId,
            'worker_name' => self::$workerName,
            'registered'  => $started,
            'total'       => count($jobConfigs),
        ));
    }

    /**
     * 注册单个任务
     *
     * @param array $job ['name','interval','class','method','persistent','timeout','scope']
     * @return bool
     */
    public static function start(array $job)
    {
        $name = isset($job['name']) ? (string)$job['name'] : '';
        if ($name === '') {
            Logger::error('定时任务缺少 name 配置');
            return false;
        }
        if (isset(self::$jobs[$name])) {
            Logger::warn('定时任务重复注册，已忽略', array('name' => $name));
            return false;
        }

        $class  = isset($job['class']) ? (string)$job['class'] : '';
        $method = isset($job['method']) ? (string)$job['method'] : '';
        if ($class === '' || $method === '' || !class_exists($class) || !method_exists($class, $method)) {
            Logger::error('定时任务处理器不可用', array(
                'name'    => $name,
                'handler' => $class . '::' . $method,
            ));
            return false;
        }

        $interval = isset($job['interval']) ? (float)$job['interval'] : 0.0;
        if ($interval <= 0) {
            Logger::error('定时任务执行间隔非法', array('name' => $name, 'interval' => $interval));
            return false;
        }

        $persistent = isset($job['persistent']) ? (bool)$job['persistent'] : true;
        $timeout    = isset($job['timeout']) ? (int)$job['timeout'] : 0;

        self::$jobs[$name] = array(
            'name'       => $name,
            'interval'   => $interval,
            'handler'    => $class . '::' . $method,
            'persistent' => $persistent,
            'timeout'    => $timeout,
            'running'    => false,
            'count'      => 0,
            'skip'       => 0,
            'fail'       => 0,
            'last_start' => 0.0,
            'last_cost'  => 0.0,
            'timer'      => 0,
        );

        $timerId = Timer::add($interval, function () use ($name) {
            self::execute($name);
        }, [], $persistent);

        self::$jobs[$name]['timer'] = $timerId;

        Logger::info('定时任务已注册', array(
            'name'       => $name,
            'handler'    => $class . '::' . $method,
            'interval'   => $interval . 's',
            'persistent' => $persistent,
        ));

        // 补跑首次执行：周期任务默认要空等一个 interval 才启动第一轮，
        // 对 86400s 级的任务而言，进程活不满一天就永远不会跑（静默失效）——
        // 在频繁重启的环境里，这等于没有清理。
        // 非持久化的 Timer 即「延迟一次」，与周期定时器互不影响。
        if (! empty($job['run_at_start'])) {
            Timer::add(self::START_RUN_DELAY, function () use ($name) {
                self::execute($name);
            }, [], false);

            Logger::info('定时任务已安排启动后补跑一次', array(
                'name'  => $name,
                'delay' => self::START_RUN_DELAY . 's',
            ));
        }

        return true;
    }

    /**
     * 停止任务
     *
     * @param string $name
     * @return bool
     */
    public static function stop($name)
    {
        if (!isset(self::$jobs[$name])) {
            return false;
        }
        if (!empty(self::$jobs[$name]['timer'])) {
            Timer::del((int)self::$jobs[$name]['timer']);
        }
        unset(self::$jobs[$name]);
        Logger::info('定时任务已停止', array('name' => $name));
        return true;
    }

    /**
     * 任务运行统计（供监控上报）
     *
     * @return array
     */
    public static function stats()
    {
        $stats = [];
        foreach (self::$jobs as $name => $job) {
            $stats[$name] = array(
                'interval'   => $job['interval'],
                'count'      => $job['count'],
                'skip'       => $job['skip'],
                'fail'       => $job['fail'],
                'last_cost'  => round($job['last_cost'], 4),
                'running'    => $job['running'],
            );
        }
        return $stats;
    }

    /**
     * 当前 worker 进程 id
     *
     * @return int
     */
    public static function workerId()
    {
        return self::$workerId;
    }

    /* ---------------------------------------------------------------------
     | 内部实现
     --------------------------------------------------------------------- */

    /**
     * 执行任务（含防重入与异常隔离）
     *
     * @param string $name
     * @return void
     */
    protected static function execute($name)
    {
        if (!isset(self::$jobs[$name])) {
            return;
        }
        $job = &self::$jobs[$name];

        if ($job['running']) {
            $job['skip']++;
            Logger::warn('上一周期任务尚未结束，跳过本次执行', array(
                'name'     => $name,
                'interval' => $job['interval'],
            ));
            return;
        }

        $job['running']    = true;
        $job['last_start'] = microtime(true);

        try {
            ($job['handler'])();
        } catch (\Throwable $e) {
            $job['fail']++;
            Logger::exception($e, 'task:' . $name);
        }

        $job['running']   = false;
        $job['count']++;
        $job['last_cost'] = microtime(true) - $job['last_start'];

        if ($job['timeout'] > 0 && $job['last_cost'] > $job['timeout']) {
            Logger::warn('定时任务执行耗时超出预期', array(
                'name'    => $name,
                'cost'    => round($job['last_cost'], 4),
                'timeout' => $job['timeout'],
            ));
        }
    }

    /**
     * 判断任务是否应在当前进程注册
     *
     * @param array $job
     * @return bool
     */
    protected static function matchScope(array $job)
    {
        $scope = isset($job['scope']) ? (string)$job['scope'] : 'first';
        if ($scope === 'all') {
            return true;
        }
        return self::$workerId === 0;
    }
}
