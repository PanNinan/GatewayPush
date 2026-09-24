<?php
/**
 * admin 单测 —— PushContractTest。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace tests\Unit;

use app\controller\api\PushController as PushApiController;
use app\controller\PushController;
use app\service\Pusher;
use app\service\PushRepository;
use PHPUnit\Framework\TestCase;

/**
 * P3 推送管理页「视图 ↔ 前端脚本 ↔ 路由 ↔ 写路径边界 ↔ RBAC」契约测试
 * （**纯静态 + 纯常量，不依赖任何服务**）。
 *
 * 与 `SessionContractTest` 同一动机：页面是「骨架 + 按需取数」，
 * 服务端不知道 JS 是否把它渲染对了 —— id 拼错、cfg 键漏注入，都只会**静默留白**。
 *
 * 但本页与 `/sessions` 有**三条本质差别**，故本文件比它多出三组断言：
 *
 * 1. **本页有写操作** ⇒ 必须钉住「写请求只走声明过的端点、动词正确、URL 由后端下发」；
 * 2. **本页的写路径不碰 Redis**（读走 Redis，写走主项目 HTTP API）⇒ 必须钉住
 *    写路径文件里没有 Redis 写命令、也没有 `RedisReader`；
 * 3. **本页的权限是逐节点分开的** ⇒ 必须钉住「只读角色拿不到 push.create / tplSave / tplDelete」，
 *    否则「给某人看历史」会连带给出「以他的名义向任意 uid 推任意载荷」的能力。
 *
 * 断言全部**锁结构、不对排版有观点**（只判断「有没有」，不判断位置/顺序），
 * 因此不会被 `composer cs` 之类的排版改动打散。
 */
final class PushContractTest extends TestCase
{
    private const VIEW = 'app/view/push/index.html';

    private const SCRIPT = 'public/static/push.js';

    private const STYLE = 'public/static/push.css';

    private const PAGE_CONTROLLER = 'app/controller/PushController.php';

    private const API_CONTROLLER = 'app/controller/api/PushController.php';

    private const ROUTES = 'config/route.php';

    private const INSTALL = 'scripts/install.php';

    /**
     * 写路径上的 PHP 文件 —— 它们**不得**出现任何 Redis 命令。
     *
     * 项目原则是「读走 Redis，写走 HTTP」：后台改变推送系统状态必须经主项目已签名的
     * HTTP API，否则绕过主项目的校验、限流与指标。这三个文件是「写」的落点。
     */
    private const WRITE_PATH_FILES = [
        self::API_CONTROLLER,
        'app/service/PushRepository.php',
        'app/service/TemplateRepository.php',
    ];

    /**
     * Redis **写**命令清单（大小写不敏感，只匹配 `->cmd(` 与 `::cmd(` 形态）。
     *
     * ⚠ 刻意**不含**读命令（`get` / `exists` / `hGetAll` …）：这三个文件用的是
     * Illuminate 的查询构造器，它的 `->get()` 会被读命令清单误判。
     * 「这三个文件与 Redis 完全无关」这层意思由下面的 `RedisReader` / `RedisKeys`
     * / `new Redis(` 三条显式引用断言表达，比堆一张命令表更精确。
     *
     * 用 `php_strip_whitespace()` 去掉注释后再匹配（注释里会大量提到 Redis 语义）。
     *
     * ⚠ 新增 Redis 写命令时同步补进本表；漏了不会报错，只会让这条断言悄悄失效。
     */
    private const REDIS_WRITE_COMMANDS = [
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
     * 写路径上**必须不出现**的 Redis 门面符号。
     *
     * 比命令表更精确：出现 `RedisKeys::` 或 `RedisReader::` 就已经说明这个文件
     * 把手伸进了键空间，无论它调的是读还是写。
     *
     * @var list<string>
     */
    private const FORBIDDEN_REDIS_SYMBOLS = ['RedisReader', 'RedisKeys', 'new Redis(', 'Redis::'];

    /**
     * 端点 → 允许的 HTTP 动词。
     *
     * ⚠ 必须显式列出而不是「从 route.php 反推」：后者只能证明「有个路由」，
     * 无法证明「动词没被改错」。典型回归是把 `POST /api/push` 改成 GET
     * —— 路由仍在，但写操作变成了读，页面表现为一个看不懂的 405/404。
     *
     * `templates_url` 有两个动词（GET 列表 / POST 保存）是刻意的。
     *
     * @var array<string, list<string>>
     */
    private const ENDPOINT_VERBS = [
        'create_url' => ['post'],
        'history_url' => ['get'],
        'templates_url' => ['get', 'post'],
        'template_delete_base' => ['delete'],
    ];

    /**
     * JS 里按 id 取 DOM 的辅助函数名（**第一个入参是 id**）。
     *
     * ⚠ 在 `push.js` 里新增取 id 的辅助函数时必须同步加进本表 ——
     * 否则新函数引用的 id 会逃过契约检查（正是本测试要防的那类漏网）。
     *
     * @var list<string>
     */
    private const ID_ACCESSORS = [
        'setText', 'setRowEmpty', 'setNote', 'setChips',
        'hide', 'show', 'val', 'setVal', 'renderNotes', 'fillSelect', 'bind', 'bindInput',
    ];

    /**
     * 允许「第一个入参不是 cfg 表达式」的 `requestJson` 调用 —— **只有内部委托一处**。
     *
     * `getJson(url)` 是 `requestJson(url, 'GET', null)` 的薄封装，它的形参名就叫 `url`。
     * 这是本脚本内部唯一的例外；其余任何非 `cfg.` 的 URL 表达式都判为硬编码。
     *
     * @var list<string>
     */
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

        $this->assertStringContainsString('id="push-config"', $view);
        $this->assertStringContainsString('application/json', $view);
        // 配置里若含 </script 会提前闭合标签 —— JSON_HEX_TAG 必须开着
        $this->assertStringContainsString('JSON_HEX_TAG', $view, '注入 JSON 必须开启 JSON_HEX_TAG');
    }

    public function testViewMountsStaticAssets(): void
    {
        $view = $this->read(self::VIEW);

        $this->assertStringContainsString('/static/push.js', $view);
        $this->assertStringContainsString('/static/push.css', $view);
        $this->assertNotFalse(@file_get_contents($this->path(self::STYLE)), '样式文件缺失：' . self::STYLE);
    }

    /* =====================================================================
     | ② 结构性不变量
     ===================================================================== */

    /**
     * ★ 本页**不得有任何定时器**。
     *
     * 推送历史是检索型视图，静止时无需刷新。整族入口一起禁掉 ——
     * 只禁 `setInterval` 的话，换 `setTimeout` 递归轮询就绕过去了。
     * （需要等待的只有 `/actions` 的补查，那里允许 `setTimeout` 递归退避，另页另测。）
     */
    public function testScriptHasNoTimers(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/\b(setTimeout|setInterval|setImmediate|requestAnimationFrame)\s*\(/',
            $this->read(self::SCRIPT),
            '推送管理页必须按需取数：出现任何定时器/帧回调都会把页面变回轮询模型'
        );
    }

    public function testScriptAvoidsInnerHtml(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/\.innerHTML\s*=/',
            $this->read(self::SCRIPT),
            '前端脚本不得使用 innerHTML：服务端返回的目标值/载荷原文/备注必须经 textContent 落地'
        );
    }

    /* =====================================================================
     | ③ 写路径：动词、URL 来源、Redis 边界
     ===================================================================== */

    /**
     * ★ 写请求只允许 `POST` / `DELETE`，且 URL **必须来自 cfg**（不得硬编码）。
     *
     * 硬编码 URL 的后果是「改路由忘了改 JS」→ 请求打到 404，
     * 而契约测试看不出来（因为路由与端点各自都存在，只是没接上）。
     *
     * 同时要求 POST 与 DELETE **都真实出现过** —— 否则「把所有写请求删掉」
     * 会让本用例以「没有违规」通过，而那正是最需要被拦住的改动。
     */
    public function testScriptWriteRequestsUseDeclaredVerbsAndConfigUrls(): void
    {
        $script = $this->read(self::SCRIPT);
        // `.*?` 而不是 `[^,]+?`：DELETE 那条的 URL 表达式内部含逗号（`... + num(t.id)`），
        // 用排除逗号的写法会整条漏掉，于是「动词被改错」这类回归反而抓不到。
        preg_match_all(
            "/requestJson\\(\\s*(.*?)\\s*,\\s*'([A-Z]+)'/",
            $script,
            $calls,
            PREG_SET_ORDER
        );

        $this->assertNotEmpty($calls, '没有解析到任何 requestJson 调用，正则八成失配了');

        $seenVerbs = [];
        foreach ($calls as $call) {
            $urlExpr = trim($call[1]);
            $verb = $call[2];

            $this->assertContains(
                $verb,
                ['POST', 'DELETE', 'GET'],
                'requestJson 出现了未预期的动词：' . $verb
            );

            if (in_array($urlExpr, self::INTERNAL_DELEGATION_URL_EXPRS, true)) {
                continue;
            }

            $this->assertStringStartsWith(
                'cfg.',
                $urlExpr,
                '写端点的 URL 必须来自 cfg（后端下发），不得硬编码：' . $urlExpr
            );
            $seenVerbs[$verb] = true;
        }

        foreach (['POST', 'DELETE'] as $verb) {
            $this->assertArrayHasKey(
                $verb,
                $seenVerbs,
                '脚本里没有出现任何 ' . $verb . ' 写请求 —— 写路径被整体删掉了？本用例会因此失去覆盖'
            );
        }
    }

    /**
     * ★ 写路径上**不得出现任何 Redis 命令**，也不得引用 `RedisReader` / `RedisKeys`。
     *
     * 这是「读走 Redis，写走 HTTP API」这条架构原则在代码层的落点。
     * 一旦有人图省事在后台直接写 `push:offline:*`，就绕过了主项目的
     * 校验、限流与指标 —— 且故障时无从从主项目侧排查。
     */
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
                $file . ' 出现了 Redis 写命令 —— 写路径必须走主项目 HTTP API，不得直连 Redis'
            );

            foreach (self::FORBIDDEN_REDIS_SYMBOLS as $symbol) {
                $this->assertStringNotContainsString(
                    $symbol,
                    $stripped,
                    $file . ' 引用了 ' . $symbol . ' —— 写路径与 Redis 无关，'
                    . '把手伸进键空间就绕过了主项目的校验、限流与指标'
                );
            }
        }
    }

    /**
     * ★ `payload_max` 必须由**同一个函数**产出（`Pusher::payloadMax()`），两侧不得各算一遍。
     *
     * 各算一遍的下场：页面提示「上限 4096」，提交却被按 1024 拒 ——
     * 用户看到的是「明明没超限却被拒」，而两侧代码各自看都正确。
     */
    public function testPayloadMaxIsComputedByTheSameFunctionOnBothSides(): void
    {
        $this->assertStringContainsString(
            'Pusher::payloadMax(',
            $this->read(self::PAGE_CONTROLLER),
            '页面控制器必须复用 Pusher::payloadMax()，不得自行计算上限'
        );
        $this->assertStringContainsString(
            'Pusher::payloadMax(',
            $this->read(self::API_CONTROLLER),
            'api 控制器必须复用 Pusher::payloadMax()'
        );
    }

    /* =====================================================================
     | ④ 「前端不得编词」：说明文案只能由后端下发
     ===================================================================== */

    public function testViewProvidesNoteContainersForEachBackendGroup(): void
    {
        $view = $this->read(self::VIEW);
        $controller = $this->read(self::PAGE_CONTROLLER);

        foreach (['push-notes', 'history-note', 'tpl-note'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $view, '视图缺少说明容器：' . $id);
        }

        // 三组必须由控制器产出（JS 按组注入，组名是后端决定的）
        foreach (["'global' =>", "'history' =>", "'templates' =>"] as $group) {
            $this->assertStringContainsString(
                $group,
                $controller,
                '页面控制器必须下发说明分组 ' . $group
            );
        }
    }

    /**
     * ★ 六条后端文案**不得**在前端复述。
     *
     * 做法：把常量文本的空白压掉取前 20 个字符，再去压掉空白的脚本里找。
     * 只要前端原样抄了这句话（不论怎么换行/缩进）就会被抓到。
     *
     * 为什么要这么严：这六条里有三条是「响应里读不出来」的事实
     * （code=0 只代表入队 / 同 msg_id 静默去重 / payload 超限在业务进程静默丢弃）。
     * 前端复述一遍就一定会与后端漂移，而漂移的后果是**界面在撒谎**。
     */
    public function testFrontendScriptDoesNotRestateBackendNotes(): void
    {
        $flatScript = (string)preg_replace('/\s+/u', '', $this->read(self::SCRIPT));

        $notes = [
            'ACCEPTED' => Pusher::ACCEPTED_NOTE,
            'DEDUP' => Pusher::DEDUP_NOTE,
            'PAYLOAD_DROP' => Pusher::PAYLOAD_DROP_NOTE,
            'NO_TOPIC' => Pusher::NO_TOPIC_NOTE,
            'TEMPLATE' => Pusher::TEMPLATE_NOTE,
            'RECORD' => PushRepository::RECORD_NOTE,
        ];

        foreach ($notes as $name => $note) {
            $flatNote = (string)preg_replace('/\s+/u', '', $note);
            $this->assertNotSame('', $flatNote, $name . ' 文案为空，断言将失去意义');

            $fragment = mb_substr($flatNote, 0, 20);
            $this->assertStringNotContainsString(
                $fragment,
                $flatScript,
                'push.js 里出现了后端文案 ' . $name . ' 的开头片段 —— '
                . '说明文案必须由 cfg.notes 下发，前端复述会与后端漂移（界面撒谎）'
            );
        }
    }

    /* =====================================================================
     | ⑤ 路由覆盖
     ===================================================================== */

    /**
     * ★ 控制器里声明的每个端点，都必须能在 `config/route.php` 找到**动词正确**的路由。
     *
     * 与前缀匹配的处理同 `SessionContractTest`：API 路由声明在 `Route::group('/api', …)` 内，
     * 直接抓 `Route::get('...')` 得到的是去掉前缀的片段，故同时生成「原路径」与
     * 「各 group 前缀 + 原路径」两组候选。
     *
     * ⚠ 与 Session 的版本相比，这里把 `path => verb` 改成了 **`path => 动词集合`**：
     * `/api/push/templates` 同时承载 GET（列表）与 POST（保存），
     * 若用 `array_combine` 后者会覆盖前者，断言将失去一半覆盖。
     */
    public function testEveryEndpointDeclaredByControllerHasARouteWithExpectedVerb(): void
    {
        $controller = $this->read(self::PAGE_CONTROLLER);
        preg_match_all("/'([a-z_]+_(?:url|base))'\\s*=>\\s*'([^']+)'/", $controller, $m, PREG_SET_ORDER);
        $endpoints = [];
        foreach ($m as $row) {
            $endpoints[$row[1]] = $row[2];
        }

        $this->assertNotEmpty($endpoints, '控制器里没有解析到任何端点，正则八成失配了');

        $routeFile = $this->read(self::ROUTES);
        preg_match_all("#Route::(get|post|put|delete|patch|any)\\(\\s*'([^']+)'#", $routeFile, $r, PREG_SET_ORDER);
        $verbsByPath = [];
        foreach ($r as $row) {
            $verbsByPath[$row[2]][] = $row[1];
        }
        $this->assertNotEmpty($verbsByPath, '路由文件里没有解析到任何路由，正则八成失配了');

        preg_match_all("#Route::group\\(\\s*'([^']+)'#", $routeFile, $g);
        $groupPrefixes = array_values(array_unique($g[1]));
        $this->assertNotEmpty($groupPrefixes, '没有解析到 Route::group 前缀，正则八成失配了');

        foreach (self::ENDPOINT_VERBS as $alias => $expectedVerbs) {
            $this->assertArrayHasKey($alias, $endpoints, '控制器里没有声明端点 ' . $alias);
            $endpoint = $endpoints[$alias];

            $matchKey = null;
            foreach (array_keys($verbsByPath) as $path) {
                foreach ($groupPrefixes as $prefix) {
                    foreach ([$path, $prefix . $path] as $candidate) {
                        if ($candidate === $endpoint) {
                            $matchKey = $path;

                            break 3;
                        }
                        $tail = str_starts_with($candidate, $endpoint) ? substr($candidate, strlen($endpoint)) : null;
                        if ($tail !== null && preg_match('/^\{[A-Za-z_][A-Za-z0-9_]*\}$/', $tail) === 1) {
                            $matchKey = $path;

                            break 3;
                        }
                    }
                }
            }

            $this->assertNotNull(
                $matchKey,
                '端点 ' . $endpoint . '（' . $alias . '）在 config/route.php 里没有对应路由 —— '
                . '前端会打到 404，而契约上看不出任何问题'
            );

            $actual = $verbsByPath[(string)$matchKey];
            foreach ($expectedVerbs as $verb) {
                $this->assertContains(
                    $verb,
                    $actual,
                    $endpoint . ' 缺少 ' . strtoupper($verb) . ' 路由（现有：' . implode('/', $actual) . '）'
                    . ' —— 动词改错会让写操作变成读，页面表现为难懂的 405/404'
                );
            }
        }
    }

    public function testBothPushControllersHaveDefaultRouteDisabled(): void
    {
        $routeFile = $this->read(self::ROUTES);

        $this->assertStringContainsString('Route::disableDefaultRoute(PushController::class);', $routeFile);
        $this->assertStringContainsString('Route::disableDefaultRoute(PushApiController::class);', $routeFile);
    }

    public function testPageRouteIsGuarded(): void
    {
        $this->assertMatchesRegularExpression(
            "#Route::get\\('/push',\\s*\\[PushController::class,\\s*'index'\\]\\)\\s*->middleware\\(\\[AdminAuth::class\\]\\)#",
            $this->read(self::ROUTES),
            '/push 页面路由必须挂 AdminAuth 中间件'
        );
    }

    /* =====================================================================
     | ⑥ RBAC：写权限必须与读权限分开
     ===================================================================== */

    /**
     * ★ 权限别名必须指向**真实存在**的控制器动作。
     *
     * 两件事一起钉：
     *   ① 控制器名必须写成 `PushApiController::class` 而不是字符串字面量 ——
     *      字面量写错一个反斜杠不会报任何错，`Auth::canAccess()` 只是查不到规则、
     *      静默返回 false，表现为「所有人都看不到按钮」，排查方向会跑偏到「角色配错了」；
     *   ② 动作名必须真的存在于那个控制器上（用反射判定，不是文本匹配）。
     */
    public function testPermissionAliasesPointAtRealControllerActions(): void
    {
        $controller = $this->read(self::PAGE_CONTROLLER);

        $this->assertMatchesRegularExpression(
            '/\'perms\'\s*=>\s*Perm::map\(\s*\[(.*?)\]\s*\)/s',
            $controller,
            '没有解析到 perms 映射，正则八成失配了（结构变了要同步本测试）'
        );
        preg_match('/\'perms\'\s*=>\s*Perm::map\(\s*\[(.*?)\]\s*\)/s', $controller, $block);
        $inner = $block[1];

        // 所有条目都必须走 `::class` 形式
        // ⚠ 动作名必须用 `[A-Za-z_][A-Za-z0-9_]*` 而不是 `[a-z_]+`：
        //   本模块的动作是 `create` / `templateSave` / `templateDelete`，含大写字母。
        //   写窄了只会匹配到 `create` 一条，而断言会以「计数不符」的形式失败 ——
        //   报错信息指向「存在非 ::class 条目」，与真实原因完全不符（已踩过）。
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
            'perms 映射里存在非 `X::class` 形式的条目 —— 控制器名必须用类常量引用，'
            . '写字符串字面量会在命名空间改名时静默失效'
        );

        $class = new \ReflectionClass(PushApiController::class);

        foreach ($pairs as $pair) {
            $alias = $pair[1];
            $aliasClass = $pair[2];
            $action = $pair[3];

            $this->assertSame(
                'PushApiController',
                $aliasClass,
                '权限别名 ' . $alias . ' 必须引用 api 控制器（PushApiController::class），当前是 ' . $aliasClass
            );
            $this->assertTrue(
                $class->hasMethod($action),
                '权限别名 ' . $alias . ' 指向的动作 ' . $action . '() 在 '
                . self::API_CONTROLLER . ' 里不存在 —— Auth::canAccess 只会静默返回 false，'
                . '表现为「所有人都看不到按钮」'
            );
        }
    }

    /**
     * ★ 「只读」角色**不得**拿到任何推送写权限节点。
     *
     * 这条防的是「为了让只读角色能看历史，把整个 /push 相关节点一起授予」——
     * 那样一来，一个只该看报表的角色就获得了「以他的名义向任意 uid 推任意载荷」的能力，
     * 而且**不会有任何报错**：页面只是多显示了几个按钮。
     */
    public function testReadOnlyRoleHasNoPushWriteNodes(): void
    {
        $install = $this->read(self::INSTALL);

        $this->assertMatchesRegularExpression(
            '/\$viewerRules\s*=\s*\[(.*?)\];/s',
            $install,
            '没有解析到 $viewerRules，install.php 结构变了要同步本测试'
        );
        preg_match('/\$viewerRules\s*=\s*\[(.*?)\];/s', $install, $viewer);
        $viewerBlock = $viewer[1];

        foreach (['push.create', 'push.tplSave', 'push.tplDelete'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $viewerBlock,
                '「只读」角色被授予了写权限节点 ' . $forbidden
                . ' —— 只读角色应只有 push 页 + push.history + push.tplList'
            );
        }

        // 正方向：只读角色**必须**能看历史与模板列表，否则这一页对它是全空的
        foreach (['push', 'push.history', 'push.tplList'] as $required) {
            $this->assertStringContainsString(
                $required,
                $viewerBlock,
                '「只读」角色缺少 ' . $required . ' —— 它将看不到推送历史或模板列表'
            );
        }

        // 运维角色必须补上三个写节点（否则只有超管能发推送，运维角色形同虚设）
        $this->assertMatchesRegularExpression(
            '/\$operatorRules\s*=\s*array_merge\(\s*\$viewerRules\s*,\s*\[(.*?)\]\s*\);/s',
            $install,
            '没有解析到 $operatorRules，install.php 结构变了要同步本测试'
        );
        preg_match('/\$operatorRules\s*=\s*array_merge\(\s*\$viewerRules\s*,\s*\[(.*?)\]\s*\);/s', $install, $operator);
        foreach (['push.create', 'push.tplSave', 'push.tplDelete', 'action.invoke'] as $required) {
            $this->assertStringContainsString(
                $required,
                $operator[1],
                '「运维」角色缺少 ' . $required . ' —— 写路径对运维不可用'
            );
        }
    }

    /* =====================================================================
     | ⑦ 常量边界
     ===================================================================== */

    /**
     * 页大小候选不得越界。
     *
     * 刻意不写 `assertNotEmpty(SIZE_OPTIONS)`：那是对字面量常量的判断，PHPStan 会判恒真
     * （`method.alreadyNarrowedType`）。下面的逐项边界断言才是真正要守的东西。
     */
    public function testSizeOptionsAreWithinRepositoryMax(): void
    {
        foreach (PushController::SIZE_OPTIONS as $size) {
            $this->assertLessThanOrEqual(
                PushRepository::SIZE_MAX,
                $size,
                '页大小候选 ' . $size . ' 超过 PushRepository::SIZE_MAX：下拉能选、后端却会静默夹取'
            );
            $this->assertGreaterThanOrEqual(PushRepository::SIZE_MIN, $size);
        }
    }

    /**
     * 历史默认页大小在两侧同源（页面控制器引用 api 控制器的常量，而不是各写一个字面量）。
     */
    public function testHistoryPageSizeIsSingleSourced(): void
    {
        $this->assertStringContainsString(
            'PushApiController::HISTORY_PAGE_SIZE',
            $this->read(self::PAGE_CONTROLLER),
            '页面控制器必须引用 PushApiController::HISTORY_PAGE_SIZE —— '
            . '两侧各写一个字面量会出现「首屏 20 条但下拉写着 50」'
        );
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

    /**
     * 脚本里引用的全部 DOM id。
     *
     * ⚠ 必须返回**值列表**而非字典 —— 后者会让 `array_diff` 比较「值」（全是 `true`），
     * 结果恒等于脚本侧清单，断言永远失败（P1 已踩过）。
     *
     * @return list<string>
     */
    private function scriptIds(): array
    {
        $script = $this->read(self::SCRIPT);
        $accessors = implode('|', array_map(
            static fn (string $n): string => preg_quote($n, '/'),
            self::ID_ACCESSORS
        ));
        $quoted = "'([^']+)'";

        $found = [];

        // 形态一：辅助函数第一个入参是 id，如 setNote('push-status', ...)
        preg_match_all('/\b(?:' . $accessors . ')\s*\(\s*' . $quoted . '/', $script, $m1);
        $found = array_merge($found, $m1[1]);

        // 形态二：$('id')
        preg_match_all('/\$\s*\(\s*' . $quoted . '\s*\)/', $script, $m2);
        $found = array_merge($found, $m2[1]);

        // 形态三：document.getElementById('id')
        preg_match_all('/getElementById\s*\(\s*' . $quoted . '\s*\)/', $script, $m3);
        $found = array_merge($found, $m3[1]);

        // 形态四：refreshBytes(输入框, 计数显示, 上限显示) —— 三个入参**全是** id，
        //   只取第一个会漏掉两个显示节点（它们的 id 拼错就只会静默不更新）。
        preg_match_all('/\brefreshBytes\s*\(([^)]*)\)/', $script, $m4);
        foreach ($m4[1] as $args) {
            preg_match_all("/'([^']+)'/", $args, $inner);
            $found = array_merge($found, $inner[1]);
        }

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
