<?php
/**
 * 业务动作执行上下文
 *
 * 由 ActionRunner 构造并注入处理器，承载三件事：
 *   1. 身份与来源：clientId / uid / deviceId / protocol / channel
 *   2. 已校验参数：params() 返回经 ParamValidator 归一化后的参数，
 *      处理器无需再做类型判断与默认值填充
 *   3. 统一回执：reply() / replyError()，通道差异（WS 直发 / UDP 出站队列）
 *      由构造时注入的 sender 抹平，处理器无感知
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
 * 兼容 PHP 8.0 ~ 8.5
 */

namespace GatewayPush\Business;

class ActionContext
{
    /* ---------------------- 通道 ---------------------- */
    const CHANNEL_WS  = 'ws';
    const CHANNEL_UDP = 'udp';

    /* ---------------------- 回执方式 ---------------------- */
    const REPLY_SYNC = 'sync';   // 处理结果下发客户端
    const REPLY_NONE = 'none';   // 静默处理，不下发任何报文

    /**
     * 动作名
     *
     * @var string
     */
    protected $action;

    /**
     * 原始报文
     *
     * @var array
     */
    protected $packet;

    /**
     * 已校验并归一化的业务参数
     *
     * @var array
     */
    protected $params;

    /**
     * 连接标识
     *
     * @var string
     */
    protected $clientId;

    /**
     * 用户标识（未鉴权为空串）
     *
     * @var string
     */
    protected $uid;

    /**
     * 设备标识
     *
     * @var string
     */
    protected $deviceId;

    /**
     * 协议类型（ws / udp）
     *
     * @var string
     */
    protected $protocol;

    /**
     * 来源通道（ws / udp）
     *
     * @var string
     */
    protected $channel;

    /**
     * 回执方式
     *
     * @var string
     */
    protected $replyMode;

    /**
     * 报文下发器，签名 function (array $packet): void
     *
     * @var callable
     */
    protected $sender;

    /**
     * 是否已回执（含被抑制的回执）
     *
     * @var bool
     */
    protected $replied = false;

    /**
     * 首次回执后的钩子，供 ActionRunner 注销超时定时器
     *
     * @var callable|null
     */
    protected $replyHook;

    /**
     * 动作私有配置（来自 config/actions.php 的 options 段）
     *
     * @var array
     */
    protected $options = array();

    /**
     * @param string   $action    动作名
     * @param array    $packet    原始报文
     * @param array    $params    已校验参数
     * @param array    $identity  ['client_id','uid','device_id','protocol']
     * @param string   $channel   ws | udp
     * @param string   $replyMode sync | none
     * @param callable $sender    function (array $packet): void
     * @param array    $options   动作私有配置
     */
    public function __construct($action, array $packet, array $params, array $identity, $channel, $replyMode, callable $sender, array $options = array())
    {
        $this->options = $options;
        $this->action    = (string)$action;
        $this->packet    = $packet;
        $this->params    = $params;
        $this->clientId  = isset($identity['client_id']) ? (string)$identity['client_id'] : '';
        $this->uid       = isset($identity['uid']) ? (string)$identity['uid'] : '';
        $this->deviceId  = isset($identity['device_id']) ? (string)$identity['device_id'] : '';
        $this->protocol  = isset($identity['protocol']) ? (string)$identity['protocol'] : '';
        $this->channel   = (string)$channel;
        $this->replyMode = (string)$replyMode === self::REPLY_NONE ? self::REPLY_NONE : self::REPLY_SYNC;
        $this->sender    = $sender;
    }

    /* ---------------------------------------------------------------------
     | 身份与来源
     --------------------------------------------------------------------- */

    /**
     * @return string
     */
    public function action()
    {
        return $this->action;
    }

    /**
     * @return string
     */
    public function clientId()
    {
        return $this->clientId;
    }

    /**
     * @return string
     */
    public function uid()
    {
        return $this->uid;
    }

    /**
     * @return string
     */
    public function deviceId()
    {
        return $this->deviceId;
    }

    /**
     * @return string
     */
    public function protocol()
    {
        return $this->protocol;
    }

    /**
     * @return string
     */
    public function channel()
    {
        return $this->channel;
    }

    /**
     * 报文序号（回执原样带回，客户端据此关联请求）
     *
     * @return string
     */
    public function seq()
    {
        return isset($this->packet['seq']) ? (string)$this->packet['seq'] : '';
    }

    /**
     * 原始报文
     *
     * @return array
     */
    public function packet()
    {
        return $this->packet;
    }

    /* ---------------------------------------------------------------------
     | 参数
     --------------------------------------------------------------------- */

    /**
     * 全部已校验参数
     *
     * @return array
     */
    public function params()
    {
        return $this->params;
    }

    /**
     * 取单个已校验参数
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function param($key, $default = null)
    {
        return array_key_exists($key, $this->params) ? $this->params[$key] : $default;
    }

    /**
     * 取动作私有配置
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function option($key, $default = null)
    {
        return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
    }

    /**
     * 全部动作私有配置
     *
     * @return array
     */
    public function options()
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
    public function replyMode()
    {
        return $this->replyMode;
    }

    /**
     * 是否已回执
     *
     * @return bool
     */
    public function isReplied()
    {
        return $this->replied;
    }

    /**
     * 注册首次回执钩子
     *
     * @param callable $hook
     * @return void
     */
    public function setReplyHook(callable $hook)
    {
        $this->replyHook = $hook;
    }

    /**
     * 成功回执
     *
     * reply = none 时不实际下发，仅标记已回执（见类注释）。
     *
     * @param array $data 业务数据体
     * @return bool 是否真正下发
     */
    public function reply(array $data = array())
    {
        return $this->send(Message::ack($this->seq(), $data));
    }

    /**
     * 错误回执
     *
     * @param int    $code
     * @param string $msg 为空时取错误码默认文案
     * @return bool 是否真正下发
     */
    public function replyError($code, $msg = '')
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
     * @param array $packet
     * @return bool 是否真正下发
     */
    public function send(array $packet)
    {
        $first = !$this->replied;

        // 无论是否真正下发，都视为已回执：避免超时保护对静默动作误报
        $this->replied = true;
        if ($first && $this->replyHook !== null) {
            call_user_func($this->replyHook);
        }

        if ($this->replyMode === self::REPLY_NONE) {
            return false;
        }

        call_user_func($this->sender, $packet);
        return true;
    }
}
