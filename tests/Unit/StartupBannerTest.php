<?php
/**
 * 启动信息横幅测试
 *
 * 横幅的**打印时机**是本功能最容易在重构中静默失效的一环：一旦有人把打印点挪到
 * Worker::runAll() 之后，守护模式（-d）下就再也看不到任何启动信息 —— 因为
 * daemonize() 会把 STDOUT 重定向，workerman 的 log() 也随之停写终端。而前台模式
 * 依然正常，本地开发根本测不出来，只有生产（守护模式）才会暴露。
 * 故此项以结构性断言锁定，而非依赖手工验证。
 *
 * 端口探测同理：Windows 的 socket 默认允许重复 bind，一旦退回 bind 判定，
 * 端口占用与监听状态会全部失真（且不会有任何报错）。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class StartupBannerTest extends TestCase
{
    /* ---------------------------------------------------------------------
     | 结构性断言：打印时机与条件
     --------------------------------------------------------------------- */

    public function testBannerIsPrintedBeforeRunAll(): void
    {
        $code = (string)file_get_contents($this->root('start.php'));

        $runAllAt = strpos($code, 'Worker::runAll();');
        $this->assertNotFalse($runAllAt, 'start.php 未调用 Worker::runAll()');

        $beforeRunAll = substr($code, 0, (int)$runAllAt);

        // 锚点同时接受 array(...) 与 [...] 两种等价写法：php-cs-fixer 的 array_syntax
        // 会把前者规整成后者，而本用例锁的是「条件与打印点的相对位置」，
        // 不该因排版工具落地而失败（2026-09-22 曾因此红过一次）。
        $matched = [];
        $this->assertSame(
            1,
            preg_match(
                "/in_array\(\\\$command, (?:array\('start', 'restart'\)|\\['start', 'restart'\\]), true\)/",
                $beforeRunAll,
                $matched,
                PREG_OFFSET_CAPTURE
            ),
            'start / restart 的横幅打印条件缺失'
        );
        $condAt = $matched[0][1];

        // 只检查条件之后的一段窗口，而非全文搜索：info 命令也有自己的横幅调用
        // （同样位于 runAll() 之前），全文搜索会被它满足 —— 那样即便把 start 分支
        // 里的调用整段删掉，本用例也照样通过。该假阴性由阴性验证实测抓出过。
        //
        // 横幅的文本拼装自 v2 起下沉到 Console\Banner，但**打印点与打印条件仍留在
        // 入口 start.php** —— 本用例锁的正是后者（时机），故锚点指向 start.php。
        $window = substr($beforeRunAll, $condAt, 600);
        $this->assertMatchesRegularExpression(
            '/^[ \t]*echo [^\n]*Banner::render\(/m',
            $window,
            'start / restart 分支内必须在 Worker::runAll() 之前打印横幅：'
            . 'daemonize() 由 runAll() 内部执行，在其之后 STDOUT 已被重定向，'
            . '守护模式（-d）下看不到任何输出，而前台模式仍正常 —— 本地开发测不出来'
        );
    }

    public function testBannerConditionCoversStartAndRestartOnlyQuietAware(): void
    {
        $code = (string)file_get_contents($this->root('start.php'));

        $this->assertMatchesRegularExpression(
            "/in_array\(\\\$command, (?:array\('start', 'restart'\)|\\['start', 'restart'\\]), true\)\s*&&\s*!in_array\('-q', \\\$cleanArgv, true\)/",
            $code,
            '横幅的打印条件必须同时限定「start / restart」与「未带 -q」，'
            . '否则 stop / status 也会打印启动信息，且 -q 无法静默'
        );
    }

    /* ---------------------------------------------------------------------
     | 结构性断言：管理脚本
     --------------------------------------------------------------------- */

    public function testWindowsLauncherPrintsBannerOnMainWindow(): void
    {
        $code = (string)file_get_contents($this->root('bin/start.ps1'));

        $this->assertMatchesRegularExpression(
            "/start\\.php'\\) info \\(\\\$roles -join ','\\)/",
            $code,
            'Windows 各角色的横幅打在各自的新窗口里，主窗口看不到，'
            . '需在启动完成后补打印一份（范围取本次实际启动的角色）'
        );
    }

    public function testLaunchersForwardInfoCommand(): void
    {
        $sh = (string)file_get_contents($this->root('bin/start.sh'));
        $this->assertMatchesRegularExpression(
            '/info\)\s+preflight && php_run info/',
            $sh,
            'start.sh 未透传 info 子命令'
        );

        $ps1 = (string)file_get_contents($this->root('bin/start.ps1'));
        $this->assertMatchesRegularExpression(
            "/'info'\\s+\\{ & \\\$script:PhpExe .*start\\.php'\\) info/",
            $ps1,
            'start.ps1 未透传 info 子命令'
        );
    }

    /* ---------------------------------------------------------------------
     | 结构性断言：Windows 端口探测
     --------------------------------------------------------------------- */

    public function testPortProbeUsesNetstatOnWindowsBeforeBindFallback(): void
    {
        // 探测实现位于 Console\PortProbe（自 v2 起从 start.php 迁出）：
        // 自检报告与服务清单共用同一份判定，故锚点跟着实现走。
        $code = (string)file_get_contents($this->root('src/Console/PortProbe.php'));

        $this->assertMatchesRegularExpression(
            '/DIRECTORY_SEPARATOR !== .\/. && function_exists\(.exec.\)/',
            $code,
            'Windows 必须走 netstat 判定：该平台 socket 允许重复 bind，'
            . 'bind 探测会把「已占用」判成「空闲」，且没有任何报错'
        );

        $netstatAt = strpos($code, 'usedPortsByNetstat($isUdp');
        // 锚点必须带 @ 与括号：源码注释里也提到了 stream_socket_server，
        // 只用函数名会命中注释，断言随之失去意义
        $bindAt    = strpos($code, '@stream_socket_server(');

        $this->assertNotFalse($netstatAt, 'PortProbe::isUsed 未调用 usedPortsByNetstat');
        $this->assertNotFalse($bindAt, 'PortProbe::isUsed 的 bind 探测分支丢失');
        $this->assertLessThan(
            $bindAt,
            $netstatAt,
            'netstat 分支必须先于 bind 探测，否则 Windows 永远走不到它'
        );
    }

    /* ---------------------------------------------------------------------
     | 集成断言：真实执行 info 命令
     --------------------------------------------------------------------- */

    public function testInfoCommandPrintsAllBannerSections(): void
    {
        $output = $this->runInfo('');

        $fields = ['PHP 版本', '启动角色', '环境配置', '时区', '运行目录', '框架版本', '依赖版本', '服务清单'];
        foreach ($fields as $field) {
            $this->assertStringContainsString($field, $output, '横幅缺少字段：' . $field);
        }

        $roles = ['register', 'gateway', 'udp', 'business', 'api', 'dashboard'];
        foreach ($roles as $role) {
            $this->assertStringContainsString($role, $output, '服务清单缺少角色：' . $role);
        }

        // 版本号取自 Composer 已安装包，解析失败会退化成 '-'，需显式拦截
        $this->assertStringNotContainsString('workerman -', $output, '未能解析 workerman 版本');
        $this->assertStringNotContainsString('gateway-worker -', $output, '未能解析 gateway-worker 版本');

        // 只读展示：不得真的启动服务
        $this->assertStringNotContainsString('Start success', $output);
    }

    public function testInfoCommandFiltersRoles(): void
    {
        $output = $this->runInfo('gateway,udp');

        $this->assertStringContainsString('gateway', $output);
        $this->assertStringContainsString('udp', $output);

        // 其余角色的进程名不应出现（进程名比角色名更不容易被其它文案撞上）
        foreach (['BusinessWorker', 'GW-API', 'GW-DASH', 'Register'] as $absent) {
            $this->assertStringNotContainsString($absent, $output, '角色过滤失效，仍出现：' . $absent);
        }
    }

    public function testInfoCommandToleratesUnknownRoleArgument(): void
    {
        // 只读展示命令：参数写错应回落为「全部角色」而非报错退出
        $output = $this->runInfo('not-a-role');

        $this->assertStringContainsString('服务清单', $output);
        $this->assertStringContainsString('dashboard', $output);
    }

    /* ---------------------------------------------------------------------
     | 辅助
     --------------------------------------------------------------------- */

    /**
     * 以子进程执行 info 命令并返回合并后的输出
     *
     * 不在同进程内 require start.php —— 那会执行完整启动流程并进入事件循环。
     *
     * @param string $roles 逗号分隔的角色列表，空串表示不带参数
     *
     * @return string
     */
    private function runInfo($roles)
    {
        if (!function_exists('shell_exec')) {
            $this->markTestSkipped('shell_exec 不可用，跳过 info 集成断言');
        }

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->root('start.php')) . ' info';
        if ($roles !== '') {
            $command .= ' ' . escapeshellarg($roles);
        }

        $output = shell_exec($command . ' 2>&1');
        $this->assertIsString($output, 'info 命令未产生输出，可能执行失败');

        return (string)$output;
    }

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
}
