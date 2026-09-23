<?php

declare(strict_types=1);

namespace tests\Unit;

use app\service\LogTailService;
use app\service\RoleProbeService;
use app\service\SecretMasker;
use PHPUnit\Framework\TestCase;

/**
 * P5 运维只读三件套的单元契约。
 *
 * 重点不是「功能能跑」，而是三道**安全 / 漂移**防线：
 * 1. LogTailService 的路径穿越防御（`../`、绝对路径、非法 role/date 全部拒绝）；
 * 2. LogTailService 的 role 白名单与主项目 RoleCatalog **同源**（防静默漂移）；
 * 3. SecretMasker 的脱敏口径（前 4 后 4 / 短值全掩 / 空值原样）。
 */
final class P5OpsServiceTest extends TestCase
{
    /* =====================================================================
     | SecretMasker
     ===================================================================== */

    public function testMaskKeepsFirstAndLastFour(): void
    {
        // 16 字符 → 前 4 + **** + 后 4 = 12 字符（不泄露原长）
        $this->assertSame('abcd****wxyz', SecretMasker::mask('abcdefghijklmnopqrstuvwxyz'));
        $this->assertSame('1234****7890', SecretMasker::mask('1234567890'));
    }

    public function testMaskShortValuesEntirely(): void
    {
        $this->assertSame('****', SecretMasker::mask('abc'));
        $this->assertSame('****', SecretMasker::mask('1234567'), '7 字符（<8）必须全掩');
        $this->assertSame('1234****5678', SecretMasker::mask('12345678'), '恰好 8 字符可以保留首尾各 4');
    }

    public function testMaskEmptyStaysEmpty(): void
    {
        $this->assertSame('', SecretMasker::mask(''), '空 = 未配置，打码反而制造「已配置」假象');
    }

    /* =====================================================================
     | LogTailService：路径穿越防御
     ===================================================================== */

    private function svc(): LogTailService
    {
        return new LogTailService(__DIR__ . '/fixtures/logs');
    }

    public function testRejectsTraversalRole(): void
    {
        $res = $this->svc()->tail('../config', '2026-09-24');
        $this->assertFalse($res['ok']);
        $this->assertSame('', $res['file'], '被拒的请求不得回显任何路径');
    }

    public function testRejectsBadDateFormat(): void
    {
        foreach (['2026-9-24', '20260924', '2026-09-24; rm', '../../../etc/passwd', ''] as $bad) {
            $res = $this->svc()->tail('api', $bad);
            $this->assertFalse($res['ok'], "date={$bad} 应被拒");
            $this->assertSame('', $res['file']);
        }
    }

    public function testRejectsUnknownRole(): void
    {
        $res = $this->svc()->tail('unknown-role', '2026-09-24');
        $this->assertFalse($res['ok']);
    }

    public function testMissingFileIsReportedAsNotFound(): void
    {
        $res = $this->svc()->tail('api', '1999-01-01');
        $this->assertFalse($res['ok']);
        $this->assertSame('日志文件不存在', $res['hint']);
        $this->assertSame([], $res['lines'], 'not_found 不得带出任何行');
    }

    public function testTailReadsLastLinesWithKeywordFilter(): void
    {
        $res = $this->svc()->tail('api', '2026-09-24', 3);
        $this->assertTrue($res['ok'], $res['hint']);
        // fixtures/logs/api_2026-09-24.log 有 5 行；只取最后 3 行
        $this->assertCount(3, $res['lines']);
        $this->assertStringContainsString('line5', end($res['lines']));

        $filtered = $this->svc()->tail('api', '2026-09-24', 200, 'WARN');
        $this->assertTrue($filtered['ok']);
        $this->assertCount(2, $filtered['lines'], '关键词过滤后只剩命中行');
        $this->assertSame(2, $filtered['matched']);
    }

    public function testLinesClampedToTailMax(): void
    {
        $this->assertSame(1, max(1, min(LogTailService::TAIL_MAX, 0)), '0 行夹到 1');
    }

    /**
     * ★ role 白名单与主项目 RoleCatalog 同源（防漂移）
     *
     * 主项目加角色后这里若不同步，运维会「看不到新角色的日志」且无任何报错 ——
     * 故把「RoleCatalog 里的角色都在白名单里」钉成测试。
     */
    public function testRoleWhitelistCoversMainProjectRoleCatalog(): void
    {
        // admin 是同仓子目录：admin/tests/Unit → 主项目根 = 上两级再上一级
        $src = (string)@file_get_contents(dirname(__DIR__, 2) . '/../src/Common/RoleCatalog.php');
        if ($src === '') {
            $this->markTestSkipped('主项目 RoleCatalog.php 不可读（独立部署形态）');
        }

        preg_match_all("/^\s+'([a-z_]+)'\s*=>\s*\[/m", $src, $m);
        $catalogRoles = array_values(array_unique($m[1]));
        $this->assertNotEmpty($catalogRoles, '解析 RoleCatalog 失败 —— 正则与主项目源码漂移，需更新');

        foreach ($catalogRoles as $role) {
            $this->assertContains(
                $role,
                LogTailService::ROLES,
                "主项目新增角色 {$role}，但 LogTailService::ROLES 白名单没跟上 —— 新角色日志会静默不可读"
            );
        }
    }

    /* =====================================================================
     | RoleProbeService：netstat 解析（纯函数）
     ===================================================================== */

    public function testParseNetstatWindowsShape(): void
    {
        $out = "\n  协议  本地地址          外部地址        状态\n"
            . "  TCP    0.0.0.0:8282           0.0.0.0:0              LISTENING       17872\n"
            . "  TCP    127.0.0.1:8290         127.0.0.1:5000         ESTABLISHED     14532\n"
            . "  TCP    127.0.0.1:8290         0.0.0.0:0              LISTENING       14532\n"
            . "  UDP    0.0.0.0:8283           *:*                                    17340\n"
            . "  UDP    127.0.0.1:1900         *:*                                    4652\n";

        $rows = RoleProbeService::parseNetstat($out);

        $this->assertSame(1, $rows[8282] ?? 0, 'TCP 只认 LISTENING —— ESTABLISHED 不得计入');
        $this->assertSame(1, $rows[8290] ?? 0, '同端口 LISTENING + ESTABLISHED 只算监听那 1 行');
        $this->assertSame(1, $rows[8283] ?? 0, '★ UDP 行没有 LISTENING 字样，必须照常计入');
        $this->assertArrayHasKey(1900, $rows, '不相关的 UDP 端口也会计入 —— 探测时按角色端口取');
    }

    public function testParseNetstatLinuxShape(): void
    {
        $out = "Active Internet connections (servers and established)\n"
            . "tcp        0      0 0.0.0.0:8282            0.0.0.0:*               LISTEN\n"
            . "tcp        0      0 127.0.0.1:1238          0.0.0.0:*               LISTEN\n"
            . "udp        0      0 0.0.0.0:8283            0.0.0.0:*\n";

        $rows = RoleProbeService::parseNetstat($out);

        $this->assertSame(1, $rows[8282] ?? 0);
        $this->assertSame(1, $rows[1238] ?? 0);
        $this->assertSame(1, $rows[8283] ?? 0);
    }

    public function testParseNetstatCountsDuplicates(): void
    {
        // 红线 ㊳：两套实例叠加 = 同端口两行 LISTENING
        $out = "  TCP    0.0.0.0:8282           0.0.0.0:0              LISTENING       1111\n"
            . "  TCP    0.0.0.0:8282           0.0.0.0:0              LISTENING       2222\n";
        $rows = RoleProbeService::parseNetstat($out);
        $this->assertSame(2, $rows[8282] ?? 0, '重复监听必须可被计数 —— 这是红线 ㊳ 的判定依据');
    }
}
