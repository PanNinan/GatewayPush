<?php
/**
 * CLI 调试器引擎 —— `client/bin/gwclient.php` 的能力实现
 *
 * 两种模式（设计稿 §12 裁定）：
 *   1. 一次性命令：gwclient.php <command> [args] [options] —— 建连 → 鉴权 → 执行 → 退出
 *   2. REPL 交互：gwclient.php shell —— 提示符反映连接态、推送不打断输入行、断线自动重连
 *   另提供 `listen` 挂机模式：只订阅并打印下行推送
 *
 * 事件驱动：与 SDK 同构，须运行在 Worker::runAll() 之后；本类负责装配与调度，
 * 不重复实现协议/传输细节（全部复用 Protocol / Transport / Session / Service 层）。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Cli;

use GatewayPush\Client\Error\ClientException;
use GatewayPush\Client\Event\PushReceiver;
use GatewayPush\Client\Service\AdminApi;
use GatewayPush\Client\Service\EchoApi;
use GatewayPush\Client\Service\NotifyApi;
use GatewayPush\Client\Service\ReportApi;
use GatewayPush\Client\Service\SessionApi;
use GatewayPush\Client\Service\SubscribeApi;
use GatewayPush\Client\Session\SessionManager;
use GatewayPush\Client\Transport\TransportInterface;
use GatewayPush\Client\Transport\UdpTransport;
use GatewayPush\Client\Transport\WsTransport;
use Workerman\Timer;
use Workerman\Worker;

class Debugger
{
    const VERSION = '1.0.0';

    /** 一次性命令白名单 */
    const COMMANDS = array(
        'help', 'state', 'ping', 'echo', 'session', 'report',
        'subscribe', 'unsubscribe', 'topics', 'notify',
        'push', 'stats', 'health', 'shell', 'listen',
    );

    /** @var array 运行配置 */
    private $config;

    /** @var SessionManager|null */
    private $session;

    /** @var PushReceiver|null */
    private $receiver;

    /** @var array<string,object> 已装配的业务 API */
    private $apis = [];

    /** @var bool REPL 模式（输出需保护输入行） */
    private $repl = false;

    /** @var resource|null */
    private $stdin;

    /**
     * @param array $config uid / device_id / secret / proto / ws_url / udp_url / api_url /
     *                      api_secret / timeout / heartbeat
     */
    public function __construct(array $config = array())
    {
        $this->config = array_merge(array(
            'uid'        => '',
            'device_id'  => '',              // 缺省按 uid 派生（保证同 uid 复用同一设备身份）
            'secret'     => '',
            'proto'      => 'ws',            // ws | udp
            'ws_url'     => 'ws://127.0.0.1:8282',
            'udp_url'    => 'udp://127.0.0.1:8283',
            'api_url'    => 'http://127.0.0.1:8290',
            'api_secret' => '',
            'timeout'    => 5.0,
            'heartbeat'  => 0,
        ), $config);
    }

    /**
     * 入口：解析参数并拉起事件循环
     *
     * @param array $args 不含程序名的 argv
     * @return int 退出码（事件循环内 exit，实际不返回）
     * @throws ClientException 配置非法
     */
    public function run(array $args)
    {
        $parsed  = CommandParser::parse($args);
        $command = (string)$parsed['command'];

        $this->applyOptions($parsed['options']);

        if ($command === '' || $command === 'help' || CommandParser::flag($parsed['options'], 'help')) {
            $this->printHelp();
            return 0;
        }

        if (!in_array($command, self::COMMANDS, true)) {
            $this->line('未知命令：' . $command . '（help 查看可用命令）');
            return 2;
        }

        if ($this->config['uid'] === '') {
            throw ClientException::config('缺少 uid：请使用 --uid=xxx 指定');
        }

        if ((string)$this->config['device_id'] === '') {
            // 设备身份按 uid 派生：避免随机 device 触发「首个绑定者胜出」拦截（见设计稿 §11-4）
            $this->config['device_id'] = 'gwcli-' . substr(md5((string)$this->config['uid']), 0, 8);
        }

        // workerman 默认把框架日志落在「入口脚本所在目录」（$argv[0] 同级），会在
        // client/bin/ 里凭空多出一个 workerman.log。显式收敛到仓库 runtime/logs，
        // 与服务端 start.php 同一处，运行时产物不散落在源码树里。
        $logDir = dirname(__DIR__, 3) . '/runtime/logs';
        if (!is_dir($logDir) && !@mkdir($logDir, 0755, true) && !is_dir($logDir)) {
            fwrite(STDERR, "[WARN] 日志目录创建失败：{$logDir}\n");
        }
        Worker::$logFile = $logDir . '/gwclient.log';

        $worker              = new Worker();
        $worker->onWorkerStart = function () use ($parsed) {
            $this->boot($parsed);
        };

        Worker::runAll();

        return 0;
    }

    /* ---------------------------------------------------------------------
     | 装配
     --------------------------------------------------------------------- */

    /**
     * 选项覆盖运行配置
     *
     * @param array $options
     * @return void
     */
    private function applyOptions(array $options)
    {
        $map = array(
            'uid'        => 'uid',
            'device'     => 'device_id',
            'device-id'  => 'device_id',
            'secret'     => 'secret',
            'proto'      => 'proto',
            'ws'         => 'ws_url',
            'udp'        => 'udp_url',
            'api'        => 'api_url',
            'api-secret' => 'api_secret',
            'hb'         => 'heartbeat',
        );

        foreach ($map as $opt => $key) {
            $value = CommandParser::str($options, $opt, '');
            if ($value !== '') {
                $this->config[$key] = $value;
            }
        }

        $timeout = CommandParser::float($options, 'timeout', 0.0);
        if ($timeout > 0) {
            $this->config['timeout'] = $timeout;
        }

        $hb = CommandParser::float($options, 'hb', -1.0);
        if ($hb >= 0) {
            $this->config['heartbeat'] = $hb;
        }
    }

    /**
     * 事件循环内的启动分发
     *
     * @param array $parsed
     * @return void
     */
    private function boot(array $parsed)
    {
        $command = (string)$parsed['command'];

        // HTTP 管理端命令不需要长连接会话
        if (in_array($command, array('push', 'stats', 'health'), true)) {
            $this->runHttp($command, $parsed);
            return;
        }

        $this->bootSession();

        if ($command === 'shell') {
            $this->startRepl();
            $this->session()->connect();
            return;
        }

        if ($command === 'listen') {
            $this->startListen($parsed);
            return;
        }

        $this->session()->connect();
        $this->awaitReady(function () use ($command, $parsed) {
            if ($command === 'state') {
                $this->printState();
                exit(0);
            }
            $this->execute($command, $parsed, true);
        });
    }

    /**
     * 装配会话与业务 API
     *
     * @return void
     * @throws ClientException 传输层配置非法
     */
    private function bootSession()
    {
        $transport = $this->createTransport();

        $this->session = new SessionManager(array(
            'uid'            => (string)$this->config['uid'],
            'device_id'      => (string)$this->config['device_id'],
            'secret'         => (string)$this->config['secret'],
            'heartbeat'      => (float)$this->config['heartbeat'],
            'timeout'        => (float)$this->config['timeout'],
            'reconnect'      => true,
            'reconnect_base' => 1.0,
            'reconnect_max'  => 8.0,
            'auto_auth'      => true,
        ), $transport);

        $this->session->onStateChange(function ($new, $old) {
            $this->line(sprintf('[state] %s -> %s', $old, $new));
        });

        $this->session->onError(function ($e) {
            $this->line('[error] ' . $e->getMessage());
        });

        $this->receiver = new PushReceiver($this->session);
        $this->receiver->onPush(function (array $payload, array $meta) {
            $this->line(sprintf(
                '[push] msg_id=%s offline=%d payload=%s',
                $meta['msg_id'],
                $meta['offline'],
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ));
        });

        $this->apis = array(
            'echo'     => new EchoApi($this->session),
            'session'  => new SessionApi($this->session),
            'report'   => new ReportApi($this->session),
            'sub'      => new SubscribeApi($this->session),
            'notify'   => new NotifyApi($this->session),
        );
    }

    /**
     * 按 proto 创建传输层
     *
     * @return TransportInterface
     * @throws ClientException 协议非法
     */
    private function createTransport()
    {
        if ((string)$this->config['proto'] === 'udp') {
            return new UdpTransport((string)$this->config['udp_url']);
        }
        return new WsTransport((string)$this->config['ws_url']);
    }

    /**
     * 取会话（未装配时抛错，便于静态分析与排障）
     *
     * @return SessionManager
     * @throws ClientException 未装配
     */
    private function session()
    {
        if ($this->session === null) {
            throw ClientException::state('会话尚未装配');
        }
        return $this->session;
    }

    /* ---------------------------------------------------------------------
     | 一次性命令
     --------------------------------------------------------------------- */

    /**
     * 等待会话就绪后执行
     *
     * @param callable $cb
     * @return void
     */
    private function awaitReady(callable $cb)
    {
        $waited  = 0.0;
        $timerId = null;
        $timerId = Timer::add(0.1, function () use (&$timerId, &$waited, $cb) {
            $waited += 0.1;
            $state = $this->session()->state();

            if ($state === SessionManager::STATE_READY) {
                Timer::del((int)$timerId);
                $cb();
                return;
            }

            if ($waited > 15.0) {
                Timer::del((int)$timerId);
                $this->line('[fail] 等待就绪超时（当前状态 ' . $state . '）');
                exit(3);
            }
        });
    }

    /**
     * 执行业务命令
     *
     * @param string $command
     * @param array  $parsed
     * @param bool   $exitAfter 完成后是否退出进程
     * @return void
     */
    private function execute($command, array $parsed, $exitAfter)
    {
        $args = $parsed['args'];
        $opts = $parsed['options'];

        switch ($command) {
            case 'ping':
                $this->session()->ping(function ($ok) use ($exitAfter) {
                    $this->line(sprintf(
                        '[%s] ping -> pong（rtt %.1fms）',
                        $ok ? 'ok' : 'fail',
                        $this->session()->lastRtt() * 1000
                    ));
                    $this->settle($ok, $exitAfter);
                });
                return;

            case 'echo':
                $params = $this->jsonArg($args, 0, array());
                $this->api('echo')->send(is_array($params) ? $params : array($params), function ($ok, $data, $error) use ($exitAfter) {
                    $this->result('echo', $ok, $data, $error, $exitAfter);
                });
                return;

            case 'session':
                $this->api('session')->get(function ($ok, $data, $error) use ($exitAfter) {
                    $this->result('session', $ok, $data, $error, $exitAfter);
                });
                return;

            case 'report':
                $topic = isset($args[0]) ? (string)$args[0] : '';
                $count = isset($args[1]) ? (int)$args[1] : 1;
                $value = $this->jsonArg($args, 2, null);
                $this->api('report')->report($topic, $count, $value, function ($ok, $data, $error) use ($exitAfter) {
                    $this->result('report', $ok, $data, $error, $exitAfter);
                });
                return;

            case 'subscribe':
                $this->chainTopics('subscribe', $args, 0, $exitAfter);
                return;

            case 'unsubscribe':
                $this->chainTopics('unsubscribe', $args, 0, $exitAfter);
                return;

            case 'topics':
                $this->api('sub')->topics(function ($ok, $data, $error) use ($exitAfter) {
                    $this->result('topics', $ok, $data, $error, $exitAfter);
                });
                return;

            case 'notify':
                $value = $this->jsonArg($args, 0, null);
                $msgId = CommandParser::str($opts, 'msg-id', '');
                $mode  = CommandParser::str($opts, 'offline-mode', '');
                $this->api('notify')->notify($value, $msgId, $mode, function ($ok, $data, $error) use ($exitAfter) {
                    // 受理回执先到、push 后到：一次性模式留 2s 观察窗口再退出
                    $this->result('notify', $ok, $data, $error, false);
                    if (!$exitAfter) {
                        return;
                    }
                    Timer::add(2.0, function () use ($ok) {
                        exit($ok ? 0 : 1);
                    }, [], false);
                });
                return;

            default:
                $this->line('命令需在运行前确定：' . $command);
                $this->settle(false, $exitAfter);
        }
    }

    /**
     * 主题族命令串行执行（订阅多个主题时逐个下发，避免乱序）
     *
     * @param string $action subscribe|unsubscribe
     * @param array  $args
     * @param int    $index
     * @param bool   $exitAfter
     * @return void
     */
    private function chainTopics($action, array $args, $index, $exitAfter)
    {
        if (!isset($args[$index])) {
            $this->settle(true, $exitAfter);
            return;
        }

        $topic = (string)$args[$index];
        $api   = $this->api('sub');
        $next  = function ($ok) use ($action, $args, $index, $exitAfter) {
            if (!$ok) {
                $this->settle(false, $exitAfter);
                return;
            }
            $this->chainTopics($action, $args, $index + 1, $exitAfter);
        };

        if ($action === 'subscribe') {
            $api->subscribe($topic, function ($ok, $data, $error) use ($topic, $next) {
                $this->result('subscribe ' . $topic, $ok, $data, $error, false);
                $next($ok);
            });
            return;
        }

        $api->unsubscribe($topic, function ($ok, $data, $error) use ($topic, $next) {
            $this->result('unsubscribe ' . $topic, $ok, $data, $error, false);
            $next($ok);
        });
    }

    /**
     * HTTP 管理端命令（/push /stats /health）
     *
     * @param string $command
     * @param array  $parsed
     * @return void
     */
    private function runHttp($command, array $parsed)
    {
        $secret = CommandParser::str($parsed['options'], 'api-secret', (string)$this->config['api_secret']);
        if ($secret === '') {
            $secret = (string)$this->config['secret'];
        }
        // --bad-sign：故意用错密钥，用于验证 401 分支
        if (CommandParser::flag($parsed['options'], 'bad-sign')) {
            $secret = 'bad-secret-for-negative-test';
        }

        $api = new AdminApi((string)$this->config['api_url'], $secret, (float)$this->config['timeout']);

        if ($command === 'health') {
            $api->health(function ($ok, $data, $error) {
                $this->result('health', $ok, $data, $error, true);
            });
            return;
        }

        if ($command === 'stats') {
            $api->stats(function ($ok, $data, $error) {
                $this->result('stats', $ok, $data, $error, true);
            });
            return;
        }

        $targetType = CommandParser::str($parsed['options'], 'to-type', 'uid');
        $target     = CommandParser::str($parsed['options'], 'to', (string)$this->config['uid']);
        $payload    = $this->jsonArg($parsed['args'], 0, array());
        $pushOpts   = [];
        $msgId      = CommandParser::str($parsed['options'], 'msg-id', '');
        if ($msgId !== '') {
            $pushOpts['msg_id'] = $msgId;
        }
        $mode = CommandParser::str($parsed['options'], 'offline-mode', '');
        if ($mode !== '') {
            $pushOpts['offline_mode'] = $mode;
        }

        $api->push($targetType, $target, is_array($payload) ? $payload : array($payload), $pushOpts, function ($ok, $data, $error) {
            $this->result('push', $ok, $data, $error, true);
        });
    }

    /* ---------------------------------------------------------------------
     | REPL / listen
     --------------------------------------------------------------------- */

    /**
     * 启动交互模式
     *
     * 输入读取：优先非阻塞 select 轮询（Linux/macOS 与管道均可用）；
     * Windows 原生控制台不支持对 stdin 做 select，此时降级为阻塞 fgets ——
     * 表现为「推送在下一次回车时渲染」，功能不受影响（见 client/README.md）。
     *
     * @return void
     */
    private function startRepl()
    {
        $this->repl = true;
        $stdin      = fopen('php://stdin', 'r');
        if ($stdin === false) {
            $this->line('[fail] 无法打开 stdin');
            exit(1);
        }
        $this->stdin = $stdin;
        @stream_set_blocking($stdin, false);

        $this->line('gwclient REPL v' . self::VERSION . ' —— help 查看命令，quit 退出');

        Timer::add(0.05, function () {
            $this->pollStdin();
        });
    }

    /**
     * 启动挂机监听模式
     *
     * @param array $parsed
     * @return void
     */
    private function startListen(array $parsed)
    {
        $topics = $parsed['args'];
        $this->session()->connect();

        $this->awaitReady(function () use ($topics) {
            $this->line('[listen] 已就绪，等待推送' . ($topics ? '（订阅：' . implode(',', $topics) . '）' : ''));
            if (!$topics) {
                return;
            }
            $this->chainTopics('subscribe', $topics, 0, false);
        });
    }

    /**
     * 轮询标准输入
     *
     * @return void
     */
    private function pollStdin()
    {
        $stdin = $this->stdin;
        if (!is_resource($stdin)) {
            return;
        }
        if (feof($stdin)) {
            $this->line('[repl] 输入结束，退出');
            exit(0);
        }

        $line = $this->readLine($stdin);
        if ($line === null) {
            return;
        }

        $line = trim($line);
        if ($line === '') {
            $this->prompt();
            return;
        }

        $this->handleLine($line);
    }

    /**
     * 非阻塞读取一行
     *
     * @param resource $stdin
     * @return string|null null = 暂无输入
     */
    private function readLine($stdin)
    {
        $read   = array($stdin);
        $write  = [];
        $except = [];
        $ready  = @stream_select($read, $write, $except, 0);

        if ($ready === false) {
            // 降级：控制台不可 select，直接阻塞读
            $line = fgets($stdin);
            return $line === false ? null : $line;
        }
        if ($ready < 1) {
            return null;
        }
        $line = fgets($stdin);
        return $line === false ? null : $line;
    }

    /**
     * 处理一行 REPL 输入
     *
     * @param string $line
     * @return void
     */
    private function handleLine($line)
    {
        if ($line === 'quit' || $line === 'exit') {
            $this->line('[repl] 退出');
            exit(0);
        }

        if ($line === 'help') {
            $this->printReplHelp();
            $this->prompt();
            return;
        }

        $tokens = explode(' ', $line);
        $parsed = CommandParser::parse($tokens);
        $name   = (string)$parsed['command'];

        if (!in_array($name, self::COMMANDS, true)) {
            $this->line('未知命令：' . $name);
            $this->prompt();
            return;
        }

        if (in_array($name, array('shell', 'listen'), true)) {
            $this->line('[warn] shell / listen 仅在启动时可用');
            $this->prompt();
            return;
        }

        if (in_array($name, array('push', 'stats', 'health'), true)) {
            $this->runHttp($name, $parsed);
            return;
        }

        if ($parsed['command'] === 'state') {
            $this->printState();
            $this->prompt();
            return;
        }

        if (!$this->session()->isReady()) {
            $this->line('[warn] 未就绪（state=' . $this->session()->state() . '），命令未发送');
            $this->prompt();
            return;
        }

        $this->execute((string)$parsed['command'], $parsed, false);
    }

    /* ---------------------------------------------------------------------
     | 输出
     --------------------------------------------------------------------- */

    /**
     * 输出一行（REPL 下先清行再输出，避免打断输入）
     *
     * @param string $text
     * @return void
     */
    private function line($text)
    {
        if ($this->repl) {
            echo "\r" . str_repeat(' ', 100) . "\r" . $text . PHP_EOL;
            $this->prompt();
            return;
        }
        echo $text . PHP_EOL;
    }

    /**
     * 打印提示符（仅 REPL）
     *
     * @return void
     */
    private function prompt()
    {
        if (!$this->repl) {
            return;
        }
        $state = $this->session === null ? 'init' : $this->session->state();
        echo '(' . $state . ')> ';
    }

    /**
     * 打印会话与 SDK 内部状态
     *
     * @return void
     */
    private function printState()
    {
        $session = $this->session();
        $this->line('state=' . $session->state()
            . ' pending=' . $session->pendingCount()
            . ' rtt=' . sprintf('%.1f', $session->lastRtt() * 1000) . 'ms'
            . ' acked=' . ($this->receiver !== null ? $this->receiver->ackedCount() : 0));
        $this->line('stats=' . json_encode($session->stats(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 统一结果呈现
     *
     * @param string     $label
     * @param bool       $ok
     * @param array|null $data
     * @param array|null $error
     * @param bool       $exitAfter
     * @return void
     */
    private function result($label, $ok, $data, $error, $exitAfter)
    {
        if ($ok) {
            $this->line('[ok] ' . $label . ' -> ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $code   = is_array($error) && isset($error['code']) ? $error['code'] : '-';
            $msg    = is_array($error) && isset($error['msg']) ? $error['msg'] : '-';
            $status = is_array($error) && isset($error['status']) ? ' status=' . $error['status'] : '';
            $this->line('[fail] ' . $label . $status . ' code=' . $code . ' msg=' . $msg);
        }
        $this->settle($ok, $exitAfter);
    }

    /**
     * 结算：按需退出
     *
     * @param bool $ok
     * @param bool $exitAfter
     * @return void
     */
    private function settle($ok, $exitAfter)
    {
        if (!$exitAfter) {
            return; // REPL 下每条输出已自带提示符，避免重复
        }
        exit($ok ? 0 : 1);
    }

    /* ---------------------------------------------------------------------
     | 辅助
     --------------------------------------------------------------------- */

    /**
     * 取业务 API 实例
     *
     * @param string $key
     * @return object
     * @throws ClientException 未装配
     */
    private function api($key)
    {
        if (!isset($this->apis[$key])) {
            throw ClientException::state('API 未装配：' . $key);
        }
        return $this->apis[$key];
    }

    /**
     * 解析 JSON 位置参数
     *
     * @param array  $args
     * @param int    $index
     * @param mixed  $default
     * @return mixed
     */
    private function jsonArg(array $args, $index, $default = null)
    {
        if (!isset($args[$index]) || $args[$index] === '') {
            return $default;
        }
        $raw = (string)$args[$index];
        if ($raw[0] !== '{' && $raw[0] !== '[' && $raw[0] !== '"') {
            return $raw; // 非 JSON 原样作为标量
        }
        $decoded = json_decode($raw, true);
        return $decoded === null ? $default : $decoded;
    }

    /**
     * 打印完整帮助
     *
     * @return void
     */
    private function printHelp()
    {
        echo <<<TXT
gwclient —— GatewayPush 客户端调试器 v{$this->version()}

用法：
  php client/bin/gwclient.php <command> [args] [options]
  php client/bin/gwclient.php shell                 交互模式（提示符反映连接态）
  php client/bin/gwclient.php listen [topic...]     挂机接收推送

命令：
  ping                       心跳往返
  echo <json>                echo 回显（params='*' 原样透传）
  session                    会话摘要
  report <topic> [n] [json]  数据上报
  subscribe <topic...>       订阅主题
  unsubscribe <topic...>     取消订阅
  topics                     已订阅主题
  notify <json> [--msg-id=] [--offline-mode=]  触发自身推送
  push <json> [--to-type=uid] [--to=] [--msg-id=] [--offline-mode=] [--bad-sign]
  stats / health             HTTP 管理端
  state                      本地会话状态
  shell / listen / help

常用选项：
  --uid=xxx --device=xxx --secret=xxx --proto=ws|udp
  --ws=ws://host:port --udp=udp://host:port --api=http://host:port
  --timeout=5 --hb=0（主动心跳秒数，0=关闭）

TXT;
    }

    /**
     * REPL 内简版帮助
     *
     * @return void
     */
    private function printReplHelp()
    {
        $this->line('可用：ping / echo <json> / session / report <topic> [n] [json] / '
            . 'subscribe <t...> / unsubscribe <t...> / topics / notify <json> / '
            . 'push <json> / stats / health / state / quit');
    }

    /**
     * 版本号（供帮助文本插值）
     *
     * @return string
     */
    private function version()
    {
        return self::VERSION;
    }
}
