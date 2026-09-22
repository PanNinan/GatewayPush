<?php
/**
 * 指令路由注册表
 *
 * ---------------------------------------------------------------------
 * 为什么需要它
 * ---------------------------------------------------------------------
 * P0/P1 早期阶段，onMessage 采用「硬编码 switch(cmd)」分发，data 指令是空壳。
 * 随着业务指令增多，集中式 switch 会持续膨胀且无法由业务模块自行注册处理器。
 * 本类把分发点改为「注册表 + 查表」两级结构：
 *
 *   一级（cmd）         auth / ping / data / ack ...
 *   二级（data.action） echo / session / ...
 *
 * 分发流程与错误处理仍由 Bootstrap 负责（便于统一计指标与回执），
 * 本类只提供纯粹的注册 / 查询能力，不做任何 IO 与日志，保持零副作用。
 *
 * ---------------------------------------------------------------------
 * 注册示例
 * ---------------------------------------------------------------------
 *   // 一级：新增一类指令
 *   Router::registerCommand('sync', function ($clientId, array $packet) { ... });
 *
 *   // 二级：为 data 指令新增一个业务动作
 *   Router::registerAction('order', function ($clientId, array $packet) { ... });
 *
 * 处理器约定：function (string $clientId, array $packet): void
 *   - 回执通过 Bootstrap::respond() / Bootstrap::respondError() 下发；
 *   - 允许异步（Redis 回调后再回执），Bootstrap 不对此设同步约束。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Business;

/**
 * 指令路由注册表（cmd → data.action 两级）
 *
 * 只提供注册与查询，零 IO、零日志；分发与错误处理仍由 Bootstrap 负责。
 */
class Router
{
    /**
     * 一级路由表：cmd => callable
     *
     * @var array<string, mixed>
     */
    protected static $commands = [];

    /**
     * 二级路由表：action => callable
     *
     * @var array<string, mixed>
     */
    protected static $actions = [];

    /* ---------------------------------------------------------------------
     | 注册
     --------------------------------------------------------------------- */

    /**
     * 注册一级指令处理器
     *
     * @param string   $cmd
     * @param callable $handler function (string $clientId, array $packet): void
     *
     * @return void
     */
    public static function registerCommand($cmd, callable $handler)
    {
        $cmd = (string)$cmd;
        if ($cmd === '') {
            return;
        }
        self::$commands[$cmd] = $handler;
    }

    /**
     * 注册 data 指令的业务动作处理器
     *
     * @param string   $action
     * @param callable $handler function (string $clientId, array $packet): void
     *
     * @return void
     */
    public static function registerAction($action, callable $handler)
    {
        $action = (string)$action;
        if ($action === '') {
            return;
        }
        self::$actions[$action] = $handler;
    }

    /* ---------------------------------------------------------------------
     | 查询
     --------------------------------------------------------------------- */

    /**
     * 取一级指令处理器
     *
     * @param string $cmd
     *
     * @return null|callable 未注册返回 null
     */
    public static function command($cmd)
    {
        $cmd = (string)$cmd;

        return self::$commands[$cmd] ?? null;
    }

    /**
     * 取二级业务动作处理器
     *
     * @param string $action
     *
     * @return null|callable 未注册返回 null
     */
    public static function action($action)
    {
        $action = (string)$action;

        return self::$actions[$action] ?? null;
    }

    /**
     * 已注册的指令列表
     *
     * @return list<string>
     */
    public static function commands()
    {
        return array_keys(self::$commands);
    }

    /**
     * 已注册的业务动作列表
     *
     * @return list<string>
     */
    public static function actions()
    {
        return array_keys(self::$actions);
    }

    /**
     * 是否存在指定一级指令
     *
     * @param string $cmd
     *
     * @return bool
     */
    public static function hasCommand($cmd)
    {
        return isset(self::$commands[(string)$cmd]);
    }

    /**
     * 是否存在指定业务动作
     *
     * @param string $action
     *
     * @return bool
     */
    public static function hasAction($action)
    {
        return isset(self::$actions[(string)$action]);
    }
}
