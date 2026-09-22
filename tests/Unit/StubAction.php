<?php
/**
 * 测试夹具：最小可用的动作处理器
 *
 * 被 ActionRunnerTest 用作「合法处理器」样本（`handler => StubAction::class`）。
 * 单独成文件是为满足 PSR-1「一个文件一个类」：原先它与 NotAnAction 同写在
 * 测试文件末尾，phpcs 报 ClassDeclaration.MultipleClasses。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Tests\Unit;

use GatewayPush\Business\ActionContext;
use GatewayPush\Business\ActionInterface;

class StubAction implements ActionInterface
{
    public function handle(ActionContext $ctx)
    {
        // 接口声明为 void：不得 return null（PHPStan: return.void）
    }
}
