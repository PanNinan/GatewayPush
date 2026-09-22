<?php
/**
 * ParamValidator 单元测试
 *
 * 覆盖两条核心约定：
 *   1. 白名单语义 —— 未在规则中声明的入参一律丢弃，防止业务数据被意外透传；
 *   2. 类型归一化 —— 校验通过后处理器拿到的类型一定是确定的。
 *
 * 长度相关用例统一使用 ASCII 字符：本机未安装 mbstring，
 * 长度校验回退到 strlen（按字节计数），用 ASCII 可让断言与实现选择无关。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Tests\Unit;

use GatewayPush\Business\ParamValidator;
use PHPUnit\Framework\TestCase;

class ParamValidatorTest extends TestCase
{
    /**
     * 最近一次校验的错误原因
     *
     * @var string
     */
    private $error = '';

    protected function setUp(): void
    {
        $this->error = '';
    }

    /* ---------------------------------------------------------------------
     | 白名单语义
     --------------------------------------------------------------------- */

    public function testDropsParamsNotDeclaredInRules(): void
    {
        $out = $this->validate(
            ['keep' => ['type' => 'string']],
            ['keep' => 'a', 'drop' => 'b', 'nested' => ['x' => 1]]
        );

        self::assertSame(['keep' => 'a'], $out);
        self::assertSame('', $this->error);
    }

    public function testEmptyRulesDropEverything(): void
    {
        self::assertSame([], $this->validate([], ['a' => 1, 'b' => 2]));
        self::assertSame('', $this->error);
    }

    /* ---------------------------------------------------------------------
     | 必填与默认值
     --------------------------------------------------------------------- */

    public function testRequiredMissingProducesError(): void
    {
        self::assertNull($this->validate(['topic' => ['type' => 'string', 'required' => true]], []));
        self::assertSame('缺少必要参数：topic', $this->error);
    }

    public function testNullValueIsTreatedAsMissingForRequired(): void
    {
        self::assertNull($this->validate(
            ['topic' => ['type' => 'string', 'required' => true]],
            ['topic' => null]
        ));
        self::assertSame('缺少必要参数：topic', $this->error);
    }

    public function testDefaultIsAppliedWhenAbsent(): void
    {
        self::assertSame(['count' => 7], $this->validate(
            ['count' => ['type' => 'int', 'default' => 7]],
            []
        ));
    }

    public function testDefaultIsNotCast(): void
    {
        // default 原样写入、不做类型转换：配置写错时保持可见，而不是被静默修正
        self::assertSame(['count' => '7'], $this->validate(
            ['count' => ['type' => 'int', 'default' => '7']],
            []
        ));
    }

    /* ---------------------------------------------------------------------
     | string
     --------------------------------------------------------------------- */

    public function testStringCastsScalars(): void
    {
        $out = $this->validate(
            ['a' => ['type' => 'string'], 'b' => ['type' => 'string'], 'c' => ['type' => 'string']],
            ['a' => 123, 'b' => true, 'c' => false]
        );

        self::assertSame(['a' => '123', 'b' => 'true', 'c' => 'false'], $out);
    }

    public function testStringRejectsArray(): void
    {
        self::assertNull($this->validate(['a' => ['type' => 'string']], ['a' => [1]]));
        self::assertSame('参数 a 必须是字符串', $this->error);
    }

    public function testStringTrimAppliesOnlyWhenDeclared(): void
    {
        self::assertSame(['a' => 'x'], $this->validate(
            ['a' => ['type' => 'string', 'trim' => true]],
            ['a' => '  x  ']
        ));

        self::assertSame(['a' => '  x  '], $this->validate(
            ['a' => ['type' => 'string']],
            ['a' => '  x  ']
        ));
    }

    public function testStringLengthBounds(): void
    {
        $rules = ['a' => ['type' => 'string', 'max_len' => 3]];

        self::assertSame(['a' => 'abc'], $this->validate($rules, ['a' => 'abc']));

        self::assertNull($this->validate($rules, ['a' => 'abcd']));
        self::assertSame('参数 a 长度超出上限 3', $this->error);

        $min = ['b' => ['type' => 'string', 'min_len' => 2]];

        self::assertNull($this->validate($min, ['b' => 'a']));
        self::assertSame('参数 b 长度低于下限 2', $this->error);
    }

    public function testStringPattern(): void
    {
        $rules = ['topic' => ['type' => 'string', 'pattern' => '/^[A-Za-z0-9_]+$/']];

        self::assertSame(['topic' => 'abc_1'], $this->validate($rules, ['topic' => 'abc_1']));

        self::assertNull($this->validate($rules, ['topic' => 'abc-1']));
        self::assertSame('参数 topic 格式不合法', $this->error);
    }

    /* ---------------------------------------------------------------------
     | int / float
     --------------------------------------------------------------------- */

    public function testIntAcceptsIntNumericStringAndWholeFloat(): void
    {
        $out = $this->validate(
            ['a' => ['type' => 'int'], 'b' => ['type' => 'int'], 'c' => ['type' => 'int']],
            ['a' => 5, 'b' => '-12', 'c' => 3.0]
        );

        self::assertSame(['a' => 5, 'b' => -12, 'c' => 3], $out);
    }

    public function testIntRejectsNonIntegralValues(): void
    {
        $rules = ['a' => ['type' => 'int']];

        foreach (['abc', '1.5', ' 1', 1.5, true] as $bad) {
            self::assertNull($this->validate($rules, ['a' => $bad]), '应拒绝：' . var_export($bad, true));
            self::assertSame('参数 a 必须是整数', $this->error);
        }
    }

    public function testIntRangeBounds(): void
    {
        $rules = ['n' => ['type' => 'int', 'min' => 1, 'max' => 10]];

        self::assertSame(['n' => 1], $this->validate($rules, ['n' => 1]));
        self::assertSame(['n' => 10], $this->validate($rules, ['n' => 10]));

        self::assertNull($this->validate($rules, ['n' => 0]));
        self::assertSame('参数 n 小于最小值 1', $this->error);

        self::assertNull($this->validate($rules, ['n' => 11]));
        self::assertSame('参数 n 大于最大值 10', $this->error);
    }

    public function testFloatAcceptsNumericForms(): void
    {
        $rules = ['a' => ['type' => 'float'], 'b' => ['type' => 'float']];

        self::assertSame(['a' => 1.5, 'b' => 2.0], $this->validate($rules, ['a' => '1.5', 'b' => 2]));

        self::assertNull($this->validate($rules, ['a' => 'x']));
        self::assertSame('参数 a 必须是数字', $this->error);
    }

    /* ---------------------------------------------------------------------
     | bool
     --------------------------------------------------------------------- */

    public function testBoolAcceptsDeclaredLiterals(): void
    {
        $out = $this->validate(
            [
                'a' => ['type' => 'bool'],
                'b' => ['type' => 'bool'],
                'c' => ['type' => 'bool'],
                'd' => ['type' => 'bool'],
            ],
            ['a' => true, 'b' => '1', 'c' => 0, 'd' => 'false']
        );

        self::assertSame(['a' => true, 'b' => true, 'c' => false, 'd' => false], $out);
    }

    public function testBoolRejectsUndeclaredLiterals(): void
    {
        $rules = ['a' => ['type' => 'bool']];

        // 严格比较：'yes'/'on'/'TRUE' 均不被接受，避免宽松真值语义带来的歧义
        foreach (['yes', 'on', 'TRUE', 2, '01'] as $bad) {
            self::assertNull($this->validate($rules, ['a' => $bad]), '应拒绝：' . var_export($bad, true));
            self::assertSame('参数 a 必须是布尔值', $this->error);
        }
    }

    /* ---------------------------------------------------------------------
     | array / json
     --------------------------------------------------------------------- */

    public function testArrayTypeAndElementLimit(): void
    {
        $rules = ['list' => ['type' => 'array', 'max_len' => 2]];

        self::assertSame(['list' => [1, 2]], $this->validate($rules, ['list' => [1, 2]]));

        self::assertNull($this->validate($rules, ['list' => [1, 2, 3]]));
        self::assertSame('参数 list 元素个数超出上限 2', $this->error);

        self::assertNull($this->validate($rules, ['list' => 'not-array']));
        self::assertSame('参数 list 必须是对象或数组', $this->error);
    }

    public function testJsonAcceptsArrayDirectly(): void
    {
        $value = ['k' => 'v'];

        self::assertSame(['d' => $value], $this->validate(['d' => ['type' => 'json']], ['d' => $value]));
    }

    public function testJsonDecodesString(): void
    {
        self::assertSame(
            ['d' => ['k' => 'v']],
            $this->validate(['d' => ['type' => 'json']], ['d' => '{"k":"v"}'])
        );
    }

    public function testJsonRejectsInvalidInput(): void
    {
        $rules = ['d' => ['type' => 'json']];

        self::assertNull($this->validate($rules, ['d' => '{oops']));
        self::assertSame('参数 d 不是合法 JSON', $this->error);

        self::assertNull($this->validate($rules, ['d' => 123]));
        self::assertSame('参数 d 必须是 JSON 字符串', $this->error);
    }

    /* ---------------------------------------------------------------------
     | 枚举与规则自身合法性
     --------------------------------------------------------------------- */

    public function testEnumIsStrictlyCompared(): void
    {
        $rules = ['mode' => ['type' => 'string', 'enum' => ['drop', 'queue']]];

        self::assertSame(['mode' => 'queue'], $this->validate($rules, ['mode' => 'queue']));

        self::assertNull($this->validate($rules, ['mode' => 'other']));
        self::assertSame('参数 mode 取值不在允许范围内', $this->error);
    }

    public function testIllegalRuleTypeIsRejectedLoudly(): void
    {
        // 规则写错时不静默放行，直接暴露出来
        self::assertNull($this->validate(['a' => ['type' => 'datetime']], ['a' => 'x']));
        self::assertSame('参数 a 的规则类型非法：datetime', $this->error);
    }

    public function testRuleMayBeDeclaredAsPlainTypeString(): void
    {
        self::assertSame(['a' => 9], $this->validate(['a' => 'int'], ['a' => '9']));
    }

    /**
     * 以固定上下文执行校验，统一重置 $error
     *
     * @param array $rules
     * @param array $input
     *
     * @return null|array
     */
    private function validate(array $rules, array $input)
    {
        $this->error = '';

        return ParamValidator::validate($rules, $input, $this->error);
    }
}
