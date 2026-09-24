<?php
/**
 * admin · 服务层 —— ErrorAggregator。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace app\service;

/**
 * 主项目错误日志聚合（2.0 §1.4「错误日志聚合」的服务层）。
 *
 * 与 {@see LogTailService} 的分工：那边是「打开某一个文件看尾巴」（人工翻日志）；
 * 本类是「按角色把今日 error 摊成一张表」（一眼看谁在报错、报了多少）。
 *
 * 数据源两条，语义刻意不同：
 * - **各角色文件**（`{role}_{date}.log`）：用关键字 `[ERROR]` 过滤后计数 ——
 *   与主项目 `Logger` 的行格式 `[Y-m-d H:i:s][LEVEL][tag]` 对齐；
 * - **error 级双写汇总**（`error_{date}.log`）：文件内**每一行都是 error**，
 *   故不加关键字，`matched` 即行数（仍在 LogTailService 的向前扫描上限内）。
 *
 * 计数口径（必须在 UI 如实标注，否则会误导）：
 * `count` 来自 `LogTailService::tail()` 的 `matched` —— 它是在
 * **向前扫描窗口（最多 20000 行）内**的命中数，超大文件会带 `truncated=true`。
 * 这不是「今日精确总行数」，而是「尾部窗口内的 error 数」；需要精确总量时
 * 应去主项目侧做离线统计，不在后台尾读职责内。
 *
 * 失败语义：某角色文件不存在 → `not_found=true`（今天还没日志，不是错误）；
 * 路径非法 / IO 失败 → `ok=false` + `hint`，**单角色失败不拖垮整表**。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */
final class ErrorAggregator
{
    /** 主项目 Logger 的 error 级行标记（`[LEVEL]` 形态，见 Logger::log()） */
    public const ERROR_KEYWORD = '[ERROR]';

    /** 单角色默认展示行数（服务端夹取到 LogTailService::TAIL_MAX） */
    public const DEFAULT_LINES = 20;

    /** @var LogTailService */
    private LogTailService $tail;

    /**
     * @param null|LogTailService $tail 缺省自建；测试可注入夹具目录
     */
    public function __construct(?LogTailService $tail = null)
    {
        $this->tail = $tail ?? new LogTailService();
    }

    /**
     * 按角色聚合指定日期的 error 条数 + 最新若干行原文。
     *
     * @param string $date  `Y-m-d`（形态校验失败时整体 400，由调用方先拦）
     * @param int    $lines 每角色展示行数，1~TAIL_MAX
     *
     * @return array{
     *     date: string,
     *     keyword: string,
     *     lines: int,
     *     total: int,
     *     roles: list<array{
     *         role: string, ok: bool, not_found: bool, count: int,
     *         truncated: bool, lines: list<string>, hint: string
     *     }>
     * }
     */
    public function aggregate(string $date, int $lines = self::DEFAULT_LINES): array
    {
        $lines = max(1, min(LogTailService::TAIL_MAX, $lines));

        $items = [];
        $total = 0;

        // 六个业务角色（error 是汇总通道，单独处理，不进本循环）
        foreach (LogTailService::ROLES as $role) {
            if ($role === 'error') {
                continue;
            }

            $item = $this->one($role, $date, $lines, self::ERROR_KEYWORD);
            $items[] = $item;
            $total += $item['count'];
        }

        // error 级双写汇总：文件内全是 error，不过滤关键字
        $digest = $this->one('error', $date, $lines, '');
        $items[] = $digest;

        return [
            'date' => $date,
            'keyword' => self::ERROR_KEYWORD,
            'lines' => $lines,
            'total' => $total, // 仅业务角色之和（汇总文件是同一批行的副本，不重复计入）
            'roles' => $items,
        ];
    }

    /**
     * 单角色取数。**不抛异常** —— 单文件失败只影响该行。
     *
     * @return array{
     *     role: string, ok: bool, not_found: bool, count: int,
     *     truncated: bool, lines: list<string>, hint: string
     * }
     */
    private function one(string $role, string $date, int $lines, string $keyword): array
    {
        $res = $this->tail->tail($role, $date, $lines, $keyword);
        $notFound = $res['ok'] === false && $res['hint'] === '日志文件不存在';

        return [
            'role' => $role,
            'ok' => $res['ok'],
            'not_found' => $notFound,
            // not_found 时 matched 恒为 0；IO 失败时同样按 0 计，hint 已带原因
            'count' => $res['ok'] ? $res['matched'] : 0,
            'truncated' => $res['truncated'],
            'lines' => $res['lines'],
            'hint' => $res['hint'],
        ];
    }
}
