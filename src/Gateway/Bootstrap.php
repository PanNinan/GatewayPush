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
 * 兼容 PHP 8.0 ~ 8.5
 */

namespace GatewayPush\Gateway;

use GatewayWorker\Gateway as WsGateway;
use GatewayWorker\Register as RegisterWorker;
use GatewayPush\Business\Message;
use GatewayPush\Common\Logger;
use GatewayPush\Common\RedisClient;
use Workerman\Connection\ConnectionInterface;
use Workerman\Worker;

class Bootstrap
{
    /**
     * gateway.php 配置
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
            Logger::info('Register 注册中心已启动', array(
                'listen' => $worker->getSocketName(),
            ));
        };
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

        $context = array();
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
            // UDP 网关需要写业务队列，此处必须初始化 Redis 客户端，
            // 否则 onUdpMessage 会在 rPush 处抛出「未初始化」异常并吞掉 ack 回执
            RedisClient::init(self::$appConfig['redis']);

            Logger::info('UDP 网关已启动', array(
                'listen'  => $worker->getSocketName(),
                'queue'   => !empty($conf['queue']['enable']) ? $conf['queue']['key'] : 'disabled',
                'max_packet_size' => UdpProtocol::$maxPacketSize,
            ));
        };

        $udp->onWorkerStop = function ($worker) {
            Logger::info('UDP 网关正在停止，释放连接资源', array('id' => $worker->id));
            RedisClient::closeAll();
        };
    }

    /**
     * UDP 报文处理入口
     *
     * 仅执行协议层职责：签名与时效校验 -> 投递 Redis 队列 -> 立即回执。
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
            $queueConf = isset(self::$config['udp']['queue']) ? self::$config['udp']['queue'] : array();
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
