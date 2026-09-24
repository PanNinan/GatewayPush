<?php

declare(strict_types=1);

namespace tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 行为日志页 / 示例菜单清理的**静态**契约（不引导框架、不连 DB）。
 *
 * 守的是三件最容易静默回归的事：
 * 1. install.php 必须清理已装环境里的 demos 子树（import 只 upsert 不 delete）；
 *    ⚠ 不断言 `plugin/admin/config/menu.php` —— 该文件是 composer 包 webman/admin
 *    的纯副本（admin/.gitignore 排除），`composer install` 会从 vendor 原样重建并
 *    带回 demos；清理的唯一版本库真源是 install.php 步骤 3b。
 * 2. 行为日志页 / API 节点已登记，且只读与运维同授；
 * 3. 路由 / 默认路由禁用 / 视图不硬编码动作表。
 */
final class AuditMenuContractTest extends TestCase
{
    private const INSTALL = 'scripts/install.php';

    private const ROUTES = 'config/route.php';

    public function testInstallCleansObsoleteDemoTree(): void
    {
        $src = $this->read(self::INSTALL);

        $this->assertStringContainsString(
            'deleteRuleTreeByKey',
            $src,
            'install.php 必须删除已从 menu.php 摘除的废弃菜单子树（import 只 upsert）'
        );
        $this->assertStringContainsString(
            "\$obsoleteMenuRoots = ['demos']",
            $src,
            '废弃菜单根必须包含 demos'
        );
        $this->assertStringContainsString(
            "\$nodeIds['auditPage']",
            $src,
            'install.php 必须注册行为日志页面节点'
        );
        $this->assertStringContainsString(
            "\$nodeIds['audit.list']",
            $src,
            'install.php 必须注册行为日志 API 节点'
        );
    }

    public function testViewerAndOperatorBothGetAuditNodes(): void
    {
        $src = $this->read(self::INSTALL);

        // 取 $viewerRules 数组体（到 ]; 为止）
        $this->assertSame(
            1,
            preg_match('/\$viewerRules\s*=\s*\[(.*?)\];/s', $src, $m),
            '解析不到 $viewerRules 数组'
        );
        $viewer = (string)$m[1];
        $this->assertStringContainsString("\$nodeIds['auditPage']", $viewer, '只读角色必须拿到行为日志页');
        $this->assertStringContainsString("\$nodeIds['audit.list']", $viewer, '只读角色必须拿到 audit.list');

        // 运维 = 只读之上追加，故只要 $operatorRules 是 array_merge($viewerRules, …) 即继承
        $this->assertMatchesRegularExpression(
            '/\$operatorRules\s*=\s*array_merge\(\s*\$viewerRules\s*,/',
            $src,
            '运维角色应 array_merge 继承只读规则（含 audit 节点）'
        );
    }

    public function testAuditRoutesAndDefaultRouteDisabled(): void
    {
        $src = $this->read(self::ROUTES);

        $this->assertMatchesRegularExpression(
            "#Route::get\\(\\s*'/audit',\\s*\\[AuditPageController::class,\\s*'index'\\]\\)->middleware\\(\\[AdminAuth::class\\]\\)#",
            $src,
            '/audit 页面路由必须存在且挂 AdminAuth'
        );
        // 路由声明在 /api 组内，源码里路径是 '/audit/logs'（完整 URL = /api + 组内路径）
        $this->assertMatchesRegularExpression(
            "#Route::get\\(\\s*'/audit/logs',\\s*\\[AuditController::class,\\s*'index'\\]\\)#",
            $src,
            '/api/audit/logs 必须存在（组内声明为 /audit/logs，且位于 /api 分组内）'
        );
        $groupStart = strpos($src, "Route::group('/api'");
        $routePos = strpos($src, "Route::get('/audit/logs'");
        $this->assertNotFalse($groupStart, '解析不到 /api 分组');
        $this->assertNotFalse($routePos, '解析不到 /audit/logs 路由');
        $this->assertGreaterThan($groupStart, $routePos, '/audit/logs 必须位于 /api 分组体内');
        $this->assertStringContainsString(
            'Route::disableDefaultRoute(AuditPageController::class);',
            $src,
            'AuditPageController 必须禁用默认路由'
        );
        $this->assertStringContainsString(
            'Route::disableDefaultRoute(AuditController::class);',
            $src,
            'AuditController 必须禁用默认路由'
        );
    }

    public function testAuditPageDoesNotHardcodeActionList(): void
    {
        $page = $this->read('app/controller/AuditPageController.php');
        $this->assertStringContainsString(
            'Auditor::ACTIONS',
            $page,
            '动作枚举必须取自 Auditor::ACTIONS，页面控制器不得硬编码动作表'
        );

        $view = $this->read('app/view/audit/index.html');
        $this->assertStringNotContainsString(
            'push.create',
            $view,
            '视图不得硬编码动作名（由 config.actions 下发）'
        );
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
