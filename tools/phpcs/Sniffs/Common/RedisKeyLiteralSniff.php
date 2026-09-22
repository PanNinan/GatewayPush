<?php
/**
 * Redis 键名禁止字面量：键名必须引用 `src/Common/RedisKeys.php`。
 *
 * 对应项目红线：所有 Redis 键名一律引用 RedisKeys，禁止字面量。
 * 见 AGENTS.md「高频红线 → 常量与键名」。
 * 违反的后果不只是风格问题：改键名时字面量会漏改，导致队列分裂
 * （表现为「请求成功但动作永不执行」）。
 *
 * 前缀取自 `RedisKeys` 每个常量的**精确字面量**（不是「首段+冒号」）。
 * 为什么不用更宽的首段形式：`action:` 这种宽前缀会撞上日志标签 —— 实测
 * `Logger::exception($e, 'action:' . $action)`（src/Business/ActionRunner.php:413）
 * 就曾被误报成键名字面量。宁可用精确值，也不要假阳性。
 * 新增键常量时要同步 `$prefixes`；`tests/Manual/phpcs_business_rules_check.php`
 * 会拿 RedisKeys 的常量做漂移自检。
 *
 * 为什么作用域写在嗅探器里而不是规则集：见 ForbiddenCallSniff 的同类说明 ——
 * 按文件路径引用嗅探器时，规则级的 include/exclude-pattern 会静默失效。
 */

namespace PHP_CodeSniffer\Standards\GatewayPush\Sniffs\Common;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

class RedisKeyLiteralSniff implements Sniff
{
    /**
     * 受禁的键字面量前缀（与 src/Common/RedisKeys.php 的常量一一对应）
     *
     * 本数组是**唯一真源**，自检脚本会读它做漂移校验。
     *
     * @var string[]
     */
    public $prefixes = [
        'session:',
        'heartbeat:',
        'uid:clients:',
        'device:client:',
        'online:clients',
        'online:',
        'auth:revoked:',
        'auth:bind:',
        'queue:udp:in',
        'queue:action:in',
        'queue:udp:out',
        'queue:push:out',
        'push:offline:',
        'push:dedup:',
        'subscribe:uid:',
        'subscribe:topic:',
        'action:result:',
        'action:report:',
        'metrics:counter:',
        'metrics:gauge',
        'rl:',
        'api:rate:',
        'health:probe',
    ];

    /**
     * 生效范围（对完整路径做正则；`[/\\\\]` 同时兼容 `/` 与 `\` 两种分隔符）
     *
     * @var string
     */
    public $filePattern = '#(?:^|[/\\\\])(?:client[/\\\\])?src[/\\\\]#';

    /**
     * 豁免名单（按标准化为 `/` 的路径后缀匹配）
     *
     * `src/Common/RedisKeys.php` 就是键名字面量的唯一声明处。
     *
     * @var string[]
     */
    public $exempt = [
        '/src/Common/RedisKeys.php',
    ];

    /**
     * 建议替代：引用 RedisKeys 的静态构造方法
     *
     * @var string
     */
    public $suggestion = 'RedisKeys::xxx()';

    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array<int|string>
     */
    public function register()
    {
        return [T_CONSTANT_ENCAPSED_STRING, T_DOUBLE_QUOTED_STRING];
    }

    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
     * @param int                         $stackPtr  The position of the current token in
     *                                               the stack passed in $tokens.
     *
     * @return int|void
     */
    public function process(File $phpcsFile, int $stackPtr)
    {
        $file = str_replace('\\', '/', (string) $phpcsFile->getFilename());

        if (preg_match($this->filePattern, $file) !== 1) {
            return;
        }

        foreach ($this->exempt as $suffix) {
            if (str_ends_with($file, $suffix) === true) {
                return;
            }
        }

        $tokens = $phpcsFile->getTokens();
        $content = trim($tokens[$stackPtr]['content'], "\"'");

        foreach ($this->prefixes as $prefix) {
            if (str_starts_with($content, $prefix) === false) {
                continue;
            }

            $error = 'Redis 键名禁止字面量（%s 开头），请改用 %s。';
            $data = [$prefix, $this->suggestion];
            $phpcsFile->addError($error, $stackPtr, 'FoundLiteral', $data);
            return;
        }
    }
}
