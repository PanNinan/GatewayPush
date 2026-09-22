<?php
/**
 * 会话管理器 —— 客户端侧会话层核心
 *
 * 职责（设计稿 §5.3）：
 *   - 鉴权状态机：disconnected → connecting → connected → authenticating → ready
 *     （+ reconnecting：断线且启用重连时的过渡态）
 *   - seq 生成：单调递增字符串（服务端原样回传，按它关联 pending）
 *   - pending 请求表：seq → PendingRequest（结算回调 + 超时定时器）
 *   - 心跳：主动 ping（可配间隔，0 = 关闭）+ **应答服务端反向心跳**
 *     （网关下发 {"cmd":"ping","ts":0}，25s/次、漏 2 次断开 —— 必须回 pong）
 *   - 重连：指数退避（base * 2^attempt，封顶 max）；重连成功后经 auto_auth 自动重鉴权
 *   - 下行分发：按 cmd 路由 —— pong/ack/error 结算 pending，push 转交 onPush，
 *     ping 应答 pong，其余忽略
 *
 * 事件驱动模型：与 SDK 整体一致，须运行在 workerman 环境（Worker::runAll 之后）
 * 或任何持续驱动事件循环的进程里；单测通过注入假计时器 + 假传输层脱离该依赖。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Session;

use GatewayPush\Business\Message;
use GatewayPush\Client\Error\ClientException;
use GatewayPush\Client\Error\ErrorCode;
use GatewayPush\Client\Protocol\Codec;
use GatewayPush\Client\Protocol\Signer;
use GatewayPush\Client\Protocol\TokenIssuer;
use GatewayPush\Client\Transport\TransportInterface;
use GatewayPush\Client\Transport\WsTransport;
use Workerman\Timer;

/**
 * 会话管理器（客户端侧会话层核心）
 *
 * 鉴权状态机、seq 生成、pending 请求表、心跳与重连、下行分发；
 * 须运行在持续驱动事件循环的环境，单测经注入假计时器脱离该依赖。
 */
class SessionManager
{
    const STATE_DISCONNECTED   = 'disconnected';
    const STATE_CONNECTING     = 'connecting';
    const STATE_CONNECTED      = 'connected';
    const STATE_AUTHENTICATING = 'authenticating';
    const STATE_READY          = 'ready';
    const STATE_RECONNECTING   = 'reconnecting';

    /**
     * 配置（已合并默认值）
     *
     * @var array
     */
    private $config;

    /**
     * @var TransportInterface
     */
    private $transport;

    /**
     * @var TokenIssuer
     */
    private $issuer;

    /**
     * 当前状态（STATE_* 常量）
     *
     * @var string
     */
    private $state = self::STATE_DISCONNECTED;

    /**
     * seq 计数器
     *
     * @var int
     */
    private $seqCounter = 0;

    /**
     * pending 请求表：seq => PendingRequest
     *
     * @var array
     */
    private $pending = [];

    /**
     * 最近一次成功结算的往返耗时（秒）
     *
     * @var float
     */
    private $lastRtt = 0.0;

    /**
     * 当前会话使用的 Token（auth 时签发；attach_token 开启时随包携带）
     *
     * @var string
     */
    private $token = '';

    /**
     * 连续重连次数（鉴权成功后清零）
     *
     * @var int
     */
    private $reconnectAttempts = 0;

    /**
     * 用户主动关闭标记（阻止 onClose 触发重连）
     *
     * @var bool
     */
    private $closing = false;

    /**
     * 心跳定时器 id
     *
     * @var int|null
     */
    private $heartbeatTimerId;

    /**
     * 重连定时器 id
     *
     * @var int|null
     */
    private $reconnectTimerId;

    /** @var callable|null function (array $packet): void */
    private $onPushCb;

    /** @var callable|null function (ClientException $e): void */
    private $onErrorCb;

    /** @var callable|null function (string $new, string $old): void */
    private $onStateChangeCb;

    /**
     * 计时器创建 function (float $interval, bool $persistent, callable $fn): int
     *
     * @var callable
     */
    private $timerAdd;

    /**
     * 计时器删除 function (int $timerId): void
     *
     * @var callable
     */
    private $timerDel;

    /**
     * @param array                  $config    见 self::defaultConfig()
     * @param TransportInterface|null $transport 缺省按 ws_url 构造 WsTransport
     * @param TokenIssuer|null       $issuer    缺省按 secret/token_ttl 构造
     * @param callable|null          $timerAdd  计时器创建（单测注入假计时器）
     * @param callable|null          $timerDel  计时器删除
     * @throws ClientException 配置非法
     */
    public function __construct(
        array $config,
        ?TransportInterface $transport = null,
        ?TokenIssuer $issuer = null,
        ?callable $timerAdd = null,
        ?callable $timerDel = null
    ) {
        $this->config = array_merge(self::defaultConfig(), $config);

        foreach (array('uid', 'device_id', 'secret') as $key) {
            if ((string)$this->config[$key] === '') {
                throw ClientException::config('客户端配置缺少 ' . $key);
            }
        }
        if ((float)$this->config['timeout'] <= 0) {
            throw ClientException::config('timeout 必须大于 0');
        }
        if ((float)$this->config['heartbeat'] < 0) {
            throw ClientException::config('heartbeat 不能为负数');
        }

        if ($issuer === null) {
            $issuer = new TokenIssuer(
                (string)$this->config['secret'],
                (int)$this->config['token_ttl']
            );
        }
        $this->issuer = $issuer;

        if ($transport === null) {
            if ((string)$this->config['ws_url'] === '') {
                throw ClientException::config('客户端配置缺少 ws_url');
            }
            $transport = new WsTransport($this->config['ws_url']);
        }
        $this->transport = $transport;
        $this->wireTransport();

        $this->timerAdd = $timerAdd !== null
            ? $timerAdd
            : function ($interval, $persistent, $fn) {
                return Timer::add($interval, $fn, [], $persistent);
            };
        $this->timerDel = $timerDel !== null
            ? $timerDel
            : function ($timerId) {
                Timer::del($timerId);
            };
    }

    /**
     * 配置默认值
     *
     * @return array
     */
    public static function defaultConfig()
    {
        return array(
            'ws_url'         => '',       // ws://host:port
            'uid'            => '',
            'device_id'      => '',
            'secret'         => '',       // 与服务端 app.auth.secret 一致
            'token_ttl'      => 0,        // <=0 取 TokenIssuer::DEFAULT_TTL
            'heartbeat'      => 20,       // 主动 ping 间隔（秒），0 = 关闭
            'timeout'        => 5.0,      // 单次请求超时（秒）
            'reconnect'      => true,     // 断线自动重连（重连成功后自动重鉴权）
            'reconnect_base' => 1.0,      // 退避基数（秒）
            'reconnect_max'  => 15.0,     // 退避封顶（秒）
            'auto_auth'      => true,     // 握手完成后立即自动发 auth（15s 窗口内抢先）
            'attach_token'   => false,    // 业务报文随包携带 Token（UDP 通道必需，见 sendPacket）
        );
    }

    /* ---------------------------------------------------------------------
     | 上行 API
     --------------------------------------------------------------------- */

    /**
     * 建立连接
     *
     * @return void
     * @throws ClientException 状态非法（已连接/正在建连时重复调用）
     */
    public function connect()
    {
        if (!in_array($this->state, array(self::STATE_DISCONNECTED, self::STATE_RECONNECTING), true)) {
            throw ClientException::state('当前状态为 ' . $this->state . '，不能重复建连');
        }

        $this->closing = false;
        $this->setState(self::STATE_CONNECTING);
        $this->transport->connect();
    }

    /**
     * 发送鉴权（auto_auth=false 时由调用方在建连后手动触发）
     *
     * @param callable|null $cb function (bool $ok, array $packet): void
     *                          成功时 $packet['data'] = {uid, device_id, protocol, reconnected}
     * @return void
     * @throws ClientException 状态非法 / Token 签发失败
     */
    public function auth($cb = null)
    {
        if ($this->state !== self::STATE_CONNECTED) {
            throw ClientException::state('当前状态为 ' . $this->state . '，须先 connect 且握手完成');
        }

        $token = $this->issuer->issue(array(
            'uid'       => $this->config['uid'],
            'device_id' => $this->config['device_id'],
        ));
        $this->token = $token; // attach_token 开启时供后续业务报文携带

        $packet = Message::packet(Message::CMD_AUTH, array('client' => 'gateway-push-client'), array(
            'seq'       => $this->newSeq(),
            'uid'       => $this->config['uid'],
            'device_id' => $this->config['device_id'],
            'token'     => $token,
        ));

        $this->registerPending($packet['seq'], 'auth', (float)$this->config['timeout'], $cb);
        $this->setState(self::STATE_AUTHENTICATING);
        $this->sendPacket($packet);
    }

    /**
     * 发送心跳（测量 RTT 与存活探测）
     *
     * @param callable|null $cb function (bool $ok, array $packet): void
     * @return void
     * @throws ClientException 未就绪
     */
    public function ping($cb = null)
    {
        $this->assertReady();

        $packet = Message::packet(Message::CMD_PING, [], array('seq' => $this->newSeq()));
        $this->registerPending($packet['seq'], 'ping', (float)$this->config['timeout'], $cb);
        $this->sendPacket($packet);
    }

    /**
     * 发送业务动作请求（P2 的 Service API 将封装在本方法之上）
     *
     * @param string        $action  动作名（须在服务端 config/actions.php 登记）
     * @param array         $params  动作参数
     * @param callable|null $cb      function (bool $ok, array $packet): void（完整回执报文，data 载荷在 $packet['data']）
     * @param float|null    $timeout 覆盖全局 timeout
     * @return string 本请求 seq
     * @throws ClientException 未就绪
     */
    public function request($action, array $params = [], $cb = null, $timeout = null)
    {
        $this->assertReady();

        $packet = Message::packet(Message::CMD_DATA, array(
            'action' => (string)$action,
            'params' => $params,
        ), array(
            'seq'       => $this->newSeq(),
            'uid'       => $this->config['uid'],
            'device_id' => $this->config['device_id'],
        ));

        $what    = 'data.' . (string)$action;
        $timeout = $timeout !== null ? (float)$timeout : (float)$this->config['timeout'];
        $this->registerPending($packet['seq'], $what, $timeout, $cb);
        $this->sendPacket($packet);

        return $packet['seq'];
    }

    /**
     * 对下行推送回执（「至少一次」语义的 ack；服务端据此统计投递质量）
     *
     * PushReceiver 收到 push 后自动调用；业务代码一般不需要手动调用。
     *
     * @param string $msgId 推送报文的 msg_id（即服务端 seq）
     * @param array  $data  附加数据
     * @return bool 是否已发送（未就绪时静默跳过）
     */
    public function sendAck($msgId, array $data = array())
    {
        $msgId = (string)$msgId;
        if ($msgId === '' || $this->state !== self::STATE_READY) {
            return false;
        }

        $packet = Message::packet(Message::CMD_ACK, array_merge(array(
            'msg_id' => $msgId,
        ), $data), array(
            'seq' => $msgId,
        ));
        $this->sendPacket($packet);

        return true;
    }

    /**
     * 主动关闭（不发起重连，取消挂起的重连定时器）
     *
     * @return void
     */
    public function close()
    {
        if ($this->state === self::STATE_DISCONNECTED) {
            return;
        }

        $this->closing = true;
        $this->clearHeartbeat();

        if ($this->reconnectTimerId !== null) {
            $this->delTimer($this->reconnectTimerId);
            $this->reconnectTimerId = null;
        }

        if ($this->state === self::STATE_RECONNECTING) {
            // 重连等待期没有活动连接（onClose 不会再触发），直接落状态
            $this->setState(self::STATE_DISCONNECTED);
            return;
        }

        $this->transport->close(); // onClose 回调统一结算 pending 并落状态
    }

    /* ---------------------------------------------------------------------
     | 观测与回调注册
     --------------------------------------------------------------------- */

    /**
     * 当前状态
     *
     * @return string
     */
    public function state()
    {
        return $this->state;
    }

    /**
     * 是否已就绪（可发业务请求）
     *
     * @return bool
     */
    public function isReady()
    {
        return $this->state === self::STATE_READY;
    }

    /**
     * 最近一次成功结算的往返耗时（秒）
     *
     * @return float
     */
    public function lastRtt()
    {
        return $this->lastRtt;
    }

    /**
     * pending 请求数量
     *
     * @return int
     */
    public function pendingCount()
    {
        return count($this->pending);
    }

    /**
     * 运行时快照（调试器 status 命令用）
     *
     * @return array
     */
    public function stats()
    {
        return array(
            'state'              => $this->state,
            'uid'                => $this->config['uid'],
            'device_id'          => $this->config['device_id'],
            'pending'            => count($this->pending),
            'last_rtt'           => round($this->lastRtt, 4),
            'reconnect_attempts' => $this->reconnectAttempts,
        );
    }

    /**
     * 注册推送回调（P2 将由 PushReceiver 承接，当前直接暴露原始报文）
     *
     * @param callable $cb function (array $packet): void
     * @return void
     */
    public function onPush($cb)
    {
        $this->onPushCb = $cb;
    }

    /**
     * 注册错误回调（服务端 error 报文 / 本地超时 / 传输错误）
     *
     * @param callable $cb function (ClientException $e): void
     * @return void
     */
    public function onError($cb)
    {
        $this->onErrorCb = $cb;
    }

    /**
     * 注册状态变更回调
     *
     * @param callable $cb function (string $newState, string $oldState): void
     * @return void
     */
    public function onStateChange($cb)
    {
        $this->onStateChangeCb = $cb;
    }

    /* ---------------------------------------------------------------------
     | 传输层回调（构造时接线）
     --------------------------------------------------------------------- */

    private function wireTransport()
    {
        $this->transport->onOpen(function () {
            $this->handleOpen();
        });
        $this->transport->onMessage(function ($frame) {
            $this->handleFrame($frame);
        });
        $this->transport->onClose(function () {
            $this->handleTransportClose();
        });
        $this->transport->onError(function ($code, $msg) {
            $this->fireError(ClientException::transport('传输错误 ' . $code . '：' . $msg));
            // 底层错误通常伴随 onClose，重连在那里统一处理
        });
    }

    /**
     * 握手完成 —— 15s 鉴权窗口从此刻起算，auto_auth 时立即抢发 auth
     *
     * @return void
     */
    private function handleOpen()
    {
        $this->reconnectTimerId = null;
        $this->setState(self::STATE_CONNECTED);

        if ($this->config['auto_auth']) {
            $this->auth(); // 15s 鉴权窗口内抢发
        }
    }

    /**
     * 下行分发：按 cmd 路由
     *
     * @param string $frame
     * @return void
     */
    private function handleFrame($frame)
    {
        $packet = Codec::decode($frame, $error);
        if ($packet === null) {
            $this->fireError(new ClientException(
                ErrorCode::BAD_PACKET,
                '收到非法报文：' . $error,
                array('raw' => (string)$frame)
            ));
            return;
        }

        switch ($packet['cmd']) {
            case Message::CMD_PING:
                // 服务端反向心跳必须应答：网关 25s/次、漏 2 次判定死亡并断开
                $this->sendPacket(Message::packet(Message::CMD_PONG, [], array(
                    'seq' => $packet['seq'],
                )));
                return;

            case Message::CMD_PONG:
            case Message::CMD_ACK:
                // UDP 双层回执（硬约束⑳）：网关收包即回传输层 ack（data 为空且无
                // action）。业务请求（data.*）的结算必须等业务层回执，传输层 ack
                // 在此跳过；auth/ping 无业务层回执，以传输层 ack 结算。
                $ackSeq = (string)(isset($packet['seq']) ? $packet['seq'] : '');
                if ($packet['cmd'] === Message::CMD_ACK
                    && Codec::isTransportAck($packet)
                    && isset($this->pending[$ackSeq])
                    && str_starts_with($this->pending[$ackSeq]->what, 'data.')
                ) {
                    return;
                }
                $this->settle($packet, true);
                return;

            case Message::CMD_ERROR:
                $this->settle($packet, false);
                $this->fireError(ClientException::fromPacket($packet));
                return;

            case Message::CMD_PUSH:
                if ($this->onPushCb !== null) {
                    ($this->onPushCb)($packet);
                }
                return;

            default:
                return; // 服务端路由表保证不下发未知指令，防御性忽略
        }
    }

    /**
     * 连接断开 —— 结算 pending，按配置重连或落回 disconnected
     *
     * @return void
     */
    private function handleTransportClose()
    {
        $this->clearHeartbeat();
        $this->failAllPending('连接已断开，请求未结算');

        if ($this->closing) {
            $this->reconnectAttempts = 0;
            $this->setState(self::STATE_DISCONNECTED);
            return;
        }

        if (!$this->config['reconnect']) {
            $this->setState(self::STATE_DISCONNECTED);
            $this->fireError(ClientException::transport('连接已断开（自动重连未启用）'));
            return;
        }

        $this->reconnectAttempts++;
        $base = (float)$this->config['reconnect_base'];
        $max  = (float)$this->config['reconnect_max'];
        $delay = min($base * pow(2, $this->reconnectAttempts - 1), $max);

        $this->setState(self::STATE_RECONNECTING);
        $this->reconnectTimerId = $this->addTimer($delay, false, function () {
            $this->connect();
        });
    }

    /* ---------------------------------------------------------------------
     | pending 请求表
     --------------------------------------------------------------------- */

    /**
     * 登记一笔 pending 请求（含超时定时器）
     *
     * @param string        $seq
     * @param string        $what
     * @param float         $timeout
     * @param callable|null $cb
     * @return void
     */
    private function registerPending($seq, $what, $timeout, $cb)
    {
        $req          = new PendingRequest();
        $req->seq     = $seq;
        $req->what    = $what;
        $req->sentAt  = microtime(true);
        $req->timeout = $timeout;
        $req->onReply = $cb;
        $req->timerId = $this->addTimer($timeout, false, function () use ($seq) {
            if (!isset($this->pending[$seq])) {
                return;
            }
            $timedOut = $this->pending[$seq];
            unset($this->pending[$seq]);

            $ex = ClientException::timeout($timedOut->what, $seq, $timedOut->timeout);
            if ($timedOut->onReply !== null) {
                ($timedOut->onReply)(false, array());
            }
            $this->fireError($ex);
        });

        $this->pending[$seq] = $req;
    }

    /**
     * 按报文 seq 结算 pending
     *
     * @param array $packet 服务端回执报文
     * @param bool  $ok
     * @return void
     */
    private function settle(array $packet, $ok)
    {
        $seq = (string)$packet['seq'];
        if ($seq === '' || !isset($this->pending[$seq])) {
            return; // 无主回执（如推送回执 ack），忽略
        }

        $req = $this->pending[$seq];
        unset($this->pending[$seq]);
        $this->delTimer($req->timerId);

        if ($ok) {
            $this->lastRtt = microtime(true) - $req->sentAt;
            if ($req->what === 'auth') {
                // 鉴权成功 → 会话就绪（重连计数清零、开启心跳）
                $this->setState(self::STATE_READY);
            }
        }
        if ($req->onReply !== null) {
            ($req->onReply)($ok, $packet);
        }
    }

    /**
     * 全部 pending 以传输失败结算（断线时）
     *
     * @param string $reason
     * @return void
     */
    private function failAllPending($reason)
    {
        foreach ($this->pending as $req) {
            $this->delTimer($req->timerId);
            if ($req->onReply !== null) {
                ($req->onReply)(false, array('reason' => $reason));
            }
        }
        $this->pending = [];
    }

    /* ---------------------------------------------------------------------
     | 内部辅助
     --------------------------------------------------------------------- */

    /**
     * 生成下一个请求序号（十进制字符串，单调递增）
     *
     * @return string
     */
    private function newSeq()
    {
        return (string)(++$this->seqCounter);
    }

    /**
     * 断言会话已就绪（业务请求的前置条件）
     *
     * @return void
     * @throws ClientException 尚未完成鉴权时抛出
     */
    private function assertReady()
    {
        if ($this->state !== self::STATE_READY) {
            throw ClientException::state('当前状态为 ' . $this->state . '，须先完成鉴权');
        }
    }

    /**
     * 发送上行报文（统一补齐 Token 与签名）
     *
     * @param array $packet 八字段报文；缺失的 token / sign 在此补齐
     * @return void
     */
    private function sendPacket(array $packet)
    {
        // UDP 通道（硬约束⑲）：服务端身份只取自 Token 载荷，报文不带 Token 会被
        // 静默拒绝。attach_token 开启时所有上行报文统一携带（Token 参与签名，
        // 必须在计算 sign 之前附加）。WS 不校验 Token，携带无副作用。
        if (!empty($this->config['attach_token']) && $this->token !== '' && !isset($packet['token'])) {
            $packet['token'] = $this->token;
        }

        $secret = (string)$this->config['secret'];
        if ($secret !== '' && !isset($packet['sign'])) {
            // WS 不校验签名（无副作用）；UDP 通道（P3）必需。统一在发送口计算，
            // 保证所有上行报文的签名口径一致，调用方无需关心。
            $packet['sign'] = Signer::sign($packet, $secret);
        }

        $this->transport->send(Codec::encode($packet));
    }

    /**
     * 切换会话状态（附带心跳的启停与状态变更回调）
     *
     * @param string $new 目标状态
     * @return void
     */
    private function setState($new)
    {
        $old         = $this->state;
        $this->state = $new;

        if ($new === self::STATE_READY) {
            $this->reconnectAttempts = 0;
            $this->scheduleHeartbeat();
        } else {
            $this->clearHeartbeat();
        }

        if ($old !== $new && $this->onStateChangeCb !== null) {
            ($this->onStateChangeCb)($new, $old);
        }
    }

    /**
     * 就绪后开启主动心跳（间隔可配，0 = 关闭）
     *
     * @return void
     */
    private function scheduleHeartbeat()
    {
        if ($this->heartbeatTimerId !== null) {
            return;
        }
        $interval = (float)$this->config['heartbeat'];
        if ($interval <= 0) {
            return;
        }

        $this->heartbeatTimerId = $this->addTimer($interval, true, function () {
            if ($this->state === self::STATE_READY) {
                $this->ping();
            }
        });
    }

    /**
     * 停止主动心跳
     *
     * @return void
     */
    private function clearHeartbeat()
    {
        if ($this->heartbeatTimerId !== null) {
            $this->delTimer($this->heartbeatTimerId);
            $this->heartbeatTimerId = null;
        }
    }

    /**
     * 注册计时器（经注入的 add 函数，单测可替换为假实现）
     *
     * @param float    $interval
     * @param bool     $persistent
     * @param callable $fn
     * @return int 计时器 ID
     */
    private function addTimer($interval, $persistent, $fn)
    {
        return (int)($this->timerAdd)((float)$interval, $persistent, $fn);
    }

    /**
     * 删除计时器（ID 为 0 / null 时静默跳过）
     *
     * @param int|null $timerId
     * @return void
     */
    private function delTimer($timerId)
    {
        if ($timerId) {
            ($this->timerDel)((int)$timerId);
        }
    }

    /**
     * 向上抛出错误（转发给 onError 回调）
     *
     * @param ClientException $e
     * @return void
     */
    private function fireError(ClientException $e)
    {
        if ($this->onErrorCb !== null) {
            ($this->onErrorCb)($e);
        }
    }
}
