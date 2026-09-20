<?php
/**
 * 业务动作处理器契约
 *
 * ---------------------------------------------------------------------
 * 为什么需要它
 * ---------------------------------------------------------------------
 * 早期 action 以闭包形式注册，处理器签名是裸的 function ($clientId, $packet)：
 * 参数要自己从 $packet['data']['params'] 里掏、校验要自己写、回执要自己挑
 * 通道（WS 走 sendToClient、UDP 走出站队列），且没有任何统一约束。
 * 结果就是每个 action 各写一套，UDP 侧更因缺少执行路径而完全无法落地。
 *
 * 本接口把「处理器的输入」收敛为一个 ActionContext：
 *   - 身份与来源（clientId / uid / deviceId / protocol / channel）
 *   - 已校验并归一化的参数（ctx->params()）
 *   - 统一回执入口（ctx->reply() / replyError()），通道差异由执行器抹平
 *
 * 处理器只需关心业务本身，不需要知道自己在 WS 还是 UDP 上被调用。
 *
 * ---------------------------------------------------------------------
 * 实现约定
 * ---------------------------------------------------------------------
 * 1. 处理器的动作名与参数规则统一声明在 config/actions.php，不在代码里判断。
 * 2. 允许异步：可在 Redis 回调内调用 ctx->reply()，但必须在声明的 timeout
 *    内完成回执，否则由 ActionRunner 统一兜底（记指标 + 告警 + 错误回执）。
 * 3. 抛出异常由 ActionRunner 捕获并回 5000，处理器不必自行 try/catch。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Business;

interface ActionInterface
{
    /**
     * 执行动作
     *
     * @param ActionContext $ctx 身份、参数与回执的统一入口
     * @return void
     */
    public function handle(ActionContext $ctx);
}
