<?php
/**
 * admin 单测 —— DashboardContractTest。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 仪表盘「视图 ↔ 前端脚本」契约测试（**纯静态，不依赖任何服务**）。
 *
 * 动因：P1 把页面从「服务端渲染 + 整页重载」改成了「服务端骨架 + 浏览器轮询填充」。
 * 代价是**服务端不再知道自己渲染的骨架是否被 JS 正确填充** —— 一旦 JS 里的
 * `getElementById('c-onlnie')` 拼错一个字母，页面不会报错、不会 500，
 * 只会**永远显示一个 `—`**。这类静默失效在人工验收时极易漏过。
 *
 * 因此这里把契约钉死：
 *  ① JS 引用的每个 DOM id 必须在视图里存在；
 *  ② JS 读取的每个 `cfg.*` 配置键必须在 `DashboardController` 里注入；
 *  ③ P1 的两条结构性约定（已移除 meta refresh、已挂载静态资源）。
 *
 * 这些断言**锁结构、不对排版有观点**：只做「有没有」的判断，不做位置/顺序判断，
 * 因此不会被 `composer cs` 之类的排版改动打散。
 */
final class DashboardContractTest extends TestCase
{
    private const VIEW = 'app/view/dashboard/index.html';

    private const SCRIPT = 'public/static/dashboard.js';

    private const CONTROLLER = 'app/controller/DashboardController.php';

    /**
     * JS 里用来按 id 取 DOM 的辅助函数名。
     *
     * ⚠ 在 `dashboard.js` 里**新增取 id 的辅助函数时，必须同步加进本表** ——
     * 否则新函数引用的 id 会逃过契约检查（这正是本测试要防的那类漏网）。
     *
     * @var list<string>
     */
    private const ID_ACCESSORS = [
        'setText',
        'setValue',
        'setRowEmpty',
        'sparkline',
        'renderKeyValueTable',
        'markPill',
    ];

    /**
     * ★ 核心断言：JS 引用的每个 DOM id 都必须存在于视图。
     *
     * 失败时的排查方式：在视图 `app/view/dashboard/index.html` 里补上该 id，
     * 或修掉 JS 里的拼写。**不要**为了让测试变绿而删掉 JS 的引用 ——
     * 那等于放弃这块数据的渲染。
     */
    public function testEveryIdReferencedByScriptExistsInView(): void
    {
        $viewIds = $this->viewIds();
        $scriptIds = $this->scriptIds();

        $this->assertNotEmpty($viewIds, '视图里一个 id 都没有，解析八成坏了');
        $this->assertNotEmpty($scriptIds, '脚本里没有解析到任何 id 引用，正则八成失配了');

        $missing = array_values(array_diff($scriptIds, $viewIds));

        $this->assertSame(
            [],
            $missing,
            '脚本引用了视图中不存在的 DOM id（页面会静默留白，不报错）：'
            . implode('、', $missing)
        );
    }

    /**
     * JS 读取的 `cfg.*` 键必须都由控制器注入 ——
     * 少一个键 → 该配置读成 `undefined`（例如轮询间隔变 NaN，定时器直接不排）。
     */
    public function testEveryConfigKeyReadByScriptIsInjectedByController(): void
    {
        $script = $this->read(self::SCRIPT);
        preg_match_all('/\bcfg\.([A-Za-z_][A-Za-z0-9_]*)/', $script, $matches);
        // 捕获组存在即 offset 1 必定存在（无匹配时为空数组），故不需 ?? 兜底
        $used = array_values(array_unique($matches[1]));

        $this->assertNotEmpty($used, '脚本没有读取任何 cfg 键，正则八成失配了');

        $controller = $this->read(self::CONTROLLER);

        $missing = [];
        foreach ($used as $key) {
            if (!str_contains($controller, "'" . $key . "' =>")) {
                $missing[] = $key;
            }
        }

        $this->assertSame(
            [],
            $missing,
            '脚本读取了控制器未注入的配置键：' . implode('、', $missing)
        );
    }

    /**
     * 视图必须把配置注入成 JSON 块 —— 脚本用 `JSON.parse` 读它，缺了就整页不工作。
     */
    public function testViewInjectsConfigAsJsonBlock(): void
    {
        $view = $this->read(self::VIEW);

        $this->assertStringContainsString('id="dashboard-config"', $view);
        $this->assertStringContainsString('application/json', $view);
        // 配置里若含 </script 会提前闭合标签 —— JSON_HEX_TAG 必须开着
        $this->assertStringContainsString('JSON_HEX_TAG', $view, '注入 JSON 必须开启 JSON_HEX_TAG');
    }

    /**
     * P1 已改用 AJAX 轮询，**不得**再退回整页重载。
     *
     * 退回的代价不只是体验：整页重建会丢掉 DOM 状态，趋势（浏览器端环形缓冲）
     * 会每次刷新清零，面板将永远画不出曲线。
     */
    public function testViewNoLongerUsesMetaRefresh(): void
    {
        $this->assertStringNotContainsString(
            'http-equiv="refresh"',
            $this->read(self::VIEW),
            'P1 起必须由 JS 轮询，整页重载会让趋势永远累积不起来'
        );
    }

    /**
     * 静态资源必须被引用（文件名换了而视图没改 = 页面全白）。
     */
    public function testViewMountsStaticAssets(): void
    {
        $view = $this->read(self::VIEW);

        $this->assertStringContainsString('/static/dashboard.js', $view);
        $this->assertStringContainsString('/static/dashboard.css', $view);
    }

    /**
     * ★ XSS 纪律：前端脚本**不得**使用 innerHTML。
     *
     * 服务端返回的字段（角色名、队列名、告警文案、hint）都会被渲染进 DOM，
     * 用 innerHTML 拼接即等于开放注入面。本项目一律走 textContent。
     */
    public function testScriptAvoidsInnerHtml(): void
    {
        $script = $this->read(self::SCRIPT);

        $this->assertDoesNotMatchRegularExpression(
            '/\.innerHTML\s*=/',
            $script,
            '前端脚本不得使用 innerHTML：服务端返回的字符串必须经 textContent 落地'
        );
    }

    /**
     * ★ 轮询编排的两条硬约束（本次实现最容易写错的地方）。
     *
     * ① 不得用 `setInterval`：请求慢于间隔时会让请求层层堆积；
     * ② 每次响应结束都必须续排 —— 包括**陈旧响应被丢弃**的分支。
     *    漏掉后者会让轮询在第一次可见性切换后永久停摆（页面看起来「就是不动了」）。
     */
    public function testPollingUsesChainedTimeoutAndAlwaysReschedules(): void
    {
        $script = $this->read(self::SCRIPT);

        $this->assertDoesNotMatchRegularExpression(
            '/setInterval\s*\(/',
            $script,
            '必须用链式 setTimeout：setInterval 会在慢响应时堆积请求'
        );

        // 每个循环只有一个真正的 setTimeout 调用点（在 schedule* 里），
        // 其余全是 schedule*() 的调用 —— 这样保证「排下一轮」的口径只有一处。
        $this->assertSame(
            2,
            preg_match_all('/window\.setTimeout\s*\(/', $script),
            '两条循环各自只应有一个 setTimeout 调用点（收敛在 scheduleLive / scheduleSlow 内）'
        );

        // 每条循环必须覆盖「成功续排」与「失败退避续排」两条路径：
        // 调用点 = 函数定义 1 + 成功 1 + 失败 1
        foreach (['scheduleLive', 'scheduleSlow'] as $scheduler) {
            $this->assertGreaterThanOrEqual(
                3,
                preg_match_all('/\b' . $scheduler . '\s*\(/', $script),
                $scheduler . ' 必须同时存在「定义 / 成功续排 / 失败退避续排」三处'
            );
        }

        // 陈旧响应必须显式处理，且处理方式是「直接返回」（不续排）——由新循环接手
        $this->assertStringContainsString('stale', $script, '缺少陈旧响应判定：可见性切换后会出现双定时器');
    }

    /**
     * 视图里的全部 DOM id。
     *
     * ⚠ 必须返回**值列表**而非 `array_fill_keys()` 的字典 —— 后者会让
     * `array_diff($scriptIds, $viewIds)` 去比较「值」（全是 `true`），
     * 结果恒等于 `$scriptIds`，断言永远失败（已踩过）。
     *
     * @return list<string>
     */
    private function viewIds(): array
    {
        preg_match_all('/\bid="([^"]+)"/', $this->read(self::VIEW), $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @return list<string> */
    private function scriptIds(): array
    {
        $script = $this->read(self::SCRIPT);
        $accessors = implode('|', array_map(static fn (string $n): string => preg_quote($n, '/'), self::ID_ACCESSORS));
        $quoted = "'([^']+)'";

        $found = [];

        // 形态一：辅助函数第一个入参是 id，如 setText('meta-ts', ...)
        preg_match_all('/\b(?:' . $accessors . ')\s*\(\s*' . $quoted . '/', $script, $m1);
        $found = array_merge($found, $m1[1]);

        // 形态二：$('id')  ——取 DOM 的原语
        preg_match_all('/\$\s*\(\s*' . $quoted . '\s*\)/', $script, $m2);
        $found = array_merge($found, $m2[1]);

        // 只保留看着像 DOM id 的字面量：排除 URL / 选择器 / 类名之类
        $found = array_filter($found, static fn (string $id): bool => $id !== ''
                && !str_contains($id, '/')
                && !str_contains($id, '#')
                && !str_contains($id, '.')
                && !str_contains($id, ':')
                && preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $id) === 1);

        return array_values(array_unique($found));
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        $content = file_get_contents($path);

        $this->assertNotFalse($content, '读不到文件：' . $path);

        return (string)$content;
    }
}
