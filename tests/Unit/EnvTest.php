<?php
/**
 * Env 单元测试
 *
 * 隔离手段：Env::lookup() 的读取顺序是 $_ENV -> $_SERVER -> getenv()，
 * 因此用例只需把测试键写入 $_ENV，即可优先命中，不受真实 .env 影响。
 * 用例结束后在 tearDown 中还原，避免污染其他用例。
 *
 * 重点固定两类易错语义：
 *   1. 「键存在但为空」与「键不存在」是两回事 —— 前者返回空串，不回落默认值；
 *   2. bool 只识别 1/true/yes/on（大小写不敏感），其余一律为 false，
 *      避免 'false' 被 PHP 强转成 true。
 *
 * 兼容 PHP 8.0 ~ 8.5
 */

namespace GatewayPush\Tests\Unit;

use GatewayPush\Common\Env;
use PHPUnit\Framework\TestCase;

class EnvTest extends TestCase
{
    /**
     * 本次用例注入的键，tearDown 中还原
     *
     * @var array
     */
    private $injected = array();

    protected function tearDown(): void
    {
        foreach ($this->injected as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->injected = array();
    }

    /**
     * 向 $_ENV 注入测试值
     *
     * @param string $key
     * @param mixed  $value
     * @return string 便于链式使用
     */
    private function inject($key, $value)
    {
        $this->injected[] = $key;
        $_ENV[$key] = $value;

        return $key;
    }

    /**
     * 保证不存在的键名，用于验证默认值回落
     *
     * @param string $suffix
     * @return string
     */
    private static function absent($suffix)
    {
        return 'GW_UNIT_TEST_ABSENT_' . $suffix;
    }

    /* ---------------------------------------------------------------------
     | str
     --------------------------------------------------------------------- */

    public function testStrFallsBackWhenKeyAbsent(): void
    {
        $key = self::absent('STR');

        self::assertFalse(Env::has($key));
        self::assertSame('fallback', Env::str($key, 'fallback'));
    }

    public function testStrCastsScalarValue(): void
    {
        self::assertSame('123', Env::str($this->inject('GW_UNIT_TEST_STR', 123), 'fallback'));
    }

    public function testStrPreservesExplicitEmptyString(): void
    {
        $key = $this->inject('GW_UNIT_TEST_EMPTY', '');

        self::assertTrue(Env::has($key));
        self::assertSame('', Env::str($key, 'fallback'));
    }

    public function testStrNormalizesNullToEmptyString(): void
    {
        // 环境变量中的 null 视为空串，保留「键存在」这一事实
        self::assertSame('', Env::str($this->inject('GW_UNIT_TEST_NULL', null), 'fallback'));
    }

    public function testStrFallsBackForArrayValue(): void
    {
        self::assertSame('fallback', Env::str($this->inject('GW_UNIT_TEST_STR_ARR', array('x')), 'fallback'));
    }

    /* ---------------------------------------------------------------------
     | int / float
     --------------------------------------------------------------------- */

    public function testIntParsesNumericString(): void
    {
        self::assertSame(42, Env::int($this->inject('GW_UNIT_TEST_INT', '42'), 7));
    }

    public function testIntFallsBackForEmptyAndNonNumeric(): void
    {
        self::assertSame(7, Env::int($this->inject('GW_UNIT_TEST_INT_EMPTY', ''), 7));
        self::assertSame(7, Env::int($this->inject('GW_UNIT_TEST_INT_BAD', 'abc'), 7));
    }

    public function testIntFallsBackWhenAbsent(): void
    {
        self::assertSame(7, Env::int(self::absent('INT'), 7));
    }

    public function testFloatParsesNumericString(): void
    {
        self::assertSame(1.25, Env::float($this->inject('GW_UNIT_TEST_FLOAT', '1.25'), 9.9));
    }

    public function testFloatFallsBackForEmptyAndAbsent(): void
    {
        self::assertSame(9.9, Env::float($this->inject('GW_UNIT_TEST_FLOAT_EMPTY', ''), 9.9));
        self::assertSame(9.9, Env::float(self::absent('FLOAT'), 9.9));
    }

    /* ---------------------------------------------------------------------
     | bool
     --------------------------------------------------------------------- */

    public function testBoolRecognizesTruthyLiteralsCaseInsensitively(): void
    {
        foreach (array('1', 'true', 'TRUE', 'yes', 'On', ' on ') as $literal) {
            self::assertTrue(
                Env::bool($this->inject('GW_UNIT_TEST_BOOL_TRUE', $literal), false),
                '应识别为真：' . $literal
            );
        }
    }

    public function testBoolTreatsOtherLiteralsAsFalse(): void
    {
        // 注意：这些返回 false 而非默认值 —— 避免 'false' 被 PHP 强转为 true
        foreach (array('0', 'false', 'no', 'off') as $literal) {
            self::assertFalse(
                Env::bool($this->inject('GW_UNIT_TEST_BOOL_FALSE', $literal), true),
                '应识别为假：' . $literal
            );
        }
    }

    public function testBoolFallsBackForEmptyString(): void
    {
        self::assertTrue(Env::bool($this->inject('GW_UNIT_TEST_BOOL_EMPTY', ''), true));
    }

    public function testBoolPassesThroughRealBoolean(): void
    {
        self::assertFalse(Env::bool($this->inject('GW_UNIT_TEST_BOOL_RAW', false), true));
    }

    public function testBoolFallsBackWhenAbsent(): void
    {
        self::assertTrue(Env::bool(self::absent('BOOL'), true));
    }

    /* ---------------------------------------------------------------------
     | list
     --------------------------------------------------------------------- */

    public function testListSplitsTrimsAndDropsEmptyItems(): void
    {
        self::assertSame(
            array('a', 'b', 'c'),
            Env::list($this->inject('GW_UNIT_TEST_LIST', 'a, b ,,c '), array('x'))
        );
    }

    public function testListFallsBackForBlankOrAbsent(): void
    {
        self::assertSame(array('x'), Env::list($this->inject('GW_UNIT_TEST_LIST_BLANK', '   '), array('x')));
        self::assertSame(array('x'), Env::list($this->inject('GW_UNIT_TEST_LIST_COMMAS', ',,'), array('x')));
        self::assertSame(array('x'), Env::list(self::absent('LIST'), array('x')));
    }

    /* ---------------------------------------------------------------------
     | has
     --------------------------------------------------------------------- */

    public function testHasDistinguishesPresentFromAbsent(): void
    {
        self::assertTrue(Env::has($this->inject('GW_UNIT_TEST_HAS', '')));
        self::assertFalse(Env::has(self::absent('HAS')));
    }
}
