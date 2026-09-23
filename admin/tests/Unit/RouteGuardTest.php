<?php

declare(strict_types=1);

namespace tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * ★ 路由鉴权边界契约测试（**纯静态，不引导框架、不依赖任何服务**）。
 *
 * 本文件防的是**同一类洞的两个方向**——「未鉴权的入口」：
 *
 * ① **默认路由方向**：webman 会把 `/controller/action` 直接解析到控制器的公有动作，
 *    这条路径**不经过** `config/route.php` 上挂的 `AdminAuth`。
 *    判定见 `vendor/workerman/webman-framework/src/App.php:172-182`：
 *    `parseControllerAction()` 之后先问 `Route::isDefaultRouteDisabled($controller)`，
 *    没被禁用才走默认路由。
 *    ⇒ 只要**默认路径 ≠ 显式路径**，显式路由上的鉴权就是**完全空的**。
 *    典型：显式路由是 `/api/ops/redis/scan`，而 `OpsController::redisScan` 的默认路径是
 *    `/api/ops/redisScan` —— 后者未鉴权可访问（2026-09-23 实测修复）。
 *    2026-09-23 二次追加：webman **脚手架自带**的 `app\controller\IndexController`
 *    正是「从未注册过、只靠默认路由暴露」的极端情形，三个动作全部零鉴权
 *    （`/index/json` 甚至返回一个与本项目成功响应**形状一致**的信封）。
 *    2026-09-24 该控制器与 `app/view/index/` 已**彻底删除**（见
 *    `testScaffoldWelcomeControllerIsRemoved`），本段保留作背景说明。
 *
 * ② **显式路由方向**：新加一条页面/API 路由时**忘了挂 `AdminAuth`**。
 *    这是更常见的人为漏项，且漏了之后页面照常 200、功能照常可用，
 *    只有「不带 Cookie 访问」才会看出来。
 *
 * 两条断言互为补充：① 保证「没注册的动作不可达」，② 保证「注册了的动作都要鉴权」。
 *
 * ---------------------------------------------------------------------
 * 为什么用静态解析而不是引导框架后调 `Route::isDefaultRouteDisabled()`
 * ---------------------------------------------------------------------
 * `phpunit.xml` 的 bootstrap 只有 `vendor/autoload.php`，**不引导框架**；而
 * `admin/support/bootstrap.php` 会做 `Dotenv::load()` + `Config::clear()` + `Route::load()`，
 * 在单测里 require 它属于「为了断言一个字符串而启动半个应用」——既有副作用，
 * 又会被本仓库 `failOnWarning/Notice/Deprecation = true` 放大成假红。
 * 故沿用 `SessionContractTest` 已验证的**静态解析**路线：断言「有没有」而不判断位置顺序，
 * 因此 `composer cs` 之类的排版改动打不散它。
 */
final class RouteGuardTest extends TestCase
{
    private const ROUTES = 'config/route.php';

    private const CONTROLLER_DIR = 'app/controller';

    /**
     * 允许「不挂 AdminAuth 且不在 `/api` group 内」的显式路由白名单。
     *
     * 目前只有根路径 `/`，它由下方 `testRootPathIsAnExplicitRedirect` **单独**断言为
     * 「重定向到 /app/admin 且不返回任何数据」，故不是豁免而是**换了一条更精确的断言**。
     * 新增白名单项时必须一并补上等价的精确断言，否则等于开洞。
     *
     * @var list<string>
     */
    private const UNGUARDED_PATH_ALLOWLIST = ['/'];

    /**
     * 允许不做 `disableDefaultRoute()` 的控制器 —— **刻意为空，且刻意不做成白名单机制**。
     *
     * 本项目 `app/controller/**` 下的控制器全部属于自己，没有一个是靠默认路由工作的，
     * 故不存在需要豁免的情形。真要开豁免，理由必须是「它的显式路由与默认路径完全重合
     * 且都已挂 AdminAuth」——那种情况下禁用与否都不影响安全，豁免只是为了少一行。
     *
     * 所以这里**不**预置一个空数组常量：空数组会让 `in_array()` 恒假，
     * PHPStan L6 会直接判 `function.impossibleType`（实测踩到）。
     * 真需要豁免时，届时再加常量与对应的 `in_array` 分支即可。
     */

    /* =====================================================================
     | ① 默认路由方向
     ===================================================================== */

    /**
     * ★ `app/controller/**` 下的每个控制器都必须在 `config/route.php` 里禁用默认路由。
     *
     * 这是 `route.php` 里那句注释「新增本项目控制器时必须在此补一行」的**机器化版本**——
     * 靠人记必然漏，而漏掉的后果是**静默的免鉴权入口**。
     *
     * 反向也断了一条：**所有被禁用的 FQCN 必须落在 `app\controller\` 之下**。
     * 这是「不要全局禁用」的机器化表达 —— 一刀切 `Route::disableDefaultRoute()` 会让
     * webman-admin 插件的 `/app/admin/*` 整片失效（它正是靠默认路由解析的）。
     */
    public function testEveryAppControllerHasItsDefaultRouteDisabled(): void
    {
        $controllers = $this->controllerClasses();
        $this->assertNotEmpty($controllers, '一个控制器都没扫到，目录或解析八成坏了');

        $disabled = $this->disabledControllerClasses();
        $this->assertNotEmpty($disabled, 'route.php 里没有解析到任何 disableDefaultRoute 调用，正则八成失配了');

        $missing = array_values(array_diff($controllers, $disabled));

        $this->assertSame(
            [],
            $missing,
            '以下控制器未禁用「默认路由」——它的每个公有动作都能被 /<controller>/<action> 免鉴权访问'
            . '（默认路由不经过 config/route.php 上挂的 AdminAuth）：' . implode('、', $missing)
        );

        // 反向：禁用的目标必须只落在本项目自己的控制器上
        $foreign = array_values(array_filter(
            $disabled,
            static fn (string $fqcn): bool => !str_starts_with($fqcn, 'app\\controller\\')
        ));
        $this->assertSame(
            [],
            $foreign,
            'disableDefaultRoute 指向了本项目之外的控制器：' . implode('、', $foreign)
            . ' —— webman-admin 插件的 /app/admin/* 正是靠默认路由解析，禁掉它会让整个后台 UI 失效'
        );
    }

    /**
     * ★ webman 脚手架欢迎页控制器必须**不存在**（2026-09-23 发现漏洞，2026-09-24 彻底移除）。
     *
     * 演变史：2026-09-23 先补 `Route::disableDefaultRoute(IndexController::class);` 关闭
     * 三个零鉴权默认路径；2026-09-24 删除控制器文件与 `app/view/index/` 视图目录。
     * 本用例从「禁用」改钉为「不存在」：
     *
     * - 类文件不得存在 —— `composer create-project` 或日后升级若重新生成脚手架文件，测试会红；
     * - `config/route.php` 不得再出现 `use` 导入或 `disableDefaultRoute(IndexController...)`
     *   （注释里**提及**「已移除」允许 —— 所以断言只锚定代码形态，不锚定字样）。
     *
     * `/index/json` 当年返回 `{"code":0,"msg":"ok"}` —— 与本项目成功响应**形状一致**，
     * 未鉴权即可拿到一个「看起来成功」的响应，会误导健康探针与扫描器；文件不存在后即普通 404。
     */
    public function testScaffoldWelcomeControllerIsRemoved(): void
    {
        $scaffold = dirname(__DIR__, 2) . '/app/controller/IndexController.php';

        $this->assertFileDoesNotExist(
            $scaffold,
            '脚手架欢迎页控制器已彻底移除，不得重新出现 —— 它从未注册过，'
            . '出现即意味着三个零鉴权默认路径（/index/index、/index/view、/index/json）重新暴露。'
            . '若为 composer 升级误生成，直接删除文件与 app/view/index/ 目录'
        );

        $routeFile = $this->read(self::ROUTES);

        $this->assertDoesNotMatchRegularExpression(
            '/^use\s+app\\\\controller\\\\IndexController;/m',
            $routeFile,
            'config/route.php 不得再导入已删除的 IndexController'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/Route::disableDefaultRoute\(\s*(?:IndexController|app\\\\controller\\\\IndexController)::class\s*\)/',
            $routeFile,
            'IndexController 文件已删除，route.php 里的 disableDefaultRoute(IndexController) '
            . '引用一个不存在的类 —— 请删除该行（见 route.php 顶部注释）'
        );
    }

    /**
     * 禁用**不得**是全局的一刀切。
     *
     * `Route::disableDefaultRoute()` 不传参 = 禁用 `''`（根 app）的全部默认路由，
     * 而 webman-admin 插件的页面解析依赖它 —— 全站禁用会让整个后台 UI 变成 404，
     * 且症状是「登录页都打不开」，与「漏禁用」的静默相反、属于**立刻可见**的故障，
     * 故这里主要防止「为了让上一条测试变绿而顺手全局禁用」。
     */
    public function testDefaultRouteIsNotDisabledGlobally(): void
    {
        $routeFile = $this->read(self::ROUTES);

        $this->assertDoesNotMatchRegularExpression(
            '/Route::disableDefaultRoute\(\s*\)\s*;/',
            $routeFile,
            '不得全局禁用默认路由：webman-admin 的 /app/admin/* 靠默认路由解析，全禁会让整个后台 UI 失效'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/Route::disableDefaultRoute\(\s*''\s*\)\s*;/",
            $routeFile,
            "不得 `disableDefaultRoute('')`：语义等同全局禁用默认路由"
        );
    }

    /* =====================================================================
     | ② 显式路由方向
     ===================================================================== */

    /**
     * 根路径必须是一条**显式**路由。
     *
     * 背景：`/` 的默认路由原本恰好落在 `IndexController::index` 上（该控制器 2026-09-24 已删除）。
     * 若没人显式接管 `/`，访问根路径就会从「200 欢迎页」变成 404 —— 这对运维是**倒退**。
     * 故这里断言 `/` 由显式路由接管，且是**重定向到管理面**（不返回任何数据）。
     */
    public function testRootPathIsAnExplicitRedirect(): void
    {
        $routeFile = $this->read(self::ROUTES);

        $this->assertMatchesRegularExpression(
            "#Route::get\(\s*'/',\s*[^;]*redirect\(\s*'/app/admin'\s*\)#s",
            $routeFile,
            "根路径 `/` 必须由显式路由重定向到 /app/admin —— 否则禁用脚手架控制器后 `/` 会变成 404"
        );
    }

    /**
     * ★ 每一条显式路由都必须被 `AdminAuth` 覆盖。
     *
     * 覆盖的两种合法形式：
     *   a) 语句自身带 `->middleware([AdminAuth::class])`（页面路由用这种）；
     *   b) 位于 `Route::group('/api', …)->middleware([AdminAuth::class])` 的**组体内**（API 用这种）。
     * 其余一律判为漏挂 —— 白名单只有 `UNGUARDED_PATH_ALLOWLIST` 里的根路径，
     * 且它由 `testRootPathIsAnExplicitRedirect` 单独断言为纯重定向。
     *
     * ⚠ 组体的判定用**子串位置**比较（`Route::group('/api'` 的偏移 → 组尾 `->middleware(...)` 的偏移），
     * 而**不做**大括号配对 —— 路由路径里的 `{uid}` / `{clientId}` 会干扰括号计数。
     * 这样判定的是「语句是否落在组区间内」，对排版与嵌套都足够稳健。
     */
    public function testEveryExplicitRouteIsGuardedByAdminAuth(): void
    {
        $routeFile = $this->read(self::ROUTES);

        // 匹配「一条完整路由语句」：从 Route::<verb>( 到该语句的第一个 ';'
        preg_match_all(
            '/Route::(get|post|put|patch|delete|any)\s*\([^;]*;/',
            $routeFile,
            $matches,
            PREG_OFFSET_CAPTURE
        );
        $this->assertNotEmpty($matches[0], '没有解析到任何路由语句，正则八成失配了');

        $groupStart = strpos($routeFile, "Route::group('/api'");
        $this->assertNotFalse($groupStart, "没有解析到 Route::group('/api') —— 分组结构变了，本用例需同步");
        $groupEnd = strpos($routeFile, '})->middleware([AdminAuth::class]);', $groupStart);
        $this->assertNotFalse($groupEnd, '没有解析到 /api 组的 AdminAuth 中间件收尾，分组结构变了，本用例需同步');

        $unguarded = [];
        $groupRoutes = 0;

        foreach ($matches[0] as [$statement, $offset]) {
            $inGroup = $offset > $groupStart && $offset < $groupEnd;
            if ($inGroup) {
                $groupRoutes++;
                continue;
            }

            if (str_contains($statement, 'middleware([AdminAuth::class])')) {
                continue;
            }

            // 未被中间件覆盖：只允许白名单里的根路径（且它已被上一条用例断言为纯重定向）
            preg_match("/Route::\w+\(\s*'([^']+)'/", $statement, $pathMatch);
            $path = $pathMatch[1] ?? '(解析失败)';
            if (in_array($path, self::UNGUARDED_PATH_ALLOWLIST, true)) {
                continue;
            }

            $unguarded[] = $path;
        }

        $this->assertSame(
            [],
            $unguarded,
            '以下显式路由既没挂 AdminAuth、也不在 /api 分组内 —— 未登录即可访问：' . implode('、', $unguarded)
        );
        $this->assertGreaterThan(0, $groupRoutes, '/api 组内一条路由都没解析到，区间判定八成坏了');
    }

    /**
     * `/api` 分组必须挂 `AdminAuth` —— 组内所有 API 的鉴权全靠这一处。
     *
     * 单独断言的理由：`testEveryExplicitRouteIsGuardedByAdminAuth` 把「在组内」当作已鉴权，
     * 若组自身的中间件被删，那条用例会因为「组区间定位失败」而报结构变化，
     * 报错信息指向分组结构而不是「中间件没了」。本用例把后者说得更直白。
     */
    public function testApiGroupCarriesAdminAuthMiddleware(): void
    {
        $this->assertMatchesRegularExpression(
            "#\}\)->middleware\(\[AdminAuth::class\]\)\s*;#",
            $this->read(self::ROUTES),
            '/api 分组必须挂 AdminAuth —— 组内所有 API 的鉴权都依赖它'
        );
    }

    /* =====================================================================
     | 辅助
     ===================================================================== */

    /**
     * 扫描 `app/controller/**` 下声明的全部控制器 FQCN。
     *
     * 跳过 `abstract` / `interface` / `trait` —— 它们无法被路由实例化，
     * 也因此不可能成为默认路由的入口（本目录目前没有，属防御）。
     *
     * @return list<string>
     */
    private function controllerClasses(): array
    {
        $root = $this->path(self::CONTROLLER_DIR);
        $this->assertDirectoryExists($root);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $classes = [];
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string)file_get_contents($file->getPathname());

            // ⚠ 一律用**原始源码**配 `/m` 锚点，不要用 `php_strip_whitespace()`：
            //   后者把换行也一并压掉，`^` 锚点随之失效，导致「一个类都扫不到」。
            //   注释行以 `*` 开头，故不会撞上 `^\s*(final |abstract )?class`。
            if (preg_match('/^\s*(?:abstract\s+class|interface|trait)\s+/m', $source) === 1) {
                continue;
            }

            if (preg_match('/^\s*namespace\s+([^;]+);/m', $source, $ns) !== 1) {
                continue;
            }
            if (preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $source, $cls) !== 1) {
                continue;
            }

            $classes[] = trim($ns[1], '\\') . '\\' . $cls[1];
        }

        sort($classes);

        return array_values(array_unique($classes));
    }

    /**
     * 解析 `config/route.php` 里所有 `Route::disableDefaultRoute(X::class)` 的目标 FQCN。
     *
     * 必须先把**别名**还原成 FQCN：本项目为区分同名的页面/API 控制器用了
     * `use app\controller\api\SessionController as SessionApiController;`，
     * 只取短名会把 `SessionApiController` 当成一个不存在的 FQCN 而漏判。
     *
     * @return list<string>
     */
    private function disabledControllerClasses(): array
    {
        $routeFile = $this->read(self::ROUTES);

        // 1) 收集 `use` 映射：别名（或短名） → FQCN
        preg_match_all(
            '/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/m',
            $routeFile,
            $uses,
            PREG_SET_ORDER
        );
        $aliasMap = [];
        foreach ($uses as $use) {
            $fqcn = trim($use[1], '\\');
            // ⚠ 必须用 `?? ''`：`use Foo\Bar;` 这种**无 as** 的写法下 `$use[2]` **不存在**
            //   （PHP 8 的可选尾组不会补空串），直接比较会触发 Undefined array key 警告 →
            //   本仓库 `failOnWarning=true`，警告即失败；且会把别名算成 null 从而整表错位。
            $alias = ($use[2] ?? '') !== '' ? $use[2] : substr($fqcn, (int)strrpos($fqcn, '\\') + 1);
            $aliasMap[$alias] = $fqcn;
        }

        // 2) 收集 `disableDefaultRoute(X::class)` 里的 X
        preg_match_all(
            '/Route::disableDefaultRoute\(\s*([A-Za-z0-9_\\\\]+)::class\s*\)/',
            $routeFile,
            $calls
        );
        $this->assertNotEmpty($calls[1], 'route.php 里没有解析到形如 disableDefaultRoute(X::class) 的调用');

        // 3) 还原为 FQCN；解析不到别名的（例如直接写 FQCN，不用 use）原样保留其反斜杠形态
        $fqcns = [];
        foreach ($calls[1] as $name) {
            $fqcns[] = $aliasMap[$name] ?? $name;
        }

        sort($fqcns);

        return array_values(array_unique($fqcns));
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
