<?php
/**
 * 运维动作转签的契约测试（后台侧，P4）
 *
 * ---------------------------------------------------------------------
 * 这一组端点与之前所有端点的本质差别
 * ---------------------------------------------------------------------
 * P0~P3 的后台**全是只读**（或只写后台自己的 MySQL）。
 * P4 的 `OpsActionController` 是后台**第一个改变主项目运行状态的入口**：
 * 它会让别人的连接掉线、让别人的 Token 失效、解绑别人的设备 ——
 * 且这三件事**都不可撤销**。
 *
 * 因此这里钉的不是「功能对不对」，而是**四道边界**：
 *
 * 1. **权限**：4 个节点必须在 `install.php` 里登记，且**一个都不在只读角色**里。
 *    漏登记 ⇒ 非超管 403（看不出问题）；错放进只读角色 ⇒ 只读账号能踢人（静默提权）。
 * 2. **鉴权**：控制器必须 `disableDefaultRoute` —— 否则默认路径
 *    `/api/ops-action/kick` 是**零鉴权**的踢任意连接接口。
 * 3. **明文 token 不外泄**：`revoke` 只能收明文，而明文是本模块最敏感的数据。
 *    它**只可以**出现在那一次 HTTP 调用里，不得进日志 / 审计 / 响应。
 * 4. **语义如实**：`force-offline` 必须是 revoke → kick 顺序，且没给 token 时
 *    必须标记 `partial`（只做到一半），不许假装完成了「禁止重连」。
 *
 * ⚠ 与**主项目**的 `tests/Unit/OpsActionContractTest.php` 同名不同物：
 *    那边守 `config/actions.php` 的 `channels` 声明，这边守后台的转签与权限。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace Tests\Unit;

use app\service\Auditor;
use app\service\OpsAction;
use PHPUnit\Framework\TestCase;

class OpsActionContractTest extends TestCase
{
    /** 5 个运维节点别名（与 install.php 的 $nodeSpecs 键一致） */
    private const OPS_NODES = ['ops.kick', 'ops.revoke', 'ops.unbind', 'ops.forceOffline', 'ops.purgeOffline'];

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /* =====================================================================
     | ① 权限节点
     ===================================================================== */

    public function testOpsNodesAreDeclaredInInstaller(): void
    {
        $src = $this->read('scripts/install.php');

        foreach (self::OPS_NODES as $alias) {
            $this->assertStringContainsString(
                "'" . $alias . "' =>",
                $src,
                '节点 ' . $alias . ' 未在 install.php 的 $nodeSpecs 登记'
            );
        }
    }

    /**
     * ★ 只读角色不得拿到任何一个运维节点
     */
    public function testViewerRoleHasNoOpsNodes(): void
    {
        $src = $this->read('scripts/install.php');

        preg_match('/\$viewerRules\s*=\s*\[(.*?)\];/s', $src, $m);
        $block = (string)($m[1] ?? '');
        $this->assertNotSame('', $block, '解析不到 $viewerRules');

        foreach (self::OPS_NODES as $alias) {
            $this->assertStringNotContainsString(
                "\$nodeIds['" . $alias . "']",
                $block,
                '★ 只读角色拿到了运维节点 ' . $alias . ' —— 只读账号能踢人，属静默提权'
            );
        }
    }

    public function testOperatorRoleHasAllOpsNodes(): void
    {
        $src = $this->read('scripts/install.php');

        preg_match('/\$operatorRules\s*=\s*array_merge\((.*?)\);/s', $src, $m);
        $block = (string)($m[1] ?? '');
        $this->assertNotSame('', $block, '解析不到 $operatorRules');

        foreach (self::OPS_NODES as $alias) {
            $this->assertStringContainsString(
                "\$nodeIds['" . $alias . "']",
                $block,
                '运维角色缺少节点 ' . $alias
            );
        }
    }

    /* =====================================================================
     | ② 路由与鉴权
     ===================================================================== */

    public function testControllerHasDefaultRouteDisabled(): void
    {
        $src = $this->read('config/route.php');

        $this->assertStringContainsString(
            'Route::disableDefaultRoute(OpsActionController::class);',
            $src,
            '★ OpsActionController 未禁用默认路由 —— /api/ops-action/kick 会额外暴露一个零鉴权的踢线接口'
        );
    }

    public function testFiveEndpointsAreDeclaredAsPost(): void
    {
        $src = $this->read('config/route.php');

        $expect = [
            '/ops-action/kick'          => 'kick',
            '/ops-action/revoke'        => 'revoke',
            '/ops-action/unbind'        => 'unbind',
            '/ops-action/force-offline' => 'forceOffline',
            '/ops-action/purge-offline' => 'purgeOffline',
        ];

        foreach ($expect as $path => $action) {
            $this->assertMatchesRegularExpression(
                "#Route::post\\('" . preg_quote($path, '#') . "',\\s*\\[OpsActionController::class,\\s*'" . $action . "'\\]\\)#",
                $src,
                '路由 POST ' . $path . ' → ' . $action . ' 缺失'
            );
        }
    }

    /* =====================================================================
     | ③ 明文 token 不外泄
     ===================================================================== */

    /**
     * ★ 明文 token 只可进入那一次转签调用，不得进日志 / 审计 / 响应
     */
    public function testPlainTokenIsNeverLoggedOrReturned(): void
    {
        $svc = $this->read('app/service/OpsAction.php');
        $ctl = $this->read('app/controller/api/OpsActionController.php');

        // 日志：OpsAction 里唯一的 Log 调用只写 state / code / request_id，不写 params
        $this->assertStringNotContainsString(
            'Log::',
            str_replace('Log::warning(', '', $svc),
            'OpsAction 出现了额外的日志调用 —— 逐个确认它没有把 params（含明文 token）写进去'
        );

        preg_match('/Log::warning\((.*?)\);/s', $svc, $m);
        $logCall = (string)($m[1] ?? '');
        $this->assertNotSame('', $logCall, '解析不到 OpsAction 的 Log::warning 调用');
        foreach (['$params', '$token'] as $leak) {
            $this->assertStringNotContainsString($leak, $logCall, '★ 日志里出现了 ' . $leak . ' —— 明文 token 会落盘');
        }

        // 响应：控制器不得把 $token 放进任何回执
        $this->assertStringNotContainsString(
            "'token' => \$token",
            $ctl,
            '★ 控制器把明文 token 放进了响应'
        );
        $this->assertStringNotContainsString(
            "'token' => \$body['token']",
            $ctl,
            '★ 控制器把明文 token 放进了响应'
        );
    }

    public function testRevokeAuditCarriesFingerprintOnly(): void
    {
        $ctl = $this->read('app/controller/api/OpsActionController.php');

        // revoke 的审计参数必须是 fingerprint，不是 token
        $this->assertStringContainsString(
            "'fingerprint' =>",
            $ctl,
            'revoke 的审计参数应只含指纹'
        );
        $this->assertStringNotContainsString(
            "'token' => \$token",
            $ctl,
            '★ 审计参数里出现了明文 token'
        );
    }

    public function testAuditorKnowsTheFourOpsActions(): void
    {
        foreach (['OPS_KICK', 'OPS_REVOKE', 'OPS_UNBIND', 'OPS_FORCE_OFFLINE'] as $const) {
            $this->assertTrue(
                defined(Auditor::class . '::ACTION_' . $const),
                'Auditor 缺少常量 ACTION_' . $const
            );
        }

        $actions = Auditor::ACTIONS;
        foreach (['ops.kick', 'ops.revoke', 'ops.unbind', 'ops.force_offline', 'ops.purge_offline'] as $name) {
            $this->assertContains($name, $actions, 'Auditor::ACTIONS 缺 ' . $name);
        }
    }

    /* =====================================================================
     | ④ 语义如实
     ===================================================================== */

    public function testForceOfflineRunsRevokeBeforeKick(): void
    {
        $ctl = $this->read('app/controller/api/OpsActionController.php');

        // 源码里的执行顺序 = 数组下标顺序：revoke 的 step 必须是 1
        $revokePos = strpos($ctl, "'action' => OpsAction::NAME_REVOKE");
        $kickPos   = strpos($ctl, "'action' => OpsAction::NAME_KICK");

        $this->assertNotFalse($revokePos, 'force-offline 里找不到 revoke 步骤');
        $this->assertNotFalse($kickPos, 'force-offline 里找不到 kick 步骤');
        $this->assertLessThan(
            $kickPos,
            $revokePos,
            '★ force-offline 的顺序错了：必须 revoke 先行。'
            . '反了时客户端会落在重连窗口内用同一 Token 重连成功，表现为「执行了却没效果」'
        );
    }

    public function testForceOfflineMarksPartialWhenTokenMissing(): void
    {
        $ctl = $this->read('app/controller/api/OpsActionController.php');

        $this->assertStringContainsString("'partial' => \$token === ''", $ctl);
        $this->assertStringContainsString("'partial_note' => \$token === '' ? OpsAction::NO_TOKEN_NOTE : ''", $ctl);
    }

    public function testCaveatsExistForEveryAction(): void
    {
        foreach ([OpsAction::NAME_KICK, OpsAction::NAME_REVOKE, OpsAction::NAME_UNBIND] as $name) {
            $this->assertArrayHasKey($name, OpsAction::CAVEATS, $name . ' 缺语义说明');
            $this->assertNotSame('', OpsAction::CAVEATS[$name]);
        }

        // 三条说明必须**各不相同** —— 复制粘贴会让「kick 不撤 Token」这类关键差异消失
        $uniq = array_unique(array_values(OpsAction::CAVEATS));
        $this->assertCount(count(OpsAction::CAVEATS), $uniq, 'CAVEATS 存在重复文案');

        $this->assertNotSame('', OpsAction::FORCE_OFFLINE_NOTE);
        $this->assertNotSame('', OpsAction::NO_TOKEN_NOTE);
    }

    public function testFingerprintMatchesMainProjectAlgorithm(): void
    {
        // 与主项目 Auth::tokenFingerprint() 同口径：sha256 前 32 位
        $token = 'plain.token.value';
        $this->assertSame(substr(hash('sha256', $token), 0, 32), OpsAction::fingerprint($token));
        $this->assertSame(32, strlen(OpsAction::fingerprint($token)));
    }

    private function read(string $relative): string
    {
        $path    = $this->root . '/' . $relative;
        $content = @file_get_contents($path);

        $this->assertNotFalse($content, '读不到文件：' . $path);

        return (string)$content;
    }
}
