<?php
/**
 * 常驻进程禁则：禁止在业务代码里调用会终止进程或阻塞事件循环的语言结构/函数。
 *
 * 对应项目红线：`src/` 与 `client/src/` 内零 `exit` / `die` / `sleep` / `usleep` / `pcntl_fork`。
 * 见 AGENTS.md「高频红线 → 常驻内存」。
 *
 * 为什么不用内置的 `Generic.PHP.ForbiddenFunctions`：
 * 它只监听 T_STRING，而 `exit` / `die` 是语言结构（T_EXIT 令牌），内置嗅探器抓不到。
 *
 * 为什么作用域写在嗅探器里而不是规则集：
 * 本嗅探器由规则集用**文件路径**引用（`<rule ref="./tools/.../FooSniff.php">`），
 * 此时 PHPCS 会把规则级的 `include-pattern` / `exclude-pattern` 注册到「路径字符串」
 * 这个键下（见 Ruleset::processRuleset 里 `$code === $ref` 的分支），
 * 而检查时是按嗅探器编码查的，于是模式永远不命中、静默失效（实测确认）。
 * 所以作用域与豁免由本嗅探器的 `$filePattern` / `$exempt` 承担。
 */

namespace PHP_CodeSniffer\Standards\GatewayPush\Sniffs\PHP;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

class ForbiddenCallSniff implements Sniff
{
    /**
     * 禁止调用的名称 => 替代建议（null 表示无替代）
     *
     * 新增项请同步 AGENTS.md 的红线清单；本数组是**唯一真源**，
     * `tests/Manual/phpcs_business_rules_check.php` 会读它做自检。
     *
     * @var array<string, string|null>
     */
    public $forbidden = [
        'exit' => null,
        'die' => null,
        'sleep' => 'Timer::add()',
        'usleep' => 'Timer::add()',
        'pcntl_fork' => null,
    ];

    /**
     * 生效范围（对完整路径做正则；`[\\\\/]` 同时兼容 `/` 与 `\` 两种分隔符）
     *
     * `start.php` 等 CLI 入口不在 `src/` 下，天然不匹配。
     *
     * @var string
     */
    public $filePattern = '#(?:^|[/\\\\])(?:client[/\\\\])?src[/\\\\]#';

    /**
     * 豁免名单（按标准化为 `/` 的路径后缀匹配）
     *
     * `client/src/Cli/Debugger.php` 是 CLI 调试入口，`exit` / `die` 是其正常控制流。
     *
     * @var string[]
     */
    public $exempt = [
        '/client/src/Cli/Debugger.php',
    ];

    /**
     * 前置令牌：出现在这些令牌之后的 T_STRING 不是函数调用
     *
     * @var array<int|string, bool>
     */
    private static array $notAFunctionCall = [
        T_OBJECT_OPERATOR => true,
        T_NULLSAFE_OBJECT_OPERATOR => true,
        T_DOUBLE_COLON => true,
        T_FUNCTION => true,
        T_NEW => true,
    ];

    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array<int|string>
     */
    public function register()
    {
        return [T_EXIT, T_STRING, T_NAME_FULLY_QUALIFIED];
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
        $token = $tokens[$stackPtr];

        if ($token['code'] === T_EXIT) {
            // `exit` / `die` / `exit()` / `die()` 都是语言结构，直接判违规。
            $this->report($phpcsFile, $stackPtr, ltrim($token['content'], '\\'));
            return;
        }

        if ($this->isMethodOrDeclaration($tokens, $stackPtr) === true) {
            return;
        }

        // 必须紧跟 `(` 才算函数调用（排除 `sleep` 被当成常量/具名参数的情形）。
        $next = $phpcsFile->findNext(T_WHITESPACE, ($stackPtr + 1), null, true);
        if ($next === false || $tokens[$next]['code'] !== T_OPEN_PARENTHESIS) {
            return;
        }

        $this->report($phpcsFile, $stackPtr, ltrim($token['content'], '\\'));
    }

    /**
     * 判断该 T_STRING 是否属于「方法调用 / 函数声明 / new 类名」而非全局函数调用
     *
     * @param array<int, array<string, mixed>> $tokens   Token 栈
     * @param int                              $stackPtr 当前令牌位置
     *
     * @return bool
     */
    private function isMethodOrDeclaration(array $tokens, int $stackPtr): bool
    {
        $prev = $stackPtr;
        do {
            $prev = ($prev - 1);
            if ($prev < 0) {
                return false;
            }
        } while ($tokens[$prev]['code'] === T_WHITESPACE);

        return isset(self::$notAFunctionCall[$tokens[$prev]['code']]);
    }

    /**
     * 上报违规
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
     * @param int                         $stackPtr  Violation position.
     * @param string                      $name      Forbidden name.
     *
     * @return void
     */
    private function report(File $phpcsFile, int $stackPtr, string $name): void
    {
        if (array_key_exists($name, $this->forbidden) === false) {
            return;
        }

        $alternative = $this->forbidden[$name];

        if ($alternative === null) {
            $error = '常驻进程禁则：禁止调用 %s（会终止进程），见 AGENTS.md 红线清单。';
            $data = [$name];
        } else {
            $error = '常驻进程禁则：禁止调用 %s（会阻塞事件循环），应改用 %s。';
            $data = [$name, $alternative];
        }

        $phpcsFile->addError($error, $stackPtr, 'ForbiddenCall', $data);
    }
}
