<?php
/**
 * 异步 Redis 客户端封装（基于 workerman/redis）
 *
 * 设计要点：
 *  - 纯 PHP 异步实现，无需 phpredis 扩展；命令全程异步，不阻塞事件循环（对应文档 8.2 节）
 *  - 连接池：每进程维护 pool_size 个长连接，轮询分配，避免单连接命令队列过深
 *  - 统一前缀：所有 key 自动拼接 app.redis.prefix，业务层不感知命名空间
 *  - 强制回调：workerman/redis 在无回调且存在协程环境时会挂起当前协程，
 *              本封装一律传回调，保证在普通事件回调中调用也安全
 *  - 断线重连：由 workerman/redis 内部 onClose -> connect 自动完成，无需业务干预
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Common;

use RuntimeException;
use Workerman\Redis\Client;

/**
 * 异步 Redis 客户端封装（基于 workerman/redis）
 *
 * 连接池轮询分配、键名统一拼接全局前缀、命令一律带回调；无需 phpredis 扩展。
 */
class RedisClient
{
    /**
     * Lua：原子批量弹出队列元素
     *
     * KEYS[1] = 队列键，ARGV[1] = 单批最大条数
     */
    public const LUA_POP_BATCH = "local items = redis.call('LRANGE', KEYS[1], 0, tonumber(ARGV[1]) - 1) "
        . "if #items > 0 then redis.call('LTRIM', KEYS[1], #items, -1) end return items";

    /**
     * Lua：SET key value EX ttl NX
     *
     * KEYS[1] = 键，ARGV[1] = 值，ARGV[2] = TTL 秒
     */
    public const LUA_SET_NX_EX = "local ok = redis.call('SET', KEYS[1], ARGV[1], 'EX', tonumber(ARGV[2]), 'NX') "
        . 'if ok then return 1 end return 0';

    /**
     * Lua：多桶令牌桶限流（单次往返原子判定 N 个桶）
     *
     * 设计说明：
     *  - 令牌桶相对固定窗口的优势：支持合法突发，且不存在窗口边界「双倍放行」缺陷。
     *  - 一次判定多个桶（KEYS 可变长），任一桶令牌不足即整单拒绝且**不扣减任何桶**，
     *    避免「连接桶已扣、用户桶拒绝」造成的配额泄漏。
     *  - 全部桶在 Redis 单线程内完成读改写，多进程 / 多机天然共享配额。
     *
     * 参数布局：
     *   KEYS[1..n]     各桶键（需已带全局前缀）
     *   ARGV[1]        now，当前时间戳（毫秒）
     *   ARGV[2]        cost，本次消耗令牌数
     *   ARGV[3+2i]     rate_i，第 i 个桶的令牌补充速率（个/秒，i 从 0 起）
     *   ARGV[4+2i]     burst_i，第 i 个桶的容量上限
     *
     * 返回：1 = 放行，0 = 拒绝
     *
     * 存储结构（Hash，HMSET 兼容 Redis 3.x，HSET 多字段需 4.0+）：
     *   tokens  当前剩余令牌（浮点）
     *   ts      上次结算时间（毫秒）
     */
    public const LUA_TOKEN_BUCKET = 'local n = #KEYS '
        . 'local now = tonumber(ARGV[1]) '
        . 'local cost = tonumber(ARGV[2]) '
        . 'local state = {} '
        . 'local allowed = 1 '
        . 'for i = 1, n do '
        . "  local d = redis.call('HMGET', KEYS[i], 'tokens', 'ts') "
        . '  local tokens = tonumber(d[1]) '
        . '  local ts = tonumber(d[2]) '
        . '  local rate = tonumber(ARGV[1 + i * 2]) '
        . '  local burst = tonumber(ARGV[2 + i * 2]) '
        . '  if tokens == nil then tokens = burst; ts = now end '
        . '  tokens = math.min(burst, tokens + math.max(0, now - ts) * rate / 1000) '
        . '  state[i] = {tokens = tokens, rate = rate, burst = burst} '
        . '  if tokens < cost then allowed = 0 end '
        . 'end '
        . 'if allowed == 1 then '
        . '  for i = 1, n do state[i].tokens = state[i].tokens - cost end '
        . 'end '
        . 'for i = 1, n do '
        . "  redis.call('HMSET', KEYS[i], 'tokens', tostring(state[i].tokens), 'ts', tostring(now)) "
        . "  redis.call('PEXPIRE', KEYS[i], math.ceil(state[i].burst / state[i].rate * 1000) + 1000) "
        . 'end '
        . 'return allowed';

    /**
     * 连接配置
     *
     * @var array<string, mixed>
     */
    protected static $config = [
        'host'       => '127.0.0.1',
        'port'       => 6379,
        'password'   => '',
        'database'   => 0,
        'timeout'    => 2.0,
        'pool_size'  => 8,
        'prefix'     => '',
        'reconnect_interval' => 1.0,
    ];

    /**
     * 异步连接池
     *
     * @var Client[]
     */
    protected static $pool = [];

    /**
     * 轮询游标
     *
     * @var int
     */
    protected static $cursor = 0;

    /**
     * 是否已初始化
     *
     * @var bool
     */
    protected static $inited = false;

    /**
     * 连接预设结果（spl_object_id => bool）
     *
     * 标记哪些连接已完成 DB / AUTH 预设，用于跳过连接回调中的兜底逻辑。
     *
     * @var array<string, mixed>
     */
    protected static $primed = [];

    /**
     * 初始化连接配置
     *
     * @param array<string, mixed> $config
     *
     * @return void
     */
    public static function init(array $config = [])
    {
        self::$config = array_merge(self::$config, $config);
        self::$inited = true;
    }

    /**
     * 拼接全局前缀
     *
     * @param string $name
     *
     * @return string
     */
    public static function key($name)
    {
        return self::$config['prefix'] . $name;
    }

    /**
     * 取出一个可用连接（轮询）
     *
     * @return Client
     *
     * @throws RuntimeException 未调用 init()，或缺少 workerman/redis 依赖时抛出
     */
    public static function connection()
    {
        if (!self::$inited) {
            throw new RuntimeException('RedisClient 未初始化，请先调用 RedisClient::init()');
        }
        if (!class_exists(Client::class)) {
            throw new RuntimeException('缺少依赖 workerman/redis，请先执行 composer install');
        }

        $size  = max(1, (int)self::$config['pool_size']);
        $index = self::$cursor++ % $size;

        if (!isset(self::$pool[$index]) || self::$pool[$index] === null) {
            self::$pool[$index] = self::createConnection($index);
        }

        return self::$pool[$index];
    }

    /* ---------------------------------------------------------------------
     | String
     --------------------------------------------------------------------- */

    /**
     * @param string $key
     *
     * @return mixed
     */
    public static function get($key, ?callable $cb = null)
    {
        return self::connection()->get(self::key($key), self::wrap('GET', $cb, $key));
    }

    /**
     * 写入字符串，$ttl > 0 时使用 SETEX
     *
     * @param string        $key
     * @param mixed         $value
     * @param int           $ttl   秒，0 表示不过期
     * @param null|callable $cb
     *
     * @return mixed
     */
    public static function set($key, $value, $ttl = 0, ?callable $cb = null)
    {
        $fullKey = self::key($key);
        if ((int)$ttl > 0) {
            return self::connection()->setEx($fullKey, (int)$ttl, $value, self::wrap('SETEX', $cb, $key));
        }

        return self::connection()->set($fullKey, $value, self::wrap('SET', $cb, $key));
    }

    /**
     * 批量读取（自动补前缀），返回顺序与入参一致，缺失元素为 false
     *
     * @param array<int|string, mixed> $keys
     * @param null|callable            $cb
     *
     * @return mixed
     */
    public static function mGet(array $keys, ?callable $cb = null)
    {
        $fullKeys = self::prefixKeys($keys);
        if (!$fullKeys) {
            if ($cb) {
                $cb([]);
            }

            return null;
        }

        return self::connection()->mGet($fullKeys, self::wrap('MGET', $cb, count($fullKeys) . ' keys'));
    }

    /**
     * 判断 key 是否存在
     *
     * @param string        $key
     * @param null|callable $cb
     *
     * @return mixed
     */
    public static function exists($key, ?callable $cb = null)
    {
        return self::connection()->exists(self::key($key), self::wrap('EXISTS', $cb, $key));
    }

    /**
     * 设置 key 的过期时间（秒）
     *
     * @param string        $key
     * @param int           $ttl
     * @param null|callable $cb
     *
     * @return mixed
     */
    public static function expire($key, $ttl, ?callable $cb = null)
    {
        return self::connection()->expire(self::key($key), (int)$ttl, self::wrap('EXPIRE', $cb, $key));
    }

    /**
     * 读取 key 的剩余生存时间（秒；-1 = 永久，-2 = 不存在）
     *
     * @param string        $key
     * @param null|callable $cb
     *
     * @return mixed
     */
    public static function ttl($key, ?callable $cb = null)
    {
        return self::connection()->ttl(self::key($key), self::wrap('TTL', $cb, $key));
    }

    /**
     * 自增（缺省步长为 1，底层为 INCRBY）
     *
     * @param string        $key
     * @param int           $step
     * @param null|callable $cb
     *
     * @return mixed
     */
    public static function incr($key, $step = 1, ?callable $cb = null)
    {
        return self::connection()->incr(self::key($key), (int)$step, self::wrap('INCRBY', $cb, $key));
    }

    /* ---------------------------------------------------------------------
     | Keys
     --------------------------------------------------------------------- */

    /**
     * 删除 key（支持单个或数组）
     *
     * @param array<int|string, mixed>|string $keys
     * @param null|callable                   $cb
     *
     * @return mixed
     */
    public static function del($keys, ?callable $cb = null)
    {
        $fullKeys = self::prefixKeys((array)$keys);
        if (!$fullKeys) {
            return null;
        }
        $args   = $fullKeys;
        $args[] = self::wrap('DEL', $cb, implode(',', $fullKeys));

        return self::connection()->del(...$args);
    }

    /* ---------------------------------------------------------------------
     | Hash
     --------------------------------------------------------------------- */

    /**
     * 写入单个 Hash 字段
     *
     * @param string        $key
     * @param string        $field
     * @param mixed         $value
     * @param null|callable $cb
     *
     * @return mixed
     */
    public static function hSet($key, $field, $value, ?callable $cb = null)
    {
        return self::connection()->hSet(self::key($key), $field, $value, self::wrap('HSET', $cb, $key));
    }

    /**
     * 批量写入 Hash 字段
     *
     * 用 HMSET 而非多字段 HSET —— 后者需 Redis 4.0+，本封装兼顾 Redis 3.x。
     *
     * @param string               $key
     * @param array<string, mixed> $hash
     * @param null|callable        $cb
     *
     * @return mixed
     */
    public static function hMSet($key, array $hash, ?callable $cb = null)
    {
        return self::connection()->hMSet(self::key($key), $hash, self::wrap('HMSET', $cb, $key));
    }

    /**
     * 读取单个 Hash 字段
     *
     * @param string        $key
     * @param string        $field
     * @param null|callable $cb
     *
     * @return mixed
     */
    public static function hGet($key, $field, ?callable $cb = null)
    {
        return self::connection()->hGet(self::key($key), $field, self::wrap('HGET', $cb, $key));
    }

    /**
     * 读取 Hash 的全部字段
     *
     * @param string        $key
     * @param null|callable $cb
     *
     * @return mixed
     */
    public static function hGetAll($key, ?callable $cb = null)
    {
        return self::connection()->hGetAll(self::key($key), self::wrap('HGETALL', $cb, $key));
    }

    /**
     * 删除一个或多个 Hash 字段
     *
     * @param string                          $key
     * @param array<int|string, mixed>|string $fields
     * @param null|callable                   $cb
     *
     * @return mixed
     */
    public static function hDel($key, $fields, ?callable $cb = null)
    {
        $args   = [self::key($key)];
        foreach ((array)$fields as $field) {
            $args[] = $field;
        }
        $args[] = self::wrap('HDEL', $cb, $key);

        return self::connection()->hDel(...$args);
    }

    /**
     * Hash 字段自增
     *
     * @param string        $key
     * @param string        $field
     * @param int           $step
     * @param null|callable $cb
     *
     * @return mixed
     */
    public static function hIncrBy($key, $field, $step = 1, ?callable $cb = null)
    {
        return self::connection()->hIncrBy(self::key($key), $field, (int)$step, self::wrap('HINCRBY', $cb, $key));
    }

    /* ---------------------------------------------------------------------
     | List（UDP 队列使用）
     --------------------------------------------------------------------- */

    /**
     * 从右侧推入列表元素
     *
     * @param string        $key
     * @param mixed         $value
     * @param null|callable $cb
     *
     * @return mixed
     */
    public static function rPush($key, $value, ?callable $cb = null)
    {
        return self::connection()->rPush(self::key($key), $value, self::wrap('RPUSH', $cb, $key));
    }

    /**
     * 读取列表长度
     *
     * @param string        $key
     * @param null|callable $cb
     *
     * @return mixed
     */
    public static function lLen($key, ?callable $cb = null)
    {
        return self::connection()->lLen(self::key($key), self::wrap('LLEN', $cb, $key));
    }

    /**
     * 读取列表区间（含首尾，支持负索引）
     *
     * @param string        $key
     * @param int           $start
     * @param int           $stop
     * @param null|callable $cb
     *
     * @return mixed
     */
    public static function lRange($key, $start, $stop, ?callable $cb = null)
    {
        return self::connection()->lRange(
            self::key($key),
            (int)$start,
            (int)$stop,
            self::wrap('LRANGE', $cb, $key)
        );
    }

    /**
     * 裁剪列表，仅保留指定区间
     *
     * @param string        $key
     * @param int           $start
     * @param int           $stop
     * @param null|callable $cb
     *
     * @return mixed
     */
    public static function lTrim($key, $start, $stop, ?callable $cb = null)
    {
        return self::connection()->lTrim(
            self::key($key),
            (int)$start,
            (int)$stop,
            self::wrap('LTRIM', $cb, $key)
        );
    }

    /**
     * 仅保留列表末尾 N 条（超限裁剪）
     *
     * 等价于 LTRIM key -N -1，用于离线消息等有上限的队列。
     *
     * @param string        $key
     * @param int           $keep
     * @param null|callable $cb
     *
     * @return mixed
     */
    public static function lTrimKeepLast($key, $keep, ?callable $cb = null)
    {
        $keep = max(1, (int)$keep);

        return self::connection()->lTrim(
            self::key($key),
            -$keep,
            -1,
            self::wrap('LTRIM', $cb, $key)
        );
    }

    /* ---------------------------------------------------------------------
     | Set（在线集合使用）
     --------------------------------------------------------------------- */

    /**
     * 添加集合成员
     *
     * @param string                          $key
     * @param array<int|string, mixed>|string $members
     * @param null|callable                   $cb
     *
     * @return mixed
     */
    public static function sAdd($key, $members, ?callable $cb = null)
    {
        $args = [self::key($key)];
        foreach ((array)$members as $member) {
            $args[] = $member;
        }
        $args[] = self::wrap('SADD', $cb, $key);

        return self::connection()->sAdd(...$args);
    }

    /**
     * 移除集合成员
     *
     * @param string                          $key
     * @param array<int|string, mixed>|string $members
     * @param null|callable                   $cb
     *
     * @return mixed
     */
    public static function sRem($key, $members, ?callable $cb = null)
    {
        $args = [self::key($key)];
        foreach ((array)$members as $member) {
            $args[] = $member;
        }
        $args[] = self::wrap('SREM', $cb, $key);

        return self::connection()->sRem(...$args);
    }

    /**
     * 读取集合全部成员
     *
     * @param string        $key
     * @param null|callable $cb
     *
     * @return mixed
     */
    public static function sMembers($key, ?callable $cb = null)
    {
        return self::connection()->sMembers(self::key($key), self::wrap('SMEMBERS', $cb, $key));
    }

    /**
     * 获取集合成员数
     *
     * @param string        $key
     * @param null|callable $cb
     *
     * @return mixed
     */
    public static function sCard($key, ?callable $cb = null)
    {
        return self::connection()->sCard(self::key($key), self::wrap('SCARD', $cb, $key));
    }

    /**
     * 判断是否为集合成员
     *
     * @param string        $key
     * @param string        $member
     * @param null|callable $cb
     *
     * @return mixed
     */
    public static function sIsMember($key, $member, ?callable $cb = null)
    {
        return self::connection()->sIsMember(self::key($key), $member, self::wrap('SISMEMBER', $cb, $key));
    }

    /* ---------------------------------------------------------------------
     | Scripting
     --------------------------------------------------------------------- */

    /**
     * 原子批量弹出队列元素（Lua 实现）
     *
     * 用途：UDP 入站队列 / 推送队列的多进程消费。
     * lRange + lTrim 两步操作之间存在非原子窗口，多进程并发消费会重复处理同一批
     * 元素；Lua 脚本在 Redis 单线程内一次执行完毕，取批与裁剪不可分割。
     *
     * @param string        $key
     * @param int           $batch 单次最大弹出条数
     * @param null|callable $cb    function(array $items)
     *
     * @return mixed
     */
    public static function popBatch($key, $batch = 100, ?callable $cb = null)
    {
        $batch   = max(1, (int)$batch);
        $fullKey = self::key($key);

        return self::eval(
            self::LUA_POP_BATCH,
            [$fullKey, $batch],
            1,
            function ($result, $client = null) use ($key, $cb) {
                if ($client && method_exists($client, 'error') && $client->error() !== '') {
                    Logger::error('Redis 批量弹出失败', ['key' => $key, 'error' => $client->error()]);
                    $result = [];
                }
                $items = is_array($result) ? $result : [];
                if ($cb) {
                    $cb($items);
                }

                return $items;
            }
        );
    }

    /**
     * 原子写入「不存在的键」（幂等控制用）
     *
     * 等价于 SET key value EX ttl NX，返回 1 表示首次写入成功。
     *
     * @param string        $key
     * @param string        $value
     * @param int           $ttl
     * @param null|callable $cb    function(bool $first)
     *
     * @return mixed
     */
    public static function setNxEx($key, $value, $ttl = 0, ?callable $cb = null)
    {
        $ttl     = max(1, (int)$ttl);
        $fullKey = self::key($key);

        return self::eval(
            self::LUA_SET_NX_EX,
            [$fullKey, (string)$value, (string)$ttl],
            1,
            function ($result, $client = null) use ($key, $cb) {
                if ($client && method_exists($client, 'error') && $client->error() !== '') {
                    Logger::error('Redis SETNX 执行失败', ['key' => $key, 'error' => $client->error()]);
                    $result = 0;
                }
                $first = (int)$result === 1;
                if ($cb) {
                    $cb($first);
                }

                return $first;
            }
        );
    }

    /**
     * 多桶令牌桶限流（原子）
     *
     * @param array<int|string, mixed> $buckets 桶定义列表：[['key'=>string, 'rate'=>int, 'burst'=>int], ...]
     * @param int                      $cost    本次消耗令牌数
     * @param null|callable            $cb      function(bool $allowed)
     *
     * @return mixed
     */
    public static function tokenBuckets(array $buckets, $cost = 1, ?callable $cb = null)
    {
        if (!$buckets) {
            if ($cb) {
                $cb(true);
            }

            return true;
        }

        $cost  = max(1, (int)$cost);
        $keys  = [];
        $rates = [];
        $sizes = [];

        foreach ($buckets as $bucket) {
            $rate  = max(1, (int)($bucket['rate'] ?? 1));
            $burst = max($rate, (int)($bucket['burst'] ?? $rate));

            $keys[]  = self::key($bucket['key']);
            $rates[] = (string)$rate;
            $sizes[] = (string)$burst;
        }

        // 参数顺序：now, cost, 然后按桶顺序 (rate, burst) 两两成对
        $args = [(string)(int)(microtime(true) * 1000), (string)$cost];
        $n    = count($keys);
        for ($i = 0; $i < $n; $i++) {
            $args[] = $rates[$i];
            $args[] = $sizes[$i];
        }

        return self::eval(
            self::LUA_TOKEN_BUCKET,
            array_merge($keys, $args),
            $n,
            function ($result, $client = null) use ($cb) {
                $error = ($client && method_exists($client, 'error')) ? $client->error() : '';
                if ($error !== '') {
                    // 交由调用方决定降级策略（RateLimiter 采用 fail-open）
                    Logger::error('Redis 令牌桶执行失败', ['error' => $error]);
                    if ($cb) {
                        $cb(null, $error);
                    }

                    return null;
                }
                $allowed = (int)$result === 1;
                if ($cb) {
                    $cb($allowed, '');
                }

                return $allowed;
            }
        );
    }

    /**
     * 执行 Lua 脚本（用于需要原子性的复合操作）
     *
     * 参数拼接说明（实测结论，勿随意调整）：
     *   Redis 语法为 EVAL script numkeys key [key ...] [arg [arg ...]]，
     *   即 numkeys 必须紧跟在 script 之后。
     *   而 workerman/redis 的魔术方法只会把入参数组**展平一层**
     *   （见 Protocols\Redis::encode），因此本方法把 numkeys 与 KEYS/ARGV
     *   合并为单个数组传入，最终拼出正确的命令序列。
     *
     *   错误写法：connection()->eval($script, $args, $numKeys, $cb)
     *              -> 得到 EVAL script key arg numkeys（numkeys 位置错误，Redis 报错）
     *
     * @param string                   $script
     * @param array<int|string, mixed> $args    KEYS + ARGV 顺序拼接（key 需已带全局前缀）
     * @param int                      $numKeys KEYS 个数
     * @param null|callable            $cb      function(mixed $result, Client $client = null)
     *
     * @return mixed
     */
    public static function eval($script, array $args = [], $numKeys = 0, ?callable $cb = null)
    {
        $flat = array_merge([(int)$numKeys], array_values($args));

        return self::connection()->eval(
            (string)$script,
            $flat,
            self::wrap('EVAL', $cb)
        );
    }

    /* ---------------------------------------------------------------------
     | 运维辅助
     --------------------------------------------------------------------- */

    /**
     * 连通性探测
     *
     * 说明：workerman/redis 的 __call 对无参命令（PING）会误判回调位置，
     * 因此统一使用 EXISTS 做健康检查。
     *
     * @param null|callable $cb
     *
     * @return mixed
     */
    public static function healthCheck(?callable $cb = null)
    {
        return self::connection()->exists(self::key(RedisKeys::HEALTH_PROBE), function ($result, $client = null) use ($cb) {
            $ok = ($client && method_exists($client, 'error') && $client->error() !== '') ? false : true;
            if ($cb) {
                $cb($ok);
            }
        });
    }

    /**
     * 关闭全部连接（进程退出前调用）
     *
     * @return void
     */
    public static function closeAll()
    {
        foreach (self::$pool as $client) {
            if ($client instanceof Client) {
                $client->close();
            }
        }
        self::$pool   = [];
        self::$cursor = 0;
        self::$primed = [];
    }

    /**
     * 创建异步连接
     *
     * @param int $index 池内序号，仅用于日志
     *
     * @return Client
     */
    protected static function createConnection($index)
    {
        $address = sprintf('redis://%s:%d', self::$config['host'], (int)self::$config['port']);
        $options = [
            'connect_timeout' => (float)self::$config['timeout'],
        ];

        $client = new Client($address, $options, function ($success, $client) use ($index, $address) {
            if (!$success) {
                Logger::error('Redis 连接失败', [
                    'pool'    => $index,
                    'address' => $address,
                    'error'   => $client->error(),
                ]);

                return;
            }
            Logger::info('Redis 连接成功', [
                'pool'     => $index,
                'address'  => $address,
                'database' => (int)self::$config['database'],
            ]);

            // 兜底路径：正常情况已由 primeConnection() 预设属性、由客户端自动补发完成；
            // 仅当反射预设失败时才需要在这里显式下发（此时首批命令可能已落错库，属已知降级）
            if (!empty(self::$primed[spl_object_id($client)])) {
                return;
            }

            $password = (string)self::$config['password'];
            if ($password !== '') {
                $client->auth($password, function ($result, $c) {
                    if ($c->error() !== '') {
                        Logger::error('Redis AUTH 失败', ['error' => $c->error()]);
                    }
                });
            }

            $database = (int)self::$config['database'];
            if ($database > 0) {
                $client->select($database, function () {});
            }
        });

        // 必须在任何业务命令入队前完成，原因见 primeConnection()
        self::primeConnection($client);

        return $client;
    }

    /**
     * 预设连接的 DB / 认证信息（关键修复，勿删）
     *
     * 背景：
     *   workerman/redis 的 Client 内部维护 $_db / $_auth，并在**每次连接建立时**
     *   把 [['SELECT', $_db]] / [['AUTH', $_auth]] 插入命令队列首位（Client::connect 中）。
     *   但 select() / auth() 是在**命令响应返回后**才通过 format 回调写入这两个属性的，
     *   而 onConnect 的执行顺序是「先 process() 发送队列，再回调用户 callback」。
     *
     *   后果：若首个业务命令与连接建立落在同一事件循环周期（连接池懒加载时必然如此），
     *   该命令会先于 SELECT 发出，静默落到默认 DB 0。实测复现：
     *   Push::enqueue 的首条 RPUSH 写入 DB 0，而消费端读 DB 9，队列恒为空。
     *
     *   解决：构造完成后立即用反射预设属性，使自动补发机制在连接建立时就
     *   把 SELECT / AUTH 排到队首，从根源消除竞态。
     *
     * @param Client $client
     *
     * @return void
     */
    protected static function primeConnection(Client $client)
    {
        $database = (int)self::$config['database'];
        $password = (string)self::$config['password'];

        self::$primed[spl_object_id($client)] = false;

        if ($database <= 0 && $password === '') {
            // 使用默认 DB 且无密码，无需预设
            self::$primed[spl_object_id($client)] = true;

            return;
        }

        try {
            $ref = new \ReflectionObject($client);

            if ($database > 0 && $ref->hasProperty('_db')) {
                $prop = $ref->getProperty('_db');
                $prop->setAccessible(true);
                $prop->setValue($client, $database);
            }

            if ($password !== '' && $ref->hasProperty('_auth')) {
                $prop = $ref->getProperty('_auth');
                $prop->setAccessible(true);
                $prop->setValue($client, $password);
            }

            self::$primed[spl_object_id($client)] = true;
        } catch (\Throwable $e) {
            Logger::warn('Redis 连接预设失败，DB / AUTH 可能延迟生效', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 统一回调包装
     *
     * 未显式传入回调时，自动消费命令结果并记录错误，避免错误静默丢失。
     *
     * @param string        $command
     * @param null|callable $cb
     * @param string        $traceKey
     *
     * @return callable
     */
    protected static function wrap($command, ?callable $cb = null, $traceKey = '')
    {
        if ($cb !== null) {
            return $cb;
        }

        return function ($result, $client = null) use ($command, $traceKey) {
            if ($client && method_exists($client, 'error')) {
                $err = $client->error();
                if ($err !== '') {
                    Logger::error('Redis 命令执行失败', [
                        'cmd'   => $command,
                        'key'   => $traceKey,
                        'error' => $err,
                    ]);
                }
            }

            return $result;
        };
    }

    /**
     * 批量补齐 key 前缀
     *
     * @param array<int|string, mixed> $keys
     *
     * @return array<int|string, mixed>
     */
    protected static function prefixKeys(array $keys)
    {
        $result = [];
        foreach ($keys as $key) {
            $result[] = self::key($key);
        }

        return $result;
    }
}
