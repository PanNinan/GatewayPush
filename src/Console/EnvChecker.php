<?php
/**
 * 运行环境自检
 *
 * 在**任何** workerman 命令之前强制执行（restart / reload / svc-status 亦然）：
 * 配置漂移类问题（注册中心地址不一致、UDP 队列 key 不一致、鉴权密钥为占位值）
 * 一旦漏到运行期，表现是「服务起得来但功能不通」，排查成本远高于启动时拒绝。
 *
 * 输出为**人读报告 + 布尔结论**两部分，调用方负责打印与决定退出码 ——
 * 本类不做任何 IO 输出，也不调用 exit（CLI 退出码统一由入口 start.php 决定）。
 *
 * 自检项覆盖：PHP 版本与扩展、运行时目录可写、跨角色配置一致性、密钥强度、
 * 接口验签状态、限流维度、业务动作清单可用性、端口占用提示。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Console;

use GatewayPush\Api\Bootstrap;
use GatewayPush\Common\Env;

/**
 * 运行环境自检（任何 workerman 命令之前强制执行）
 *
 * 输出人读报告 + 布尔结论，自身不做 IO 输出；用于把配置漂移拦在启动前。
 */
final class EnvChecker
{
    /**
     * 执行环境自检
     *
     * 端口占用只作提示、不阻断：restart 场景下端口被自身占用属正常。
     *
     * @param array<string, mixed> $appConfig      config/app.php
     * @param array<string, mixed> $gatewayConfig  config/gateway.php
     * @param array<string, mixed> $businessConfig config/business.php
     * @param array<string, mixed> $actionConfig   config/actions.php
     *
     * @return array<int|string, mixed> ['ok' => bool, 'text' => string]
     *
     * @throws \RuntimeException 运行时目录无法创建时抛出
     */
    public static function check(array $appConfig, array $gatewayConfig, array $businessConfig, array $actionConfig = [])
    {
        $runtime = $appConfig['runtime'];
        $lines   = [];
        $ok      = true;
        $isLinux = DIRECTORY_SEPARATOR === '/';

        $lines[] = 'GatewayPush 推送服务 - 运行环境自检';
        $lines[] = str_repeat('=', 70);
        $lines[] = 'PHP 版本  : ' . PHP_VERSION . ' (' . PHP_SAPI . ')';
        $lines[] = '操作系统  : ' . PHP_OS . ($isLinux ? ' [多进程模式]' : ' [单进程模式]');
        $lines[] = '启动角色  : ' . (defined('APP_ROLE') ? \APP_ROLE : 'all');
        $lines[] = str_repeat('-', 70);

        // PHP 版本
        $phpMin  = $runtime['php_min'];
        $verOk   = version_compare(PHP_VERSION, $phpMin, '>=');
        $lines[] = sprintf('[%-4s] PHP 版本 >= %s', $verOk ? 'OK' : 'FAIL', $phpMin);
        $ok      = $ok && $verOk;

        $phpMaxWarn = $runtime['php_max_warn'];
        if (version_compare(PHP_VERSION, $phpMaxWarn, '>')) {
            $lines[] = sprintf('[%-4s] PHP 版本高于已验证上限 %s，请评估兼容性', 'WARN', $phpMaxWarn);
        }

        // 必需扩展
        foreach ($runtime['require_ext'] as $ext) {
            $loaded  = extension_loaded($ext);
            $lines[] = sprintf('[%-4s] 必需扩展 %s', $loaded ? 'OK' : 'FAIL', $ext);
            $ok      = $ok && $loaded;
        }

        // Linux 专属扩展
        if ($isLinux) {
            foreach ($runtime['require_ext_linux'] as $ext) {
                $loaded  = extension_loaded($ext);
                $lines[] = sprintf('[%-4s] Linux 必需扩展 %s', $loaded ? 'OK' : 'FAIL', $ext);
                $ok      = $ok && $loaded;
            }
        } else {
            $lines[] = '[SKIP] Windows 环境跳过 pcntl / posix 检查（多进程不可用）';
        }

        // 可选扩展
        foreach ($runtime['optional_ext'] as $ext) {
            if (extension_loaded($ext)) {
                $lines[] = sprintf('[%-4s] 可选扩展 %s', 'OK', $ext);
            } else {
                $lines[] = sprintf('[%-4s] 可选扩展 %s 未安装（不影响运行，性能可优化）', 'WARN', $ext);
            }
        }

        // 运行时目录
        foreach (['runtime_path', 'log_path', 'pid_path'] as $key) {
            $dir = $runtime[$key];
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0o755, true) && !is_dir($dir)) {
                    throw new \RuntimeException(sprintf('Directory "%s" was not created', $dir));
                }
            }
            $writable = is_dir($dir) && is_writable($dir);
            $lines[]  = sprintf('[%-4s] 目录可写 %s', $writable ? 'OK' : 'FAIL', $dir);
            $ok       = $ok && $writable;
        }

        // 配置一致性
        $registerMatch = $gatewayConfig['register']['listen'] === $businessConfig['register_address'];
        $lines[]       = sprintf(
            '[%-4s] 注册中心地址一致（gateway: %s / business: %s）',
            $registerMatch ? 'OK' : 'FAIL',
            $gatewayConfig['register']['listen'],
            $businessConfig['register_address']
        );
        $ok = $ok && $registerMatch;

        $queueMatch = $gatewayConfig['udp']['queue']['key'] === $businessConfig['udp_queue']['key'];
        $lines[]    = sprintf('[%-4s] UDP 队列 key 一致（%s）', $queueMatch ? 'OK' : 'FAIL', $gatewayConfig['udp']['queue']['key']);
        $ok         = $ok && $queueMatch;

        // 环境配置来源
        $envFiles = Env::loadedFiles();
        if (empty($envFiles)) {
            $lines[] = '[WARN] 未找到 .env（当前全部使用代码内默认值），可执行 php start.php env:init 生成';
        } else {
            $lines[] = sprintf(
                '[%-4s] 环境配置 %s（APP_ENV=%s，右侧覆盖左侧）',
                'OK',
                implode(' -> ', $envFiles),
                Env::envName()
            );
        }
        if (Env::lastError() !== '') {
            $lines[] = '[FAIL] .env 解析失败：' . Env::lastError();
            $ok      = false;
        }

        // 安全项
        $internalOk = !empty($appConfig['internal']['secret']);
        $lines[]    = sprintf(
            '[%-4s] 内部通信密钥（Register / Gateway / BusinessWorker 一致性）',
            $internalOk ? 'OK' : 'FAIL'
        );
        $ok = $ok && $internalOk;

        $authEnabled = !empty($appConfig['auth']['enable']);
        $authSecret  = (string)$appConfig['auth']['secret'];

        if (!$authEnabled) {
            $lines[] = '[WARN] 连接鉴权已关闭（AUTH_ENABLE=false），生产环境务必开启';
        } elseif ($authSecret === '') {
            $lines[] = '[FAIL] 鉴权密钥为空，请执行 php start.php env:init 生成 .env';
            $ok      = false;
        } elseif (SecretGuard::isPlaceholder($authSecret)) {
            $lines[] = '[FAIL] 鉴权密钥为占位值，生产环境必须替换为高强度随机值';
            $ok      = false;
        } elseif (strlen($authSecret) < 32) {
            $lines[] = sprintf(
                '[%-4s] 鉴权密钥长度仅 %d，建议改用 64 位十六进制随机值',
                'WARN',
                strlen($authSecret)
            );
        } else {
            $lines[] = sprintf('[%-4s] 鉴权密钥已配置（长度 %d）', 'OK', strlen($authSecret));
        }

        if (empty($appConfig['auth']['sign_enable'])) {
            $lines[] = '[WARN] 报文签名校验已关闭，UDP 报文可被伪造';
        } else {
            $lines[] = '[OK  ] 报文签名校验已开启';
        }

        // 定向推送
        if (empty($appConfig['push']['enable'])) {
            $lines[] = '[WARN] 定向推送已关闭（PUSH_ENABLE=false）';
        } else {
            $mode    = (string)$appConfig['push']['offline_mode'];
            $modeOk  = in_array($mode, ['drop', 'queue'], true);
            $lines[] = sprintf(
                '[%-4s] 定向推送已开启（离线策略 %s，指令队列 %s）',
                $modeOk ? 'OK' : 'FAIL',
                $mode,
                $businessConfig['push_queue']['key']
            );
            $ok = $ok && $modeOk;

            $outKey = $gatewayConfig['udp']['out_queue']['key'] ?? '';
            if (!empty($gatewayConfig['udp']['enable']) && $outKey !== '') {
                $lines[] = sprintf('[%-4s] UDP 出站队列 %s', 'OK', $outKey);
            }
        }

        // HTTP 推送接口
        if (!empty($appConfig['api']['enable'])) {
            $apiOwnSecret = (string)$appConfig['api']['secret'];
            $apiSecret    = $apiOwnSecret !== '' ? $apiOwnSecret : (string)$appConfig['auth']['secret'];

            if ($apiSecret === '') {
                $lines[] = '[FAIL] HTTP 接口密钥为空（API_SECRET 未配置，且 AUTH_SECRET 亦为空）';
                $ok      = false;
            } elseif ($apiOwnSecret === '' && SecretGuard::isPlaceholder($apiSecret)) {
                $lines[] = '[FAIL] HTTP 接口复用 AUTH_SECRET，但该密钥为占位值';
                $ok      = false;
            } else {
                $lines[] = sprintf(
                    '[%-4s] HTTP 接口密钥已配置（%s）',
                    'OK',
                    $apiOwnSecret !== '' ? 'API_SECRET 独立配置' : '复用 AUTH_SECRET'
                );
            }

            $signTtl = (int)$appConfig['api']['sign_ttl'];
            if ($signTtl <= 0) {
                $lines[] = '[WARN] 接口时间戳窗口为 0（关闭防重放校验），生产环境不建议';
            }

            // 接口验签。关闭仅在回环监听时生效（见 Api\Bootstrap::signEnabled）：
            // 开关被护栏拦下时只告警不阻断 —— 运行时仍是「强制验签」，属安全侧，
            // 没必要因此拒绝启动，但必须让配置者知道自己的配置没生效。
            $apiListen  = (string)$appConfig['api']['listen'];
            $apiSignOff = empty($appConfig['api']['sign_enable']);
            $apiLoop    = Bootstrap::isLoopbackHost($apiListen);

            if (!$apiSignOff) {
                $lines[] = '[OK  ] 接口验签已开启';
            } elseif ($apiLoop) {
                $lines[] = sprintf(
                    '[%-4s] 接口验签已关闭（API_SIGN_ENABLE=false，监听 %s），所有请求无需签名即可调用；'
                    . '该开关仅回环监听生效，生产环境务必开启',
                    'WARN',
                    $apiListen
                );
            } else {
                $lines[] = sprintf(
                    '[%-4s] API_SIGN_ENABLE=false 被忽略：接口监听地址非回环（%s），已强制开启验签',
                    'WARN',
                    $apiListen
                );
            }
        } else {
            $lines[] = '[WARN] HTTP 推送接口已关闭（API_ENABLE=false），仅支持队列触发';
        }

        // 报文级限流
        if (empty($appConfig['rate_limit']['enable'])) {
            $lines[] = '[WARN] 报文级限流已关闭（RATE_LIMIT_ENABLE=false）';
        } else {
            $rateConf = $appConfig['rate_limit'];
            $dims     = ['conn' => '连接', 'uid' => '用户', 'ip' => 'IP', 'ping' => '心跳'];
            $active   = 0;
            $desc     = [];
            $badBurst = [];

            foreach ($dims as $dim => $label) {
                $rate  = (int)$rateConf[$dim]['rate'];
                $burst = (int)$rateConf[$dim]['burst'];

                if ($rate <= 0) {
                    $desc[] = $label . ' 已关闭';

                    continue;
                }
                $active++;
                if ($burst < $rate) {
                    $badBurst[] = $label;
                }
                $desc[] = sprintf('%s %d/%d', $label, $rate, $burst);
            }

            if ($active === 0) {
                $lines[] = '[FAIL] 报文级限流已开启但全部维度速率均为 0，等同于未限流';
                $ok      = false;
            } else {
                $lines[] = sprintf(
                    '[%-4s] 报文级限流已开启（%s，格式为 维度 速率/突发）',
                    'OK',
                    implode('，', $desc)
                );
            }

            if ($badBurst) {
                $lines[] = sprintf(
                    '[WARN] 维度 %s 的 burst 小于 rate，已按 rate 兜底修正（突发能力被压缩）',
                    implode('/', $badBurst)
                );
            }

            if ((int)$rateConf['mem_max_buckets'] < 1000) {
                $lines[] = sprintf(
                    '[WARN] 限流内存桶上限过低（%d），高并发下会频繁淘汰',
                    (int)$rateConf['mem_max_buckets']
                );
            }
        }

        // 业务动作清单（config/actions.php）
        $actionList = isset($actionConfig['actions']) && is_array($actionConfig['actions'])
            ? $actionConfig['actions']
            : [];

        if (!$actionList) {
            $lines[] = '[FAIL] 业务动作清单为空（config/actions.php 的 actions 段未配置任何动作）';
            $ok      = false;
        } else {
            $invalid   = [];
            $silent    = [];
            $noAuth    = [];
            $noTimeout = [];

            foreach ($actionList as $name => $decl) {
                $handler = is_array($decl) && isset($decl['handler']) ? (string)$decl['handler'] : '';
                if ($handler === '' || !class_exists($handler)
                    || !in_array('GatewayPush\Business\ActionInterface', (array)class_implements($handler), true)) {
                    $invalid[] = $name;

                    continue;
                }

                // 回执方式：数组为按通道分别声明，字符串为两通道共用
                $reply    = is_array($decl) && array_key_exists('reply', $decl) ? $decl['reply'] : null;
                $udpReply = is_array($reply)
                    ? (isset($reply['udp']) ? (string)$reply['udp'] : 'sync')
                    : (is_string($reply) ? $reply : 'sync');
                if ($udpReply === 'none') {
                    $silent[] = $name;
                }

                if (is_array($decl) && array_key_exists('auth', $decl) && empty($decl['auth'])) {
                    $noAuth[] = $name;
                }

                if (is_array($decl) && array_key_exists('timeout', $decl) && (int)$decl['timeout'] === 0) {
                    $noTimeout[] = $name;
                }
            }

            if ($invalid) {
                $lines[] = sprintf(
                    '[FAIL] 业务动作处理器不可用：%s（类不存在或未实现 ActionInterface）',
                    implode('/', $invalid)
                );
                $ok = false;
            } else {
                $lines[] = sprintf(
                    '[%-4s] 业务动作清单共 %d 个（%s）',
                    'OK',
                    count($actionList),
                    implode('/', array_keys($actionList))
                );
            }

            if ($silent) {
                $lines[] = sprintf(
                    '[%-4s] UDP 通道静默回执的动作：%s（处理但不下发，避免双向流量放大）',
                    'OK',
                    implode('/', $silent)
                );
            }
            if ($noTimeout) {
                $lines[] = sprintf(
                    '[WARN] 以下动作关闭了回执超时保护（timeout=0），异步回执丢失时客户端会永久等待：%s',
                    implode('/', $noTimeout)
                );
            }
            if ($noAuth) {
                $lines[] = sprintf(
                    '[WARN] 以下动作声明为无需鉴权（auth=false）：%s —— UDP 通道没有连接级鉴权闸门，'
                    . '该声明即为其唯一身份校验，请确认确属公开动作',
                    implode('/', $noAuth)
                );
            }
        }

        // 端口占用探测（仅提示，不阻断：restart 场景下端口被自身占用属正常）
        $ports = [];
        if (!empty($gatewayConfig['websocket']['enable'])) {
            $ports['WebSocket'] = $gatewayConfig['websocket']['listen'];
        }
        if (!empty($gatewayConfig['udp']['enable'])) {
            $ports['UDP'] = $gatewayConfig['udp']['listen'];
        }
        if (!empty($gatewayConfig['register']['enable'])) {
            $ports['Register'] = 'tcp://' . $gatewayConfig['register']['listen'];
        }
        if (!empty($appConfig['api']['enable'])) {
            $ports['HTTP-API'] = $appConfig['api']['listen'];
        }
        foreach ($ports as $label => $listen) {
            $probe = PortProbe::isUsed($listen);
            $lines[] = sprintf(
                '[%-4s] %s 端口 %s%s',
                $probe ? 'WARN' : 'OK',
                $label,
                preg_replace('#^[a-z]+://#i', '', $listen),
                $probe ? ' 已被占用（若为本服务实例可忽略）' : ''
            );
        }

        $lines[] = str_repeat('=', 70);
        $lines[] = $ok ? '自检结论：通过' : '自检结论：未通过，请修正上述 FAIL 项';
        $lines[] = '';

        return ['ok' => $ok, 'text' => implode("\n", $lines) . "\n"];
    }
}
