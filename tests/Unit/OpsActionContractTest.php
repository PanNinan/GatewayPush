<?php
/**
 * 运维动作声明契约测试（P4）
 *
 * ---------------------------------------------------------------------
 * 为什么要有这个文件
 * ---------------------------------------------------------------------
 * `config/actions.php` 是**声明式**接入：新增动作只需登记一行，不改任何代码。
 * 好处是接入成本低，代价是**安全约束也只是一行声明** ——
 * 运维动作（kick / revoke / unbind / purge_offline）漏写 `channels => [http]` 时：
 *
 *   - 代码照样跑、测试照样绿、启动照样成功；
 *   - 但任何持自己合法 Token 的**终端客户端**都能经 WS 通道调 `kick` 踢掉任意 clientId；
 *   - 没有任何报错或告警 —— 属**静默的终端提权**。
 *
 * 声明式配置里的安全字段不能只靠注释和 review 兜住，必须有一条硬断言。
 * 本文件就是那条断言：它读**真实配置**（不读副本），逐项钉住三条关键语义。
 *
 * ---------------------------------------------------------------------
 * 三条被钉住的语义
 * ---------------------------------------------------------------------
 * 1. **运维动作必须声明 `channels = [http]`** —— 否则 WS / UDP 侧可用（终端提权）。
 * 2. **既有动作一律不得声明 `channels`** —— 收紧既有动作会直接打断线上客户端，
 *    且是「配置笔误引发的生产事故」的典型形态。
 * 3. **`auth` 必须显式 false** —— 运维动作的 uid 是**入参**（操作对象），
 *    不是调用方身份；置 true 会要求 HTTP 调用带 uid，语义错位。
 *
 * 另核对 C5 的指标登记：`config/app.php` 的 `monitor.metrics` 必须含三个新指标名，
 * 漏登记不会报错，只是「面板与文档的指标字典与实际不一致」—— 属静默漂移。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Tests\Unit;

use GatewayPush\Business\ActionContext;
use GatewayPush\Business\ActionInterface;
use PHPUnit\Framework\TestCase;

class OpsActionContractTest extends TestCase
{
    /** 三个运维动作名 */
    private const OPS_ACTIONS = ['kick', 'revoke', 'unbind', 'purge_offline'];

    /** P4 之前既有的动作名 —— 它们必须保持「未声明 channels」才不会被打断 */
    private const LEGACY_ACTIONS = ['echo', 'session', 'report', 'subscribe', 'unsubscribe', 'topics', 'notify'];

    /**
     * @var array<string, mixed>
     */
    private array $actions = [];

    protected function setUp(): void
    {
        $cfg = require __DIR__ . '/../../config/actions.php';

        $this->actions = is_array($cfg) && isset($cfg['actions']) && is_array($cfg['actions'])
            ? $cfg['actions']
            : [];
    }

    public function testOpsActionsAreDeclared(): void
    {
        foreach (self::OPS_ACTIONS as $name) {
            $this->assertArrayHasKey($name, $this->actions, '运维动作 ' . $name . ' 未在 config/actions.php 登记');
        }
    }

    /**
     * ★ 本文件最重要的一条：漏声明 channels 即终端提权，且不报错
     */
    public function testOpsActionsAreRestrictedToHttpChannel(): void
    {
        foreach (self::OPS_ACTIONS as $name) {
            $decl = $this->actions[$name];

            $this->assertArrayHasKey(
                'channels',
                $decl,
                '★ 运维动作 ' . $name . ' 未声明 channels —— WS/UDP 侧即可调用，属终端提权'
            );
            $this->assertSame(
                [ActionContext::CHANNEL_HTTP],
                $decl['channels'],
                '★ 运维动作 ' . $name . ' 的 channels 必须是 [http]'
            );
        }
    }

    public function testOpsActionsOpenHttpChannel(): void
    {
        foreach (self::OPS_ACTIONS as $name) {
            $this->assertTrue(
                !empty($this->actions[$name]['http']),
                '运维动作 ' . $name . ' 必须开放 HTTP 通道，否则后台无从调用'
            );
        }
    }

    /**
     * 运维动作的 uid 是操作对象而非调用方身份，故不能要求鉴权身份
     */
    public function testOpsActionsDoNotRequireAuthIdentity(): void
    {
        foreach (self::OPS_ACTIONS as $name) {
            $decl = $this->actions[$name];

            $this->assertArrayHasKey('auth', $decl, $name . ' 必须**显式**声明 auth（不要依赖 defaults）');
            $this->assertFalse($decl['auth'], '运维动作 ' . $name . ' 的 auth 必须为 false');
        }
    }

    public function testOpsHandlersExistAndImplementTheInterface(): void
    {
        foreach (self::OPS_ACTIONS as $name) {
            $handler = (string)($this->actions[$name]['handler'] ?? '');

            $this->assertNotSame('', $handler, $name . ' 缺少 handler');
            $this->assertTrue(class_exists($handler), '处理器类不存在：' . $handler);
            $this->assertTrue(
                is_subclass_of($handler, ActionInterface::class),
                $handler . ' 必须实现 ' . ActionInterface::class
            );
        }
    }

    /**
     * ★ 反向护栏：既有动作一旦被加上 channels，等于收紧线上行为
     */
    public function testLegacyActionsRemainChannelAgnostic(): void
    {
        foreach (self::LEGACY_ACTIONS as $name) {
            $this->assertArrayHasKey($name, $this->actions, '既有动作 ' . $name . ' 消失了');

            $decl = $this->actions[$name];
            $this->assertArrayNotHasKey(
                'channels',
                $decl,
                '★ 既有动作 ' . $name . ' 不应声明 channels —— 声明即收紧，会打断现有客户端'
            );
        }
    }

    /**
     * C5：指标登记。漏登记不报错，只是面板与文档的指标字典静默漂移
     */
    public function testMetricsAreRegisteredForOpsActions(): void
    {
        $cfg = require __DIR__ . '/../../config/app.php';

        $metrics = $cfg['monitor']['metrics'] ?? null;
        $this->assertIsArray($metrics, 'config/app.php 的 monitor.metrics 缺失');

        foreach (self::OPS_ACTIONS as $name) {
            $this->assertContains(
                'action_' . $name,
                $metrics,
                '指标 action_' . $name . ' 未登记进 monitor.metrics —— 面板字典会与实际不一致'
            );
        }
    }

    /**
     * 每个运维动作声明的参数键，必须与处理器实际读取的键一致
     *
     * 声明与实现漂移的表现是「参数明明传了却被当成缺省值」，
     * 而 ParamValidator 只会校验**声明过的**键 —— 传错的键被静默丢弃。
     */
    public function testDeclaredParamsMatchHandlerSource(): void
    {
        $expect = [
            'kick'          => ['client_id', 'uid', 'reason'],
            'revoke'        => ['token', 'ttl'],
            'unbind'        => ['uid'],
            'purge_offline' => ['uid'],
        ];

        foreach ($expect as $name => $keys) {
            $params = $this->actions[$name]['params'] ?? null;
            $this->assertIsArray($params, $name . ' 的 params 必须是数组');

            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $params, $name . ' 未声明参数 ' . $key);

                $src = (string)file_get_contents($this->handlerPath($name));
                $this->assertStringContainsString(
                    "param('" . $key . "'",
                    $src,
                    $name . ' 声明了参数 ' . $key . '，但处理器里读不到 —— 传了也会被静默丢弃'
                );
            }
        }
    }

    private function handlerPath(string $action): string
    {
        return __DIR__ . '/../../src/Business/Action/' . [
            'kick'          => 'KickAction.php',
            'revoke'        => 'RevokeTokenAction.php',
            'unbind'        => 'UnbindDeviceAction.php',
            'purge_offline' => 'PurgeOfflineAction.php',
        ][$action];
    }
}
