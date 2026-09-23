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
 * 其后 HTTP 侧（Api 进程）同样需要一个执行入口，三类通道共用本类。
 *
 * 本类把「取动作名 → 校验鉴权 → 校验参数 → 查处理器 → 执行 → 回执」
 * 抽成与通道无关的单一路径，WS / UDP / HTTP 共用，行为差异只体现在
 * 回执的下发方式上（见下）。
 *
 * ---------------------------------------------------------------------
 * 通道判定与回执下发
 * ---------------------------------------------------------------------
 * 通道由 clientId 前缀推断（与 Push::UDP_PREFIX 的约定同构）：
 *   udp:{ip}:{port}    -> udp
 *   http:{request_id}  -> http
 *   其余（数字ID）      -> ws
 *
 * 回执下发：
 *   ws   -> Bootstrap::respond()，即 GatewayClient::sendToClient
 *   udp  -> Push::sendToUdpClient()，写入网关出站队列由网关进程 sendto
 *           （UDP clientId 不在 Gateway 连接表内，sendToClient 对其无效）
 *   http -> ActionReply::store()，写入 action:result:{request_id}
 *           供 Api 进程轮询取回（HTTP 是唯一有「同步等待的调用方」的通道）
 *
 * ---------------------------------------------------------------------
 * HTTP 通道的暴露白名单
 * ---------------------------------------------------------------------
 * HTTP 默认**不开放任何动作**，须在 config/actions.php 中逐个声明
 * `'http' => true` 才可经 POST /action 调用。原因是通道性质差异：
 * HTTP 调用方持有接口密钥即代表任意 uid 发起动作（与 /push 同权，无提权），
 * 但「密钥持有者能做什么」应当是一份显式清单，而不是「所有动作的并集」。
 * 典型不适合暴露的是 session —— 它依赖 clientId 语义，HTTP 下无意义。
 *
 * ---------------------------------------------------------------------
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Business;

use GatewayPush\Common\Logger;
use Workerman\Timer;

/**
 * 业务动作执行器（WS / UDP / HTTP 三通道共用）
 *
 * 把「取动作名 → 鉴权 → 校验参数 → 查处理器 → 执行 → 回执」抽成单一路径；
 * 通道由 clientId 前缀推断，行为差异只体现在回执的下发方式上。
 */
class ActionRunner
{
    /**
     * 默认回执超时（秒），0 表示不启用保护
     */
    public const DEFAULT_TIMEOUT = 5;

    /**
     * params 取该值时表示原样透传，不做白名单过滤
     *
     * 仅供 echo 这类以「原样回显」为目的的动作使用；
     * 业务动作必须显式声明参数规则，避免业务数据被无意透传。
     */
    public const PARAMS_PASSTHROUGH = '*';

    /**
     * 动作声明表：action => 归一化后的声明
     *
     * @var array<string, mixed>
     */
    protected static array $declarations = [];

    /**
     * 处理器实例缓存（处理器无状态，可复用）
     *
     * @var array<string, mixed>
     */
    protected static array $instances = [];

    /**
     * 是否已装载
     *
     * @var bool
     */
    protected static bool $loaded = false;

    /* ---------------------------------------------------------------------
     | 装载与查询
     --------------------------------------------------------------------- */

    /**
     * 从 config/actions.php 装载动作声明
     *
     * 幂等：重复调用会以最后一次为准重建声明表（便于测试与热改配置）。
     *
     * @param array<string, mixed> $config ['defaults' => [...], 'actions' => [...]]
     *
     * @return list<string> 已装载的动作名列表
     */
    public static function load(array $config): array
    {
        self::$declarations = [];
        self::$instances    = [];
        self::$loaded       = true;

        $defaults = [
            'auth'    => true,                                          // 是否要求已鉴权
            'reply'   => [
                ActionContext::CHANNEL_WS   => ActionContext::REPLY_SYNC,
                ActionContext::CHANNEL_UDP  => ActionContext::REPLY_SYNC,
                ActionContext::CHANNEL_HTTP => ActionContext::REPLY_SYNC,
            ],
            'timeout' => self::DEFAULT_TIMEOUT,
            'params'  => [],
            'http'    => false,                                         // 是否开放 HTTP 通道（默认关闭）
        ];
        if (isset($config['defaults']) && is_array($config['defaults'])) {
            $defaults = array_merge($defaults, $config['defaults']);
        }

        $actions = isset($config['actions']) && is_array($config['actions']) ? $config['actions'] : [];
        foreach ($actions as $name => $decl) {
            $name = (string)$name;
            if ($name === '' || !is_array($decl)) {
                continue;
            }

            $handler = isset($decl['handler']) ? (string)$decl['handler'] : '';
            if ($handler === '' || !class_exists($handler)) {
                Logger::warn('业务动作处理器不存在，已跳过注册', [
                    'action'  => $name,
                    'handler' => $handler,
                ]);

                continue;
            }
            if (!in_array(ActionInterface::class, class_implements($handler), true)) {
                Logger::warn('业务动作处理器未实现 ActionInterface，已跳过注册', [
                    'action'  => $name,
                    'handler' => $handler,
                ]);

                continue;
            }

            $item = array_merge($defaults, $decl);
            $item['handler']     = $handler;
            $item['name']        = $name;
            $item['reply']       = self::normalizeReply($item['reply']);
            $item['timeout']     = (int)$item['timeout'];
            // HTTP 暴露白名单：默认关闭，须逐动作显式声明 'http' => true
            $item['http']        = !empty($item['http']);
            // params 支持 '*' 表示原样透传，仅供 echo 这类以回显为目的的动作使用，
            // 业务动作必须显式声明规则（白名单语义）
            $item['params']      = self::normalizeParams($item['params']);
            $item['description'] = isset($item['description']) ? (string)$item['description'] : '';
            // 动作私有配置：由声明携带、经 ActionContext::option() 读取，
            // 使「参数规则之外的少量行为参数」不必下沉到全局 config
            $item['options']     = isset($item['options']) && is_array($item['options']) ? $item['options'] : [];

            self::$declarations[$name] = $item;
        }

        Logger::info('业务动作表装载完成', [
            'count'   => count(self::$declarations),
            'http'    => array_keys(array_filter(self::$declarations, fn ($decl) => !empty($decl['http']))),
            'actions' => array_keys(self::$declarations),
        ]);

        return array_keys(self::$declarations);
    }

    /**
     * 是否已装载动作表
     *
     * @return bool
     */
    public static function loaded(): bool
    {
        return self::$loaded;
    }

    /**
     * 已注册的动作名
     *
     * @return list<string>
     */
    public static function registered(): array
    {
        return array_keys(self::$declarations);
    }

    /**
     * 全部动作声明（供运维接口展示）
     *
     * @return array<string, mixed>
     */
    public static function declarations(): array
    {
        $out = [];
        foreach (self::$declarations as $name => $decl) {
            $out[$name] = [
                'description' => $decl['description'],
                'auth'        => !empty($decl['auth']),
                'reply'       => $decl['reply'],
                'timeout'     => $decl['timeout'],
                'http'        => !empty($decl['http']),
                // 透传规则不是数组，不能直接 array_keys
                'params'      => $decl['params'] === self::PARAMS_PASSTHROUGH
                    ? self::PARAMS_PASSTHROUGH
                    : array_keys($decl['params']),
            ];
        }

        return $out;
    }

    /**
     * 动作是否已注册
     *
     * @param string $action
     *
     * @return bool
     */
    public static function has(string $action): bool
    {
        return isset(self::$declarations[$action]);
    }

    /**
     * 动作是否开放 HTTP 通道
     *
     * 未注册的动作一律返回 false（不存在「未注册但可调用」的中间态）。
     *
     * @param string $action
     *
     * @return bool
     */
    public static function httpExposed(string $action): bool
    {
        $action = $action;

        return isset(self::$declarations[$action]) && !empty(self::$declarations[$action]['http']);
    }

    /**
     * 动作是否在**指定通道**开放（反向白名单）
     *
     * 与 {@see self::httpExposed()} 互为镜像，两者解决的是两个不同方向的问题：
     *
     * | 字段 | 语义 | 缺省 | 解决什么 |
     * |---|---|---|---|
     * | `http => true` | **额外**开放 HTTP | 不开放 | 客户端动作不该被 HTTP 调 |
     * | `channels => [...]` | **只**在这些通道开放 | 全通道 | 运维动作不该被客户端调 |
     *
     * 只有 `http` 这一条单向判定时，**「WS/UDP 侧凡注册即可用」** 是成立的（对外接口文档 §8.5）。
     * 一旦登记运维动作（kick / revoke / unbind）而不声明 `channels`，
     * 任何持自己合法 Token 的终端客户端都能经 WS 踢掉任意 clientId —— 属**终端提权**。
     *
     * ⚠ 缺省（未声明 `channels`）**必须放行**：既有动作全部未声明，收紧默认值即线上行为变更。
     *
     * @param string $action
     * @param string $channel {@see ActionContext::CHANNEL_*} 之一
     *
     * @return bool
     */
    public static function channelExposed(string $action, string $channel): bool
    {
        if (!isset(self::$declarations[$action])) {
            return false;
        }

        $decl = self::$declarations[$action];

        // 未声明 = 全通道放行（向后兼容）
        if (!isset($decl['channels']) || !is_array($decl['channels'])) {
            return true;
        }

        return in_array($channel, $decl['channels'], true);
    }

    /**
     * 已开放 HTTP 通道的动作名
     *
     * @return list<string>
     */
    public static function httpActions(): array
    {
        $names = [];
        foreach (self::$declarations as $name => $decl) {
            if (!empty($decl['http'])) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * 动作声明的通道白名单（未声明时返回空数组，语义是「全通道」）
     *
     * 仅供错误信息展示与自检使用 —— 判定一律走 {@see self::channelExposed()}，
     * 不要把本方法的返回值当成白名单去 `in_array`，那会漏掉「未声明 = 全放行」这一支。
     *
     * @param string $action
     *
     * @return list<string>
     */
    public static function declaredChannels(string $action): array
    {
        $decl = self::$declarations[$action] ?? null;
        if ($decl === null || !isset($decl['channels']) || !is_array($decl['channels'])) {
            return [];
        }

        $out = [];
        foreach ($decl['channels'] as $c) {
            $out[] = is_string($c) ? $c : '';
        }

        return $out;
    }

    /**
     * 取动作声明
     *
     * @param string $action
     *
     * @return null|array<string, mixed>
     */
    public static function declaration(string $action)
    {
        $action = $action;

        return self::$declarations[$action] ?? null;
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
     * @param string               $clientId
     * @param array<string, mixed> $packet   已解码报文（data.action 承载动作名）
     * @param string               $uid
     * @param string               $deviceId
     * @param string               $protocol ws | udp | http
     *
     * @return void
     */
    public static function run(string $clientId, array $packet, string $uid = '', string $deviceId = '', string $protocol = ''): void
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

        // HTTP 通道的暴露白名单。Api 进程在入队前已校验一次，此处是第二道防线 ——
        // 动作队列是 Redis 键，任何持有 Redis 凭证者都可直接写入任务，
        // 因此「能不能经 HTTP 调用」必须由执行方而非投递方裁定。
        if ($channel === ActionContext::CHANNEL_HTTP && empty($decl['http'])) {
            self::fail(
                $clientId,
                $packet,
                $channel,
                Message::CODE_UNKNOWN_CMD,
                '动作未开放 HTTP 通道：' . $action,
                $action
            );

            return;
        }

        // 反向通道白名单：声明了 channels 的动作**只**在这些通道开放。
        //
        // 与上面那道互为反向 —— 上面防「HTTP 调了不该调的」，这道防「WS/UDP 调了不该调的」。
        // 缺省（未声明 channels）一律放行，既有动作行为完全不变。
        //
        // ⚠ 这是运维动作（kick / revoke / unbind）的**安全前提**：不声明 channels=[http]
        //   就等于把管理面能力开放给所有终端用户（任何已鉴权客户端都能借 WS 通道踢任意人）。
        //   Api 侧（C2）另有入队前校验，本处是**执行方裁定** —— 动作队列是 Redis 键，
        //   任何持有 Redis 凭证者都可直接写入任务，故最终裁定权必须在执行侧（硬约束 ㉗）。
        $allowed = isset($decl['channels']) && is_array($decl['channels']) ? $decl['channels'] : null;
        if ($allowed !== null && !in_array($channel, $allowed, true)) {
            self::fail(
                $clientId,
                $packet,
                $channel,
                Message::CODE_UNKNOWN_CMD,
                '动作未开放该通道：' . $action . '（当前通道：' . $channel . '）',
                $action
            );

            return;
        }

        // 动作级鉴权要求。
        // UDP 通道没有「连接」概念，也就没有连接级鉴权闸门，其身份完全依赖
        // 报文内 uid + 签名校验 —— 因此这道检查对 UDP 是唯一的业务侧鉴权防线。
        if (!empty($decl['auth']) && $uid === '' && Auth::enabled()) {
            Monitor::incr('action_fail');
            self::incrChannel($channel, 'fail');
            Logger::warn('动作要求鉴权但身份缺失，已拒绝', [
                'action'    => $action,
                'client_id' => $clientId,
                'channel'   => $channel,
            ]);
            self::emitError($clientId, $packet, $channel, Message::CODE_UNAUTHORIZED, '请先完成鉴权');

            return;
        }

        // 参数校验：规则外的一律丢弃，处理器拿到的一定是归一化参数
        $raw = isset($packet['data']['params']) && is_array($packet['data']['params'])
            ? $packet['data']['params']
            : [];

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

        $replyMode = $decl['reply'][$channel] ?? ActionContext::REPLY_SYNC;

        $ctx = new ActionContext(
            $action,
            $packet,
            $params,
            [
                'client_id' => $clientId,
                'uid'       => $uid,
                'device_id' => $deviceId,
                'protocol'  => $protocol,
            ],
            $channel,
            $replyMode,
            self::sender($channel, $clientId),
            $decl['options']
        );

        Monitor::incr('action_in');
        self::incrChannel($channel, 'in');

        // 超时保护：处理器可能走 Redis 异步回执，若回调始终不来，
        // 客户端会永久等待。定时器在首次回执时由钩子注销，未回执则兜底。
        /** @var null|int $timerId 先声明、下方按需赋值：回执钩子必须按引用捕获它 */
        $timerId = null;
        $ctx->setReplyHook(function () use (&$timerId) {
            if ($timerId !== null) {
                Timer::del($timerId);
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
                self::incrChannel($ctx->channel(), 'timeout');
                Logger::warn('业务动作超时未回执', [
                    'action'    => $ctx->action(),
                    'client_id' => $ctx->clientId(),
                    'channel'   => $ctx->channel(),
                    'timeout'   => $timeout,
                ]);
                $ctx->replyError(Message::CODE_SERVER_ERROR, '动作处理超时');
            }, [], false);
        }

        try {
            self::instance($action, $decl)->handle($ctx);
            Monitor::incr('action_ok');
            self::incrChannel($channel, 'ok');

            Logger::debug('业务动作已执行', [
                'action'    => $action,
                'client_id' => $clientId,
                'channel'   => $channel,
                'uid'       => $uid,
                'reply'     => $replyMode,
                'replied'   => $ctx->isReplied() ? 1 : 0,
            ]);
        } catch (\Throwable $e) {
            Monitor::incr('action_fail');
            self::incrChannel($channel, 'fail');
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
     * 前缀表而非二元判断：新增通道只需在此追加一行，不需要改动 sender /
     * emitError 等分支的判断结构。
     *
     * @param string $clientId
     *
     * @return string
     */
    protected static function channelOf(string $clientId): string
    {
        $clientId = $clientId;

        if (str_starts_with($clientId, Push::UDP_PREFIX)) {
            return ActionContext::CHANNEL_UDP;
        }
        if (ActionReply::isHttpClient($clientId)) {
            return ActionContext::CHANNEL_HTTP;
        }

        return ActionContext::CHANNEL_WS;
    }

    /**
     * 分通道指标自增
     *
     * 仅为 HTTP 通道单独计数 —— 既有 ws / udp 沿用聚合指标，不引入指标名变更，
     * 以免面板分组与历史数据对比失效。HTTP 是新通道，需要能把它从
     * action_in / action_ok 的合计里区分出来。
     *
     * @param string $channel
     * @param string $suffix  in | ok | fail | timeout
     *
     * @return void
     */
    protected static function incrChannel(string $channel, string $suffix): void
    {
        if ($channel === ActionContext::CHANNEL_HTTP) {
            Monitor::incr('action_http_' . $suffix);
        }
    }

    /**
     * 回执下发器
     *
     * @param string $channel
     * @param string $clientId
     *
     * @return callable function (array $packet): void
     */
    protected static function sender(string $channel, string $clientId)
    {
        if ($channel === ActionContext::CHANNEL_UDP) {
            return function (array $packet) use ($clientId) {
                Push::sendToUdpClient($clientId, $packet);
            };
        }

        if ($channel === ActionContext::CHANNEL_HTTP) {
            return function (array $packet) use ($clientId) {
                ActionReply::store($clientId, $packet);
            };
        }

        return function (array $packet) use ($clientId) {
            Bootstrap::respond($clientId, $packet);
        };
    }

    /**
     * 取处理器实例（无状态，惰性创建并缓存）
     *
     * @param string               $action
     * @param array<string, mixed> $decl
     *
     * @return ActionInterface
     */
    protected static function instance(string $action, array $decl)
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
     *   'reply' => 'sync'                                         三通道相同
     *   'reply' => ['ws' => 'sync', 'udp' => 'none']              按通道分别声明
     *
     * 未声明的通道回落 sync —— 因此既有的双通道声明（不含 http 键）无需改动
     * 即自动获得 HTTP 通道的 sync 语义。
     *
     * @param mixed $reply
     *
     * @return array<string, mixed> ['ws' => .., 'udp' => .., 'http' => ..]
     */
    protected static function normalizeReply($reply): array
    {
        if (is_array($reply)) {
            return [
                ActionContext::CHANNEL_WS   => self::pickReply($reply[ActionContext::CHANNEL_WS] ?? null),
                ActionContext::CHANNEL_UDP  => self::pickReply($reply[ActionContext::CHANNEL_UDP] ?? null),
                ActionContext::CHANNEL_HTTP => self::pickReply($reply[ActionContext::CHANNEL_HTTP] ?? null),
            ];
        }

        return [
            ActionContext::CHANNEL_WS   => self::pickReply($reply),
            ActionContext::CHANNEL_UDP  => self::pickReply($reply),
            ActionContext::CHANNEL_HTTP => self::pickReply($reply),
        ];
    }

    /**
     * 参数规则归一化
     *
     * '*' -> 透传标记；数组 -> 原样；其余非法值 -> 空规则（拒绝一切参数）
     *
     * @param mixed $params
     *
     * @return array<string, mixed>|string
     */
    protected static function normalizeParams($params)
    {
        if ($params === self::PARAMS_PASSTHROUGH) {
            return self::PARAMS_PASSTHROUGH;
        }

        return is_array($params) ? $params : [];
    }

    /**
     * @param mixed $value
     *
     * @return string
     */
    protected static function pickReply($value): string
    {
        return (string)$value === ActionContext::REPLY_NONE
            ? ActionContext::REPLY_NONE
            : ActionContext::REPLY_SYNC;
    }

    /**
     * 执行前失败（未知动作 / 参数非法 / 身份缺失）
     *
     * @param string               $clientId
     * @param array<string, mixed> $packet
     * @param string               $channel
     * @param int                  $code
     * @param string               $msg
     * @param string               $action
     *
     * @return void
     */
    protected static function fail(string $clientId, array $packet, string $channel, int $code, string $msg = '', string $action = ''): void
    {
        Monitor::incr('action_fail');
        self::incrChannel($channel, 'fail');
        Monitor::incr('msg_fail');

        Logger::warn('业务动作执行前失败', [
            'client_id' => $clientId,
            'channel'   => $channel,
            'action'    => $action !== '' ? $action : (isset($packet['data']['action']) ? (string)$packet['data']['action'] : ''),
            'code'      => $code,
            'msg'       => $msg,
        ]);

        self::emitError($clientId, $packet, $channel, $code, $msg);
    }

    /**
     * 错误报文下发（含通道抑制策略）
     *
     * UDP 通道一律静默：对超限 / 非法报文回错误会形成反射放大，
     * 与限流模块「UDP 超限静默丢弃」的处置原则保持一致。
     *
     * 其余通道均需下发 —— WS 直发连接，HTTP 写入结果回程键（api 进程据此
     * 把错误码透出给调用方，这是 HTTP 相对 UDP 的关键差异：调用方在同步等待，
     * 静默会让它一直等到超窗）。
     *
     * @param string               $clientId
     * @param array<string, mixed> $packet
     * @param string               $channel
     * @param int                  $code
     * @param string               $msg
     *
     * @return void
     */
    protected static function emitError(string $clientId, array $packet, string $channel, int $code, string $msg = ''): void
    {
        if ($channel === ActionContext::CHANNEL_UDP) {
            return;
        }

        $sender = self::sender($channel, $clientId);
        $sender(Message::error(
            $code,
            $msg,
            isset($packet['seq']) ? (string)$packet['seq'] : '',
            isset($packet['cmd']) ? (string)$packet['cmd'] : ''
        ));
    }
}
