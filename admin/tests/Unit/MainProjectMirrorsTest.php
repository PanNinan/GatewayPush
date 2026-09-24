<?php
/**
 * admin 单测 —— MainProjectMirrorsTest。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace tests\Unit;

use app\service\ActionCatalog;
use app\service\Pusher;
use PHPUnit\Framework\TestCase;

/**
 * **手写镜像常量 vs 主项目配置默认值** 的对照测试。
 *
 * ## 为什么需要这个文件
 *
 * 后台与主项目是**两个进程、两套 .env**，且本项目明确禁止后台解析主项目的 `.env`
 * （`config/gateway_push.php`：「后台不得自行解析 .env」，唯一真源走 CLI 的 JSON 契约）。
 * 于是有 6 个数值只能**手写镜像**进来。手写副本的失效方式是**静默的**：
 * 主项目把 `PUSH_PAYLOAD_MAX` 改成 8192，后台仍按 4096 拦 —— 用户提交 6KB 载荷会被后台
 * 无理由拒绝，而服务端本可以接受。
 *
 * 本文件把每一个镜像都钉到 `../config/*.php` 里的 `Env::int('KEY', <默认值>)` 上。
 * **只对照默认值，不读 `.env`** —— 理由：
 * - `.env` 是**部署态**，不同机器不同值，读它会把单元测试变成环境相关的；
 * - `.env` 覆盖源文件默认值这件事，由 `tests/Manual/p3_acceptance.php` 在同机做一次在线对照
 *   （它能同时看到 `../.env` 与后台的生效值）。
 *
 * 两者分工：**本文件保证「代码默认值同源」，验收脚本保证「部署值也同源」。**
 *
 * ## 反向也断一条
 *
 * 每个镜像都必须**被真正用到**。若哪天某个镜像常量没人引用了（例如改用配置读取），
 * 它就从「约束」退化成了「注释」，本文件的 `testEveryMirrorIsActuallyConsumed`
 * 会指出这一点。
 */
final class MainProjectMirrorsTest extends TestCase
{
    private const APP_CONFIG = __DIR__ . '/../../../config/app.php';

    private const BUSINESS_CONFIG = __DIR__ . '/../../../config/business.php';

    /* =====================================================================
     | 逐个镜像对照
     ===================================================================== */

    public function testPayloadMaxMirrorMatchesMainProjectDefault(): void
    {
        $this->assertSame(
            self::envDefault(self::APP_CONFIG, 'PUSH_PAYLOAD_MAX'),
            Pusher::PAYLOAD_MAX_MIRROR,
            'Pusher::PAYLOAD_MAX_MIRROR 与主项目 config/app.php 的 PUSH_PAYLOAD_MAX 默认值不一致'
        );
    }

    public function testIdempotentTtlMirrorMatchesMainProjectDefault(): void
    {
        $this->assertSame(
            self::envDefault(self::APP_CONFIG, 'PUSH_IDEMPOTENT_TTL'),
            Pusher::IDEMPOTENT_TTL_MIRROR,
            'Pusher::IDEMPOTENT_TTL_MIRROR 与主项目 PUSH_IDEMPOTENT_TTL 默认值不一致'
        );
    }

    public function testOfflineMaxMirrorMatchesMainProjectDefault(): void
    {
        $this->assertSame(
            self::envDefault(self::APP_CONFIG, 'PUSH_OFFLINE_MAX'),
            Pusher::OFFLINE_MAX_MIRROR,
            'Pusher::OFFLINE_MAX_MIRROR 与主项目 PUSH_OFFLINE_MAX 默认值不一致'
        );
    }

    public function testActionWaitMirrorMatchesMainProjectDefault(): void
    {
        $this->assertSame(
            self::envDefault(self::APP_CONFIG, 'API_ACTION_WAIT_MS'),
            ActionCatalog::WAIT_MS_MIRROR,
            'ActionCatalog::WAIT_MS_MIRROR 与主项目 API_ACTION_WAIT_MS 默认值不一致'
        );
    }

    public function testResultTtlMirrorMatchesMainProjectDefault(): void
    {
        // 这条真源在 config/business.php（不在 app.php）—— 写错文件会静默退化成「找不到就跳过」
        $this->assertSame(
            self::envDefault(self::BUSINESS_CONFIG, 'ACTION_RESULT_TTL'),
            ActionCatalog::RESULT_TTL_MIRROR,
            'ActionCatalog::RESULT_TTL_MIRROR 与主项目 ACTION_RESULT_TTL 默认值不一致'
        );
    }

    /* =====================================================================
     | 客户端超时必须容得下服务端的等待窗
     ===================================================================== */

    public function testClientTimeoutExceedsServerActionWaitWindow(): void
    {
        // `GatewayPushClient` 的默认超时 8s 必须 > `API_ACTION_WAIT_MS`(6000ms)，
        // 否则后台会**先于服务端**放弃，把「超窗降级为 202」误报成「连接失败」——
        // 两个都是「没拿到结果」，但处理方式完全相反（前者该补查，后者该人工确认）。
        $client = new \ReflectionClass(\app\service\GatewayPushClient::class);
        $ctor = $client->getConstructor();
        $this->assertNotNull($ctor);

        $timeout = $ctor->getParameters()[2]->getDefaultValue();
        $this->assertIsFloat($timeout);
        $this->assertGreaterThan(
            ActionCatalog::WAIT_MS_MIRROR / 1000,
            $timeout,
            'GatewayPushClient 默认超时必须大于 API_ACTION_WAIT_MS，否则会误报超时为连接失败'
        );
    }

    /* =====================================================================
     | 反向：每个镜像都必须被真正消费
     ===================================================================== */

    public function testEveryMirrorIsActuallyConsumed(): void
    {
        $mirrors = [
            'Pusher::PAYLOAD_MAX_MIRROR',
            'Pusher::IDEMPOTENT_TTL_MIRROR',
            'Pusher::OFFLINE_MAX_MIRROR',
            'ActionCatalog::WAIT_MS_MIRROR',
            'ActionCatalog::RESULT_TTL_MIRROR',
        ];

        $files = self::phpFilesIn(dirname(__DIR__, 2) . '/app');
        $src = '';
        foreach ($files as $file) {
            $src .= (string)file_get_contents($file) . "\n";
        }

        foreach ($mirrors as $label) {
            $const = substr($label, (int)strrpos($label, ':') + 1);

            // 计数而非「是否出现」：常量**声明**本身必然出现 1 次，
            // 故 >= 2 才意味着「除声明外还有人在读它」。
            // 用裸常量名而不是 FQCN —— 类内引用一律写作 `self::`，匹配 FQCN 永远匹配不到。
            $count = substr_count($src, $const);

            $this->assertGreaterThanOrEqual(
                2,
                $count,
                $label . ' 在 app/ 下只出现了 ' . $count . ' 次（1 次是它自己的声明）。'
                . '它已从「约束」退化成「注释」—— 要么接回校验/文案链路，要么删掉。'
            );
        }
    }

    /* =====================================================================
     | 工具
     ===================================================================== */

    /**
     * 从主项目配置里取 `Env::int('KEY', <默认值>)` 的第 2 个参数。
     *
     * 找不到时**直接失败**（而不是返回 0 让断言以「0 !== 4096」的形式报警）：
     * 「键被改名/删除」与「默认值变了」是两类完全不同的故障，
     * 混成同一条失败信息会让人去改错地方（本文件开头那段注释说的就是这件事）。
     *
     * @param string $file 配置文件绝对路径
     * @param string $key  环境变量名
     */
    private static function envDefault(string $file, string $key): int
    {
        $src = (string)file_get_contents($file);

        if (preg_match(
            "/Env::int\\(\\s*'" . preg_quote($key, '/') . "'\\s*,\\s*(-?\\d+)\\s*\\)/",
            $src,
            $m
        ) !== 1) {
            self::fail(sprintf(
                '在 %s 里找不到 Env::int(%s, <默认值>) —— '
                . '要么该配置项被改名/移走了（请更新本测试与被测镜像），'
                . '要么主项目改了取值写法（不再用 Env::int）。',
                basename($file),
                $key
            ));
        }

        return (int)$m[1];
    }

    /** @return list<string> */
    private static function phpFilesIn(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }
        sort($out);

        return $out;
    }
}
