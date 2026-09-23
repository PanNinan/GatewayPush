<?php
/**
 * Push 单元测试（纯配置面 + 目标类型归一化）
 *
 * Push 的投递链路（enqueue / dispatch / resolveTargets）依赖 Redis 异步
 * 回调与 GatewayClient，属端到端范畴由 tests/E2E/CasePush* 覆盖。
 * 本文件只覆盖零 IO 的配置与归一化逻辑：
 *   - init() 对非法 offline_mode 的保守回退（必须落 drop 并告警）；
 *   - enabled() / offlineMode() getter；
 *   - normalizeTargetType() 非法值回落 uid（经 direct 的公开路径间接测，
 *     直接反射会绑死 protected 签名，这里选行为断言）。
 *
 * 关键回归点：
 *   - 非法 offline_mode 绝不能静默透传（否则离线消息行为与配置漂移）；
 *   - 未知 target_type 回落 uid —— HTTP 层已做白名单，这里是第二道防线。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Tests\Unit;

use GatewayPush\Business\Push;
use GatewayPush\Common\Logger;
use PHPUnit\Framework\TestCase;

class PushTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private $originalConfig = [];

    /**
     * @var array<string, mixed>
     */
    private $originalQueue = [];

    /**
     * @var array<string, mixed>
     */
    private $originalUdpOut = [];

    protected function setUp(): void
    {
        Logger::init([
            'path'   => sys_get_temp_dir(),
            'level'  => Logger::ERROR,
            'role'   => 'test',
            'stdout' => false,
        ]);

        $this->originalConfig = $this->readProp('config');
        $this->originalQueue  = $this->readProp('queueConfig');
        $this->originalUdpOut = $this->readProp('udpOutConfig');

        $this->restoreDefaults();
    }

    protected function tearDown(): void
    {
        $this->writeProp('config', $this->originalConfig);
        $this->writeProp('queueConfig', $this->originalQueue);
        $this->writeProp('udpOutConfig', $this->originalUdpOut);

        Logger::init([
            'path'   => sys_get_temp_dir(),
            'level'  => Logger::ERROR,
            'role'   => 'test',
            'stdout' => false,
        ]);
    }

    /* -----------------------------------------------------------------
     | init / getter
     ----------------------------------------------------------------- */

    public function testEnabledFollowsConfigFlag(): void
    {
        Push::init(['enable' => true]);
        self::assertTrue(Push::enabled());

        Push::init(['enable' => false]);
        self::assertFalse(Push::enabled());
    }

    public function testOfflineModeQueueIsPreserved(): void
    {
        Push::init(['offline_mode' => Push::MODE_QUEUE]);

        self::assertSame(Push::MODE_QUEUE, Push::offlineMode());
    }

    public function testOfflineModeDropIsPreserved(): void
    {
        Push::init(['offline_mode' => Push::MODE_DROP]);

        self::assertSame(Push::MODE_DROP, Push::offlineMode());
    }

    public function testIllegalOfflineModeFallsBackToDrop(): void
    {
        Push::init(['offline_mode' => 'sideways']);

        self::assertSame(
            Push::MODE_DROP,
            Push::offlineMode(),
            '非法离线策略必须回退 drop —— 静默透传会让离线消息行为与配置漂移'
        );
    }

    public function testEmptyOfflineModeFallsBackToDrop(): void
    {
        Push::init(['offline_mode' => '']);

        self::assertSame(Push::MODE_DROP, Push::offlineMode());
    }

    public function testInitMergesQueueConfigWithoutClobberingDefaults(): void
    {
        Push::init([], ['max_len' => 42]);

        $queue = $this->readProp('queueConfig');
        self::assertSame(42, $queue['max_len']);
        self::assertArrayHasKey('key', $queue, '未传入的键必须保留默认值');
        self::assertArrayHasKey('batch', $queue);
    }

    public function testInitIgnoresEmptyQueueAndUdpOutArrays(): void
    {
        $beforeQueue  = $this->readProp('queueConfig');
        $beforeUdpOut = $this->readProp('udpOutConfig');

        Push::init([], [], []);

        self::assertSame($beforeQueue, $this->readProp('queueConfig'));
        self::assertSame($beforeUdpOut, $this->readProp('udpOutConfig'));
    }

    public function testInitMergesUdpOutConfig(): void
    {
        Push::init([], [], ['enable' => false]);

        $udpOut = $this->readProp('udpOutConfig');
        self::assertFalse($udpOut['enable']);
        self::assertArrayHasKey('key', $udpOut);
    }

    /* -----------------------------------------------------------------
     | 目标类型归一化（经 enqueue 的禁用路径间接断言 normalize 语义不可达，
    | 这里用 direct 前的 normalize —— direct 会走 dispatch/Redis，改为反射测纯函数）
     ----------------------------------------------------------------- */

    public function testNormalizeTargetTypeAcceptsKnownTypes(): void
    {
        $fn = $this->normalizeTargetType();

        self::assertSame('uid', $fn('uid'));
        self::assertSame('device', $fn('device'));
        self::assertSame('client', $fn('client'));
    }

    public function testNormalizeTargetTypeLowercasesAndTrims(): void
    {
        $fn = $this->normalizeTargetType();

        self::assertSame('uid', $fn('  UID  '));
        self::assertSame('device', $fn("Device\n"));
    }

    public function testNormalizeTargetTypeFallsBackToUid(): void
    {
        $fn = $this->normalizeTargetType();

        self::assertSame('uid', $fn('topic'));
        self::assertSame('uid', $fn(''));
        self::assertSame('uid', $fn('uidd'));
    }

    public function testResolveModePrefersExplicitValidOverride(): void
    {
        $fn = $this->resolveModeFn();

        Push::init(['offline_mode' => Push::MODE_QUEUE]);

        self::assertSame(Push::MODE_DROP, $fn(Push::MODE_DROP));
        self::assertSame(Push::MODE_QUEUE, $fn(Push::MODE_QUEUE));
    }

    public function testResolveModeFallsBackToGlobalWhenInvalid(): void
    {
        $fn = $this->resolveModeFn();

        Push::init(['offline_mode' => Push::MODE_QUEUE]);
        self::assertSame(Push::MODE_QUEUE, $fn(''));
        self::assertSame(Push::MODE_QUEUE, $fn('bogus'));

        Push::init(['offline_mode' => Push::MODE_DROP]);
        self::assertSame(Push::MODE_DROP, $fn('bogus'));
    }

    /* -----------------------------------------------------------------
     | 工具
     ----------------------------------------------------------------- */

    /**
     * @return void
     */
    private function restoreDefaults(): void
    {
        Push::init([
            'enable'         => true,
            'offline_mode'   => Push::MODE_QUEUE,
            'offline_ttl'    => 86400,
            'offline_max'    => 100,
            'replay_batch'   => 50,
            'idempotent'     => true,
            'idempotent_ttl' => 600,
            'payload_max'    => 4096,
        ]);
    }

    /**
     * @return callable(string): string
     */
    private function normalizeTargetType(): callable
    {
        $method = new \ReflectionMethod(Push::class, 'normalizeTargetType');
        $method->setAccessible(true);

        return static fn ($type) => $method->invoke(null, $type);
    }

    /**
     * @return callable(string): string
     */
    private function resolveModeFn(): callable
    {
        $method = new \ReflectionMethod(Push::class, 'resolveMode');
        $method->setAccessible(true);

        return static fn ($mode) => $method->invoke(null, $mode);
    }

    /**
     * @param string $name
     *
     * @return array<string, mixed>
     */
    private function readProp(string $name): array
    {
        $prop = (new \ReflectionClass(Push::class))->getProperty($name);
        $prop->setAccessible(true);

        /** @var array<string, mixed> */
        return $prop->getValue();
    }

    /**
     * @param string               $name
     * @param array<string, mixed> $value
     *
     * @return void
     */
    private function writeProp(string $name, array $value): void
    {
        $prop = (new \ReflectionClass(Push::class))->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue(null, $value);
    }
}
