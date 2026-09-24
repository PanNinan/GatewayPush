<?php
/**
 * admin 单测 —— SessionInspectorTest。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace tests\Unit;

use app\service\SessionInspector;
use GatewayPush\Business\Session;
use GatewayPush\Common\RedisKeys;
use PHPUnit\Framework\TestCase;

/**
 * `SessionInspector` 纯函数单测。
 *
 * 这里能做到**零 Redis、零 MySQL**，正是因为判定/归类/排序/分页被写成了静态函数 ——
 * 与 P1 的 `MetricsDeriver` 同一口径（阈值靠构造注入而非内部读配置）。
 *
 * 三条核心断言，改判定逻辑时最容易被顺手破坏：
 *
 *  ① **在线判定只看 `online:clients` 的成员资格，不看 `offline_at`。**
 *     `offline_at` 是**粘性字段**：主项目 `Session::bind()` 只 `hMSet` 9 个业务字段、
 *     全 `src/Business/` 内从无 `hDel('offline_at')`；而 UDP 的 clientId 是
 *     `udp:{ip}:{port}`，同一客户端会**重绑同一个 clientId**。两者叠加 →「断过再活跃」的
 *     连接会长期带着 `offline_at`。若拿它判在线，活跃 UDP 连接会被误判为离线。
 *     故 `stateOf()` 的**签名里刻意没有 `offline_at`**；本测试用「带 offline_at 的在线会话」
 *     这个反例把它钉住。
 *
 *  ② **时间戳缺失得 `null`，不是 `0`。** 「没有这个时间」与「正好 0 秒前」在页面上是
 *     「—」与「0s」的区别；混同会让从未建连的会话显示成「刚刚活跃」。
 *
 *  ③ **分页大小上限 100 是「无 N+1」不变量的前提。** 列表取数按 `size` 个键做一批
 *     HGETALL，上限失效则一次请求即可触发上万次批量读取。
 *
 * `@phpstan-import-type` 而非就地重写 shape：类型定义只有一处（`SessionInspector`），
 * 测试与实现共用同一份，避免改了实现忘了同步测试的 shape。
 *
 * @phpstan-import-type SessionRow from SessionInspector
 */
final class SessionInspectorTest extends TestCase
{
    /** 固定时间戳：不使用 time()，保证断言与执行时刻无关 */
    private const NOW = 1800000000;

    // =====================================================================
    // ① stateOf：在线判定不看 offline_at
    // =====================================================================

    public function testStateOfCoversFourQuadrants(): void
    {
        $this->assertSame(
            SessionInspector::STATE_ONLINE,
            SessionInspector::stateOf(true, true),
            '在在线集合内 → 在线'
        );
        $this->assertSame(
            SessionInspector::STATE_ONLINE,
            SessionInspector::stateOf(true, false),
            '在集合内但会话键已消失（回收竞态）→ 仍按在线处理，避免把正在连的显示成已回收'
        );
        $this->assertSame(
            SessionInspector::STATE_RETAINED,
            SessionInspector::stateOf(false, true),
            '不在集合内但键仍在 → 保留（断开但可重连）'
        );
        $this->assertSame(
            SessionInspector::STATE_GONE,
            SessionInspector::stateOf(false, false),
            '不在集合内且键不存在 → 已回收'
        );
    }

    /**
     * ★ 核心反例：会话 Hash 里**带着** `offline_at`，但只要它在在线集合内，就判在线。
     *
     * 这一条锁的是「粘性 offline_at」这个真实缺陷：主项目不清理该字段，
     * 后台若以「offline_at 为空」判在线，就会把重连后的 UDP 连接持续误判为离线。
     */
    public function testStickyOfflineAtDoesNotMakeAnOnlineSessionLookOffline(): void
    {
        $session = ['uid' => '1001', 'protocol' => 'udp', 'offline_at' => (string)(self::NOW - 300)];

        $row = SessionInspector::rowOf('udp:127.0.0.1:5000', $session, true, self::NOW);

        $this->assertSame(
            SessionInspector::STATE_ONLINE,
            $row['state'],
            '带着 offline_at 但在在线集合内 → 必须判在线（offline_at 是粘性字段，不可作在线判据）'
        );
        $this->assertSame(self::NOW - 300, $row['offline_at'], 'offline_at 仍如实展示（作「最近一次断开时间」）');
        $this->assertSame(300, $row['offline_secs'], '并给出可读的秒数差');
    }

    public function testStateNotesCoverEveryStateConstant(): void
    {
        $states = [
            SessionInspector::STATE_ONLINE,
            SessionInspector::STATE_RETAINED,
            SessionInspector::STATE_GONE,
        ];

        foreach ($states as $state) {
            $this->assertArrayHasKey($state, SessionInspector::STATE_NOTES, $state . ' 缺少状态说明文案');
            $this->assertNotSame('', SessionInspector::STATE_NOTES[$state], $state . ' 的说明文案不应为空');
        }
    }

    public function testRevokeNoteExplainsWhyItIsNotDerivable(): void
    {
        $this->assertNotSame('', SessionInspector::REVOKE_NOTE);
        $this->assertStringContainsString('指纹', SessionInspector::REVOKE_NOTE, '必须说明撤销名单以指纹为键');
    }

    public function testProtocolsAlignWithMainProjectConstants(): void
    {
        $this->assertSame(
            [Session::PROTOCOL_WS, Session::PROTOCOL_UDP],
            SessionInspector::PROTOCOLS,
            '协议清单必须与主项目 Session::PROTOCOL_* 同源，否则筛选器会漏掉协议'
        );
    }

    // =====================================================================
    // ② rowOf：字段映射与缺失回落
    // =====================================================================

    public function testRowOfFallsBackToEmptyStringAndZero(): void
    {
        // ⚠ 刻意**不走** self::row()：那个构造器会填一批合法默认值（uid=1001 等），
        //    而本用例测的正是「字段缺失时的回落」，必须喂空 Hash。
        $row = SessionInspector::rowOf('c-1', [], false, self::NOW);

        $this->assertSame('', $row['uid']);
        $this->assertSame('', $row['device_id']);
        $this->assertSame('', $row['protocol']);
        $this->assertSame(0, $row['connect_at']);
        $this->assertSame(0, $row['last_active']);
        $this->assertSame(0, $row['offline_at']);
        $this->assertNull($row['connect_secs'], '缺失的 connect_at 应得 null（不是 0）');
        $this->assertNull($row['idle_secs']);
        $this->assertNull($row['offline_secs']);
    }

    public function testRowOfKeepsClientIdArgumentWhenFieldMissing(): void
    {
        $row = self::row([], clientId: 'ws-abc');

        $this->assertSame('ws-abc', $row['client_id'], 'Hash 里没有 client_id 时应回落到入参，不能变成空串');
    }

    public function testRowOfPrefersFieldValueOverArgument(): void
    {
        $row = self::row(['client_id' => 'from-hash'], clientId: 'from-arg');

        $this->assertSame('from-hash', $row['client_id']);
    }

    public function testRowOfComputesElapsedSeconds(): void
    {
        $row = self::row([
            'connect_at' => (string)(self::NOW - 100),
            'last_active' => (string)(self::NOW - 5),
        ]);

        $this->assertSame(100, $row['connect_secs']);
        $this->assertSame(5, $row['idle_secs']);
    }

    public function testAgeOfTreatsDestroyedTimestampsAsMissing(): void
    {
        // 0 / 负数 / 非数字 / 空串 一律视为「没有这个时间」
        foreach ([0, -5, 'abc', ''] as $broken) {
            $this->assertNull(
                SessionInspector::ageOf(['t' => $broken], 't', self::NOW),
                '损坏的时间戳 ' . var_export($broken, true) . ' 必须得 null'
            );
        }

        $this->assertNull(SessionInspector::ageOf([], 't', self::NOW), '字段缺失同样得 null');
    }

    public function testAgeOfClampsFutureTimestampsToZero(): void
    {
        $this->assertSame(
            0,
            SessionInspector::ageOf(['t' => self::NOW + 60], 't', self::NOW),
            '未来时间戳（时钟偏移）夹到 0，不能出现负数秒'
        );
    }

    // =====================================================================
    // sortRows / slice
    // =====================================================================

    public function testSortRowsPutsOnlineFirstThenMostRecent(): void
    {
        $retainedNew = self::row(['client_id' => 'r-new', 'last_active' => (string)self::NOW], inOnlineSet: false);
        $onlineOld = self::row(['client_id' => 'o-old', 'last_active' => (string)(self::NOW - 900)], inOnlineSet: true);
        $onlineNew = self::row(['client_id' => 'o-new', 'last_active' => (string)self::NOW], inOnlineSet: true);

        $sorted = SessionInspector::sortRows([$retainedNew, $onlineOld, $onlineNew]);

        $this->assertSame('o-new', $sorted[0]['client_id'], '在线且最近活跃的排最前');
        $this->assertSame('o-old', $sorted[1]['client_id'], '在线组内按 last_active 降序');
        $this->assertSame('r-new', $sorted[2]['client_id'], '保留会话即便更「新」也排在线之后');
    }

    public function testSortRowsIsStableViaClientIdTieBreak(): void
    {
        $a = self::row(['client_id' => 'bbb', 'last_active' => (string)self::NOW], inOnlineSet: true);
        $b = self::row(['client_id' => 'aaa', 'last_active' => (string)self::NOW], inOnlineSet: true);

        $sorted = SessionInspector::sortRows([$a, $b]);

        $this->assertSame('aaa', $sorted[0]['client_id'], '同 last_active 时必须按 clientId 升序，避免翻页时记录重复/漏出');
        $this->assertSame('bbb', $sorted[1]['client_id']);
    }

    public function testSliceReturnsEmptyOnOutOfRangePage(): void
    {
        $rows = [
            self::row(['client_id' => 'a'], inOnlineSet: true),
            self::row(['client_id' => 'b'], inOnlineSet: true),
        ];

        $this->assertCount(2, SessionInspector::slice($rows, 1, 10));
        $this->assertCount(1, SessionInspector::slice($rows, 2, 1));
        $this->assertSame([], SessionInspector::slice($rows, 3, 1), '越界页返回空列表，不做环绕');
        $this->assertSame([], SessionInspector::slice($rows, 0, 10), '页码 0 视为非法');
        $this->assertSame([], SessionInspector::slice($rows, 1, 0), '页大小 0 视为非法');
    }

    // =====================================================================
    // 入参归一
    // =====================================================================

    public function testValidIdAcceptsRealisticIdentifiers(): void
    {
        $ok = [
            '1001',
            'udp:127.0.0.1:5000',      // UDP 的 clientId 形态（冒号与点号都必须放行）
            '7f0000010b5400000001',    // GatewayWorker 的 WS clientId 形态
            'device-abc_1.2',
            str_repeat('x', SessionInspector::ID_MAX_LEN),   // 恰好到上限
            '中文uid',                  // 刻意不做字符集白名单：uid 形态未定型，过严会误杀
        ];

        foreach ($ok as $value) {
            $this->assertTrue(SessionInspector::validId($value), $value . ' 应被判为合法');
        }
    }

    public function testValidIdRejectsUnsafeValues(): void
    {
        $bad = [
            '',
            str_repeat('x', SessionInspector::ID_MAX_LEN + 1),
            "line\nbreak",
            "null\0byte",
            "\x7f",
            "\t",
        ];

        foreach ($bad as $value) {
            $this->assertFalse(
                SessionInspector::validId($value),
                var_export($value, true) . ' 应被判为非法（空、超长或含控制字符）'
            );
        }
    }

    public function testCleanIdCollapsesNonStringsToEmpty(): void
    {
        $this->assertSame('', SessionInspector::cleanId(null));
        $this->assertSame('', SessionInspector::cleanId(123));
        $this->assertSame('', SessionInspector::cleanId(['a']));
        $this->assertSame('', SessionInspector::cleanId('bad' . "\n"));
        $this->assertSame('1001', SessionInspector::cleanId('1001'));
    }

    public function testScopeOfFallsBackToOnline(): void
    {
        $this->assertSame(SessionInspector::SCOPE_ONLINE, SessionInspector::scopeOf(null));
        $this->assertSame(SessionInspector::SCOPE_ONLINE, SessionInspector::scopeOf('nope'));
        $this->assertSame(SessionInspector::SCOPE_ONLINE, SessionInspector::scopeOf(123));
        $this->assertSame(SessionInspector::SCOPE_RETAINED, SessionInspector::scopeOf('retained'));
        $this->assertSame(SessionInspector::SCOPE_ALL, SessionInspector::scopeOf('all'));
    }

    // =====================================================================
    // ③ 键空间边界：scanKeys() 给「逻辑键名」，sessions() 要「clientId」
    // =====================================================================

    /**
     * 回归锚点：这条边界漏了会**静默读空**（scope=retained 返回 0 条而 SCAN 明明扫到了），
     * 故用「与 sessions() 的真实约定」做往返断言，而不是只断言字符串被切掉。
     */
    public function testClientIdsFromKeysStripsSessionPrefix(): void
    {
        $clientIds = SessionInspector::clientIdsFromKeys(['session:ws-abc', 'session:udp:1.2.3.4:5000']);

        $this->assertSame(['ws-abc', 'udp:1.2.3.4:5000'], $clientIds);
    }

    /**
     * 往返契约：`sessions()` 会把每个 clientId 重新拼成 `session:{clientId}`，
     * 因此「SCAN 键 → clientId → 会话键」必须回到同一个键。
     * 若有人把转换写成 `substr($key, ...)` 之外的花样（或漏了前缀剥离），这里立刻红。
     */
    public function testClientIdsFromKeysRoundTripMatchesRedisKeysSession(): void
    {
        foreach (['ws-abc', 'udp:127.0.0.1:5000', '1001', '中文-uid'] as $clientId) {
            $logicalKey = RedisKeys::session($clientId);
            $this->assertSame(
                [$clientId],
                SessionInspector::clientIdsFromKeys([$logicalKey]),
                'clientId 经 RedisKeys::session() 再还原后必须原样相等'
            );
            $this->assertSame(
                $logicalKey,
                RedisKeys::session(SessionInspector::clientIdsFromKeys([$logicalKey])[0]),
                '还原出的 clientId 重新拼键必须回到同一个键（否则 sessions() 会读空）'
            );
        }
    }

    public function testClientIdsFromKeysDropsForeignAndBareKeys(): void
    {
        $this->assertSame(
            ['ws-a'],
            SessionInspector::clientIdsFromKeys([
                'ws-a',                   // 裸 clientId：没有 session: 前缀 → 丢弃（保留会成幽灵记录）
                'heartbeat:ws-b',         // 别的键族 → 丢弃
                'metrics:gauge',          // 别的键族 → 丢弃
                'auth:revoked:deadbeef',
                'session:ws-a',           // 唯一合法项
                'session:',               // 空 clientId → 丢弃
                '',                       // 空串 → 丢弃
            ]),
            '非 session: 前缀与空 clientId 一律丢弃，不做「原样保留」的兜底'
        );
    }

    public function testClientIdsFromKeysDeduplicatesAndKeepsListShape(): void
    {
        $out = SessionInspector::clientIdsFromKeys([
            'session:ws-a',
            'session:ws-b',
            'session:ws-a',
        ]);

        $this->assertSame(['ws-a', 'ws-b'], $out, '去重且保持 list 形状（array_values 之后仍是 list）');
        $this->assertSame([], SessionInspector::clientIdsFromKeys([]), '空输入 → 空输出');
    }

    public function testProtocolOfFallsBackToEmpty(): void
    {
        $this->assertSame('', SessionInspector::protocolOf(null));
        $this->assertSame('', SessionInspector::protocolOf('tcp'), '未知协议回落「全量」而非报错');
        $this->assertSame('ws', SessionInspector::protocolOf('ws'));
        $this->assertSame('udp', SessionInspector::protocolOf('udp'));
    }

    // =====================================================================
    // ③ paging：上限是「无 N+1」的前提
    // =====================================================================

    public function testPagingClampsSizeAndPage(): void
    {
        $this->assertSame([1, 20], SessionInspector::paging(null, null), '缺省 → 第 1 页 / 默认页大小');
        $this->assertSame([1, 20], SessionInspector::paging(0, 0), '0 视为缺省，不是返回空页');
        $this->assertSame([1, 20], SessionInspector::paging(-3, -1), '负值视为缺省');
        $this->assertSame([2, 50], SessionInspector::paging('2', '50'), '数字串按数值处理（查询串都是字符串）');
        $this->assertSame([1, 100], SessionInspector::paging(1, 99999), '超过上限必须夹到 100');
        $this->assertSame([1, 20], SessionInspector::paging(1, 0.4), '小数截断为 0 → 按缺省处理，不夹到 1');
        $this->assertSame([1, 20], SessionInspector::paging('abc', 'def'), '非数字回落缺省');
    }

    /**
     * `SIZE_MIN` 的唯一可达路径：**注入的默认值本身非法**时才有作用。
     *
     * 之所以要单独钉住：从查询串进来的 size 一旦 <=0 就走「回落默认值」，
     * 永远碰不到下界；因此 `SIZE_MIN` 实际是在防 `admin_settings.session.page_size`
     * 被改成 0 / 负数 / 超限 —— 少了这条断言，很容易误以为它保护的是请求参数。
     */
    public function testPagingSizeMinGuardsAMisconfiguredDefault(): void
    {
        $this->assertSame([1, 1], SessionInspector::paging(1, null, 0), '注入的默认值 0 → 夹到下界 1');
        $this->assertSame([1, 1], SessionInspector::paging(1, null, -9), '注入的默认值为负 → 夹到下界 1');
        $this->assertSame([1, 100], SessionInspector::paging(1, null, 999), '注入的默认值超上限 → 夹到 100');
    }

    public function testPagingAcceptsExplicitDefaultSize(): void
    {
        $this->assertSame(
            [1, 35],
            SessionInspector::paging(null, null, 35),
            '默认页大小由调用方注入（实例方法从 admin_settings 读），纯函数本身零 IO'
        );
    }

    public function testSizeMaxIsBounded(): void
    {
        $this->assertSame(100, SessionInspector::SIZE_MAX, '上限变更会直接放大单次请求的读取量');
        $this->assertLessThanOrEqual(SessionInspector::SIZE_MAX, SessionInspector::paging(1, 101)[1]);
    }

    // =====================================================================
    // 组装一行：私有构造器（避免每个用例重复写全字段）
    // =====================================================================

    /**
     * 造一行视图结构；只写关心的字段，其余用合法默认值。
     *
     * @param array<string, mixed> $overrides 覆盖的会话 Hash 字段（键名同 session Hash）
     *
     * @return SessionRow
     */
    private static function row(array $overrides, string $clientId = 'c-1', ?bool $inOnlineSet = null): array
    {
        $session = [
            'uid' => '1001',
            'device_id' => 'dev-1',
            'protocol' => 'ws',
            'client_ip' => '127.0.0.1',
            'client_port' => '5000',
            'gateway' => 'gateway:8282',
        ];
        foreach ($overrides as $key => $value) {
            $session[$key] = is_scalar($value) ? (string)$value : '';
        }

        // 默认：带 connect_at/last_active 的「在线」行；调用方可显式指定
        if (!isset($overrides['connect_at'])) {
            $session['connect_at'] = (string)(self::NOW - 10);
        }
        if (!isset($overrides['last_active'])) {
            $session['last_active'] = (string)self::NOW;
        }

        $online = $inOnlineSet ?? !isset($overrides['offline_at']);

        return SessionInspector::rowOf($clientId, $session, $online, self::NOW);
    }
}
