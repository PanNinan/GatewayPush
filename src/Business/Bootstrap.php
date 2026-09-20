<?php
/**
 * BusinessWorker 业务进程启动入口与事件处理器
 *
 * 本类同时承担两个角色：
 *  1. init()  —— 创建 BusinessWorker 实例，绑定注册中心与事件处理器
 *  2. 静态事件方法 —— 由 BusinessWorker 通过 eventHandler 反射调用
 *     onWorkerStart / onConnect / onMessage / onClose / onWebSocketConnect / onWorkerStop
 *
 * 职责范围（对应文档 3.1.2）：承载全部业务逻辑，直接操作 Redis，
 * 完成鉴权、会话管理、单对一定向推送、定时任务、指标统计。
 *
 * 兼容 PHP 8.0 ~ 8.5
 */

namespace GatewayPush\Business;

use GatewayPush\Common\Logger;
use GatewayPush\Common\RateLimiter;
use GatewayPush\Common\RedisClient;
use GatewayPush\Common\WorkerEvents;
use GatewayWorker\BusinessWorker;
use GatewayWorker\Lib\Context;
use GatewayWorker\Lib\Gateway as GatewayClient;
use Workerman\Timer;

class Bootstrap
{
    /**
     * business.php 配置
     *
     * @var array
     */
    protected static $config = array();

    /**
     * app.php 配置
     *
     * @var array
     */
    protected static $appConfig = array();

    /**
     * 已通过鉴权的连接：clientId => uid（进程内，随连接生命周期）
     *
     * 存 uid 而非单纯的 true，是为了让报文级限流的用户维度无需再查一次
     * Redis 会话（每报文省一次往返）。uid 为空串表示鉴权功能关闭下的直通连接。
     *
     * @var array
     */
    protected static $authed = array();

    /**
     * 鉴权超时定时器
     *
     * @var array
     */
    protected static $authTimers = array();

    /**
     * 指令路由表是否已注册
     *
     * @var bool
     */
    protected static $routesRegistered = false;

    /* ---------------------------------------------------------------------
     | 初始化
     --------------------------------------------------------------------- */

    /**
     * 创建并配置 BusinessWorker
     *
     * @param array $businessConfig config/business.php
     * @param array $appConfig      config/app.php
     * @param array $gatewayConfig  config/gateway.php（仅取 UDP 出站队列配置）
     * @param array $actionConfig   config/actions.php（业务动作清单）
     * @return void
     */
    public static function init(array $businessConfig, array $appConfig, array $gatewayConfig = array(), array $actionConfig = array())
    {
        if (!self::roleEnabled('business')) {
            return;
        }

        self::$config    = $businessConfig;
        self::$appConfig = $appConfig;

        Auth::init($appConfig['auth']);
        Session::init($appConfig['session']);
        Monitor::init($appConfig['monitor']);
        RateLimiter::init(isset($appConfig['rate_limit']) ? $appConfig['rate_limit'] : array());
        Subscribe::init(isset($appConfig['subscribe']) ? $appConfig['subscribe'] : array());

        // 装载业务动作表：WS 与 UDP 两条链路共用同一份声明，
        // 差异只在回执方式（见 config/actions.php 的 reply 段）
        ActionRunner::load($actionConfig);

        // UDP 出站队列 key 与网关进程同源（gateway.udp.out_queue），避免两套真源
        Push::init(
            $appConfig['push'],
            $businessConfig['push_queue'],
            isset($gatewayConfig['udp']['out_queue']) ? $gatewayConfig['udp']['out_queue'] : array()
        );

        // 注册指令路由表（一级 cmd + 二级 data.action）
        self::registerDefaultRoutes();

        $conf   = $businessConfig['worker'];
        $worker = new BusinessWorker();

        $worker->name            = $conf['name'];
        $worker->count           = self::resolveCount($conf['count']);
        $worker->registerAddress = $businessConfig['register_address'];
        $worker->secretKey       = self::internalSecret();
        $worker->eventHandler    = self::class;

        // 该回调在 BusinessWorker 内部事件绑定之前执行，用于完成进程级初始化
        $worker->onWorkerStart = function ($worker) {
            Logger::init(self::$appConfig['log']);
            RedisClient::init(self::$appConfig['redis']);

            // Lib\Gateway 不会自动继承 BusinessWorker 的注册中心配置，需显式设置，
            // 否则定向推送 / 踢人等跨进程操作会连向默认地址 127.0.0.1:1236
            GatewayClient::$registerAddress = self::$config['register_address'];
            GatewayClient::$secretKey       = self::internalSecret();

            Task::init(self::$config['tasks'], $worker->id, $worker->name);

            Logger::info('BusinessWorker 启动完成', array(
                'id'          => $worker->id,
                'count'       => $worker->count,
                'register'    => self::$config['register_address'],
                'redis'       => self::$appConfig['redis']['host'] . ':' . self::$appConfig['redis']['port'],
                'auth_enable' => Auth::enabled() ? 1 : 0,
            ));
        };

        $worker->onWorkerStop = function ($worker) {
            Logger::info('BusinessWorker 正在停止，释放连接资源', array('id' => $worker->id));
            RedisClient::closeAll();
        };

        // 连接级错误与背压观测（业务进程持有与网关的内部连接）
        WorkerEvents::bind($worker, $conf['name']);
    }

    /* ---------------------------------------------------------------------
     | 事件回调
     --------------------------------------------------------------------- */

    /**
     * 连接建立
     *
     * @param string $clientId
     * @return void
     */
    public static function onConnect($clientId)
    {
        Monitor::incr('conn_open');

        Logger::info('客户端连接建立', array(
            'client_id' => $clientId,
            'ip'        => Context::$client_ip,
            'port'      => Context::$client_port,
        ));

        if (!Auth::enabled()) {
            self::markAuthed($clientId);
            return;
        }

        // 鉴权超时保护：规定时间内未完成鉴权则强制断开，防止空连接占用资源
        $timeout = Auth::authTimeout();
        if ($timeout <= 0) {
            return;
        }

        self::$authTimers[$clientId] = Timer::add($timeout, function () use ($clientId) {
            unset(self::$authTimers[$clientId]);
            if (self::isAuthed($clientId)) {
                return;
            }
            Logger::warn('连接未在超时时间内完成鉴权，执行断开', array(
                'client_id' => $clientId,
                'timeout'   => Auth::authTimeout(),
            ));
            Monitor::incr('auth_fail');
            self::closeClient($clientId, Message::CODE_UNAUTHORIZED, '鉴权超时');
        }, array(), false);
    }

    /**
     * 收到客户端消息
     *
     * 流程：解码 -> 报文级限流 -> 业务分发。
     * 限流置于解码之后、业务之前：解码是纯本地计算且已有长度上限保护，
     * 先解码才能拿到 cmd 以区分心跳与业务指令的配额。
     *
     * @param string $clientId
     * @param mixed  $rawMessage
     * @return void
     */
    public static function onMessage($clientId, $rawMessage)
    {
        Monitor::incr('msg_in');

        // 任意上行数据均视为活跃，刷新心跳时间戳
        if (self::isAuthed($clientId)) {
            Session::touch($clientId);
        }

        $error  = '';
        $packet = Message::decode($rawMessage, $error);
        if ($packet === null) {
            Monitor::incr('msg_fail');
            Logger::warn('报文解析失败', array('client_id' => $clientId, 'error' => $error));
            self::send($clientId, Message::error(Message::CODE_BAD_PACKET, $error));
            return;
        }

        // 报文级限流（L2）：连接 / 用户双维度，单次 Redis 往返原子判定。
        // 判定为异步，通过后才进入业务分发，杜绝「未判定即执行处理器」。
        self::guardRate($clientId, $packet, function () use ($clientId, $packet) {
            self::dispatch($clientId, $packet);
        });
    }

    /**
     * 业务分发（限流通过后的主链路）
     *
     * @param string $clientId
     * @param array  $packet
     * @return void
     */
    protected static function dispatch($clientId, array $packet)
    {
        // 鉴权拦截：未鉴权连接仅允许白名单指令
        if (Auth::enabled() && !self::isAuthed($clientId) && !Auth::isAllowedBeforeAuth($packet['cmd'])) {
            Monitor::incr('msg_fail');
            Logger::warn('未鉴权连接尝试业务指令，已拒绝', array(
                'client_id' => $clientId,
                'cmd'       => $packet['cmd'],
            ));
            self::reject(
                $clientId,
                Message::CODE_UNAUTHORIZED,
                '请先完成鉴权',
                $packet['seq'],
                $packet['cmd']
            );
            return;
        }

        // 指令分发统一走路由表（一级 cmd），新增指令无需改动本方法
        $handler = Router::command($packet['cmd']);
        if ($handler === null) {
            Monitor::incr('msg_fail');
            Logger::warn('收到未知指令', array('client_id' => $clientId, 'cmd' => $packet['cmd']));
            self::send($clientId, Message::error(
                Message::CODE_UNKNOWN_CMD,
                '',
                $packet['seq'],
                $packet['cmd']
            ));
            return;
        }

        // 处理器异常在此统一兜底：保证单条报文异常不影响连接与进程
        try {
            call_user_func($handler, $clientId, $packet);
        } catch (\Throwable $e) {
            Monitor::incr('msg_fail');
            Logger::exception($e, 'business.route:' . $packet['cmd']);
            self::send($clientId, Message::error(
                Message::CODE_SERVER_ERROR,
                '',
                $packet['seq'],
                $packet['cmd']
            ));
        }
    }

    /* ---------------------------------------------------------------------
     | 报文级限流
     --------------------------------------------------------------------- */

    /**
     * WebSocket 报文限流
     *
     * 维度组合：
     *   连接维度（conn）—— 恒定参与，约束单连接刷报文
     *   用户维度（uid）  —— 已鉴权时叠加，约束同账号多连接的总量
     *   心跳维度（ping） —— 替代 conn 参与，配额更严
     *
     * 两个桶在一次 Redis 往返内原子判定（任一不足即整单拒绝且均不扣减），
     * 避免「连接桶已扣、用户桶拒绝」造成的配额泄漏。
     *
     * @param string   $clientId
     * @param array    $packet
     * @param callable $next 放行后的后续处理
     * @return void
     */
    protected static function guardRate($clientId, array $packet, callable $next)
    {
        if (!RateLimiter::enabled()) {
            call_user_func($next);
            return;
        }

        $isPing = in_array($packet['cmd'], array(Message::CMD_PING, Message::CMD_PONG), true);
        $dim    = $isPing ? RateLimiter::DIM_PING : RateLimiter::DIM_CONN;

        $buckets = array(RateLimiter::bucket($dim, $clientId));

        $uid = self::authedUid($clientId);
        if ($uid !== '') {
            $buckets[] = RateLimiter::bucket(RateLimiter::DIM_UID, $uid);
        }

        RateLimiter::acquire($buckets, 1, function ($allowed) use ($clientId, $packet, $dim, $next) {
            if ($allowed) {
                call_user_func($next);
                return;
            }

            Monitor::incr('msg_fail');
            Monitor::incr('rate_limit_hit');
            Monitor::incr('rate_limit_' . $dim);

            RateLimiter::logReject($dim, $clientId, array(
                'channel' => 'ws',
                'cmd'     => $packet['cmd'],
                'seq'     => $packet['seq'],
            ));

            self::rejectRateLimited($clientId, $packet);
        });
    }

    /**
     * WebSocket 超限处置
     *
     * 默认仅回错误报文、不断开连接：客户端可感知并自行退避，
     * 而断开会在网络抖动时把限流放大成重连风暴。
     *
     * @param string $clientId
     * @param array  $packet
     * @return void
     */
    protected static function rejectRateLimited($clientId, array $packet)
    {
        if (!RateLimiter::shouldNotify()) {
            return;
        }

        if (RateLimiter::shouldClose()) {
            self::closeClient($clientId, Message::CODE_RATE_LIMIT, Message::codeMessage(Message::CODE_RATE_LIMIT));
            return;
        }

        self::send($clientId, Message::error(
            Message::CODE_RATE_LIMIT,
            '',
            $packet['seq'],
            $packet['cmd']
        ));
    }

    /**
     * WebSocket 握手完成
     *
     * @param string $clientId
     * @param array  $data ['get'=>..,'server'=>..,'cookie'=>..]
     * @return void
     */
    public static function onWebSocketConnect($clientId, $data)
    {
        Logger::debug('WebSocket 握手完成', array(
            'client_id' => $clientId,
            'get'       => isset($data['get']) ? $data['get'] : array(),
        ));
        // P1 扩展点：可从握手 URL 的 query 中提取 token，实现「握手即鉴权」以省去一次往返
    }

    /**
     * 连接关闭
     *
     * 会话主体保留在 Redis，供客户端断线重连时恢复（文档 4.3）。
     *
     * @param string $clientId
     * @return void
     */
    public static function onClose($clientId)
    {
        Monitor::incr('conn_close');

        if (isset(self::$authTimers[$clientId])) {
            Timer::del((int)self::$authTimers[$clientId]);
            unset(self::$authTimers[$clientId]);
        }

        $wasAuthed = self::isAuthed($clientId);
        unset(self::$authed[$clientId]);

        if ($wasAuthed) {
            Session::markOffline($clientId);
        }

        Logger::info('客户端连接关闭', array(
            'client_id' => $clientId,
            'authed'    => $wasAuthed ? 1 : 0,
        ));
    }

    /**
     * 进程停止
     *
     * @param mixed $worker
     * @return void
     */
    public static function onWorkerStop($worker)
    {
        Logger::info('BusinessWorker 已退出', array(
            'id'       => isset($worker->id) ? $worker->id : 0,
            'authed'   => count(self::$authed),
        ));
    }

    /* ---------------------------------------------------------------------
     | 指令处理
     --------------------------------------------------------------------- */

    /**
     * 处理鉴权指令
     *
     * 校验链路：本地签名与时效 -> Redis 撤销名单 -> uid/设备绑定 -> 写入会话
     *
     * @param string $clientId
     * @param array  $packet
     * @return void
     */
    protected static function handleAuth($clientId, array $packet)
    {
        if (!Auth::enabled()) {
            self::markAuthed($clientId);
            self::send($clientId, Message::ack($packet['seq'], array('auth' => 'disabled')));
            return;
        }

        $result = Auth::verifyLocal($packet['token']);
        if (!$result['ok']) {
            Monitor::incr('auth_fail');
            Logger::warn('Token 本地校验失败', array(
                'client_id' => $clientId,
                'code'      => $result['code'],
                'msg'       => $result['msg'],
            ));
            self::reject($clientId, $result['code'], $result['msg'], $packet['seq'], $packet['cmd']);
            return;
        }

        $claims   = $result['claims'];
        $uid      = isset($claims['uid']) ? (string)$claims['uid'] : '';
        $deviceId = isset($claims['device_id']) ? (string)$claims['device_id'] : '';

        // 报文中的 device_id 优先（UDP 客户端可能使用动态设备标识）
        if ($packet['device_id'] !== '') {
            $deviceId = $packet['device_id'];
        }

        if ($uid === '') {
            Monitor::incr('auth_fail');
            self::reject($clientId, Message::CODE_AUTH_FAILED, 'Token 缺少 uid', $packet['seq'], $packet['cmd']);
            return;
        }

        $token = $packet['token'];

        // 撤销名单校验
        Auth::isRevoked($token, function ($revoked) use ($clientId, $packet, $uid, $deviceId) {
            if ($revoked) {
                Monitor::incr('auth_fail');
                Logger::warn('Token 已失效或被撤销', array('client_id' => $clientId, 'uid' => $uid));
                self::reject($clientId, Message::CODE_AUTH_FAILED, 'Token 已失效', $packet['seq'], $packet['cmd']);
                return;
            }

            // 设备合法性校验
            Auth::checkDeviceBind($uid, $deviceId, function ($pass, $msg) use ($clientId, $packet, $uid, $deviceId) {
                if (!$pass) {
                    Monitor::incr('auth_fail');
                    Logger::warn('设备绑定校验未通过', array(
                        'client_id' => $clientId,
                        'uid'       => $uid,
                        'device_id' => $deviceId,
                        'msg'       => $msg,
                    ));
                    self::reject($clientId, Message::CODE_AUTH_FAILED, $msg, $packet['seq'], $packet['cmd']);
                    return;
                }

                self::bindSession($clientId, $uid, $deviceId, $packet);
            });
        });
    }

    /**
     * 绑定会话并返回鉴权结果
     *
     * @param string $clientId
     * @param string $uid
     * @param string $deviceId
     * @param array  $packet
     * @return void
     */
    protected static function bindSession($clientId, $uid, $deviceId, array $packet)
    {
        $protocol = self::protocolOf($clientId);

        // 断线重连：按 device_id 找回历史会话
        Session::restore($deviceId, function ($history) use ($clientId, $uid, $deviceId, $protocol, $packet) {
            $reconnected = !empty($history);

            // 单对一定向推送要求设备唯一在线：同设备新连接上线时踢掉旧连接
            if ($reconnected && isset($history['client_id']) && (string)$history['client_id'] !== (string)$clientId) {
                $oldClientId = (string)$history['client_id'];
                Logger::info('同设备重复登录，断开旧连接', array(
                    'device_id' => $deviceId,
                    'old'       => $oldClientId,
                    'new'       => $clientId,
                ));
                self::closeClient($oldClientId);
            }

            Session::bind($clientId, array(
                'uid'       => $uid,
                'device_id' => $deviceId,
            ), $protocol, array(
                'client_ip'   => Context::$client_ip,
                'client_port' => Context::$client_port,
                'connect_at'  => time(),
            ));

            self::markAuthed($clientId, $uid);
            Monitor::incr('auth_success');

            // 绑定 Gateway 原生 uid 路由，使 sendToUid 可用（跨进程由 Register 转发）。
            // UDP 的 client_id 不在 Gateway 连接表内，无法参与该映射，故仅 WebSocket 绑定；
            // 连接关闭时 Gateway 会自行清理 uid 路由表（见 Gateway::onClientClose），
            // 业务侧无需重复调用 unbindUid。
            if ($protocol === Session::PROTOCOL_WS) {
                try {
                    GatewayClient::bindUid($clientId, $uid);
                } catch (\Throwable $e) {
                    Logger::exception($e, 'business.bind_uid:' . $clientId);
                }
            }

            self::send($clientId, Message::ack($packet['seq'], array(
                'uid'         => $uid,
                'device_id'   => $deviceId,
                'protocol'    => $protocol,
                'reconnected' => $reconnected ? 1 : 0,
            )));

            Logger::info('连接鉴权成功', array(
                'client_id'   => $clientId,
                'uid'         => $uid,
                'device_id'   => $deviceId,
                'protocol'    => $protocol,
                'reconnected' => $reconnected ? 1 : 0,
            ));

            // 断线期间缓存的离线消息在鉴权成功后补投（至少一次语义）
            Push::replayOffline($uid, $clientId, function ($count) use ($uid, $clientId) {
                if ($count > 0) {
                    Logger::info('离线消息已补投', array(
                        'uid'       => $uid,
                        'client_id' => $clientId,
                        'count'     => $count,
                    ));
                }
            });
        });
    }

    /**
     * 处理心跳指令
     *
     * @param string $clientId
     * @param array  $packet
     * @return void
     */
    protected static function handlePing($clientId, array $packet)
    {
        if (self::isAuthed($clientId)) {
            Session::touch($clientId);
        }
        self::send($clientId, Message::packet(Message::CMD_PONG, array(), array('seq' => $packet['seq'])));
    }

    /**
     * 处理客户端回执（对 push 下行报文的确认）
     *
     * 推送采用「至少一次」语义，客户端回执仅用于观测投递质量；
     * 报文中的 seq 即服务端下发的 msg_id，可直接与推送日志对齐排查。
     *
     * @param string $clientId
     * @param array  $packet
     * @return void
     */
    protected static function handleClientAck($clientId, array $packet)
    {
        $data  = $packet['data'];
        $msgId = isset($data['msg_id']) ? (string)$data['msg_id'] : (string)$packet['seq'];

        Monitor::incr('push_ack');

        Logger::debug('收到客户端回执', array(
            'client_id' => $clientId,
            'msg_id'    => $msgId,
            'ref'       => isset($packet['ref']) ? (string)$packet['ref'] : '',
        ));
    }

    /**
     * 处理业务数据指令（二级路由入口）
     *
     * data 报文约定：{"cmd":"data","data":{"action":"<动作名>","params":{...}}}
     *
     * 具体分发、鉴权、参数校验与回执全部交由 ActionRunner 完成 ——
     * 它是 WS 与 UDP 两条链路共用的执行器，本方法只负责把身份信息透传下去。
     * 这样做的直接收益：UDP 侧接入业务动作的路径与本方法完全一致，
     * 不需要为「UDP 上报的业务报文」再维护一套并行的分发逻辑。
     *
     * @param string $clientId
     * @param array  $packet
     * @return void
     */
    protected static function handleData($clientId, array $packet)
    {
        ActionRunner::run(
            $clientId,
            $packet,
            self::resolveUid($clientId, $packet),
            isset($packet['device_id']) ? (string)$packet['device_id'] : '',
            self::protocolOf($clientId)
        );
    }

    /**
     * 解析报文归属身份（按通道选择可信来源）
     *
     * 两条通道的身份可信来源不同，不能混用：
     *
     *   WebSocket —— 以鉴权时写入的进程内映射为准。报文里的 uid 由客户端自行
     *   填写且不在签名覆盖范围内，若采信则任意连接都能冒充他人身份。
     *
     *   UDP —— 无连接实体、无鉴权映射，身份取自报文 Token 的载荷。
     *   Message::sign() 的签名基串是 cmd|seq|ts|device_id|token|canonicalize(data)，
     *   uid 不在其中（可被篡改），但 token 参与签名、且 token 载荷本身由服务端
     *   密钥 HMAC 保护并内含 uid —— 因此 Token 是报文内唯一可信的身份来源。
     *
     * Token 不可信时返回空串（不放行），而非回退到报文 uid，避免把伪造身份
     * 当作合法身份使用。仅当鉴权整体关闭、报文确实不带 Token 时才回退。
     *
     * @param string $clientId
     * @param array  $packet
     * @return string 解析失败返回空串
     */
    protected static function resolveUid($clientId, array $packet)
    {
        if (self::protocolOf($clientId) !== Session::PROTOCOL_UDP) {
            return self::authedUid($clientId);
        }

        $token = isset($packet['token']) ? (string)$packet['token'] : '';

        if (Auth::enabled() && $token !== '') {
            $result = Auth::verifyLocal($token);
            if (!empty($result['ok']) && isset($result['claims']['uid'])) {
                return (string)$result['claims']['uid'];
            }

            Logger::debug('UDP 报文 Token 不可信，身份置空', array(
                'client_id' => $clientId,
                'code'      => isset($result['code']) ? (int)$result['code'] : 0,
                'msg'       => isset($result['msg']) ? (string)$result['msg'] : '',
            ));
            return '';
        }

        // 鉴权关闭场景：无 Token 可依，退回报文字段
        return isset($packet['uid']) ? (string)$packet['uid'] : '';
    }

    /* ---------------------------------------------------------------------
     | 指令路由表
     --------------------------------------------------------------------- */

    /**
     * 注册默认指令路由（幂等）
     *
     * 一级（cmd）与二级（data.action）处理器均在此登记。
     * 处理器以闭包形式注册，闭包在 Bootstrap 类作用域内定义，
     * 因此可直接调用受保护的 handleXxx / actionXxx 方法。
     *
     * 扩展方式：在 init() 之后调用 Router::registerCommand / registerAction，
     * 或在业务模块中自行注册，无需修改本类。
     *
     * @return void
     */
    protected static function registerDefaultRoutes()
    {
        if (self::$routesRegistered) {
            return;
        }
        self::$routesRegistered = true;

        // 一级：指令 -> 处理器
        Router::registerCommand(Message::CMD_AUTH, function ($clientId, array $packet) {
            self::handleAuth($clientId, $packet);
        });
        Router::registerCommand(Message::CMD_PING, function ($clientId, array $packet) {
            self::handlePing($clientId, $packet);
        });
        Router::registerCommand(Message::CMD_DATA, function ($clientId, array $packet) {
            self::handleData($clientId, $packet);
        });
        Router::registerCommand(Message::CMD_ACK, function ($clientId, array $packet) {
            self::handleClientAck($clientId, $packet);
        });

        // 二级（data.action）不再在此注册：
        // 业务动作改由 config/actions.php 声明，经 ActionRunner 装载与执行，
        // 业务模块接入新动作无需改动本类。Router 的二级注册能力保留，
        // 供需要绕过参数校验等标准流程的特殊场景使用。
        Logger::info('指令路由表注册完成', array(
            'commands' => Router::commands(),
            'actions'  => ActionRunner::registered(),
        ));
    }

    /* ---------------------------------------------------------------------
     | UDP 队列消费（定时任务）
     --------------------------------------------------------------------- */

    /**
     * 消费 UDP 网关投递的业务队列
     *
     * 采用 Lua 原子取批（LRANGE + LTRIM 在脚本内一次完成）：
     * 早期用 lRange + lTrim 两步实现，二者之间的非原子窗口会让多进程并发消费
     * 重复处理同一批元素。改用原子取批后，该任务可安全地扩展到多 worker 消费。
     *
     * @return void
     */
    public static function consumeUdpQueue()
    {
        $conf = self::$config['udp_queue'];
        if (empty($conf['enable'])) {
            return;
        }

        $batch = max(1, (int)$conf['batch']);

        RedisClient::popBatch($conf['key'], $batch, function ($items) {
            foreach ($items as $raw) {
                try {
                    self::handleUdpJob($raw);
                } catch (\Throwable $e) {
                    Monitor::incr('msg_fail');
                    Logger::exception($e, 'business.udp_job');
                }
            }
        });
    }

    /**
     * 消费定向推送队列（定时任务）
     *
     * 所有推送触发入口（HTTP 接口 / 外部系统直接写队列 / 运维命令）最终都汇聚到这里，
     * 由 Push::dispatch 统一完成目标解析、通道选择、幂等与离线缓存。
     *
     * @return void
     */
    public static function consumePushQueue()
    {
        try {
            Push::consumeQueue();
        } catch (\Throwable $e) {
            Logger::exception($e, 'business.push_queue');
        }
    }

    /**
     * 处理单条 UDP 队列任务
     *
     * 流程：格式校验 -> 报文级限流 -> 业务处理。
     * 超限采用静默丢弃：UDP 允许丢包，回错误报文会形成反射放大
     * （攻击者伪造源地址即可借服务端放大流量）。
     *
     * @param string $raw
     * @return void
     */
    protected static function handleUdpJob($raw)
    {
        $job = json_decode($raw, true);
        if (!is_array($job) || empty($job['packet']) || !is_array($job['packet'])) {
            Monitor::incr('msg_fail');
            Logger::warn('UDP 队列任务格式非法，已丢弃');
            return;
        }

        $clientId = isset($job['client_id']) ? (string)$job['client_id'] : '';
        $packet   = $job['packet'];
        $uid      = isset($packet['uid']) ? (string)$packet['uid'] : '';
        $deviceId = isset($packet['device_id']) ? (string)$packet['device_id'] : '';

        Monitor::incr('msg_in');
        Monitor::incr('udp_msg_in');

        self::guardUdpRate($clientId, $uid, $packet, function () use ($job, $clientId, $uid, $deviceId) {
            self::processUdpJob($job, $clientId, $uid, $deviceId);
        });
    }

    /**
     * UDP 报文限流
     *
     * UDP 无连接实体，连接维度以虚拟 clientId（udp:ip:port）参与；
     * 报文自带 uid，已上报身份的报文叠加用户维度。
     *
     * 注意：网关层已按来源 IP 做过一轮内存桶限流（L1），此处是业务层的
     * 第二道防线，用于约束单终端 / 单账号，与前者的 IP 维度互补。
     *
     * @param string   $clientId
     * @param string   $uid
     * @param array    $packet
     * @param callable $next
     * @return void
     */
    protected static function guardUdpRate($clientId, $uid, array $packet, callable $next)
    {
        if (!RateLimiter::enabled()) {
            call_user_func($next);
            return;
        }

        $cmd    = isset($packet['cmd']) ? (string)$packet['cmd'] : '';
        $isPing = in_array($cmd, array(Message::CMD_PING, Message::CMD_PONG), true);
        $dim    = $isPing ? RateLimiter::DIM_PING : RateLimiter::DIM_CONN;

        $buckets = array(RateLimiter::bucket($dim, $clientId));
        if ($uid !== '') {
            $buckets[] = RateLimiter::bucket(RateLimiter::DIM_UID, $uid);
        }

        RateLimiter::acquire($buckets, 1, function ($allowed) use ($clientId, $uid, $cmd, $dim, $next) {
            if ($allowed) {
                call_user_func($next);
                return;
            }

            Monitor::incr('msg_fail');
            Monitor::incr('rate_limit_hit');
            Monitor::incr('rate_limit_' . $dim);

            RateLimiter::logReject($dim, $clientId, array(
                'channel' => 'udp',
                'cmd'     => $cmd,
                'uid'     => $uid,
            ));
            // 静默丢弃：不回错误报文、不断开连接
        });
    }

    /**
     * UDP 业务处理（限流通过后）
     *
     * @param array  $job
     * @param string $clientId
     * @param string $uid
     * @param string $deviceId
     * @return void
     */
    protected static function processUdpJob(array $job, $clientId, $uid, $deviceId)
    {
        // 应用层会话识别：UDP 以来源地址 + 报文身份建立会话。
        // 先用 EXISTS 探测会话是否已存在 —— UDP 无连接实体，只有在报文到达时
        // 才能判断「会话是否重建」，而该时机正是离线消息补投的触发点
        // （与 WebSocket 在鉴权成功后补投的语义对齐）。
        if ($deviceId !== '' || $uid !== '') {
            Session::exists($clientId, function ($existed) use ($clientId, $uid, $deviceId, $job) {
                Session::bind($clientId, array(
                    'uid'       => $uid,
                    'device_id' => $deviceId,
                ), Session::PROTOCOL_UDP, array(
                    'client_ip'   => isset($job['remote_ip']) ? (string)$job['remote_ip'] : '',
                    'client_port' => isset($job['remote_port']) ? (int)$job['remote_port'] : 0,
                    'connect_at'  => time(),
                ));

                // 会话此前已被回收/过期（EXISTS=0）说明客户端刚刚恢复连接，补投离线消息；
                // 会话存在期间不会重复触发，判定天然幂等。
                if (!$existed && $uid !== '') {
                    Push::replayOffline($uid, $clientId, function ($count) use ($uid, $clientId) {
                        if ($count > 0) {
                            Logger::info('UDP 离线消息已补投', array(
                                'uid'       => $uid,
                                'client_id' => $clientId,
                                'count'     => $count,
                            ));
                        }
                    });
                }
            });
        }

        // 业务分发：与 WebSocket 共用同一张指令路由表，业务动作的声明式清单
        // （config/actions.php）在两条链路上完全一致，不存在「WS 能跑、UDP 跑不通」。
        //
        // 但只放行真正需要业务层介入的两类指令：
        //   data —— 业务动作
        //   ack  —— 客户端对下行推送的确认
        // 其余指令在此不重复处理：auth 所需的会话绑定已在上方完成，
        // ping 已由 UDP 网关在收包时即时回执，再走一遍会造成重复回执与重复补投。
        $packet = isset($job['packet']) && is_array($job['packet']) ? $job['packet'] : array();
        $cmd    = isset($packet['cmd']) ? (string)$packet['cmd'] : '';

        if ($cmd !== Message::CMD_DATA && $cmd !== Message::CMD_ACK) {
            Logger::debug('UDP 报文无需业务层处理', array(
                'client_id' => $clientId,
                'cmd'       => $cmd,
                'uid'       => $uid,
                'device_id' => $deviceId,
            ));
            return;
        }

        $handler = Router::command($cmd);
        if ($handler === null) {
            Monitor::incr('msg_fail');
            Logger::warn('UDP 报文指令未注册，已丢弃', array(
                'client_id' => $clientId,
                'cmd'       => $cmd,
            ));
            return;
        }

        // 处理器异常统一兜底：单条 UDP 报文异常不得影响进程与后续队列消费
        try {
            call_user_func($handler, $clientId, $packet);
        } catch (\Throwable $e) {
            Monitor::incr('msg_fail');
            Logger::exception($e, 'business.udp.route:' . $cmd);
        }
    }

    /* ---------------------------------------------------------------------
     | 内部辅助
     --------------------------------------------------------------------- */

    /**
     * 向客户端回执报文（供路由处理器调用）
     *
     * 与内部 send() 的区别：本方法为公开 API，允许在外部模块注册
     * Router 处理器时复用统一的回执通道（含指标与异常兜底）。
     *
     * @param string $clientId
     * @param array  $packet 已构造的报文数组
     * @return void
     */
    public static function respond($clientId, array $packet)
    {
        self::send($clientId, $packet);
    }

    /**
     * 向客户端回执错误报文（供路由处理器调用）
     *
     * @param string $clientId
     * @param int    $code
     * @param string $msg
     * @param string $seq
     * @param string $ref
     * @return void
     */
    public static function respondError($clientId, $code, $msg = '', $seq = '', $ref = '')
    {
        self::send($clientId, Message::error($code, $msg, $seq, $ref));
    }

    /**
     * 下发报文
     *
     * 传入已序列化的 JSON 字符串，由 Gateway 侧协议层完成帧封装。
     *
     * @param string $clientId
     * @param array  $packet
     * @return void
     */
    protected static function send($clientId, array $packet)
    {
        Monitor::incr('msg_out');
        try {
            // UDP 的 clientId 形如 udp:{ip}:{port}，不在 Gateway 连接表内，
            // sendToClient 对其静默无效。统一改走网关出站队列，由 UDP 网关
            // 进程 sendto —— 这样本方法的调用方（含既有 cmd 处理器）无需
            // 感知协议差异，双通道自动兼容。
            if (self::protocolOf($clientId) === Session::PROTOCOL_UDP) {
                Push::sendToUdpClient($clientId, $packet);
                return;
            }

            GatewayClient::sendToClient($clientId, Message::encode($packet));
        } catch (\Throwable $e) {
            Monitor::incr('msg_fail');
            Logger::exception($e, 'business.send:' . $clientId);
        }
    }

    /**
     * 主动断开连接
     *
     * 传入 $code 时，错误报文会作为断开前的附带消息由 Gateway 一并下发，
     * 避免「先 send 再 close」两条独立内部消息带来的时序风险。
     *
     * @param string $clientId
     * @param int    $code
     * @param string $msg
     * @return void
     */
    protected static function closeClient($clientId, $code = 0, $msg = '')
    {
        // UDP 无连接实体，不存在「断开」动作，也不会进入 Gateway 连接表。
        // 其会话回收依赖心跳超时巡检（Session::checkHeartbeatTimeout），
        // 此处仅记录并返回，避免对 UDP 下发无效的关闭指令。
        if (self::protocolOf($clientId) === Session::PROTOCOL_UDP) {
            Logger::debug('UDP 会话无连接可关闭，改由心跳超时回收', array(
                'client_id' => $clientId,
                'code'      => $code,
            ));
            return;
        }

        try {
            if ($code > 0) {
                GatewayClient::closeClient($clientId, Message::encode(Message::error($code, $msg)));
                return;
            }
            GatewayClient::closeClient($clientId);
        } catch (\Throwable $e) {
            Logger::exception($e, 'business.close:' . $clientId);
        }
    }

    /**
     * 下发错误报文，并按配置决定是否断开
     *
     * @param string $clientId
     * @param int    $code
     * @param string $msg
     * @param string $seq
     * @param string $ref
     * @return void
     */
    protected static function reject($clientId, $code, $msg, $seq = '', $ref = '')
    {
        if (Auth::shouldCloseOnFail()) {
            self::closeClient($clientId, $code, $msg);
            return;
        }
        self::send($clientId, Message::error($code, $msg, $seq, $ref));
    }

    /**
     * 标记连接已鉴权，并清理鉴权超时定时器
     *
     * @param string $clientId
     * @param string $uid 鉴权功能关闭时可为空串
     * @return void
     */
    protected static function markAuthed($clientId, $uid = '')
    {
        self::$authed[$clientId] = (string)$uid;
        if (isset(self::$authTimers[$clientId])) {
            Timer::del((int)self::$authTimers[$clientId]);
            unset(self::$authTimers[$clientId]);
        }
    }

    /**
     * 连接是否已鉴权
     *
     * @param string $clientId
     * @return bool
     */
    protected static function isAuthed($clientId)
    {
        return isset(self::$authed[$clientId]);
    }

    /**
     * 已鉴权连接的 uid（未鉴权返回空串）
     *
     * @param string $clientId
     * @return string
     */
    protected static function authedUid($clientId)
    {
        return isset(self::$authed[$clientId]) ? (string)self::$authed[$clientId] : '';
    }

    /**
     * 推断连接协议类型
     *
     * @param string $clientId
     * @return string
     */
    protected static function protocolOf($clientId)
    {
        return strpos((string)$clientId, 'udp:') === 0 ? Session::PROTOCOL_UDP : Session::PROTOCOL_WS;
    }

    /**
     * 内部通信密钥（Register <-> Gateway <-> BusinessWorker 三方必须一致）
     *
     * 优先取 app.internal.secret，未配置时回退 app.auth.secret。
     *
     * @return string
     */
    protected static function internalSecret()
    {
        $secret = isset(self::$appConfig['internal']['secret']) ? (string)self::$appConfig['internal']['secret'] : '';
        if ($secret === '') {
            $secret = isset(self::$appConfig['auth']['secret']) ? (string)self::$appConfig['auth']['secret'] : '';
        }
        return $secret;
    }

    /**
     * 判断当前启动角色是否包含业务进程
     *
     * @param string $role
     * @return bool
     */
    protected static function roleEnabled($role)
    {
        $current = defined('APP_ROLE') ? APP_ROLE : 'all';
        return $current === 'all' || $current === $role;
    }

    /**
     * 进程数解析（Windows 下强制 1，原因见基建说明）
     *
     * @param mixed $configured
     * @return int
     */
    protected static function resolveCount($configured)
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return 1;
        }
        return max(1, (int)$configured);
    }
}
