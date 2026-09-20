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
 * 兼容 PHP 8.0 ~ 8.5
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

    /**
     * 以固定上下文执行校验，统一重置 $error
     *
     * @param array $rules
     * @param array $input
     * @return array|null
     */
    private function validate(array $rules, array $input)
    {
        $this->error = '';

        return ParamValidator::validate($rules, $input, $this->error);
    }

    /* ---------------------------------------------------------------------
     | 白名单语义
     --------------------------------------------------------------------- */

    public function testDropsParamsNotDeclaredInRules(): void
    {
        $out = $this->validate(
            array('keep' => array('type' => 'string')),
            array('keep' => 'a', 'drop' => 'b', 'nested' => array('x' => 1))
        );

        self::assertSame(array('keep' => 'a'), $out);
        self::assertSame('', $this->error);
    }

    public function testEmptyRulesDropEverything(): void
    {
        self::assertSame(array(), $this->validate(array(), array('a' => 1, 'b' => 2)));
        self::assertSame('', $this->error);
    }

    /* ---------------------------------------------------------------------
     | 必填与默认值
     --------------------------------------------------------------------- */

    public function testRequiredMissingProducesError(): void
    {
        self::assertNull($this->validate(array('topic' => array('type' => 'string', 'required' => true)), array()));
        self::assertSame('缺少必要参数：topic', $this->error);
    }

    public function testNullValueIsTreatedAsMissingForRequired(): void
    {
        self::assertNull($this->validate(
            array('topic' => array('type' => 'string', 'required' => true)),
            array('topic' => null)
        ));
        self::assertSame('缺少必要参数：topic', $this->error);
    }

    public function testDefaultIsAppliedWhenAbsent(): void
    {
        self::assertSame(array('count' => 7), $this->validate(
            array('count' => array('type' => 'int', 'default' => 7)),
            array()
        ));
    }

    public function testDefaultIsNotCast(): void
    {
        // default 原样写入、不做类型转换：配置写错时保持可见，而不是被静默修正
        self::assertSame(array('count' => '7'), $this->validate(
            array('count' => array('type' => 'int', 'default' => '7')),
            array()
        ));
    }

    /* ---------------------------------------------------------------------
     | string
     --------------------------------------------------------------------- */

    public function testStringCastsScalars(): void
    {
        $out = $this->validate(
            array('a' => array('type' => 'string'), 'b' => array('type' => 'string'), 'c' => array('type' => 'string')),
            array('a' => 123, 'b' => true, 'c' => false)
        );

        self::assertSame(array('a' => '123', 'b' => 'true', 'c' => 'false'), $out);
    }

    public function testStringRejectsArray(): void
    {
        self::assertNull($this->validate(array('a' => array('type' => 'string')), array('a' => array(1))));
        self::assertSame('参数 a 必须是字符串', $this->error);
    }

    public function testStringTrimAppliesOnlyWhenDeclared(): void
    {
        self::assertSame(array('a' => 'x'), $this->validate(
            array('a' => array('type' => 'string', 'trim' => true)),
            array('a' => '  x  ')
        ));

        self::assertSame(array('a' => '  x  '), $this->validate(
            array('a' => array('type' => 'string')),
            array('a' => '  x  ')
        ));
    }

    public function testStringLengthBounds(): void
    {
        $rules = array('a' => array('type' => 'string', 'max_len' => 3));

        self::assertSame(array('a' => 'abc'), $this->validate($rules, array('a' => 'abc')));

        self::assertNull($this->validate($rules, array('a' => 'abcd')));
        self::assertSame('参数 a 长度超出上限 3', $this->error);

        $min = array('b' => array('type' => 'string', 'min_len' => 2));

        self::assertNull($this->validate($min, array('b' => 'a')));
        self::assertSame('参数 b 长度低于下限 2', $this->error);
    }

    public function testStringPattern(): void
    {
        $rules = array('topic' => array('type' => 'string', 'pattern' => '/^[A-Za-z0-9_]+$/'));

        self::assertSame(array('topic' => 'abc_1'), $this->validate($rules, array('topic' => 'abc_1')));

        self::assertNull($this->validate($rules, array('topic' => 'abc-1')));
        self::assertSame('参数 topic 格式不合法', $this->error);
    }

    /* ---------------------------------------------------------------------
     | int / float
     --------------------------------------------------------------------- */

    public function testIntAcceptsIntNumericStringAndWholeFloat(): void
    {
        $out = $this->validate(
            array('a' => array('type' => 'int'), 'b' => array('type' => 'int'), 'c' => array('type' => 'int')),
            array('a' => 5, 'b' => '-12', 'c' => 3.0)
        );

        self::assertSame(array('a' => 5, 'b' => -12, 'c' => 3), $out);
    }

    public function testIntRejectsNonIntegralValues(): void
    {
        $rules = array('a' => array('type' => 'int'));

        foreach (array('abc', '1.5', ' 1', 1.5, true) as $bad) {
            self::assertNull($this->validate($rules, array('a' => $bad)), '应拒绝：' . var_export($bad, true));
            self::assertSame('参数 a 必须是整数', $this->error);
        }
    }

    public function testIntRangeBounds(): void
    {
        $rules = array('n' => array('type' => 'int', 'min' => 1, 'max' => 10));

        self::assertSame(array('n' => 1), $this->validate($rules, array('n' => 1)));
        self::assertSame(array('n' => 10), $this->validate($rules, array('n' => 10)));

        self::assertNull($this->validate($rules, array('n' => 0)));
        self::assertSame('参数 n 小于最小值 1', $this->error);

        self::assertNull($this->validate($rules, array('n' => 11)));
        self::assertSame('参数 n 大于最大值 10', $this->error);
    }

    public function testFloatAcceptsNumericForms(): void
    {
        $rules = array('a' => array('type' => 'float'), 'b' => array('type' => 'float'));

        self::assertSame(array('a' => 1.5, 'b' => 2.0), $this->validate($rules, array('a' => '1.5', 'b' => 2)));

        self::assertNull($this->validate($rules, array('a' => 'x')));
        self::assertSame('参数 a 必须是数字', $this->error);
    }

    /* ---------------------------------------------------------------------
     | bool
     --------------------------------------------------------------------- */

    public function testBoolAcceptsDeclaredLiterals(): void
    {
        $out = $this->validate(
            array(
                'a' => array('type' => 'bool'),
                'b' => array('type' => 'bool'),
                'c' => array('type' => 'bool'),
                'd' => array('type' => 'bool'),
            ),
            array('a' => true, 'b' => '1', 'c' => 0, 'd' => 'false')
        );

        self::assertSame(array('a' => true, 'b' => true, 'c' => false, 'd' => false), $out);
    }

    public function testBoolRejectsUndeclaredLiterals(): void
    {
        $rules = array('a' => array('type' => 'bool'));

        // 严格比较：'yes'/'on'/'TRUE' 均不被接受，避免宽松真值语义带来的歧义
        foreach (array('yes', 'on', 'TRUE', 2, '01') as $bad) {
            self::assertNull($this->validate($rules, array('a' => $bad)), '应拒绝：' . var_export($bad, true));
            self::assertSame('参数 a 必须是布尔值', $this->error);
        }
    }

    /* ---------------------------------------------------------------------
     | array / json
     --------------------------------------------------------------------- */

    public function testArrayTypeAndElementLimit(): void
    {
        $rules = array('list' => array('type' => 'array', 'max_len' => 2));

        self::assertSame(array('list' => array(1, 2)), $this->validate($rules, array('list' => array(1, 2))));

        self::assertNull($this->validate($rules, array('list' => array(1, 2, 3))));
        self::assertSame('参数 list 元素个数超出上限 2', $this->error);

        self::assertNull($this->validate($rules, array('list' => 'not-array')));
        self::assertSame('参数 list 必须是对象或数组', $this->error);
    }

    public function testJsonAcceptsArrayDirectly(): void
    {
        $value = array('k' => 'v');

        self::assertSame(array('d' => $value), $this->validate(array('d' => array('type' => 'json')), array('d' => $value)));
    }

    public function testJsonDecodesString(): void
    {
        self::assertSame(
            array('d' => array('k' => 'v')),
            $this->validate(array('d' => array('type' => 'json')), array('d' => '{"k":"v"}'))
        );
    }

    public function testJsonRejectsInvalidInput(): void
    {
        $rules = array('d' => array('type' => 'json'));

        self::assertNull($this->validate($rules, array('d' => '{oops')));
        self::assertSame('参数 d 不是合法 JSON', $this->error);

        self::assertNull($this->validate($rules, array('d' => 123)));
        self::assertSame('参数 d 必须是 JSON 字符串', $this->error);
    }

    /* ---------------------------------------------------------------------
     | 枚举与规则自身合法性
     --------------------------------------------------------------------- */

    public function testEnumIsStrictlyCompared(): void
    {
        $rules = array('mode' => array('type' => 'string', 'enum' => array('drop', 'queue')));

        self::assertSame(array('mode' => 'queue'), $this->validate($rules, array('mode' => 'queue')));

        self::assertNull($this->validate($rules, array('mode' => 'other')));
        self::assertSame('参数 mode 取值不在允许范围内', $this->error);
    }

    public function testIllegalRuleTypeIsRejectedLoudly(): void
    {
        // 规则写错时不静默放行，直接暴露出来
        self::assertNull($this->validate(array('a' => array('type' => 'datetime')), array('a' => 'x')));
        self::assertSame('参数 a 的规则类型非法：datetime', $this->error);
    }

    public function testRuleMayBeDeclaredAsPlainTypeString(): void
    {
        self::assertSame(array('a' => 9), $this->validate(array('a' => 'int'), array('a' => '9')));
    }
}
