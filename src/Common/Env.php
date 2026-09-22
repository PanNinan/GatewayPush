<?php
/**
 * 环境变量加载与类型化读取
 *
 * 设计目标：让端口、地址、密钥、连接参数等环境相关配置脱离代码，
 * 由 .env 文件或真实系统环境变量提供，避免硬编码散落在配置文件里。
 *
 * 加载优先级（低 -> 高）：
 *   代码内默认值
 *     < .env
 *     < .env.{APP_ENV}
 *     < .env.local
 *     < .env.{APP_ENV}.local
 *     < 真实系统环境变量（容器 / CI 注入，最高优先，不会被 .env 覆盖）
 *
 * 实现要点（基于 vlucas/phpdotenv v5.7）：
 *   1. v5.7 已移除 Dotenv\Env 门面，读取由本类统一封装（$_ENV -> $_SERVER -> getenv）。
 *   2. 多级文件必须显式关闭 shortCircuit，否则 Reader 只会读取第一个命中的文件。
 *   3. immutable 语义为「不覆盖外部已有变量，但本次加载写入的值可被后续文件覆盖」
 *      （见 ImmutableWriter::isExternallyDefined），因此文件按优先级从低到高排列，
 *      后者覆盖前者；而系统环境变量在加载前即已存在，故始终胜出、不被覆盖。
 *   4. PutenvAdapter 仅注册为 reader 而非 writer：使 getenv() 注入的值同样被识别为
 *      「外部已有」而受保护，同时避免把密钥写回进程环境（/proc/self/environ 可读）。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Common;

use Dotenv\Dotenv;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\RepositoryBuilder;

/**
 * 环境变量加载与类型化读取
 *
 * 多级 .env 叠加，真实环境变量最高优先；读取一律走类型化方法，不直接访问 $_ENV。
 */
class Env
{
    /** 缺省环境标识 */
    public const DEFAULT_ENV = 'dev';

    /** 密钥生成字节数（转为十六进制后长度为该值的 2 倍） */
    public const SECRET_BYTES = 32;

    /**
     * .env 文件槽位
     *
     * 必须按「加载顺序」排列，且低优先级在前 —— phpdotenv 的 immutable 语义是
     * 「外部已有变量不可覆盖，但本次加载写入的值允许被后续文件覆盖」，
     * 顺序倒置会导致高优先级文件被基础文件反向覆盖（曾踩坑）。
     */
    public const FILE_SLOTS = [
        '.env',
        '.env.{env}',
        '.env.local',
        '.env.{env}.local',
    ];

    /**
     * 是否已完成加载
     *
     * @var bool
     */
    protected static $loaded = false;

    /**
     * 实际读取到的文件（按优先级从高到低）
     *
     * @var array<int|string, mixed>
     */
    protected static $files = [];

    /**
     * 当前环境标识
     *
     * @var string
     */
    protected static $envName = self::DEFAULT_ENV;

    /**
     * 加载根目录
     *
     * @var string
     */
    protected static $basePath = '';

    /**
     * 加载阶段异常信息
     *
     * @var string
     */
    protected static $error = '';

    /* =================================================================
     | 加载
     ================================================================= */

    /**
     * 加载环境变量文件（幂等，重复调用直接返回首次结果）
     *
     * @param null|string $basePath 项目根目录，缺省为 src 的上两级
     *
     * @return array<string, mixed> 实际读取到的文件名列表（按加载顺序，即优先级从低到高）
     */
    public static function load($basePath = null)
    {
        if (self::$loaded) {
            return self::$files;
        }

        self::$basePath = $basePath !== null
            ? rtrim((string)$basePath, '/\\')
            : dirname(__DIR__, 2);

        self::$envName = self::detectEnvName(self::$basePath);

        // 收集真实存在的文件，保持「低优先级在前」的加载顺序
        $files = [];
        foreach (self::FILE_SLOTS as $slot) {
            $name = str_replace('{env}', self::$envName, $slot);
            if (is_file(self::$basePath . '/' . $name)) {
                $files[] = $name;
            }
        }

        if (!empty($files)) {
            try {
                $repository = RepositoryBuilder::createWithDefaultAdapters()
                    ->addReader(PutenvAdapter::class)   // 仅读，不写回 getenv()
                    ->immutable()                       // 已存在的值（含系统环境变量）不被覆盖
                    ->make()
                ;

                // shortCircuit = false：读取全部命中文件。配合 immutable 实现
                // 「外部环境变量不被覆盖，文件之间后者覆盖前者」
                $dotenv = Dotenv::create($repository, self::$basePath, $files, false);
                $dotenv->safeLoad();
            } catch (\Throwable $e) {
                // safeLoad 只吞掉 InvalidPathException；文件语法非法等异常在此兜底。
                // 降级为「未加载」而非中断进程，由自检输出提示后由使用者修正。
                self::$error = $e->getMessage();
            }
        }

        self::$files  = $files;
        self::$loaded = true;

        return $files;
    }

    /**
     * 加载阶段的异常信息（正常为空字符串）
     *
     * @return string
     */
    public static function lastError()
    {
        return self::$error;
    }

    /**
     * 是否已完成加载
     *
     * @return bool
     */
    public static function isLoaded()
    {
        return self::$loaded;
    }

    /**
     * 已读取到的环境文件列表（按加载顺序，后者覆盖前者）
     *
     * @return array<int|string, mixed>
     */
    public static function loadedFiles()
    {
        return self::$files;
    }

    /**
     * 当前环境标识（APP_ENV）
     *
     * @return string
     */
    public static function envName()
    {
        if (!self::$loaded) {
            self::load();
        }

        return self::$envName;
    }

    /**
     * 生成高强度随机密钥（十六进制）
     *
     * @param int $bytes 随机字节数
     *
     * @return string
     */
    public static function generateSecret($bytes = self::SECRET_BYTES)
    {
        $bytes = (int)$bytes;
        if ($bytes < 16) {
            $bytes = 16;
        }

        try {
            return bin2hex(random_bytes($bytes));
        } catch (\Throwable $e) {
            // 极端环境缺少 CSPRNG 时的降级方案：仍可用，但强度下降
            return hash('sha256', uniqid((string)mt_rand(), true) . microtime(true));
        }
    }

    /* =================================================================
     | 读取（类型化）
     ================================================================= */

    /**
     * 判断变量是否已定义（含空字符串）
     *
     * @param string $key
     *
     * @return bool
     */
    public static function has($key)
    {
        return self::lookup($key, null) !== null;
    }

    /**
     * 读取原始值
     *
     * @param string $key
     * @param mixed  $default 变量不存在时返回的默认值
     *
     * @return mixed
     */
    public static function get($key, $default = null)
    {
        if (!self::$loaded) {
            self::load();
        }

        return self::lookup($key, $default);
    }

    /**
     * 读取字符串
     *
     * @param string $key
     * @param string $default
     *
     * @return string
     */
    public static function str($key, $default = '')
    {
        $value = self::get($key, null);
        if ($value === null || is_array($value)) {
            return (string)$default;
        }

        return (string)$value;
    }

    /**
     * 读取整型
     *
     * @param string $key
     * @param int    $default
     *
     * @return int
     */
    public static function int($key, $default = 0)
    {
        $value = self::get($key, null);
        if ($value === null || $value === '' || is_array($value) || !is_numeric($value)) {
            return (int)$default;
        }

        return (int)$value;
    }

    /**
     * 读取浮点型
     *
     * @param string $key
     * @param float  $default
     *
     * @return float
     */
    public static function float($key, $default = 0.0)
    {
        $value = self::get($key, null);
        if ($value === null || $value === '' || is_array($value) || !is_numeric($value)) {
            return (float)$default;
        }

        return (float)$value;
    }

    /**
     * 读取布尔型
     *
     * 可识别的真值：1 / true / yes / on（大小写不敏感）
     * 空字符串与非真值字符串均返回默认值，避免 'false' 被强转为 true
     *
     * @param string $key
     * @param bool   $default
     *
     * @return bool
     */
    public static function bool($key, $default = false)
    {
        $value = self::get($key, null);
        if ($value === null || is_array($value)) {
            return (bool)$default;
        }
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string)$value));
        if ($normalized === '') {
            return (bool)$default;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * 读取逗号分隔的列表
     *
     * @param string                   $key
     * @param array<int|string, mixed> $default
     *
     * @return array<int|string, mixed>
     */
    public static function list($key, $default = [])
    {
        $value = self::get($key, null);
        if (!is_string($value) || trim($value) === '') {
            return (array)$default;
        }

        $items = [];
        foreach (explode(',', $value) as $item) {
            $item = trim($item);
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return empty($items) ? (array)$default : $items;
    }

    /* =================================================================
     | 内部实现
     ================================================================= */

    /**
     * 底层读取，不触发懒加载
     *
     * 读取顺序：$_ENV -> $_SERVER -> getenv()
     *
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    protected static function lookup($key, $default = null)
    {
        if (!is_string($key) || $key === '') {
            return $default;
        }

        if (array_key_exists($key, $_ENV)) {
            return self::normalize($_ENV[$key], $default);
        }

        if (isset($_SERVER) && is_array($_SERVER) && array_key_exists($key, $_SERVER)) {
            return self::normalize($_SERVER[$key], $default);
        }

        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        return $default;
    }

    /**
     * 空值归一：环境变量中的 null 视为空字符串，保留「键存在」这一事实
     *
     * @param mixed $value
     * @param mixed $default
     *
     * @return mixed
     */
    protected static function normalize($value, $default)
    {
        if ($value === null) {
            return '';
        }

        return $value;
    }

    /**
     * 探测当前环境标识
     *
     * 取值顺序：真实环境变量 -> .env 文件预读 -> 默认 dev
     * 结果会被拼入文件名，因此严格限制为字母数字下划线，防止路径穿越
     *
     * @param string $basePath
     *
     * @return string
     */
    protected static function detectEnvName($basePath)
    {
        $name = self::lookup('APP_ENV', '');

        if (!is_string($name) || trim($name) === '') {
            $name = self::peek($basePath . '/.env', 'APP_ENV');
        }

        $name = trim((string)$name);

        if ($name === '' || preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            return self::DEFAULT_ENV;
        }

        return $name;
    }

    /**
     * 预读指定 .env 文件中的单个配置项（不写入环境）
     *
     * 仅用于在加载前确定 APP_ENV；支持 export 前缀、行内注释与引号包裹。
     *
     * @param string $file
     * @param string $key
     *
     * @return string
     */
    protected static function peek($file, $key)
    {
        if (!is_file($file) || !is_readable($file)) {
            return '';
        }

        $content = @file_get_contents($file);
        if (!is_string($content) || $content === '') {
            return '';
        }

        $pattern = '/^[ \t]*(?:export[ \t]+)?' . preg_quote($key, '/') . '[ \t]*=[ \t]*(.*)$/m';
        if (preg_match($pattern, $content, $matches) !== 1) {
            return '';
        }

        // 剥离行内注释（# 前需有空白，避免误伤值内的 #）
        $value = preg_replace('/[ \t]+#.*$/', '', $matches[1]);
        $value = trim((string)$value);

        return trim($value, "\"'");
    }
}
