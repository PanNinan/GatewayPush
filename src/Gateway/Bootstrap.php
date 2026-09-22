<?php
/**
 * Gateway 网关层启动入口
 *
 * 职责边界（对应文档 3.1.1）：只做网络调度，不承载任何业务逻辑。
 *   - Register 注册中心：Gateway 与 BusinessWorker 的地址发现
 *   - WebSocket 网关：长连接接入、原生心跳、死连接清理、报文转发
 *   - UDP 网关：协议解析 + 签名校验（纯本地计算），业务请求经 Redis 队列解耦投递
 *
 * 启动角色由常量 APP_ROLE 控制（见 start.php）：
 *   all / register / gateway / udp / business
 * Windows 下 workerman 单启动文件仅支持 1 个 Worker 实例，需按角色分别启动。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Gateway;

use GatewayWorker\Gateway as WsGateway;
use GatewayWorker\Register as RegisterWorker;
use GatewayPush\Business\Message;
use GatewayPush\Business\Monitor;
use GatewayPush\Common\Logger;
use GatewayPush\Common\RateLimiter;
use GatewayPush\Common\RedisClient;
use GatewayPush\Common\WorkerEvents;
use Workerman\Connection\ConnectionInterface;
use Workerman\Timer;
use Workerman\Worker;

class Bootstrap
{
    /**
     * gateway.php 配置
     *
     * @var array
     */
    protected static $config = [];

    /**
     * app.php 配置
     *
     * @var array
     */
    protected static $appConfig = [];

    /**
     * 初始化网关层 Worker
     *
     * @param array $gatewayConfig config/gateway.php
     * @param array $appConfig     config/app.php
     * @return void
     */
    public static function init(array $gatewayConfig, array $appConfig)
    {
        self::$config    = $gatewayConfig;
        self::$appConfig = $appConfig;

        UdpProtocol::$maxPacketSize = (int)(isset($gatewayConfig['udp']['max_packet_size'])
            ? $gatewayConfig['udp']['max_packet_size'] : 8192);

        if (self::roleEnabled('register')) {
            self::initRegister();
        }
        if (self::roleEnabled('gateway')) {
            self::initWebSocketGateway();
        }
        if (self::roleEnabled('udp')) {
            self::initUdpGateway();
        }
    }

    /* ---------------------------------------------------------------------
     | Register 注册中心
     --------------------------------------------------------------------- */

    /**
     * 启动 Register 注册中心
     *
     * @return void
     */
    protected static function initRegister()
    {
        $conf = self::$config['register'];
        if (empty($conf['enable'])) {
            return;
        }

        $register = new RegisterWorker('text://' . $conf['listen']);
        $register->name      = $conf['name'];
        $register->count     = 1;   // 注册中心必须单进程
        // Register 会校验 secretKey，与 Gateway / BusinessWorker 不一致时直接拒绝注册
        $register->secretKey = self::buildSecretKey();

        $register->onWorkerStart = function ($worker) {
            Logger::useChannel('register');

            Logger::info('Register 注册中心已启动', array(
                'listen' => $worker->getSocketName(),
            ));
        };

        // 连接级异常与背压观测（注册中心为内部 TCP，无背压压力，仅绑错误事件）
        WorkerEvents::bind($register, $conf['name'], array('buffer' => false));
    }

    /* ---------------------------------------------------------------------
     | WebSocket 网关
     --------------------------------------------------------------------- */

    /**
     * 启动 WebSocket 网关
     *
     * @return void
     */
    protected static function initWebSocketGateway()
    {
        $conf = self::$config['websocket'];
        if (empty($conf['enable'])) {
            return;
        }

        $context = [];
        $sslOn   = !empty($conf['ssl']['enable']);
        if ($sslOn) {
            $context['ssl'] = array(
                'local_cert'        => $conf['ssl']['local_cert'],
                'local_pk'          => $conf['ssl']['local_pk'],
                'verify_peer'       => (bool)$conf['ssl']['verify_peer'],
                'allow_self_signed' => true,
            );
        }

        $gateway = new WsGateway($conf['listen'], $context);
        $gateway->name       = $conf['name'];
        $gateway->count      = self::resolveCount($conf['count']);
        $gateway->lanIp      = $conf['lan_ip'];
        $gateway->startPort  = (int)$conf['start_port'];
        $gateway->secretKey  = self::buildSecretKey();
        $gateway->registerAddress = self::$config['register']['listen'];

        if ($sslOn) {
            $gateway->transport = 'ssl';
        }

        // 心跳：Gateway 原生实现，无需业务层参与
        // ping 定时器周期为 pingInterval/2（当 pingNotResponseLimit > 0 时），
        // 断开条件为 pingNotResponseCount >= pingNotResponseLimit * 2，
        // 因此实际容忍的无上行数据时长约为 pingInterval * pingNotResponseLimit。
        $heartbeat = self::$config['heartbeat'];
        $gateway->pingInterval           = (int)$heartbeat['gateway_ping_interval'];
        $gateway->pingData               = (string)$heartbeat['gateway_ping_data'];
        $gateway->pingNotResponseLimit   = (int)$heartbeat['gateway_ping_not_response_limit'];

        $gateway->onWorkerStart = function ($worker) use ($sslOn) {
            Logger::useChannel('gateway');

            Logger::info('WebSocket 网关已启动', array(
                'listen'          => $worker->getSocketName(),
                'lan_ip'          => $worker->lanIp,
                'internal_port'   => $worker->lanPort,
                'ping_interval'   => $worker->pingInterval,
                'ssl'             => $sslOn ? 'on' : 'off',
            ));
        };

        $gateway->onBusinessWorkerConnected = function ($connection) {
            Logger::info('BusinessWorker 已接入网关', array(
                'worker_key' => isset($connection->key) ? $connection->key : '',
            ));
        };

        $gateway->onBusinessWorkerClose = function ($connection) {
            Logger::warn('BusinessWorker 与网关连接断开', array(
                'worker_key' => isset($connection->key) ? $connection->key : '',
            ));
        };

        // 长连接网关必须观测背压：客户端消费慢会顶满发送缓冲并触发丢包
        WorkerEvents::bind($gateway, $conf['name']);
    }

    /* ---------------------------------------------------------------------
     | UDP 网关
     --------------------------------------------------------------------- */

    /**
     * 启动 UDP 网关
     *
     * @return void
     */
    protected static function initUdpGateway()
    {
        $conf = self::$config['udp'];
        if (empty($conf['enable'])) {
            return;
        }

        $udp = new Worker($conf['listen']);
        $udp->name     = $conf['name'];
        $udp->count    = self::resolveCount($conf['count']);
        $udp->protocol = UdpProtocol::class;
        $udp->onMessage = array(self::class, 'onUdpMessage');

        $udp->onWorkerStart = function ($worker) use ($conf) {
            Logger::useChannel('udp');

            // UDP 网关需要写业务队列、读推送出站队列，此处必须初始化 Redis 客户端，
            // 否则 onUdpMessage 会在 rPush 处抛出「未初始化」异常并吞掉 ack 回执
            RedisClient::init(self::$appConfig['redis']);
            Monitor::init(self::$appConfig['monitor']);
            RateLimiter::init(isset(self::$appConfig['rate_limit']) ? self::$appConfig['rate_limit'] : array());

            $out       = isset($conf['out_queue']) ? $conf['out_queue'] : [];
            $outOn     = !empty($out['enable']);
            $outPeriod = $outOn ? max(0.01, (float)$out['interval']) : 0;

            // 出站队列消费：业务进程写入的定向推送任务由此进程 sendto 发出。
            // UDP 的 client_id 不在 Gateway 连接表内，无法复用 sendToClient 通道。
            // 多进程（UDP_COUNT > 1）并发消费安全，取批由 Lua 保证原子性。
            if ($outOn) {
                Timer::add($outPeriod, function () use ($worker, $out) {
                    self::consumeUdpOutQueue($worker, $out);
                }, [], true);
            }

            // 指标上报：网关进程只产出站维度指标，跳过在线数采集（其归属业务进程）
            $monitorInterval = (float)(self::$appConfig['monitor']['interval'] ?? 60);
            if (!empty(self::$appConfig['monitor']['enable']) && $monitorInterval > 0) {
                Timer::add($monitorInterval, function () {
                    Monitor::report(false);
                });
            }

            Logger::info('UDP 网关已启动', array(
                'listen'     => $worker->getSocketName(),
                'queue'      => !empty($conf['queue']['enable']) ? $conf['queue']['key'] : 'disabled',
                'out_queue'  => $outOn ? $out['key'] : 'disabled',
                'out_period' => $outOn ? $outPeriod : 0,
                'max_packet_size' => UdpProtocol::$maxPacketSize,
            ));
        };

        $udp->onWorkerStop = function ($worker) {
            Logger::info('UDP 网关正在停止，释放连接资源', array('id' => $worker->id));
            RedisClient::closeAll();
        };

        // UDP 为无连接协议，不存在 TCP 发送缓冲背压，仅绑错误事件
        WorkerEvents::bind($udp, $conf['name'], array('buffer' => false));
    }

    /**
     * UDP 报文处理入口
     *
     * 仅执行协议层职责：报文级限流 -> 签名与时效校验 -> 投递 Redis 队列 -> 立即回执。
     * 业务处理由 BusinessWorker 异步消费队列完成，网关不感知业务逻辑。
     *
     * @param ConnectionInterface $connection
     * @param array               $packet 已经过 UdpProtocol::decode 归一化
     * @return void
     */
    public static function onUdpMessage(ConnectionInterface $connection, $packet)
    {
        try {
            if (!is_array($packet)) {
                return;
            }

            // 0. 报文级限流（L1）：每来源 IP 的进程内内存令牌桶。
            //    置于验签之前 —— 洪水场景下限流器绝不能自身发起 Redis IO；
            //    超限静默丢弃，不回错误报文以免形成反射放大。
            $ip = (string)$connection->getRemoteIp();
            if (!RateLimiter::checkMemory(RateLimiter::DIM_IP, $ip)) {
                Monitor::incr('msg_fail');
                Monitor::incr('rate_limit_hit');
                Monitor::incr('rate_limit_ip');
                RateLimiter::logReject(RateLimiter::DIM_IP, $ip, array(
                    'channel'   => 'udp-gateway',
                    'device_id' => isset($packet['device_id']) ? (string)$packet['device_id'] : '',
                ));
                return;
            }

            // 1. 签名与时效校验（纯本地计算）
            $verify = Message::verify($packet, self::$appConfig['auth']);
            if (!$verify['ok']) {
                Logger::warn('UDP 报文校验未通过', array(
                    'remote'    => $connection->getRemoteIp() . ':' . $connection->getRemotePort(),
                    'cmd'       => $packet['cmd'],
                    'device_id' => $packet['device_id'],
                    'code'      => $verify['code'],
                    'msg'       => $verify['msg'],
                ));
                $connection->send(Message::error($verify['code'], $verify['msg'], $packet['seq'], $packet['cmd']));
                return;
            }

            // 2. 投递业务队列，交由 BusinessWorker 处理
            $queueConf = isset(self::$config['udp']['queue']) ? self::$config['udp']['queue'] : [];
            if (!empty($queueConf['enable'])) {
                self::pushToBusinessQueue($connection, $packet, $queueConf);
            }

            // 3. 立即回执：UDP 允许丢包，业务处理结果由服务端另行推送
            $connection->send(Message::ack($packet['seq']));
        } catch (\Throwable $e) {
            Logger::exception($e, 'gateway.udp.on_message');
        }
    }

    /**
     * 将 UDP 业务请求写入 Redis 队列
     *
     * @param ConnectionInterface $connection
     * @param array               $packet
     * @param array               $queueConf
     * @return void
     */
    protected static function pushToBusinessQueue(ConnectionInterface $connection, array $packet, array $queueConf)
    {
        $job = json_encode(array(
            'client_id'   => self::udpClientId($connection),
            'protocol'    => 'udp',
            'remote_ip'   => $connection->getRemoteIp(),
            'remote_port' => $connection->getRemotePort(),
            'packet'      => $packet,
            'recv_at'     => microtime(true),
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($job === false) {
            Logger::error('UDP 队列任务序列化失败', array('cmd' => $packet['cmd']));
            return;
        }

        $maxLen = (int)(isset($queueConf['max_len']) ? $queueConf['max_len'] : 0);
        $queueKey = $queueConf['key'];

        RedisClient::rPush($queueKey, $job, function ($result) use ($maxLen, $queueKey) {
            if (!is_int($result)) {
                return;
            }
            if ($maxLen > 0 && $result > $maxLen) {
                Logger::warn('UDP 队列积压超限，业务消费能力不足', array(
                    'queue'  => $queueKey,
                    'length' => $result,
                    'max'    => $maxLen,
                ));
            }
        });
    }

    /**
     * 构造 UDP 虚拟 client_id
     *
     * UDP 无连接，以「来源地址」作为应用层会话标识；
     * 真实身份（uid / device_id）由报文内 Token 解析后写入会话。
     *
     * @param ConnectionInterface $connection
     * @return string
     */
    public static function udpClientId(ConnectionInterface $connection)
    {
        return 'udp:' . $connection->getRemoteIp() . ':' . $connection->getRemotePort();
    }

    /* ---------------------------------------------------------------------
     | UDP 出站（服务端定向推送）
     --------------------------------------------------------------------- */

    /**
     * 消费 UDP 出站队列并发送
     *
     * 通道设计说明：
     *   UDP 客户端的 client_id 形如 udp:ip:port，仅存在于本进程的 socket 上下文，
     *   不在 GatewayWorker 的连接表内，因此业务进程的 Gateway::sendToClient 对其无效。
     *   出站改由「业务进程写队列 -> 网关进程 sendto」闭环，与入站的解耦方式对称。
     *
     * @param Worker $worker
     * @param array  $conf gateway.udp.out_queue
     * @return void
     */
    public static function consumeUdpOutQueue($worker, array $conf)
    {
        try {
            $key   = $conf['key'];
            $batch = max(1, (int)(isset($conf['batch']) ? $conf['batch'] : 200));

            RedisClient::popBatch($key, $batch, function ($items) use ($worker) {
                foreach ($items as $raw) {
                    $task = json_decode($raw, true);
                    if (!is_array($task) || empty($task['client_id']) || empty($task['frame'])) {
                        Monitor::incr('udp_out_fail');
                        Logger::warn('UDP 出站任务格式非法，已丢弃', array('raw' => substr((string)$raw, 0, 200)));
                        continue;
                    }
                    self::sendUdp($worker, (string)$task['client_id'], (string)$task['frame'], $task);
                }
            });
        } catch (\Throwable $e) {
            Logger::exception($e, 'gateway.udp.out_queue');
        }
    }

    /**
     * 向指定 UDP 地址发送报文
     *
     * 直接复用网关自身的 UDP socket（unconnected），以 stream_socket_sendto 指定目标地址。
     * 相比每次新建 AsyncUdpConnection，此方式无建连竞态、无额外 fd 开销，
     * 且与 UdpConnection::send() 的底层实现路径完全一致。
     *
     * @param Worker $worker
     * @param string $clientId
     * @param string $frame
     * @param array  $task
     * @return void
     */
    protected static function sendUdp($worker, $clientId, $frame, array $task = array())
    {
        $address = self::parseUdpAddress($clientId);
        if ($address === '') {
            Monitor::incr('udp_out_fail');
            Logger::warn('UDP 出站目标地址无法解析', array('client_id' => $clientId));
            return;
        }

        // workerman 5.x 将 Worker::getSocket() 重命名为 getMainSocket()，
        // 4.x 及以下仍为 getSocket()，此处按版本择优取值，避免版本升级后静默失效。
        if (method_exists($worker, 'getMainSocket')) {
            $socket = $worker->getMainSocket();
        } elseif (method_exists($worker, 'getSocket')) {
            $socket = $worker->getSocket();
        } else {
            $socket = null;
        }

        if (!$socket) {
            Monitor::incr('udp_out_fail');
            Logger::error('UDP 网关 socket 不可用，出站报文丢弃', array('client_id' => $clientId));
            return;
        }

        $written = @stream_socket_sendto($socket, $frame, 0, $address);
        if ($written === false || $written !== strlen($frame)) {
            Monitor::incr('udp_out_fail');
            Logger::error('UDP 出站发送失败', array(
                'client_id' => $clientId,
                'address'   => $address,
                'size'      => strlen($frame),
                'written'   => $written === false ? -1 : $written,
            ));
            return;
        }

        Monitor::incr('udp_out');
        Logger::info('UDP 推送已下发', array(
            'client_id' => $clientId,
            'address'   => $address,
            'uid'       => isset($task['uid']) ? (string)$task['uid'] : '',
            'msg_id'    => isset($task['msg_id']) ? (string)$task['msg_id'] : '',
            'size'      => strlen($frame),
        ));
    }

    /**
     * 解析 UDP client_id 为可发送地址
     *
     * 输入形如 udp:127.0.0.1:52344 或 udp:[::1]:52344
     * 输出 stream_socket_sendto 所需的 ip:port（IPv6 需带方括号）
     *
     * @param string $clientId
     * @return string 解析失败返回空串
     */
    protected static function parseUdpAddress($clientId)
    {
        if (! str_starts_with((string)$clientId, 'udp:')) {
            return '';
        }
        $rest = substr((string)$clientId, 4);
        if ($rest === '') {
            return '';
        }

        // 已带方括号的 IPv6 形式：udp:[::1]:52344
        if ($rest[0] === '[') {
            $end = strpos($rest, ']');
            if ($end === false) {
                return '';
            }
            $ip   = substr($rest, 1, $end - 1);
            $port = (int)substr($rest, $end + 2);
            return ($ip !== '' && $port > 0) ? '[' . $ip . ']:' . $port : '';
        }

        $pos = strrpos($rest, ':');
        if ($pos === false) {
            return '';
        }
        $ip   = substr($rest, 0, $pos);
        $port = (int)substr($rest, $pos + 1);
        if ($ip === '' || $port <= 0) {
            return '';
        }

        // 裸 IPv6（含多个冒号）需补方括号，否则 sendto 会解析失败
        return str_contains($ip, ':') ? '[' . $ip . ']:' . $port : $ip . ':' . $port;
    }

    /* ---------------------------------------------------------------------
     | 内部辅助
     --------------------------------------------------------------------- */

    /**
     * 判断当前启动角色是否包含指定组件
     *
     * @param string $role register / gateway / udp / business
     * @return bool
     */
    protected static function roleEnabled($role)
    {
        $current = defined('APP_ROLE') ? APP_ROLE : 'all';
        return $current === 'all' || $current === $role;
    }

    /**
     * 进程数解析
     *
     * Windows 下 workerman 通过 proc_open 复制启动文件实现多进程，
     * 且要求单个启动文件只初始化 1 个 Worker 实例，因此统一降级为 1。
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

    /**
     * 内部通信密钥（Register <-> Gateway <-> BusinessWorker 三方必须一致）
     *
     * 优先取 app.internal.secret；未配置时回退到 app.auth.secret，
     * 生产环境建议独立配置，避免业务鉴权密钥扩散到内部通信用途。
     *
     * @return string
     */
    protected static function buildSecretKey()
    {
        $secret = isset(self::$appConfig['internal']['secret']) ? (string)self::$appConfig['internal']['secret'] : '';
        if ($secret === '') {
            $secret = isset(self::$appConfig['auth']['secret']) ? (string)self::$appConfig['auth']['secret'] : '';
        }
        return $secret;
    }
}
