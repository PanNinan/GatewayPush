<?php

declare(strict_types=1);

namespace tests\Unit;

use app\service\ActionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * `ActionCatalog` 与主项目 `../config/actions.php` 的**漂移检测**。
 *
 * ## 为什么必须是测试而不是「注释里写一句『改主项目时记得同步』」
 *
 * `ActionCatalog::ACTIONS` 是**手写副本**（理由见类注释：运行时读取会把主项目的 `.env`
 * 引进来）。手写副本的通病是「改了 A 忘了改 B」，而这里忘了同步的后果是：
 * - 主项目**新增**了 HTTP 动作 → 后台下拉里没有它（功能缺失，尚可忍）；
 * - 主项目**关闭**了某动作的 HTTP 通道 → 后台仍能选它，每次点都拿 `400` + `4006`
 *   —— **一个必然失败的选项**（这就是虚假功能）；
 * - 描述文本漂移 → 页面上的说明与实际行为不符。
 *
 * 这与 `RedisKeyLiteralSniff::$prefixes`（`RedisKeys` 常量的手写副本）配
 * `composer lint:self` 做漂移检测是同一手法，只是这里放在 PHPUnit 里。
 *
 * ## 解析方式：按缩进切块，而不是解析 PHP
 *
 * `config/actions.php` 顶层 `use` 了主项目的 `Env`，**不能 `require`**（会读主项目的 .env）。
 * 故按「4 空格 = 顶层键、8 空格 = 动作名、12 空格 = 动作字段」的行缩进切块。
 * 这比正则匹配「`=> [` 到 `],`」稳得多 —— 动作里有 `params` / `reply` 两层嵌套数组，
 * 用括号配对写不出可维护的表达式。
 * 代价是**对缩进敏感**：主项目若重排缩进，本测试会以「一个动作都没解析到」失败
 * （`testParserFindsTheExpectedBaselineActions` 专门把这条底线显式化）。
 */
final class ActionCatalogTest extends TestCase
{
    /** 主项目动作声明文件 */
    private const ACTIONS_FILE = __DIR__ . '/../../../config/actions.php';

    /** 解析出的动作数下限（低于此值说明解析器坏了，而不是主项目变简单了） */
    private const BASELINE_ACTION_COUNT = 7;

    /** @var null|array<string, array{http: bool, auth: bool, description: string}> */
    private static ?array $parsed = null;

    /* =====================================================================
     | 解析器底线
     ===================================================================== */

    public function testParserFindsTheExpectedBaselineActions(): void
    {
        $parsed = self::parse();

        $this->assertGreaterThanOrEqual(
            self::BASELINE_ACTION_COUNT,
            count($parsed),
            '只解析到 ' . count($parsed) . ' 个动作（主项目现有 '
            . self::BASELINE_ACTION_COUNT . ' 个）—— 八成是 config/actions.php 改了缩进，'
            . '本测试的切块解析随之失效。**先确认解析器，再看下面的断言**。'
        );
    }

    /* =====================================================================
     | ① HTTP 开放集合（双向）
     ===================================================================== */

    public function testHttpExposedSetMatchesMainProjectBothWays(): void
    {
        $declared = self::httpExposedNames();
        // P4 起「HTTP 可用」≠「调试器列出」：运维动作能经 HTTP 调，但走专用运维入口。
        // 故比对口径是 names() ∪ NOT_IN_DEBUGGER，两者合起来才等于主项目的 http 集合。
        $mirrored = array_merge(ActionCatalog::names(), ActionCatalog::NOT_IN_DEBUGGER);

        sort($declared);
        sort($mirrored);

        $this->assertSame(
            $declared,
            $mirrored,
            'ActionCatalog（调试器清单）+ NOT_IN_DEBUGGER（运维动作）合起来，'
            . '必须与主项目 config/actions.php 的「HTTP 可用动作集合」逐项相同。' . "\n"
            . '  主项目有而镜像缺 → 后台漏掉一个可用动作（或新动作忘了归类）；' . "\n"
            . '  镜像有而主项目没有 → 后台给出一个**必然 400+4006** 的选项（虚假功能）。'
        );
    }

    /**
     * ★ 运维动作必须「HTTP 开放 + 限 HTTP 通道」，且**不**进调试器下拉
     *
     * 三条断言对应三个不同方向的坑：
     *   ① 没声明 http     → 后台运维入口必然 4006；
     *   ② 没声明 channels → 任何终端客户端都能经 WS 踢人（终端提权，主项目侧已有同名测试守着）；
     *   ③ 进调试器下拉    → `/actions` 变成一个一键踢任意人的按钮，而它不可撤销。
     */
    public function testOpsActionsAreHttpOpenChannelRestrictedAndAbsentFromDebugger(): void
    {
        $src = (string)@file_get_contents(dirname(__DIR__, 2) . '/../config/actions.php');
        $this->assertNotSame('', $src, '读不到主项目 config/actions.php');

        foreach (ActionCatalog::NOT_IN_DEBUGGER as $name) {
            // ① + ②：主项目声明里必须同时有 http=true 与 channels=[http]
            //
            // ⚠ 不用一条大正则跨整块匹配：`params` 段是嵌套数组，
            //   `\[[^\]]*\]` 这类量词在长文本上会撞 pcre.backtrack_limit 而**静默不匹配**
            //   （表现为「断言莫名其妙失败」）。先切块、再在块内找，简单且无回溯风险。
            $block = self::actionBlock($src, $name);
            $this->assertNotSame('', $block, '主项目 config/actions.php 里找不到动作 ' . $name);

            $this->assertStringContainsString(
                "'channels'    => [ActionContext::CHANNEL_HTTP]",
                $block,
                '★ 运维动作 ' . $name . ' 在主项目未声明 channels=[http] —— WS/UDP 侧即可调用，属终端提权'
            );
            $this->assertStringContainsString(
                "'http'        => true",
                $block,
                '运维动作 ' . $name . ' 在主项目未开放 HTTP —— 后台运维入口必然 4006'
            );

            // ③：不得出现在调试器清单里
            $this->assertNotContains(
                $name,
                ActionCatalog::names(),
                '★ 运维动作 ' . $name . ' 进了调试器下拉 —— 那等于给 /actions 装一个不可撤销的踢人按钮'
            );
        }
    }

    public function testCatalogCoversExactlySixActionsAtPresent(): void
    {
        // 把「当前是 6 个」写死是刻意的：P4 落地 kick/revoke/unbind 时**必然**走到这条断言
        // （要么失败后更新基线，要么在 NOT_HTTP_EXPOSED 里补 —— 两条路都迫使作者读一遍本文件）。
        $this->assertCount(6, ActionCatalog::ACTIONS);
    }

    /* =====================================================================
     | ② 具名锚点：session 刻意不在列
     ===================================================================== */

    public function testSessionIsDeclaredInMainProjectButNotHttpExposed(): void
    {
        $parsed = self::parse();

        $this->assertArrayHasKey('session', $parsed, 'session 动作应当仍存在于 config/actions.php');
        $this->assertFalse(
            $parsed['session']['http'],
            '前提变了：session 现在开放了 HTTP —— 请先确认该动作在 HTTP 通道下确实有意义'
            . '（它的语义锚点是「当前连接」，而 HTTP 下 clientId = http:{request_id}）'
        );

        // 双向：既不在镜像里，又在 NOT_HTTP_EXPOSED 里（「不列」是一个被钉住的显式决定）
        $this->assertArrayNotHasKey('session', ActionCatalog::ACTIONS);
        $this->assertContains('session', ActionCatalog::NOT_HTTP_EXPOSED);
    }

    public function testEveryNotHttpExposedNameExistsInMainProjectAndIsNotExposed(): void
    {
        $parsed = self::parse();

        foreach (ActionCatalog::NOT_HTTP_EXPOSED as $name) {
            $this->assertArrayHasKey($name, $parsed, $name . ' 在 NOT_HTTP_EXPOSED 里，但主项目没有该动作声明');
            $this->assertFalse($parsed[$name]['http'], $name . ' 实际已开放 HTTP，不该留在 NOT_HTTP_EXPOSED 里');
            $this->assertArrayNotHasKey($name, ActionCatalog::ACTIONS);
        }
    }

    public function testSessionActionIsNotInTheUiList(): void
    {
        foreach (ActionCatalog::forUi() as $item) {
            $this->assertNotSame('session', $item['name'], 'session 不在 HTTP 白名单里，绝不能出现在下拉框中');
        }
    }

    /* =====================================================================
     | ③ 描述逐字一致
     ===================================================================== */

    public function testDescriptionsMatchMainProjectVerbatim(): void
    {
        $parsed = self::parse();

        foreach (ActionCatalog::ACTIONS as $name => $meta) {
            $this->assertArrayHasKey($name, $parsed, $name . ' 在主项目里不存在');
            $this->assertSame(
                $parsed[$name]['description'],
                $meta['description'],
                $name . ' 的 description 与主项目不一致 —— 页面说明会与实际行为脱节'
            );
        }
    }

    /* =====================================================================
     | ④ requires_uid 等价于 auth 声明
     | ===================================================================== */

    public function testRequiresUidMatchesAuthDeclaration(): void
    {
        $parsed = self::parse();

        foreach (ActionCatalog::ACTIONS as $name => $meta) {
            $this->assertSame(
                $parsed[$name]['auth'],
                $meta['requires_uid'],
                $name . ' 的 requires_uid 与主项目 `auth` 声明不一致。'
                . '`src/Api/Bootstrap.php` 对 auth 为真且 uid 为空的请求直接回 401 + 4003，'
                . '故这两者必须严格等价 —— 否则 UI 会允许一个必然 401 的提交。'
            );
        }
    }

    public function testAllCurrentlyExposedActionsRequireUid(): void
    {
        // 当前 6 个动作都没有声明 `auth => false`，故全都要 uid。
        // P4 的 kick/revoke/unbind 将是首批 `auth => false` 的动作 ——
        // 那条断言届时会红，提醒作者在 forUi() 里把 uid 变成选填。
        //
        // ⚠ 断言打在**解析结果**（`bool`）而不是 `ActionCatalog::ACTIONS` 上：
        // 后者的字面量让 PHPStan 判定 `assertTrue(true)` 恒真并报 `alreadyNarrowedType`。
        $parsed = self::parse();

        foreach (ActionCatalog::ACTIONS as $name => $meta) {
            $this->assertTrue($parsed[$name]['auth'], $name . ' 当前应当要求 uid（auth 默认 true）');
        }
    }

    /* =====================================================================
     | ⑤ topic 规则的字节级同源
     ===================================================================== */

    public function testTopicPatternAppearsVerbatimInMainProject(): void
    {
        $src = (string)file_get_contents(self::ACTIONS_FILE);

        $this->assertTrue(
            str_contains($src, ActionCatalog::TOPIC_PATTERN),
            'ActionCatalog::TOPIC_PATTERN 必须与主项目 config/actions.php 中的 $topicRule '
            . '逐字符相同（用子串存在性断言，比解析结构更抗排版变化）。当前值：'
            . ActionCatalog::TOPIC_PATTERN
        );
    }

    /* =====================================================================
     | ⑥ 归一与展示
     ===================================================================== */

    public function testNormalizeIsWhitelistBased(): void
    {
        $this->assertSame('echo', ActionCatalog::normalize('ECHO'));
        $this->assertSame('notify', ActionCatalog::normalize('  notify '));
        $this->assertSame('', ActionCatalog::normalize('session'), 'session 不在白名单，必须回落空串');
        $this->assertSame('', ActionCatalog::normalize('unknown'));
        $this->assertSame('', ActionCatalog::normalize(null));
        $this->assertSame('', ActionCatalog::normalize(42));
    }

    public function testForUiIsCompleteAndOrderedLikeTheConstant(): void
    {
        $ui = ActionCatalog::forUi();

        $this->assertCount(count(ActionCatalog::ACTIONS), $ui);
        $this->assertSame(ActionCatalog::names(), array_column($ui, 'name'));

        foreach ($ui as $item) {
            $this->assertNotSame('', $item['description']);
            $this->assertNotSame('', $item['params_hint'], $item['name'] . ' 缺 params_hint');
            // PHPStan 需要知道 shape 里的每个键都存在；顺带把它当作契约断言
            $this->assertIsBool($item['requires_uid']);
        }
    }

    public function testNotesMentionTheNonObviousFacts(): void
    {
        $this->assertStringContainsString('不校验归属', ActionCatalog::UID_SEMANTICS_NOTE);
        $this->assertStringContainsString('4003', ActionCatalog::UID_REQUIRED_NOTE);
        $this->assertStringContainsString('不是失败', ActionCatalog::PENDING_NOTE);
        $this->assertStringContainsString('两种含义', ActionCatalog::RESULT_TTL_NOTE);
        $this->assertStringContainsString('P4', ActionCatalog::SCOPE_NOTE);
    }

    /* =====================================================================
     | 解析器
     ===================================================================== */

    /**
     * 解析 `../config/actions.php` 的 `'actions' => [...]` 块。
     *
     * @return array<string, array{http: bool, auth: bool, description: string}>
     */
    private static function parse(): array
    {
        if (self::$parsed !== null) {
            return self::$parsed;
        }

        $src = (string)file_get_contents(self::ACTIONS_FILE);
        $chunks = self::chunkByAction($src);

        // `defaults.auth` 是各动作未显式声明时的回落值（现为 true）
        $defaultAuth = self::defaultAuth($src);

        $out = [];
        foreach ($chunks as $name => $body) {
            $auth = true;
            if (preg_match("/^\s+'auth'\s*=>\s*(true|false)\s*,/m", $body, $a) === 1) {
                $auth = $a[1] === 'true';
            } else {
                $auth = $defaultAuth;
            }

            $description = '';
            if (preg_match("/^\s+'description'\s*=>\s*'(.*)',\s*$/m", $body, $d) === 1) {
                $description = $d[1];
            }

            $out[$name] = [
                'http' => preg_match("/^\s+'http'\s*=>\s*true\s*,/m", $body) === 1,
                'auth' => $auth,
                'description' => $description,
            ];
        }

        self::$parsed = $out;

        return $out;
    }

    /**
     * 按缩进把 `'actions' => [` 块切成「动作名 => 该动作的正文」。
     *
     * @return array<string, string>
     */
    private static function chunkByAction(string $src): array
    {
        $chunks = [];
        $current = null;
        $inActions = false;

        foreach (explode("\n", $src) as $line) {
            if (!$inActions) {
                if (preg_match("/^    'actions' => \[\s*$/", $line) === 1) {
                    $inActions = true;
                }

                continue;
            }

            // `    ],`（4 空格）= actions 块的收尾。动作自身的收尾是 8 空格，不会撞上。
            if (preg_match("/^    \],\s*$/", $line) === 1) {
                break;
            }

            if (preg_match("/^        '([a-z][a-z0-9_]*)' => \[\s*$/", $line, $m) === 1) {
                $current = $m[1];
                $chunks[$current] = '';

                continue;
            }

            if ($current !== null) {
                $chunks[$current] .= $line . "\n";
            }
        }

        return $chunks;
    }

    /** `'defaults' => [` 块里的 `auth` 回落值。 */
    private static function defaultAuth(string $src): bool
    {
        // 取第一个 `'defaults'` 之后的 `'auth'` 声明
        $pos = strpos($src, "'defaults' => [");
        if ($pos === false) {
            return true;
        }

        $tail = substr($src, $pos, 400);

        return preg_match("/^\s+'auth'\s*=>\s*false\s*,/m", $tail) !== 1;
    }

    /** @return list<string> */
    private static function httpExposedNames(): array
    {
        $out = [];
        foreach (self::parse() as $name => $meta) {
            if ($meta['http']) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * 切出某个动作的声明块（`'name' => [` 到该块收尾的 `],`）
     *
     * 用**行扫描**而非正则：块内 `params` 是嵌套数组，正则要靠回溯跨过它，
     * 长文本下会撞 `pcre.backtrack_limit` 而静默不匹配（已踩过一次）。
     * 按缩进收尾判定既简单又无回溯风险 —— 声明块收尾固定是 `    ],`（4 空格）。
     *
     * @param string $src  主项目 config/actions.php 源码
     * @param string $name 动作名
     *
     * @return string 找不到时返回空串
     */
    private static function actionBlock(string $src, string $name): string
    {
        $lines = explode("\n", $src);
        $start = -1;
        foreach ($lines as $i => $line) {
            if (preg_match("/^\s+'" . preg_quote($name, '/') . "'\s*=>\s*\[/", $line) === 1) {
                $start = $i;
                break;
            }
        }
        if ($start < 0) {
            return '';
        }

        $out = [];
        for ($i = $start; $i < count($lines); $i++) {
            $out[] = $lines[$i];
            // 只认**顶层**收尾（4 空格缩进的 `],`）：嵌套的 params 段收尾是 12 空格，
            // 用 `/^\s*\],/` 会在 params 处提前截断（已踩过）。
            if ($i > $start && preg_match('/^    \],/', $lines[$i]) === 1) {
                break;
            }
        }

        return implode("\n", $out);
    }
}
