<?php

declare(strict_types=1);

namespace tests\Unit;

use app\service\ConfigViewer;
use PHPUnit\Framework\TestCase;

/**
 * 2.0 §2.1 配置查看（脱敏）的服务层契约。
 *
 * 钉的是三条硬约束：
 * 1. **白名单 fail-closed** —— 不在 {@see ConfigViewer::GROUPS} 的键不进输出；
 * 2. **密钥不回显** —— SECRET_KEYS 的 value 必须经 SecretMasker，且
 *    「空值 = 未配置」不打码（打码会制造已配置假象）；
 * 3. **parseEnv 容忍现实 .env 形态** —— CRLF / export / 引号 / 行内注释，
 *    非法行跳过不整页失败。
 *
 * 路径三关走 `resolveEnvFile()`（private + 依赖 `config()`），不在纯单测里
 * 引导框架；「.env 不存在」由线上 `view()` 的 hint 分支兜底。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */
final class ConfigViewerTest extends TestCase
{
    /* =====================================================================
     | parseEnv
     ===================================================================== */

    public function testParseEnvHandlesCrlfExportQuotesAndComments(): void
    {
        $raw = "# comment\r\n"
            . "export APP_ENV=prod\r\n"
            . "REDIS_HOST=127.0.0.1\r\n"
            . "AUTH_SECRET='abcdefghijklmnopqrstuvwxyz'\r\n"
            . "APP_NAME=\"Gateway Push\" # trailing\r\n"
            . "LOG_LEVEL = debug \r\n"
            . "NOT_A_PAIR\r\n"
            . "9BAD=x\r\n"
            . "\r\n";

        $kv = ConfigViewer::parseEnv($raw);

        $this->assertSame('prod', $kv['APP_ENV'], 'export 前缀应剥掉');
        $this->assertSame('127.0.0.1', $kv['REDIS_HOST']);
        $this->assertSame('abcdefghijklmnopqrstuvwxyz', $kv['AUTH_SECRET'], '成对单引号剥一层');
        $this->assertSame('Gateway Push', $kv['APP_NAME'], '双引号内含空格');
        $this->assertSame('debug', $kv['LOG_LEVEL'], '键值两侧空白 trim');
        $this->assertArrayNotHasKey('NOT_A_PAIR', $kv, '无 = 的行跳过');
        $this->assertArrayNotHasKey('9BAD', $kv, '非法键名跳过');
    }

    public function testParseEnvKeepsHashInsideQuotes(): void
    {
        $kv = ConfigViewer::parseEnv("TOKEN='ab#cd'\n");
        $this->assertSame('ab#cd', $kv['TOKEN'], '引号内的 # 不得当行内注释截断');
    }

    public function testParseEnvEmptyInputYieldsEmptyArray(): void
    {
        $this->assertSame([], ConfigViewer::parseEnv(''));
        $this->assertSame([], ConfigViewer::parseEnv("# only comment\n"));
    }

    /* =====================================================================
     | renderGroups：白名单 + 脱敏 + configured 语义
     ===================================================================== */

    /**
     * @param array<int|string, mixed> $groups
     *
     * @return array<string, mixed>
     */
    private function findItem(array $groups, string $key): array
    {
        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                if ($item['key'] === $key) {
                    return $item;
                }
            }
        }

        $this->fail('白名单投影里找不到键 ' . $key);
    }

    public function testWhitelistIsClosedToConfiguredKeysOnly(): void
    {
        $groups = ConfigViewer::renderGroups([
            'APP_ENV' => 'prod',
            'MY_NEW_SECRET_KEY' => 's3cr3t-should-never-appear',
            'SOME_UNKNOWN' => 'x',
        ]);

        $flat = '';
        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $flat .= $item['key'] . "\n" . $item['value'] . "\n";
            }
        }

        $this->assertStringContainsString('APP_ENV', $flat);
        $this->assertStringNotContainsString('MY_NEW_SECRET_KEY', $flat,
            '★ 非白名单键出现在输出 —— 误加进 .env 的新密钥会被本页带出去');
        $this->assertStringNotContainsString('s3cr3t-should-never-appear', $flat);
        $this->assertStringNotContainsString('SOME_UNKNOWN', $flat);
    }

    public function testSecretKeysAreMaskedNeverRaw(): void
    {
        $raw = 'abcdefghijklmnopqrstuvwxyz012345';
        $groups = ConfigViewer::renderGroups([
            'AUTH_SECRET' => $raw,
            'API_SECRET' => $raw,
            'INTERNAL_SECRET' => $raw,
            'REDIS_PASSWORD' => $raw,
        ]);

        foreach (ConfigViewer::SECRET_KEYS as $key) {
            $item = $this->findItem($groups, $key);
            $this->assertTrue($item['secret'], $key . ' 应标 secret');
            $this->assertTrue($item['masked'], $key . ' 应标 masked');
            $this->assertTrue($item['configured']);
            $this->assertNotSame($raw, $item['value'], '★ ' . $key . ' 回显了原文');
            $this->assertStringContainsString('****', $item['value']);
            $this->assertStringNotContainsString('abcdefgh', $item['value'], '前缀泄露');
            $this->assertStringNotContainsString('012345', $item['value'], '后缀泄露');
        }
    }

    public function testEmptySecretIsUnconfiguredNotMasked(): void
    {
        $groups = ConfigViewer::renderGroups([
            'AUTH_SECRET' => '',
            'APP_ENV' => '',
        ]);

        $secret = $this->findItem($groups, 'AUTH_SECRET');
        $this->assertFalse($secret['configured'], '空密钥 = 未配置（与 SecretMasker 口径一致）');
        $this->assertSame('', $secret['value'], '空值不打码，打码反而制造已配置假象');

        $plain = $this->findItem($groups, 'APP_ENV');
        $this->assertFalse($plain['configured'], '空串非密钥同样按未设置展示');
        $this->assertSame('', $plain['value']);
    }

    public function testMissingKeyIsNotConfigured(): void
    {
        $groups = ConfigViewer::renderGroups([]);

        $item = $this->findItem($groups, 'REDIS_HOST');
        $this->assertFalse($item['configured']);
        $this->assertSame('', $item['value']);
        $this->assertFalse($item['secret']);
        $this->assertFalse($item['masked']);
    }

    public function testConfiguredNonEmptyPlainKeyShowsRawValue(): void
    {
        $groups = ConfigViewer::renderGroups(['REDIS_HOST' => '10.0.0.5']);
        $item = $this->findItem($groups, 'REDIS_HOST');

        $this->assertTrue($item['configured']);
        $this->assertSame('10.0.0.5', $item['value']);
        $this->assertFalse($item['masked']);
    }

    public function testGroupOrderAndEveryWhitelistedKeyAppears(): void
    {
        $groups = ConfigViewer::renderGroups([]);

        $names = array_column($groups, 'name');
        $expected = array_keys(ConfigViewer::GROUPS);
        $this->assertSame($expected, $names, '分组顺序即 UI 顺序，不得重排');

        $seen = [];
        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $seen[] = $item['key'];
            }
        }

        $want = [];
        foreach (ConfigViewer::GROUPS as $keys) {
            foreach ($keys as $key) {
                $want[] = $key;
            }
        }
        $this->assertSame($want, $seen, '每行都必须来自白名单，且顺序稳定');
    }

    public function testNotesExplainNoHotReloadAndMasking(): void
    {
        $notes = ConfigViewer::notes();
        $joined = implode("\n", $notes);

        $this->assertStringContainsString('无热重载', $joined);
        $this->assertStringContainsString('脱敏', $joined);
        $this->assertNotEmpty($notes);
    }

    public function testViewReturnsStructuredShapeEvenWhenEnvMissing(): void
    {
        // project_root 走 config() 默认 '..'；相对 admin CWD 下若 .env 缺失，
        // 必须仍返回完整形状（ok=false + notes），而不是抛异常。
        $view = (new ConfigViewer())->view();

        $this->assertArrayHasKey('ok', $view);
        $this->assertArrayHasKey('hint', $view);
        $this->assertArrayHasKey('groups', $view);
        $this->assertArrayHasKey('notes', $view);
        $this->assertArrayHasKey('mtime_text', $view);

        if (!$view['ok']) {
            $this->assertSame([], $view['groups'], '失败时不得吐半截分组');
            $this->assertNotSame('', $view['hint'], '失败必须有可读 hint');
            $this->assertStringNotContainsString('..', $view['file'], '失败时不回显未收口路径');
        } else {
            $this->assertNotSame('', $view['file']);
            $this->assertNotEmpty($view['groups']);
        }
    }
}
