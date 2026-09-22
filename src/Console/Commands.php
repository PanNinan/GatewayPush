<?php
/**
 * 只读与配置类子命令
 *
 * 这三个命令既不进入 workerman 主流程、也不依赖事件循环，故集中在一处：
 *   roles     输出各角色启用清单（JSON），供管理脚本在启动前跳过被关闭的角色
 *   env:init  生成 / 补齐 .env（首次部署必执行）
 *   usage     打印使用说明
 *
 * 与 PushCommand 分工：push 需要 workerman 事件循环（Redis 入队是异步的），单独成类。
 *
 * 本类不调用 exit —— 退出码经返回值交给入口 start.php，以守住「src/ 内零 exit」的约束。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Console;

use GatewayPush\Common\Env;
use GatewayPush\Common\RoleCatalog;

/**
 * 只读与配置类子命令：roles / env:init / usage
 *
 * 不进入 workerman 主流程；不调用 exit，退出码经返回值交给入口 start.php。
 */
final class Commands
{
    /**
     * 角色启用清单（JSON）
     *
     * 输出契约（roles 命令的对外格式，消费方按字段名读取，不解析人读文案）：
     *   {"roles":[{"role":"register","enabled":true,"env":"REGISTER_ENABLE"}, ...]}
     *
     * env 为「关闭该角色的环境变量键名」，业务进程没有独立开关故为空串 ——
     * 该字段仅供消费方在提示语中引用，真值判断一律以 enabled 为准。
     *
     * 真值必须取自 PHP 侧配置而非脚本自行解析 .env：.env < .env.{env} < .env.local
     * < .env.{env}.local < 真实环境变量的叠加语义只有 Env 类能正确还原，脚本再实现
     * 一遍必然漂移。
     *
     * @param array $gatewayConfig  config/gateway.php
     * @param array $businessConfig config/business.php
     * @param array $appConfig      config/app.php
     *
     * @return string 合法 JSON；极端编码失败时退化为空清单而非非法输出
     */
    public static function roles(array $gatewayConfig, array $businessConfig, array $appConfig)
    {
        $items = [];
        foreach (RoleCatalog::build(
            $gatewayConfig,
            $businessConfig,
            $appConfig,
            DIRECTORY_SEPARATOR === '/'
        ) as $role => $service) {
            $items[] = [
                'role'    => $role,
                'enabled' => !empty($service['enable']),
                'env'     => (string)$service['env'],
            ];
        }

        $json = json_encode(['roles' => $items], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : '{"roles":[]}';
    }

    /**
     * 初始化 .env 配置文件
     *
     * 文件不存在：从 .env.example 复制，并为密钥项注入随机值
     * 文件已存在：仅补齐仍为空的密钥项，绝不覆盖已有取值
     *
     * @param string $basePath
     *
     * @return int 退出码
     */
    public static function envInit($basePath)
    {
        $target  = $basePath . '/.env';
        $example = $basePath . '/.env.example';

        if (!is_file($example)) {
            fwrite(STDERR, '[FATAL] 缺少模板文件：' . $example . "\n");

            return 1;
        }

        $template = @file_get_contents($example);
        if (!is_string($template) || $template === '') {
            fwrite(STDERR, '[FATAL] 模板文件不可读：' . $example . "\n");

            return 1;
        }

        // 需要自动注入强度的密钥项
        $secretKeys = ['AUTH_SECRET', 'INTERNAL_SECRET'];

        if (!is_file($target)) {
            foreach ($secretKeys as $key) {
                $template = (string)preg_replace(
                    '/^[ \t]*' . preg_quote($key, '/') . '[ \t]*=[ \t]*$/m',
                    $key . '=' . Env::generateSecret(),
                    $template
                );
            }

            if (@file_put_contents($target, $template) === false) {
                fwrite(STDERR, '[FATAL] 写入失败：' . $target . "\n");

                return 1;
            }

            echo '已生成配置文件：' . $target . "\n";
            echo '  随机注入：' . implode(' , ', $secretKeys) . "\n";
            echo "\n下一步：php start.php check\n";

            return 0;
        }

        // 已存在：仅补齐空值密钥，不触碰已有取值
        $current = @file_get_contents($target);
        if (!is_string($current)) {
            fwrite(STDERR, '[FATAL] 配置文件不可读：' . $target . "\n");

            return 1;
        }

        $patched = [];
        foreach ($secretKeys as $key) {
            $pattern = '/^[ \t]*' . preg_quote($key, '/') . '[ \t]*=[ \t]*$/m';
            if (preg_match($pattern, $current) === 1) {
                $current   = (string)preg_replace($pattern, $key . '=' . Env::generateSecret(), $current);
                $patched[] = $key;
            }
        }

        if (empty($patched)) {
            echo '配置文件已存在且密钥项均已配置，未做改动：' . $target . "\n";

            return 0;
        }

        if (@file_put_contents($target, $current) === false) {
            fwrite(STDERR, '[FATAL] 写入失败：' . $target . "\n");

            return 1;
        }

        echo '已补齐空值密钥：' . implode(' , ', $patched) . "\n";
        echo '文件：' . $target . "\n";

        return 0;
    }

    /**
     * 使用说明
     *
     * @return string
     */
    public static function usage()
    {
        $isLinux = DIRECTORY_SEPARATOR === '/';
        $text    = [];
        $text[]  = 'GatewayPush 实时数据推送服务（WebSocket + UDP 双协议）';
        $text[]  = str_repeat('=', 70);
        $text[]  = '用法：php start.php <command> [--role=<role>]';
        $text[]  = '';
        $text[]  = '命令：';
        $text[]  = '  start         启动服务';
        $text[]  = '  start -d      ' . ($isLinux ? '守护模式启动' : '（Windows 不支持守护模式，将以前台运行）');
        $text[]  = '  stop          停止服务';
        $text[]  = '  restart       重启服务';
        $text[]  = '  reload        平滑重启业务进程（网关长连接不中断）';
        $text[]  = '  svc-status    查看进程运行状态';
        $text[]  = '  connections   查看连接状态';
        $text[]  = '  check         仅执行环境自检';
        $text[]  = '  info          打印启动信息：环境 / 框架版本 / 服务清单（含端口探测）';
        $text[]  = '                可选参数为角色列表，例：php start.php info gateway,udp';
        $text[]  = '  roles         输出各角色的启用清单（JSON），供管理脚本判断哪些角色被配置关闭';
        $text[]  = '  env:init      生成 .env 配置（不存在则从模板创建并注入随机密钥）';
        $text[]  = '  token <uid> [device_id] [ttl]   生成调试用 Token';
        $text[]  = '  push <uid|device|client> <target> [payload-json] [msg_id] [offline_mode]';
        $text[]  = '                提交一条定向推送任务（只入队，由业务进程消费后投递）';
        $text[]  = '';
        $text[]  = '角色（--role）：all / register / gateway / udp / business / api / dashboard';
        $text[]  = '  dashboard 为只读监控面板，默认监听 ' . Env::str('DASHBOARD_LISTEN', 'http://127.0.0.1:8291');
        $text[]  = '';
        if ($isLinux) {
            $text[] = 'Linux 单机部署：php start.php start -d';
        } else {
            $text[] = 'Windows 开发环境需按角色分别启动（6 个终端）：';
            $text[] = '  php start.php start --role=register';
            $text[] = '  php start.php start --role=gateway';
            $text[] = '  php start.php start --role=udp';
            $text[] = '  php start.php start --role=business';
            $text[] = '  php start.php start --role=api';
            $text[] = '  php start.php start --role=dashboard   # 可选的监控面板';
        }
        $text[] = '';

        return implode("\n", $text) . "\n";
    }
}
