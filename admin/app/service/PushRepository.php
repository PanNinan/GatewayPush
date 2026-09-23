<?php

declare(strict_types=1);

namespace app\service;

use support\Db;
use Throwable;

/**
 * `push_task`（后台发起的推送**受理记录**）的读写门面。
 *
 * ## 定位：受理记录，不是投递回执
 *
 * `/push` 是「入队即返回」的异步受理，服务端**不落逐条投递历史**（设计文档 §5.3.2 已写明）。
 * 因此本表只记「后台提交过什么、服务端怎么受理的」，`status` 的终态只有
 * `accepted` / `rejected`，**刻意不引入 `delivered`** —— 服务端本身不承诺这个事实，
 * 后台自己造一个就是假数据（设计文档 R5）。
 *
 * ## 异常策略：**写抛、读抛，都不吞**
 *
 * 与 `Settings`「库不可用回落默认值」的策略**刻意相反**，两处理由不同：
 * - `Settings` 读的是「标红阈值 / 分页大小」这类**展示参数**，缺了只影响观感，故可回落；
 * - 本类写的是**留痕**：若推送已受理、记录却静默没落库，事后完全无法追责；
 *   读的是**历史**：DB 挂了却返回空列表，会让运维误判成「没人发过推送」。
 *   ⇒ 前者是静默丢证据，后者是静默撒谎，**都不能吞**。
 *
 * 故本类不 `catch`，一律上抛给控制器，由控制器转成 `503` + 明确文案
 * （「受理记录落库失败」是可分辨的部分失败，而「空列表」不可分辨）。
 */
final class PushRepository
{
    /** 受理成功（`/push` 返回 `code=0`） */
    public const STATUS_ACCEPTED = 'accepted';

    /** 受理失败（入队前被拒，或连不上主项目） */
    public const STATUS_REJECTED = 'rejected';

    /** 状态集合（供前端下拉与校验） */
    public const STATUSES = [self::STATUS_ACCEPTED, self::STATUS_REJECTED];

    /**
     * 「这是受理记录，不是投递回执」的固定说明（后端下发，前端不得自行编词）。
     *
     * 这条必须出现在历史列表页上：`status=accepted` 只说明「后台拿到了 `code=0`」，
     * 而服务端在收到之后仍可能**静默丢弃**（payload 超限，见 `Pusher::PAYLOAD_DROP_NOTE`）
     * 或因为目标离线而走 `drop` 策略。少了这句话，列表就会被读成投递保证。
     */
    public const RECORD_NOTE = '本表是**受理记录**：accepted 只代表后台提交时拿到了服务端的 code=0，'
        . '不代表已投递。服务端不落逐条投递历史，真实效果请到监控页看 push_out / push_fail 的增量。';

    /** 列表页大小上限（`push_task` 是 MySQL 表，成本远低于 Redis SCAN，但仍设界防误用） */
    public const SIZE_MAX = 100;

    public const SIZE_MIN = 1;

    /**
     * 写入一条受理记录。
     *
     * `request_id` 由调用方用 `bin2hex(random_bytes(8))` 生成并有唯一键约束 ——
     * 它同时是「同一次提交」的关联键（`push_task` 与服务端回执都没有共同的业务 id，
     * `msg_id` 是调用方可选的、可能重复为空）。
     *
     * @param array{
     *     request_id: string,
     *     target_type: string,
     *     target: string,
     *     payload: array<mixed>,
     *     payload_bytes: int,
     *     msg_id: string,
     *     offline_mode: string,
     *     http_status: int,
     *     code: int,
     *     msg: string,
     *     status: string,
     *     operator_id: int
     * } $row
     *
     * @return int 自增主键
     *
     * @throws Throwable 落库失败时上抛（**不得吞** —— 见类注释）
     */
    public static function insert(array $row): int
    {
        $payload = json_encode($row['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return (int)Db::table('push_task')->insertGetId([
            'request_id' => $row['request_id'],
            'target_type' => $row['target_type'],
            'target' => $row['target'],
            // `payload` 列是 JSON 类型；非合法 JSON 会插入失败，此时用 `[]` 兜底
            // 而不是放弃整条记录 —— 记录「发过一次、载荷无法序列化」也比丢记录有用。
            'payload' => $payload === false ? '[]' : $payload,
            'payload_bytes' => $row['payload_bytes'],
            'msg_id' => $row['msg_id'],
            'offline_mode' => $row['offline_mode'],
            'http_status' => $row['http_status'],
            'code' => $row['code'],
            'msg' => self::clip($row['msg'], 255),
            'status' => $row['status'],
            'operator_id' => $row['operator_id'],
            // 显式写时间而不是靠 `DEFAULT CURRENT_TIMESTAMP`：后者的时区取自 **MySQL 服务端**，
            // 与 PHP 的 `date()` 可能不同；两套时间混在同一列的区间筛选里会错得很隐蔽。
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 分页查询受理记录（按 `created_at` 倒序）。
     *
     * @param array<string, mixed> $filters 支持 `target_type` / `target` / `status` /
     *                                      `msg_id` / `operator_id` / `from` / `to`
     *
     * @return array{
     *     items: list<array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     size: int,
     *     pages: int,
     *     filters: array<string, string>
     * }
     *
     * @throws Throwable 见类注释（读空会撒谎，故不吞）
     */
    public static function page(array $filters, mixed $page, mixed $size, int $defaultSize): array
    {
        [$p, $s] = SessionInspector::paging($page, $size, min($defaultSize, self::SIZE_MAX));
        $s = max(self::SIZE_MIN, min(self::SIZE_MAX, $s));

        $applied = self::appliedFilters($filters);

        // 计数与取数**各建一次查询**（而不是 `clone` 复用）：
        // `Illuminate\Database\Query\Builder` 没有 `__clone()`，浅克隆分享内部数组的
        // 值语义虽然在当前实现下可用，但那是「依赖实现细节的侥幸」——
        // 一旦上游给 Builder 加上 `__clone()` 做有状态清理，复用就会静默改变语义。
        $total = (int)self::query($applied)->count();

        /** @var array<int, \stdClass> $rows */
        $rows = self::query($applied)
            ->orderBy('id', 'desc')
            ->offset(($p - 1) * $s)
            ->limit($s)
            ->get()
            ->all();

        $items = [];
        foreach ($rows as $row) {
            $items[] = self::toItem((array)$row);
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $p,
            'size' => $s,
            'pages' => $total === 0 ? 0 : (int)ceil($total / $s),
            'filters' => $applied,
        ];
    }

    /**
     * 汇总统计（供「去监控页看增量」之外的本地对照）。
     *
     * @return array{total: int, accepted: int, rejected: int, today: int}
     *
     * @throws Throwable
     */
    public static function summary(): array
    {
        $total = (int)Db::table('push_task')->count();
        $accepted = (int)Db::table('push_task')->where('status', self::STATUS_ACCEPTED)->count();
        $today = (int)Db::table('push_task')
            ->where('created_at', '>=', date('Y-m-d') . ' 00:00:00')
            ->count();

        return [
            'total' => $total,
            'accepted' => $accepted,
            'rejected' => $total - $accepted,
            'today' => $today,
        ];
    }

    /**
     * 归一化并白名单化筛选条件（纯函数，可单测）。
     *
     * 只接受已知键，未知键直接丢弃 —— 若原样透传，一个手搓的 `?foo=1` 会变成
     * `where foo = 1` 并抛 SQL 异常（查询构造器不会替你过滤列名）。
     *
     * @param array<string, mixed> $filters
     *
     * @return array<string, string>
     */
    public static function appliedFilters(array $filters): array
    {
        $out = [];

        $targetType = Pusher::normalizeTargetType($filters['target_type'] ?? null);
        if ($targetType !== '') {
            $out['target_type'] = $targetType;
        }

        $target = Pusher::normalizeTarget($filters['target'] ?? null);
        if ($target !== '' && SessionInspector::validId($target)) {
            $out['target'] = $target;
        }

        $status = isset($filters['status']) && is_string($filters['status']) ? trim($filters['status']) : '';
        if (in_array($status, self::STATUSES, true)) {
            $out['status'] = $status;
        }

        $msgId = Pusher::normalizeMsgId($filters['msg_id'] ?? null);
        if ($msgId !== '' && strlen($msgId) <= Pusher::MSG_ID_MAX_LEN) {
            $out['msg_id'] = $msgId;
        }

        foreach (['from', 'to'] as $bound) {
            $date = self::validDate($filters[$bound] ?? null);
            if ($date !== '') {
                $out[$bound] = $date;
            }
        }

        return $out;
    }

    /**
     * `Y-m-d` 形态校验（纯函数）。
     *
     * 用 `DateTimeImmutable::createFromFormat` + 回比而不是 `strtotime()`：
     * 后者对 `2026-02-31` 这类「合法格式、非法日期」会静默顺延成 3 月 3 日，
     * 于是用户的筛选条件被悄悄改掉 —— 那是比报错更糟的行为。
     */
    public static function validDate(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);
        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return '';
        }

        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($dt === false || $dt->format('Y-m-d') !== $value) {
            return '';
        }

        return $value;
    }

    /* ---------------------------------------------------------------------
     | 内部
     --------------------------------------------------------------------- */

    /**
     * 按已归一的条件建一个查询（**每次调用都新建**，调用方可安全地再叠加排序与分页）。
     *
     * 返回类型只写在 docblock 里（不写原生类型）：原生类型会把
     * `Illuminate\Database\Query\Builder` 变成一个**硬运行期依赖**，
     * 而本项目的直接依赖是 `webman/database`；一旦上游换实现，原生类型会在
     * 类加载期就炸，而 docblock 不会。
     *
     * @param array<string, string> $applied {@see appliedFilters()} 的产物
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private static function query(array $applied)
    {
        $q = Db::table('push_task');

        foreach (['target_type', 'status'] as $col) {
            if (isset($applied[$col])) {
                $q->where($col, $applied[$col]);
            }
        }
        if (isset($applied['target'])) {
            $q->where('target', $applied['target']);
        }
        if (isset($applied['msg_id'])) {
            $q->where('msg_id', $applied['msg_id']);
        }
        if (isset($applied['from'])) {
            $q->where('created_at', '>=', $applied['from'] . ' 00:00:00');
        }
        if (isset($applied['to'])) {
            // 闭区间取到当日 23:59:59 —— 用 `< 次日 00:00:00` 更严谨，但会让「到」的语义
            // 变成开区间，与用户勾选「到 9 月 23 日」的直觉不符，故刻意取闭区间上界。
            $q->where('created_at', '<=', $applied['to'] . ' 23:59:59');
        }

        return $q;
    }

    /**
     * DB 行 → 前端条目（**字段名与列名一一对应，不做重命名**）。
     *
     * 刻意不把 `payload` 解析成数组：它可能是运行期被人工改坏的 JSON，
     * 解析失败时前端应看到原文而不是一个空的 `{}`（后者会让人以为载荷本来就是空的）。
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function toItem(array $row): array
    {
        $item = [];
        foreach ([
            'id', 'request_id', 'target_type', 'target', 'payload', 'payload_bytes',
            'msg_id', 'offline_mode', 'http_status', 'code', 'msg', 'status',
            'operator_id', 'created_at',
        ] as $col) {
            $item[$col] = $row[$col] ?? null;
        }
        $item['target_label'] = Pusher::labelOfTargetType((string)($row['target_type'] ?? ''));
        $item['offline_label'] = Pusher::labelOfOfflineMode((string)($row['offline_mode'] ?? ''));

        return $item;
    }

    /** 截断到列宽（MySQL 严格模式下超长会直接报 1406，宁可截断也不要丢整条记录）。 */
    private static function clip(string $value, int $max): string
    {
        return strlen($value) <= $max ? $value : substr($value, 0, $max);
    }
}
