<?php
/**
 * 测试夹具：未实现 ActionInterface 的普通类
 *
 * 被 ActionRunnerTest 用作「已声明 handler 但处理器不合规」的样本，
 * 用于验证装载阶段的过滤逻辑会将其丢弃。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Tests\Unit;

class NotAnAction {}
