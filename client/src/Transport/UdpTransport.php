<?php
/**
 * UDP 传输 —— workerman `AsyncUdpConnection('udp://...')` 的封装
 *
 * 与 WsTransport（薄封装）不同，本类承载两条 UDP 特有的可靠性策略，
 * 均来自服务端实测结论（硬约束⑩ / e2e Harness::udpSendUntilAck 同口径）：
 *
 *   1. **延迟首包** —— `AsyncUdpConnection::send()` 返回 true 仅代表数据已交给
 *      内核；建连瞬间（onConnect 同步回调内）发出的首个报文在 Windows 上稳定
 *      静默丢失。因此 connect() 后的一段预热窗口内，所有 send() 先入队，
 *      窗口结束（默认 0.2s）后统一补发。onOpen（握手信号）不受影响：
 *      SessionManager 仍在 onConnect 瞬间发 auth，报文只是被缓冲而非丢失。
 *
 *   2. **应用层重传** —— UDP 网关对每个合法报文立即回传输层 ack
 *      （`{"cmd":"ack",...,"data":[]}`，硬约束⑳「双层回执」的传输层），
 *      因此「收到任何下行」即可视为「此前最近一个未确认报文已送达」。
 *      已发出但未收到任何下行的报文按固定间隔重传，超过上限放弃
 *      （放弃后由 SessionManager 的 pending 超时统一结算，本层通过
 *      onError 告知「重传 N 次未收到回执」）。
 *
 * 连接对象经构造参数注入工厂创建，单测注入假连接脱离 workerman 事件环境；
 * 计时器同样可注入（同 SessionManager 的约定）。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Transport;

use GatewayPush\Client\Error\ClientException;
use GatewayPush\Client\Error\ErrorCode;
use Workerman\Connection\AsyncUdpConnection;
use Workerman\Timer;

/**
 * UDP 传输（AsyncUdpConnection 的封装）
 *
 * 承载两条 UDP 特有策略：延迟首包（避开建连瞬间丢包）与应用层重传
 * （收到任何下行即视为此前最近一条未确认报文已送达）。
 */
final class UdpTransport implements TransportInterface
{
    /** 预热窗口时长（秒）：窗口内 send() 入队，窗口结束统一补发 */
    public const DEFAULT_FIRST_SEND_DELAY = 0.2;

    /** 重传间隔（秒）：与 e2e Harness::udpSendUntilAck 的实测口径一致 */
    public const DEFAULT_RETRANSMIT_INTERVAL = 1.2;

    /** 单报文最大发送次数（含首发）：超过后放弃并触发 onError */
    public const DEFAULT_MAX_ATTEMPTS = 4;

    /**
     * 服务地址（udp://host:port）
     *
     * @var string
     */
    private $url;

    /** @var float 预热窗口（秒） */
    private $firstSendDelay;

    /** @var float 重传间隔（秒） */
    private $retransmitInterval;

    /** @var int 最大发送次数 */
    private $maxAttempts;

    /**
     * 底层连接（默认 AsyncUdpConnection，单测为假连接；关闭后置 null）
     *
     * @var null|object
     */
    private $conn;

    /**
     * 连接工厂 function (): object
     *
     * @var callable
     */
    private $connFactory;

    /** @var bool 连接可用状态 */
    private $connected = false;

    /**
     * 预热窗口标记：true 时 send() 一律入队
     *
     * @var bool
     */
    private $warmup = false;

    /**
     * 预热窗口内缓冲的帧
     *
     * @var array
     */
    private $queue = [];

    /**
     * 在途报文：frame => 已发送次数（收到下行即确认最旧一笔）
     *
     * @var array
     */
    private $inflight = [];

    /**
     * 累计放弃的报文数（重传耗尽）
     *
     * @var int
     */
    private $dropped = 0;

    /** @var null|int 预热定时器 id */
    private $warmupTimerId;

    /** @var null|int 重传定时器 id */
    private $retransmitTimerId;

    /** @var callable 计时器创建 function (float $interval, bool $persistent, callable $fn): int */
    private $timerAdd;

    /** @var callable 计时器删除 function (int $timerId): void */
    private $timerDel;

    /** @var null|callable function (): void */
    private $onOpenCb;

    /** @var null|callable function (string $frame): void */
    private $onMessageCb;

    /** @var null|callable function (): void */
    private $onCloseCb;

    /** @var null|callable function (int $code, string $message): void */
    private $onErrorCb;

    /**
     * @param string        $url         udp://host:port
     * @param array         $options     first_send_delay / retransmit_interval / max_attempts
     * @param null|callable $connFactory 连接工厂（单测注入假连接），缺省创建 AsyncUdpConnection
     * @param null|callable $timerAdd    计时器创建（单测注入假计时器）
     * @param null|callable $timerDel    计时器删除
     *
     * @throws ClientException URL 非法
     */
    public function __construct(
        $url,
        array $options = [],
        ?callable $connFactory = null,
        ?callable $timerAdd = null,
        ?callable $timerDel = null
    ) {
        $url    = (string)$url;
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if ($scheme !== 'udp') {
            throw ClientException::config('udp_url 必须以 udp:// 开头，当前为：' . $url);
        }

        $this->url                = $url;
        $this->firstSendDelay     = isset($options['first_send_delay'])
            ? max(0.0, (float)$options['first_send_delay']) : self::DEFAULT_FIRST_SEND_DELAY;
        $this->retransmitInterval = isset($options['retransmit_interval'])
            ? max(0.05, (float)$options['retransmit_interval']) : self::DEFAULT_RETRANSMIT_INTERVAL;
        $this->maxAttempts        = isset($options['max_attempts'])
            ? max(1, (int)$options['max_attempts']) : self::DEFAULT_MAX_ATTEMPTS;

        // 闭包在方法体内创建即自动绑定 $this，无需另存一份 $self
        $this->connFactory = $connFactory !== null
            ? $connFactory
            : fn () => new AsyncUdpConnection($this->url);

        $this->timerAdd = $timerAdd !== null
            ? $timerAdd
            : fn ($interval, $persistent, $fn) => Timer::add($interval, $fn, [], $persistent);
        $this->timerDel = $timerDel !== null
            ? $timerDel
            : function ($timerId) {
                Timer::del($timerId);
            };
    }

    /**
     * {@inheritDoc}
     */
    public function connect()
    {
        if ($this->conn !== null) {
            return; // 幂等：已在建连/已连接，重复调用无副作用
        }

        $conn = ($this->connFactory)();

        $conn->onConnect = function () {
            $this->connected = true;
            if ($this->onOpenCb !== null) {
                ($this->onOpenCb)();
            }
        };

        $conn->onMessage = function ($con, $raw) {
            $this->handleInbound((string)$raw);
        };

        $conn->onClose = function () {
            // AsyncUdpConnection 关闭后不可复用，丢弃实例等待重连时新建
            $this->connected = false;
            $this->conn      = null;
            $this->stopTimers();
            if ($this->onCloseCb !== null) {
                ($this->onCloseCb)();
            }
        };

        // AsyncUdpConnection 无 onError 回调（UDP 无连接级错误事件），保留接口占位

        $this->conn     = $conn;
        $this->inflight = [];
        $this->queue    = [];
        // 预热标记必须先于 connect() 置位：onConnect 同步回调里 SessionManager
        // 会立即发 auth，该报文须入队等待窗口结束补发（硬约束⑩）
        $this->warmup   = true;
        $this->scheduleWarmup();
        $conn->connect(); // UDP 建连为同步动作，onConnect 在此行内触发
    }

    /**
     * {@inheritDoc}
     *
     * @throws ClientException 连接未建立时抛出
     */
    public function send($frame)
    {
        if ($this->conn === null || !$this->connected) {
            throw ClientException::state('UDP 连接未建立，无法发送报文');
        }

        $frame = (string)$frame;

        if ($this->warmup) {
            $this->queue[] = $frame;

            return;
        }

        $this->doSend($frame);
    }

    /**
     * {@inheritDoc}
     */
    public function close()
    {
        $this->stopTimers();

        if ($this->conn !== null) {
            $this->conn->close(); // onClose 回调里统一置位与清理
        }
    }

    /**
     * {@inheritDoc}
     */
    public function isConnected()
    {
        return $this->connected;
    }

    /**
     * {@inheritDoc}
     */
    public function onOpen(callable $cb)
    {
        $this->onOpenCb = $cb;
    }

    /**
     * {@inheritDoc}
     */
    public function onMessage(callable $cb)
    {
        $this->onMessageCb = $cb;
    }

    /**
     * {@inheritDoc}
     */
    public function onClose(callable $cb)
    {
        $this->onCloseCb = $cb;
    }

    /**
     * {@inheritDoc}
     */
    public function onError(callable $cb)
    {
        $this->onErrorCb = $cb;
    }

    /* ---------------------------------------------------------------------
     | 观测（调试器 status / 单测断言用）
     --------------------------------------------------------------------- */

    /**
     * 在途（已发送未收到任何下行）报文数
     *
     * @return int
     */
    public function inflightCount()
    {
        return count($this->inflight);
    }

    /**
     * 预热窗口内待发送的缓冲帧数
     *
     * @return int
     */
    public function queuedCount()
    {
        return count($this->queue);
    }

    /**
     * 重传耗尽后累计放弃的报文数
     *
     * @return int
     */
    public function droppedCount()
    {
        return $this->dropped;
    }

    /* ---------------------------------------------------------------------
     | 内部实现
     --------------------------------------------------------------------- */

    /**
     * 收到任何下行报文：确认最旧一笔在途报文已送达
     *
     * UDP 网关对每个合法报文按到达顺序立即回执（传输层 ack / 业务回执 / 错误
     * 报文均为下行），同源 socket 的回执顺序与发送顺序一致，因此「收到一笔
     * 下行」即可确认「最旧在途报文已送达」。
     *
     * @param string $raw
     *
     * @return void
     */
    private function handleInbound($raw)
    {
        if (count($this->inflight) > 0) {
            array_shift($this->inflight);
        }
        if (count($this->inflight) === 0) {
            $this->stopRetransmit();
        }

        if ($this->onMessageCb !== null) {
            ($this->onMessageCb)($raw);
        }
    }

    /**
     * 实际发送并登记在途（重传跟踪）
     *
     * @param string $frame
     *
     * @return void
     */
    private function doSend($frame)
    {
        if ($this->conn === null || !$this->connected) {
            return; // 发送窗口期连接被关闭，帧随连接一起作废
        }

        $this->conn->send($frame);
        $this->inflight[$frame] = isset($this->inflight[$frame])
            ? $this->inflight[$frame] + 1 : 1;
        $this->ensureRetransmit();
    }

    /**
     * 预热窗口结束：补发缓冲帧
     *
     * @return void
     */
    private function flushWarmup()
    {
        $this->warmup        = false;
        $this->warmupTimerId = null;

        $queued = $this->queue;
        $this->queue = [];
        foreach ($queued as $frame) {
            $this->doSend($frame);
        }
    }

    /**
     * 启动预热窗口计时器（窗口结束后补发首包）
     *
     * @return void
     */
    private function scheduleWarmup()
    {
        if ($this->warmupTimerId !== null) {
            return;
        }
        $this->warmupTimerId = $this->addTimer($this->firstSendDelay, false, function () {
            $this->flushWarmup();
        });
    }

    /**
     * 确保重传计时器处于运行态（惰性启动：仅在途表非空时注册）
     *
     * @return void
     */
    private function ensureRetransmit()
    {
        if (count($this->inflight) === 0 || $this->retransmitTimerId !== null) {
            return;
        }
        $this->retransmitTimerId = $this->addTimer($this->retransmitInterval, false, function () {
            $this->handleRetransmit();
        });
    }

    /**
     * 重传：重发全部在途报文；耗尽次数的报文放弃并通过 onError 告知
     *
     * @return void
     */
    private function handleRetransmit()
    {
        $this->retransmitTimerId = null;

        if ($this->conn === null || !$this->connected || count($this->inflight) === 0) {
            return;
        }

        foreach (array_keys($this->inflight) as $frame) {
            if ($this->inflight[$frame] >= $this->maxAttempts) {
                unset($this->inflight[$frame]);
                $this->dropped++;
                if ($this->onErrorCb !== null) {
                    ($this->onErrorCb)(ErrorCode::CLIENT_TRANSPORT, sprintf(
                        'UDP 报文重传 %d 次未收到回执，已放弃（%d 字节）',
                        $this->maxAttempts,
                        strlen($frame)
                    ));
                }

                continue;
            }

            $this->inflight[$frame]++;
            $this->conn->send($frame);
        }

        // 不必再判 count($this->inflight)：ensureRetransmit() 首行已自守
        // 「在途为空则直接返回」，此处多一次判断既冗余、静态分析下又恒真
        $this->ensureRetransmit();
    }

    /**
     * 停止重传计时器
     *
     * @return void
     */
    private function stopRetransmit()
    {
        if ($this->retransmitTimerId !== null) {
            $this->delTimer($this->retransmitTimerId);
            $this->retransmitTimerId = null;
        }
    }

    /**
     * 停止本类注册的全部计时器（预热窗口 + 重传）
     *
     * @return void
     */
    private function stopTimers()
    {
        $this->stopRetransmit();
        if ($this->warmupTimerId !== null) {
            $this->delTimer($this->warmupTimerId);
            $this->warmupTimerId = null;
        }
    }

    /**
     * 注册计时器（经注入的 add 函数，单测可替换为假实现）
     *
     * @param float    $interval
     * @param bool     $persistent
     * @param callable $fn
     *
     * @return int 计时器 ID
     */
    private function addTimer($interval, $persistent, $fn)
    {
        return (int)($this->timerAdd)((float)$interval, $persistent, $fn);
    }

    /**
     * 删除计时器（ID 为 0 / null 时静默跳过）
     *
     * @param null|int $timerId
     *
     * @return void
     */
    private function delTimer($timerId)
    {
        if ($timerId) {
            ($this->timerDel)((int)$timerId);
        }
    }
}
