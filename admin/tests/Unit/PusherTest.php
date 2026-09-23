<?php

declare(strict_types=1);

namespace tests\Unit;

use app\service\Pusher;
use GatewayPush\Business\Message;
use GatewayPush\Business\Push;
use PHPUnit\Framework\TestCase;

/**
 * `Pusher` 纯函数单测（零 Redis / 零 MySQL / 零主项目 HTTP）。
 *
 * 四条核心断言，改判定逻辑时最容易被顺手破坏：
 *
 * ① **payload 字节数必须与服务端同源。** 判据是 `strlen(Message::encode($payload))`，
 *   与服务端 `Push::dispatch()`（`src/Business/Push.php:421`）调用的是**同一个方法**。
 *   注意 `strlen()` 是**字节**数：本测试用中文键值断言 `{"中文":"中文"}` 的字节数
 *   严格大于其字符数，把「按字符算」这种写法钉死。
 *   这不是洁癖 —— 服务端超限时是**静默丢弃**（只记 `push_fail`），后台若算错方向，
 *   用户会看到「已受理」而实际什么都没发生。
 *
 * ② **`msg_id` 留空必须自动补齐。** 服务端只在 `msg_id !== ''` 时才走幂等
 *   （`Push::enqueue()`），留空 = 无保护。这条与运维直觉相反，故由后台兜住。
 *
 * ③ **未知 `target_type` 回落空串，禁止学服务端的「回落 uid」。** 服务端的回落发生在
 *   business 进程（给队列脏 job 兜底），后台若也回落，一个拼错的值会静默变成
 *   「按 uid 推送」—— 错得非常隐蔽。
 *
 * ④ **payload 上限只允许调小。** 允许 `admin_settings` 覆盖，但调大无意义：
 *   服务端上限没变，后台放开只会产出「已受理但被静默丢弃」的载荷。
 */
final class PusherTest extends TestCase
{
    /* =====================================================================
     | ① 字节口径
     ===================================================================== */

    public function testPayloadBytesMatchesServerSideEncoding(): void
    {
        $payload = ['title' => 'hi', 'n' => 1];

        $this->assertSame(
            strlen(Message::encode($payload)),
            Pusher::payloadBytes($payload),
            'payloadBytes() 必须与服务端 Push::dispatch() 用同一个编码口径'
        );
    }

    public function testPayloadBytesCountsBytesNotCharacters(): void
    {
        // 4 个汉字 = 12 字节（UTF-8 每字 3 字节），而字符数是 4 —— 两者必须不同，
        // 否则「按字符算」的实现会与本断言一起通过（那样这个测试就不咬人了）。
        $payload = ['中文' => '中文'];
        $json = Message::encode($payload);

        $this->assertGreaterThan(
            mb_strlen($json),
            strlen($json),
            '测试前提：该 payload 的字节数必须大于字符数（否则本条断言不咬人）'
        );
        $this->assertSame(strlen($json), Pusher::payloadBytes($payload));
    }

    public function testPayloadBytesOfEmptyPayloadIsTwo(): void
    {
        // `[]` 与 `{}` 都会编码成 `[]`（PHP 空数组）→ 2 字节。写死这个值是为了让
        // 「空载荷也有成本」这件事可见：它不是 0。
        $this->assertSame(2, Pusher::payloadBytes([]));
    }

    /* =====================================================================
     | ② msg_id
     ===================================================================== */

    public function testGenMsgIdIsHexOfDeclaredLength(): void
    {
        $id = Pusher::genMsgId();

        $this->assertSame(Pusher::MSG_ID_LEN, strlen($id));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $id);
    }

    public function testGenMsgIdIsNotConstantAcrossCalls(): void
    {
        // 24 次全同的概率在真随机下可忽略；若实现被改成常量或递增种子，本条必红
        $seen = [];
        for ($i = 0; $i < 24; $i++) {
            $seen[Pusher::genMsgId()] = true;
        }

        $this->assertCount(24, $seen, 'msg_id 必须每次不同，否则服务端幂等会把第二次发送静默吞掉');
    }

    public function testValidatePushGeneratesMsgIdWhenBlank(): void
    {
        $r = Pusher::validatePush([
            'target_type' => 'uid',
            'target' => '1001',
        ], Pusher::PAYLOAD_MAX_MIRROR);

        $this->assertTrue($r['ok'], implode(' / ', $r['errors']));
        $this->assertTrue($r['msg_id_generated']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $r['job']['msg_id']);
    }

    public function testValidatePushKeepsExplicitMsgId(): void
    {
        $r = Pusher::validatePush([
            'target_type' => 'uid',
            'target' => '1001',
            'msg_id' => '  m-fixed-1  ',
        ], Pusher::PAYLOAD_MAX_MIRROR);

        $this->assertTrue($r['ok'], implode(' / ', $r['errors']));
        $this->assertFalse($r['msg_id_generated']);
        $this->assertSame('m-fixed-1', $r['job']['msg_id'], 'msg_id 应去首尾空白后原样保留');
    }

    public function testValidatePushRejectsOverlongMsgId(): void
    {
        $r = Pusher::validatePush([
            'target_type' => 'uid',
            'target' => '1001',
            'msg_id' => str_repeat('x', Pusher::MSG_ID_MAX_LEN + 1),
        ], Pusher::PAYLOAD_MAX_MIRROR);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('msg_id', implode(' ', $r['errors']));
    }

    /* =====================================================================
     | ③ target_type / offline_mode 归一
     ===================================================================== */

    public function testNormalizeTargetTypeAcceptsTheThreeDeclaredValues(): void
    {
        $this->assertSame(Push::TARGET_UID, Pusher::normalizeTargetType('UID'));
        $this->assertSame(Push::TARGET_DEVICE, Pusher::normalizeTargetType('  device  '));
        $this->assertSame(Push::TARGET_CLIENT, Pusher::normalizeTargetType('client'));
    }

    public function testNormalizeTargetTypeFallsBackToEmptyNotToUid(): void
    {
        // 这条是本类的核心纪律：**不学服务端的 `Push::normalizeTargetType()` 回落 uid**。
        // 一旦有人「对齐」成回落 uid，拼错的 target_type 会静默变成按 uid 推送。
        $this->assertSame('', Pusher::normalizeTargetType('topic'));
        $this->assertSame('', Pusher::normalizeTargetType(''));
        $this->assertSame('', Pusher::normalizeTargetType(null));
        $this->assertSame('', Pusher::normalizeTargetType(123));
        $this->assertSame('', Pusher::normalizeTargetType(['uid']));
    }

    public function testTopicIsNotAValidTargetType(): void
    {
        // 主题广播在服务端没有 HTTP 入口（Push::enqueueTopic()），UI 若能选就是虚假功能
        $this->assertNotContains('topic', Pusher::TARGET_TYPES);
        $this->assertSame('', Pusher::normalizeTargetType('topic'));
    }

    public function testNormalizeOfflineModeDistinguishesBlankFromInvalid(): void
    {
        // 空串是**合法值**（= 取服务端默认），与 `null`（非法）必须区分开
        $this->assertSame('', Pusher::normalizeOfflineMode(null));
        $this->assertSame('', Pusher::normalizeOfflineMode(''));
        $this->assertSame('', Pusher::normalizeOfflineMode('   '));
        $this->assertSame(Push::MODE_DROP, Pusher::normalizeOfflineMode('drop'));
        $this->assertSame(Push::MODE_QUEUE, Pusher::normalizeOfflineMode(' QUEUE '));

        $this->assertNull(Pusher::normalizeOfflineMode('keep'));
        $this->assertNull(Pusher::normalizeOfflineMode(1));
        $this->assertNull(Pusher::normalizeOfflineMode([]));
    }

    public function testValidatePushReportsInvalidOfflineModeInsteadOfSilentlyDefaulting(): void
    {
        $r = Pusher::validatePush([
            'target_type' => 'uid',
            'target' => '1001',
            'offline_mode' => 'keep',
        ], Pusher::PAYLOAD_MAX_MIRROR);

        $this->assertFalse($r['ok'], '未知 offline_mode 必须显式报错，不得静默回落默认值');
        $this->assertStringContainsString('offline_mode', implode(' ', $r['errors']));
    }

    /* =====================================================================
     | ④ payload 上限
     ===================================================================== */

    public function testPayloadMaxOnlyAllowsTightening(): void
    {
        $this->assertSame(Pusher::PAYLOAD_MAX_MIRROR, Pusher::payloadMax());
        $this->assertSame(Pusher::PAYLOAD_MAX_MIRROR, Pusher::payloadMax(0));
        $this->assertSame(Pusher::PAYLOAD_MAX_MIRROR, Pusher::payloadMax(-9));
        $this->assertSame(1024, Pusher::payloadMax(1024));
        $this->assertSame(
            Pusher::PAYLOAD_MAX_MIRROR,
            Pusher::payloadMax(Pusher::PAYLOAD_MAX_MIRROR + 1),
            '调大必须被夹回镜像值 —— 放开只会产出「已受理但被服务端静默丢弃」的载荷'
        );
    }

    public function testValidatePushRejectsPayloadOverLimit(): void
    {
        $r = Pusher::validatePush([
            'target_type' => 'uid',
            'target' => '1001',
            'payload' => ['blob' => str_repeat('a', 200)],
        ], 64);

        $this->assertFalse($r['ok']);
        $this->assertGreaterThan(64, $r['bytes']);
        $this->assertStringContainsString('静默丢弃', implode(' ', $r['errors']),
            '报错必须说明「服务端会静默丢弃」这一事实，否则用户会以为后台在无理由拦截');
    }

    public function testValidatePushAcceptsPayloadExactlyAtLimit(): void
    {
        $payload = ['k' => 'v'];
        $exact = Pusher::payloadBytes($payload);

        $r = Pusher::validatePush([
            'target_type' => 'uid',
            'target' => '1001',
            'payload' => $payload,
        ], $exact);

        // 边界是**闭区间**：<= 上限放行。服务端判据是 `$bodySize > $maxBody` 才拦，
        // 故后台必须是 `>` 而不是 `>=`，否则恰好等长的载荷会被后台挡住、而服务端本会接受。
        $this->assertTrue($r['ok'], '恰好等于上限必须放行（服务端判据是 > 上限才丢）');
    }

    public function testValidatePushTreatsNullAndEmptyStringPayloadAsEmptyArray(): void
    {
        foreach ([null, ''] as $blank) {
            $r = Pusher::validatePush([
                'target_type' => 'uid',
                'target' => '1001',
                'payload' => $blank,
            ], Pusher::PAYLOAD_MAX_MIRROR);

            $this->assertTrue($r['ok']);
            $this->assertSame([], $r['job']['payload']);
        }
    }

    public function testValidatePushRejectsScalarPayload(): void
    {
        $r = Pusher::validatePush([
            'target_type' => 'uid',
            'target' => '1001',
            'payload' => 'not-an-object',
        ], Pusher::PAYLOAD_MAX_MIRROR);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('payload', implode(' ', $r['errors']));
    }

    /* =====================================================================
     | payload 双形态（数组 / JSON 字符串）
     ===================================================================== */

    public function testDecodePayloadAcceptsBothArrayAndJsonStringIdentically(): void
    {
        $expected = ['title' => 'hi', 'n' => 1];

        $fromArray = Pusher::decodePayload($expected);
        $fromString = Pusher::decodePayload('{"title":"hi","n":1}');

        $this->assertTrue($fromArray['ok']);
        $this->assertTrue($fromString['ok']);
        $this->assertSame($expected, $fromArray['value']);
        $this->assertSame(
            $expected,
            $fromString['value'],
            '表单 textarea 的字符串形态与前端 JSON.parse 后的数组形态必须产出同一个载荷'
        );
    }

    public function testDecodePayloadTreatsBlankAsEmptyArray(): void
    {
        foreach ([null, '', []] as $blank) {
            $r = Pusher::decodePayload($blank);
            $this->assertTrue($r['ok']);
            $this->assertSame([], $r['value']);
        }
    }

    public function testDecodePayloadReportsJsonSyntaxErrors(): void
    {
        $r = Pusher::decodePayload('{"a": ');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('不是合法 JSON', $r['msg']);
        $this->assertSame([], $r['value']);
    }

    public function testDecodePayloadRejectsValidJsonThatIsNotAnObjectOrArray(): void
    {
        // `"123"` / `"true"` / `"null"` / `"\"str\""` 都是合法 JSON，但服务端
        // handlePush 对非数组 payload 直接回 4000 —— 后台必须先拦下，否则白跑一趟且
        // 用户看到的错误来自「主项目拒绝」而不是「你填错了」
        foreach (['123', 'true', 'null', '"a string"'] as $scalar) {
            $r = Pusher::decodePayload($scalar);
            $this->assertFalse($r['ok'], '合法但非对象的 JSON 应被拒：' . $scalar);
            $this->assertStringContainsString('必须是 JSON 对象或数组', $r['msg']);
        }
    }

    public function testDecodePayloadRejectsOtherTypes(): void
    {
        foreach ([42, 3.14, true] as $other) {
            $r = Pusher::decodePayload($other);
            $this->assertFalse($r['ok']);
            $this->assertStringContainsString('payload', $r['msg']);
        }
    }

    public function testValidatePushAcceptsJsonStringPayloadAndCountsBytesOnDecodedForm(): void
    {
        // 字节数必须按**解码后的载荷**算，而不是按原始字符串算 ——
        // 后者会把转义后的引号也计进去，与服务端判据不一致。
        $json = '{"title":"hi"}';
        $r = Pusher::validatePush([
            'target_type' => 'uid',
            'target' => '1001',
            'payload' => $json,
        ], Pusher::PAYLOAD_MAX_MIRROR);

        $this->assertTrue($r['ok'], implode(' / ', $r['errors']));
        $this->assertSame(strlen($json), $r['bytes']);
        $this->assertSame(['title' => 'hi'], $r['job']['payload']);
    }

    /* =====================================================================
     | target 校验
     ===================================================================== */

    public function testValidatePushRequiresTarget(): void
    {
        foreach ([null, '', '   '] as $blank) {
            $r = Pusher::validatePush([
                'target_type' => 'uid',
                'target' => $blank,
            ], Pusher::PAYLOAD_MAX_MIRROR);

            $this->assertFalse($r['ok']);
            $this->assertStringContainsString('target 不能为空', implode(' ', $r['errors']));
        }
    }

    public function testValidatePushRejectsTargetWithControlCharacter(): void
    {
        // 含换行的 target 会被直接拼进 Redis 键 → 日志与页面出现无法解释的换行/截断
        $r = Pusher::validatePush([
            'target_type' => 'uid',
            'target' => "1001\n1002",
        ], Pusher::PAYLOAD_MAX_MIRROR);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('控制字符', implode(' ', $r['errors']));
    }

    public function testValidatePushTargetLimitIsBytesAndMatchesSessionInspector(): void
    {
        // 与「会话查询」共用同一口径：128 是**字节**上限，故 43 个汉字（129 字节）必须被拒，
        // 而 42 个汉字（126 字节）必须放行。按字符算会放过前者 → 前台能提交、会话页却查不到。
        $ok = str_repeat('汉', 42);
        $tooLong = str_repeat('汉', 43);
        $this->assertLessThanOrEqual(128, strlen($ok));
        $this->assertGreaterThan(128, strlen($tooLong));

        $r1 = Pusher::validatePush(['target_type' => 'uid', 'target' => $ok], Pusher::PAYLOAD_MAX_MIRROR);
        $r2 = Pusher::validatePush(['target_type' => 'uid', 'target' => $tooLong], Pusher::PAYLOAD_MAX_MIRROR);

        $this->assertTrue($r1['ok'], '126 字节的 target 必须放行');
        $this->assertFalse($r2['ok'], '129 字节的 target 必须被拒（判据是字节而非字符）');
    }

    public function testValidatePushTrimsTargetAndReportsAllErrorsAtOnce(): void
    {
        // 一次请求把所有错误都回给用户，而不是「改一个报一个」
        $r = Pusher::validatePush([
            'target_type' => 'nope',
            'target' => '  ',
            'offline_mode' => 'nope',
            'payload' => 'nope',
        ], Pusher::PAYLOAD_MAX_MIRROR);

        $this->assertFalse($r['ok']);
        $this->assertGreaterThanOrEqual(4, count($r['errors']), '四类错误应一次性全部返回');
    }

    /* =====================================================================
     | 模板校验
     ===================================================================== */

    public function testValidateTemplateDoesNotRequireTargetNorMsgId(): void
    {
        // 模板不存 target（防「发到旧目标」）也不存 msg_id（防被幂等静默吞掉）
        $r = Pusher::validateTemplate([
            'name' => '  通知  ',
            'target_type' => 'device',
            'payload' => ['type' => 'notice'],
            'offline_mode' => 'queue',
            'remark' => 'warn 级通知',
        ], Pusher::PAYLOAD_MAX_MIRROR);

        $this->assertTrue($r['ok'], implode(' / ', $r['errors']));
        $this->assertSame('通知', $r['row']['name']);
        $this->assertSame(Push::TARGET_DEVICE, $r['row']['target_type']);
        $this->assertSame(Push::MODE_QUEUE, $r['row']['offline_mode']);
        $this->assertArrayNotHasKey('target', $r['row']);
        $this->assertArrayNotHasKey('msg_id', $r['row']);
    }

    public function testValidateTemplateRequiresName(): void
    {
        $r = Pusher::validateTemplate([
            'target_type' => 'uid',
            'payload' => [],
        ], Pusher::PAYLOAD_MAX_MIRROR);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('name 不能为空', implode(' ', $r['errors']));
    }

    public function testValidateTemplateStillEnforcesPayloadLimitAndEnums(): void
    {
        // 模板路径也走 core()，故 payload 超限与离线策略非法同样必须拦下 ——
        // 否则会存进一个「永远发不出去」的模板
        $over = Pusher::validateTemplate([
            'name' => 'x',
            'target_type' => 'uid',
            'payload' => ['blob' => str_repeat('a', 300)],
        ], 64);
        $this->assertFalse($over['ok']);

        $badEnum = Pusher::validateTemplate([
            'name' => 'x',
            'target_type' => 'topic',
            'payload' => [],
        ], Pusher::PAYLOAD_MAX_MIRROR);
        $this->assertFalse($badEnum['ok']);
        $this->assertStringContainsString('target_type', implode(' ', $badEnum['errors']));
    }

    public function testValidateTemplateRejectsOverlongNameAndRemark(): void
    {
        $r = Pusher::validateTemplate([
            'name' => str_repeat('n', Pusher::TEMPLATE_NAME_MAX_LEN + 1),
            'target_type' => 'uid',
            'payload' => [],
            'remark' => str_repeat('r', Pusher::TEMPLATE_REMARK_MAX_LEN + 1),
        ], Pusher::PAYLOAD_MAX_MIRROR);

        $this->assertFalse($r['ok']);
        $errs = implode(' ', $r['errors']);
        $this->assertStringContainsString('name 长度超过', $errs);
        $this->assertStringContainsString('remark 长度超过', $errs);
    }

    /* =====================================================================
     | 文案与标签
     ===================================================================== */

    public function testNotesAreDeliveredFromBackendAndMentionTheSilentBehaviours(): void
    {
        // 这三条是「响应里读不出来」的语义，必须由后端下发且说清「静默」二字 ——
        // 少了任何一条，UI 就有可能编出「已去重」这类假事实。
        $this->assertStringContainsString('去重', Pusher::DEDUP_NOTE);
        $this->assertStringContainsString('无法', Pusher::DEDUP_NOTE);
        $this->assertStringContainsString('静默丢弃', Pusher::PAYLOAD_DROP_NOTE);
        $this->assertStringContainsString('已入队', Pusher::ACCEPTED_NOTE);
        $this->assertStringContainsString('主题广播', Pusher::NO_TOPIC_NOTE);
        $this->assertStringContainsString('不保存目标值', Pusher::TEMPLATE_NOTE);
    }

    public function testLabelsCoverEveryEnumValue(): void
    {
        foreach (Pusher::TARGET_TYPES as $type) {
            $this->assertNotSame($type, Pusher::labelOfTargetType($type), 'target_type=' . $type . ' 缺中文说明');
        }
        foreach (Pusher::OFFLINE_MODES as $mode) {
            // 空串也是合法枚举值，故这里不能断言「标签 ≠ 取值」（两者都是空串）
            $this->assertNotSame('', Pusher::labelOfOfflineMode($mode), 'offline_mode=' . $mode . ' 缺中文说明');
        }
        $this->assertSame('unknown-xyz', Pusher::labelOfTargetType('unknown-xyz'), '未知值应回落原样便于排查');
    }
}
