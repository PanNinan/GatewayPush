<?php
/**
 * 报文级限流
 *
 * ---------------------------------------------------------------------
 * 定位与分层
 * ---------------------------------------------------------------------
 * 限流按「谁能承担多少成本」分两层，两层使用不同实现，不可互换：
 *
 *   L1 网关防护（内存桶，checkMemory）
 *       位置：UDP 网关 onUdpMessage，验签前。
 *       维度：每来源 IP。
 *       理由：洪水场景下限流器自身去打 Redis 等于放大攻击面，且异步回调会
 *             打乱 UDP 的同步 ack 时序。故必须零 IO、纯进程内计算。
 *
 *   L2 业务限流（Redis 桶，acquire）
 *       位置：BusinessWorker 的 onMessage(WS) 与 handleUdpJob(UDP)。
 *       维度：每连接 clientId + 每用户 uid。
 *       理由：需要跨进程 / 跨机共享配额，只有 Redis 能做到；且此处已有完备的
 *             异步处理链路，不引入额外的时序约束。
 *
 * WebSocket 不做 L1：GatewayWorker\Gateway 的 onMessage 被框架内部接管用于
 * 转发 BusinessWorker，外部覆盖会破坏转发链路；且 TCP 已有内核 + workerman
 * 两级背压，洪水风险远低于 UDP。
 *
 * ---------------------------------------------------------------------
 * 算法
 * ---------------------------------------------------------------------
 * 令牌桶。相对固定窗口的优势：支持合法突发流量，且不存在窗口边界
 * 「双倍放行」缺陷（固定窗口在 t=59s 与 t=61s 各放行满额）。
 *
 * Redis 侧一次判定可包含多个桶（见 RedisClient::LUA_TOKEN_BUCKET），
 * 任一桶不足即整单拒绝且不扣减任何桶，避免配额泄漏。
 *
 * ---------------------------------------------------------------------
 * 故障策略
 * ---------------------------------------------------------------------
 * Redis 不可用时 **fail-open 放行**并告警。限流器故障不应导致服务整体不可用
 * ——这与鉴权的 fail-close 语义不同：鉴权守护安全边界，限流守护容量水位。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Common;

/**
 * 报文级限流（两层实现不可互换）
 *
 * L1 内存桶用于 UDP 验签前（零 IO、每来源 IP）；L2 Redis 令牌桶用于业务侧（每连接 + 每 uid）。
 */
class RateLimiter
{
    /** 维度：每连接（clientId） */
    const DIM_CONN = 'conn';

    /** 维度：每用户（uid） */
    const DIM_UID = 'uid';

    /** 维度：每来源 IP */
    const DIM_IP = 'ip';

    /** 维度：心跳指令（ping），配额独立且更严 */
    const DIM_PING = 'ping';

    /** 超限日志采样间隔（秒/维度），防止被限流的洪水写爆日志 */
    const LOG_INTERVAL = 1.0;

    /** 内存桶键前缀（仅 L1 使用） */
    const MEM_PREFIX = 'mem:';

    /**
     * 限流配置（app.rate_limit）
     *
     * @var array
     */
    protected static $config = array(
        'enable'          => true,
        'conn'            => array('rate' => 20,  'burst' => 40),
        'uid'             => array('rate' => 50,  'burst' => 100),
        'ip'              => array('rate' => 200, 'burst' => 400),
        'ping'            => array('rate' => 5,   'burst' => 10),
        'close_on_exceed' => false,
        'notify'          => true,
        'mem_max_buckets' => 20000,
    );

    /**
     * 进程内内存桶（L1）
     *
     * 结构：{ "<dim>|<id>" => ['tokens' => float, 'ts' => float(毫秒)] }
     *
     * @var array
     */
    protected static $buckets = [];

    /**
     * 超限日志采样时间戳：{ dim => float(秒) }
     *
     * @var array
     */
    protected static $logAt = [];

    /**
     * 初始化
     *
     * @param array $config app.rate_limit
     * @return void
     */
    public static function init(array $config = array())
    {
        self::$config = array_merge(self::$config, $config);
    }

    /**
     * 限流是否启用
     *
     * @return bool
     */
    public static function enabled()
    {
        return !empty(self::$config['enable']);
    }

    /**
     * 超限时是否断开连接
     *
     * @return bool
     */
    public static function shouldClose()
    {
        return !empty(self::$config['close_on_exceed']);
    }

    /**
     * 超限时是否回错误报文
     *
     * UDP 场景应在调用方绕过本开关（UDP 不回错误，避免反射放大）。
     *
     * @return bool
     */
    public static function shouldNotify()
    {
        return !empty(self::$config['notify']);
    }

    /**
     * 读取维度配额
     *
     * @param string $dim
     * @return array ['rate' => int, 'burst' => int]，rate <= 0 表示该维度不限流
     */
    public static function spec($dim)
    {
        $spec = isset(self::$config[$dim]) && is_array(self::$config[$dim])
            ? self::$config[$dim]
            : [];

        $rate  = isset($spec['rate']) ? (int)$spec['rate'] : 0;
        $burst = isset($spec['burst']) ? (int)$spec['burst'] : 0;

        // 容量不得小于速率，否则瞬时突发会被异常收紧
        if ($burst < $rate) {
            $burst = $rate;
        }

        return array('rate' => $rate, 'burst' => $burst);
    }

    /* ---------------------------------------------------------------------
     | L1：进程内内存令牌桶（零 IO）
     --------------------------------------------------------------------- */

    /**
     * 内存桶判定
     *
     * 用于网关层抗洪水。桶数量受 mem_max_buckets 约束，超限时按最近使用时间
     * 淘汰最旧的一半 —— 防止伪造源 IP 攻击导致进程内存无界增长。
     *
     * @param string $dim  维度（本层通常为 DIM_IP）
     * @param string $id   维度主体（IP / clientId / uid）
     * @param int    $cost 本次消耗令牌数
     * @return bool 是否放行
     */
    public static function checkMemory($dim, $id, $cost = 1)
    {
        if (!self::enabled()) {
            return true;
        }

        $spec = self::spec($dim);
        if ($spec['rate'] <= 0) {
            return true;
        }

        $cost = max(1, (int)$cost);
        $now  = microtime(true) * 1000;   // 毫秒，浮点
        $key  = self::MEM_PREFIX . $dim . '|' . $id;

        if (!isset(self::$buckets[$key])) {
            self::evictIfNeeded();
            self::$buckets[$key] = array(
                'tokens' => (float)$spec['burst'],
                'ts'     => $now,
            );
        }

        $bucket = &self::$buckets[$key];

        // 按经过时长线性补充，补至容量上限
        $elapsed         = max(0.0, $now - $bucket['ts']);
        $bucket['tokens'] = min((float)$spec['burst'], $bucket['tokens'] + $elapsed * $spec['rate'] / 1000);
        $bucket['ts']    = $now;

        if ($bucket['tokens'] < $cost) {
            return false;
        }

        $bucket['tokens'] -= $cost;
        return true;
    }

    /**
     * 内存桶超限淘汰
     *
     * 仅在桶数量达到上限时触发；每次淘汰一半，摊薄单次开销（O(n log n) 但摊薄后
     * 平均每 N/2 次新建桶才执行一次）。
     *
     * @return void
     */
    protected static function evictIfNeeded()
    {
        $max = max(100, (int)self::$config['mem_max_buckets']);
        if (count(self::$buckets) < $max) {
            return;
        }

        uasort(self::$buckets, function ($a, $b) {
            if ($a['ts'] === $b['ts']) {
                return 0;
            }
            return $a['ts'] < $b['ts'] ? -1 : 1;
        });

        self::$buckets = array_slice(self::$buckets, (int)($max / 2), null, true);

        // 淘汰告警同样采样：桶满意味着大量不同来源，逐条记录会让日志成为新瓶颈
        $now = microtime(true);
        if (!isset(self::$logAt['__evict']) || $now - self::$logAt['__evict'] >= self::LOG_INTERVAL) {
            self::$logAt['__evict'] = $now;
            Logger::warn('限流内存桶达到上限，已淘汰最旧的一半', array(
                'limit' => $max,
                'kept'  => count(self::$buckets),
            ));
        }
    }

    /* ---------------------------------------------------------------------
     | L2：Redis 令牌桶（跨进程共享）
     --------------------------------------------------------------------- */

    /**
     * 构造桶定义
     *
     * 主体标识统一 md5 压缩：uid 可能含任意字符，且避免键名过长。
     *
     * @param string $dim
     * @param string $id
     * @return array 空数组表示该维度未启用限流
     */
    public static function bucket($dim, $id)
    {
        $spec = self::spec($dim);
        if ($spec['rate'] <= 0 || (string)$id === '') {
            return [];
        }

        return array(
            'key'   => RedisKeys::rateBucket($dim, $id),
            'rate'  => $spec['rate'],
            'burst' => $spec['burst'],
        );
    }

    /**
     * Redis 多桶判定
     *
     * @param array    $buckets bucket() 返回的桶定义列表（可含空数组，自动忽略）
     * @param int      $cost
     * @param callable $cb      function(bool $allowed)
     * @return void
     */
    public static function acquire(array $buckets, $cost, callable $cb)
    {
        $valid = [];
        foreach ($buckets as $bucket) {
            if (is_array($bucket) && !empty($bucket['key'])) {
                $valid[] = $bucket;
            }
        }

        if (!self::enabled() || !$valid) {
            $cb(true);
            return;
        }

        RedisClient::tokenBuckets($valid, $cost, function ($allowed, $error = '') use ($cb) {
            if ($allowed === null) {
                // Redis 异常：fail-open 放行，避免限流器故障放大为业务全量中断
                Logger::error('限流器不可用，按 fail-open 放行', array('error' => $error));
                $cb(true);
                return;
            }
            $cb((bool)$allowed);
        });
    }

    /* ---------------------------------------------------------------------
     | 可观测性辅助
     --------------------------------------------------------------------- */

    /**
     * 超限日志（按维度采样，每秒最多一条）
     *
     * 被限流的流量本身就是洪水，逐条记录会让日志成为新的瓶颈。
     *
     * @param string $dim
     * @param string $id
     * @param array  $extra
     * @return void
     */
    public static function logReject($dim, $id, array $extra = array())
    {
        $now = microtime(true);
        if (isset(self::$logAt[$dim]) && $now - self::$logAt[$dim] < self::LOG_INTERVAL) {
            return;
        }
        self::$logAt[$dim] = $now;

        Logger::warn('报文超限已拒绝（日志按维度采样）', array_merge(array(
            'dim'   => $dim,
            'rate'  => self::spec($dim)['rate'],
            'burst' => self::spec($dim)['burst'],
            'from'  => substr((string)$id, 0, 64),
        ), $extra));
    }

    /**
     * 重置进程内状态（仅测试使用）
     *
     * @return void
     */
    public static function reset()
    {
        self::$buckets = [];
        self::$logAt   = [];
    }

    /**
     * 当前内存桶数量（仅观测/测试使用）
     *
     * @return int
     */
    public static function bucketCount()
    {
        return count(self::$buckets);
    }
}
