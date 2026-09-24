<?php
/**
 * admin · 引导 —— RedisBootstrap。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace app\bootstrap;

use app\support\PredisSafeRedisManager;
use support\Redis;
use Webman\Bootstrap;
use Workerman\Worker;

/**
 * 每个 worker 启动时把 support\Redis 的管理器换成 Predis-safe 子类。
 *
 * 为什么必须在 Bootstrap 而不是首次调用时懒替换：
 * - webman/redis 的 RedisManager::connection() 硬编码 client()->close()，
 *   predis 无 close() ⇒ __call 当成 CLOSE 命令 ⇒ idle 清理时刷异常堆栈
 *   （deploy.md §13.3 踩坑1 的根因侧）。
 * - metric-sampler 等自定义进程也会走 support\Redis；Bootstrap::start()
 *   在每个相关 worker 启动时执行（含自定义进程），早于任何取连接。
 *
 * 幂等：只在 instance 尚未创建、或仍是父类 RedisManager 时替换；
 * 已是本类则不动。构造参数与 vendor support\Redis::instance() 对齐
 * （$app 传空串，非 Laravel 容器）。
 */
class RedisBootstrap implements Bootstrap
{
    /**
     * @param null|Worker $worker
     *
     * @return void
     */
    public static function start(?Worker $worker)
    {
        unset($worker);

        $current = Redis::instance();
        if ($current instanceof PredisSafeRedisManager) {
            return;
        }

        $config = config('redis');
        $client = $config['client'] ?? Redis::PHPREDIS_CLIENT;
        if (!in_array($client, Redis::$allowClient, true)) {
            $client = Redis::PHPREDIS_CLIENT;
        }

        $manager = new PredisSafeRedisManager('', $client, $config);

        // 用反射写回 support\Redis::$instance（protected static），避免改 vendor。
        $ref = new \ReflectionProperty(Redis::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $manager);
    }
}
