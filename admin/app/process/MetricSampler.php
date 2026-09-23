<?php

declare(strict_types=1);

namespace app\process;

use app\service\MetricService;
use support\Log;
use Workerman\Timer;

/**
 * 指标趋势采样进程（2.0 §1.1）。
 *
 * webman 自定义进程（config/process.php 的 'metric-sampler' 项），count=1：
 * 每 ADMIN_METRIC_SAMPLE_INTERVAL（默认 60）秒把 MonitorAggregator 的即时快照
 * 固化进 gw_metric_samples 一行 —— 趋势页的数据源。
 *
 * 为什么是独立进程而不是 HTTP 请求内采样：
 * - 采样必须**与访问无关地持续发生**，否则没人打开页面就没有数据点；
 * - 独立进程 count=1，即使采样阻塞（Redis 慢查询）也不影响 HTTP worker。
 *
 * 红线 ㊲ 的落点：Timer::add 是**延迟首跑**，若只在回调里采样，本进程频繁重启时
 * （开发环境常态）可能一次都不跑 —— 故 onWorkerStart 里**立即**执行一次采样与清理，
 * 然后再挂周期 Timer。
 */
final class MetricSampler
{
    private MetricService $service;

    private int $interval;

    private int $keepDays;

    /** 上次清理的日期（Y-m-d），跨天即触发一次 cleanup */
    private string $lastCleanupDay = '';

    public function __construct()
    {
        $this->service = new MetricService();
        $this->interval = max(10, (int)(getenv('ADMIN_METRIC_SAMPLE_INTERVAL') ?: MetricService::DEFAULT_INTERVAL));
        $this->keepDays = max(1, (int)(getenv('ADMIN_METRIC_KEEP_DAYS') ?: MetricService::DEFAULT_KEEP_DAYS));
    }

    public function onWorkerStart(): void
    {
        // 立即执行一次（红线 ㊲：延迟首跑会让频繁重启环境一次都不跑）
        $this->cleanup();
        $this->sample();

        Timer::add($this->interval, function (): void {
            $this->sample();
            $this->cleanupIfNewDay();
        });
    }

    /** 采样一次；失败 debug 级记日志（不 warn —— 主项目下线属常态，趋势图空洞即事实） */
    public function sample(): void
    {
        $res = $this->service->sample();
        if (!$res['ok'] && isset($res['error'])) {
            Log::debug('metric-sampler skip: ' . $res['error']);
        }
    }

    /** 跨天触发一次过期清理（每日至多一次，而不是每小时硬扫） */
    public function cleanupIfNewDay(): void
    {
        $today = date('Y-m-d');
        if ($this->lastCleanupDay === $today) {
            return;
        }
        $this->lastCleanupDay = $today;
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $this->lastCleanupDay = date('Y-m-d');
        $n = $this->service->cleanup($this->keepDays);
        if ($n > 0) {
            Log::info('metric-sampler cleaned ' . $n . ' expired rows (keep ' . $this->keepDays . 'd)');
        }
    }
}
