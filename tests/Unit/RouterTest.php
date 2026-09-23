<?php
/**
 * Router 单元测试（纯注册表，零 IO）
 *
 * Router 是 Bootstrap 分发链路上唯一「可被业务模块自行扩展」的接缝：
 * registerCommand / registerAction 注册、command / action 查询、
 * has* / *s 列举。全部是静态数组读写，无网络、无定时器，属标准单测面。
 *
 * 关键回归点：
 *   - 空字符串 cmd/action 必须被拒（否则路由表可被空键污染）；
 *   - 同名重注册必须覆盖（热更新 / 重复引导场景）；
 *   - 未注册查询必须返回 null（Bootstrap 据此回 CODE_UNKNOWN_CMD）。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Tests\Unit;

use GatewayPush\Business\Router;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetRouter();
    }

    protected function tearDown(): void
    {
        $this->resetRouter();
    }

    public function testRegisterAndQueryCommand(): void
    {
        $handler = static function (): void {};
        Router::registerCommand('ping', $handler);

        self::assertSame($handler, Router::command('ping'));
        self::assertTrue(Router::hasCommand('ping'));
    }

    public function testEmptyCommandKeyIsRejected(): void
    {
        Router::registerCommand('', static function (): void {});

        self::assertFalse(Router::hasCommand(''));
        self::assertNull(Router::command(''));
        self::assertSame([], Router::commands());
    }

    public function testWhitespaceCommandKeyIsCastNotEmpty(): void
    {
        // (string)' ' 非空，应被接受 —— 钉住「只判 === ''」而非 trim
        Router::registerCommand(' ', static function (): void {});

        self::assertTrue(Router::hasCommand(' '));
    }

    public function testUnknownCommandReturnsNull(): void
    {
        self::assertNull(Router::command('nope'));
        self::assertFalse(Router::hasCommand('nope'));
    }

    public function testReRegisterOverwritesHandler(): void
    {
        $first  = static function (): void {};
        $second = static function (): void {};

        Router::registerCommand('dup', $first);
        Router::registerCommand('dup', $second);

        self::assertSame($second, Router::command('dup'));
        self::assertSame(['dup'], Router::commands(), '重注册不得产生重复键');
    }

    public function testNumericStringCommandKeyIsPreserved(): void
    {
        // 钉住「只判 === ''」—— 数字串键合法且原样保留（不 trim、不折叠）
        Router::registerCommand('0', static function (): void {});

        self::assertTrue(Router::hasCommand('0'));
        self::assertNotNull(Router::command('0'));
    }

    public function testRegisterAndQueryAction(): void
    {
        $handler = static function (): void {};
        Router::registerAction('echo', $handler);

        self::assertSame($handler, Router::action('echo'));
        self::assertTrue(Router::hasAction('echo'));
    }

    public function testEmptyActionKeyIsRejected(): void
    {
        Router::registerAction('', static function (): void {});

        self::assertFalse(Router::hasAction(''));
        self::assertNull(Router::action(''));
        self::assertSame([], Router::actions());
    }

    public function testUnknownActionReturnsNull(): void
    {
        self::assertNull(Router::action('missing'));
        self::assertFalse(Router::hasAction('missing'));
    }

    public function testCommandAndActionTablesAreIndependent(): void
    {
        $handler = static function (): void {};
        Router::registerCommand('data', $handler);
        Router::registerAction('data', $handler);

        self::assertTrue(Router::hasCommand('data'));
        self::assertTrue(Router::hasAction('data'));

        // 清空其中一侧不影响另一侧
        $this->resetRouter(['actions']);

        self::assertTrue(Router::hasCommand('data'));
        self::assertFalse(Router::hasAction('data'));
    }

    public function testListMethodsReturnKeysInInsertionOrder(): void
    {
        Router::registerCommand('a', static function (): void {});
        Router::registerCommand('b', static function (): void {});
        Router::registerAction('x', static function (): void {});
        Router::registerAction('y', static function (): void {});

        self::assertSame(['a', 'b'], Router::commands());
        self::assertSame(['x', 'y'], Router::actions());
    }

    /* -----------------------------------------------------------------
     | 工具：静态路由表无公开 reset，用反射清空（仅测试内使用）
     ----------------------------------------------------------------- */

    /**
     * @param array<int, string> $only 仅清空指定表（默认两表都清）
     *
     * @return void
     */
    private function resetRouter(array $only = ['commands', 'actions']): void
    {
        $ref = new \ReflectionClass(Router::class);
        foreach ($only as $name) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue(null, []);
        }
    }
}
