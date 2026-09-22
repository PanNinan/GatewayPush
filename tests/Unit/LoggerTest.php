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
 * 兼容 PHP 8.2 ~ 8.5
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
            mkdir($this->logDir, 0o777, true);
        }
        $this->clearLogs();

        // Logger 是静态类，配置会跨用例残留，每个用例前完整重置一次
        Logger::init([
            'path'      => $this->logDir,
            'level'     => Logger::DEBUG,
            'role'      => Logger::CHANNEL_DEFAULT,
            'keep_days' => 30,
            'stdout'    => false,
        ]);
    }

    protected function tearDown(): void
    {
        $this->clearLogs();
        @rmdir($this->logDir);
        // 复位默认通道，避免影响后续用例
        Logger::init(['role' => Logger::CHANNEL_DEFAULT]);
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
        return [
            '空串'         => [''],
            '路径穿越'     => ['../../evil'],
            '含正斜杠'     => ['foo/bar'],
            '含反斜杠'     => ['foo\bar'],
            '数字开头'     => ['1abc'],
            '超长(17 字符)' => [str_repeat('a', 17)],
            'error 保留字' => [Logger::CHANNEL_ERROR_DIGEST],
        ];
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
        Logger::init(['path' => $this->logDir, 'level' => Logger::WARN, 'stdout' => false]);
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

        Logger::init(['path' => $this->logDir, 'keep_days' => 30, 'stdout' => false]);
        $removed = Logger::cleanup();

        $this->assertSame(1, $removed);
        $this->assertFileDoesNotExist($stale);
        $this->assertFileExists($fresh);
    }

    /* ---------------------------------------------------------------------
     | 归档：明文 -> 月度 tar.gz -> 删明文
     |
     | 这几条守的是数据安全，不是「功能有没有」：
     |   - 包损坏时若继续归档并删明文，历史日志就永久没了（不可逆）；
     |   - 追加若写成覆盖，同月的旧归档会被整包清掉；
     |   - 包名若按归档时刻而非文件自身日期取，跨月归档会串包；
     |   - 任务顺序若颠倒，超期明文先被 cleanup 删掉，归档永远拿不到内容。
     --------------------------------------------------------------------- */

    public function testArchivePacksExpiredLogsIntoMonthPackAndRemovesPlaintext(): void
    {
        $aug1 = $this->makeStaleLog('api_2026-08-30.log', "aug-30-payload\n", 20);
        $aug2 = $this->makeStaleLog('gateway_2026-08-31.log', "aug-31-payload\n", 20);
        $sep  = $this->makeStaleLog('api_2026-09-01.log', "sep-01-payload\n", 20);

        Logger::init($this->archiveConfig());
        $this->assertSame(3, Logger::archive());

        // 明文必须被删：留着就等于没有归档，文件数照样增长
        $this->assertFileDoesNotExist($aug1);
        $this->assertFileDoesNotExist($aug2);
        $this->assertFileDoesNotExist($sep);

        // 包按「文件自身日期」的月份分组，而非归档发生的月份
        $augPack = $this->readPack('2026-08');
        $this->assertStringContainsString('api_2026-08-30.log', $augPack);
        $this->assertStringContainsString('aug-30-payload', $augPack);
        $this->assertStringContainsString('gateway_2026-08-31.log', $augPack);
        $this->assertStringNotContainsString(
            'api_2026-09-01.log',
            $augPack,
            '9 月日志不得混进 8 月包，否则归档按月份取用即失效'
        );

        $sepPack = $this->readPack('2026-09');
        $this->assertStringContainsString('api_2026-09-01.log', $sepPack);
        $this->assertStringContainsString('sep-01-payload', $sepPack);
    }

    public function testArchiveAppendsToExistingPackInsteadOfOverwriting(): void
    {
        Logger::init($this->archiveConfig());

        $this->makeStaleLog('api_2026-08-10.log', "first-batch\n", 20);
        Logger::archive();

        $this->makeStaleLog('gateway_2026-08-11.log', "second-batch\n", 20);
        Logger::archive();

        $raw = $this->readPack('2026-08');
        $this->assertStringContainsString('first-batch', $raw, '追加时不得覆盖既有条目');
        $this->assertStringContainsString('second-batch', $raw);
    }

    public function testArchiveIgnoresUndatedFilesAndFreshFiles(): void
    {
        // 无日期后缀：归 LOG_MAX_MB 管，不该进归档
        $workerLog = $this->makeStaleLog('workerman.log', "worker-log\n", 60);
        // 未超过 archive_after_days：仍属热日志，供实时排查
        $fresh     = $this->makeStaleLog('api_' . date('Y-m-d') . '.log', "fresh\n", 1);

        Logger::init($this->archiveConfig());
        $this->assertSame(0, Logger::archive());

        $this->assertFileExists($workerLog, '无日期命名的文件既不该被归档，也不该被删');
        $this->assertFileExists($fresh, '未超过阈值的明文不得被归档');
        $this->assertFileDoesNotExist($this->archivePath('2026-08'), '无待归档内容时不该产生空包');
    }

    public function testArchiveKeepsPlaintextWhenExistingPackIsCorrupt(): void
    {
        if (!is_dir($this->archiveDir())) {
            mkdir($this->archiveDir(), 0o777, true);
        }
        file_put_contents($this->archivePath('2026-08'), 'not-a-gzip-stream');

        $plain = $this->makeStaleLog('api_2026-08-30.log', "must-survive\n", 20);

        Logger::init($this->archiveConfig());
        $this->assertSame(0, Logger::archive(), '包损坏时不得报告归档成功');

        $this->assertFileExists($plain, '包写不成时必须留下明文，否则数据永久丢失');
        $this->assertSame(
            'not-a-gzip-stream',
            (string)file_get_contents($this->archivePath('2026-08')),
            '损坏的既有归档也不能被覆盖，否则连带毁掉其中历史'
        );
    }

    /**
     * @dataProvider archiveNoOpProvider
     */
    public function testArchiveIsNoOpWhenDisabledOrThresholdsInvalid(array $overrides): void
    {
        $plain = $this->makeStaleLog('api_2026-08-30.log', "keep-me\n", 60);

        Logger::init($this->archiveConfig($overrides));
        $this->assertSame(0, Logger::archive());
        $this->assertFileExists($plain);
        $this->assertFileDoesNotExist($this->archivePath('2026-08'));
    }

    public function archiveNoOpProvider(): array
    {
        return [
            '未开启归档'       => [['archive_enable' => false]],
            '阈值等于保留期'   => [['archive_after_days' => 30]],
            '阈值大于保留期'   => [['archive_after_days' => 45]],
            '阈值为零'         => [['archive_after_days' => 0]],
        ];
    }

    public function testArchivePurgesExpiredPacks(): void
    {
        Logger::init($this->archiveConfig());
        $this->makeStaleLog('api_2026-08-30.log', "old-pack\n", 20);
        Logger::archive();

        $pack = $this->archivePath('2026-08');
        $this->assertFileExists($pack);

        touch($pack, (int)strtotime('-200 day'));   // 超过 archive_keep_days = 180
        Logger::archive();

        $this->assertFileDoesNotExist($pack, '超过 archive_keep_days 的归档包应被清理');
    }

    public function testTarHeaderMatchesUstarLayout(): void
    {
        // 1000 字节：跨越 512 边界，可同时验证数据体补齐规则
        $this->makeStaleLog('api_2026-08-30.log', str_repeat('x', 1000), 20);

        Logger::init($this->archiveConfig());
        Logger::archive();

        $raw    = $this->readPack('2026-08');
        $header = substr($raw, 0, 512);

        $this->assertSame(512, strlen($header), 'tar 头必须是固定 512 字节块');
        $this->assertSame(
            'api_2026-08-30.log',
            rtrim(substr($header, 0, 100), "\0"),
            '条目名须落在偏移 0，否则解包出来是乱码'
        );
        $this->assertSame(
            1000,
            (int)octdec(trim(substr($header, 124, 12), "\0 ")),
            'size 须为八进制且落在偏移 124'
        );
        $this->assertSame("ustar\0" . '00', substr($header, 257, 8), 'ustar magic + version 须落在偏移 257');
        $this->assertSame('0', $header[156], 'typeflag 须为普通文件');

        // 校验和：以 checksum 字段自身按 8 空格代入重算，须与写入值一致。
        // 不符时 GNU tar 会直接判定归档损坏（"A lone zero block" / checksum error）
        $expected = 0;
        for ($i = 0; $i < 512; $i++) {
            $expected += ord($i >= 148 && $i < 156 ? ' ' : $header[$i]);
        }
        $this->assertSame(
            (int)octdec(trim(substr($header, 148, 8), "\0 ")),
            $expected,
            'tar 校验和不符，归档无法被解包工具读取'
        );

        $this->assertSame(
            512 + 1024 + 1024,
            strlen($raw),
            '结构应为「头 512 + 数据补齐到 512 整数倍（1000 -> 1024）+ 终止双空块 1024」'
        );
    }

    /**
     * @dataProvider archiveMonthProvider
     */
    public function testArchiveMonthOfParsesOnlyDatedNaming($name, $expected): void
    {
        $this->assertSame($expected, Logger::archiveMonthOf($name));
    }

    public function archiveMonthProvider(): array
    {
        return [
            '角色日志'     => ['api_2026-09-01.log', '2026-09'],
            '汇总通道'     => ['error_2026-12-31.log', '2026-12'],
            '含下划线角色' => ['my_role_2026-01-05.log', '2026-01'],
            'workerman'    => ['workerman.log', null],
            'stdout'       => ['stdout.log', null],
            '归档产物'     => ['2026-09.tar.gz', null],
            '缺日期'       => ['api_2026-09.log', null],
            '角色名超长'   => [str_repeat('a', 17) . '_2026-09-01.log', null],
            '大写角色'     => ['API_2026-09-01.log', null],
            '路径穿越尝试' => ['../api_2026-09-01.log', null],
        ];
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
        $expect = [
            'src/Gateway/Bootstrap.php'   => 3,   // register / gateway / udp
            'src/Business/Bootstrap.php'  => 1,
            'src/Api/Bootstrap.php'       => 1,
            'src/Dashboard/Bootstrap.php' => 1,
        ];

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
                    "/Logger::useChannel\\('[a-z]+'\\)/",
                    $body,
                    $file . ' 存在未切换日志通道的 onWorkerStart'
                );
            }
        }
    }

    /* ---------------------------------------------------------------------
     | 清理任务的调度接线
     |
     | 清理逻辑本身没问题，问题在「什么时候跑」：workerman 的 Timer::add 是
     | 延迟首跑 —— 先等满一个 interval 才执行第一次。周期 86400s 的任务只要进程
     | 活不满一天就一次都不执行，而且完全静默（任务注册成功、无报错、count 恒 0）。
     | 开发机每天重启，实际就是「从未清理过」。故把接线钉死在此。
     --------------------------------------------------------------------- */

    public function testCleanupTaskIsWiredToRunAtStartup(): void
    {
        $block = $this->taskBlock('log-cleanup');

        $this->assertMatchesRegularExpression(
            "/'interval'\\s*=>\\s*86400/",
            $block,
            '日志清理的周期应为 24h（86400s）'
        );
        $this->assertMatchesRegularExpression(
            "/'scope'\\s*=>\\s*'first'/",
            $block,
            'log-cleanup 须全局唯一：多进程同时删同一批文件没有意义'
        );
        $this->assertMatchesRegularExpression(
            "/'run_at_start'\\s*=>\\s*true/",
            $block,
            'log-cleanup 周期长达 86400s：不声明 run_at_start，进程活不满一天就永不清理'
        );
    }

    public function testArchiveTaskIsWiredAndOrderedBeforeCleanup(): void
    {
        $archive = $this->taskBlock('log-archive');

        $this->assertMatchesRegularExpression(
            "/'interval'\\s*=>\\s*86400/",
            $archive,
            '日志归档的周期应为 24h（86400s）'
        );
        $this->assertMatchesRegularExpression(
            "/'scope'\\s*=>\\s*'first'/",
            $archive,
            'log-archive 必须全局唯一：多进程同时读写同一个归档包会互相覆盖'
        );
        $this->assertMatchesRegularExpression(
            "/'run_at_start'\\s*=>\\s*true/",
            $archive,
            'log-archive 周期 86400s：不声明 run_at_start 则永不执行'
        );

        // 纯顺序依赖的静默失效：两个任务都在启动后 1s 补跑，按声明顺序触发。
        // 若 cleanup 先跑，刚超期的明文会被直接删掉，归档再也拿不到内容 ——
        // 不报错、不告警，只是归档永远是空的。
        $code      = (string)file_get_contents($this->root('config/business.php'));
        $archiveAt = strpos($code, "'log-archive'");
        $cleanupAt = strpos($code, "'log-cleanup'");

        $this->assertNotFalse($archiveAt, 'config/business.php 未注册 log-archive 任务');
        $this->assertNotFalse($cleanupAt, 'config/business.php 未注册 log-cleanup 任务');
        $this->assertLessThan(
            $cleanupAt,
            $archiveAt,
            'log-archive 必须声明在 log-cleanup 之前，否则明文先被删、归档拿不到内容'
        );
    }

    public function testTaskRunnerImplementsRunAtStart(): void
    {
        $code = str_replace("\r\n", "\n", (string)file_get_contents($this->root('src/Business/Task.php')));

        // 锚点带行首空白与完整条件：源码注释里同样会出现 run_at_start，
        // 只用键名搜索会命中注释，断言随之失去意义（同类假阴性此前踩过）
        // `!\s?empty` 兼容 `! empty(` 与 `!empty(` 两种排版写法。
        //
        // ⚠ 读入后必须先把 CRLF 归一化为 LF（上面的 str_replace）：
        //   本仓工作区因 `core.autocrlf=true` 为 CRLF，而带 `$` 的 /m 锚点在
        //   `... {\r\n` 上匹配不到 —— 会让断言无端失败（或让负向断言静默空转）。
        //   同类坑在 baseline 正则上已踩过一次。
        $this->assertMatchesRegularExpression(
            '/^[ \t]*if \(!\s?empty\(\$job\[\'run_at_start\'\]\)\) \{$/m',
            $code,
            'Task 未实现 run_at_start 分支，配置声明将静默失效'
        );

        // 必须是单次延迟定时器（第 4 参数 false）；否则会变成第二个周期任务，
        // 每轮多跑一次。同时要求复用 execute()，以保留防重入与统计能力。
        $this->assertMatchesRegularExpression(
            '/Timer::add\(self::START_RUN_DELAY, function \(\) use \(\$name\) \{\s*'
            . 'self::execute\(\$name\);\s*\}, \[\], false\);/',
            $code,
            'run_at_start 的补跑必须是非持久化定时器，且经 execute() 执行'
        );
    }

    public function testDeadLogRotateConfigIsGone(): void
    {
        // 同 testTaskRunnerImplementsRunAtStart：CRLF 会让正向锚点失败、
        // 让下面这条**负向**锚点静默空转（测试看似通过、实则什么都没验）。
        $code = str_replace("\r\n", "\n", (string)file_get_contents($this->root('config/app.php')));

        $this->assertDoesNotMatchRegularExpression(
            "/^[ \t]*'rotate'\\s*=>/m",
            $code,
            'config/app.php 的 rotate 从未被读取（按天分割在 Logger 内硬编码），属死配置'
        );
    }

    /* ---------------------------------------------------------------------
     | 辅助
     --------------------------------------------------------------------- */

    /**
     * 解析项目根下的文件路径（不依赖当前工作目录）
     *
     * @param string $relative
     *
     * @return string
     */
    private function root($relative)
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    /**
     * 从 config/business.php 源码中切出指定任务的配置块
     *
     * 不直接 require 该文件：它会连带求值 Env::int() 等环境读取，单测里没有完整的
     * 配置上下文。本处只断言接线关系，不需要求值。
     *
     * @param string $name
     *
     * @return string
     */
    private function taskBlock($name)
    {
        $code = (string)file_get_contents($this->root('config/business.php'));
        $hit  = preg_match(
            "/'name'\\s*=>\\s*'" . preg_quote($name, '/') . "'.*?\\],/s",
            $code,
            $m
        );

        $this->assertSame(
            1,
            $hit,
            '在 config/business.php 中未找到任务 ' . $name . '，任务清单可能已改名'
        );

        return $m[0] ?? '';
    }

    /**
     * @param string $name
     *
     * @return string
     */
    private function path($name)
    {
        return $this->logDir . DIRECTORY_SEPARATOR . $name;
    }

    /**
     * 归档目录（与 Logger 在 archive_dir 留空时的回落规则一致）
     *
     * @return string
     */
    private function archiveDir()
    {
        return $this->logDir . DIRECTORY_SEPARATOR . 'archive';
    }

    /**
     * @param string $month 形如 2026-09
     *
     * @return string
     */
    private function archivePath($month)
    {
        return $this->archiveDir() . DIRECTORY_SEPARATOR . $month . '.tar.gz';
    }

    /**
     * 归档场景的 Logger 配置（默认开启归档，便于各用例只覆盖关心的字段）
     *
     * @param array $overrides
     *
     * @return array
     */
    private function archiveConfig(array $overrides = [])
    {
        return array_merge([
            'path'               => $this->logDir,
            'stdout'             => false,
            'keep_days'          => 30,
            'archive_enable'     => true,
            'archive_after_days' => 7,
            'archive_keep_days'  => 180,
        ], $overrides);
    }

    /**
     * 造一个 mtime 落在 N 天前的按天日志
     *
     * @param string $name
     * @param string $body
     * @param int    $daysAgo
     *
     * @return string 文件路径
     */
    private function makeStaleLog($name, $body, $daysAgo)
    {
        $file = $this->path($name);
        file_put_contents($file, $body);
        touch($file, (int)strtotime('-' . $daysAgo . ' day'));
        clearstatcache(true, $file);

        return $file;
    }

    /**
     * 取出归档包内解压后的 tar 缓冲区（同时校验它确实是合法 gzip 流）
     *
     * @param string $month
     *
     * @return string
     */
    private function readPack($month)
    {
        $pack = $this->archivePath($month);
        $this->assertFileExists($pack, '归档包 ' . $month . ' 不存在');

        $raw = @gzdecode((string)file_get_contents($pack));
        $this->assertIsString($raw, '归档包 ' . $month . ' 不是合法的 gzip 流');

        return $raw;
    }

    /**
     * 清空用例目录下的日志与归档产物
     *
     * 归档也必须清：残留的包会被下一次归档当成「已有归档」读取，用例之间互相污染。
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

        $packs = glob($this->archiveDir() . DIRECTORY_SEPARATOR . '*.tar.gz');
        if (is_array($packs)) {
            foreach ($packs as $pack) {
                @unlink($pack);
            }
        }
        @rmdir($this->archiveDir());
    }
}
