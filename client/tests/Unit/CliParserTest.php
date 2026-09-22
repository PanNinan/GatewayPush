<?php
/**
 * CommandParser 单测
 *
 * 覆盖：命令/位置参数识别、长短选项、开关、`--` 字面量、取值辅助
 */

namespace GatewayPush\Client\Tests\Unit;

use GatewayPush\Client\Cli\CommandParser;
use PHPUnit\Framework\TestCase;

class CliParserTest extends TestCase
{
    public function testParsesCommandAndPositionalArgs()
    {
        $parsed = CommandParser::parse(array('echo', '{"a":1}', 'extra'));

        self::assertSame('echo', $parsed['command']);
        self::assertSame(array('{"a":1}', 'extra'), $parsed['args']);
        self::assertSame([], $parsed['options']);
    }

    public function testParsesLongOptionWithEqualsAndSpace()
    {
        $parsed = CommandParser::parse(array('echo', '--uid=u1', '--device', 'd1'));

        self::assertSame('u1', $parsed['options']['uid']);
        self::assertSame('d1', $parsed['options']['device']);
        self::assertSame([], $parsed['args']);
    }

    public function testFlagWithoutValueBecomesTrue()
    {
        $parsed = CommandParser::parse(array('push', '{}', '--bad-sign'));

        self::assertTrue($parsed['options']['bad-sign']);
        self::assertTrue(CommandParser::flag($parsed['options'], 'bad-sign'));
        self::assertFalse(CommandParser::flag($parsed['options'], 'missing'));
    }

    public function testShortOptionIsSwitch()
    {
        $parsed = CommandParser::parse(array('help', '-v'));

        self::assertTrue($parsed['options']['v']);
    }

    public function testDoubleDashStopsOptionParsing()
    {
        $parsed = CommandParser::parse(array('echo', '--', '--not-an-option'));

        self::assertSame('echo', $parsed['command']);
        self::assertSame(array('--not-an-option'), $parsed['args']);
        self::assertSame([], $parsed['options']);
    }

    public function testEmptyArgsYieldsEmptyCommand()
    {
        $parsed = CommandParser::parse(array());

        self::assertSame('', $parsed['command']);
    }

    public function testTypedAccessors()
    {
        $options = array('timeout' => '2.5', 'hb' => true, 'uid' => 123);

        self::assertSame('2.5', CommandParser::str($options, 'timeout', '5'));
        self::assertSame('123', CommandParser::str($options, 'uid', ''));
        self::assertSame('', CommandParser::str($options, 'hb', ''));       // 布尔开关不取值
        self::assertSame('x', CommandParser::str($options, 'absent', 'x'));
        self::assertSame(2.5, CommandParser::float($options, 'timeout', 1.0));
        self::assertSame(1.0, CommandParser::float($options, 'absent', 1.0));
        self::assertSame(1.0, CommandParser::float($options, 'hb', 1.0));   // 非数值回落默认
    }
}
