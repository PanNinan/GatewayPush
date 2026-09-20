<?php
/**
 * 业务动作执行器（通道无关）
 *
 * ---------------------------------------------------------------------
 * 解决的问题
 * ---------------------------------------------------------------------
 * 改造前，action 的执行流程只存在于 WebSocket 侧（Bootstrap::handleData），
 * UDP 侧（Bootstrap::processUdpJob）仅打印一条 debug 日志 —— 同一份业务
 * 动作在 WS 上可用、在 UDP 上无处执行，与「双协议兼容」的核心需求冲突。
 *
 * 本类把「取动作名 → 校验鉴权 → 校验参数 → 查处理器 → 执行 → 回执」
 * 抽成与通道无关的单一路径，WS 与 UDP 共用，两者行为差异只体现在
 * 回执的下发方式上（见下）。
 *
 * ---------------------------------------------------------------------
 * 通道判定与回执下发
 * ---------------------------------------------------------------------
 * 通道由 clientId 前缀推断（与 Session::PROTOCOL_UDP 的约定一致）：
 *   udp:{ip}:{port}  -> udp
 *   其余（数字ID）    -> ws
 *
 * 回执下发：
 *   ws  -> Bootstrap::respond()，即 GatewayClient::sendToClient
 *   udp -> Push::sendToUdpClient()，写入网关出站队列由网关进程 sendto
 *          （UDP clientId 不在 Gateway 连接表内，sendToClient 对其无效）
 *
 * ---------------------------------------------------------------------
 * 兼容 PHP 8.0 ~ 8.5
 */

namespace GatewayPush\Business;

use GatewayPush\Common\Logger;
use Workerman\Timer;

class ActionRunner
{
    /**
     * 默认回执超时（秒），0 表示不启用保护
     */
    const DEFAULT_TIMEOUT = 5;

    /**
     * params 取该值时表示原样透传，不做白名单过滤
     *
     * 仅供 echo 这类以「原样回显」为目的的动作使用；
     * 业务动作必须显式声明参数规则，避免业务数据被无意透传。
     */
    const PARAMS_PASSTHROUGH = '*';

    /**
     * 动作声明表：action => 归一化后的声明
     *
     * @var array
     */
    protected static $declarations = array();

    /**
     * 处理器实例缓存（处理器无状态，可复用）
     *
     * @var array
     */
    protected static $instances = array();

    /**
     * 是否已装载
     *
     * @var bool
     */
    protected static $loaded = false;

    /* ---------------------------------------------------------------------
     | 装载与查询
     --------------------------------------------------------------------- */

    /**
     * 从 config/actions.php 装载动作声明
     *
     * 幂等：重复调用会以最后一次为准重建声明表（便于测试与热改配置）。
     *
     * @param array $config ['defaults' => [...], 'actions' => [...]]
     * @return array 已装载的动作名列表
     */
    public static function load(array $config)
    {
        self::$declarations = array();
        self::$instances    = array();
        self::$loaded       = true;

        $defaults = array(
            'auth'    => true,                                          // 是否要求已鉴权
            'reply'   => array('ws' => ActionContext::REPLY_SYNC, 'udp' => ActionContext::REPLY_SYNC),
            'timeout' => self::DEFAULT_TIMEOUT,
            'params'  => array(),
        );
        if (isset($config['defaults']) && is_array($config['defaults'])) {
            $defaults = array_merge($defaults, $config['defaults']);
        }

        $actions = isset($config['actions']) && is_array($config['actions']) ? $config['actions'] : array();
        foreach ($actions as $name => $decl) {
            $name = (string)$name;
            if ($name === '' || !is_array($decl)) {
                continue;
            }

            $handler = isset($decl['handler']) ? (string)$decl['handler'] : '';
            if ($handler === '' || !class_exists($handler)) {
                Logger::warn('业务动作处理器不存在，已跳过注册', array(
                    'action'  => $name,
                    'handler' => $handler,
                ));
                continue;
            }
            if (!in_array(ActionInterface::class, class_implements($handler), true)) {
                Logger::warn('业务动作处理器未实现 ActionInterface，已跳过注册', array(
                    'action'  => $name,
                    'handler' => $handler,
                ));
                continue;
            }

            $item = array_merge($defaults, $decl);
            $item['handler']     = $handler;
            $item['name']        = $name;
            $item['reply']       = self::normalizeReply($item['reply']);
            $item['timeout']     = (int)$item['timeout'];
            // params 支持 '*' 表示原样透传，仅供 echo 这类以回显为目的的动作使用，
            // 业务动作必须显式声明规则（白名单语义）
            $item['params']      = self::normalizeParams($item['params']);
            $item['description'] = isset($item['description']) ? (string)$item['description'] : '';
            // 动作私有配置：由声明携带、经 ActionContext::option() 读取，
            // 使「参数规则之外的少量行为参数」不必下沉到全局 config
            $item['options']     = isset($item['options']) && is_array($item['options']) ? $item['options'] : array();

            self::$declarations[$name] = $item;
        }

        Logger::info('业务动作表装载完成', array(
            'count'   => count(self::$declarations),
            'actions' => array_keys(self::$declarations),
        ));

        return array_keys(self::$declarations);
    }

    /**
     * 是否已装载动作表
     *
     * @return bool
     */
    public static function loaded()
    {
        return self::$loaded;
    }

    /**
     * 已注册的动作名
     *
     * @return array
     */
    public static function registered()
    {
        return array_keys(self::$declarations);
    }

    /**
     * 全部动作声明（供运维接口展示）
     *
     * @return array
     */
    public static function declarations()
    {
        $out = array();
        foreach (self::$declarations as $name => $decl) {
            $out[$name] = array(
                'description' => $decl['description'],
                'auth'        => !empty($decl['auth']),
                'reply'       => $decl['reply'],
                'timeout'     => $decl['timeout'],
                // 透传规则不是数组，不能直接 array_keys
                'params'      => $decl['params'] === self::PARAMS_PASSTHROUGH
                    ? self::PARAMS_PASSTHROUGH
                    : array_keys($decl['params']),
            );
        }
        return $out;
    }

    /**
     * 动作是否已注册
     *
     * @param string $action
     * @return bool
     */
    public static function has($action)
    {
        return isset(self::$declarations[(string)$action]);
    }

    /**
     * 取动作声明
     *
     * @param string $action
     * @return array|null
     */
    public static function declaration($action)
    {
        $action = (string)$action;
        return isset(self::$declarations[$action]) ? self::$declarations[$action] : null;
    }

    /* ---------------------------------------------------------------------
     | 执行
     --------------------------------------------------------------------- */

    /**
     * 执行一个业务动作
     *
     * 通道由 clientId 前缀推断，调用方无需显式传参 —— 这样
     * Bootstrap 的 WS 链路与 UDP 链路可以共用同一次调用。
     *
     * @param string $clientId
     * @param array  $packet   已解码报文（data.action 承载动作名）
     * @param string $uid
     * @param string $deviceId
     * @param string $protocol ws | udp
     * @return void
     */
    public static function run($clientId, array $packet, $uid = '', $deviceId = '', $protocol = '')
    {
        $channel = self::channelOf($clientId);

        $action = isset($packet['data']['action']) ? (string)$packet['data']['action'] : '';
        if ($action === '') {
            self::fail($clientId, $packet, $channel, Message::CODE_PARAM_MISSING, '缺少 data.action');
            return;
        }

        $decl = self::declaration($action);
        if ($decl === null) {
            self::fail($clientId, $packet, $channel, Message::CODE_UNKNOWN_CMD, '未知业务动作：' . $action);
            return;
        }

        // 动作级鉴权要求。
        // UDP 通道没有「连接」概念，也就没有连接级鉴权闸门，其身份完全依赖
        // 报文内 uid + 签名校验 —— 因此这道检查对 UDP 是唯一的业务侧鉴权防线。
        if (!empty($decl['auth']) && Auth::enabled() && (string)$uid === '') {
            Monitor::incr('action_fail');
            Logger::warn('动作要求鉴权但身份缺失，已拒绝', array(
                'action'    => $action,
                'client_id' => $clientId,
                'channel'   => $channel,
            ));
            self::emitError($clientId, $packet, $channel, Message::CODE_UNAUTHORIZED, '请先完成鉴权');
            return;
        }

        // 参数校验：规则外的一律丢弃，处理器拿到的一定是归一化参数
        $raw = isset($packet['data']['params']) && is_array($packet['data']['params'])
            ? $packet['data']['params']
            : array();

        if ($decl['params'] === self::PARAMS_PASSTHROUGH) {
            $params = $raw;
        } else {
            $reason = '';
            $params = ParamValidator::validate($decl['params'], $raw, $reason);
            if ($params === null) {
                self::fail($clientId, $packet, $channel, Message::CODE_PARAM_MISSING, $reason, $action);
                return;
            }
        }

        $replyMode = isset($decl['reply'][$channel])
            ? $decl['reply'][$channel]
            : ActionContext::REPLY_SYNC;

        $ctx = new ActionContext(
            $action,
            $packet,
            $params,
            array(
                'client_id' => (string)$clientId,
                'uid'       => (string)$uid,
                'device_id' => (string)$deviceId,
                'protocol'  => (string)$protocol,
            ),
            $channel,
            $replyMode,
            self::sender($channel, $clientId),
            $decl['options']
        );

        Monitor::incr('action_in');

        // 超时保护：处理器可能走 Redis 异步回执，若回调始终不来，
        // 客户端会永久等待。定时器在首次回执时由钩子注销，未回执则兜底。
        $timerId = null;
        $ctx->setReplyHook(function () use (&$timerId) {
            if ($timerId !== null) {
                Timer::del((int)$timerId);
                $timerId = null;
            }
        });

        if ($decl['timeout'] > 0) {
            $timeout = $decl['timeout'];
            $timerId = Timer::add($timeout, function () use ($ctx, $timeout) {
                if ($ctx->isReplied()) {
                    return;
                }
                Monitor::incr('action_timeout');
                Logger::warn('业务动作超时未回执', array(
                    'action'    => $ctx->action(),
                    'client_id' => $ctx->clientId(),
                    'channel'   => $ctx->channel(),
                    'timeout'   => $timeout,
                ));
                $ctx->replyError(Message::CODE_SERVER_ERROR, '动作处理超时');
            }, array(), false);
        }

        try {
            self::instance($action, $decl)->handle($ctx);
            Monitor::incr('action_ok');

            Logger::debug('业务动作已执行', array(
                'action'    => $action,
                'client_id' => $clientId,
                'channel'   => $channel,
                'uid'       => $uid,
                'reply'     => $replyMode,
                'replied'   => $ctx->isReplied() ? 1 : 0,
            ));
        } catch (\Throwable $e) {
            Monitor::incr('action_fail');
            Logger::exception($e, 'action:' . $action);
            $ctx->replyError(Message::CODE_SERVER_ERROR);
        }
    }

    /* ---------------------------------------------------------------------
     | 内部实现
     --------------------------------------------------------------------- */

    /**
     * 由 clientId 前缀推断通道
     *
     * @param string $clientId
     * @return string
     */
    protected static function channelOf($clientId)
    {
        return strpos((string)$clientId, Push::UDP_PREFIX) === 0
            ? ActionContext::CHANNEL_UDP
            : ActionContext::CHANNEL_WS;
    }

    /**
     * 回执下发器
     *
     * @param string $channel
     * @param string $clientId
     * @return callable function (array $packet): void
     */
    protected static function sender($channel, $clientId)
    {
        if ($channel === ActionContext::CHANNEL_UDP) {
            return function (array $packet) use ($clientId) {
                Push::sendToUdpClient($clientId, $packet);
            };
        }

        return function (array $packet) use ($clientId) {
            Bootstrap::respond($clientId, $packet);
        };
    }

    /**
     * 取处理器实例（无状态，惰性创建并缓存）
     *
     * @param string $action
     * @param array  $decl
     * @return ActionInterface
     */
    protected static function instance($action, array $decl)
    {
        if (isset(self::$instances[$action])) {
            return self::$instances[$action];
        }

        $handler = $decl['handler'];
        self::$instances[$action] = new $handler();

        return self::$instances[$action];
    }

    /**
     * 回执方式归一化
     *
     * 支持两种写法：
     *   'reply' => 'sync'                             两通道相同
     *   'reply' => ['ws' => 'sync', 'udp' => 'none']  按通道分别声明
     *
     * @param mixed $reply
     * @return array ['ws' => .., 'udp' => ..]
     */
    protected static function normalizeReply($reply)
    {
        if (is_array($reply)) {
            return array(
                ActionContext::CHANNEL_WS  => self::pickReply(isset($reply[ActionContext::CHANNEL_WS]) ? $reply[ActionContext::CHANNEL_WS] : null),
                ActionContext::CHANNEL_UDP => self::pickReply(isset($reply[ActionContext::CHANNEL_UDP]) ? $reply[ActionContext::CHANNEL_UDP] : null),
            );
        }

        return array(
            ActionContext::CHANNEL_WS  => self::pickReply($reply),
            ActionContext::CHANNEL_UDP => self::pickReply($reply),
        );
    }

    /**
     * 参数规则归一化
     *
     * '*' -> 透传标记；数组 -> 原样；其余非法值 -> 空规则（拒绝一切参数）
     *
     * @param mixed $params
     * @return array|string
     */
    protected static function normalizeParams($params)
    {
        if ($params === self::PARAMS_PASSTHROUGH) {
            return self::PARAMS_PASSTHROUGH;
        }
        return is_array($params) ? $params : array();
    }

    /**
     * @param mixed $value
     * @return string
     */
    protected static function pickReply($value)
    {
        return (string)$value === ActionContext::REPLY_NONE
            ? ActionContext::REPLY_NONE
            : ActionContext::REPLY_SYNC;
    }

    /**
     * 执行前失败（未知动作 / 参数非法 / 身份缺失）
     *
     * @param string $clientId
     * @param array  $packet
     * @param string $channel
     * @param int    $code
     * @param string $msg
     * @param string $action
     * @return void
     */
    protected static function fail($clientId, array $packet, $channel, $code, $msg = '', $action = '')
    {
        Monitor::incr('action_fail');
        Monitor::incr('msg_fail');

        Logger::warn('业务动作执行前失败', array(
            'client_id' => $clientId,
            'channel'   => $channel,
            'action'    => $action !== '' ? $action : (isset($packet['data']['action']) ? (string)$packet['data']['action'] : ''),
            'code'      => $code,
            'msg'       => $msg,
        ));

        self::emitError($clientId, $packet, $channel, $code, $msg);
    }

    /**
     * 错误报文下发（含通道抑制策略）
     *
     * UDP 通道一律静默：对超限 / 非法报文回错误会形成反射放大，
     * 与限流模块「UDP 超限静默丢弃」的处置原则保持一致。
     *
     * @param string $clientId
     * @param array  $packet
     * @param string $channel
     * @param int    $code
     * @param string $msg
     * @return void
     */
    protected static function emitError($clientId, array $packet, $channel, $code, $msg = '')
    {
        if ($channel !== ActionContext::CHANNEL_WS) {
            return;
        }

        call_user_func(self::sender($channel, $clientId), Message::error(
            $code,
            $msg,
            isset($packet['seq']) ? (string)$packet['seq'] : '',
            isset($packet['cmd']) ? (string)$packet['cmd'] : ''
        ));
    }
}
