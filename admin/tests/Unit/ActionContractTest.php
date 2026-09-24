<?php
/**
 * admin 单测 —— ActionContractTest。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace tests\Unit;

use app\controller\ActionController;
use app\controller\api\ActionController as ActionApiController;
use app\service\ActionCatalog;
use app\service\ActionOutcome;
use PHPUnit\Framework\TestCase;

/**
 * P3 动作调试页「视图 ↔ 前端脚本 ↔ 路由 ↔ 定时器政策 ↔ RBAC」契约测试
 * （**纯静态 + 纯常量，不依赖任何服务**）。
 *
 * 与 `PushContractTest` 同一动机，但**定时器政策相反**，故本文件最重要的两组断言是：
 *
 * ① `setTimeout` **必须有且只有一个**，且延迟序列来自后端（`cfg.poll_delays_ms`）；
 *    同时 `setInterval` / `setImmediate` / `requestAnimationFrame` 一个都不许有。
 *    「有且只有一个」这条看似苛刻，但它正是「有界退避」与「周期轮询」的分界线：
 *    `/actions` 要等的是**某一条**动作的回执，取到即停；用周期轮询就等于
 *    「无论有没有结果都持续打生产环境的主项目 API」。
 *
 * ② 退避**总时长必须小于回执保留窗口**（`ActionCatalog::RESULT_TTL_MIRROR`）。
 *    否则最后几次补查注定落在窗口外，只会在界面上稳定产出「已超出补查窗口」的噪声 ——
 *    一个「功能都在、就是没用」的隐蔽缺陷。
 *
 * 另外三组：路由动词、写路径不碰 Redis、`/actions` 页面**仅运维角色**可见。
 */
final class ActionContractTest extends TestCase
{
    private const VIEW = 'app/view/action/index.html';

    private const SCRIPT = 'public/static/action.js';

    private const STYLE = 'public/static/action.css';

    private const PAGE_CONTROLLER = 'app/controller/ActionController.php';

    private const API_CONTROLLER = 'app/controller/api/ActionController.php';

    private const ROUTES = 'config/route.php';

    private const INSTALL = 'scripts/install.php';

    /**
     * 写路径上的 PHP 文件 —— 不得出现任何 Redis 写命令，也不得引用 Redis 门面。
     *
     * `invoke` 会在真实客户端上执行动作，属于「改变推送系统状态」，必须走主项目
     * 已签名的 HTTP API（读走 Redis，写走 HTTP）。
     */
    private const WRITE_PATH_FILES = [
        self::API_CONTROLLER,
        'app/service/ActionCatalog.php',
        'app/service/ActionOutcome.php',
    ];

    /**
     * Redis 写命令（越权直改推送系统状态的典型信号）。
     *
     * ⚠ 刻意不含读命令：本页的读取走 `GatewayPushClient`（HTTP），不碰 Redis，
     * 但清单过宽会让「构造器/门面方法名」误判。Redis 门面由
     * {@see FORBIDDEN_REDIS_SYMBOLS} 单独钉。
     *
     * @var list<string>
     */
    private const REDIS_WRITE_COMMANDS = [
        'del', 'unlink', 'set', 'setEx', 'pSetEx', 'setNx', 'mSet', 'mSetNx',
        'hSet', 'hMSet', 'hDel', 'hIncrBy', 'hIncrByFloat',
        'sAdd', 'sRem', 'sMove', 'sPop',
        'lPush', 'rPush', 'lPop', 'rPop', 'lTrim', 'lRem', 'lSet', 'lInsert',
        'zAdd', 'zRem', 'zIncrBy',
        'expire', 'expireAt', 'pExpire', 'pExpireAt', 'persist',
        'incr', 'decr', 'incrBy', 'decrBy', 'incrByFloat',
        'flushDb', 'flushAll', 'publish',
    ];

    /** @var list<string> */
    private const FORBIDDEN_REDIS_SYMBOLS = ['RedisReader', 'RedisKeys', 'new Redis(', 'Redis::'];

    /** @var list<string> */
    private const ID_ACCESSORS = [
        'setText', 'setRowEmpty', 'setNote',
        'hide', 'show', 'val', 'setVal', 'renderNotes', 'bind', 'bindInput',
    ];

    /** @var list<string> */
    private const INTERNAL_DELEGATION_URL_EXPRS = ['url'];

    /* =====================================================================
     | ① DOM id 与配置键
     ===================================================================== */

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
     * ★ `renderOutcome()` 用三元表达式选 DOM 目标（因为要服务 invoke / lookup 两处）。
     *
     * 这类「用字符串变量选节点」的写法会逃过 `setText('id', ...)` 那类静态检查，
     * 而写错一个 id 的表现就是**回执整个不显示**（`$(bodyId)` 返回 null，函数静默 return）。
     * 故单独把它抓出来对视图断言。
     *
     * ⚠ 正则要求字面量**含连字符**（`[a-z][a-z0-9]*(?:-[a-z0-9]+)+`）：
     *   脚本里还有 `state === 'pending' ? 'warn' : 'info'` 这类**选 tone** 的三元表达式，
     *   不排除它们会得到 `warn` / `info` 两个假 id（已踩过，报错信息完全指向错误方向）。
     *
     * 但「含连字符」这条也意味着：若有人把目标 id 改成驼峰（`tbInvokeResult`），
     * 本用例会**静默失去覆盖**。故另有 {@see testOutcomeTargetIdsAreExplicitlyCovered()}
     * 用显式清单兜底。
     */
    public function testOutcomeScopeTargetsExistInView(): void
    {
        $script = $this->read(self::SCRIPT);
        preg_match_all(
            "/\\?\\s*'([a-z][a-z0-9]*(?:-[a-z0-9]+)+)'\\s*:\\s*'([a-z][a-z0-9]*(?:-[a-z0-9]+)+)'/",
            $script,
            $m
        );
        $targets = array_values(array_unique(array_merge($m[1], $m[2])));

        $this->assertNotEmpty($targets, '没有解析到任何三元表达式形式的 DOM id，正则八成失配了');

        $viewIds = $this->viewIds();
        foreach ($targets as $id) {
            $this->assertContains(
                $id,
                $viewIds,
                'renderOutcome 可能写入不存在的 id：' . $id . ' —— 表现为「回执区整个不显示」'
            );
        }
    }

    /**
     * 四个回执目标 id 的**显式**清单 —— 兜住上一条用例「正则要求含连字符」的覆盖盲区。
     *
     * 这里是故意写死的字面量：改成驼峰命名会让上一条用例静默失效，
     * 而这一条会直接报红，提示维护者同步更新断言。
     */
    public function testOutcomeTargetIdsAreExplicitlyCovered(): void
    {
        $script = $this->read(self::SCRIPT);
        $viewIds = $this->viewIds();

        foreach (['invoke-result-wrap', 'tb-invoke-result', 'lookup-result-wrap', 'tb-lookup-result'] as $id) {
            $this->assertStringContainsString(
                "'" . $id . "'",
                $script,
                '脚本里找不到回执目标 id ' . $id . ' —— 若已重命名，请同步更新本用例'
            );
            $this->assertContains(
                $id,
                $viewIds,
                '视图里缺少回执目标节点 ' . $id . ' —— 回执区会整个不显示'
            );
        }
    }

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

        $this->assertStringContainsString('id="action-config"', $view);
        $this->assertStringContainsString('application/json', $view);
        $this->assertStringContainsString('JSON_HEX_TAG', $view, '注入 JSON 必须开启 JSON_HEX_TAG');
    }

    public function testViewMountsStaticAssets(): void
    {
        $view = $this->read(self::VIEW);

        $this->assertStringContainsString('/static/action.js', $view);
        $this->assertStringContainsString('/static/action.css', $view);
        $this->assertNotFalse(@file_get_contents($this->path(self::STYLE)), '样式文件缺失：' . self::STYLE);
    }

    /* =====================================================================
     | ② 定时器政策：有界退避，不是周期轮询
     ===================================================================== */

    /**
     * ★ `setTimeout` 有且只有一个，且 `setInterval` 族一个都没有。
     *
     * 「只有一个」是本页与 `/dashboard` 的根本分界：`setTimeout` 递归退避在
     * 「取到终态」或「次数用尽」时必然停止；而一旦有人在旁边再加一个 `setTimeout`
     * 轮询，这个不变量就被破坏 —— 而功能上看不出任何差别，只会持续打主项目 API。
     */
    public function testScriptUsesExactlyOneBoundedTimeout(): void
    {
        $script = $this->read(self::SCRIPT);

        $this->assertDoesNotMatchRegularExpression(
            '/\b(setInterval|setImmediate|requestAnimationFrame)\s*\(/',
            $script,
            '本页只允许有界退避：出现周期定时器/帧回调就变成了轮询，会持续打生产环境的主项目 API'
        );

        $this->assertSame(
            1,
            substr_count($script, 'window.setTimeout('),
            '本页必须恰好有一处 `window.setTimeout(`（有界退避的那一处）。'
            . '新增第二处意味着多了一条无人收敛的定时链'
        );
        $this->assertStringContainsString(
            'cfg.poll_delays_ms',
            $script,
            '退避延迟序列必须来自后端（cfg.poll_delays_ms），前端不得自行编数'
        );
    }

    /**
     * ★ 有界退避必须**真的能停**：要有会话号作废机制与次数上限。
     *
     * 两处文本锚点是「可停止性」的证据：
     *   - `stopPoll` —— 新调用 / 手动补查会作废旧链；
     *   - `poll.attempt >= delays.length` —— 次数用尽即停，不会无限递归。
     */
    public function testScriptCanActuallyStopTheBackoff(): void
    {
        $script = $this->read(self::SCRIPT);

        $this->assertStringContainsString('function stopPoll(', $script, '必须有作废补查链的入口');
        $this->assertStringContainsString(
            'poll.attempt >= delays.length',
            $script,
            '必须有「次数用尽即停」的判据，否则退避会无限递归'
        );
        $this->assertStringContainsString('poll.seq', $script, '必须用会话号作废旧链的回调');
    }

    /**
     * ★ 退避总时长必须**小于**回执保留窗口，且留有余量。
     *
     * 服务端在 `ACTION_RESULT_TTL`（默认 60s）后就回收回执，此后补查必然返回
     * `expired`（且该状态**两义**，服务端不区分「仍在执行」与「已回收」）。
     * 退避排到窗口之外，等于把「已超出补查窗口」这个结论重复地说好几遍。
     */
    public function testBackoffWindowFitsInsideResultTtl(): void
    {
        $total = array_sum(ActionController::POLL_DELAYS_MS);
        $ttlMs = ActionCatalog::RESULT_TTL_MIRROR * 1000;

        $this->assertGreaterThan(0, $total, '退避序列不能为空');
        $this->assertLessThan(
            $ttlMs,
            $total,
            '退避总时长 ' . $total . 'ms 不小于回执保留窗口 ' . $ttlMs . 'ms —— '
            . '最后几次补查注定落在窗口外，界面会稳定产出「已超出补查窗口」的噪声'
        );
        // 留出余量：窗口刚过就耗尽次数，说明序列排得太满
        $this->assertLessThanOrEqual(
            (int)($ttlMs * 0.8),
            $total,
            '退避总时长 ' . $total . 'ms 过于贴近窗口 ' . $ttlMs . 'ms，应留出余量'
        );
    }

    public function testScriptAvoidsInnerHtml(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/\.innerHTML\s*=/',
            $this->read(self::SCRIPT),
            '前端脚本不得使用 innerHTML：result 里可能含任意业务数据，必须经 textContent 落地'
        );
    }

    /* =====================================================================
     | ③ 动作白名单与 request_id 口径
     | ===================================================================== */

    /**
     * ★ `session` 不得出现在页面清单里，且六个 HTTP 动作必须都在。
     *
     * `session` 的语义锚点是「当前连接」，在 HTTP 通道下不成立（它的默认 clientId 是
     * `http:{request_id}`），故主项目声明为 `http=false`。列进 UI 就是给出一个
     * **必然失败**的选项。
     */
    public function testActionListMirrorsHttpExposedActionsOnly(): void
    {
        $names = ActionCatalog::names();

        $this->assertNotContains('session', $names, 'session 是 http=false，不得出现在调试页清单里');
        foreach (ActionCatalog::NOT_HTTP_EXPOSED as $hidden) {
            $this->assertNotContains($hidden, $names, '刻意不列入的动作出现在了清单里：' . $hidden);
        }

        $controller = $this->read(self::PAGE_CONTROLLER);
        $this->assertStringContainsString(
            'ActionCatalog::forUi()',
            $controller,
            '动作清单必须来自 ActionCatalog，不得在控制器里另写一份'
        );
    }

    /**
     * ★ `request_id` 的合法形态必须由后端下发，前端不得复刻正则。
     *
     * 前端硬编码的下场：服务端把 `{1,64}` 放宽成 `{1,128}` 后，前端会把合法 id 判成非法，
     * 让「补查」这个纯读操作无谓失败（而服务端日志里什么都看不到）。
     */
    public function testRequestIdPatternIsSingleSourced(): void
    {
        $script = $this->read(self::SCRIPT);

        $this->assertStringContainsString(
            'cfg.request_id_pattern',
            $script,
            'request_id 的校验必须取自 cfg.request_id_pattern'
        );
        $this->assertStringContainsString(
            'new RegExp(',
            $script,
            '需要把后端下发的正则字面量剥离定界符后再构造 RegExp（直接整串喂进去会恒不匹配）'
        );
    }

    /**
     * 六种状态都必须有后端下发的解释文案，且前端按 `state` 取值。
     *
     * 前端若自行复刻一份「state → 文案」，后端改文案时前端不会跟随 —— 界面会撒谎。
     */
    public function testOutcomeNotesCoverAllStatesAndComeFromBackend(): void
    {
        $controller = $this->read(self::PAGE_CONTROLLER);

        $this->assertStringContainsString(
            'ActionOutcome::NOTES',
            $controller,
            '状态解释文案必须来自 ActionOutcome::NOTES'
        );

        $states = [
            ActionOutcome::STATE_DONE,
            ActionOutcome::STATE_FAILED,
            ActionOutcome::STATE_PENDING,
            ActionOutcome::STATE_REJECTED,
            ActionOutcome::STATE_EXPIRED,
            ActionOutcome::STATE_TRANSPORT,
        ];
        foreach ($states as $state) {
            $this->assertArrayHasKey(
                $state,
                ActionOutcome::NOTES,
                '状态 ' . $state . ' 没有解释文案 —— 界面上会出现一个没有说明的状态标签'
            );
        }

        $this->assertStringContainsString(
            'cfg.outcome_notes',
            $this->read(self::SCRIPT),
            '前端应按 state 从 cfg.outcome_notes 取文案（作 data.note 的兜底）'
        );
    }

    /**
     * `uid` 必填的判据必须来自后端的 `requires_uid`，而不是前端写死「uid 必填」。
     *
     * P4 会加入 `auth=false` 的运维动作（kick / revoke / unbind）。前端若写死必填，
     * 那些动作上线后会被前端挡住提交 —— 而服务端本来是接受的。
     */
    public function testUidRequirementComesFromBackendMeta(): void
    {
        $section = $this->read(self::SCRIPT);

        $this->assertStringContainsString(
            'requires_uid',
            $section,
            'uid 是否必填必须读后端下发的 requires_uid，不得前端写死'
        );
    }

    /**
     * ★ 「动作能不能再发一次」的判定必须来自后端，且**不得**由前端用 `retryable` 代答。
     *
     * 这是 2026-09-23 那处界面撒谎的静态护栏：`retryable` 回答的是「补查能不能重试」，
     * 与「重发是否安全」在 `rejected` 上**正好相反**。运行时那三条对立断言
     * （`action_render_check.js` A04/A06/A07）管行为，本用例管「代码里根本不许出现它」。
     *
     * ⚠ 去注释后再断言：`retryable` 在注释里作为「不要这么做」的反例被提及是**应该的**。
     */
    public function testResendVerdictComesFromBackendNotFromRetryable(): void
    {
        $bare = $this->stripJsComments($this->read(self::SCRIPT));

        foreach (['data.resend', 'data.resend_label', 'data.resend_tone', 'data.resend_note'] as $key) {
            $this->assertStringContainsString(
                $key,
                $bare,
                '重发判定的 ' . $key . ' 必须由后端下发 —— 前端自行编词必然与后端漂移'
            );
        }

        $this->assertStringNotContainsString(
            'retryable',
            $bare,
            '★ 脚本不得读取 retryable 来渲染重发判定 —— 那会把「未入队」这个最该重发的状态'
            . '显示成「不要重发」，同时把「已完成」显示成「重发安全」'
        );

        foreach (array_keys(ActionOutcome::RESEND) as $state) {
            $this->assertArrayHasKey(
                $state,
                ActionOutcome::RESEND_NOTES,
                '状态 ' . $state . ' 没有重发解释文案 —— 回执里会出现一个没有理由的「不要重发」'
            );
        }
    }

    /* =====================================================================
     | ④ 路由覆盖与写路径边界
     | ===================================================================== */

    /**
     * ★ `invoke` 必须发 POST，且 URL 来自 cfg；本页**不得有 DELETE**。
     *
     * 动词改错的后果很隐蔽：`invoke` 变成 GET 后路由仍在（只是匹配到别的方法），
     * 页面表现是一个看不懂的 405/404，而契约上看不出任何问题。
     */
    public function testScriptUsesExpectedVerbsAndConfigUrls(): void
    {
        $script = $this->read(self::SCRIPT);
        preg_match_all("/requestJson\\(\\s*(.*?)\\s*,\\s*'([A-Z]+)'/", $script, $calls, PREG_SET_ORDER);

        $this->assertNotEmpty($calls, '没有解析到任何 requestJson 调用，正则八成失配了');

        $seen = [];
        foreach ($calls as $call) {
            $urlExpr = trim($call[1]);
            $verb = $call[2];

            $this->assertContains($verb, ['POST', 'GET'], 'requestJson 出现了未预期的动词：' . $verb);

            if (in_array($urlExpr, self::INTERNAL_DELEGATION_URL_EXPRS, true)) {
                continue;
            }

            $this->assertStringStartsWith(
                'cfg.',
                $urlExpr,
                '端点 URL 必须来自 cfg（后端下发），不得硬编码：' . $urlExpr
            );
            $seen[$verb] = true;
        }

        $this->assertArrayHasKey(
            'POST',
            $seen,
            '脚本里没有出现任何 POST —— 动作调用必须是 POST，被改成 GET 就变成了读操作'
        );
        $this->assertArrayNotHasKey('DELETE', $seen, '动作调试页不得有任何 DELETE 请求');
    }

    /**
     * `invoke` 必须是 `POST`，`result` 必须是 `GET` 且带 `{requestId}` 路径参数。
     *
     * ⚠ 特别钉住 `result` 是 GET：它必须**纯读**。若有人把它改成 POST，
     * 「补查」就会变成一个可被 CSRF 面利用、且语义上是写操作的端点。
     */
    public function testApiRoutesHaveExpectedVerbs(): void
    {
        $routeFile = $this->read(self::ROUTES);

        $this->assertMatchesRegularExpression(
            "#Route::post\\('/action',\\s*\\[ActionApiController::class,\\s*'invoke'\\]\\)#",
            $routeFile,
            'POST /api/action 必须指向 ActionApiController::invoke'
        );
        $this->assertMatchesRegularExpression(
            "#Route::get\\('/action/\\{requestId\\}',\\s*\\[ActionApiController::class,\\s*'result'\\]\\)#",
            $routeFile,
            'GET /api/action/{requestId} 必须指向 ActionApiController::result —— '
            . '补查是纯读，动词改成 POST 会让它变成写端点'
        );
    }

    public function testBothActionControllersHaveDefaultRouteDisabled(): void
    {
        $routeFile = $this->read(self::ROUTES);

        $this->assertStringContainsString('Route::disableDefaultRoute(ActionController::class);', $routeFile);
        $this->assertStringContainsString('Route::disableDefaultRoute(ActionApiController::class);', $routeFile);
    }

    public function testPageRouteIsGuarded(): void
    {
        $this->assertMatchesRegularExpression(
            "#Route::get\\('/actions',\\s*\\[ActionController::class,\\s*'index'\\]\\)\\s*->middleware\\(\\[AdminAuth::class\\]\\)#",
            $this->read(self::ROUTES),
            '/actions 页面路由必须挂 AdminAuth 中间件'
        );
    }

    public function testWritePathNeverTouchesRedis(): void
    {
        $pattern = '/(?:->|::)(' . implode('|', self::REDIS_WRITE_COMMANDS) . ')\s*\(/i';

        foreach (self::WRITE_PATH_FILES as $file) {
            $stripped = php_strip_whitespace($this->path($file));
            $this->assertNotSame('', $stripped, '读不到无注释源码：' . $file);

            preg_match_all($pattern, $stripped, $matches);
            $this->assertSame(
                [],
                array_values(array_unique($matches[1])),
                $file . ' 出现了 Redis 写命令 —— 动作调用必须走主项目 HTTP API，不得直连 Redis'
            );

            foreach (self::FORBIDDEN_REDIS_SYMBOLS as $symbol) {
                $this->assertStringNotContainsString(
                    $symbol,
                    $stripped,
                    $file . ' 引用了 ' . $symbol . ' —— 动作调试与 Redis 无关'
                );
            }
        }
    }

    /* =====================================================================
     | ⑤ 权限
     ===================================================================== */

    public function testPermissionAliasesPointAtRealControllerActions(): void
    {
        $controller = $this->read(self::PAGE_CONTROLLER);

        $this->assertMatchesRegularExpression(
            '/\'perms\'\s*=>\s*Perm::map\(\s*\[(.*?)\]\s*\)/s',
            $controller,
            '没有解析到 perms 映射，正则八成失配了'
        );
        preg_match('/\'perms\'\s*=>\s*Perm::map\(\s*\[(.*?)\]\s*\)/s', $controller, $block);
        $inner = $block[1];

        preg_match_all("/'([a-z_]+)'\\s*=>\\s*\\[/", $inner, $allEntries);
        preg_match_all(
            "/'([a-z_]+)'\\s*=>\\s*\\[\\s*([A-Za-z_][A-Za-z0-9_]*)::class\\s*,\\s*'([A-Za-z_][A-Za-z0-9_]*)'\\s*\\]/",
            $inner,
            $pairs,
            PREG_SET_ORDER
        );
        $this->assertNotEmpty($pairs, 'perms 映射里没有解析到任何条目，正则八成失配了');
        $this->assertCount(
            count($allEntries[1]),
            $pairs,
            'perms 映射里存在非 `X::class` 形式的条目 —— 控制器名必须用类常量引用'
        );

        $class = new \ReflectionClass(ActionApiController::class);
        foreach ($pairs as $pair) {
            $this->assertSame(
                'ActionApiController',
                $pair[2],
                '权限别名 ' . $pair[1] . ' 必须引用 api 控制器，当前是 ' . $pair[2]
            );
            $this->assertTrue(
                $class->hasMethod($pair[3]),
                '权限别名 ' . $pair[1] . ' 指向的动作 ' . $pair[3] . '() 不存在'
            );
        }
    }

    /**
     * ★ `/actions` 页面与两个动作端点**只属于运维角色**，只读角色一个都拿不到。
     *
     * 理由：本页能在任意 uid 的在线连接上执行动作（echo / notify / report…），
     * 属「主动对生产连接施加行为」。把它连同只读查询一起授予，等于
     * 给报表读者发了生产操作权 —— 而且不会有任何报错，只是菜单多了一项。
     */
    public function testActionDebuggerIsOperatorOnly(): void
    {
        $install = $this->read(self::INSTALL);

        $this->assertMatchesRegularExpression('/\$viewerRules\s*=\s*\[(.*?)\];/s', $install, '没有解析到 $viewerRules');
        preg_match('/\$viewerRules\s*=\s*\[(.*?)\];/s', $install, $viewer);

        foreach (['actions', 'action.invoke', 'action.result'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $viewer[1],
                '「只读」角色被授予了 ' . $forbidden . ' —— 动作调试属运维职责，只读角色不应可见'
            );
        }

        $this->assertMatchesRegularExpression(
            '/\$operatorRules\s*=\s*array_merge\(\s*\$viewerRules\s*,\s*\[(.*?)\]\s*\);/s',
            $install,
            '没有解析到 $operatorRules'
        );
        preg_match('/\$operatorRules\s*=\s*array_merge\(\s*\$viewerRules\s*,\s*\[(.*?)\]\s*\);/s', $install, $operator);
        foreach (['actions', 'action.invoke', 'action.result'] as $required) {
            $this->assertStringContainsString(
                $required,
                $operator[1],
                '「运维」角色缺少 ' . $required . ' —— 该角色将无法使用动作调试'
            );
        }
    }

    /**
     * 页面必须提供「无权限」的说明块 —— 只读角色打不开这一页（403），
     * 而能打开却看不到任何区块的情形（权限被收窄）需要一句话解释，否则会被当成页面坏了。
     */
    public function testViewProvidesReadOnlyExplanation(): void
    {
        $view = $this->read(self::VIEW);

        $this->assertStringContainsString('id="action-readonly"', $view);
        $this->assertStringContainsString('id="action-notes"', $view);
        $this->assertStringContainsString('id="sec-invoke"', $view);
        $this->assertStringContainsString('id="sec-lookup"', $view);
    }

    /* =====================================================================
     | 辅助
     ===================================================================== */

    /** @return list<string> */
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

        preg_match_all('/\b(?:' . $accessors . ')\s*\(\s*' . $quoted . '/', $script, $m1);
        $found = array_merge($found, $m1[1]);

        preg_match_all('/\$\s*\(\s*' . $quoted . '\s*\)/', $script, $m2);
        $found = array_merge($found, $m2[1]);

        preg_match_all('/getElementById\s*\(\s*' . $quoted . '\s*\)/', $script, $m3);
        $found = array_merge($found, $m3[1]);

        // 三元表达式选节点（renderOutcome 的两组目标）—— 见 testOutcomeScopeTargetsExistInView。
        // ⚠ 要求含连字符，否则会把 `? 'warn' : 'info'` 这类**选 tone** 的表达式当成 id。
        preg_match_all(
            "/\\?\\s*'([a-z][a-z0-9]*(?:-[a-z0-9]+)+)'\\s*:\\s*'([a-z][a-z0-9]*(?:-[a-z0-9]+)+)'/",
            $script,
            $m4
        );
        $found = array_merge($found, $m4[1], $m4[2]);

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

    /**
     * 剥掉 JS 的块注释与行注释。
     *
     * 用途：断言「代码里不得出现 X」时，**注释里**出现 X 应当不算违规 ——
     * 本项目刻意在注释里写出反例（如「不得用 retryable 判重发」），
     * 不剥注释会让这条护栏与它自己的说明文字互相打架。
     *
     * 不追求完整的词法分析：本项目的 JS 里没有正则字面量含 `//` 或 `/*`，
     * 这个粗糙实现足够；若将来出现了，应当在**注释**里说明而不是升级正则。
     */
    private function stripJsComments(string $js): string
    {
        $withoutBlock = (string)preg_replace('#/\*[\s\S]*?\*/#', '', $js);

        return (string)preg_replace('#^[ \t]*//.*$#m', '', $withoutBlock);
    }
}
