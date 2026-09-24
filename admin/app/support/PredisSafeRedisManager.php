<?php

declare(strict_types=1);

namespace app\support;

use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Connections\Connection;
use Predis\Client as PredisClient;
use StdClass;
use Throwable;
use WeakMap;
use Webman\Context;
use Webman\Redis\RedisManager;
use Workerman\Coroutine\Pool;

/**
 * Predis-safe RedisManager：覆盖 webman/redis 的连接池 closer。
 *
 * 背景（deploy.md §13.3 踩坑1 的根因侧）：
 * - webman/redis 的 RedisManager::connection() 硬编码 `$connection->client()->close()`；
 * - phpredis 才有 close()；predis\Client 只有 disconnect()，
 *   __call('close') 会把它当成 Redis 命令 CLOSE ⇒ ClientException；
 * - idle_timeout 到期后 Pool::checkConnections() → closeConnection() → 抛异常刷日志。
 *
 * 修法：子类 override connection()，closer 改为 closeClient() ——
 * 按客户端类型分流：有 close() 的走 close()（phpredis）；
 * predis 走 disconnect()；其余有 disconnect() 的兜底 disconnect()。
 *
 * 接线：RedisBootstrap::start() 在每个 worker 启动时把 support\Redis::$instance
 * 替换成本类，保证 metric-sampler 等自定义进程在首次取连接前就拿到安全 closer。
 */
class PredisSafeRedisManager extends RedisManager
{
    /**
     * Context 缓存连接的最小探活间隔（秒）。
     *
     * 自定义进程（metric-sampler）无请求生命周期：连接钉在 non-fiber Context 里，
     * 池的 idle_timeout/heartbeat 碰不到它；对端断开后下一次采样必炸 10054。
     * 限频探活：每个 Context 命中最多每 30s PING 一次，死了立即还池关闭并换新。
     * HTTP 侧 Context 多随请求销毁，探活开销可忽略。
     */
    private const PROBE_TTL = 30;

    /**
     * @var WeakMap<object, bool>
     */
    protected WeakMap $allConnections;

    /**
     * 与 vendor support\Redis::instance() 相同：webman 不传 Laravel 容器，传空串。
     *
     * $this->app 故意不赋值：Illuminate 仅在 events=true 时读它（configure()），
     * 本项目走本类 connection() 覆写（连接创建时直接 setEventDispatcher），
     * events 恒为 false，读不到 $app。
     *
     * @param  string  $app  占位，与 vendor 的 '' 对齐；不写入 $this->app
     * @param  string  $driver
     * @param  array<string, mixed>  $config
     */
    public function __construct(string $app, string $driver, array $config)
    {
        unset($app);
        // 不调 parent::__construct：其 @param 要求 Application，与 webman 的 '' 冲突。
        // 逐字段赋值与 Illuminate\Redis\RedisManager::__construct 等价（除 $app）。
        $this->driver = $driver;
        $this->config = $config;
    }

    /**
     * 关闭底层客户端：按能力分流，**不得**对无 close() 的客户端调 close()。
     *
     * 供连接池 closer 与单测共用，避免两份逻辑漂移。
     *
     * @param  object  $client  phpredis \Redis 或 predis\Client（或其它带 close/disconnect 的对象）
     * @return void
     */
    public static function closeClient(object $client): void
    {
        if (method_exists($client, 'close')) {
            $client->close();
            return;
        }
        if ($client instanceof PredisClient) {
            $client->disconnect();
            return;
        }
        if (method_exists($client, 'disconnect')) {
            $client->disconnect();
            return;
        }
        // 最后手段：与父类行为一致（会走 __call；正常客户端到不了这里）。
        $client->close();
    }

    /**
     * Get connection.
     *
     * @param  string|null  $name
     * @return Connection|mixed|StdClass|null
     * @throws Throwable
     */
    public function connection($name = null)
    {
        $name = $name ?: 'default';
        $key = "redis.connections.$name";
        $connection = Context::get($key);
        if ($connection) {
            $connection = $this->probeCached($connection, $key, $name);
        }
        if (!$connection) {
            if (!isset(static::$pools[$name])) {
                $poolConfig = $this->config[$name]['pool'] ?? [];
                $pool = new Pool($poolConfig['max_connections'] ?? 10, $poolConfig);
                $pool->setConnectionCreator(function () use ($name) {
                    $connection = $this->configure($this->resolve($name), $name);
                    if (class_exists(Dispatcher::class)) {
                        $connection->setEventDispatcher(new Dispatcher());
                    }
                    $this->allConnections ??= new WeakMap();
                    $this->allConnections[$connection] = true;
                    return $connection;
                });
                // 与父类唯一差异：走 closeClient()，predis 不再触发 CLOSE 命令。
                $pool->setConnectionCloser(static function ($connection): void {
                    self::closeClient($connection->client());
                });
                $pool->setHeartbeatChecker(function ($connection) {
                    $connection->get('PING');
                });
                static::$pools[$name] = $pool;
            }
            try {
                $connection = static::$pools[$name]->get();
                Context::set($key, $connection);
                Context::set($key . '.probed_at', time());
            } finally {
                Context::onDestroy(function () use ($connection, $name) {
                    try {
                        $connection && static::$pools[$name]->put($connection);
                    } catch (Throwable) {
                        // ignore
                    }
                });
            }
        }
        return $connection;
    }

    /**
     * 对 Context 缓存的连接做限频探活；死了就还池关闭，返回 null 让调用方换新。
     *
     * @param  object  $connection  Illuminate Connection（或等价对象）
     * @param  string  $key  Context 键（redis.connections.{name}）
     * @param  string  $name  池名
     * @return object|null  活连接原样返回；死连接返回 null 并已回收
     */
    private function probeCached(object $connection, string $key, string $name): ?object
    {
        $probedAt = (int)Context::get($key . '.probed_at', 0);
        if (time() - $probedAt < self::PROBE_TTL) {
            return $connection;
        }
        Context::set($key . '.probed_at', time());
        try {
            // 必须是 PING 命令：get('PING') 是 GET 键，死连接同样会抛，但语义含糊。
            $connection->ping();
            return $connection;
        } catch (Throwable) {
            try {
                if (isset(static::$pools[$name])) {
                    static::$pools[$name]->closeConnection($connection);
                }
            } catch (Throwable) {
                // already gone / never registered
            }
            Context::set($key, null);
            return null;
        }
    }

    /**
     * Return all the created connections.
     *
     * @return array<int, mixed>
     */
    public function connections()
    {
        if (empty($this->allConnections)) {
            return [];
        }
        $connections = [];
        foreach ($this->allConnections as $connection => $_) {
            $connections[] = $connection;
        }
        return $connections;
    }
}
