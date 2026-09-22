<?php
/**
 * 角色清单 / roles 命令测试
 *
 * 本功能解决的是「某个角色被 .env 关闭后，Windows 启动脚本仍会白等一轮就绪超时
 * （25s）并把配置关闭误报成启动失败」。修复依赖两处契约，二者都不会自己报错，
 * 只会静默退化，故各自加锁：
 *
 *   1) start.php 的 roles 命令 —— 脚本据此判断哪些角色该跳过。它一旦输出非法
 *      JSON 或漏角色，脚本会回落到「全部启用」，故障表现与修复前完全一样；
 *   2) bin/start.ps1 的过滤点 —— 必须在进入就绪等待**之前**过滤，否则等于没改。
 *
 * 另有一条防漂移断言：RoleCatalog 记录的 *_ENABLE 键名与 config 的 Env::bool()
 * 实参必须双向一致 —— 键名只在提示语里出现，写错不会有任何报错。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RoleCatalogTest extends TestCase
{
    /** 角色顺序即依赖顺序，与管理脚本 / 横幅一致 */
    const ROLE_ORDER = array('register', 'gateway', 'udp', 'business', 'api', 'dashboard');

    /**
     * 非角色级开关：控制的是「组件行为 / 队列」而非「某个角色是否启动」，
     * 因此不应出现在 RoleCatalog 中。显式白名单，新增此类开关时必须同步登记 ——
     * 这正是下方反向断言能发现「角色开关漏登记」的前提。
     */
    const NON_ROLE_SWITCHES = array(
        'SSL_ENABLE',              // WebSocket 是否走 TLS，网关角色照常启动
        'UDP_QUEUE_ENABLE',        // UDP 入站队列
        'UDP_OUT_QUEUE_ENABLE',    // UDP 出站队列
        'AUTH_ENABLE',             // 报文层鉴权
        'AUTH_SIGN_ENABLE',        // 报文层签名
        'API_SIGN_ENABLE',         // 接口验签
        'PUSH_ENABLE',             // 推送总开关
        'RATE_LIMIT_ENABLE',       // 限流
        'SUBSCRIBE_ENABLE',        // 订阅
        'MONITOR_ENABLE',          // 指标采集
        'LOG_ARCHIVE_ENABLE',      // 日志归档（功能开关，角色照常启动）
    );

    /* ---------------------------------------------------------------------
     | 集成断言：roles 命令的输出契约
     --------------------------------------------------------------------- */

    public function testRolesCommandExposesEnabledContract(): void
    {
        $decoded = $this->roles();

        $this->assertArrayHasKey('roles', $decoded, 'roles 命令必须返回 {"roles":[...]}');
        $this->assertCount(
            count(self::ROLE_ORDER),
            $decoded['roles'],
            '角色数量与角色清单不一致（新增角色时须同步 RoleCatalog 与管理脚本）'
        );
        $this->assertSame(
            self::ROLE_ORDER,
            array_column($decoded['roles'], 'role'),
            'roles 的输出顺序即依赖顺序：register 必须先就绪，其余角色都要向它注册'
        );

        foreach ($decoded['roles'] as $item) {
            $this->assertArrayHasKey('enabled', $item, '角色 ' . $item['role'] . ' 缺少 enabled 字段');
            $this->assertArrayHasKey('env', $item, '角色 ' . $item['role'] . ' 缺少 env 字段');
            $this->assertIsBool($item['enabled'], 'enabled 必须是 JSON 布尔值，脚本按布尔解析');
            $this->assertIsString($item['env']);
        }
    }

    public function testBusinessHasNoStandaloneSwitch(): void
    {
        $byRole = $this->byRole();

        // 业务进程是消息处理的唯一载体，关闭它等于服务整体不可用，故没有独立开关；
        // env 留空串而非 null，消费方无需额外判空
        $this->assertTrue($byRole['business']['enabled'], '业务进程不支持单独关闭');
        $this->assertSame('', $byRole['business']['env'], '业务进程的 env 必须为空串');

        foreach (array('register', 'gateway', 'udp', 'api', 'dashboard') as $role) {
            $this->assertMatchesRegularExpression(
                '/^[A-Z][A-Z0-9_]*_ENABLE$/',
                $byRole[$role]['env'],
                '角色 ' . $role . ' 的 env 应为 *_ENABLE 形式的开关名'
            );
        }
    }

    public function testEnabledFlagFollowsRealEnvironmentOverride(): void
    {
        // 真实环境变量优先级最高（.env < .env.local < 真实环境变量）。这条语义正是
        // 管理脚本**不能**自行解析 .env 的原因：脚本读到的值可能与进程实际取值相反。
        $byRole = $this->byRole(array('WS_ENABLE' => 'false', 'DASHBOARD_ENABLE' => 'false'));

        $this->assertFalse($byRole['gateway']['enabled'], 'WS_ENABLE=false 未被识别');
        $this->assertFalse($byRole['dashboard']['enabled'], 'DASHBOARD_ENABLE=false 未被识别');
    }

    /* ---------------------------------------------------------------------
     | 结构性断言：开关名与 config 双向一致
     --------------------------------------------------------------------- */

    public function testCatalogSwitchNamesMatchConfigDeclarations(): void
    {
        $catalogKeys  = $this->catalogSwitchNames();
        $configKeys   = $this->configSwitchNames();

        $this->assertNotEmpty($catalogKeys, '未能从 RoleCatalog 提取到任何开关名');

        // 正向：清单里写的键名必须真的存在于 config —— 键名只用于提示语，
        // 写错了不会报错，只会给用户一个不存在的变量名
        foreach ($catalogKeys as $key) {
            $this->assertContains(
                $key,
                $configKeys,
                'RoleCatalog 声明的开关 ' . $key . ' 在 config 中不存在（键名拼写漂移）'
            );
        }

        // 反向：config 中除白名单外的 *_ENABLE 都应是角色级开关，且已被清单登记 ——
        // 新增角色开关却忘记登记时，脚本会把它当成「无开关」而永远不跳过
        $expected = array_values(array_diff($configKeys, self::NON_ROLE_SWITCHES));
        sort($expected);
        $actual = $catalogKeys;
        sort($actual);

        $this->assertSame(
            $expected,
            $actual,
            'config 的角色级开关与 RoleCatalog 登记项不一致：新增角色级开关时必须同步登记，'
            . '否则管理脚本不会跳过该角色'
        );
    }

    /* ---------------------------------------------------------------------
     | 结构性断言：Windows 启动脚本的过滤点
     --------------------------------------------------------------------- */

    public function testLauncherQueriesRoleStatesFromPhp(): void
    {
        $ps1 = (string)file_get_contents($this->root('bin/start.ps1'));

        $this->assertMatchesRegularExpression(
            "/start\.php'\) 'roles'/",
            $ps1,
            'start.ps1 必须向 start.php 询问角色启用状态：.env 的叠加语义只有 Env 类能还原，'
            . '脚本自行解析会与实际取值漂移'
        );
        $this->assertStringContainsString(
            'RoleStates',
            $ps1,
            'start.ps1 缺少角色启用状态缓存'
        );
    }

    public function testLauncherFiltersDisabledRolesBeforeWaiting(): void
    {
        $ps1 = (string)file_get_contents($this->root('bin/start.ps1'));

        // 关键在「过滤后遍历」：被关闭的角色必须完全不进 Wait-RoleReady，
        // 否则照旧白等就绪超时，并把配置关闭误报成启动失败
        $this->assertMatchesRegularExpression(
            '/foreach \(\$role in \$pending\)/',
            $ps1,
            '启动循环必须遍历过滤后的角色列表，而不是原始 $roles'
        );
        $this->assertStringContainsString(
            '$states[$role].Enabled',
            $ps1,
            'start.ps1 未读取角色的 enabled 标记'
        );

        // 禁用与失败必须分开呈现：前者是可预期的配置状态，不该计入失败集合
        $this->assertStringContainsString('已禁用（', $ps1, '未输出被禁用角色的原因');
        $this->assertStringContainsString(
            '没有任何角色需要启动',
            $ps1,
            '全部角色被关闭时应明确报错，而非静默返回成功'
        );
    }

    public function testStatusDistinguishesDisabledFromStopped(): void
    {
        $ps1 = (string)file_get_contents($this->root('bin/start.ps1'));

        // 「按配置不会启动」与「启动过又崩了」是两回事，状态列必须能分辨
        $this->assertStringContainsString('关闭开关：', $ps1, 'status 未列出被禁用角色的开关名');
        $this->assertStringContainsString('已禁用', $ps1);
    }

    /* ---------------------------------------------------------------------
     | 辅助
     --------------------------------------------------------------------- */

    /**
     * 执行 roles 命令并解析 JSON
     *
     * 以子进程执行而非同进程 require start.php —— 那会跑完整个启动流程。
     *
     * @param array $env 附加的环境变量（键 => 值），用于验证覆盖语义
     * @return array
     */
    private function roles(array $env = array())
    {
        if (!function_exists('shell_exec')) {
            $this->markTestSkipped('shell_exec 不可用，跳过 roles 集成断言');
        }

        // putenv 会写进本进程环境，shell_exec 的子进程随之继承；真实环境变量在
        // Env 的加载语义中优先级最高，故可覆盖 .env。用后必须还原，否则污染后续用例。
        foreach ($env as $key => $value) {
            putenv($key . '=' . $value);
        }

        try {
            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->root('start.php')) . ' roles';
            $output  = shell_exec($command . ' 2>&1');
        } finally {
            foreach (array_keys($env) as $key) {
                putenv($key);   // 不带 = 号即删除该变量
            }
        }

        $this->assertIsString($output, 'roles 命令未产生输出，可能执行失败');

        $decoded = json_decode(trim((string)$output), true);
        $this->assertIsArray(
            $decoded,
            'roles 命令的输出必须是合法 JSON，实际为：' . (string)$output
        );

        return $decoded;
    }

    /**
     * 角色名 => 记录
     *
     * @param array $env
     * @return array
     */
    private function byRole(array $env = array())
    {
        $byRole = [];
        foreach ($this->roles($env)['roles'] as $item) {
            $byRole[$item['role']] = $item;
        }

        return $byRole;
    }

    /**
     * RoleCatalog 中登记的开关名
     *
     * @return string[]
     */
    private function catalogSwitchNames()
    {
        $code = (string)file_get_contents($this->root('src/Common/RoleCatalog.php'));
        preg_match_all("/'env'\s*=>\s*'([A-Z][A-Z0-9_]*_ENABLE)'/", $code, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * config 中出现的角色级开关候选（未过滤白名单）
     *
     * @return string[]
     */
    private function configSwitchNames()
    {
        $keys = [];
        foreach (array('config/gateway.php', 'config/app.php') as $file) {
            $code = (string)file_get_contents($this->root($file));
            preg_match_all("/Env::bool\('([A-Z][A-Z0-9_]*_ENABLE)'/", $code, $matches);
            $keys = array_merge($keys, $matches[1]);
        }

        return array_values(array_unique($keys));
    }

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
}
