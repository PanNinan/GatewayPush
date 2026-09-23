<?php

declare(strict_types=1);

namespace app\service;

use support\Db;
use Throwable;

/**
 * `admin_settings` 的只读读取器。
 *
 * 用途：队列深度告警阈值、轮询间隔、分页大小这类**可调参数**存库而非写死代码
 * （DDL 见 `database/001_gw_tables.sql`），新增配置项无需改 DDL。
 *
 * 容错策略：**库不可用时不抛异常**，回落调用方给的默认值。
 * 理由：这些参数只影响展示（标红/分页），不该让 MySQL 故障升级成整个页面 500 ——
 * 后台的推送系统监控能力在 MySQL 挂掉时仍应可用。
 */
final class Settings
{
    /** @var array<string, string>|null 进程内缓存：同一次请求内多次读取不重复查库 */
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
     * 全量设置（只读一次并缓存）。
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            /** @var array<int, object{k: string, v: string}> $rows */
            $rows = Db::table('admin_settings')->select('k', 'v')->get()->all();
            $result = [];
            foreach ($rows as $row) {
                $result[(string)$row->k] = (string)$row->v;
            }
            self::$cache = $result;
        } catch (Throwable) {
            // 库不可用 / 表未迁移：本次请求内不再重试，直接按空表处理。
            self::$cache = [];
        }

        return self::$cache;
    }

    /**
     * 清空进程内缓存（供测试与「设置已修改」后立即生效使用）。
     */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
