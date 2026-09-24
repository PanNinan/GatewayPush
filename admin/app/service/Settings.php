<?php
/**
 * admin · 服务层 —— Settings。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace app\service;

use support\Db;
use Throwable;

/**
 * `admin_settings` 的只读读取器。
 *
 * 用途：队列深度告警阈值、轮询间隔、派生率阈值这类**可调参数**存库而非写死代码
 * （DDL 见 `database/001_gw_tables.sql`），新增配置项无需改 DDL。
 *
 * 容错策略：**库不可用时不抛异常**，回落调用方给的默认值。
 * 理由：这些参数只影响展示（标红/分页），不该让 MySQL 故障升级成整个页面 500 ——
 * 后台的推送系统监控能力在 MySQL 挂掉时仍应可用。
 *
 * ## ⚠ 缓存必须带 TTL（P0 曾漏掉，属常驻内存坑）
 *
 * `webman` 是 **workerman 常驻进程**，PHP 静态属性**不会在请求之间重置**（与 PHP-FPM 不同）。
 * P0 的初版只用一个 `?array $cache` 做缓存并注释「同一次请求内不重复查库」——
 * 实际语义是「**直到该 worker 进程重启**」：改库里的阈值对已在运行的 worker 完全无效，
 * 与 DashboardController 里「调参不需要重启后台」的说法直接矛盾。
 *
 * 因此改为**带 TTL 的缓存**（`CACHE_TTL` 秒）：既保住「一次请求内不重复查库」的收益，
 * 又把「改库后多久生效」收敛到一个有界的、可解释的窗口内。
 * 可调参数延迟几秒生效对运维无影响，而「改了不生效却毫无提示」是纯故障。
 */
final class Settings
{
    /**
     * 进程内缓存有效期（秒）。
     *
     * 取 5s 的理由：这些参数都是「人调、人看」的低频配置，延迟 5s 生效无感；
     * 而每个 worker 最多每 5s 查一次库，成本可忽略。
     */
    public const CACHE_TTL = 5;

    /** @var null|array{0: int, 1: array<string, string>} 进程内缓存：[取数时刻, 数据] */
    private static ?array $cache = null;

    /**
     * 取单项设置。
     */
    public static function get(string $key, string $default = ''): string
    {
        $all = self::all();

        return $all[$key] ?? $default;
    }

    /**
     * 取整数设置。
     */
    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, (string)$default);

        return is_numeric($value) ? (int)$value : $default;
    }

    /**
     * 取 JSON 设置（存库时序列化为 JSON 字符串的复合配置）。
     *
     * 用途：`monitor.ratio_thresholds` 这类「一张表要装多个键」的配置 ——
     * 存成 12 个独立 setting 会很啰嗦，存一个 JSON 更贴合实际使用。
     *
     * 容错：空值 / 非法 JSON / 解出来不是数组 → 一律回落 `$default`，
     * 与 `all()` 的容错口径一致（配置坏了只影响展示，不该让页面出错）。
     *
     * @param array<string, mixed> $default
     *
     * @return array<string, mixed>
     */
    public static function json(string $key, array $default = []): array
    {
        $raw = self::get($key, '');

        if ($raw === '') {
            return $default;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : $default;
    }

    /**
     * 全量设置（带 TTL 的进程内缓存）。
     *
     * ⚠ 缓存**必须**过期 —— 本进程是常驻的，静态变量跨请求存活。
     * 详见类注释「缓存必须带 TTL」。
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $now = time();
        if (self::$cache !== null && $now - self::$cache[0] < self::CACHE_TTL) {
            return self::$cache[1];
        }

        try {
            /** @var array<int, object{k: string, v: string}> $rows */
            $rows = Db::table('admin_settings')->select('k', 'v')->get()->all();
            $result = [];
            foreach ($rows as $row) {
                $result[(string)$row->k] = (string)$row->v;
            }
        } catch (Throwable) {
            // 库不可用 / 表未迁移：按空表处理并同样缓存 —— 避免每个请求都去撞一次
            // 已故障的 MySQL（那会把一个慢查询放大成持续的超时等）。
            $result = [];
        }

        self::$cache = [$now, $result];

        return $result;
    }

    /**
     * 清空进程内缓存（供测试与「设置已修改」后立即生效使用）。
     */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
