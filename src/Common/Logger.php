<?php
/**
 * 日志与全局异常捕获组件
 *
 * 设计要点：
 *  - 分级：debug / info / warn / error，低于配置阈值的日志直接丢弃
 *  - 分割：按天 + 按角色落盘，文件名 {role}_YYYY-MM-DD.log
 *          role 即当前进程的日志通道（register / gateway / udp / business / api /
 *          dashboard）；未设置时为 app。同角色的多个进程共写一个文件，靠行内
 *          pid 区分 —— 与 pid 文件 workerman_{role}.pid 的命名维度保持一致。
 *  - 汇总：error 级额外双写 error_YYYY-MM-DD.log，便于不经角色维度速览全局错误
 *  - 兜底：注册 error / exception / shutdown 处理器，避免异常导致进程静默退出
 *  - 清理：cleanup() 删除超过 keep_days 的历史日志，由定时任务调用
 *  - 归档：archive() 把超过 archive_after_days 的按天日志打进月度 tar.gz 后再删明文，
 *          形成「热明文 → 冷压缩包 → 过期删除」的三级保留期（默认关闭，由 .env 开启）
 *
 * 日志生命周期（archive_enable 开启时）：
 *   0 ~ archive_after_days      明文 {role}_{date}.log，供实时排查
 *   archive_after_days ~ archive_keep_days
 *                               archive/{YYYY-MM}.tar.gz，压缩留存
 *   > archive_keep_days         删除归档包
 *
 * 兼容 PHP 8.2 ~ 8.5（不使用 8.3+ 独有语法）
 */

namespace GatewayPush\Common;

use RuntimeException;
use Throwable;

/**
 * 日志与全局异常捕获
 *
 * 按「角色 + 日期」分文件，error 级双写汇总文件；含清理与归档两级保留策略。
 */
class Logger
{
    /** 日志级别常量 */
    public const DEBUG = 'debug';
    public const INFO  = 'info';
    public const WARN  = 'warn';
    public const ERROR = 'error';

    /** 未显式切换日志通道时的默认角色名 */
    public const CHANNEL_DEFAULT = 'app';

    /** 跨角色错误汇总通道前缀（error_{date}.log），保留字，不可作为角色名 */
    public const CHANNEL_ERROR_DIGEST = 'error';

    /**
     * 级别权重，数值越大越严重
     *
     * @var array<string, mixed>
     */
    protected static $weight = [
        self::DEBUG => 0,
        self::INFO  => 1,
        self::WARN  => 2,
        self::ERROR => 3,
    ];

    /**
     * 运行配置
     *
     * @var array<string, mixed>
     */
    protected static $config = [
        'path'               => '',
        'level'              => self::DEBUG,
        'role'               => self::CHANNEL_DEFAULT,
        'keep_days'          => 30,
        'stdout'             => true,
        'archive_enable'     => false,
        'archive_after_days' => 7,
        'archive_dir'        => '',
        'archive_keep_days'  => 180,
        'archive_level'      => 6,
    ];

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
     * @param array<string, mixed> $config
     *
     * @return void
     *
     * @throws RuntimeException 日志目录无法创建时抛出
     */
    public static function init(array $config = [])
    {
        self::$config = array_merge(self::$config, $config);
        self::$config['role'] = self::sanitizeRole(self::$config['role']);
        if (!is_dir(self::$config['path']) && !mkdir(
            $concurrentDirectory = self::$config['path'],
            0o755,
            true
        ) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
        }
        self::$processTag = 'pid:' . getmypid();
    }

    /**
     * 切换当前进程的日志通道（角色）
     *
     * 每个角色进程须在自己的 onWorkerStart 内调用一次：
     *   - Windows 下按角色独立启动，进程级 init 已由 start.php 传入 APP_ROLE，
     *     此处为幂等的再确认；
     *   - Linux --role=all 时 APP_ROLE 为 'all'，各组件被 workerman fork 到独立
     *     进程，子进程继承父进程的静态状态、无法自知属于哪个角色，必须在此显式
     *     切换，否则全部角色的日志都会挤进 all_*.log，分角色便告失效。
     *
     * 同时刷新进程标识：同角色的多进程共写一个文件，pid 是唯一的区分手段，
     * fork 后若不刷新将残留父进程 pid。
     *
     * @param string $role
     *
     * @return void
     */
    public static function useChannel($role)
    {
        self::$config['role'] = self::sanitizeRole($role);
        self::$processTag     = 'pid:' . getmypid();
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
            self::error($errstr, [
                'errno' => $errno,
                'file'  => $errfile . ':' . $errline,
            ]);

            return true;
        });

        // set_exception_handler 的回调参数在 PHP 7+ 恒为 Throwable，
        // 原先的 instanceof 分支与「非异常对象」兜底均不可达，故直接类型化收参
        set_exception_handler(function (Throwable $e) {
            self::exception($e, 'uncaught');
        });

        register_shutdown_function(function () {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::error($error['message'], [
                    'type' => 'fatal',
                    'file' => $error['file'] . ':' . $error['line'],
                ]);
            }
        });
    }

    /* ---------------------------------------------------------------------
     | 快捷方法
     --------------------------------------------------------------------- */

    /**
     * 记录 debug 级日志
     *
     * @param mixed                $message
     * @param array<string, mixed> $context
     *
     * @return void
     */
    public static function debug($message, array $context = [])
    {
        self::log(self::DEBUG, $message, $context);
    }

    /**
     * 记录 info 级日志
     *
     * @param mixed                $message
     * @param array<string, mixed> $context
     *
     * @return void
     */
    public static function info($message, array $context = [])
    {
        self::log(self::INFO, $message, $context);
    }

    /**
     * 记录 warn 级日志
     *
     * @param mixed                $message
     * @param array<string, mixed> $context
     *
     * @return void
     */
    public static function warn($message, array $context = [])
    {
        self::log(self::WARN, $message, $context);
    }

    /**
     * 记录 error 级日志（额外双写到跨角色汇总通道）
     *
     * @param mixed                $message
     * @param array<string, mixed> $context
     *
     * @return void
     */
    public static function error($message, array $context = [])
    {
        self::log(self::ERROR, $message, $context);
    }

    /**
     * 记录异常对象（含位置与堆栈摘要）
     *
     * @param Throwable $e
     * @param string    $tag 业务标记，便于检索
     *
     * @return void
     */
    public static function exception(Throwable $e, $tag = '')
    {
        self::log(self::ERROR, $e->getMessage(), [
            'tag'   => $tag,
            'class' => $e::class,
            'at'    => $e->getFile() . ':' . $e->getLine(),
            'trace' => self::shortTrace($e),
        ]);
    }

    /**
     * 核心写入方法
     *
     * @param string               $level
     * @param mixed                $message
     * @param array<string, mixed> $context
     *
     * @return void
     */
    public static function log($level, $message, array $context = [])
    {
        if (!isset(self::$weight[$level])) {
            $level = self::INFO;
        }
        $threshold = self::$weight[self::$config['level']] ?? 0;
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
            $message,
            $context ? ' ' . self::stringifyContext($context) : ''
        );

        $base = rtrim(self::$config['path'], '/\\') . DIRECTORY_SEPARATOR;
        $date = date('Y-m-d');

        @file_put_contents($base . self::$config['role'] . '_' . $date . '.log', $line, FILE_APPEND | LOCK_EX);

        // error 级额外落入跨角色汇总通道：排查全局故障时无需逐个角色翻文件
        if ($level === self::ERROR) {
            @file_put_contents($base . self::CHANNEL_ERROR_DIGEST . '_' . $date . '.log', $line, FILE_APPEND | LOCK_EX);
        }

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
            if (!is_file($file) || !str_ends_with($name, '.log')) {
                continue;
            }
            if (filemtime($file) < $deadline && @unlink($file)) {
                $removed++;
            }
        }
        closedir($handle);

        if ($removed > 0) {
            self::info('清理过期日志完成', ['removed' => $removed, 'keep_days' => $keepDays]);
        }

        return $removed;
    }

    /**
     * 归档过期日志：打进月度 tar.gz 后删除明文（定时任务调用）
     *
     * 与 cleanup() 的分工：
     *   - cleanup() 只删明文，是兜底 —— 归档关闭时它是唯一生效的机制
     *   - archive() 是主动转冷存：明文 → 压缩包 → 删源文件。开启归档后，明文在
     *     archive_after_days 时就被换走了，cleanup() 的 keep_days 基本无事可做
     *
     * 保留期约束：archive_after_days 必须小于 keep_days。反之明文会先被 cleanup()
     * 删掉，归档永远拿不到内容（与 API_ACTION_WAIT_MS > ACTION_TIMEOUT 同类的不变量，
     * 此处不阻断启动，只告警并跳过本轮，避免因一个可选项让服务起不来）。
     *
     * @return int 本次归档的明文文件数
     */
    public static function archive()
    {
        if (empty(self::$config['archive_enable'])) {
            return 0;
        }

        $path      = (string)self::$config['path'];
        $afterDays = (int)self::$config['archive_after_days'];
        $keepDays  = (int)self::$config['archive_keep_days'];
        $level     = self::normalizeGzipLevel(self::$config['archive_level']);
        $plainDays = (int)self::$config['keep_days'];

        if ($afterDays <= 0 || !is_dir($path)) {
            return 0;
        }
        if ($afterDays >= $plainDays) {
            self::warn('日志归档已跳过：archive_after_days 必须小于 keep_days', [
                'archive_after_days' => $afterDays,
                'keep_days'          => $plainDays,
            ]);

            return 0;
        }

        $archiveDir = self::archiveDir();
        $deadline   = strtotime('-' . $afterDays . ' day');

        // 收集待归档文件并按所属月份分组。只认 {channel}_{YYYY-MM-DD}.log 命名：
        // workerman.log / stdout.log 这类持续写入、无日期的文件被天然排除（它们归
        // LOG_MAX_MB 管），包名也因此能从文件名自身解析，不会跨月混装。
        $groups = [];
        $handle = @opendir($path);
        if ($handle === false) {
            return 0;
        }
        while (($name = readdir($handle)) !== false) {
            $month = self::archiveMonthOf($name);
            if ($month === null) {
                continue;
            }
            $file = $path . DIRECTORY_SEPARATOR . $name;
            if (!is_file($file)) {
                continue;
            }
            $mtime = (int)filemtime($file);
            if ($mtime >= $deadline) {
                continue;
            }
            $body = @file_get_contents($file);
            if ($body === false) {
                continue;
            }
            $groups[$month][] = [
                'path'  => $file,
                'name'  => $name,
                'body'  => $body,
                'mtime' => $mtime,
            ];
        }
        closedir($handle);

        $archived = 0;
        $failed   = 0;
        foreach ($groups as $month => $items) {
            $pack = $archiveDir . DIRECTORY_SEPARATOR . $month . '.tar.gz';

            // 顺序不可颠倒：先把包整体写成功，再删源文件。中途失败宁愿留下明文，
            // 也绝不能出现「明文已删、归档包却没有它」的数据空洞
            if (!self::appendTarGz($pack, $items, $level)) {
                $failed += count($items);

                continue;
            }
            foreach ($items as $item) {
                if (@unlink($item['path'])) {
                    $archived++;
                }
            }
        }

        $purged = self::purgeArchives($archiveDir, $keepDays);

        if ($archived > 0 || $purged > 0) {
            self::info('日志归档完成', [
                'archived'   => $archived,
                'purged'     => $purged,
                'after_days' => $afterDays,
                'keep_days'  => $keepDays,
                'dir'        => $archiveDir,
            ]);
        }
        if ($failed > 0) {
            self::error('日志归档失败，明文已保留', [
                'failed' => $failed,
                'dir'    => $archiveDir,
            ]);
        }

        return $archived;
    }

    /**
     * 从日志文件名解析所属月份（纯函数，可单测）
     *
     * 只接受严格日期命名 {channel}_{YYYY-MM-DD}.log —— 这既排除了 workerman.log /
     * stdout.log 这类不该归档的持续写入文件，也让包名由「文件自身日期」而非「归档
     * 发生的时刻」决定：9 月 3 日归档 8 月 27 日的日志应进 2026-08 包，包内不跨月。
     *
     * @param string $name
     *
     * @return null|string 形如 2026-09；命名不匹配返回 null
     */
    public static function archiveMonthOf($name)
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,15}_(\d{4})-(\d{2})-\d{2}\.log$/', (string)$name, $m)) {
            return null;
        }

        return $m[1] . '-' . $m[2];
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

    /**
     * 归档目录（未显式配置时落在日志目录下的 archive/）
     *
     * 位置无需额外防护：cleanup() 只遍历日志目录顶层且以 is_file() 过滤（子目录被
     * 挡掉、不递归），归档产物又是 .tar.gz 后缀、连 .log 都不匹配 —— 双重保证归档
     * 不会被清理任务误删。
     *
     * @return string
     */
    protected static function archiveDir()
    {
        $dir = trim((string)self::$config['archive_dir']);
        if ($dir === '') {
            $dir = rtrim((string)self::$config['path'], '/\\') . DIRECTORY_SEPARATOR . 'archive';
        }

        return rtrim($dir, '/\\');
    }

    /**
     * 把条目追加进 tar.gz（不覆盖已有内容）
     *
     * tar 是「头部 + 数据」逐条顺排、末尾以空块收尾的格式，追加时必须先剥掉尾部
     * 空块再续写，否则空块会夹在中间被解包工具当成归档终止。
     *
     * 已存在的包整体 gzdecode 进内存再重压：单日日志只有 KB 级、月度包也在 MB 以内，
     * 内存往返的成本远低于维护增量压缩的复杂度，且不会产生半截文件。
     *
     * @param string                   $pack  归档包路径
     * @param array<int|string, mixed> $items [['path','name','body','mtime'], ...]
     * @param int                      $level gzip 压缩级别 1~9
     *
     * @return bool 是否写入成功
     */
    protected static function appendTarGz($pack, array $items, $level)
    {
        $raw = '';
        if (is_file($pack)) {
            $existing = @file_get_contents($pack);
            $decoded  = $existing === false ? false : @gzdecode($existing);
            if (!is_string($decoded)) {
                // 包已损坏：宁可不归档也不能覆盖它，否则会连带毁掉里面已有的历史
                self::error('日志归档包损坏，已跳过本次归档以保护明文与既有归档', ['pack' => $pack]);

                return false;
            }
            $raw = substr($decoded, 0, self::tarPayloadEnd($decoded));
        }

        foreach ($items as $item) {
            $body = (string)$item['body'];
            $raw .= self::tarHeader((string)$item['name'], strlen($body), (int)$item['mtime']) . $body;
            $pad  = (int)(ceil(strlen($body) / 512) * 512) - strlen($body);
            if ($pad > 0) {
                $raw .= str_repeat("\0", $pad);
            }
        }
        $raw .= str_repeat("\0", 1024);   // 归档终止标记：两个 512 字节空块

        $dir = dirname($pack);
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            self::error('日志归档目录创建失败', ['dir' => $dir]);

            return false;
        }

        $gz = gzencode($raw, $level);
        if ($gz === false || @file_put_contents($pack, $gz, LOCK_EX) === false) {
            self::error('日志归档包写入失败', ['pack' => $pack]);

            return false;
        }

        return true;
    }

    /**
     * 清理超过保留期的归档包
     *
     * @param string $dir
     * @param int    $keepDays
     *
     * @return int 删除的包数
     */
    protected static function purgeArchives($dir, $keepDays)
    {
        if ($keepDays <= 0 || !is_dir($dir)) {
            return 0;
        }
        $deadline = strtotime('-' . $keepDays . ' day');
        $removed  = 0;
        $handle   = @opendir($dir);
        if ($handle === false) {
            return 0;
        }
        while (($name = readdir($handle)) !== false) {
            if (!str_ends_with($name, '.tar.gz')) {
                continue;
            }
            $file = $dir . DIRECTORY_SEPARATOR . $name;
            if (is_file($file) && filemtime($file) < $deadline && @unlink($file)) {
                $removed++;
            }
        }
        closedir($handle);

        return $removed;
    }

    /* ---------------------------------------------------------------------
     | 内部辅助
     --------------------------------------------------------------------- */

    /**
     * 角色名归一化
     *
     * 角色名会直接拼进文件名，必须限制字符集以防路径穿越；同时 'error' 被
     * 跨角色汇总通道占用，若作为角色名会与之撞名，一并回落到默认通道。
     *
     * @param mixed $role
     *
     * @return string
     */
    protected static function sanitizeRole($role)
    {
        $role = strtolower(trim((string)$role));
        if ($role === '' || !preg_match('/^[a-z][a-z0-9_-]{0,15}$/', $role)) {
            return self::CHANNEL_DEFAULT;
        }
        if ($role === self::CHANNEL_ERROR_DIGEST) {
            return self::CHANNEL_DEFAULT;
        }

        return $role;
    }

    /**
     * context 序列化并做长度截断
     *
     * @param array<string, mixed> $context
     *
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
     *
     * @return array<int|string, mixed>
     */
    protected static function shortTrace(Throwable $e)
    {
        $frames = [];
        $trace  = $e->getTrace();
        foreach (array_slice($trace, 0, 5) as $frame) {
            $frames[] = (isset($frame['class']) ? $frame['class'] . ($frame['type'] ?? '::') : '')
                . ($frame['function'] ?? '')
                . (isset($frame['file']) ? ' @ ' . $frame['file'] . ':' . ($frame['line'] ?? 0) : '');
        }

        return $frames;
    }

    /* ---------------------------------------------------------------------
     | 归档底层：tar 写入
     |
     | 为什么手写 tar 而不用现成组件：
     |  - PharData::compress() 实测不可用 —— 抛 BadMethodCallException
     |    （"a phar with that name already exists"），产物还是未压缩的裸 tar；
     |  - ZipArchive 需额外 zip 扩展，本项目只保证内置扩展，且 .tar.gz 在
     |    Linux 侧运维（tar -xzf）更顺手。
     | 故只依赖内置 zlib，格式为 POSIX ustar，已用 GNU tar 交叉验证：
     | tar -tzvf 能列出条目、解包内容与原文件 md5 一致、空追加幂等。
     --------------------------------------------------------------------- */

    /**
     * 生成单个 tar 条目头（纯函数，POSIX ustar 格式，固定 512 字节）
     *
     * ustar 头字段合计 500 字节，尾部 12 字节为保留填充。校验和以「checksum 字段
     * 本身按 8 个空格参与求和」计算，写入时再替换为 6 位八进制 + NUL + 空格。
     *
     * @param string $name  条目名（≤100 字符，本项目日志名远低于此）
     * @param int    $size  数据长度
     * @param int    $mtime 修改时间戳
     * @param int    $mode  权限位
     *
     * @return string 512 字节的头块
     */
    protected static function tarHeader($name, $size, $mtime, $mode = 0o644)
    {
        $header  = str_pad(substr((string)$name, 0, 100), 100, "\0");
        $header .= str_pad(decoct($mode & 0o7777), 7, '0', STR_PAD_LEFT) . "\0";
        $header .= str_pad('0', 7, '0', STR_PAD_LEFT) . "\0";   // uid
        $header .= str_pad('0', 7, '0', STR_PAD_LEFT) . "\0";   // gid
        $header .= str_pad(decoct((int)$size), 11, '0', STR_PAD_LEFT) . "\0";
        $header .= str_pad(decoct((int)$mtime), 11, '0', STR_PAD_LEFT) . "\0";
        $header .= '        ';                                  // checksum 占位（8 空格）
        $header .= '0';                                         // typeflag：普通文件
        $header .= str_repeat("\0", 100);                       // linkname
        $header .= "ustar\0" . '00';                            // magic + version
        $header .= str_repeat("\0", 32);                        // uname
        $header .= str_repeat("\0", 32);                        // gname
        $header .= str_repeat("\0", 8);                         // devmajor
        $header .= str_repeat("\0", 8);                         // devminor
        $header .= str_repeat("\0", 155);                       // prefix
        $header  = str_pad($header, 512, "\0");

        $sum = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ord($header[$i]);
        }

        return substr_replace($header, str_pad(decoct($sum), 6, '0', STR_PAD_LEFT) . "\0 ", 148, 8);
    }

    /**
     * 计算 tar 缓冲区中有效载荷的结束偏移（纯函数）
     *
     * 按「头部 512 字节 + 数据补齐到 512 整数倍」逐条推进，遇到首个空头即判定为
     * 归档终止。用解析而非「从尾部剥离空块」，是因为后者会误伤内容恰好整块为空的
     * 条目。偏移始终至少前进 512，损坏数据也不会死循环。
     *
     * @param string $raw 解压后的 tar 缓冲区
     *
     * @return int 有效载荷长度（不含尾部空块）
     */
    protected static function tarPayloadEnd($raw)
    {
        $len = strlen($raw);
        $off = 0;
        while ($off + 512 <= $len) {
            $header = substr($raw, $off, 512);
            if (trim($header, "\0 ") === '') {
                break;
            }
            $size = trim(substr($header, 124, 12), "\0 ");
            $size = $size === '' ? 0 : (int)octdec($size);
            $off += 512 + (int)(ceil($size / 512) * 512);
        }

        return min($off, $len);
    }

    /**
     * gzip 级别归一化到 1~9
     *
     * @param mixed $level
     *
     * @return int
     */
    protected static function normalizeGzipLevel($level)
    {
        $level = (int)$level;

        return ($level >= 1 && $level <= 9) ? $level : 6;
    }
}
