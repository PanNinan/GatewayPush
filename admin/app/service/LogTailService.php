<?php

declare(strict_types=1);

namespace app\service;

use RuntimeException;

/**
 * 主项目日志只读尾读（P5）。
 *
 * ---------------------------------------------------------------------
 * 三道防线（缺一即路径穿越）—— 设计文档 §7-P5 风险项
 * ---------------------------------------------------------------------
 * 主项目日志按 `runtime/logs/{role}_{date}.log`（error 级双写 `error_{date}.log`）
 * 落盘，后台只做**尾读**。`role` / `date` 都来自 URL 参数，必须防御：
 *
 * 1. **role 白名单**：只允许 RoleCatalog 的六个角色 + `error`（error 级双写文件）。
 *    白名单是**手写常量**，并由 `LogTailServiceTest` 与主项目 `RoleCatalog.php`
 *    源码**正则对照** —— 防止主项目加角色后这里静默漂移。
 * 2. **date 形态**：`^\d{4}-\d{2}-\d{2}$`（拼文件名前就拒绝）。
 * 3. **realpath 复核**：拼出的路径 realpath 后必须仍落在日志目录内
 *    （防符号链接 / `..` 之外的花样；对不存在的文件返回 not_found 而非报错）。
 *
 * **刻意不提供任何删除 / 下载** —— 尾读窗口之外的历史行不属于后台的职责。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */
final class LogTailService
{
    /** role 白名单：六个角色 + error 级双写文件（与主项目 RoleCatalog 对照） */
    public const ROLES = ['register', 'gateway', 'udp', 'business', 'api', 'dashboard', 'error'];

    /** 单次尾读的最大行数（UI 也按此夹取） */
    public const TAIL_MAX = 500;

    private string $logDir;

    public function __construct(?string $logDir = null)
    {
        $dir = $logDir !== null
            ? $logDir
            : (string)config('gateway_push.log_dir', '../runtime/logs');

        $real = realpath($dir);
        $this->logDir = $real === false ? rtrim($dir, '/\\') : $real;
    }

    /**
     * 尾读一个日志文件的末尾若干行
     *
     * @param string $role    角色名（或 `error`）
     * @param string $date    `Y-m-d`
     * @param int    $lines   1~TAIL_MAX
     * @param string $keyword 子串过滤（大小写敏感；空 = 不过滤）
     *
     * @return array{ok: bool, hint: string, file: string, lines: list<string>, matched: int, truncated: bool}
     */
    public function tail(string $role, string $date, int $lines = 200, string $keyword = ''): array
    {
        $lines = max(1, min(self::TAIL_MAX, $lines));

        $file = $this->resolve($role, $date);
        if ($file === '') {
            return ['ok' => false, 'hint' => '非法 role 或 date',
                'file' => '', 'lines' => [], 'matched' => 0, 'truncated' => false];
        }
        if ($file === 'not_found') {
            return ['ok' => false, 'hint' => '日志文件不存在',
                'file' => '', 'lines' => [], 'matched' => 0, 'truncated' => false];
        }

        try {
            $all = $this->readTail($file, $lines, $keyword);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'hint' => $e->getMessage(),
                'file' => $file, 'lines' => [], 'matched' => 0, 'truncated' => false];
        }

        return ['ok' => true, 'hint' => '', 'file' => $file] + $all;
    }

    /**
     * 把 role / date 拼成绝对路径，过三道防线
     *
     * @return string 合法路径；`''` = 参数非法；`'not_found'` = 文件不存在
     */
    private function resolve(string $role, string $date): string
    {
        if (!in_array($role, self::ROLES, true)) {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return '';
        }

        // 统一 `{role}_{date}.log`：error 汇总通道同样是按日文件（Logger 的
        // CHANNEL_ERROR_DIGEST 双写 `error_{date}.log`），**不得**剥掉日期去猜
        // 一个不存在的 `error.log`（P5 实测踩过：error 角色恒 not_found）。
        $file = $this->logDir . '/' . $role . '_' . $date . '.log';

        $real = realpath($file);
        // 文件不存在不是错误 —— 返回一个可读的提示，让 UI 显式说「今天还没有日志」
        if ($real === false) {
            return 'not_found';
        }

        $dirReal = realpath($this->logDir);
        if ($dirReal === false || !str_starts_with($real, $dirReal . DIRECTORY_SEPARATOR)) {
            // 落点跑出了日志目录 —— 可能是符号链接或目录被移动，宁可拒绝
            return '';
        }

        return $real;
    }

    /**
     * 从文件末尾向前读，取出最后 N 行（可按关键字过滤后再取 N 行）
     *
     * 「过滤后取 N 行」意味着：给 200 行、命中只有 3 行时返回 3 行；
     * 但为了不让一次过滤扫描整份大文件，**向前扫描最多 20000 行**即止（truncated 标记）。
     *
     * @return array{lines: list<string>, matched: int, truncated: bool}
     */
    private function readTail(string $file, int $lines, string $keyword): array
    {
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            throw new RuntimeException('日志文件不可读');
        }

        $scanLimit = 20000;
        $buffer = [];
        $matched = 0;
        $truncated = false;

        try {
            fseek($fh, 0, SEEK_END);
            $size = ftell($fh);
            if ($size === false) {
                throw new RuntimeException('日志文件不可读');
            }

            $chunkSize = 65536;
            $pos = $size;
            $carry = '';

            while (count($buffer) < $lines && $pos > 0) {
                $read = min($chunkSize, $pos);
                $pos -= $read;

                if (fseek($fh, $pos, SEEK_SET) !== 0) {
                    break;
                }
                $chunk = (string)fread($fh, $read);
                $piece = $chunk . $carry;
                $carry = '';

                $rows = explode("\n", $piece);
                if ($pos > 0) {
                    // 第一段大概率是被截断的半行 —— 留给下一块拼接
                    $carry = array_shift($rows);
                }

                // 最早读到的块在最前；倒序拼（块内行序保持，块间从后往前）
                $buffer = array_merge($rows, $buffer);

                if (count($buffer) > $scanLimit) {
                    $truncated = true;
                    $buffer = array_slice($buffer, -$scanLimit);
                    break;
                }
            }
        } finally {
            fclose($fh);
        }

        // 去掉末尾空行与残余半行的空段，再按关键字过滤，取最后 N 行
        $buffer = array_values(array_filter($buffer, static fn ($l) => $l !== ''));
        if ($keyword !== '') {
            $buffer = array_values(array_filter(
                $buffer,
                static fn ($l) => str_contains($l, $keyword)
            ));
        }
        $total = count($buffer);
        $buffer = array_slice($buffer, -$lines);

        return ['lines' => $buffer, 'matched' => $total, 'truncated' => $truncated];
    }
}
