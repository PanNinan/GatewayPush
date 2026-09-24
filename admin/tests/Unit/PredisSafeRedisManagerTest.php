<?php

declare(strict_types=1);

namespace tests\Unit;

use app\support\PredisSafeRedisManager;
use PHPUnit\Framework\TestCase;
use Webman\Redis\RedisManager;

/**
 * PredisSafeRedisManager 契约：closeClient 不得对无 close() 的客户端调 close()。
 *
 * 真实异常（deploy.md §13.3 踩坑1 根因）：
 * webman/redis 硬编码 `$connection->client()->close()`，
 * predis\Client 无 close() ⇒ __call → CLOSE 命令 → ClientException。
 *
 * 本测试钉 closeClient() 的分流（与 connection() 共用同一实现，不复制逻辑）。
 */
final class PredisSafeRedisManagerTest extends TestCase
{
    public function testExtendsVendorRedisManager(): void
    {
        $this->assertSame(
            RedisManager::class,
            get_parent_class(PredisSafeRedisManager::class),
            '必须继续继承 webman/redis 的 RedisManager，否则 connection()/池语义全变'
        );
    }

    public function testCloseClientUsesDisconnectForPredis(): void
    {
        $predis = new class {
            public bool $disconnected = false;

            public function disconnect(): void
            {
                $this->disconnected = true;
            }

            /**
             * 故意不定义 close()：若 closeClient 误调 close()，__call 会抛
             * 「Command CLOSE is not a registered Redis command」（模拟真实故障）。
             *
             * @param  string  $name
             * @param  array<int, mixed>  $args
             */
            public function __call(string $name, array $args): never
            {
                throw new \RuntimeException(
                    'Command ' . strtoupper($name) . ' is not a registered Redis command'
                );
            }
        };

        PredisSafeRedisManager::closeClient($predis);

        $this->assertTrue($predis->disconnected, 'predis 路径必须走 disconnect()，不得触发 close()');
    }

    public function testCloseClientUsesCloseWhenAvailable(): void
    {
        $phpredis = new class {
            public bool $closed = false;

            public function close(): void
            {
                $this->closed = true;
            }
        };

        PredisSafeRedisManager::closeClient($phpredis);

        $this->assertTrue($phpredis->closed, '有 close() 的客户端（phpredis）必须走 close()');
    }

    public function testCloseClientPrefersCloseOverDisconnect(): void
    {
        $both = new class {
            public string $called = '';

            public function close(): void
            {
                $this->called = 'close';
            }

            public function disconnect(): void
            {
                $this->called = 'disconnect';
            }
        };

        PredisSafeRedisManager::closeClient($both);

        $this->assertSame('close', $both->called, '同时有 close/disconnect 时优先 close（与 phpredis 语义一致）');
    }
}
