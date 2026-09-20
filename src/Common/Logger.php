<?php
/**
 * 日志与全局异常捕获组件
 *
 * 设计要点：
 *  - 分级：debug / info / warn / error，低于配置阈值的日志直接丢弃
 *  - 分割：按天 + 按级别落盘，文件名 {level}_YYYY-MM-DD.log
 *  - 兜底：注册 error / exception / shutdown 处理器，避免异常导致进程静默退出
 *  - 清理：cleanup() 删除超过 keep_days 的历史日志，由定时任务调用
 *
 * 兼容 PHP 8.1 ~ 8.5（不使用 8.2+ 独有语法）
 */

namespace GatewayPush\Common;

use Throwable;

class Logger
{
    /** 日志级别常量 */
    const DEBUG = 'debug';
    const INFO  = 'info';
    const WARN  = 'warn';
    const ERROR = 'error';

    /**
     * 级别权重，数值越大越严重
     *
     * @var array
     */
    protected static $weight = array(
        self::DEBUG => 0,
        self::INFO  => 1,
        self::WARN  => 2,
        self::ERROR => 3,
    );

    /**
     * 运行配置
     *
     * @var array
     */
    protected static $config = array(
        'path'      => '',
        'level'     => self::DEBUG,
        'keep_days' => 30,
        'stdout'    => true,
    );

    /**
     * 全局处理器是否已注册
     *
     * @var bool
     */
    protected static $handlerRegistered = false;

    /**
     * 进程标识，便于在多进程日志中区分来源
     *
     * @var string
     */
    protected static $processTag = '-';

    /**
     * 单条日志 context 最大长度（字符），防止堆栈内容撑爆日志
     *
     * @var int
     */
    protected static $contextMaxLength = 2000;

    /**
     * 初始化日志组件
     *
     * @param array $config
     * @return void
     */
    public static function init(array $config = array())
    {
        self::$config = array_merge(self::$config, $config);
        if (!is_dir(self::$config['path'])) {
            @mkdir(self::$config['path'], 0755, true);
        }
        self::$processTag = 'pid:' . getmypid();
    }

    /**
     * 注册全局异常 / 错误 / 致命错误处理器
     *
     * @return void
     */
    public static function registerHandlers()
    {
        if (self::$handlerRegistered) {
            return;
        }
        self::$handlerRegistered = true;

        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            if (!(error_reporting() & $errno)) {
                return false;
            }
            self::error($errstr, array(
                'errno' => $errno,
                'file'  => $errfile . ':' . $errline,
            ));
            return true;
        });

        set_exception_handler(function ($e) {
            if ($e instanceof Throwable) {
                self::exception($e, 'uncaught');
                return;
            }
            self::error('未捕获的异常对象', array('value' => var_export($e, true)));
        });

        register_shutdown_function(function () {
            $error = error_get_last();
            if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
                self::error($error['message'], array(
                    'type' => 'fatal',
                    'file' => $error['file'] . ':' . $error['line'],
                ));
            }
        });
    }

    /* ---------------------------------------------------------------------
     | 快捷方法
     --------------------------------------------------------------------- */

    public static function debug($message, array $context = array())
    {
        self::log(self::DEBUG, $message, $context);
    }

    public static function info($message, array $context = array())
    {
        self::log(self::INFO, $message, $context);
    }

    public static function warn($message, array $context = array())
    {
        self::log(self::WARN, $message, $context);
    }

    public static function error($message, array $context = array())
    {
        self::log(self::ERROR, $message, $context);
    }

    /**
     * 记录异常对象（含位置与堆栈摘要）
     *
     * @param Throwable $e
     * @param string    $tag 业务标记，便于检索
     * @return void
     */
    public static function exception(Throwable $e, $tag = '')
    {
        self::log(self::ERROR, $e->getMessage(), array(
            'tag'   => $tag,
            'class' => get_class($e),
            'at'    => $e->getFile() . ':' . $e->getLine(),
            'trace' => self::shortTrace($e),
        ));
    }

    /**
     * 核心写入方法
     *
     * @param string $level
     * @param mixed  $message
     * @param array  $context
     * @return void
     */
    public static function log($level, $message, array $context = array())
    {
        if (!isset(self::$weight[$level])) {
            $level = self::INFO;
        }
        $threshold = isset(self::$weight[self::$config['level']]) ? self::$weight[self::$config['level']] : 0;
        if (self::$weight[$level] < $threshold) {
            return;
        }

        if (is_array($message) || is_object($message)) {
            $message = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $line = sprintf(
            "[%s][%s][%s] %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            self::$processTag,
            (string)$message,
            $context ? ' ' . self::stringifyContext($context) : ''
        );

        $file = rtrim(self::$config['path'], '/\\') . DIRECTORY_SEPARATOR . $level . '_' . date('Y-m-d') . '.log';
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

        if (self::$config['stdout']) {
            echo $line;
        }
    }

    /**
     * 清理过期日志文件（定时任务调用）
     *
     * @return int 删除的文件数
     */
    public static function cleanup()
    {
        $keepDays = (int)self::$config['keep_days'];
        if ($keepDays <= 0 || !is_dir(self::$config['path'])) {
            return 0;
        }

        $deadline = strtotime('-' . $keepDays . ' day');
        $removed  = 0;
        $handle   = @opendir(self::$config['path']);
        if (!$handle) {
            return 0;
        }
        while (($name = readdir($handle)) !== false) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $file = self::$config['path'] . DIRECTORY_SEPARATOR . $name;
            if (!is_file($file) || substr($name, -4) !== '.log') {
                continue;
            }
            if (filemtime($file) < $deadline && @unlink($file)) {
                $removed++;
            }
        }
        closedir($handle);

        if ($removed > 0) {
            self::info('清理过期日志完成', array('removed' => $removed, 'keep_days' => $keepDays));
        }
        return $removed;
    }

    /**
     * 供监控上报使用：当前进程内存占用（字节）
     *
     * @return int
     */
    public static function memoryUsage()
    {
        return memory_get_usage(true);
    }

    /* ---------------------------------------------------------------------
     | 内部辅助
     --------------------------------------------------------------------- */

    /**
     * context 序列化并做长度截断
     *
     * @param array $context
     * @return string
     */
    protected static function stringifyContext(array $context)
    {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = var_export($context, true);
        }
        if (strlen($json) > self::$contextMaxLength) {
            $json = substr($json, 0, self::$contextMaxLength) . '...(truncated)';
        }
        return $json;
    }

    /**
     * 精简堆栈：只保留前若干帧，附带上层调用文件
     *
     * @param Throwable $e
     * @return array
     */
    protected static function shortTrace(Throwable $e)
    {
        $frames = array();
        $trace  = $e->getTrace();
        foreach (array_slice($trace, 0, 5) as $frame) {
            $frames[] = (isset($frame['class']) ? $frame['class'] . ($frame['type'] ?? '::') : '')
                . ($frame['function'] ?? '')
                . (isset($frame['file']) ? ' @ ' . $frame['file'] . ':' . ($frame['line'] ?? 0) : '');
        }
        return $frames;
    }
}
