<?php
/**
 * 业务动作：echo（原样回显）
 *
 * 模式：纯回执型 —— 不触碰任何业务状态，仅验证链路连通性。
 * 用途：客户端联通性自检、压力测试、排查「报文到达但无响应」类问题。
 *
 * 参数采用透传（config/actions.php 中 params = '*'）：回显动作的意义就是
 * 把客户端发来的内容原样送回，白名单过滤会使其失去验证价值。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Business\Action;

use GatewayPush\Business\ActionContext;
use GatewayPush\Business\ActionInterface;
use GatewayPush\Business\Monitor;

/**
 * 业务动作 echo：原样回显调用方参数
 *
 * 纯回执型，不触碰任何业务状态；用于联通性自检与压力测试。
 */
class EchoAction implements ActionInterface
{
    /**
     * @param ActionContext $ctx
     *
     * @return void
     */
    public function handle(ActionContext $ctx)
    {
        Monitor::incr('action_echo');

        $ctx->reply([
            'action'   => 'echo',
            'channel'  => $ctx->channel(),
            'protocol' => $ctx->protocol(),
            'params'   => $ctx->params(),
            'at'       => time(),
        ]);
    }
}
