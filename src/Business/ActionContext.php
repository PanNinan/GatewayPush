<?php
/**
 * 业务动作执行上下文
 *
 * 由 ActionRunner 构造并注入处理器，承载三件事：
 *   1. 身份与来源：clientId / uid / deviceId / protocol / channel
 *   2. 已校验参数：params() 返回经 ParamValidator 归一化后的参数，
 *      处理器无需再做类型判断与默认值填充
 *   3. 统一回执：reply() / replyError()，通道差异（WS 直发 / UDP 出站队列 /
 *      HTTP 结果回程键）由构造时注入的 sender 抹平，处理器无感知
 *
 * ---------------------------------------------------------------------
 * 回执抑制语义（重要）
 * ---------------------------------------------------------------------
 * 当声明为 reply = none 时，reply() / replyError() 不会真正下发，
 * 但仍会把上下文标记为「已回执」，使 ActionRunner 的超时保护不再触发。
 *
 * 这样处理器可以始终按「处理完就回执」的写法实现，是否真正下发完全由
 * config/actions.php 的声明决定 —— 同一个处理器在 WS 上可回执、在 UDP 上
 * 静默，不需要写任何 if 分支。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Business;

/**
 * 业务动作执行上下文
 *
 * 承载身份与来源、已归一化参数、统一回执三类信息；通道差异由注入的 sender 抹平。
 */
class ActionContext
{
    // ---------------------- 通道 ----------------------
    public const CHANNEL_WS   = 'ws';
    public const CHANNEL_UDP  = 'udp';

    /**
     * HTTP 通道：由 Api 进程经动作队列投递而来，clientId 形如 http:{request_id}。
     *
     * 与前两者的本质差异：调用方是**同步等待的外部系统**，因此回执不是
     * 「往某个连接方向下发」，而是写回 action:result:{request_id} 供 Api 取回
     * （见 ActionReply）。
     */
    public const CHANNEL_HTTP = 'http';

    // ---------------------- 回执方式 ----------------------
    public const REPLY_SYNC = 'sync';   // 处理结果下发客户端
    public const REPLY_NONE = 'none';   // 静默处理，不下发任何报文

    /**
     * 动作名
     *
     * @var string
     */
    protected string $action;

    /**
     * 原始报文
     *
     * @var array<string, mixed>
     */
    protected array $packet;

    /**
     * 已校验并归一化的业务参数
     *
     * @var array<string, mixed>
     */
    protected array $params;

    /**
     * 连接标识
     *
     * @var string
     */
    protected string $clientId;

    /**
     * 用户标识（未鉴权为空串）
     *
     * @var string
     */
    protected string $uid;

    /**
     * 设备标识
     *
     * @var string
     */
    protected string $deviceId;

    /**
     * 协议类型（ws / udp / http）
     *
     * @var string
     */
    protected string $protocol;

    /**
     * 来源通道（ws / udp / http）
     *
     * @var string
     */
    protected string $channel;

    /**
     * 回执方式
     *
     * @var string
     */
    protected string $replyMode;

    /**
     * 报文下发器，签名 function (array $packet): void
     *
     * @var callable
     */
    protected mixed $sender;

    /**
     * 是否已回执（含被抑制的回执）
     *
     * @var bool
     */
    protected bool $replied = false;

    /**
     * 首次回执后的钩子，供 ActionRunner 注销超时定时器
     *
     * @var null|callable
     */
    protected mixed $replyHook = null;

    /**
     * 动作私有配置（来自 config/actions.php 的 options 段）
     *
     * @var array<string, mixed>
     */
    protected array $options = [];

    /**
     * @param string               $action    动作名
     * @param array<string, mixed> $packet    原始报文
     * @param array<string, mixed> $params    已校验参数
     * @param array<string, mixed> $identity  ['client_id','uid','device_id','protocol']
     * @param string               $channel   ws | udp | http
     * @param string               $replyMode sync | none
     * @param callable             $sender    function (array $packet): void
     * @param array<string, mixed> $options   动作私有配置
     */
    public function __construct(string $action, array $packet, array $params, array $identity, string $channel, string $replyMode, callable $sender, array $options = [])
    {
        $this->options = $options;
        $this->action    = $action;
        $this->packet    = $packet;
        $this->params    = $params;
        $this->clientId  = isset($identity['client_id']) ? (string)$identity['client_id'] : '';
        $this->uid       = isset($identity['uid']) ? (string)$identity['uid'] : '';
        $this->deviceId  = isset($identity['device_id']) ? (string)$identity['device_id'] : '';
        $this->protocol  = isset($identity['protocol']) ? (string)$identity['protocol'] : '';
        $this->channel   = $channel;
        $this->replyMode = $replyMode === self::REPLY_NONE ? self::REPLY_NONE : self::REPLY_SYNC;
        $this->sender    = $sender;
    }

    /* ---------------------------------------------------------------------
     | 身份与来源
     --------------------------------------------------------------------- */

    /**
     * @return string
     */
    public function action(): string
    {
        return $this->action;
    }

    /**
     * @return string
     */
    public function clientId(): string
    {
        return $this->clientId;
    }

    /**
     * @return string
     */
    public function uid(): string
    {
        return $this->uid;
    }

    /**
     * @return string
     */
    public function deviceId(): string
    {
        return $this->deviceId;
    }

    /**
     * @return string
     */
    public function protocol(): string
    {
        return $this->protocol;
    }

    /**
     * @return string
     */
    public function channel(): string
    {
        return $this->channel;
    }

    /**
     * 报文序号（回执原样带回，客户端据此关联请求）
     *
     * @return string
     */
    public function seq(): string
    {
        return isset($this->packet['seq']) ? (string)$this->packet['seq'] : '';
    }

    /**
     * 原始报文
     *
     * @return array<string, mixed>
     */
    public function packet(): array
    {
        return $this->packet;
    }

    /* ---------------------------------------------------------------------
     | 参数
     --------------------------------------------------------------------- */

    /**
     * 全部已校验参数
     *
     * @return array<string, mixed>
     */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * 取单个已校验参数
     *
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    public function param(string $key, $default = null)
    {
        return array_key_exists($key, $this->params) ? $this->params[$key] : $default;
    }

    /**
     * 取动作私有配置
     *
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    public function option(string $key, $default = null)
    {
        return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
    }

    /**
     * 全部动作私有配置
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->options;
    }

    /* ---------------------------------------------------------------------
     | 回执
     --------------------------------------------------------------------- */

    /**
     * 当前通道的回执方式
     *
     * @return string
     */
    public function replyMode(): string
    {
        return $this->replyMode;
    }

    /**
     * 是否已回执
     *
     * @return bool
     */
    public function isReplied(): bool
    {
        return $this->replied;
    }

    /**
     * 注册首次回执钩子
     *
     * @param callable $hook
     *
     * @return void
     */
    public function setReplyHook(callable $hook): void
    {
        $this->replyHook = $hook;
    }

    /**
     * 成功回执
     *
     * reply = none 时不实际下发，仅标记已回执（见类注释）。
     *
     * @param array<string, mixed> $data 业务数据体
     *
     * @return bool 是否真正下发
     */
    public function reply(array $data = []): bool
    {
        return $this->send(Message::ack($this->seq(), $data));
    }

    /**
     * 错误回执
     *
     * @param int    $code
     * @param string $msg  为空时取错误码默认文案
     *
     * @return bool 是否真正下发
     */
    public function replyError(int $code, string $msg = ''): bool
    {
        return $this->send(Message::error(
            $code,
            $msg,
            $this->seq(),
            isset($this->packet['cmd']) ? (string)$this->packet['cmd'] : ''
        ));
    }

    /**
     * 下发任意报文（需要自定义 cmd 时使用）
     *
     * @param array<string, mixed> $packet
     *
     * @return bool 是否真正下发
     */
    public function send(array $packet): bool
    {
        $first = !$this->replied;

        // 无论是否真正下发，都视为已回执：避免超时保护对静默动作误报
        $this->replied = true;
        if ($first && $this->replyHook !== null) {
            ($this->replyHook)();
        }

        if ($this->replyMode === self::REPLY_NONE) {
            return false;
        }

        ($this->sender)($packet);

        return true;
    }
}
