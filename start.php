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
 *   php start.php info                  打印启动信息（环境 / 框架版本 / 服务清单）
 *   php start.php roles                 输出各角色启用清单（JSON）
 *   php start.php env:init              生成 .env 配置（首次部署必执行）
 *   php start.php token <uid> [device_id] [ttl]    生成调试用 Token
 *   php start.php push <target_type> <target> [payload] [msg_id] [offline_mode]
 *
 * 环境配置：
 *   端口、地址、密钥、Redis 连接等均从 .env 读取，不硬编码在代码中。
 *   变量清单见 .env.example，加载优先级见 src/Common/Env.php。
 *   首次部署：php start.php env:init
 *
 * 启动角色（APP_ROLE，通过 --role=xxx 指定）：
 *   all（默认）  一次创建全部组件，供 Linux 生产环境使用
 *   register / gateway / udp / business / api / dashboard
 *
 * Windows 注意：workerman 限制「单个启动文件只能初始化 1 个 Worker 实例」，
 * 因此 Windows 开发环境必须开多个终端按角色分别启动：
 *   php start.php start --role=register
 *   php start.php start --role=gateway
 *   php start.php start --role=udp
 *   php start.php start --role=business
 *   php start.php start --role=api
 *
 * ---------------------------------------------------------------------
 * 本文件只负责「入口编排」：依赖加载 -> 角色解析 -> 配置加载 -> 命令分发 ->
 * 环境自检 -> 平台校验 -> 启动。各子命令的实现、自检报告与横幅文本的拼装
 * 全部下沉到 src/Console/，**退出码统一在本文件决定**。
 *
 * 这条边界是硬性的：src/ 内不允许出现 exit / die（常驻进程里退出等于整进程
 * 静默消失），所以 Console 类一律以「返回值」表达结果，由入口翻译成退出码。
 * ---------------------------------------------------------------------
 *
 * 兼容 PHP 8.1 ~ 8.5
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
use GatewayPush\Console\Banner;
use GatewayPush\Console\Commands;
use GatewayPush\Console\EnvChecker;
use GatewayPush\Console\PushCommand;
use Workerman\Worker;

/* ---------------------------------------------------------------------
 | 2. 命令与角色解析
 --------------------------------------------------------------------- */
$argvList = $argv ?? array();
$command  = $argvList[1] ?? 'help';
$role     = 'all';

// 提取 --role=xxx 并从 argv 中剔除，避免干扰 workerman 自身的命令解析
$cleanArgv = array();
foreach ($argvList as $item) {
    if (str_starts_with($item, '--role=')) {
        $role = substr($item, 7);
        continue;
    }
    $cleanArgv[] = $item;
}
$argv = $cleanArgv;
if (isset($_SERVER['argv'])) {
    $_SERVER['argv'] = $cleanArgv;
}

$validRoles = array('all', 'register', 'gateway', 'udp', 'business', 'api', 'dashboard');
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
$actionConfig   = require BASE_PATH . '/config/actions.php';

date_default_timezone_set($appConfig['app']['timezone']);
Logger::init($appConfig['log']);
// 进程级日志通道取启动角色：Windows 下即具体角色；Linux --role=all 时为 'all'，
// 各组件会在自身 onWorkerStart 内再次 useChannel 切到真实角色。
Logger::useChannel($role);

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
    echo Commands::usage();
    exit(0);
}

if ($command === 'env:init') {
    exit(Commands::envInit(BASE_PATH));
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

if ($command === 'push') {
    exit(PushCommand::run($appConfig, $gatewayConfig, $businessConfig, $cleanArgv));
}

if ($command === 'check') {
    $result = EnvChecker::check($appConfig, $gatewayConfig, $businessConfig, $actionConfig);
    echo $result['text'];
    exit($result['ok'] ? 0 : 1);
}

if ($command === 'info') {
    // 可选参数为逗号分隔的角色列表，供管理脚本按实际启动范围过滤；
    // 非角色名一律忽略而非报错 —— 该命令是只读展示，不应因参数写法失败。
    $infoRoles = array();
    if (isset($cleanArgv[2]) && trim((string)$cleanArgv[2]) !== '') {
        foreach (explode(',', strtolower((string)$cleanArgv[2])) as $infoItem) {
            $infoItem = trim($infoItem);
            if ($infoItem !== '' && $infoItem !== 'all' && in_array($infoItem, $validRoles, true)) {
                $infoRoles[] = $infoItem;
            }
        }
    }

    echo Banner::render($appConfig, $gatewayConfig, $businessConfig, $infoRoles, true);
    exit(0);
}

if ($command === 'roles') {
    // 机器可读的角色启用清单，供管理脚本消费：bin\start.ps1 据此**在启动前**跳过被
    // 配置关闭的角色 —— 否则各角色独立窗口的编排会白等一轮就绪超时（25s），并把
    // 「配置关闭」误报成「启动失败」，最终以非 0 退出码收尾。
    //
    // 输出格式即契约，消费方读取 role / enabled / env 三个字段，不解析人读文案。
    echo Commands::roles($gatewayConfig, $businessConfig, $appConfig) . "\n";
    exit(0);
}

/* ---------------------------------------------------------------------
 | 6. 环境自检（所有 workerman 命令前强制执行）
 --------------------------------------------------------------------- */
$envResult = EnvChecker::check($appConfig, $gatewayConfig, $businessConfig, $actionConfig);
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

// workerman 框架日志单文件上限：超出后原地截断、仅保留后半（前半丢弃），0 = 不轮转。
// 显式赋值而非依赖 vendor 默认值，便于经 .env 调整；workerman 不提供归档式轮转。
Worker::$logFileMaxSize = (int)$appConfig['log']['max_size_mb'] * 1024 * 1024;

/* ---------------------------------------------------------------------
 | 9. 平台约束校验
 --------------------------------------------------------------------- */
if (DIRECTORY_SEPARATOR !== '/' && $role === 'all') {
    echo Commands::usage();
    fwrite(STDERR, "\n[FATAL] Windows 下不支持单文件启动全部组件（workerman 限制）。\n");
    fwrite(STDERR, "        请按角色分别启动，或改用 Linux 部署。\n");
    exit(1);
}

/* ---------------------------------------------------------------------
 | 9.5 启动信息横幅
 |
 | 打印点必须在 Worker::runAll() 之前：daemonize() 由 runAll() 内部执行，
 | 在此之前 STDOUT 仍然连接终端，因此守护模式（-d）下同样可见。
 |
 | 这是本项目自建横幅而非依赖 workerman displayUI() 的原因 —— 后者全程走
 | Worker::log()，而 log() 首行即判断 !$daemonize，守护模式下整块 UI
 | （版本行 + WORKERS 表）只落 runtime/logs/workerman.log，终端上完全看不到。
 |
 | 带 -q 时跳过，与 workerman 自身的静默语义保持一致。
 --------------------------------------------------------------------- */
if (in_array($command, array('start', 'restart'), true) && !in_array('-q', $cleanArgv, true)) {
    echo Banner::render(
        $appConfig,
        $gatewayConfig,
        $businessConfig,
        $role === 'all' ? array() : array($role),
        false,
        in_array('-d', $cleanArgv, true) ? 'DAEMON' : 'DEBUG'
    );
}

/* ---------------------------------------------------------------------
 | 10. 启动
 --------------------------------------------------------------------- */
GatewayPush\Gateway\Bootstrap::init($gatewayConfig, $appConfig);
GatewayPush\Business\Bootstrap::init($businessConfig, $appConfig, $gatewayConfig, $actionConfig);
GatewayPush\Api\Bootstrap::init($appConfig, $businessConfig, $actionConfig);
GatewayPush\Dashboard\Bootstrap::init($appConfig);

Worker::runAll();
