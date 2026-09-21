<?php
/**
 * Logger 单元测试（文件命名与通道分流）
 *
 * 覆盖日志结构从「按级别 + 日期」调整为「按角色 + 日期」后的关键性质。
 * 这几条一旦被改坏，后果不是「日志难看」而是排查能力失效：
 *   - 角色名直接拼进文件名，未限制字符集即产生路径穿越；
 *   - 保留字 error 若被当作角色名，会与跨角色汇总通道同名互相覆盖；
 *   - 分角色失效会退回「全部角色共写一个文件」，无法按角色定位故障；
 *   - 汇总通道若对非 error 级也写，会退化成全量日志的第二份副本。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Tests\Unit;

use GatewayPush\Common\Logger;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    /**
     * 用例独占的日志目录
     *
     * @var string
     */
    private $logDir = '';

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gwpush-logger-test';
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0777, true);
        }
        $this->clearLogs();

        // Logger 是静态类，配置会跨用例残留，每个用例前完整重置一次
        Logger::init(array(
            'path'      => $this->logDir,
            'level'     => Logger::DEBUG,
            'role'      => Logger::CHANNEL_DEFAULT,
            'keep_days' => 30,
            'stdout'    => false,
        ));
    }

    protected function tearDown(): void
    {
        $this->clearLogs();
        @rmdir($this->logDir);
        // 复位默认通道，避免影响后续用例
        Logger::init(array('role' => Logger::CHANNEL_DEFAULT));
    }

    /* ---------------------------------------------------------------------
     | 通道命名：{role}_{YYYY-MM-DD}.log
     --------------------------------------------------------------------- */

    public function testLogLandsInChannelNamedByRoleAndDate(): void
    {
        Logger::useChannel('gateway');
        Logger::info('网关事件');

        $file = $this->path('gateway_' . date('Y-m-d') . '.log');
        $this->assertFileExists($file);

        $content = (string)file_get_contents($file);
        $this->assertStringContainsString('网关事件', $content);
        $this->assertStringContainsString('[INFO]', $content);
    }

    public function testDifferentRolesWriteToDifferentFiles(): void
    {
        Logger::useChannel('gateway');
        Logger::info('来自网关');
        Logger::useChannel('business');
        Logger::info('来自业务');

        $gateway  = (string)file_get_contents($this->path('gateway_' . date('Y-m-d') . '.log'));
        $business = (string)file_get_contents($this->path('business_' . date('Y-m-d') . '.log'));

        $this->assertStringContainsString('来自网关', $gateway);
        $this->assertStringNotContainsString('来自业务', $gateway);
        $this->assertStringContainsString('来自业务', $business);
    }

    /* ---------------------------------------------------------------------
     | error 跨角色汇总通道
     --------------------------------------------------------------------- */

    public function testErrorIsDuplicatedIntoDigestChannel(): void
    {
        Logger::useChannel('gateway');
        Logger::error('连接失败');

        $roleFile   = $this->path('gateway_' . date('Y-m-d') . '.log');
        $digestFile = $this->path('error_' . date('Y-m-d') . '.log');

        $this->assertFileExists($roleFile);
        $this->assertFileExists($digestFile);

        // 两条通道写入同一行内容，含各自角色名无关的公共格式
        $this->assertSame(
            (string)file_get_contents($roleFile),
            (string)file_get_contents($digestFile)
        );
    }

    public function testDigestChannelAggregatesAcrossRoles(): void
    {
        Logger::useChannel('gateway');
        Logger::error('网关侧错误');
        Logger::useChannel('api');
        Logger::error('接口侧错误');

        $digest = (string)file_get_contents($this->path('error_' . date('Y-m-d') . '.log'));

        $this->assertStringContainsString('网关侧错误', $digest);
        $this->assertStringContainsString('接口侧错误', $digest);
    }

    public function testNonErrorLevelsDoNotReachDigestChannel(): void
    {
        Logger::useChannel('gateway');
        Logger::warn('仅告警');
        Logger::info('仅信息');

        $this->assertFileDoesNotExist($this->path('error_' . date('Y-m-d') . '.log'));
    }

    /* ---------------------------------------------------------------------
     | 角色名归一化
     --------------------------------------------------------------------- */

    /**
     * @dataProvider invalidRoleProvider
     */
    public function testInvalidRoleFallsBackToDefaultChannel($role): void
    {
        Logger::useChannel($role);
        Logger::info('回落测试');

        $this->assertFileExists($this->path(Logger::CHANNEL_DEFAULT . '_' . date('Y-m-d') . '.log'));
    }

    /**
     * @return array
     */
    public function invalidRoleProvider(): array
    {
        return array(
            '空串'         => array(''),
            '路径穿越'     => array('../../evil'),
            '含正斜杠'     => array('foo/bar'),
            '含反斜杠'     => array('foo\\bar'),
            '数字开头'     => array('1abc'),
            '超长(17 字符)' => array(str_repeat('a', 17)),
            'error 保留字' => array(Logger::CHANNEL_ERROR_DIGEST),
        );
    }

    public function testReservedErrorRoleDoesNotShadowDigestChannel(): void
    {
        // role 取 error 时必须回落：否则角色文件与汇总通道同名，两者互相覆盖
        Logger::useChannel('error');
        Logger::info('普通信息');

        $this->assertFileExists($this->path(Logger::CHANNEL_DEFAULT . '_' . date('Y-m-d') . '.log'));
        // info 级本就不写汇总通道，此处也不应因角色名撞名而写入
        $this->assertFileDoesNotExist($this->path('error_' . date('Y-m-d') . '.log'));
    }

    /* ---------------------------------------------------------------------
     | 级别门控与清理兼容性
     --------------------------------------------------------------------- */

    public function testLevelThresholdDiscardsLowerLevels(): void
    {
        Logger::init(array('path' => $this->logDir, 'level' => Logger::WARN, 'stdout' => false));
        Logger::useChannel('gateway');

        Logger::info('应被丢弃');
        Logger::warn('应落盘');

        $content = (string)file_get_contents($this->path('gateway_' . date('Y-m-d') . '.log'));
        $this->assertStringNotContainsString('应被丢弃', $content);
        $this->assertStringContainsString('应落盘', $content);
    }

    public function testCleanupRemovesExpiredFilesUnderNewNaming(): void
    {
        $stale = $this->path('gateway_2020-01-01.log');
        file_put_contents($stale, "old\n");
        touch($stale, (int)strtotime('-40 day'));

        $fresh = $this->path('business_2020-01-01.log');
        file_put_contents($fresh, "new\n");

        Logger::init(array('path' => $this->logDir, 'keep_days' => 30, 'stdout' => false));
        $removed = Logger::cleanup();

        $this->assertSame(1, $removed);
        $this->assertFileDoesNotExist($stale);
        $this->assertFileExists($fresh);
    }

    /* ---------------------------------------------------------------------
     | 结构性断言：防止新增角色时漏切通道
     |
     | 这是本次改造中最容易静默失效的一环 —— 漏掉一处，该角色的日志会默默
     | 落进启动期通道（all / app），既不报错也看不出来，直到排查时才发现
     | 日志不在预期文件里。故直接在源码层校验调用点存在。
     --------------------------------------------------------------------- */

    public function testStartupEntrySwitchesChannelByRole(): void
    {
        $code = (string)file_get_contents($this->root('start.php'));
        $this->assertMatchesRegularExpression(
            '/Logger::useChannel\(\$role\)/',
            $code,
            'start.php 未按启动角色切换日志通道'
        );
    }

    public function testEveryOnWorkerStartSwitchesToItsOwnChannel(): void
    {
        $expect = array(
            'src/Gateway/Bootstrap.php'   => 3,   // register / gateway / udp
            'src/Business/Bootstrap.php'  => 1,
            'src/Api/Bootstrap.php'       => 1,
            'src/Dashboard/Bootstrap.php' => 1,
        );

        foreach ($expect as $file => $count) {
            $code = (string)file_get_contents($this->root($file));

            preg_match_all('/onWorkerStart\s*=\s*function[^{]*\{/', $code, $hits, PREG_OFFSET_CAPTURE);
            $this->assertCount(
                $count,
                $hits[0],
                $file . ' 的 onWorkerStart 数量与预期不符，请同步本测试的清单'
            );

            foreach ($hits[0] as $hit) {
                $body = substr($code, $hit[1], 500);
                $this->assertMatchesRegularExpression(
                    "/Logger::useChannel\('[a-z]+'\)/",
                    $body,
                    $file . ' 存在未切换日志通道的 onWorkerStart'
                );
            }
        }
    }

    /* ---------------------------------------------------------------------
     | 辅助
     --------------------------------------------------------------------- */

    /**
     * 解析项目根下的文件路径（不依赖当前工作目录）
     *
     * @param string $relative
     * @return string
     */
    private function root($relative)
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    /**
     * @param string $name
     * @return string
     */
    private function path($name)
    {
        return $this->logDir . DIRECTORY_SEPARATOR . $name;
    }

    /**
     * 清空用例目录下的日志文件
     *
     * @return void
     */
    private function clearLogs()
    {
        $files = glob($this->logDir . DIRECTORY_SEPARATOR . '*.log');
        if (is_array($files)) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }
}
