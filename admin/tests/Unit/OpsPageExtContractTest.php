<?php

declare(strict_types=1);

namespace tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 2.0 序4（§1.2 队列深度 + §1.4 错误聚合）、序5（§2.1 配置查看）与
 * 序7（§1.3 限流巡检）的 **权限 / 路由 / 视图** 三面契约（纯静态，不引导框架）。
 *
 * 与 `OpsActionContractTest` 同一路数，钉的是三类静默失效：
 * 1. **节点漏登记或错进只读角色** —— 漏了 = 非超管 403 看不出问题；
 *    进了只读 = 普通账号能看到队列水位 / 错误原文 / 密钥指纹 / 限流指纹（静默越权）。
 * 2. **路由没挂 AdminAuth** —— `/api/ops/queues` 变成零鉴权只读口。
 * 3. **视图 id ↔ JS cfg 键漂移** —— 后端加了区块、前端没绑，页面永远空白。
 *
 * ⚠ 这四个端点**刻意只进运维角色**（与 ops.logs / ops.rotation 同级）：
 *   错误原文含业务细节、配置快照含密钥指纹、队列水位 / 限流指纹属运维排查面，
 *   都不该给「只读看板」账号。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */
final class OpsPageExtContractTest extends TestCase
{
    /** 序4/序5/序7 新增节点别名（与 install.php 的 $nodeSpecs 键一致） */
    private const NEW_NODES = ['ops.queues', 'ops.errors', 'ops.config', 'ops.rate'];

    /** 路径 => 控制器动作 */
    private const ROUTES = [
        '/ops/queues' => 'queues',
        '/ops/errors' => 'errors',
        '/ops/config' => 'config',
        '/ops/rate' => 'rate',
    ];

    /** 视图区块 / 按钮 / 表体 id（ops/index.html） */
    private const VIEW_IDS = [
        'sec-queues', 'queues-status', 'tb-queues', 'btn-queues-refresh', 'queues-truncated',
        'sec-errors', 'errors-status', 'tb-errors', 'btn-errors-load', 'err-date', 'err-lines',
        'sec-config', 'config-status', 'config-notes', 'tb-config', 'btn-config-load',
        'sec-rate', 'rate-status', 'rate-notes', 'tb-rate-dims', 'tb-rate-buckets',
        'tb-rate-api', 'rate-truncated', 'btn-rate-refresh',
        'roles-env-status', 'tb-roles-env', 'roles-env-notes',
    ];

    /** ops.js 必须绑定的按钮 id */
    private const JS_BINDS = [
        'btn-queues-refresh',
        'btn-errors-load',
        'btn-config-load',
        'btn-rate-refresh',
    ];

    /** OpsPageController 必须下发的 cfg 键 */
    private const CFG_KEYS = [
        'queues_url',
        'errors_url',
        'config_url',
        'rate_url',
        'error_default_lines',
    ];

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /* =====================================================================
     | ① 权限节点
     ===================================================================== */

    public function testNewNodesAreDeclaredInInstaller(): void
    {
        $src = $this->read('scripts/install.php');

        foreach (self::NEW_NODES as $alias) {
            $this->assertStringContainsString(
                "'" . $alias . "' =>",
                $src,
                '节点 ' . $alias . ' 未在 install.php 的 $nodeSpecs 登记'
            );
        }
    }

    public function testViewerRoleHasNoNewOpsNodes(): void
    {
        $src = $this->read('scripts/install.php');

        preg_match('/\$viewerRules\s*=\s*\[(.*?)\];/s', $src, $m);
        $block = (string)($m[1] ?? '');
        $this->assertNotSame('', $block, '解析不到 $viewerRules');

        foreach (self::NEW_NODES as $alias) {
            $this->assertStringNotContainsString(
                "\$nodeIds['" . $alias . "']",
                $block,
                '★ 只读角色拿到了 ' . $alias . ' —— 队列水位 / 错误原文 / 配置快照不该给只读账号'
            );
        }
    }

    public function testOperatorRoleHasAllNewOpsNodes(): void
    {
        $src = $this->read('scripts/install.php');

        preg_match('/\$operatorRules\s*=\s*array_merge\((.*?)\);/s', $src, $m);
        $block = (string)($m[1] ?? '');
        $this->assertNotSame('', $block, '解析不到 $operatorRules');

        foreach (self::NEW_NODES as $alias) {
            $this->assertStringContainsString(
                "\$nodeIds['" . $alias . "']",
                $block,
                '运维角色缺少节点 ' . $alias
            );
        }
    }

    public function testControllerMethodsExist(): void
    {
        $ctl = $this->read('app/controller/api/OpsController.php');

        foreach (self::ROUTES as $action) {
            $this->assertMatchesRegularExpression(
                '/public function ' . $action . '\s*\(/',
                $ctl,
                'OpsController 缺方法 ' . $action . '()'
            );
        }
    }

    /* =====================================================================
     | ② 路由（GET + 挂 AdminAuth 的 group 内）
     ===================================================================== */

    public function testRoutesAreGetInsideAdminApiGroup(): void
    {
        $src = $this->read('config/route.php');

        foreach (self::ROUTES as $path => $action) {
            $this->assertMatchesRegularExpression(
                "#Route::get\('" . preg_quote($path, '#') . "',\s*\[OpsController::class,\s*'" . $action . "'\]\)#",
                $src,
                '路由 GET ' . $path . ' → ' . $action . ' 缺失（只读端点不得 POST 以外的动词语义）'
            );
        }
    }

    /**
     * 三个新路由必须落在挂了 AdminAuth 的 group 内（与既有 /ops/* 同 group）。
     */
    public function testRoutesLurkInsideGuardedGroup(): void
    {
        $src = $this->read('config/route.php');

        $authPos = strpos($src, 'AdminAuth::class');
        $this->assertNotFalse($authPos, 'route.php 解析不到 AdminAuth');

        foreach (self::ROUTES as $path => $action) {
            $pos = strpos($src, "Route::get('" . $path . "'");
            $this->assertNotFalse($pos, '找不到路由 ' . $path);
            $this->assertGreaterThan(
                $authPos,
                $pos,
                '路由 ' . $path . ' 出现在 AdminAuth 声明之前 —— 多半不在鉴权 group 内'
            );
        }
    }

    /* =====================================================================
     | ③ 数据 SQL 种子（阈值）
     ===================================================================== */

    public function testQueueThresholdSeedsExist(): void
    {
        $sql = $this->read('database/001_gw_tables.sql');

        $this->assertStringContainsString("'ops.queue_warn_depth'", $sql,
            '缺 ops.queue_warn_depth 种子 —— 阈值回落代码默认值，运维改库不生效');
        $this->assertStringContainsString("'ops.action_warn_depth'", $sql,
            '缺 ops.action_warn_depth 种子');
        $this->assertStringContainsString("'ops.error_lines'", $sql,
            '缺 ops.error_lines 种子');
    }

    /* =====================================================================
     | ④ 视图 id ↔ JS 绑定 ↔ cfg 键 三方对齐
     ===================================================================== */

    public function testViewContainsEverySectionId(): void
    {
        $view = $this->read('app/view/ops/index.html');

        foreach (self::VIEW_IDS as $id) {
            $this->assertStringContainsString(
                'id="' . $id . '"',
                $view,
                '视图缺少 id=' . $id
            );
        }
    }

    public function testJsBindsEveryButton(): void
    {
        $js = $this->read('public/static/ops.js');

        foreach (self::JS_BINDS as $id) {
            $this->assertStringContainsString(
                "bind('" . $id . "'",
                $js,
                'ops.js 未绑定 ' . $id
            );
        }
    }

    public function testOpsPageControllerExposesCfgKeys(): void
    {
        $ctl = $this->read('app/controller/OpsPageController.php');

        foreach (self::CFG_KEYS as $key) {
            $this->assertStringContainsString(
                "'" . $key . "' =>",
                $ctl,
                'OpsPageController 未下发 cfg.' . $key
            );
        }

        foreach (['queues', 'errors', 'config', 'rate'] as $perm) {
            $this->assertStringContainsString(
                "'" . $perm . "' =>",
                $ctl,
                'OpsPageController 未下发 perms.' . $perm
            );
        }
    }

    public function testJsReadsNewCfgUrls(): void
    {
        $js = $this->read('public/static/ops.js');

        foreach (['queues_url', 'errors_url', 'config_url', 'rate_url'] as $key) {
            $this->assertStringContainsString(
                'cfg.' . $key,
                $js,
                'ops.js 未读取 cfg.' . $key
            );
        }
    }

    public function testViewConfigAnnotationCoversNewKeys(): void
    {
        $view = $this->read('app/view/ops/index.html');

        // 视图文件头的 @var 注解必须跟上真实 $config 形状（防文档漂移）
        foreach (['queues_url', 'errors_url', 'config_url', 'rate_url', 'error_default_lines'] as $key) {
            $this->assertStringContainsString($key, $view,
                '视图 @var 注解缺 ' . $key);
        }
    }

    private function read(string $relative): string
    {
        $path = $this->root . '/' . $relative;
        $content = @file_get_contents($path);

        $this->assertNotFalse($content, '读不到文件：' . $path);

        return (string)$content;
    }
}
