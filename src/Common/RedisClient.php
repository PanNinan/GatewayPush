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
 * 兼容 PHP 8.0 ~ 8.5
 */

namespace GatewayPush\Common;

use RuntimeException;
use Workerman\Redis\Client;

class RedisClient
{
    /**
     * 连接配置
     *
     * @var array
     */
    protected static $config = array(
        'host'       => '127.0.0.1',
        'port'       => 6379,
        'password'   => '',
        'database'   => 0,
        'timeout'    => 2.0,
        'pool_size'  => 8,
        'prefix'     => '',
        'reconnect_interval' => 1.0,
    );

    /**
     * 异步连接池
     *
     * @var Client[]
     */
    protected static $pool = array();

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
     * 初始化连接配置
     *
     * @param array $config
     * @return void
     */
    public static function init(array $config = array())
    {
        self::$config = array_merge(self::$config, $config);
        self::$inited = true;
    }

    /**
     * 拼接全局前缀
     *
     * @param string $name
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

    /**
     * 创建异步连接
     *
     * @param int $index 池内序号，仅用于日志
     * @return Client
     */
    protected static function createConnection($index)
    {
        $address = sprintf('redis://%s:%d', self::$config['host'], (int)self::$config['port']);
        $options = array(
            'connect_timeout' => (float)self::$config['timeout'],
        );

        $client = new Client($address, $options, function ($success, $client) use ($index, $address) {
            if (!$success) {
                Logger::error('Redis 连接失败', array(
                    'pool'    => $index,
                    'address' => $address,
                    'error'   => $client->error(),
                ));
                return;
            }
            Logger::info('Redis 连接成功', array('pool' => $index, 'address' => $address));

            $password = (string)self::$config['password'];
            if ($password !== '') {
                $client->auth($password, function ($result, $c) {
                    if ($c->error() !== '') {
                        Logger::error('Redis AUTH 失败', array('error' => $c->error()));
                    }
                });
            }

            $database = (int)self::$config['database'];
            if ($database > 0) {
                $client->select($database, function () {
                });
            }
        });

        return $client;
    }

    /**
     * 统一回调包装
     *
     * 未显式传入回调时，自动消费命令结果并记录错误，避免错误静默丢失。
     *
     * @param string        $command
     * @param callable|null $cb
     * @param string        $traceKey
     * @return callable
     */
    protected static function wrap($command, callable $cb = null, $traceKey = '')
    {
        if ($cb !== null) {
            return $cb;
        }
        return function ($result, $client = null) use ($command, $traceKey) {
            if ($client && method_exists($client, 'error')) {
                $err = $client->error();
                if ($err !== '') {
                    Logger::error('Redis 命令执行失败', array(
                        'cmd'   => $command,
                        'key'   => $traceKey,
                        'error' => $err,
                    ));
                }
            }
            return $result;
        };
    }

    /**
     * 批量补齐 key 前缀
     *
     * @param array $keys
     * @return array
     */
    protected static function prefixKeys(array $keys)
    {
        $result = array();
        foreach ($keys as $key) {
            $result[] = self::key($key);
        }
        return $result;
    }

    /* ---------------------------------------------------------------------
     | String
     --------------------------------------------------------------------- */

    public static function get($key, callable $cb = null)
    {
        return self::connection()->get(self::key($key), self::wrap('GET', $cb, $key));
    }

    /**
     * 写入字符串，$ttl > 0 时使用 SETEX
     *
     * @param string        $key
     * @param mixed         $value
     * @param int           $ttl 秒，0 表示不过期
     * @param callable|null $cb
     * @return mixed
     */
    public static function set($key, $value, $ttl = 0, callable $cb = null)
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
     * @param array         $keys
     * @param callable|null $cb
     * @return mixed
     */
    public static function mGet(array $keys, callable $cb = null)
    {
        $fullKeys = self::prefixKeys($keys);
        if (!$fullKeys) {
            if ($cb) {
                call_user_func($cb, array());
            }
            return null;
        }
        return self::connection()->mGet($fullKeys, self::wrap('MGET', $cb, count($fullKeys) . ' keys'));
    }

    public static function exists($key, callable $cb = null)
    {
        return self::connection()->exists(self::key($key), self::wrap('EXISTS', $cb, $key));
    }

    public static function expire($key, $ttl, callable $cb = null)
    {
        return self::connection()->expire(self::key($key), (int)$ttl, self::wrap('EXPIRE', $cb, $key));
    }

    public static function ttl($key, callable $cb = null)
    {
        return self::connection()->ttl(self::key($key), self::wrap('TTL', $cb, $key));
    }

    public static function incr($key, $step = 1, callable $cb = null)
    {
        return self::connection()->incr(self::key($key), (int)$step, self::wrap('INCRBY', $cb, $key));
    }

    /* ---------------------------------------------------------------------
     | Keys
     --------------------------------------------------------------------- */

    /**
     * 删除 key（支持单个或数组）
     *
     * @param string|array  $keys
     * @param callable|null $cb
     * @return mixed
     */
    public static function del($keys, callable $cb = null)
    {
        $fullKeys = self::prefixKeys((array)$keys);
        if (!$fullKeys) {
            return null;
        }
        $args   = $fullKeys;
        $args[] = self::wrap('DEL', $cb, implode(',', $fullKeys));
        return call_user_func_array(array(self::connection(), 'del'), $args);
    }

    /* ---------------------------------------------------------------------
     | Hash
     --------------------------------------------------------------------- */

    public static function hSet($key, $field, $value, callable $cb = null)
    {
        return self::connection()->hSet(self::key($key), $field, $value, self::wrap('HSET', $cb, $key));
    }

    public static function hMSet($key, array $hash, callable $cb = null)
    {
        return self::connection()->hMSet(self::key($key), $hash, self::wrap('HMSET', $cb, $key));
    }

    public static function hGet($key, $field, callable $cb = null)
    {
        return self::connection()->hGet(self::key($key), $field, self::wrap('HGET', $cb, $key));
    }

    public static function hGetAll($key, callable $cb = null)
    {
        return self::connection()->hGetAll(self::key($key), self::wrap('HGETALL', $cb, $key));
    }

    public static function hDel($key, $fields, callable $cb = null)
    {
        $args   = array(self::key($key));
        foreach ((array)$fields as $field) {
            $args[] = $field;
        }
        $args[] = self::wrap('HDEL', $cb, $key);
        return call_user_func_array(array(self::connection(), 'hDel'), $args);
    }

    public static function hIncrBy($key, $field, $step = 1, callable $cb = null)
    {
        return self::connection()->hIncrBy(self::key($key), $field, (int)$step, self::wrap('HINCRBY', $cb, $key));
    }

    /* ---------------------------------------------------------------------
     | List（UDP 队列使用）
     --------------------------------------------------------------------- */

    public static function rPush($key, $value, callable $cb = null)
    {
        return self::connection()->rPush(self::key($key), $value, self::wrap('RPUSH', $cb, $key));
    }

    public static function lLen($key, callable $cb = null)
    {
        return self::connection()->lLen(self::key($key), self::wrap('LLEN', $cb, $key));
    }

    public static function lRange($key, $start, $stop, callable $cb = null)
    {
        return self::connection()->lRange(
            self::key($key),
            (int)$start,
            (int)$stop,
            self::wrap('LRANGE', $cb, $key)
        );
    }

    public static function lTrim($key, $start, $stop, callable $cb = null)
    {
        return self::connection()->lTrim(
            self::key($key),
            (int)$start,
            (int)$stop,
            self::wrap('LTRIM', $cb, $key)
        );
    }

    /* ---------------------------------------------------------------------
     | Set（在线集合使用）
     --------------------------------------------------------------------- */

    public static function sAdd($key, $members, callable $cb = null)
    {
        $args = array(self::key($key));
        foreach ((array)$members as $member) {
            $args[] = $member;
        }
        $args[] = self::wrap('SADD', $cb, $key);
        return call_user_func_array(array(self::connection(), 'sAdd'), $args);
    }

    public static function sRem($key, $members, callable $cb = null)
    {
        $args = array(self::key($key));
        foreach ((array)$members as $member) {
            $args[] = $member;
        }
        $args[] = self::wrap('SREM', $cb, $key);
        return call_user_func_array(array(self::connection(), 'sRem'), $args);
    }

    public static function sMembers($key, callable $cb = null)
    {
        return self::connection()->sMembers(self::key($key), self::wrap('SMEMBERS', $cb, $key));
    }

    public static function sCard($key, callable $cb = null)
    {
        return self::connection()->sCard(self::key($key), self::wrap('SCARD', $cb, $key));
    }

    public static function sIsMember($key, $member, callable $cb = null)
    {
        return self::connection()->sIsMember(self::key($key), $member, self::wrap('SISMEMBER', $cb, $key));
    }

    /* ---------------------------------------------------------------------
     | Scripting
     --------------------------------------------------------------------- */

    /**
     * 执行 Lua 脚本（用于需要原子性的复合操作）
     *
     * @param string        $script
     * @param array         $args    KEYS + ARGV 顺序拼接
     * @param int           $numKeys KEYS 个数
     * @param callable|null $cb
     * @return mixed
     */
    public static function eval($script, array $args = array(), $numKeys = 0, callable $cb = null)
    {
        return self::connection()->eval(
            $script,
            $args,
            (int)$numKeys,
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
     * @param callable|null $cb
     * @return mixed
     */
    public static function healthCheck(callable $cb = null)
    {
        return self::connection()->exists(self::key('health:probe'), function ($result, $client = null) use ($cb) {
            $ok = ($client && method_exists($client, 'error') && $client->error() !== '') ? false : true;
            if ($cb) {
                call_user_func($cb, $ok);
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
        self::$pool   = array();
        self::$cursor = 0;
    }
}
