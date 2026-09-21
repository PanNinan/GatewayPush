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
*   php start.php push <target_type> <target> [payload] [msg_id] [offline_mode]   提交一条定向推送任务
*
* 环境配置：
*   端口、地址、密钥、Redis 连接等均从 .env 读取，不硬编码在代码中。
*   变量清单见 .env.example，加载优先级见 src/Common/Env.php。
*   首次部署：php start.php env:init
*
* 启动角色（APP_ROLE，通过 --role=xxx 指定）：
*   all（默认）  一次创建全部组件，供 Linux 生产环境使用
*   register / gateway / udp / business / api
*
* Windows 注意：workerman 限制「单个启动文件只能初始化 1 个 Worker 实例」，
* 因此 Windows 开发环境必须开多个终端按角色分别启动：
*   php start.php start --role=register
*   php start.php start --role=gateway
*   php start.php start --role=udp
*   php start.php start --role=business
*   php start.php start --role=api
*
 * 兼容 PHP 8.1 ~ 8.5
 */
error_reporting(E_ALL & ~E_DEPRECATED);
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
use GatewayPush\Business\Push;
use GatewayPush\Common\Env;
use GatewayPush\Common\Logger;
use GatewayPush\Common\RedisClient;
use Workerman\Timer;
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

if ($command === 'push') {
    exit(commandPush($appConfig, $gatewayConfig, $businessConfig, $cleanArgv));
}

if ($command === 'check') {
    $result = checkEnvironment($appConfig, $gatewayConfig, $businessConfig, $actionConfig, false);
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

    echo startupBanner($appConfig, $gatewayConfig, $businessConfig, $infoRoles, true);
    exit(0);
}

if ($command === 'roles') {
    // 机器可读的角色启用清单，供管理脚本消费：bin\start.ps1 据此**在启动前**跳过被
    // 配置关闭的角色 —— 否则各角色独立窗口的编排会白等一轮就绪超时（25s），并把
    // 「配置关闭」误报成「启动失败」，最终以非 0 退出码收尾。
    //
    // 真值必须取自 PHP 侧配置而非脚本自行解析 .env：.env < .env.{env} < .env.local
    // < .env.{env}.local < 真实环境变量的叠加语义只有 Env 类能正确还原，脚本再实现
    // 一遍必然漂移。输出格式即契约，消费方读取 role / enabled / env 三个字段。
    echo commandRoles($gatewayConfig, $businessConfig, $appConfig) . "\n";
    exit(0);
}

/* ---------------------------------------------------------------------
 | 6. 环境自检（所有 workerman 命令前强制执行）
 --------------------------------------------------------------------- */
$envResult = checkEnvironment($appConfig, $gatewayConfig, $businessConfig, $actionConfig, true);
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
    echo usageText();
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
    echo startupBanner(
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

/* =====================================================================
 | 辅助函数
 ===================================================================== */

/**
 * 环境自检
 *
 * @param array $appConfig
 * @param array $gatewayConfig
 * @param array $businessConfig
 * @param array $actionConfig   config/actions.php
 * @param bool  $verbose        是否输出完整报告
 * @return array ['ok' => bool, 'text' => string]
 */
function checkEnvironment(array $appConfig, array $gatewayConfig, array $businessConfig, array $actionConfig = array(), $verbose = true)
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
            if (! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $dir));
            }
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

    // 定向推送
    if (empty($appConfig['push']['enable'])) {
        $lines[] = '[WARN] 定向推送已关闭（PUSH_ENABLE=false）';
    } else {
        $mode    = (string)$appConfig['push']['offline_mode'];
        $modeOk  = in_array($mode, array('drop', 'queue'), true);
        $lines[] = sprintf(
            '[%-4s] 定向推送已开启（离线策略 %s，指令队列 %s）',
            $modeOk ? 'OK' : 'FAIL',
            $mode,
            $businessConfig['push_queue']['key']
        );
        $ok = $ok && $modeOk;

        $outKey = isset($gatewayConfig['udp']['out_queue']['key']) ? $gatewayConfig['udp']['out_queue']['key'] : '';
        if (!empty($gatewayConfig['udp']['enable']) && $outKey !== '') {
            $lines[] = sprintf('[%-4s] UDP 出站队列 %s', 'OK', $outKey);
        }
    }

    // HTTP 推送接口
    if (!empty($appConfig['api']['enable'])) {
        $apiOwnSecret = (string)$appConfig['api']['secret'];
        $apiSecret    = $apiOwnSecret !== '' ? $apiOwnSecret : (string)$appConfig['auth']['secret'];

        if ($apiSecret === '') {
            $lines[] = '[FAIL] HTTP 接口密钥为空（API_SECRET 未配置，且 AUTH_SECRET 亦为空）';
            $ok      = false;
        } elseif ($apiOwnSecret === '' && isPlaceholderSecret($apiSecret)) {
            $lines[] = '[FAIL] HTTP 接口复用 AUTH_SECRET，但该密钥为占位值';
            $ok      = false;
        } else {
            $lines[] = sprintf(
                '[%-4s] HTTP 接口密钥已配置（%s）',
                'OK',
                $apiOwnSecret !== '' ? 'API_SECRET 独立配置' : '复用 AUTH_SECRET'
            );
        }

        $signTtl = (int)$appConfig['api']['sign_ttl'];
        if ($signTtl <= 0) {
            $lines[] = '[WARN] 接口时间戳窗口为 0（关闭防重放校验），生产环境不建议';
        }

        // 接口验签。关闭仅在回环监听时生效（见 Bootstrap::signEnabled）：
        // 开关被护栏拦下时只告警不阻断 —— 运行时仍是「强制验签」，属安全侧，
        // 没必要因此拒绝启动，但必须让配置者知道自己的配置没生效。
        $apiListen  = (string)$appConfig['api']['listen'];
        $apiSignOff = empty($appConfig['api']['sign_enable']);
        $apiLoop    = \GatewayPush\Api\Bootstrap::isLoopbackHost($apiListen);

        if (!$apiSignOff) {
            $lines[] = '[OK  ] 接口验签已开启';
        } elseif ($apiLoop) {
            $lines[] = sprintf(
                '[%-4s] 接口验签已关闭（API_SIGN_ENABLE=false，监听 %s），所有请求无需签名即可调用；'
                . '该开关仅回环监听生效，生产环境务必开启',
                'WARN',
                $apiListen
            );
        } else {
            $lines[] = sprintf(
                '[%-4s] API_SIGN_ENABLE=false 被忽略：接口监听地址非回环（%s），已强制开启验签',
                'WARN',
                $apiListen
            );
        }
    } else {
        $lines[] = '[WARN] HTTP 推送接口已关闭（API_ENABLE=false），仅支持队列触发';
    }

    // 报文级限流
    if (empty($appConfig['rate_limit']['enable'])) {
        $lines[] = '[WARN] 报文级限流已关闭（RATE_LIMIT_ENABLE=false）';
    } else {
        $rateConf = $appConfig['rate_limit'];
        $dims     = array('conn' => '连接', 'uid' => '用户', 'ip' => 'IP', 'ping' => '心跳');
        $active   = 0;
        $desc     = array();
        $badBurst = array();

        foreach ($dims as $dim => $label) {
            $rate  = (int)$rateConf[$dim]['rate'];
            $burst = (int)$rateConf[$dim]['burst'];

            if ($rate <= 0) {
                $desc[] = $label . ' 已关闭';
                continue;
            }
            $active++;
            if ($burst < $rate) {
                $badBurst[] = $label;
            }
            $desc[] = sprintf('%s %d/%d', $label, $rate, $burst);
        }

        if ($active === 0) {
            $lines[] = '[FAIL] 报文级限流已开启但全部维度速率均为 0，等同于未限流';
            $ok      = false;
        } else {
            $lines[] = sprintf(
                '[%-4s] 报文级限流已开启（%s，格式为 维度 速率/突发）',
                'OK',
                implode('，', $desc)
            );
        }

        if ($badBurst) {
            $lines[] = sprintf(
                '[WARN] 维度 %s 的 burst 小于 rate，已按 rate 兜底修正（突发能力被压缩）',
                implode('/', $badBurst)
            );
        }

        if ((int)$rateConf['mem_max_buckets'] < 1000) {
            $lines[] = sprintf(
                '[WARN] 限流内存桶上限过低（%d），高并发下会频繁淘汰',
                (int)$rateConf['mem_max_buckets']
            );
        }
    }

    // 业务动作清单（config/actions.php）
    $actionList = isset($actionConfig['actions']) && is_array($actionConfig['actions'])
        ? $actionConfig['actions']
        : array();

    if (!$actionList) {
        $lines[] = '[FAIL] 业务动作清单为空（config/actions.php 的 actions 段未配置任何动作）';
        $ok      = false;
    } else {
        $invalid  = array();
        $silent   = array();
        $noAuth   = array();
        $noTimeout = array();

        foreach ($actionList as $name => $decl) {
            $handler = is_array($decl) && isset($decl['handler']) ? (string)$decl['handler'] : '';
            if ($handler === '' || !class_exists($handler)
                || !in_array('GatewayPush\\Business\\ActionInterface', class_implements($handler), true)) {
                $invalid[] = $name;
                continue;
            }

            // 回执方式：数组为按通道分别声明，字符串为两通道共用
            $reply    = is_array($decl) && array_key_exists('reply', $decl) ? $decl['reply'] : null;
            $udpReply = is_array($reply)
                ? (isset($reply['udp']) ? (string)$reply['udp'] : 'sync')
                : (is_string($reply) ? $reply : 'sync');
            if ($udpReply === 'none') {
                $silent[] = $name;
            }

            if (is_array($decl) && array_key_exists('auth', $decl) && empty($decl['auth'])) {
                $noAuth[] = $name;
            }

            if (is_array($decl) && array_key_exists('timeout', $decl) && (int)$decl['timeout'] === 0) {
                $noTimeout[] = $name;
            }
        }

        if ($invalid) {
            $lines[] = sprintf(
                '[FAIL] 业务动作处理器不可用：%s（类不存在或未实现 ActionInterface）',
                implode('/', $invalid)
            );
            $ok = false;
        } else {
            $lines[] = sprintf(
                '[%-4s] 业务动作清单共 %d 个（%s）',
                'OK',
                count($actionList),
                implode('/', array_keys($actionList))
            );
        }

        if ($silent) {
            $lines[] = sprintf(
                '[%-4s] UDP 通道静默回执的动作：%s（处理但不下发，避免双向流量放大）',
                'OK',
                implode('/', $silent)
            );
        }
        if ($noTimeout) {
            $lines[] = sprintf(
                '[WARN] 以下动作关闭了回执超时保护（timeout=0），异步回执丢失时客户端会永久等待：%s',
                implode('/', $noTimeout)
            );
        }
        if ($noAuth) {
            $lines[] = sprintf(
                '[WARN] 以下动作声明为无需鉴权（auth=false）：%s —— UDP 通道没有连接级鉴权闸门，'
                . '该声明即为其唯一身份校验，请确认确属公开动作',
                implode('/', $noAuth)
            );
        }
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
    if (!empty($appConfig['api']['enable'])) {
        $ports['HTTP-API'] = $appConfig['api']['listen'];
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

    // Windows 的 socket 默认允许重复 bind（PHP 未暴露 SO_EXCLUSIVEADDRUSE，
    // stream_socket_server 也不会设置它），端口已被监听时本地 bind 依然成功 ——
    // 用 bind 判定会恒返回"未占用"，使占用提示与监听状态彻底失效。
    // 故 Windows 改用 netstat 快照判定；Linux 无此特性，bind 探测即准确。
    if (DIRECTORY_SEPARATOR !== '/' && function_exists('exec')) {
        $colon = strrpos($target, ':');
        if ($colon !== false) {
            $ports = usedPortsByNetstat($isUdp ? 'udp' : 'tcp');
            return isset($ports[(int)substr($target, $colon + 1)]);
        }
    }

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
 * 本机已占用端口快照（仅 Windows 使用）
 *
 * netstat 输出的状态列在中文 Windows 下仍为英文（LISTENING），可安全匹配。
 * 结果按协议缓存，多个端口共用一次进程调用 —— check 要探测 5 个端口，
 * 逐端口调用 netstat 会带来数百毫秒的无谓开销。
 *
 * @param string $protocol 'tcp' 或 'udp'
 * @return array 端口号 => true
 */
function usedPortsByNetstat($protocol)
{
    static $cache = array();

    if (isset($cache[$protocol])) {
        return $cache[$protocol];
    }

    $ports = array();
    $lines = array();
    @exec('netstat -a -n -p ' . strtoupper($protocol), $lines);

    foreach ($lines as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (!is_array($parts) || count($parts) < 3) {
            continue;
        }
        if (strcasecmp($parts[0], $protocol) !== 0) {
            continue;
        }
        // TCP 只认监听态：ESTABLISHED 行里的"本地地址"是本机客户端用的临时端口，
        // 与服务监听无关，计入会凭空制造端口冲突假象。
        if ($protocol === 'tcp' && !in_array('LISTENING', $parts, true)) {
            continue;
        }

        $colon = strrpos($parts[1], ':');
        if ($colon === false) {
            continue;
        }
        $port = (int)substr($parts[1], $colon + 1);
        if ($port > 0) {
            $ports[$port] = true;
        }
    }

    $cache[$protocol] = $ports;
    return $ports;
}

/**
 * 启动信息横幅
 *
 * 打印时机必须早于 Worker::runAll() —— daemonize() 由 runAll() 内部执行，
 * 在此之前 STDOUT 仍连接终端，因此守护模式（-d）下同样可见。
 *
 * 这正是本项目需要自建横幅的原因：workerman 自带的 displayUI() 全程走
 * Worker::log()，而 log() 首行即判断 !$daemonize，守护模式下整块 UI
 * （版本行 + WORKERS 表）只落 runtime/logs/workerman.log，终端上看不到。
 *
 * @param array  $appConfig      config/app.php
 * @param array  $gatewayConfig  config/gateway.php
 * @param array  $businessConfig config/business.php
 * @param array  $roles          只列这些角色；空数组 = 按配置列出全部相关角色
 * @param bool   $withProbe      是否探测端口监听状态（启动前端口必然空闲，故仅 info 命令启用）
 * @param string $modeLabel      启动模式标签（DAEMON / DEBUG），空串则不显示该行
 * @return string
 */
function startupBanner(array $appConfig, array $gatewayConfig, array $businessConfig, array $roles = array(), $withProbe = false, $modeLabel = '')
{
    $isLinux  = DIRECTORY_SEPARATOR === '/';
    $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);

    // 角色清单（角色 -> 进程名 / 监听 / 进程数 / 启用开关）的唯一真源：
    // 与 roles 命令、Windows 管理脚本共用同一份，避免角色名与开关名在多处各写一遍。
    // 进程数口径、business 无独立开关等约定见 RoleCatalog 头部注释。
    $services = \GatewayPush\Common\RoleCatalog::build(
        $gatewayConfig,
        $businessConfig,
        $appConfig,
        $isLinux
    );

    // 项目内路径显示为相对形式，避免横幅里反复出现本机绝对路径
    $relative = function ($path) use ($basePath) {
        $path = str_replace('\\', '/', (string)$path);
        $root = rtrim(str_replace('\\', '/', $basePath), '/') . '/';
        return strncmp($path, $root, strlen($root)) === 0 ? substr($path, strlen($root)) : $path;
    };

    $envFiles = Env::loadedFiles();
    $envDesc  = $envFiles
        ? implode(' -> ', array_map($relative, $envFiles))
        : '（未找到，全部使用代码内默认值）';

    $lines   = array();
    $lines[] = 'GatewayWorker 实时数据推送服务 - 启动信息';
    $lines[] = str_repeat('=', 70);
    $lines[] = 'PHP 版本  : ' . PHP_VERSION . ' (' . PHP_SAPI . ') / ' . PHP_OS_FAMILY
        . ($isLinux ? ' [多进程模式]' : ' [单进程模式]');
    $lines[] = '启动角色  : ' . (defined('APP_ROLE') ? APP_ROLE : 'all');
    if ($modeLabel !== '') {
        $lines[] = '启动模式  : ' . $modeLabel;
    }
    $lines[] = '环境配置  : ' . $appConfig['app']['env'] . '（' . $envDesc . '）';

    // 接口验签状态。免签是安全相关状态，必须在启动时就可见 —— 它不像日志级别
    // 那样只影响可观测性，而是直接影响接口的对外开放程度。
    if (!empty($appConfig['api']['enable'])) {
        $apiSignOn = empty($appConfig['api']['sign_enable'])
            ? !\GatewayPush\Api\Bootstrap::isLoopbackHost((string)$appConfig['api']['listen'])
            : true;
        $lines[] = '接口验签  : ' . ($apiSignOn
            ? '已开启'
            : '已关闭（本地调试免签，仅回环监听生效）');
    }

    $lines[] = '时区      : ' . date_default_timezone_get();
    $lines[] = '运行目录  : ' . rtrim($relative($appConfig['runtime']['runtime_path']), '/') . '/'
        . '  (日志 ' . rtrim($relative($appConfig['runtime']['log_path']), '/') . '/'
        . '，进程 ' . rtrim($relative($appConfig['runtime']['pid_path']), '/') . '/)';
    $lines[] = '框架版本  : workerman ' . packageVersion('workerman/workerman')
        . ' / gateway-worker ' . packageVersion('workerman/gateway-worker');
    $lines[] = '依赖版本  : workerman/redis ' . packageVersion('workerman/redis')
        . ' / vlucas/phpdotenv ' . packageVersion('vlucas/phpdotenv');
    $lines[] = str_repeat('-', 70);
    $lines[] = '服务清单  :';
    $lines[] = '  ' . padDisplay('角色', 12) . padDisplay('进程名', 18)
        . padDisplay('监听', 36) . padDisplay('进程数', 8) . '状态';

    $running   = 0;
    $probeable = 0;
    foreach ($services as $roleName => $service) {
        if ($roles && !in_array($roleName, $roles, true)) {
            continue;
        }

        if (empty($service['enable'])) {
            $status = '未启用';
        } elseif (!$withProbe || $service['probe'] === '') {
            $status = '-';
        } else {
            $probeable++;
            if (probePort($service['probe'])) {
                $status = '监听中';
                $running++;
            } else {
                $status = '未监听';
            }
        }

        $lines[] = '  ' . padDisplay($roleName, 12) . padDisplay($service['name'], 18)
            . padDisplay($service['listen'], 36) . padDisplay((string)$service['count'], 8) . $status;
    }

    if ($withProbe) {
        $lines[] = '';
        $lines[] = sprintf(
            '端口探测：%d / %d 个可探测服务处于监听状态（business 无监听端口，不参与判断）',
            $running,
            $probeable
        );
    }

    $lines[] = str_repeat('=', 70);
    $lines[] = '';

    return implode("\n", $lines);
}

/**
 * 角色启用清单（JSON）
 *
 * 输出契约（roles 命令的对外格式，消费方按字段名读取，不解析人读文案）：
 *   {"roles":[{"role":"register","enabled":true,"env":"REGISTER_ENABLE"}, ...]}
 *
 * env 为「关闭该角色的环境变量键名」，业务进程没有独立开关故为空串 ——
 * 该字段仅供消费方在提示语中引用，真值判断一律以 enabled 为准。
 *
 * @param array $gatewayConfig
 * @param array $businessConfig
 * @param array $appConfig
 * @return string 合法 JSON；极端编码失败时退化为空清单而非非法输出
 */
function commandRoles(array $gatewayConfig, array $businessConfig, array $appConfig)
{
    $items = array();
    foreach (\GatewayPush\Common\RoleCatalog::build(
        $gatewayConfig,
        $businessConfig,
        $appConfig,
        DIRECTORY_SEPARATOR === '/'
    ) as $role => $service) {
        $items[] = array(
            'role'    => $role,
            'enabled' => !empty($service['enable']),
            'env'     => (string)$service['env'],
        );
    }

    $json = json_encode(array('roles' => $items), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return is_string($json) ? $json : '{"roles":[]}';
}

/**
 * 读取 Composer 已安装包的真实版本号
 *
 * 不硬编码版本，避免升级依赖后横幅与 composer.lock 漂移；
 * InstalledVersions 不可用（手工裁剪 vendor）或包不存在时降级为 '-'。
 *
 * @param string $package 形如 workerman/workerman
 * @return string
 */
function packageVersion($package)
{
    if (!class_exists('Composer\\InstalledVersions')) {
        return '-';
    }

    try {
        $version = \Composer\InstalledVersions::getPrettyVersion($package);
    } catch (\Throwable $e) {
        return '-';
    }

    return is_string($version) && $version !== '' ? $version : '-';
}

/**
 * 字符串在等宽终端下的显示宽度
 *
 * 中文等全角字符占 2 列，ASCII 占 1 列。不能直接用 str_pad() 补空格 ——
 * 它按字节数计算，含中文的列会整体错位（bin/start.ps1 的 Format-Pad
 * 处理的是同一个问题，两处口径需保持一致）。
 *
 * @param string $text
 * @return int
 */
function displayWidth($text)
{
    $width  = 0;
    $length = strlen($text);

    for ($i = 0; $i < $length;) {
        $byte = ord($text[$i]);
        if ($byte < 0x80) {          // ASCII
            $width += 1;
            $i     += 1;
        } elseif ($byte < 0xE0) {    // 2 字节序列（拉丁扩展等），按窄字符计
            $width += 1;
            $i     += 2;
        } else {                     // 3 / 4 字节序列（CJK 等），按宽字符计
            $width += 2;
            $i     += $byte < 0xF0 ? 3 : 4;
        }
    }

    return $width;
}

/**
 * 按显示宽度右侧补空格
 *
 * @param string $text
 * @param int    $width
 * @return string
 */
function padDisplay($text, $width)
{
    $pad = $width - displayWidth($text);
    return $pad > 0 ? $text . str_repeat(' ', $pad) : $text;
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
 * 提交一条定向推送任务（调试 / 运维用）
 *
 * 与 HTTP 接口一致，只负责把任务写入队列，真实投递由业务进程消费后完成。
 * 因此本命令可在服务未启动时执行，任务会在服务起来后补投。
 *
 * @param array $appConfig
 * @param array $gatewayConfig
 * @param array $businessConfig
 * @param array $argvList 原始参数列表
 * @return int 退出码
 */
function commandPush(array $appConfig, array $gatewayConfig, array $businessConfig, array $argvList)
{
    $targetType  = isset($argvList[2]) ? strtolower(trim((string)$argvList[2])) : '';
    $target      = isset($argvList[3]) ? trim((string)$argvList[3]) : '';
    $payloadRaw  = isset($argvList[4]) ? (string)$argvList[4] : '{}';
    $msgId       = isset($argvList[5]) ? (string)$argvList[5] : '';
    $offlineMode = isset($argvList[6]) ? (string)$argvList[6] : '';

    if ($targetType === '' || $target === '') {
        fwrite(STDERR, "用法：php start.php push <uid|device|client> <target> [payload-json] [msg_id] [offline_mode]\n");
        fwrite(STDERR, "示例：php start.php push uid 1001 '{\"title\":\"hi\"}' msg-1\n");
        return 1;
    }

    $payload = json_decode($payloadRaw, true);
    if (!is_array($payload)) {
        fwrite(STDERR, '[FATAL] payload 不是合法 JSON 对象：' . $payloadRaw . "\n");
        return 1;
    }

    Push::init(
        $appConfig['push'],
        $businessConfig['push_queue'],
        isset($gatewayConfig['udp']['out_queue']) ? $gatewayConfig['udp']['out_queue'] : array()
    );

    $exitCode = 0;
    $worker   = new Worker();
    $worker->count = 1;

    $worker->onWorkerStart = function () use ($appConfig, $businessConfig, $targetType, $target, $payload, $msgId, $offlineMode, &$exitCode) {
        RedisClient::init($appConfig['redis']);

        $queueKey = $businessConfig['push_queue']['key'];

        // 入队是异步操作，需事件循环驱动；超时保护避免网络异常时命令挂死
        Timer::add(5, function () use (&$exitCode) {
            fwrite(STDERR, "[FATAL] 入队操作超时，请检查 Redis 连通性\n");
            $exitCode = 1;
            Worker::stopAll();
        }, array(), false);

        Push::enqueue($targetType, $target, $payload, array(
            'msg_id'       => $msgId,
            'offline_mode' => $offlineMode,
            'source'       => 'cli',
        ), function ($ok) use ($targetType, $target, $msgId, $offlineMode, $queueKey, &$exitCode) {
            if (!$ok) {
                fwrite(STDERR, "[FATAL] 推送任务入队失败\n");
                $exitCode = 1;
                Worker::stopAll();
                return;
            }

            echo "推送任务已入队，等待业务进程消费\n";
            echo "  target_type  : {$targetType}\n";
            echo "  target       : {$target}\n";
            echo '  msg_id       : ' . ($msgId !== '' ? $msgId : '(未指定，不参与幂等去重)') . "\n";
            echo '  offline_mode : ' . ($offlineMode !== '' ? $offlineMode : Push::offlineMode()) . "\n";
            echo "  queue        : {$queueKey}\n";
            Worker::stopAll();
        });
    };

    Worker::runAll();

    return $exitCode;
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
    $text[]  = '  info          打印启动信息：环境 / 框架版本 / 服务清单（含端口探测）';
    $text[]  = '                可选参数为角色列表，例：php start.php info gateway,udp';
    $text[]  = '  roles         输出各角色的启用清单（JSON），供管理脚本判断哪些角色被配置关闭';
    $text[]  = '  env:init      生成 .env 配置（不存在则从模板创建并注入随机密钥）';
    $text[]  = '  token <uid> [device_id] [ttl]   生成调试用 Token';
    $text[]  = '  push <uid|device|client> <target> [payload-json] [msg_id] [offline_mode]';
    $text[]  = '                提交一条定向推送任务（只入队，由业务进程消费后投递）';
    $text[]  = '';
    $text[]  = '角色（--role）：all / register / gateway / udp / business / api / dashboard';
    $text[]  = '  dashboard 为只读监控面板，默认监听 ' . Env::str('DASHBOARD_LISTEN', 'http://127.0.0.1:8291');
    $text[]  = '';
    if ($isLinux) {
        $text[] = 'Linux 单机部署：php start.php start -d';
    } else {
        $text[] = 'Windows 开发环境需按角色分别启动（6 个终端）：';
        $text[] = '  php start.php start --role=register';
        $text[] = '  php start.php start --role=gateway';
        $text[] = '  php start.php start --role=udp';
        $text[] = '  php start.php start --role=business';
        $text[] = '  php start.php start --role=api';
        $text[] = '  php start.php start --role=dashboard   # 可选的监控面板';
    }
    $text[] = '';
    return implode("\n", $text) . "\n";
}
