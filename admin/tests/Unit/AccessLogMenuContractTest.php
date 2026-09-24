<?php
/**
 * admin 单测 —— AccessLogMenuContractTest。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 访问日志页（`wa_admin_log`）的**静态**契约（不引导框架、不连 DB）。
 *
 * 守的是四件最容易静默回归的事：
 * 1. install.php 登记 accessPage / access.list，且只读与运维同授；
 * 2. 路由挂 AdminAuth + 禁用默认路由（与 /audit 同款）；
 * 3. 全局 AccessLog 中间件已注册（否则登录/登出插件操作零留痕）；
 * 4. 页面控制器不硬编码事件表（取自 AccessLogger::EVENTS）。
 *
 * 与 {@see AuditMenuContractTest}（`admin_audit_log` 业务审计）**并列、不合并** ——
 * 两套日志视角不同，测试也不该互相吞掉。
 */
final class AccessLogMenuContractTest extends TestCase
{
    private const INSTALL = 'scripts/install.php';

    private const ROUTES = 'config/route.php';

    private const MIDDLEWARE = 'config/middleware.php';

    public function testInstallRegistersAccessLogNodes(): void
    {
        $src = $this->read(self::INSTALL);

        $this->assertStringContainsString(
            "\$nodeIds['accessPage']",
            $src,
            'install.php 必须注册访问日志页面节点'
        );
        $this->assertStringContainsString(
            "\$nodeIds['access.list']",
            $src,
            'install.php 必须注册访问日志 API 节点'
        );
        // 步骤 4 表清单必须含 wa_admin_log（漏了则行数报告静默少一张表）
        $this->assertStringContainsString(
            "'wa_admin_log'",
            $src,
            'install.php 步骤 4 必须核对 wa_admin_log 行数'
        );
    }

    public function testViewerAndOperatorBothGetAccessLogNodes(): void
    {
        $src = $this->read(self::INSTALL);

        $this->assertSame(
            1,
            preg_match('/\$viewerRules\s*=\s*\[(.*?)\];/s', $src, $m),
            '解析不到 $viewerRules 数组'
        );
        $viewer = (string)$m[1];
        $this->assertStringContainsString("\$nodeIds['accessPage']", $viewer, '只读角色必须拿到访问日志页');
        $this->assertStringContainsString("\$nodeIds['access.list']", $viewer, '只读角色必须拿到 access.list');

        $this->assertMatchesRegularExpression(
            '/\$operatorRules\s*=\s*array_merge\(\s*\$viewerRules\s*,/',
            $src,
            '运维角色应 array_merge 继承只读规则（含 access 节点）'
        );
    }

    public function testAccessLogRoutesAndDefaultRouteDisabled(): void
    {
        $src = $this->read(self::ROUTES);

        $this->assertMatchesRegularExpression(
            "#Route::get\\(\\s*'/access-log',\\s*\\[AccessLogPageController::class,\\s*'index'\\]\\)->middleware\\(\\[AdminAuth::class\\]\\)#",
            $src,
            '/access-log 页面路由必须存在且挂 AdminAuth'
        );
        $this->assertMatchesRegularExpression(
            "#Route::get\\(\\s*'/access-logs',\\s*\\[AccessLogApiController::class,\\s*'index'\\]\\)#",
            $src,
            '/api/access-logs 必须存在（组内声明为 /access-logs）'
        );
        $groupStart = strpos($src, "Route::group('/api'");
        $routePos = strpos($src, "Route::get('/access-logs'");
        $this->assertNotFalse($groupStart, '解析不到 /api 分组');
        $this->assertNotFalse($routePos, '解析不到 /access-logs 路由');
        $this->assertGreaterThan($groupStart, $routePos, '/access-logs 必须位于 /api 分组体内');
        $this->assertStringContainsString(
            'Route::disableDefaultRoute(AccessLogPageController::class);',
            $src,
            'AccessLogPageController 必须禁用默认路由'
        );
        $this->assertStringContainsString(
            'Route::disableDefaultRoute(AccessLogApiController::class);',
            $src,
            'AccessLogApiController 必须禁用默认路由'
        );
    }

    public function testGlobalAccessLogMiddlewareRegistered(): void
    {
        $src = $this->read(self::MIDDLEWARE);

        $this->assertStringContainsString(
            'AccessLog::class',
            $src,
            'config/middleware.php 必须注册全局 AccessLog 中间件（否则登录/登出零留痕）'
        );
        $this->assertStringContainsString(
            'use app\middleware\AccessLog',
            $src,
            'middleware.php 必须 use AccessLog'
        );
        // webman 要求两层结构：应用名 => 类列表；扁平列表会抛 Bad middleware config
        $this->assertMatchesRegularExpression(
            "/return\\s*\\[\\s*'@'\\s*=>\\s*\\[\\s*AccessLog::class\\s*,?\\s*\\]\\s*,?\\s*\\]/s",
            $src,
            "middleware.php 必须是 ['@' => [AccessLog::class]] 两层结构"
        );
    }

    public function testAccessLogPageDoesNotHardcodeEventList(): void
    {
        $page = $this->read('app/controller/AccessLogPageController.php');
        $this->assertStringContainsString(
            'AccessLogger::EVENTS',
            $page,
            '事件枚举必须取自 AccessLogger::EVENTS，页面控制器不得硬编码事件表'
        );
        $this->assertStringContainsString(
            'AccessLogger::RESULTS',
            $page,
            '结果枚举必须取自 AccessLogger::RESULTS'
        );

        $view = $this->read('app/view/accesslog/index.html');
        $this->assertStringNotContainsString(
            "'login'",
            $view,
            '视图不得硬编码事件名（由 config.events 下发）'
        );
    }

    public function testSqlCreatesWaAdminLog(): void
    {
        $sql = $this->read('database/001_gw_tables.sql');

        $this->assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS `wa_admin_log`',
            $sql,
            '001_gw_tables.sql 必须建 wa_admin_log'
        );
        $this->assertStringContainsString(
            'utf8mb4_general_ci',
            $sql,
            'wa_admin_log 必须与 wa_* 同排序规则（防 Illegal mix of collations）'
        );
        // 关键列：脱敏后的 query/body、事件分类、耗时
        foreach (['`event`', '`query`', '`body`', '`cost_ms`', '`admin_id`'] as $col) {
            $this->assertStringContainsString($col, $sql, "wa_admin_log 缺列 {$col}");
        }
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 2) . '/' . $relative;
    }

    private function read(string $relative): string
    {
        $path = $this->path($relative);
        $content = @file_get_contents($path);

        $this->assertNotFalse($content, '读不到文件：' . $path);

        return (string)$content;
    }
}
