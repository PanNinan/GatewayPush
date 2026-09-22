<?php
/**
 * RedisKeys 单元测试（纯函数层）
 *
 * 本测试的核心是**金标断言**：把每个键的最终字符串逐字写死。
 *
 * 为什么值得为「一堆常量」写测试：
 *   键名是对外契约的一部分 —— Redis 里存着在线的会话、挂起的离线消息、
 *   已撤销的 Token 名单，其他进程（Api / Gateway / Business / Dashboard）
 *   也各自按名读写同一批键。键名一改，这些数据当场全部失配，且失败形态
 *   分散且难定位（会话查不到、队列空转、撤销名单复活）。
 *
 *   所以金标失败不是「测试太严」，而是刻意设计的拦截：改键必须是一次清醒的
 *   决定（配 RENAME 迁移脚本 + 全角色重启），而不是顺手改个名。
 *
 * 其余断言覆盖三处易错约定：
 *   1. 「前缀」与「完整键」的尾部分隔符差异（`online:` vs `online:clients`）
 *   2. 四条队列键互不相同 —— 跨进程生产者 / 消费者指向同一键的不变量
 *   3. 动态后缀的编码方式（md5 / 日期 / 分钟槽）不被悄悄改掉
 *
 * 全部为零 IO 的纯计算，不进 Redis。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Tests\Unit;

use GatewayPush\Common\RedisKeys;
use PHPUnit\Framework\TestCase;

class RedisKeysTest extends TestCase
{
    /* =====================================================================
     | 金标：静态键
     ===================================================================== */

    /**
     * @dataProvider staticKeyProvider
     */
    public function testStaticKeyGolden(string $const, string $expected): void
    {
        $this->assertSame(
            $expected,
            constant(RedisKeys::class . '::' . $const),
            '键 ' . $const . ' 的字面量已变更 —— 若确需修改，必须同时提供 RENAME 迁移脚本'
        );
    }

    /**
     * 全部静态键的金标值
     *
     * 新增键时在此补一行；修改既有键值须先确认迁移方案。
     *
     * @return array<string, array{0:string, 1:string}>
     */
    public function staticKeyProvider(): array
    {
        return array(
            // 会话与索引
            'SESSION'         => array('SESSION', 'session:'),
            'HEARTBEAT'       => array('HEARTBEAT', 'heartbeat:'),
            'UID_CLIENTS'     => array('UID_CLIENTS', 'uid:clients:'),
            'DEVICE_CLIENT'   => array('DEVICE_CLIENT', 'device:client:'),
            'ONLINE_CLIENTS'  => array('ONLINE_CLIENTS', 'online:clients'),
            'ONLINE_PREFIX'   => array('ONLINE_PREFIX', 'online:'),

            // 鉴权
            'AUTH_REVOKED'    => array('AUTH_REVOKED', 'auth:revoked:'),
            'AUTH_BIND'       => array('AUTH_BIND', 'auth:bind:'),

            // 队列（跨进程共享，键名一致性由常量保证）
            'QUEUE_UDP_IN'    => array('QUEUE_UDP_IN', 'queue:udp:in'),
            'QUEUE_ACTION_IN' => array('QUEUE_ACTION_IN', 'queue:action:in'),
            'QUEUE_UDP_OUT'   => array('QUEUE_UDP_OUT', 'queue:udp:out'),
            'QUEUE_PUSH_OUT'  => array('QUEUE_PUSH_OUT', 'queue:push:out'),

            // 推送
            'PUSH_OFFLINE'    => array('PUSH_OFFLINE', 'push:offline:'),
            'PUSH_DEDUP'      => array('PUSH_DEDUP', 'push:dedup:'),

            // 订阅
            'SUBSCRIBE_UID'   => array('SUBSCRIBE_UID', 'subscribe:uid:'),
            'SUBSCRIBE_TOPIC' => array('SUBSCRIBE_TOPIC', 'subscribe:topic:'),

            // 动作
            'ACTION_RESULT'   => array('ACTION_RESULT', 'action:result:'),
            'ACTION_REPORT'   => array('ACTION_REPORT', 'action:report:'),

            // 指标
            'METRICS_COUNTER' => array('METRICS_COUNTER', 'metrics:counter:'),
            'METRICS_GAUGE'   => array('METRICS_GAUGE', 'metrics:gauge'),

            // 限流
            'RATE_LIMIT_BUCKET' => array('RATE_LIMIT_BUCKET', 'rl:'),
            'RATE_LIMIT_API'    => array('RATE_LIMIT_API', 'api:rate:'),

            // 运维
            'HEALTH_PROBE'    => array('HEALTH_PROBE', 'health:probe'),
        );
    }

    /* =====================================================================
     | 金标：动态键
     ===================================================================== */

    /**
     * @dataProvider dynamicKeyProvider
     */
    public function testDynamicKeyGolden(string $method, array $args, string $expected): void
    {
        // 经 callable 变量而非 RedisKeys::$method() 直调：后者会触发
        // phpstan-strict-rules 的 staticMethod.dynamicName（变量静态方法名）。
        /** @var callable $factory */
        $factory = [RedisKeys::class, $method];

        $this->assertSame(
            $expected,
            $factory(...$args),
            'RedisKeys::' . $method . '() 的产出已变更'
        );
    }

    /**
     * 全部构造方法的金标产出
     *
     * @return array<string, array{0:string, 1:array, 2:string}>
     */
    public function dynamicKeyProvider(): array
    {
        return array(
            'session'        => array('session', array('c-1'), 'session:c-1'),
            'heartbeat'      => array('heartbeat', array('c-1'), 'heartbeat:c-1'),
            'uidClients'     => array('uidClients', array('u-1'), 'uid:clients:u-1'),
            'deviceClient'   => array('deviceClient', array('d-1'), 'device:client:d-1'),
            'online 全量'    => array('online', array(''), 'online:clients'),
            'online ws'      => array('online', array('ws'), 'online:ws'),
            'online udp'     => array('online', array('udp'), 'online:udp'),
            'authRevoked'    => array('authRevoked', array('a1b2c3'), 'auth:revoked:a1b2c3'),
            'authBind'       => array('authBind', array('u-1'), 'auth:bind:u-1'),
            'pushOffline'    => array('pushOffline', array('u-1'), 'push:offline:u-1'),
            'pushDedup'      => array('pushDedup', array('m-1'), 'push:dedup:' . md5('m-1')),
            'subscribeUid'   => array('subscribeUid', array('u-1'), 'subscribe:uid:u-1'),
            'subscribeTopic' => array('subscribeTopic', array('news'), 'subscribe:topic:news'),
            'actionResult'   => array('actionResult', array('r-1'), 'action:result:r-1'),
            'actionReport'   => array('actionReport', array('sys'), 'action:report:sys'),
            'rateBucket uid' => array('rateBucket', array('uid', 'u-1'), 'rl:uid:' . md5('u-1')),
            'rateBucket ip'  => array('rateBucket', array('ip', '1.2.3.4'), 'rl:ip:' . md5('1.2.3.4')),
        );
    }

    /* =====================================================================
     | 约定：前缀 vs 完整键
     ===================================================================== */

    /**
     * 需要拼接后缀的键必须以 `:` 结尾；本身即完整键的不得以 `:` 结尾。
     *
     * 这条约定若被破坏，`online('')` 与 `ONLINE_PREFIX . 'ws'` 这类拼接会
     * 悄然产出多一个冒号或少一个冒号的键，表现为「数据写进去了但读不到」。
     */
    public function testPrefixAndCompleteKeyConventions(): void
    {
        $needsSuffix = array(
            'SESSION', 'HEARTBEAT', 'UID_CLIENTS', 'DEVICE_CLIENT', 'ONLINE_PREFIX',
            'AUTH_REVOKED', 'AUTH_BIND', 'PUSH_OFFLINE', 'PUSH_DEDUP',
            'SUBSCRIBE_UID', 'SUBSCRIBE_TOPIC', 'ACTION_RESULT', 'ACTION_REPORT',
            'METRICS_COUNTER', 'RATE_LIMIT_BUCKET', 'RATE_LIMIT_API',
        );
        foreach ($needsSuffix as $const) {
            $this->assertStringEndsWith(':', constant(RedisKeys::class . '::' . $const), $const . ' 是前缀，应以冒号结尾');
        }

        $complete = array(
            'ONLINE_CLIENTS', 'METRICS_GAUGE', 'HEALTH_PROBE',
            'QUEUE_UDP_IN', 'QUEUE_ACTION_IN', 'QUEUE_UDP_OUT', 'QUEUE_PUSH_OUT',
        );
        foreach ($complete as $const) {
            $this->assertStringEndsNotWith(':', constant(RedisKeys::class . '::' . $const), $const . ' 是完整键，不应以冒号结尾');
        }
    }

    /* =====================================================================
     | 不变量：队列键互不相同
     ===================================================================== */

    /**
     * 四条队列键必须两两不同。
     *
     * 这是跨进程最强的一条不变量：Api 入队 / BusinessWorker 消费 / UDP 网关
     * 收发分别在不同进程按名读写，任意两条重复都会造成「请求成功但永不执行」
     * 或「消息投递到错误通道」这类无日志故障。统一到常量后，这里再加一道回归。
     */
    public function testQueueKeysAreDistinct(): void
    {
        $keys = array(
            RedisKeys::QUEUE_UDP_IN,
            RedisKeys::QUEUE_ACTION_IN,
            RedisKeys::QUEUE_UDP_OUT,
            RedisKeys::QUEUE_PUSH_OUT,
        );

        $this->assertSame($keys, array_values(array_unique($keys)), '队列键出现重复');
        $this->assertCount(4, array_unique($keys));
    }

    /* =====================================================================
     | 不变量：两套限流不共用键
     ===================================================================== */

    /**
     * L2 令牌桶（WS / UDP 侧）与 HTTP 分钟窗口是两套独立限流，键空间不得重叠。
     *
     * 二者同为「限流」语义但算法与配额完全不同，若键碰撞，一方的扣减会
     * 直接吃掉另一方的配额，表现为随机 429。
     */
    public function testRateLimitKeySpacesDoNotCollide(): void
    {
        $bucket = RedisKeys::rateBucket('uid', 'u-1');
        $api    = RedisKeys::rateApi('u-1');

        $this->assertNotSame($bucket, $api);
        $this->assertStringStartsWith('rl:', $bucket);
        $this->assertStringStartsWith('api:rate:', $api);
    }

    /* =====================================================================
     | 动态后缀的编码方式
     ===================================================================== */

    /**
     * 累加指标的日期后缀
     */
    public function testMetricsCounterDateSuffix(): void
    {
        $this->assertSame('metrics:counter:20260921', RedisKeys::metricsCounter('20260921'));

        // null 与空串均回落当日
        $today = 'metrics:counter:' . date('Ymd');
        $this->assertSame($today, RedisKeys::metricsCounter());
        $this->assertSame($today, RedisKeys::metricsCounter(''));
    }

    /**
     * HTTP 限流的分钟槽
     */
    public function testRateApiMinuteSlot(): void
    {
        $this->assertSame(
            'api:rate:' . md5('1.2.3.4') . ':29833050',
            RedisKeys::rateApi('1.2.3.4', 29833050)
        );

        // 缺省取当前分钟，与 Api 侧原来的 floor(time() / 60) 口径一致
        $this->assertSame(
            'api:rate:' . md5('1.2.3.4') . ':' . (int)floor(time() / 60),
            RedisKeys::rateApi('1.2.3.4')
        );
    }

    /**
     * md5 压缩后缀（去重键 / 限流桶）与手工计算一致
     */
    public function testMd5SuffixMatchesManualComputation(): void
    {
        $this->assertSame('push:dedup:' . md5('msg-42'), RedisKeys::pushDedup('msg-42'));
        $this->assertSame('rl:conn:' . md5('client-7'), RedisKeys::rateBucket('conn', 'client-7'));

        // md5 恒为 32 位十六进制，去掉前缀后长度可预期
        $this->assertSame(32, strlen(substr(RedisKeys::pushDedup('x'), strlen(RedisKeys::PUSH_DEDUP))));
    }

    /* =====================================================================
     | 同族键的区分
     ===================================================================== */

    /**
     * 同一命名空间下的兄弟键必须产出不同结果。
     *
     * `online('')` 与 `online('ws')` 共用 ONLINE_PREFIX 家族但语义不同：
     * 前者是全量集合、后者是协议维度集合。二者相等会导致在线数统计翻倍或漏算。
     */
    public function testSiblingKeysAreDistinct(): void
    {
        $all = RedisKeys::online('');
        $ws  = RedisKeys::online('ws');
        $udp = RedisKeys::online('udp');

        $this->assertSame(RedisKeys::ONLINE_CLIENTS, $all);
        $this->assertNotSame($all, $ws);
        $this->assertNotSame($ws, $udp);
        $this->assertNotSame($all, $udp);
    }

    /* =====================================================================
     | 字符集
     ===================================================================== */

    /**
     * 键名只允许小写字母 / 数字 / 冒号 / 下划线 / 点 / 连字符。
     *
     * 限制来源有二：一是其他进程按名读写，键名里出现空格或非 ASCII 极易在
     * 排查时被肉眼忽略；二是键名会出现在日志与面板中，保持字符集受限便于检索。
     */
    public function testKeysUseRestrictedCharset(): void
    {
        $keys = [];
        foreach ($this->staticKeyProvider() as $row) {
            $keys[$row[0]] = constant(RedisKeys::class . '::' . $row[0]);
        }

        // 动态键取一组代表性样本（含 md5 / 日期 / 分钟槽三种后缀）
        $keys['session()']       = RedisKeys::session('c-1');
        $keys['pushDedup()']     = RedisKeys::pushDedup('m-1');
        $keys['metricsCounter()'] = RedisKeys::metricsCounter('20260921');
        $keys['rateApi()']       = RedisKeys::rateApi('1.2.3.4', 29833050);
        $keys['rateBucket()']    = RedisKeys::rateBucket('uid', 'u-1');

        foreach ($keys as $label => $key) {
            $this->assertMatchesRegularExpression(
                '/^[a-z0-9:_.\-]+$/',
                $key,
                $label . ' 含受限字符：' . $key
            );
        }
    }
}
