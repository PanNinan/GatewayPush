<?php
/**
 * 业务动作的入参校验器
 *
 * 设计取舍：不引入第三方校验库，用轻量规则数组表达约束，保持零新增依赖。
 * 规则声明在 config/actions.php 的 params 段，与动作处理器一一对应。
 *
 * ---------------------------------------------------------------------
 * 规则格式
 * ---------------------------------------------------------------------
 *   'params' => [
 *       'topic' => ['type' => 'string', 'required' => true, 'max_len' => 64],
 *       'count' => ['type' => 'int',    'min' => 1, 'max' => 1000, 'default' => 1],
 *       'mode'  => ['type' => 'string', 'enum' => ['a', 'b'], 'default' => 'a'],
 *       'extra' => ['type' => 'array'],
 *       'raw'   => ['type' => 'json'],
 *   ]
 *
 * 支持的类型：string / int / float / bool / array / json
 * 支持的约束：required / default / enum / min / max / min_len / max_len / pattern
 *
 * 行为约定：
 *   - 未声明在规则中的入参一律丢弃（白名单语义），避免业务数据被意外透传；
 *   - required 缺失、类型不匹配、越界均返回 null 并置 $error；
 *   - 校验通过后返回「归一化参数」，处理器拿到的一定是正确类型。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Business;

class ParamValidator
{
    /**
     * 类型白名单
     *
     * @var array
     */
    protected static array $types = array('string', 'int', 'float', 'bool', 'array', 'json');

    /**
     * 校验并归一化参数
     *
     * @param array  $rules 规则数组
     * @param array  $input 原始入参
     * @param string $error 输出错误原因
     * @return array|null 失败返回 null
     */
    public static function validate(array $rules, array $input, &$error = null)
    {
        $error = '';
        $out   = [];

        foreach ($rules as $name => $rule) {
            $name = (string)$name;
            $rule = is_array($rule) ? $rule : array('type' => (string)$rule);

            $present = array_key_exists($name, $input) && $input[$name] !== null;

            if (!$present) {
                if (!empty($rule['required'])) {
                    $error = '缺少必要参数：' . $name;
                    return null;
                }
                if (array_key_exists('default', $rule)) {
                    $out[$name] = $rule['default'];
                }
                continue;
            }

            $type = isset($rule['type']) ? (string)$rule['type'] : 'string';
            if (!in_array($type, self::$types, true)) {
                // 规则写错时不做静默放行，直接暴露出来
                $error = '参数 ' . $name . ' 的规则类型非法：' . $type;
                return null;
            }

            $value = $input[$name];

            // 字符串类型的常规诉求是「去掉首尾空白」，在类型转换前完成
            if ($type === 'string' && is_string($value) && !empty($rule['trim'])) {
                $value = trim($value);
            }

            $casted = self::cast($value, $type, $name, $reason);
            if ($casted === null) {
                $error = $reason;
                return null;
            }

            $checked = self::checkRange($casted, $type, $rule, $name, $reason);
            if ($checked === null) {
                $error = $reason;
                return null;
            }

            $out[$name] = $checked;
        }

        return $out;
    }

    /**
     * 类型转换
     *
     * @param mixed  $value
     * @param string $type
     * @param string $name
     * @param string $reason 输出失败原因
     * @return mixed|null 失败返回 null
     */
    protected static function cast($value, $type, $name, &$reason)
    {
        $reason = '';

        switch ($type) {
            case 'string':
                if (is_array($value)) {
                    $reason = '参数 ' . $name . ' 必须是字符串';
                    return null;
                }
                if (is_bool($value)) {
                    // JSON 的 true/false 落到字符串时保持可预期
                    return $value ? 'true' : 'false';
                }
                return (string)$value;

            case 'int':
                if (is_int($value)) {
                    return $value;
                }
                if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
                    return (int)$value;
                }
                if (is_float($value) && floor($value) === $value) {
                    return (int)$value;
                }
                $reason = '参数 ' . $name . ' 必须是整数';
                return null;

            case 'float':
                if (is_int($value) || is_float($value)) {
                    return (float)$value;
                }
                if (is_string($value) && is_numeric($value)) {
                    return (float)$value;
                }
                $reason = '参数 ' . $name . ' 必须是数字';
                return null;

            case 'bool':
                if (is_bool($value)) {
                    return $value;
                }
                if ($value === 1 || $value === '1' || $value === 'true') {
                    return true;
                }
                if ($value === 0 || $value === '0' || $value === 'false') {
                    return false;
                }
                $reason = '参数 ' . $name . ' 必须是布尔值';
                return null;

            case 'array':
                if (!is_array($value)) {
                    $reason = '参数 ' . $name . ' 必须是对象或数组';
                    return null;
                }
                return $value;

            case 'json':
            default:
                if (is_array($value)) {
                    return $value;
                }
                if (!is_string($value)) {
                    $reason = '参数 ' . $name . ' 必须是 JSON 字符串';
                    return null;
                }
                $decoded = json_decode($value, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $reason = '参数 ' . $name . ' 不是合法 JSON';
                    return null;
                }
                return $decoded;
        }
    }

    /**
     * 范围与枚举约束
     *
     * @param mixed  $value 已转换的值
     * @param string $type
     * @param array  $rule
     * @param string $name
     * @param string $reason
     * @return mixed|null 失败返回 null
     */
    protected static function checkRange($value, $type, array $rule, $name, &$reason)
    {
        $reason = '';

        if (isset($rule['enum']) && is_array($rule['enum']) && !in_array($value, $rule['enum'], true)) {
            $reason = '参数 ' . $name . ' 取值不在允许范围内';
            return null;
        }

        if ($type === 'string') {
            $len = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);

            if (isset($rule['max_len']) && $len > (int)$rule['max_len']) {
                $reason = '参数 ' . $name . ' 长度超出上限 ' . (int)$rule['max_len'];
                return null;
            }
            if (isset($rule['min_len']) && $len < (int)$rule['min_len']) {
                $reason = '参数 ' . $name . ' 长度低于下限 ' . (int)$rule['min_len'];
                return null;
            }
            if (isset($rule['pattern']) && !preg_match((string)$rule['pattern'], $value)) {
                $reason = '参数 ' . $name . ' 格式不合法';
                return null;
            }
            return $value;
        }

        if ($type === 'int' || $type === 'float') {
            if (isset($rule['min']) && $value < $rule['min']) {
                $reason = '参数 ' . $name . ' 小于最小值 ' . $rule['min'];
                return null;
            }
            if (isset($rule['max']) && $value > $rule['max']) {
                $reason = '参数 ' . $name . ' 大于最大值 ' . $rule['max'];
                return null;
            }
            return $value;
        }

        if ($type === 'array') {
            if (isset($rule['max_len']) && count($value) > (int)$rule['max_len']) {
                $reason = '参数 ' . $name . ' 元素个数超出上限 ' . (int)$rule['max_len'];
                return null;
            }
            return $value;
        }

        return $value;
    }
}
