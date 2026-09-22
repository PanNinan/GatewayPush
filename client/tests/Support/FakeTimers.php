<?php
/**
 * 假计时器支撑 —— SessionManager 计时器经构造参数注入，本 trait 提供
 * 可手动触发的假计时器实现（模拟 workerman：一次性定时器触发后自动移除）
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Tests\Support;

trait FakeTimers
{
    /** @var array[] */
    protected $timers = [];

    /** @var callable */
    protected $timerAdd;

    /** @var callable */
    protected $timerDel;

    protected function makeTimers()
    {
        $timers = &$this->timers;

        $this->timerAdd = function ($interval, $persistent, $fn) use (&$timers) {
            $timers[] = ['interval' => $interval, 'persistent' => $persistent, 'fn' => $fn, 'deleted' => false];

            return count($timers);
        };
        $this->timerDel = function ($id) use (&$timers) {
            if (isset($timers[$id - 1])) {
                $timers[$id - 1]['deleted'] = true;
            }
        };
    }

    protected function fireTimer($id)
    {
        $t = &$this->timers[$id - 1];
        if ($t['deleted']) {
            return;
        }
        if (!$t['persistent']) {
            $t['deleted'] = true; // workerman 一次性定时器触发后自动移除
        }
        ($t['fn'])();
    }

    protected function persistentTimers()
    {
        $out = [];
        foreach ($this->timers as $t) {
            if ($t['persistent'] && !$t['deleted']) {
                $out[] = $t;
            }
        }

        return $out;
    }

    protected function nonPersistentTimers()
    {
        $out = [];
        foreach ($this->timers as $t) {
            if (!$t['persistent'] && !$t['deleted']) {
                $out[] = $t;
            }
        }

        return $out;
    }

    protected function lastTimerId()
    {
        return count($this->timers);
    }
}
