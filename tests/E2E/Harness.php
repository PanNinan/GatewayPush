<?php
/**
 * 端到端自检公共设施
 *
 * ---------------------------------------------------------------------
 * 为什么拆出这一层
 * ---------------------------------------------------------------------
 * 拆分前全部用例堆在单个 1576 行脚本里，每新增一个用例都要在巨型文件中
 * 定位并复用散落的局部变量（uid / token / msg_id / topic / $state / $finish），
 * 回归成本随用例数量线性恶化。
 *
 * 本类把「与用例逻辑无关的公共设施」收敛为唯一入口：
 *   1. 环境装配 —— 配置加载、地址推导、Auth / Push 初始化、每用例身份与随机标识
 *   2. 用例状态 —— $state 的读写与最终汇总判定（finish）
 *   3. 报文工具 —— buildPacket / encode
 *   4. UDP 与 HTTP 客户端工具 —— udpSendUntilAck / httpRequest
 *
 * 用例实现见同目录 Case*.php，每个类提供 register(Harness $h) 静态方法。
 *
 * ---------------------------------------------------------------------
 * 行为契约（重构硬约束）
 * ---------------------------------------------------------------------
 * 本文件只做「搬移」，不改语义：输出文本、用例顺序、判定条件、退出码
 * 均与原单文件脚本逐字一致。用例内部逻辑除「共享变量改由 $h 提供」外不做任何调整。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Tests\E2E;

use GatewayPush\Business\Auth;
use GatewayPush\Business\Message;
use GatewayPush\Business\Push;
use GatewayPush\Common\RedisClient;

final class Harness
{
    /**
     * 用例标签，键顺序即结果输出顺序
     */
    public const LABELS = [
        'A' => 'WebSocket 鉴权链路（auth -> ack -> ping -> pong）',
        'B' => 'WebSocket 越权拦截（未鉴权业务指令 -> 4003）',
        'C' => 'UDP 正常链路（合法签名 -> ack）',
        'D' => 'UDP 签名拦截（篡改签名 -> 4001）',
        'E' => '定向推送在线投递（uid 目标 -> push 报文）',
        'F' => '离线缓存与重连补投（离线入队 -> 上线补投）',
        'G' => '推送幂等去重（同 msg_id 重复提交 -> 仅一次）',
        'H' => 'HTTP 接口（健康探测 / 验签通过 / 验签拒绝）',
        'I' => 'UDP 定向推送（业务进程 -> 出站队列 -> 网关 sendto）',
        'J' => '指令路由表（data.action 分发 / 4006 / 4007）',
        'K' => 'UDP 离线补投（会话重建 -> 出站队列 -> offline=1）',
        'L' => '报文级限流（超量连发 -> 部分放行 / 部分 4008）',
        'M' => '业务动作契约（参数白名单 / 4006 未知动作 / 4007 参数错误）',
        'N' => 'UDP 通道业务动作（echo 回执 / report 按声明静默）',
        'O' => '订阅与广播闭环（subscribe -> enqueueTopic -> push）',
        'P' => 'HTTP 动作调用（POST /action -> BusinessWorker -> 回执）',
    ];

    /**
     * 参与超时保护的用例（H 为事件循环前同步执行，不纳入）
     */
    public const ASYNC_CASES = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'I', 'J', 'K', 'L', 'M', 'N', 'O'];

    /**
     * app.php 配置（Redis 等），供 RedisClient::init 使用
     *
     * @var array
     */
    public $appConfig = [];

    /**
     * WS 网关地址（面向客户端，已替换回环地址）
     *
     * @var string
     */
    public $wsAddress = '';

    /**
     * UDP 网关地址（面向客户端，已替换回环地址）
     *
     * @var string
     */
    public $udpAddress = '';

    /**
     * HTTP 接口地址（面向客户端，已替换回环地址）
     *
     * @var string
     */
    public $apiAddress = '';

    /**
     * 报文签名密钥
     *
     * @var string
     */
    public $secret = '';

    /**
     * 整体超时（秒）
     *
     * @var int
     */
    public $timeout = 20;

    /**
     * 基准 uid / device_id（CLI 传入）
     *
     * @var string
     */
    public $uid = '';
    public $deviceId = '';

    /**
     * 用例状态：'pending' 表示未完成，其余为布尔结果
     *
     * @var array
     */
    public $state = [];

    /**
     * 各用例上下文：uid / device_id / token / topic / msg_id / seq
     *
     * @var array
     */
    public $ctx = [];

    /**
     * 构造（请使用 boot()）
     *
     * @param array  $appConfig
     * @param string $secret
     * @param string $uid
     * @param string $deviceId
     * @param int    $timeout
     * @param string $wsAddress
     * @param string $udpAddress
     * @param string $apiAddress
     */
    public function __construct(
        array $appConfig,
        $secret,
        $uid,
        $deviceId,
        $timeout,
        $wsAddress,
        $udpAddress,
        $apiAddress
    ) {
        $this->appConfig  = $appConfig;
        $this->secret     = (string)$secret;
        $this->uid        = (string)$uid;
        $this->deviceId   = (string)$deviceId;
        $this->timeout    = (int)$timeout;
        $this->wsAddress  = (string)$wsAddress;
        $this->udpAddress = (string)$udpAddress;
        $this->apiAddress = (string)$apiAddress;

        foreach (array_keys(self::LABELS) as $case) {
            $this->state[$case]        = 'pending';
            $this->state[$case . '_msg'] = '';
        }

        $this->buildContexts();
    }

    /**
     * 入口装配：解析 CLI 参数、加载配置、初始化组件并构造实例
     *
     * 用法：php tests/e2e_check.php <uid> [device_id] [timeout]
     *
     * @param array $argv
     *
     * @return self
     */
    public static function boot(array $argv)
    {
        $uid      = isset($argv[1]) ? (string)$argv[1] : 'e2e-uid-1001';
        $deviceId = isset($argv[2]) ? (string)$argv[2] : 'e2e-device-A';
        $timeout  = isset($argv[3]) ? (int)$argv[3] : 20;

        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);

        $appConfig      = require $base . '/config/app.php';
        $gatewayConfig  = require $base . '/config/gateway.php';
        $businessConfig = require $base . '/config/business.php';

        $wsAddress  = str_replace('websocket://0.0.0.0', 'ws://127.0.0.1', $gatewayConfig['websocket']['listen']);
        // 客户端侧不能连接 0.0.0.0（仅服务端可绑定的通配地址），需替换为回环地址
        $udpAddress = str_replace('udp://0.0.0.0', 'udp://127.0.0.1', $gatewayConfig['udp']['listen']);
        $apiAddress = str_replace('0.0.0.0', '127.0.0.1', $appConfig['api']['listen']);

        Auth::init($appConfig['auth']);
        Push::init($appConfig['push'], $businessConfig['push_queue'], $gatewayConfig['udp']['out_queue']);

        return new self(
            $appConfig,
            (string)$appConfig['auth']['secret'],
            $uid,
            $deviceId,
            $timeout,
            $wsAddress,
            $udpAddress,
            $apiAddress
        );
    }

    /**
     * 打印运行头部（与拆分前输出逐字一致）
     *
     * @return void
     */
    public function printHeader()
    {
        echo "端到端链路自检（WebSocket + UDP + 定向推送 + HTTP 接口）\n";
        echo str_repeat('=', 70) . "\n";
        echo "WS  网关 : {$this->wsAddress}\n";
        echo "UDP 网关 : {$this->udpAddress}\n";
        echo "HTTP接口 : {$this->apiAddress}\n";
        echo "uid      : {$this->uid}\n";
        echo "device_id: {$this->deviceId}\n";
        echo str_repeat('-', 70) . "\n";
    }

    /**
     * 取用例上下文
     *
     * @param string $case
     *
     * @return array
     */
    public function ctx($case)
    {
        return $this->ctx[$case] ?? [];
    }

    /* ---------------------------------------------------------------------
     | 用例状态
     --------------------------------------------------------------------- */

    /**
     * 标记用例通过
     *
     * @param string $case
     *
     * @return void
     */
    public function pass($case)
    {
        $this->state[$case] = true;
    }

    /**
     * 标记用例失败
     *
     * @param string $case
     * @param string $msg
     *
     * @return void
     */
    public function fail($case, $msg)
    {
        $this->state[$case]     = false;
        $this->state[$case . '_msg'] = (string)$msg;
    }

    /**
     * 全部用例完成后汇总判定（若有未完成用例则直接返回，等待后续回调再次触发）
     *
     * @return void
     */
    public function finish()
    {
        $pass = true;
        foreach ($this->state as $key => $value) {
            if (substr($key, -4) === '_msg') {
                continue;
            }
            if ($value === 'pending') {
                return;   // 仍有未完成用例
            }
            if ($value === false) {
                $pass = false;
            }
        }

        echo "\n" . str_repeat('=', 70) . "\n";
        foreach (self::LABELS as $key => $label) {
            $ok      = $this->state[$key] === true;
            $msg     = $this->state[$key . '_msg'] !== '' ? '  原因：' . $this->state[$key . '_msg'] : '';
            $skipped = ($this->state[$key] === 'pending' && $key === 'H');
            echo sprintf(
                "[%s] %s%s\n",
                $ok ? 'PASS' : ($skipped ? 'SKIP' : 'FAIL'),
                $label,
                $msg
            );
        }
        echo str_repeat('=', 70) . "\n";
        echo $pass ? "端到端自检结论：全部通过\n" : "端到端自检结论：存在失败项\n";

        exit($pass ? 0 : 1);
    }

    /**
     * 超时保护
     *
     * @return void
     */
    public function registerTimeoutGuard()
    {
        $timeout = $this->timeout;
        \Workerman\Timer::add($timeout, function () use ($timeout) {
            echo "\n[超时] 用例未在 {$timeout} 秒内全部完成。当前状态：\n";
            foreach (self::ASYNC_CASES as $key) {
                echo "  {$key}: " . ($this->state[$key] === 'pending' ? '未完成' : var_export($this->state[$key], true)) . "\n";
            }

            exit(1);
        }, [], false);
    }

    /* ---------------------------------------------------------------------
     | 报文工具
     --------------------------------------------------------------------- */

    /**
     * 构造带签名的标准报文
     *
     * @param string $cmd
     * @param string $seq
     * @param array  $extra
     *
     * @return array
     */
    public function buildPacket($cmd, $seq, array $extra)
    {
        $packet = [
            'cmd'       => $cmd,
            'seq'       => $seq,
            'ts'        => time(),
            'uid'       => '',
            'device_id' => '',
            'token'     => '',
            'data'      => [],
        ];
        foreach ($extra as $key => $value) {
            $packet[$key] = $value;
        }
        $packet['sign'] = Message::sign($packet, $this->secret);

        return $packet;
    }

    /**
     * 编码为可发送的报文串
     *
     * @param array $packet
     *
     * @return string
     */
    public function encode(array $packet)
    {
        return Message::encode($packet);
    }

    /**
     * 发送 UDP 报文并在未收到回执时重传
     *
     * AsyncUdpConnection::send() 返回 true 仅表示数据已交给内核，不代表服务端已收到；
     * 首个报文尤其可能因 socket 尚未就绪而静默丢失（此时 send 仍返回 true）。
     * 因此按「应用层确认」语义处理：延迟首包 + 未收到回执则重传，收到回执立即停止。
     *
     * @param \Workerman\Connection\AsyncUdpConnection $con
     * @param string                                   $payload    已编码的报文
     * @param string                                   $case       用例标识（收到回执后由其 onMessage 置为非 pending）
     * @param int                                      $maxAttempt 最大发送次数
     * @param float                                    $interval   重传间隔（秒）
     *
     * @return void
     */
    public function udpSendUntilAck($con, $payload, $case, $maxAttempt = 4, $interval = 1.2)
    {
        $attempt = function ($n) use ($con, $payload, $case, $maxAttempt, $interval, &$attempt) {
            if ($this->state[$case] !== 'pending' || $n > $maxAttempt) {
                return;
            }

            $sent = $con->send($payload);

            echo sprintf(
                "[%s] -> 报文 %d 字节，第 %d/%d 次发送（send 返回 %s）\n",
                $case,
                strlen($payload),
                $n,
                $maxAttempt,
                var_export($sent, true)
            );

            \Workerman\Timer::add($interval, function () use ($n, &$attempt) {
                $attempt($n + 1);
            }, [], false);
        };

        // 延迟首包，规避 socket 就绪竞态
        \Workerman\Timer::add(0.2, function () use (&$attempt) {
            $attempt(1);
        }, [], false);
    }

    /* ---------------------------------------------------------------------
     | HTTP 工具
     --------------------------------------------------------------------- */

    /**
     * 同步 HTTP 请求（用于接口用例）
     *
     * 在事件循环启动前执行，同步阻塞无副作用；启动后请勿调用。
     *
     * @param string $method
     * @param string $url
     * @param array  $headers
     * @param string $body
     * @param int    $timeout 连接与读取超时（秒）。默认 3s 覆盖普通接口；
     *                        POST /action 为同步等待语义，需按等待窗放宽
     *                        （API_ACTION_WAIT_MS + 动作超时余量）。
     *
     * @return array ['ok' => bool, 'status' => int, 'body' => string, 'json' => array|null, 'error' => string]
     */
    public static function httpRequest($method, $url, array $headers = [], $body = '', $timeout = 3)
    {
        $parts = parse_url($url);
        $host  = $parts['host'] ?? '127.0.0.1';
        $port  = isset($parts['port']) ? (int)$parts['port'] : 80;
        $path  = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }

        $errno  = 0;
        $errstr = '';
        $fp     = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, (float)$timeout);
        if (!$fp) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'json' => null, 'error' => $errstr];
        }

        $req = "{$method} {$path} HTTP/1.1\r\n";
        $req .= "Host: {$host}:{$port}\r\n";
        $req .= "Connection: close\r\n";
        foreach ($headers as $key => $value) {
            $req .= "{$key}: {$value}\r\n";
        }
        if ($body !== '') {
            $req .= 'Content-Length: ' . strlen($body) . "\r\n";
        }
        $req .= "\r\n" . $body;

        fwrite($fp, $req);

        // 服务端默认 keep-alive，不能依赖读到 EOF，需按 Content-Length 精确读取
        $head = '';
        stream_set_timeout($fp, (int)$timeout);
        while (($line = fgets($fp, 4096)) !== false) {
            $head .= $line;
            if (str_contains($head, "\r\n\r\n")) {
                break;
            }
        }
        if ($head === '') {
            fclose($fp);

            return ['ok' => false, 'status' => 0, 'body' => '', 'json' => null, 'error' => '未收到响应头'];
        }

        $length = 0;
        if (preg_match('/Content-Length:\s*(\d+)/i', $head, $m) === 1) {
            $length = (int)$m[1];
        }

        $raw    = '';
        $remain = $length;
        while ($remain > 0) {
            $chunk = fread($fp, $remain);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raw    .= $chunk;
            $remain -= strlen($chunk);
        }
        fclose($fp);

        $status = 0;
        if (preg_match('#^HTTP/\d\.\d\s+(\d+)#', $head, $m) === 1) {
            $status = (int)$m[1];
        }

        return [
            'ok'     => true,
            'status' => $status,
            'body'   => $raw,
            'json'   => json_decode($raw, true),
            'error'  => '',
        ];
    }

    /**
     * 取 Redis 客户端（供用例内回查落库结果）
     *
     * @return \Workerman\Redis\Client
     */
    public static function redis()
    {
        return RedisClient::connection();
    }

    /**
     * 拼装 Redis 键（统一前缀）
     *
     * @param string $suffix
     *
     * @return string
     */
    public static function redisKey($suffix)
    {
        return RedisClient::key($suffix);
    }

    /* ---------------------------------------------------------------------
     | 用例上下文
     --------------------------------------------------------------------- */

    /**
     * 构造各用例的独立身份与随机标识
     *
     * 各推送 / 动作用例使用独立 uid、device 与主题，避免互相干扰。
     *
     * @return void
     */
    protected function buildContexts()
    {
        $uid      = $this->uid;
        $deviceId = $this->deviceId;

        // A / C / D 共用基准身份
        $baseToken = Auth::issue(['uid' => $uid, 'device_id' => $deviceId]);
        $this->ctx['A'] = ['uid' => $uid, 'device_id' => $deviceId, 'token' => $baseToken];
        $this->ctx['C'] = ['uid' => $uid, 'device_id' => $deviceId, 'token' => $baseToken];
        $this->ctx['D'] = ['uid' => $uid, 'device_id' => $deviceId];

        // B 的越权探测使用固定字面量，无独立身份
        $this->ctx['B'] = [];

        // H 使用基准 uid 的 -H 后缀，身份在用例内即时签发
        $this->ctx['H'] = ['uid' => $uid . '-H'];

        $suffixes = ['E', 'F', 'G', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P'];
        foreach ($suffixes as $case) {
            $cu = $uid . '-' . $case;
            $cd = $deviceId . '-' . $case;
            $this->ctx[$case] = [
                'uid'       => $cu,
                'device_id' => $cd,
                'token'     => Auth::issue(['uid' => $cu, 'device_id' => $cd]),
            ];
        }

        // 上报 / 订阅用例使用独立主题，避免跨轮次互相污染
        $this->ctx['M']['topic'] = 'e2e_m_' . bin2hex(random_bytes(3));
        $this->ctx['N']['topic'] = 'e2e_n_' . bin2hex(random_bytes(3));
        $this->ctx['O']['topic'] = 'e2e_o_' . bin2hex(random_bytes(3));
        $this->ctx['P']['topic'] = 'e2e_p_' . bin2hex(random_bytes(3));

        // 用例 N 的三条报文序号
        $this->ctx['N']['seq1']       = 'n-echo-1';
        $this->ctx['N']['seq2']       = 'n-echo-2';
        $this->ctx['N']['report_seq'] = 'n-report-1';

        $this->ctx['E']['msg_id'] = 'e2e-push-' . bin2hex(random_bytes(4));
        $this->ctx['F']['msg_id'] = 'e2e-off-' . bin2hex(random_bytes(4));
        $this->ctx['G']['msg_id'] = 'e2e-idem-' . bin2hex(random_bytes(4));
        $this->ctx['I']['msg_id'] = 'e2e-udp-' . bin2hex(random_bytes(4));
        $this->ctx['K']['msg_id'] = 'e2e-udpoff-' . bin2hex(random_bytes(4));
    }
}
