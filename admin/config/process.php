<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use support\Log;
use support\Request;
use app\process\Http;

global $argv;

return [
    'webman' => [
        'handler' => Http::class,
        // 仅回环监听：后台无 TLS，且与主项目 API(8290) / Dashboard(8291) 分端口部署。
        // ⚠ Windows 不拒绝重复 bind（主项目红线 ㊳）—— 启后台前先 netstat 确认 8292 空闲，
        //   两套实例叠加不会报错，只会表现为「页面数据随机来自两个库」。
        // ⚠ 这里直接读 env，**不能**写 config('gateway_push.listen')：
        //   process.php 本身是被 Config::load() 递归 include 的，此刻 config 尚未就绪，会拿到 null。
        'listen' => getenv('ADMIN_LISTEN') ?: 'http://127.0.0.1:8292',
        'count' => cpu_count() * 4,
        'user' => '',
        'group' => '',
        'reusePort' => false,
        'eventLoop' => '',
        'context' => [],
        'constructor' => [
            'requestClass' => Request::class,
            'logger' => Log::channel('default'),
            'appPath' => app_path(),
            'publicPath' => public_path()
        ]
    ],
    // 指标趋势采样（2.0 §1.1）：独立进程持续落 gw_metric_samples，与页面访问无关。
    // count 必须 = 1 —— 多进程并发采样会靠 uk_sampled_at 兜底，但没必要浪费。
    // 红线 ㊲ 的处理（Timer::add 延迟首跑）见 MetricSampler::onWorkerStart 注释。
    'metric-sampler' => [
        'handler' => app\process\MetricSampler::class,
        'count' => 1,
    ],
    // File update detection and automatic reload
    'monitor' => [
        'handler' => app\process\Monitor::class,
        'reloadable' => false,
        'constructor' => [
            // Monitor these directories
            'monitorDir' => array_merge([
                app_path(),
                config_path(),
                base_path() . '/process',
                base_path() . '/support',
                base_path() . '/resource',
                base_path() . '/.env',
            ], glob(base_path() . '/plugin/*/app'), glob(base_path() . '/plugin/*/config'), glob(base_path() . '/plugin/*/api')),
            // Files with these suffixes will be monitored
            'monitorExtensions' => [
                'php', 'html', 'htm', 'env'
            ],
            'options' => [
                'enable_file_monitor' => !in_array('-d', $argv) && DIRECTORY_SEPARATOR === '/',
                'enable_memory_monitor' => DIRECTORY_SEPARATOR === '/',
            ]
        ]
    ]
];
