<?php
/**
 * 项目全局启动入口
 *
 * 常用命令：
 *   php start.php start                 启动
 *   php start.php start -d              Linux 守护模式启动
 *   php start.php stop                  停止
 *   php start.php restart -d            重启
 *   php start.php reload                平滑重启业务进程（长连接不中断）
 *   php start.php svc-status            查看进程状态
 *   php start.php connections           查看连接状态
*   php start.php check                 仅执行环境自检
*   php start.php env:init              生成 .env 配置（首次部署必执行）
*   php start.php token <uid> [device_id] [ttl]    生成调试用 Token
*
* 环境配置：
*   端口、地址、密钥、Redis 连接等均从 .env 读取，不硬编码在代码中。
*   变量清单见 .env.example，加载优先级见 src/Common/Env.php。
*   首次部署：php start.php env:init
*
 * 启动角色（APP_ROLE，通过 --role=xxx 指定）：
 *   all（默认）  一次创建全部组件，供 Linux 生产环境使用
 *   register / gateway / udp / business
 *
 * Windows 注意：workerman 限制「单个启动文件只能初始化 1 个 Worker 实例」，
 * 因此 Windows 开发环境必须开 4 个终端按角色分别启动：
 *   php start.php start --role=register
 *   php start.php start --role=gateway
 *   php start.php start --role=udp
 *   php start.php start --role=business
 *
 * 兼容 PHP 8.0 ~ 8.5
 */

define('BASE_PATH', __DIR__);
define('START_AT', microtime(true));

/* ---------------------------------------------------------------------
 | 1. 依赖加载
 --------------------------------------------------------------------- */
$autoload = BASE_PATH . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "[FATAL] 依赖未安装，请先执行：composer install\n");
    exit(1);
}
require $autoload;

use GatewayPush\Business\Auth;
use GatewayPush\Common\Env;
use GatewayPush\Common\Logger;
use Workerman\Worker;

/* ---------------------------------------------------------------------
 | 2. 命令与角色解析
 --------------------------------------------------------------------- */
$argvList = isset($argv) ? $argv : array();
$command  = isset($argvList[1]) ? $argvList[1] : 'help';
$role     = 'all';

// 提取 --role=xxx 并从 argv 中剔除，避免干扰 workerman 自身的命令解析
$cleanArgv = array();
foreach ($argvList as $item) {
    if (strpos($item, '--role=') === 0) {
        $role = substr($item, 7);
        continue;
    }
    $cleanArgv[] = $item;
}
$argv = $cleanArgv;
if (isset($_SERVER['argv'])) {
    $_SERVER['argv'] = $cleanArgv;
}

$validRoles = array('all', 'register', 'gateway', 'udp', 'business');
if (!in_array($role, $validRoles, true)) {
    fwrite(STDERR, '[FATAL] 非法启动角色：' . $role . '，可选值：' . implode(' / ', $validRoles) . "\n");
    exit(1);
}
define('APP_ROLE', $role);

/* ---------------------------------------------------------------------
 | 3. 环境变量与配置加载
 |
 | .env 必须先于配置加载完成 —— config/*.php 全部经 Env 取值，
 | 顺序颠倒会使环境变量失效并静默回退到代码内默认值。
 --------------------------------------------------------------------- */
Env::load(BASE_PATH);

$appConfig      = require BASE_PATH . '/config/app.php';
$gatewayConfig  = require BASE_PATH . '/config/gateway.php';
$businessConfig = require BASE_PATH . '/config/business.php';

date_default_timezone_set($appConfig['app']['timezone']);
Logger::init($appConfig['log']);

// 内部通信密钥归一化：Register / Gateway / BusinessWorker 三方必须一致，
// 未单独配置时回退复用业务鉴权密钥
if (empty($appConfig['internal']['secret'])) {
    $appConfig['internal']['secret'] = (string)$appConfig['auth']['secret'];
}

/* ---------------------------------------------------------------------
 | 4. 运行时目录准备
 --------------------------------------------------------------------- */
foreach (array('runtime_path', 'log_path', 'pid_path') as $key) {
    $dir = $appConfig['runtime'][$key];
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        fwrite(STDERR, '[FATAL] 运行时目录创建失败：' . $dir . "\n");
        exit(1);
    }
}

/* ---------------------------------------------------------------------
 | 5. 自定义命令（不进入 workerman 主流程）
 --------------------------------------------------------------------- */
if (in_array($command, array('help', '-h', '--help'), true)) {
    echo usageText();
    exit(0);
}

if ($command === 'env:init') {
    exit(commandEnvInit(BASE_PATH));
}

if ($command === 'token') {
    Auth::init($appConfig['auth']);

    $uid = isset($cleanArgv[2]) ? (string)$cleanArgv[2] : '';
    if ($uid === '') {
        fwrite(STDERR, "用法：php start.php token <uid> [device_id] [ttl]\n");
        exit(1);
    }
    $deviceId = isset($cleanArgv[3]) ? (string)$cleanArgv[3] : '';
    $ttl      = isset($cleanArgv[4]) ? (int)$cleanArgv[4] : 0;

    $token = Auth::issue(array('uid' => $uid, 'device_id' => $deviceId), $ttl);

    echo "uid       : {$uid}\n";
    echo "device_id : {$deviceId}\n";
    echo 'ttl       : ' . ($ttl > 0 ? $ttl : (int)$appConfig['auth']['token_ttl']) . "s\n";
    echo "token     : {$token}\n";
    exit(0);
}

if ($command === 'check') {
    $result = checkEnvironment($appConfig, $gatewayConfig, $businessConfig, false);
    echo $result['text'];
    exit($result['ok'] ? 0 : 1);
}

/* ---------------------------------------------------------------------
 | 6. 环境自检（所有 workerman 命令前强制执行）
 --------------------------------------------------------------------- */
$envResult = checkEnvironment($appConfig, $gatewayConfig, $businessConfig, true);
if (!$envResult['ok']) {
    echo $envResult['text'];
    fwrite(STDERR, "\n[FATAL] 环境自检未通过，启动终止。修正后可用 php start.php check 复检。\n");
    exit(1);
}

/* ---------------------------------------------------------------------
 | 7. 全局异常捕获
 --------------------------------------------------------------------- */
if (!empty($appConfig['log']['global_handler'])) {
    Logger::registerHandlers();
}

/* ---------------------------------------------------------------------
 | 8. 进程与日志文件路径
 --------------------------------------------------------------------- */
Worker::$pidFile    = $appConfig['runtime']['pid_path'] . '/workerman_' . $role . '.pid';
Worker::$logFile    = $appConfig['runtime']['log_path'] . '/workerman.log';
Worker::$stdoutFile = $appConfig['runtime']['log_path'] . '/stdout.log';

/* ---------------------------------------------------------------------
 | 9. 平台约束校验
 --------------------------------------------------------------------- */
if (DIRECTORY_SEPARATOR !== '/' && $role === 'all') {
    echo usageText();
    fwrite(STDERR, "\n[FATAL] Windows 下不支持单文件启动全部组件（workerman 限制）。\n");
    fwrite(STDERR, "        请按角色分别启动，或改用 Linux 部署。\n");
    exit(1);
}

/* ---------------------------------------------------------------------
 | 10. 启动
 --------------------------------------------------------------------- */
GatewayPush\Gateway\Bootstrap::init($gatewayConfig, $appConfig);
GatewayPush\Business\Bootstrap::init($businessConfig, $appConfig);

Worker::runAll();

/* =====================================================================
 | 辅助函数
 ===================================================================== */

/**
 * 环境自检
 *
 * @param array $appConfig
 * @param array $gatewayConfig
 * @param array $businessConfig
 * @param bool  $verbose 是否输出完整报告
 * @return array ['ok' => bool, 'text' => string]
 */
function checkEnvironment(array $appConfig, array $gatewayConfig, array $businessConfig, $verbose = true)
{
    $runtime = $appConfig['runtime'];
    $lines   = array();
    $ok      = true;
    $isLinux = DIRECTORY_SEPARATOR === '/';

    $lines[] = 'GatewayWorker 推送服务 - 运行环境自检';
    $lines[] = str_repeat('=', 70);
    $lines[] = 'PHP 版本  : ' . PHP_VERSION . ' (' . PHP_SAPI . ')';
    $lines[] = '操作系统  : ' . PHP_OS . ($isLinux ? ' [多进程模式]' : ' [单进程模式]');
    $lines[] = '启动角色  : ' . (defined('APP_ROLE') ? APP_ROLE : 'all');
    $lines[] = str_repeat('-', 70);

    // PHP 版本
    $phpMin    = $runtime['php_min'];
    $verOk     = version_compare(PHP_VERSION, $phpMin, '>=');
    $lines[]   = sprintf('[%-4s] PHP 版本 >= %s', $verOk ? 'OK' : 'FAIL', $phpMin);
    $ok        = $ok && $verOk;

    $phpMaxWarn = $runtime['php_max_warn'];
    if (version_compare(PHP_VERSION, $phpMaxWarn, '>')) {
        $lines[] = sprintf('[%-4s] PHP 版本高于已验证上限 %s，请评估兼容性', 'WARN', $phpMaxWarn);
    }

    // 必需扩展
    foreach ($runtime['require_ext'] as $ext) {
        $loaded  = extension_loaded($ext);
        $lines[] = sprintf('[%-4s] 必需扩展 %s', $loaded ? 'OK' : 'FAIL', $ext);
        $ok      = $ok && $loaded;
    }

    // Linux 专属扩展
    if ($isLinux) {
        foreach ($runtime['require_ext_linux'] as $ext) {
            $loaded  = extension_loaded($ext);
            $lines[] = sprintf('[%-4s] Linux 必需扩展 %s', $loaded ? 'OK' : 'FAIL', $ext);
            $ok      = $ok && $loaded;
        }
    } else {
        $lines[] = '[SKIP] Windows 环境跳过 pcntl / posix 检查（多进程不可用）';
    }

    // 可选扩展
    foreach ($runtime['optional_ext'] as $ext) {
        if (extension_loaded($ext)) {
            $lines[] = sprintf('[%-4s] 可选扩展 %s', 'OK', $ext);
        } else {
            $lines[] = sprintf('[%-4s] 可选扩展 %s 未安装（不影响运行，性能可优化）', 'WARN', $ext);
        }
    }

    // 运行时目录
    foreach (array('runtime_path', 'log_path', 'pid_path') as $key) {
        $dir = $runtime[$key];
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $writable = is_dir($dir) && is_writable($dir);
        $lines[]  = sprintf('[%-4s] 目录可写 %s', $writable ? 'OK' : 'FAIL', $dir);
        $ok       = $ok && $writable;
    }

    // 配置一致性
    $registerMatch = $gatewayConfig['register']['listen'] === $businessConfig['register_address'];
    $lines[]       = sprintf(
        '[%-4s] 注册中心地址一致（gateway: %s / business: %s）',
        $registerMatch ? 'OK' : 'FAIL',
        $gatewayConfig['register']['listen'],
        $businessConfig['register_address']
    );
    $ok = $ok && $registerMatch;

    $queueMatch = $gatewayConfig['udp']['queue']['key'] === $businessConfig['udp_queue']['key'];
    $lines[]    = sprintf('[%-4s] UDP 队列 key 一致（%s）', $queueMatch ? 'OK' : 'FAIL', $gatewayConfig['udp']['queue']['key']);
    $ok         = $ok && $queueMatch;

    // 环境配置来源
    $envFiles = Env::loadedFiles();
    if (empty($envFiles)) {
        $lines[] = '[WARN] 未找到 .env（当前全部使用代码内默认值），可执行 php start.php env:init 生成';
    } else {
        $lines[] = sprintf(
            '[%-4s] 环境配置 %s（APP_ENV=%s，右侧覆盖左侧）',
            'OK',
            implode(' -> ', $envFiles),
            Env::envName()
        );
    }
    if (Env::lastError() !== '') {
        $lines[] = '[FAIL] .env 解析失败：' . Env::lastError();
        $ok      = false;
    }

    // 安全项
    $internalOk = !empty($appConfig['internal']['secret']);
    $lines[]    = sprintf(
        '[%-4s] 内部通信密钥（Register / Gateway / BusinessWorker 一致性）',
        $internalOk ? 'OK' : 'FAIL'
    );
    $ok = $ok && $internalOk;

    $authEnabled = !empty($appConfig['auth']['enable']);
    $authSecret  = (string)$appConfig['auth']['secret'];

    if (!$authEnabled) {
        $lines[] = '[WARN] 连接鉴权已关闭（AUTH_ENABLE=false），生产环境务必开启';
    } elseif ($authSecret === '') {
        $lines[] = '[FAIL] 鉴权密钥为空，请执行 php start.php env:init 生成 .env';
        $ok      = false;
    } elseif (isPlaceholderSecret($authSecret)) {
        $lines[] = '[FAIL] 鉴权密钥为占位值，生产环境必须替换为高强度随机值';
        $ok      = false;
    } elseif (strlen($authSecret) < 32) {
        $lines[] = sprintf(
            '[%-4s] 鉴权密钥长度仅 %d，建议改用 64 位十六进制随机值',
            'WARN',
            strlen($authSecret)
        );
    } else {
        $lines[] = sprintf('[%-4s] 鉴权密钥已配置（长度 %d）', 'OK', strlen($authSecret));
    }

    if (empty($appConfig['auth']['sign_enable'])) {
        $lines[] = '[WARN] 报文签名校验已关闭，UDP 报文可被伪造';
    } else {
        $lines[] = '[OK  ] 报文签名校验已开启';
    }

    // 端口占用探测（仅提示，不阻断：restart 场景下端口被自身占用属正常）
    $ports = array();
    if (!empty($gatewayConfig['websocket']['enable'])) {
        $ports['WebSocket'] = $gatewayConfig['websocket']['listen'];
    }
    if (!empty($gatewayConfig['udp']['enable'])) {
        $ports['UDP'] = $gatewayConfig['udp']['listen'];
    }
    if (!empty($gatewayConfig['register']['enable'])) {
        $ports['Register'] = 'tcp://' . $gatewayConfig['register']['listen'];
    }
    foreach ($ports as $label => $listen) {
        $probe = probePort($listen);
        $lines[] = sprintf('[%-4s] %s 端口 %s%s', $probe ? 'WARN' : 'OK', $label,
            preg_replace('#^[a-z]+://#i', '', $listen), $probe ? ' 已被占用（若为本服务实例可忽略）' : '');
    }

    $lines[] = str_repeat('=', 70);
    $lines[] = $ok ? '自检结论：通过' : '自检结论：未通过，请修正上述 FAIL 项';
    $lines[] = '';

    return array('ok' => $ok, 'text' => implode("\n", $lines) . "\n");
}

/**
 * 端口占用探测
 *
 * @param string $listen 形如 websocket://0.0.0.0:8282 / udp://0.0.0.0:8283 / tcp://127.0.0.1:1238
 * @return bool true 表示已被占用
 */
function probePort($listen)
{
    $isUdp  = stripos($listen, 'udp://') === 0;
    $target = preg_replace('#^[a-z]+://#i', '', $listen);
    $target = str_replace('0.0.0.0', '127.0.0.1', $target);

    $errno  = 0;
    $errstr = '';
    $socket = @stream_socket_server(
        ($isUdp ? 'udp://' : 'tcp://') . $target,
        $errno,
        $errstr,
        STREAM_SERVER_BIND
    );

    if ($socket === false) {
        return true;
    }
    @fclose($socket);
    return false;
}

/**
 * 判断密钥是否为占位值或明显弱值
 *
 * @param string $secret
 * @return bool
 */
function isPlaceholderSecret($secret)
{
    $secret = (string)$secret;

    $placeholders = array(
        'change_me', 'changeme', 'change_me_gateway_push_auth_secret',
        'secret', 'password', '123456', 'test', 'demo', 'example',
        'your_secret', 'your-secret', 'todo',
    );

    if (in_array(strtolower($secret), $placeholders, true)) {
        return true;
    }

    // 单字符重复（如 aaaaaa... / 000000...）
    if (preg_match('/^(.)\1+$/', $secret) === 1) {
        return true;
    }

    return false;
}

/**
 * 初始化 .env 配置文件
 *
 * 文件不存在：从 .env.example 复制，并为密钥项注入随机值
 * 文件已存在：仅补齐仍为空的密钥项，绝不覆盖已有取值
 *
 * @param string $basePath
 * @return int 退出码
 */
function commandEnvInit($basePath)
{
    $target  = $basePath . '/.env';
    $example = $basePath . '/.env.example';

    if (!is_file($example)) {
        fwrite(STDERR, '[FATAL] 缺少模板文件：' . $example . "\n");
        return 1;
    }

    $template = @file_get_contents($example);
    if (!is_string($template) || $template === '') {
        fwrite(STDERR, '[FATAL] 模板文件不可读：' . $example . "\n");
        return 1;
    }

    // 需要自动注入强度的密钥项
    $secretKeys = array('AUTH_SECRET', 'INTERNAL_SECRET');

    if (!is_file($target)) {
        foreach ($secretKeys as $key) {
            $template = (string)preg_replace(
                '/^[ \t]*' . preg_quote($key, '/') . '[ \t]*=[ \t]*$/m',
                $key . '=' . Env::generateSecret(),
                $template
            );
        }

        if (@file_put_contents($target, $template) === false) {
            fwrite(STDERR, '[FATAL] 写入失败：' . $target . "\n");
            return 1;
        }

        echo "已生成配置文件：" . $target . "\n";
        echo "  随机注入：" . implode(' , ', $secretKeys) . "\n";
        echo "\n下一步：php start.php check\n";
        return 0;
    }

    // 已存在：仅补齐空值密钥，不触碰已有取值
    $current = @file_get_contents($target);
    if (!is_string($current)) {
        fwrite(STDERR, '[FATAL] 配置文件不可读：' . $target . "\n");
        return 1;
    }

    $patched = array();
    foreach ($secretKeys as $key) {
        $pattern = '/^[ \t]*' . preg_quote($key, '/') . '[ \t]*=[ \t]*$/m';
        if (preg_match($pattern, $current) === 1) {
            $current   = (string)preg_replace($pattern, $key . '=' . Env::generateSecret(), $current);
            $patched[] = $key;
        }
    }

    if (empty($patched)) {
        echo "配置文件已存在且密钥项均已配置，未做改动：" . $target . "\n";
        return 0;
    }

    if (@file_put_contents($target, $current) === false) {
        fwrite(STDERR, '[FATAL] 写入失败：' . $target . "\n");
        return 1;
    }

    echo "已补齐空值密钥：" . implode(' , ', $patched) . "\n";
    echo "文件：" . $target . "\n";
    return 0;
}

/**
 * 使用说明
 *
 * @return string
 */
function usageText()
{
    $isLinux = DIRECTORY_SEPARATOR === '/';
    $text    = array();
    $text[]  = 'GatewayWorker 实时数据推送服务（WebSocket + UDP 双协议）';
    $text[]  = str_repeat('=', 70);
    $text[]  = '用法：php start.php <command> [--role=<role>]';
    $text[]  = '';
    $text[]  = '命令：';
    $text[]  = '  start         启动服务';
    $text[]  = '  start -d      ' . ($isLinux ? '守护模式启动' : '（Windows 不支持守护模式，将以前台运行）');
    $text[]  = '  stop          停止服务';
    $text[]  = '  restart       重启服务';
    $text[]  = '  reload        平滑重启业务进程（网关长连接不中断）';
    $text[]  = '  svc-status    查看进程运行状态';
    $text[]  = '  connections   查看连接状态';
    $text[]  = '  check         仅执行环境自检';
    $text[]  = '  env:init      生成 .env 配置（不存在则从模板创建并注入随机密钥）';
    $text[]  = '  token <uid> [device_id] [ttl]   生成调试用 Token';
    $text[]  = '';
    $text[]  = '角色（--role）：all / register / gateway / udp / business';
    $text[]  = '';
    if ($isLinux) {
        $text[] = 'Linux 单机部署：php start.php start -d';
    } else {
        $text[] = 'Windows 开发环境需按角色分别启动（4 个终端）：';
        $text[] = '  php start.php start --role=register';
        $text[] = '  php start.php start --role=gateway';
        $text[] = '  php start.php start --role=udp';
        $text[] = '  php start.php start --role=business';
    }
    $text[] = '';
    return implode("\n", $text) . "\n";
}
