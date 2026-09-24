<?php
/**
 * admin 单测 —— SessionContractTest。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace tests\Unit;

use app\controller\SessionController;
use app\service\SessionInspector;
use PHPUnit\Framework\TestCase;

/**
 * P2 会话查询页「视图 ↔ 前端脚本 ↔ 路由 ↔ 只读边界」契约测试
 * （**纯静态 + 纯常量，不依赖任何服务**）。
 *
 * 与 P1 的 `DashboardContractTest` 同一动机：页面改成「骨架 + 按需取数」之后，
 * 服务端**不再知道自己渲染的骨架是否被 JS 正确填充**。JS 里一个 id 拼错、一个 cfg 键漏注入，
 * 页面都不会报错、不会 500，只会**静默留白**。这类失效在人工验收时极易漏过。
 *
 * 本文件把四组契约钉死：
 *
 * ① **DOM id / 配置键**：JS 引用的每个 id 在视图里存在；JS 读取的每个 `cfg.*` 由控制器注入；
 * ② **P2 的结构性不变量**：无定时器（不轮询）、页内抽屉 + URL 承载 clientId、
 *   引用静态资源、无 meta refresh、无 innerHTML；
 * ③ **只读边界**：会话读取链路的三个文件里**不得出现任何 Redis 写命令**；
 * ④ **路由覆盖**：控制器里声明的每个端点字符串都能在 `config/route.php` 找到对应路由，
 *    且全是 GET；两个 Session 控制器都已 `disableDefaultRoute()`。
 *
 * 断言全部**锁结构、不对排版有观点**（只判断「有没有」，不判断位置/顺序），
 * 因此不会被 `composer cs` 之类的排版改动打散。
 */
final class SessionContractTest extends TestCase
{
    private const VIEW = 'app/view/session/index.html';

    private const SCRIPT = 'public/static/session.js';

    private const STYLE = 'public/static/session.css';

    private const PAGE_CONTROLLER = 'app/controller/SessionController.php';

    private const API_CONTROLLER = 'app/controller/api/SessionController.php';

    private const ROUTES = 'config/route.php';

    /**
     * 运维端点前缀（P4）。
     *
     * 既是路由动词判定的依据，也是「写操作只能指向这四个端点」的边界。
     *
     * ⚠ 本页新增运维端点时必须同步这里，否则新端点会被当成取数端点要求 GET。
     */
    private const OPS_ENDPOINT_PREFIX = '/api/ops-action/';

    /**
     * 会话读取链路上**不允许出现写命令**的三个文件。
     *
     * 覆盖到 `RedisReader`（唯一 Redis 门面）与两个控制器，而不是只查 `SessionInspector` ——
     * 后加一个「顺手清理一下」的写命令，最容易落在控制器里。
     */
    private const READ_PATH_FILES = [
        self::API_CONTROLLER,
        'app/service/SessionInspector.php',
        'app/service/RedisReader.php',
    ];

    /**
     * Redis 写命令清单（大小写不敏感，只匹配 `->cmd(` 与 `::cmd(` 形态）。
     *
     * 用 `php_strip_whitespace()` 去掉注释后再匹配 —— 三个文件的注释里**大量提到**写语义
     * （例如「`markOffline()` 会 `sRem`」），直接扫原文会全是假阳性。
     *
     * ⚠ 新增 Redis 写命令时同步补进本表；漏了不会报错，只会让这条断言悄悄失效。
     */
    private const WRITE_COMMANDS = [
        'del', 'unlink', 'set', 'setEx', 'pSetEx', 'setNx', 'mSet', 'mSetNx',
        'hSet', 'hMSet', 'hDel', 'hIncrBy', 'hIncrByFloat',
        'sAdd', 'sRem', 'sMove', 'sPop', 'sInterStore', 'sUnionStore', 'sDiffStore',
        'lPush', 'rPush', 'lPop', 'rPop', 'lTrim', 'lRem', 'lSet', 'lInsert', 'rPopLPush',
        'zAdd', 'zRem', 'zRemRangeByRank', 'zRemRangeByScore', 'zIncrBy',
        'expire', 'expireAt', 'pExpire', 'pExpireAt', 'persist',
        'rename', 'renameNx', 'incr', 'decr', 'incrBy', 'decrBy', 'incrByFloat',
        'flushDb', 'flushAll', 'publish', 'eval', 'evalSha', 'multi', 'exec', 'discard',
        'watch', 'unwatch', 'restore', 'migrate',
    ];

    /**
     * JS 里按 id 取 DOM 的辅助函数名。
     *
     * ⚠ 在 `session.js` 里**新增取 id 的辅助函数时必须同步加进本表** ——
     * 否则新函数引用的 id 会逃过契约检查（正是本测试要防的那类漏网）。
     *
     * `opsVal`（P4 运维区块的读框辅助）必须在此列 —— 它内部是 `$(id)` 的动态取值，
     * 静态解析抓不到字面量，漏登记就会让整组运维输入框逃过契约检查。
     * `bind` 同理：按钮 id 写错时页面只有一个「点了没反应」的空按钮，不报错。
     *
     * @var list<string>
     */
    private const ID_ACCESSORS = ['setText', 'setRowEmpty', 'setNote', 'setChips', 'opsVal', 'bind'];

    /* =====================================================================
     | ① DOM id 与配置键
     ===================================================================== */

    /**
     * ★ JS 引用的每个 DOM id 都必须存在于视图。
     *
     * 失败时的排查方式：在视图里补上该 id，或修掉 JS 里的拼写。
     * **不要**为了让测试变绿而删掉 JS 的引用 —— 那等于放弃这块数据的渲染。
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
            '脚本引用了视图中不存在的 DOM id（页面会静默留白，不报错）：' . implode('、', $missing)
        );
    }

    /**
     * JS 读取的 `cfg.*` 键必须都由控制器注入。
     *
     * 少一个键 → 该配置读成 `undefined`，症状随键而异且都不报错：
     * `page_size` 变 `NaN`（请求带 `size=NaN`，被后端夹成默认值）、
     * `scopes` 变 `undefined`（`cfg.scopes.indexOf` 直接抛异常 → 整页不工作）。
     */
    public function testEveryConfigKeyReadByScriptIsInjectedByController(): void
    {
        $script = $this->read(self::SCRIPT);
        preg_match_all('/\bcfg\.([A-Za-z_][A-Za-z0-9_]*)/', $script, $matches);
        $used = array_values(array_unique($matches[1]));

        $this->assertNotEmpty($used, '脚本没有读取任何 cfg 键，正则八成失配了');

        $controller = $this->read(self::PAGE_CONTROLLER);

        $missing = [];
        foreach ($used as $key) {
            if (!str_contains($controller, "'" . $key . "' =>")) {
                $missing[] = $key;
            }
        }

        $this->assertSame([], $missing, '脚本读取了控制器未注入的配置键：' . implode('、', $missing));
    }

    public function testViewInjectsConfigAsJsonBlock(): void
    {
        $view = $this->read(self::VIEW);

        $this->assertStringContainsString('id="session-config"', $view);
        $this->assertStringContainsString('application/json', $view);
        // 配置里若含 </script 会提前闭合标签 —— JSON_HEX_TAG 必须开着
        $this->assertStringContainsString('JSON_HEX_TAG', $view, '注入 JSON 必须开启 JSON_HEX_TAG');
    }

    public function testViewMountsStaticAssets(): void
    {
        $view = $this->read(self::VIEW);

        $this->assertStringContainsString('/static/session.js', $view);
        $this->assertStringContainsString('/static/session.css', $view);
        $this->assertNotFalse(@file_get_contents($this->path(self::STYLE)), '样式文件缺失：' . self::STYLE);
    }

    /* =====================================================================
     | ② P2 结构性不变量
     ===================================================================== */

    /**
     * ★ 本页**不得有任何轮询**。
     *
     * 这是 P2 最重要的一条约定（会话列表是检索型视图，静止时无需刷新）。
     * 用「脚本内不存在定时器调用」来表达，而不是「不存在某个具体函数名」——
     * 否则换一种定时手段（例如 `requestAnimationFrame` 递归、`setImmediate`）就绕过去了，
     * 故这里同时禁掉整族入口。
     */
    public function testScriptHasNoPollingTimers(): void
    {
        $script = $this->read(self::SCRIPT);

        $this->assertDoesNotMatchRegularExpression(
            '/\b(setTimeout|setInterval|setImmediate|requestAnimationFrame)\s*\(/',
            $script,
            '会话页必须按需取数：出现任何定时器/帧回调都会把页面变回轮询模型'
        );
        $this->assertStringNotContainsString(
            'visibilitychange',
            $script,
            '没有定时器就不需要可见性编排；出现它说明有人把轮询模型照搬过来了'
        );
    }

    /**
     * ★ 详情必须是**页内抽屉**，且 clientId 由 URL 承载。
     *
     * 两条一起断言，因为它们互为前提：只做抽屉不写 URL → 刷新/分享链接后抽屉丢失；
     * 只写 URL 不做抽屉 → 又退回整页跳转。
     */
    public function testDetailUsesInPageDrawerWithUrlState(): void
    {
        $view = $this->read(self::VIEW);
        $script = $this->read(self::SCRIPT);

        $this->assertStringContainsString('id="drawer"', $view, '视图必须提供抽屉容器');
        $this->assertStringContainsString('id="btn-drawer-close"', $view, '抽屉必须有关闭入口');

        $this->assertStringContainsString('history.replaceState', $script, '抽屉状态必须同步到 URL');
        $this->assertStringContainsString('location.search', $script, '必须能从 URL 还原抽屉状态');
        $this->assertStringNotContainsString(
            'location.href',
            $script,
            '禁止整页跳转：详情必须页内展开'
        );
    }

    public function testViewNoLongerUsesMetaRefresh(): void
    {
        $this->assertStringNotContainsString(
            'http-equiv="refresh"',
            $this->read(self::VIEW),
            '本页由 JS 按需取数，整页重载会丢掉抽屉与筛选状态'
        );
    }

    /**
     * ★ XSS 纪律：前端脚本**不得**使用 innerHTML。
     *
     * 服务端返回的 uid / device_id / clientId / 离线消息体 / hint 都会被渲染进 DOM，
     * 用 innerHTML 拼接即等于开放注入面。本项目一律走 textContent。
     */
    public function testScriptAvoidsInnerHtml(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/\.innerHTML\s*=/',
            $this->read(self::SCRIPT),
            '前端脚本不得使用 innerHTML：服务端返回的字符串必须经 textContent 落地'
        );
    }

    /**
     * ★ 取数一律 GET；写方法只允许一处，且必须收在运维区块的 `postJson()` 里。
     *
     * P2 时本页纯只读，原断言是「不得出现任何写方法」。P4 引入运维动作后这条必须放宽，
     * 但**放宽的口径要收得很紧**，否则「页面从哪发起写操作」就没人管了：
     *
     * 1. `method: 'POST'` 全文**只允许一处** —— 多一处即说明散落了第二个写入口；
     * 2. 该处必须落在 `postJson()` 函数体内 —— 防止有人在取数函数里直接 `fetch(..., 'POST')`；
     * 3. `runOps()` 的调用点必须与运维按钮一一对应 —— 多出一个即「存在非按钮触发的写操作」
     *    （例如定时踢人、列表加载顺手下线），那正是 P2 钉下的「零定时器」要挡的东西。
     *
     * 块注释先剥掉：注释里出现 `method: 'POST'` 这类说明文字不该被当成调用。
     */
    public function testScriptUsesGetForReadsAndOnePostOnlyForOps(): void
    {
        $script = $this->stripBlockComments($this->read(self::SCRIPT));

        $this->assertStringContainsString("method: 'GET'", $script, '取数必须显式声明 GET');

        preg_match_all("/method\\s*:\\s*'POST'/", $script, $posts);
        $this->assertCount(
            1,
            $posts[0],
            '★ POST 只允许一处：本页的写操作全部收在运维区块，不得散落到取数逻辑里'
        );

        $pos = strpos($script, "method: 'POST'");
        $fnPos = strpos($script, 'function postJson');
        $this->assertIsInt($pos, "找不到 method: 'POST'");
        $this->assertIsInt($fnPos, '找不到 postJson()：写操作的唯一出口被删了');
        $this->assertGreaterThan(
            $fnPos,
            $pos,
            "method: 'POST' 必须位于 postJson() 内部 —— 别在取数函数里直接发写请求"
        );

        preg_match_all('/\brunOps\(/', $script, $calls);
        preg_match_all("/bind\\('btn-ops-/", $script, $btns);
        $this->assertSame(
            count($btns[0]) + 1,
            count($calls[0]),
            'runOps 出现次数（含 1 处定义）必须恰好等于运维按钮数 +1 —— 多出来说明有非按钮触发的写操作'
        );
    }

    /**
     * 截断与空态区分必须在**脚本**里出现（行为正确性由 `tests/Frontend/session_render_check.js` 断言）。
     *
     * 这里只做「关键词存在」的静态检查：挡住「把这些字段整个删掉」这类改动。
     */
    public function testScriptHandlesTruncationAndSkeletonState(): void
    {
        $script = $this->read(self::SCRIPT);

        $this->assertStringContainsString('truncated', $script, 'SCAN 截断必须被处理，不允许静默截断');
        $this->assertStringContainsString('skeleton_ok', $script, '空态必须能区分「没人连」与「DB/PREFIX 配错」');
        $this->assertStringContainsString('offline_at', $script, 'offline_at 只能作为「最近一次断开时间」展示');
    }

    /**
     * ★ 在线判据不得改回 `offline_at`。
     *
     * `offline_at` 是粘性字段（主项目 `bind()` 不清理、`markOffline()` 只补写），
     * 重连后的 UDP 连接会长期带着它。前端若用它判在线，会把活跃连接显示成离线。
     * 判据只能来自后端的 `state` 字段（`online:clients` 成员资格）。
     */
    public function testOnlineStateComesFromServerNotFromOfflineAt(): void
    {
        $script = $this->read(self::SCRIPT);

        $this->assertStringContainsString('row.state', $script, '状态必须取自响应的 state 字段');
        $this->assertDoesNotMatchRegularExpression(
            "/offline_at[^\n]{0,80}(===|!==|==|!=|\\?)/",
            $script,
            '不得用 offline_at 推导在线状态'
        );
    }

    /* =====================================================================
     | ③ 只读边界
     ===================================================================== */

    /**
     * ★ 会话读取链路上**不得出现任何 Redis 写命令**。
     *
     * 这条是 P2 的「只读」不变量在代码层的落点：后台可以读任意 `gwpush:*` 键，
     * 但改变推送系统状态必须走主项目 HTTP API（且写操作属后续阶段）。
     *
     * 用 `php_strip_whitespace()` 得到「无注释源码」再匹配 —— 否则注释里提到的
     * `sRem` / `hDel` 会造成成片假阳性。
     */
    public function testReadPathContainsNoRedisWriteCommands(): void
    {
        $pattern = '/(?:->|::)(' . implode('|', self::WRITE_COMMANDS) . ')\s*\(/i';

        foreach (self::READ_PATH_FILES as $file) {
            $stripped = php_strip_whitespace($this->path($file));
            $this->assertNotSame('', $stripped, '读不到无注释源码：' . $file);

            preg_match_all($pattern, $stripped, $matches);
            $this->assertSame(
                [],
                array_values(array_unique($matches[1])),
                $file . ' 出现了 Redis 写命令 —— 会话读取链路必须纯只读（写操作属后续阶段，且须走主项目 HTTP API）'
            );
        }
    }

    /**
     * `SessionInspector::SIZE_MAX` 是「无 N+1」不变量的前提，页大小候选不得越界。
     *
     * 刻意不写 `assertNotEmpty(SIZE_OPTIONS)`：它是对字面量常量的判断，PHPStan 会判为
     * 恒真（`method.alreadyNarrowedType`）。下面的逐项边界断言才是真正要守的东西。
     */
    public function testSizeOptionsAreWithinInspectorMax(): void
    {
        foreach (SessionController::SIZE_OPTIONS as $size) {
            $this->assertLessThanOrEqual(
                SessionInspector::SIZE_MAX,
                $size,
                '页大小候选 ' . $size . ' 超过 SessionInspector::SIZE_MAX：下拉能选、后端却会静默夹取'
            );
            $this->assertGreaterThanOrEqual(SessionInspector::SIZE_MIN, $size);
        }
    }

    /* =====================================================================
     | ④ 路由覆盖
     ===================================================================== */

    /**
     * ★ 控制器里声明的每个端点字符串，都必须能在 `config/route.php` 找到对应路由。
     *
     * 匹配规则（不是简单 `str_contains`，否则拼错一个字母也能过）：
     * 路由路径要么与端点完全相同，要么以端点开头且**剩余部分恰好是一个路径参数**
     * （形如 `{clientId}` / `{uid}`）。这样 `/api/session/` 与 `/api/sessions/`
     * 这类单复数笔误会被抓住，而 `/api/sessions/by-uid/` → `/api/sessions/by-uid/{uid}` 这类
     * 正常关系不会被误判。
     *
     * ⚠ 必须先补回 `Route::group()` 的前缀：本项目的 API 路由全部声明在
     * `Route::group('/api', ...)` 里，正则直接抓 `Route::get('...')` 得到的是
     * **去掉前缀的片段**（`/sessions` 而非 `/api/sessions`），不补前缀则本断言必然全数失配
     * （首跑踩到过）。做法是同时生成「原路径」与「各 group 前缀 + 原路径」两组候选 ——
     * 极少数情况下可能宽松（路由未在该 group 内却靠前缀匹配上），但对「端点字符串是否有对应路由」
     * 这个目的足够，且比扫大括号做作用域分析可靠得多（路由路径里的 `{uid}` 会干扰括号计数）。
     */
    public function testEveryEndpointDeclaredByControllerHasARoute(): void
    {
        $controller = $this->read(self::PAGE_CONTROLLER);
        preg_match_all("/'[a-z_]+_(?:url|base)'\\s*=>\\s*'([^']+)'/", $controller, $m);
        $endpoints = array_values(array_unique($m[1]));

        $this->assertNotEmpty($endpoints, '控制器里没有解析到任何端点，正则八成失配了');

        $routeFile = $this->read(self::ROUTES);
        preg_match_all("#Route::(get|post|put|delete|patch|any)\\(\\s*'([^']+)'#", $routeFile, $r);
        $routes = array_combine($r[2], $r[1]);
        $this->assertNotEmpty($routes, '路由文件里没有解析到任何路由，正则八成失配了');

        preg_match_all("#Route::group\\(\\s*'([^']+)'#", $routeFile, $g);
        $groupPrefixes = array_values(array_unique($g[1]));
        $this->assertNotEmpty($groupPrefixes, '没有解析到 Route::group 前缀，正则八成失配了');

        $missing = [];
        foreach ($endpoints as $endpoint) {
            $matched = null;
            foreach ($routes as $path => $verb) {
                foreach ($groupPrefixes as $prefix) {
                    foreach ([$path, $prefix . $path] as $candidate) {
                        if ($candidate === $endpoint) {
                            $matched = [$path, $verb];

                            break 3;
                        }
                        $tail = str_starts_with($candidate, $endpoint) ? substr($candidate, strlen($endpoint)) : null;
                        if ($tail !== null && preg_match('/^\{[A-Za-z_][A-Za-z0-9_]*\}$/', $tail) === 1) {
                            $matched = [$path, $verb];

                            break 3;
                        }
                    }
                }
            }

            if ($matched === null) {
                $missing[] = $endpoint;

                continue;
            }

            // P4 起本页不再是纯只读：`/api/ops-action/*` 是四个运维端点，必须是 POST。
            // 其余端点仍必须是 GET —— 取数链路上出现写动词即说明有人顺手改了状态。
            $expectedVerb = str_starts_with($endpoint, self::OPS_ENDPOINT_PREFIX) ? 'post' : 'get';
            $this->assertSame(
                $expectedVerb,
                $matched[1],
                $endpoint . ' 命中的路由 ' . $matched[0] . ' 动词不对（运维端点必须 POST，取数端点必须 GET）'
            );
        }

        $this->assertSame(
            [],
            $missing,
            '以下端点在前端被调用，但 config/route.php 里没有对应路由：' . implode('、', $missing)
        );
    }

    /**
     * ★ 两个 Session 控制器都必须禁用默认路由。
     *
     * 否则 `/session/index`、`/api/session/index` 这类**默认路径**会绕过显式路由上的
     * `AdminAuth`（2026-09-23 实测过的鉴权洞：默认路径与显式路径不同时，显式路由压根不参与匹配）。
     */
    public function testBothSessionControllersHaveDefaultRouteDisabled(): void
    {
        $routeFile = $this->read(self::ROUTES);

        $this->assertStringContainsString('Route::disableDefaultRoute(SessionController::class);', $routeFile);
        $this->assertStringContainsString('Route::disableDefaultRoute(SessionApiController::class);', $routeFile);
    }

    /**
     * 页面路由必须挂 `AdminAuth` —— 漏挂即等于免登录可访问整页。
     */
    public function testPageRouteIsGuarded(): void
    {
        $routeFile = $this->read(self::ROUTES);

        $this->assertMatchesRegularExpression(
            "#Route::get\\('/sessions',\\s*\\[SessionController::class,\\s*'index'\\]\\)\\s*->middleware\\(\\[AdminAuth::class\\]\\)#",
            $routeFile,
            '/sessions 页面路由必须挂 AdminAuth 中间件'
        );
    }

    /* =====================================================================
     | ⑤ P4 运维区块
     ===================================================================== */

    /**
     * ★ 运维动作区必须挂在权限后面 —— 没有权限就整个隐藏，且给出说明。
     *
     * 只做服务端鉴权不够：只读角色看到一排点不动（或点了 403）的按钮，
     * 会以为系统坏了。渲染期显隐不是安全边界（真边界是 AdminAuth + wa_rules），
     * 但它是「能不能用」的诚实表达。
     */
    public function testOpsSectionIsGatedByPermsAndExplainsWhenAbsent(): void
    {
        $view = $this->read(self::VIEW);
        $script = $this->stripBlockComments($this->read(self::SCRIPT));

        $this->assertStringContainsString('id="sec-ops"', $view, '运维区块容器缺失');
        $this->assertStringContainsString('id="ops-readonly"', $view, '无权限时必须有无权限说明');

        $this->assertStringContainsString('applyOpsPerms()', $script, '首屏必须做权限显隐');
        $this->assertStringContainsString('cfg.perms', $script, '权限必须来自服务端下发的 perms，不得前端硬编码');

        // 四项权限任一为真才显示操作区；全假时显示无权限说明
        preg_match_all('/\bp\.ops_(kick|revoke|unbind|force)\b/', $script, $m);
        $this->assertSame(
            ['kick', 'revoke', 'unbind', 'force'],
            array_values(array_unique($m[1])),
            '权限判定必须覆盖 kick / revoke / unbind / force 四项，缺一项即漏判'
        );
    }

    /**
     * ★ 明文 Token 不得出现在 URL 里。
     *
     * `revoke` 只接受明文（服务端只存 `sha256` 前 32 位指纹，会话里取不到），
     * 于是它是**本页唯一会被人工粘贴进来的长期凭证**。走 URL 会落进浏览器历史、
     * 访问日志与 Referer；必须走 POST body，输入框必须是 `type="password"`。
     */
    public function testOpsTokenStaysInPostBodyAndIsMasked(): void
    {
        $view = $this->read(self::VIEW);
        $script = $this->stripBlockComments($this->read(self::SCRIPT));

        $this->assertMatchesRegularExpression(
            '/id="ops-token"[^>]*type="password"/',
            $view,
            'Token 输入框必须是 password 型（明文会落在屏幕与历史里）'
        );
        $this->assertStringContainsString('body.token = token', $script, 'Token 只能进 POST body');
        $this->assertDoesNotMatchRegularExpression(
            '/token[^\n]{0,60}\?|encodeURIComponent\([^\n]{0,20}token|[+]\s*[\'"]token=/',
            $script,
            'Token 不得拼进查询串'
        );
    }

    /**
     * ★ 回执不得概括成败 —— `state` / HTTP / 业务码 / `partial` 都要摊开。
     *
     * 运维动作的语义边界很窄：`kick` 不撤 Token、`revoke` 不断连接、`unbind` 不踢线；
     * 「强制下线」没给 Token 时只做到一半（`partial=true`）。任何一种被前端概括成
     * 「操作成功」，运维都会据此做出错误的判断（例如以为对方已经连不上）。
     */
    public function testOpsResultNeverSummarizesOutcome(): void
    {
        $script = $this->stripBlockComments($this->read(self::SCRIPT));

        $this->assertStringContainsString("out.state === 'done'", $script, '必须按 state 判成败，不能只看 HTTP 200');
        foreach (['out.state', 'out.http', 'out.code', 'data.partial'] as $field) {
            $this->assertStringContainsString(
                $field,
                $script,
                '回执必须摊开 ' . $field . ' —— 概括成败会让运维误判动作效果'
            );
        }
        $this->assertStringContainsString('cfg.ops_caveats', $script, '「不做什么」必须原样展示服务端下发的说明');
        $this->assertStringContainsString('data.caveats', $script, '回执里的 caveats 也要展示');
    }

    /* =====================================================================
     | 辅助
     ===================================================================== */

    /**
     * 剥掉 JS 块注释（`/* … *\/`）。
     *
     * 本文件里有若干条「注释里提到的关键词不该算作调用」的断言
     * （`method: 'POST'`、`p.ops_*`）。不剥注释，将来有人在注释里写一句说明就会假阳性。
     *
     * ⚠ 刻意**不**剥 `//` 行注释：JS 字符串里出现 `//`（URL、协议相对路径）的概率不低，
     * 处理它得不偿失。块注释在字符串里出现的概率为零，是安全的那一半。
     */
    private function stripBlockComments(string $js): string
    {
        return (string)preg_replace('#/\*.*?\*/#s', '', $js);
    }

    /**
     * 视图里的全部 DOM id。
     *
     * ⚠ 必须返回**值列表**而非字典 —— 后者会让 `array_diff` 比较「值」（全是 `true`），
     * 结果恒等于脚本侧清单，断言永远失败（P1 已踩过）。
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
        $accessors = implode('|', array_map(
            static fn (string $n): string => preg_quote($n, '/'),
            self::ID_ACCESSORS
        ));
        $quoted = "'([^']+)'";

        $found = [];

        // 形态一：辅助函数第一个入参是 id，如 setText('list-status', ...)
        preg_match_all('/\b(?:' . $accessors . ')\s*\(\s*' . $quoted . '/', $script, $m1);
        $found = array_merge($found, $m1[1]);

        // 形态二：$('id') —— 取 DOM 的原语
        preg_match_all('/\$\s*\(\s*' . $quoted . '\s*\)/', $script, $m2);
        $found = array_merge($found, $m2[1]);

        // 形态三：document.getElementById('id') —— 取 JSON 配置块用的就是它
        preg_match_all('/getElementById\s*\(\s*' . $quoted . '\s*\)/', $script, $m3);
        $found = array_merge($found, $m3[1]);

        // 只保留看着像 DOM id 的字面量：排除 URL / 选择器 / 类名之类
        $found = array_filter($found, static fn (string $id): bool => $id !== ''
                && !str_contains($id, '/')
                && !str_contains($id, '#')
                && !str_contains($id, '.')
                && !str_contains($id, ':')
                && preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $id) === 1);

        return array_values(array_unique($found));
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
