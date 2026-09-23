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
 * 纯函数、无 IO，便于单测覆盖；兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Client\Cli;

/**
 * CLI 参数解析（调试器入口的参数规范化）
 *
 * 纯函数、无 IO；`--` 之后的 token 一律视为位置参数。
 */
class CommandParser
{
    /**
     * 解析参数（不含程序名）
     *
     * @param array<int|string, mixed> $args 形如 ['echo', '{"a":1}', '--uid=u1']
     *
     * @return array{command:string, args:array<int,string>, options:array<string,mixed>}
     */
    public static function parse(array $args): array
    {
        $command = '';
        $posArgs = [];
        $options = [];
        $literal = false; // `--` 之后进入字面量模式

        // 必须用带索引的 for，不能用 foreach：下面 `--name value` 的分支要「消费掉
        // 下一个 token」，靠 $i++ 跳过。foreach 迭代的是自己的内部指针快照，循环体内
        // 修改 $i 不会影响下一次迭代 —— 值虽被写进 options，token 却会再次落进位置
        // 参数（CliParserTest::testParsesLongOptionWithEqualsAndSpace 已钉死该行为）。
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
            if (str_starts_with($token, '--') && strlen($token) > 2) {
                $body = substr($token, 2);
                $eq   = strpos($body, '=');
                if ($eq !== false) {
                    $options[substr($body, 0, $eq)] = substr($body, $eq + 1);

                    continue;
                }
                $next = isset($args[$i + 1]) ? (string)$args[$i + 1] : '';
                if ($next !== '' && !str_starts_with($next, '-')) {
                    $options[$body] = $next;
                    $i++;
                } else {
                    $options[$body] = true;
                }

                continue;
            }

            // 短选项 -f（单独出现时按开关处理；-f=value 也支持）
            if (str_starts_with($token, '-') && strlen($token) > 1 && $token !== '-') {
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

        return [
            'command' => $command,
            'args'    => $posArgs,
            'options' => $options,
        ];
    }

    /**
     * 取字符串选项
     *
     * @param array<string, mixed> $options
     * @param string               $name
     * @param string               $default
     *
     * @return string
     */
    public static function str(array $options, string $name, string $default = ''): string
    {
        if (!isset($options[$name]) || is_bool($options[$name])) {
            return $default;
        }

        return (string)$options[$name];
    }

    /**
     * 取浮点选项
     *
     * @param array<string, mixed> $options
     * @param string               $name
     * @param float                $default
     *
     * @return float
     */
    public static function float(array $options, string $name, float $default = 0.0): float
    {
        if (!isset($options[$name]) || is_bool($options[$name]) || !is_numeric((string)$options[$name])) {
            return $default;
        }

        return (float)$options[$name];
    }

    /**
     * 取开关选项
     *
     * @param array<string, mixed> $options
     * @param string               $name
     *
     * @return bool
     */
    public static function flag(array $options, string $name): bool
    {
        return isset($options[$name]) && $options[$name] !== false;
    }
}
