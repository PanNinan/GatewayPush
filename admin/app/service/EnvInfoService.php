<?php

declare(strict_types=1);

namespace app\service;

use Throwable;

/**
 * 主项目版本 / 环境只读快照（2.0 §2.2）。
 *
 * 与 roles 探测**合并展示**（规划原文：「与 roles 页合并即可」）——
 * 运维问「谁活着」的下一问往往就是「跑的是什么版本 / 什么环境」。
 *
 * 数据源三处，**都不打主项目 HTTP**：
 * 1. PHP 运行时：`PHP_VERSION` / `PHP_OS` / SAPI —— 后台与主项目同机部署时即主项目口径；
 * 2. 主项目 `composer.lock`：workerman / gateway-worker 等包版本（按名白名单，不整表吐出）；
 * 3. 主项目 `.env`：`APP_ENV` —— 复用 ConfigViewer 的路径三关与 parseEnv，不另开读文件路径。
 *
 * 任一源失败**不拖垮整包**：该项 value 置空、configured=false，UI 显示「—」。
 * 不把「读不到」伪装成「没有」——与配置查看的空值语义一致。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */
final class EnvInfoService
{
    /**
     * 从主项目 composer.lock 摘出的包名白名单（按展示顺序）。
     *
     * 为什么白名单而不是全量：lock 里几十个传递依赖，运维关心的只有
     * 「推送框架本体那几件」；全量列表是噪音，且会把传递依赖版本误读成
     * 「我们主动升了它」。
     *
     * @var list<string>
     */
    public const PACKAGES = [
        'workerman/workerman',
        'workerman/gateway-worker',
        'workerman/register',
        'workerman/redis',
        'predis/predis',
    ];

    /**
     * @return array{
     *     items: list<array{key: string, value: string, configured: bool, source: string}>,
     *     notes: list<string>
     * }
     */
    public function view(): array
    {
        $items = [
            $this->item('PHP 版本', PHP_VERSION, 'runtime'),
            $this->item('PHP SAPI', (string)php_sapi_name(), 'runtime'),
            $this->item('操作系统', PHP_OS_FAMILY . ' ' . PHP_OS, 'runtime'),
            $this->item('APP_ENV', $this->appEnv(), '主项目 .env'),
        ];

        foreach ($this->packageVersions() as $name => $version) {
            $items[] = $this->item($name, $version, '主项目 composer.lock');
        }

        return [
            'items' => $items,
            'notes' => self::notes(),
        ];
    }

    /**
     * 主项目 `.env` 的 APP_ENV（只读这一键；读不到 = 未配置，不猜默认值）。
     */
    private function appEnv(): string
    {
        try {
            $viewer = new ConfigViewer();
            // ConfigViewer::view() 已做路径三关 + parseEnv；这里只要 APP_ENV 一项。
            // 纯单测 / 独立部署下 config() 不可用时不得拖垮整包 —— 读不到 = 未配置。
            $view = $viewer->view();
        } catch (Throwable) {
            return '';
        }
        if (!$view['ok']) {
            return '';
        }
        foreach ($view['groups'] as $group) {
            foreach ($group['items'] as $item) {
                if (($item['key'] ?? '') === 'APP_ENV') {
                    // configured=false 时 value 可能是空串 —— 统一按未配置处理
                    return !empty($item['configured']) ? (string)($item['value'] ?? '') : '';
                }
            }
        }

        return '';
    }

    /**
     * 主项目 composer.lock 里的白名单包版本。
     *
     * @return array<string, string> 包名 => 版本；读不到返回空数组
     */
    private function packageVersions(): array
    {
        // 后台工作目录是 admin/，主项目根是上级；与 ConfigViewer / LogTailService 同口径。
        $lock = dirname(__DIR__, 3) . '/composer.lock';
        $raw = @file_get_contents($lock);
        if ($raw === false) {
            return [];
        }
        $json = json_decode($raw, true);
        if (!is_array($json) || !isset($json['packages']) || !is_array($json['packages'])) {
            return [];
        }

        $out = [];
        foreach ($json['packages'] as $pkg) {
            if (!is_array($pkg) || !isset($pkg['name'], $pkg['version'])) {
                continue;
            }
            $name = (string)$pkg['name'];
            if (in_array($name, self::PACKAGES, true)) {
                $out[$name] = (string)$pkg['version'];
            }
        }

        // 保持 PACKAGES 声明顺序（lock 顺序不保证）
        $ordered = [];
        foreach (self::PACKAGES as $name) {
            if (isset($out[$name])) {
                $ordered[$name] = $out[$name];
            }
        }

        return $ordered;
    }

    /**
     * @return array{key: string, value: string, configured: bool, source: string}
     */
    private function item(string $key, string $value, string $source): array
    {
        $value = trim($value);

        return [
            'key' => $key,
            'value' => $value,
            'configured' => $value !== '',
            'source' => $source,
        ];
    }

    /**
     * @return list<string>
     */
    public static function notes(): array
    {
        return [
            'PHP 版本取自**后台进程**运行时 —— 后台与主项目同机部署时即主项目口径；分机部署时以主项目机器为准。',
            '包版本读主项目根 composer.lock（部署态真源），不读 vendor/composer/installed.json（可能被 --no-dev 洗掉传递依赖）。',
            'APP_ENV 只读主项目 .env 白名单一钥；未配置时显示「—」，不回落代码默认值冒充部署态。',
            '配置无热重载：.env 改了 APP_ENV 只对新启动的主项目进程生效。',
        ];
    }
}
