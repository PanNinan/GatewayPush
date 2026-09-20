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
use GatewayPush\Common\RedisClient;
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
     * 已通过鉴权的 clientId 集合（进程内，随连接生命周期）
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

    /* ---------------------------------------------------------------------
     | 初始化
     --------------------------------------------------------------------- */

    /**
     * 创建并配置 BusinessWorker
     *
     * @param array $businessConfig config/business.php
     * @param array $appConfig      config/app.php
     * @param array $gatewayConfig  config/gateway.php（仅取 UDP 出站队列配置）
     * @return void
     */
    public static function init(array $businessConfig, array $appConfig, array $gatewayConfig = array())
    {
        if (!self::roleEnabled('business')) {
            return;
        }

        self::$config    = $businessConfig;
        self::$appConfig = $appConfig;

        Auth::init($appConfig['auth']);
        Session::init($appConfig['session']);
        Monitor::init($appConfig['monitor']);

        // UDP 出站队列 key 与网关进程同源（gateway.udp.out_queue），避免两套真源
        Push::init(
            $appConfig['push'],
            $businessConfig['push_queue'],
            isset($gatewayConfig['udp']['out_queue']) ? $gatewayConfig['udp']['out_queue'] : array()
        );

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

        switch ($packet['cmd']) {
            case Message::CMD_AUTH:
                self::handleAuth($clientId, $packet);
                break;

            case Message::CMD_PING:
                self::handlePing($clientId, $packet);
                break;

            case Message::CMD_DATA:
                self::handleData($clientId, $packet);
                break;

            case Message::CMD_ACK:
                self::handleClientAck($clientId, $packet);
                break;

            default:
                Monitor::incr('msg_fail');
                Logger::warn('收到未知指令', array('client_id' => $clientId, 'cmd' => $packet['cmd']));
                self::send($clientId, Message::error(
                    Message::CODE_UNKNOWN_CMD,
                    '',
                    $packet['seq'],
                    $packet['cmd']
                ));
        }
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

            self::markAuthed($clientId);
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
     * 处理业务数据指令
     *
     * P0/P1 阶段仅完成链路验证与心跳刷新，不承载具体业务。
     * 扩展点：在此接入业务数据落库、按规则回推（Push::direct）等逻辑。
     *
     * @param string $clientId
     * @param array  $packet
     * @return void
     */
    protected static function handleData($clientId, array $packet)
    {
        Logger::debug('收到业务数据（暂不做业务处理）', array(
            'client_id' => $clientId,
            'seq'       => $packet['seq'],
            'keys'      => array_keys($packet['data']),
        ));
        self::send($clientId, Message::ack($packet['seq'], array('accepted' => 1)));
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

        // 应用层会话识别：UDP 以来源地址 + 报文身份建立会话
        if ($deviceId !== '' || $uid !== '') {
            Session::bind($clientId, array(
                'uid'       => $uid,
                'device_id' => $deviceId,
            ), Session::PROTOCOL_UDP, array(
                'client_ip'   => isset($job['remote_ip']) ? (string)$job['remote_ip'] : '',
                'client_port' => isset($job['remote_port']) ? (int)$job['remote_port'] : 0,
                'connect_at'  => time(),
            ));
        }

        // P1 扩展点：在此接入 UDP 业务处理（上报数据落库、定向推送等）
        Logger::debug('UDP 业务任务已消费', array(
            'client_id' => $clientId,
            'cmd'       => isset($packet['cmd']) ? $packet['cmd'] : '',
            'uid'       => $uid,
            'device_id' => $deviceId,
        ));
    }

    /* ---------------------------------------------------------------------
     | 内部辅助
     --------------------------------------------------------------------- */

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
     * @return void
     */
    protected static function markAuthed($clientId)
    {
        self::$authed[$clientId] = true;
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
