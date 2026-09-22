<?php
/**
 * 启动信息横幅
 *
 * 打印时机必须早于 Worker::runAll() —— daemonize() 由 runAll() 内部执行，
 * 在此之前 STDOUT 仍连接终端，因此守护模式（-d）下同样可见。
 *
 * 这正是本项目需要自建横幅的原因：workerman 自带的 displayUI() 全程走
 * Worker::log()，而 log() 首行即判断 !$daemonize，守护模式下整块 UI
 * （版本行 + WORKERS 表）只落 runtime/logs/workerman.log，终端上看不到。
 *
 * 本类只负责拼装文本，打印与退出码由入口 start.php 决定。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Console;

use GatewayPush\Api\Bootstrap;
use GatewayPush\Common\Env;
use GatewayPush\Common\RoleCatalog;

/**
 * 启动信息横幅
 *
 * 必须在 Worker::runAll() 之前打印，否则守护模式下终端不可见；本类只拼装文本。
 */
final class Banner
{
    /**
     * 渲染启动信息横幅
     *
     * @param array  $appConfig      config/app.php
     * @param array  $gatewayConfig  config/gateway.php
     * @param array  $businessConfig config/business.php
     * @param array  $roles          只列这些角色；空数组 = 按配置列出全部相关角色
     * @param bool   $withProbe      是否探测端口监听状态（启动前端口必然空闲，故仅 info 命令启用）
     * @param string $modeLabel      启动模式标签（DAEMON / DEBUG），空串则不显示该行
     * @return string
     */
    public static function render(array $appConfig, array $gatewayConfig, array $businessConfig, array $roles = [], $withProbe = false, $modeLabel = '')
    {
        $isLinux = DIRECTORY_SEPARATOR === '/';
        // 入口变量 BASE_PATH 指向项目根；兜底值按本类所在层级（src/Console）回退两级
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);

        // 角色清单（角色 -> 进程名 / 监听 / 进程数 / 启用开关）的唯一真源：
        // 与 roles 命令、Windows 管理脚本共用同一份，避免角色名与开关名在多处各写一遍。
        // 进程数口径、business 无独立开关等约定见 RoleCatalog 头部注释。
        $services = RoleCatalog::build($gatewayConfig, $businessConfig, $appConfig, $isLinux);

        // 项目内路径显示为相对形式，避免横幅里反复出现本机绝对路径
        $relative = function ($path) use ($basePath) {
            $path = str_replace('\\', '/', (string)$path);
            $root = rtrim(str_replace('\\', '/', $basePath), '/') . '/';
            return strncmp($path, $root, strlen($root)) === 0 ? substr($path, strlen($root)) : $path;
        };

        $envFiles = Env::loadedFiles();
        $envDesc  = $envFiles
            ? implode(' -> ', array_map($relative, $envFiles))
            : '（未找到，全部使用代码内默认值）';

        $lines   = [];
        $lines[] = 'GatewayPush 实时数据推送服务 - 启动信息';
        $lines[] = str_repeat('=', 70);
        $lines[] = 'PHP 版本  : ' . PHP_VERSION . ' (' . PHP_SAPI . ') / ' . PHP_OS_FAMILY
            . ($isLinux ? ' [多进程模式]' : ' [单进程模式]');
        $lines[] = '启动角色  : ' . (defined('APP_ROLE') ? \APP_ROLE : 'all');
        if ($modeLabel !== '') {
            $lines[] = '启动模式  : ' . $modeLabel;
        }
        $lines[] = '环境配置  : ' . $appConfig['app']['env'] . '（' . $envDesc . '）';

        // 接口验签状态。免签是安全相关状态，必须在启动时就可见 —— 它不像日志级别
        // 那样只影响可观测性，而是直接影响接口的对外开放程度。
        if (!empty($appConfig['api']['enable'])) {
            $apiSignOn = ! empty($appConfig['api']['sign_enable']) || ! Bootstrap::isLoopbackHost(
                    (string)$appConfig['api']['listen']
                );
            $lines[] = '接口验签  : ' . ($apiSignOn
                ? '已开启'
                : '已关闭（本地调试免签，仅回环监听生效）');
        }

        $lines[] = '时区      : ' . date_default_timezone_get();
        $lines[] = '运行目录  : ' . rtrim($relative($appConfig['runtime']['runtime_path']), '/') . '/'
            . '  (日志 ' . rtrim($relative($appConfig['runtime']['log_path']), '/') . '/'
            . '，进程 ' . rtrim($relative($appConfig['runtime']['pid_path']), '/') . '/)';
        $lines[] = '框架版本  : workerman ' . self::packageVersion('workerman/workerman')
            . ' / gateway-worker ' . self::packageVersion('workerman/gateway-worker');
        $lines[] = '依赖版本  : workerman/redis ' . self::packageVersion('workerman/redis')
            . ' / vlucas/phpdotenv ' . self::packageVersion('vlucas/phpdotenv');
        $lines[] = str_repeat('-', 70);
        $lines[] = '服务清单  :';
        $lines[] = '  ' . Text::pad('角色', 12) . Text::pad('进程名', 18)
            . Text::pad('监听', 36) . Text::pad('进程数', 8) . '状态';

        $running   = 0;
        $probeable = 0;
        foreach ($services as $roleName => $service) {
            if ($roles && !in_array($roleName, $roles, true)) {
                continue;
            }

            if (empty($service['enable'])) {
                $status = '未启用';
            } elseif (!$withProbe || $service['probe'] === '') {
                $status = '-';
            } else {
                $probeable++;
                if (PortProbe::isUsed($service['probe'])) {
                    $status = '监听中';
                    $running++;
                } else {
                    $status = '未监听';
                }
            }

            $lines[] = '  ' . Text::pad($roleName, 12) . Text::pad($service['name'], 18)
                . Text::pad($service['listen'], 36) . Text::pad((string)$service['count'], 8) . $status;
        }

        if ($withProbe) {
            $lines[] = '';
            $lines[] = sprintf(
                '端口探测：%d / %d 个可探测服务处于监听状态（business 无监听端口，不参与判断）',
                $running,
                $probeable
            );
        }

        $lines[] = str_repeat('=', 70);
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * 读取 Composer 已安装包的真实版本号
     *
     * 不硬编码版本，避免升级依赖后横幅与 composer.lock 漂移；
     * InstalledVersions 不可用（手工裁剪 vendor）或包不存在时降级为 '-'。
     *
     * @param string $package 形如 workerman/workerman
     * @return string
     */
    private static function packageVersion($package)
    {
        if (!class_exists('Composer\\InstalledVersions')) {
            return '-';
        }

        try {
            $version = \Composer\InstalledVersions::getPrettyVersion($package);
        } catch (\Throwable $e) {
            return '-';
        }

        return is_string($version) && $version !== '' ? $version : '-';
    }
}
