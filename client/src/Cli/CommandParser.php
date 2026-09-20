<?php
/**
 * CLI 参数解析 —— 调试器入口的参数规范化
 *
 * 规则（与常见 CLI 一致，保持最小集）：
 *   - 首个不以 `-` 开头的 token 为命令（command），其余为位置参数（args）
 *   - `--name=value` / `--name value` 解析为选项值；`--flag` / `-f` 解析为 true
 *   - `--` 之后的所有 token 一律视为位置参数（避免 JSON、`-1` 被误判为选项）
 *   - 无命令时返回空命令，由调用方落 help
 *
 * 纯函数、无 IO，便于单测覆盖；兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Cli;

class CommandParser
{
    /**
     * 解析参数（不含程序名）
     *
     * @param array $args 形如 ['echo', '{"a":1}', '--uid=u1']
     * @return array{command:string, args:array<int,string>, options:array<string,mixed>}
     */
    public static function parse(array $args)
    {
        $command = '';
        $posArgs = array();
        $options = array();
        $literal = false; // `--` 之后进入字面量模式

        $count = count($args);
        for ($i = 0; $i < $count; $i++) {
            $token = (string)$args[$i];

            if ($literal) {
                if ($command === '') {
                    $command = $token;
                } else {
                    $posArgs[] = $token;
                }
                continue;
            }

            if ($token === '--') {
                $literal = true;
                continue;
            }

            // 长选项 --name[=value]
            if (strpos($token, '--') === 0 && strlen($token) > 2) {
                $body = substr($token, 2);
                $eq   = strpos($body, '=');
                if ($eq !== false) {
                    $options[substr($body, 0, $eq)] = substr($body, $eq + 1);
                    continue;
                }
                $next = isset($args[$i + 1]) ? (string)$args[$i + 1] : '';
                if ($next !== '' && strpos($next, '-') !== 0) {
                    $options[$body] = $next;
                    $i++;
                } else {
                    $options[$body] = true;
                }
                continue;
            }

            // 短选项 -f（单独出现时按开关处理；-f=value 也支持）
            if (strpos($token, '-') === 0 && strlen($token) > 1 && $token !== '-') {
                $body = ltrim($token, '-');
                $eq   = strpos($body, '=');
                if ($eq !== false) {
                    $options[substr($body, 0, $eq)] = substr($body, $eq + 1);
                } else {
                    $options[$body] = true;
                }
                continue;
            }

            if ($command === '') {
                $command = $token;
            } else {
                $posArgs[] = $token;
            }
        }

        return array(
            'command' => $command,
            'args'    => $posArgs,
            'options' => $options,
        );
    }

    /**
     * 取字符串选项
     *
     * @param array  $options
     * @param string $name
     * @param string $default
     * @return string
     */
    public static function str(array $options, $name, $default = '')
    {
        if (!isset($options[$name]) || is_bool($options[$name])) {
            return $default;
        }
        return (string)$options[$name];
    }

    /**
     * 取浮点选项
     *
     * @param array  $options
     * @param string $name
     * @param float  $default
     * @return float
     */
    public static function float(array $options, $name, $default = 0.0)
    {
        if (!isset($options[$name]) || is_bool($options[$name]) || !is_numeric((string)$options[$name])) {
            return $default;
        }
        return (float)$options[$name];
    }

    /**
     * 取开关选项
     *
     * @param array  $options
     * @param string $name
     * @return bool
     */
    public static function flag(array $options, $name)
    {
        return isset($options[$name]) && $options[$name] !== false;
    }
}
