<?php
/**
 * push 子命令 —— 提交一条定向推送任务（调试 / 运维用）
 *
 * 与 HTTP 接口一致，只负责把任务写入队列，真实投递由业务进程消费后完成。
 * 因此本命令可在服务未启动时执行，任务会在服务起来后补投。
 *
 * 为什么单独成类而不是并入 Commands：Redis 入队是异步操作，必须由 workerman
 * 事件循环驱动，故这里要临时起一个单 Worker 跑完即停 —— 与那几个「纯计算」子命令
 * 不是一回事。
 *
 * 本类不调用 exit（退出码经返回值交给入口 start.php）；Worker::runAll() 出现在
 * src/ 下是允许的 —— 「src/ 内零 exit/die/sleep」约束针对的是常驻进程代码，
 * 而本类只在 CLI 命令路径被调用，不会出现在任何 worker 的 onWorkerStart 里。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Console;

use GatewayPush\Business\Push;
use GatewayPush\Common\RedisClient;
use Workerman\Timer;
use Workerman\Worker;

/**
 * push 子命令：提交一条定向推送任务（调试 / 运维用）
 *
 * 只入队不投递，故可在服务未启动时执行；需 workerman 事件循环，单独成类。
 */
final class PushCommand
{
    /**
     * 执行 push 子命令
     *
     * @param array<string, mixed> $appConfig      config/app.php
     * @param array<string, mixed> $gatewayConfig  config/gateway.php
     * @param array<string, mixed> $businessConfig config/business.php
     * @param array<int|string, mixed> $argvList       原始参数列表
     *
     * @return int 退出码
     */
    public static function run(array $appConfig, array $gatewayConfig, array $businessConfig, array $argvList)
    {
        $targetType  = isset($argvList[2]) ? strtolower(trim((string)$argvList[2])) : '';
        $target      = isset($argvList[3]) ? trim((string)$argvList[3]) : '';
        $payloadRaw  = isset($argvList[4]) ? (string)$argvList[4] : '{}';
        $msgId       = isset($argvList[5]) ? (string)$argvList[5] : '';
        $offlineMode = isset($argvList[6]) ? (string)$argvList[6] : '';

        if ($targetType === '' || $target === '') {
            fwrite(STDERR, "用法：php start.php push <uid|device|client> <target> [payload-json] [msg_id] [offline_mode]\n");
            fwrite(STDERR, "示例：php start.php push uid 1001 '{\"title\":\"hi\"}' msg-1\n");

            return 1;
        }

        $payload = json_decode($payloadRaw, true);
        if (!is_array($payload)) {
            fwrite(STDERR, '[FATAL] payload 不是合法 JSON 对象：' . $payloadRaw . "\n");

            return 1;
        }

        Push::init(
            $appConfig['push'],
            $businessConfig['push_queue'],
            $gatewayConfig['udp']['out_queue'] ?? []
        );

        $exitCode = 0;
        $worker   = new Worker();
        $worker->count = 1;

        $worker->onWorkerStart = function () use ($appConfig, $businessConfig, $targetType, $target, $payload, $msgId, $offlineMode, &$exitCode) {
            RedisClient::init($appConfig['redis']);

            $queueKey = $businessConfig['push_queue']['key'];

            // 入队是异步操作，需事件循环驱动；超时保护避免网络异常时命令挂死
            Timer::add(5, function () use (&$exitCode) {
                fwrite(STDERR, "[FATAL] 入队操作超时，请检查 Redis 连通性\n");
                $exitCode = 1;
                Worker::stopAll();
            }, [], false);

            Push::enqueue($targetType, $target, $payload, [
                'msg_id'       => $msgId,
                'offline_mode' => $offlineMode,
                'source'       => 'cli',
            ], function ($ok) use ($targetType, $target, $msgId, $offlineMode, $queueKey, &$exitCode) {
                if (!$ok) {
                    fwrite(STDERR, "[FATAL] 推送任务入队失败\n");
                    $exitCode = 1;
                    Worker::stopAll();

                    return;
                }

                echo "推送任务已入队，等待业务进程消费\n";
                echo "  target_type  : {$targetType}\n";
                echo "  target       : {$target}\n";
                echo '  msg_id       : ' . ($msgId !== '' ? $msgId : '(未指定，不参与幂等去重)') . "\n";
                echo '  offline_mode : ' . ($offlineMode !== '' ? $offlineMode : Push::offlineMode()) . "\n";
                echo "  queue        : {$queueKey}\n";
                Worker::stopAll();
            });
        };

        Worker::runAll();

        return $exitCode;
    }
}
