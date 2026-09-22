<?php
/**
 * 角色清单（角色 <-> 服务 / 启用开关）
 *
 * 单一真源：三类消费方共用同一份清单，避免「角色名 / 开关名 / 监听键」在多处各写一遍：
 *   1) start.php 的启动信息横幅（服务清单 + 端口探测）；
 *   2) start.php 的 roles 命令（供管理脚本消费的机器可读输出）；
 *   3) 管理脚本（bin/start.ps1）据此跳过被关闭的角色。
 *
 * 为什么把「启用开关的 env 键名」也放在这里 —— config/*.php 只暴露解析后的布尔值，
 * 键名本身不落配置；而管理脚本需要在**不重新实现 .env 叠加语义**的前提下得知
 * 「这个角色被关掉了吗」。把键名与 enable 值放在同一条记录里，脚本只消费键名做提示，
 * 真值判断仍以 enabled 字段为准。
 *
 * 键名与 config 的 Env::bool() 调用必须一致，由 RoleCatalogTest 做结构性校验 ——
 * 这类「两处各写一遍」的字符串一旦漂移不会有任何报错，只会静默失效。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Common;

/**
 * 角色清单（角色 ↔ 服务 / 启用开关）
 *
 * 为启动横幅、roles 命令与管理脚本提供同一份清单，避免角色名与开关名多处各写一遍。
 */
final class RoleCatalog
{
    /**
     * 构建角色清单
     *
     * 返回顺序即**依赖顺序**（register 必须先就绪，其余角色都要向它注册），
     * 与 bin/start.ps1 的 RoleOrder / start.sh 的 ROLES_ALL 保持一致；
     * roles 命令按此顺序输出，故消费方无需自行排序。
     *
     * @param array<string, mixed> $gatewayConfig  config/gateway.php
     * @param array<string, mixed> $businessConfig config/business.php
     * @param array<string, mixed> $appConfig      config/app.php
     * @param bool                 $isLinux        影响进程数口径，见下方 $count 注释
     *
     * @return array<string, mixed> 角色名 => array(name, listen, probe, count, enable, env)
     */
    public static function build(array $gatewayConfig, array $businessConfig, array $appConfig, $isLinux = false)
    {
        // 进程数必须与各 Bootstrap 的 resolveCount() 口径一致：Windows 下 workerman
        // 单启动文件只允许 1 个 Worker 实例，会强制降级为 1。若直接展示 .env 的配置值，
        // 横幅会与真实进程数不符 —— 这类"展示值不等于实际值"正是最误导人的地方。
        $count = fn ($configured) => $isLinux ? max(1, (int)$configured) : 1;

        $registerListen = 'text://' . $gatewayConfig['register']['listen'];

        return [
            'register' => [
                'name'   => $gatewayConfig['register']['name'],
                'listen' => $registerListen,
                'probe'  => $registerListen,
                'count'  => 1,                                     // 注册中心必须单进程
                'enable' => !empty($gatewayConfig['register']['enable']),
                'env'    => 'REGISTER_ENABLE',
            ],
            'gateway' => [
                'name'   => $gatewayConfig['websocket']['name'],
                'listen' => $gatewayConfig['websocket']['listen']
                    . (!empty($gatewayConfig['websocket']['ssl']['enable']) ? '  (WSS)' : ''),
                'probe'  => $gatewayConfig['websocket']['listen'],
                'count'  => $count($gatewayConfig['websocket']['count']),
                'enable' => !empty($gatewayConfig['websocket']['enable']),
                'env'    => 'WS_ENABLE',
            ],
            'udp' => [
                'name'   => $gatewayConfig['udp']['name'],
                'listen' => $gatewayConfig['udp']['listen'],
                'probe'  => $gatewayConfig['udp']['listen'],
                'count'  => $count($gatewayConfig['udp']['count']),
                'enable' => !empty($gatewayConfig['udp']['enable']),
                'env'    => 'UDP_ENABLE',
            ],
            'business' => [
                'name'   => $businessConfig['worker']['name'],
                'listen' => '-（注册中心 ' . $businessConfig['register_address'] . '）',
                'probe'  => '',                                    // 不监听端口，无法探测
                'count'  => $count($businessConfig['worker']['count']),
                // 业务进程是消息处理的唯一载体，关闭它等于服务整体不可用，
                // 故没有独立开关（只有整体不启动这一种可能）
                'enable' => true,
                'env'    => '',
            ],
            'api' => [
                'name'   => $appConfig['api']['name'],
                'listen' => $appConfig['api']['listen'],
                'probe'  => $appConfig['api']['listen'],
                'count'  => 1,                                     // 接口层无状态，单进程
                'enable' => !empty($appConfig['api']['enable']),
                'env'    => 'API_ENABLE',
            ],
            'dashboard' => [
                'name'   => $appConfig['dashboard']['name'],
                'listen' => $appConfig['dashboard']['listen'],
                'probe'  => $appConfig['dashboard']['listen'],
                'count'  => 1,
                'enable' => !empty($appConfig['dashboard']['enable']),
                'env'    => 'DASHBOARD_ENABLE',
            ],
        ];
    }
}
