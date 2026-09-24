<?php
/**
 * admin · 服务层 —— TemplateRepository。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace app\service;

use support\Db;
use Throwable;

/**
 * `push_template`（推送载荷模板）的读写门面。
 *
 * ## 为什么模板必须存在
 *
 * 运维最常复现的两件事：① 发一条固定结构的通知（`{"type":"notice","level":"warn"}`）；
 * ② 反复手搓同一段 JSON。手搓的代价不是麻烦，而是**每次都可能漏掉一个字段**
 * —— 而漏字段不会报错，只会让客户端解析出一条残缺数据。
 *
 * ## 异常策略：与 {@see PushRepository} 一致（写抛、读抛，都不吞）
 *
 * 模板是**人工维护的资产**：DB 挂了却返回空列表，使用者会以为「模板被人删了」并重新创建，
 * 于是产生一堆同名冲突（`uk_name`）—— 一个查不出来的故障会升级成一次人工混乱。
 */
final class TemplateRepository
{
    /** 模板列表的返回条数上限（模板是人工维护的少量资产，不搞分页，但设界防误用） */
    public const LIST_MAX = 200;

    /**
     * 全部模板（按名称倒序无关紧要，故按 `id` 升序，稳定可断言）。
     *
     * @return list<array<string, mixed>>
     *
     * @throws Throwable 数据库异常
     */
    public static function all(): array
    {
        /** @var array<int, \stdClass> $rows */
        $rows = Db::table('push_template')
            ->orderBy('id', 'asc')
            ->limit(self::LIST_MAX)
            ->get()
            ->all()
        ;

        $out = [];
        foreach ($rows as $row) {
            $out[] = self::toItem((array)$row);
        }

        return $out;
    }

    /**
     * 单条模板。
     *
     * @return null|array<string, mixed>
     *
     * @throws Throwable 数据库异常
     */
    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $row = Db::table('push_template')->where('id', $id)->first();

        return $row === null ? null : self::toItem((array)$row);
    }

    /**
     * 名称是否已被占用（`uk_name` 唯一键的**前置**检查）。
     *
     * 为什么要前置而不是靠捕获唯一键异常：捕获 `1062` 需要识别驱动相关的错误码，
     * 且**无法区分是哪个唯一键**冲突（将来加第二个唯一键就会给出错误提示）。
     * 前置检查 + 「仍然捕获异常兜底」两层，才能既给人话文案又不丢一致性保证。
     *
     * @param int $excludeId 编辑时排除自身
     *
     * @throws Throwable 数据库异常
     */
    public static function nameTaken(string $name, int $excludeId = 0): bool
    {
        $q = Db::table('push_template')->where('name', $name);
        if ($excludeId > 0) {
            $q->where('id', '<>', $excludeId);
        }

        return (int)$q->count() > 0;
    }

    /**
     * 新建或更新一条模板。
     *
     * @param null|int             $id      `null`/`<=0` 表示新建
     * @param array<string, mixed> $row     {@see Pusher::validateTemplate()} 的 `row`
     * @param int                  $adminId
     *
     * @return int 模板 id（新建时是自增主键）
     *
     * @throws Throwable 数据库异常
     */
    public static function save(?int $id, array $row, int $adminId): int
    {
        $payload = json_encode($row['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = date('Y-m-d H:i:s');

        $data = [
            'name' => (string)$row['name'],
            'target_type' => (string)$row['target_type'],
            'payload' => $payload === false ? '{}' : $payload,
            'offline_mode' => (string)$row['offline_mode'],
            'remark' => (string)$row['remark'],
        ];

        if ($id !== null && $id > 0) {
            // 刻意不更新 `created_by`：模板的「创建者」是历史事实，
            // 后续编辑者由 `admin_audit_log` 记录（本表只有一个作者列，改它会丢信息）。
            $data['updated_at'] = $now;
            Db::table('push_template')->where('id', $id)->update($data);

            return $id;
        }

        $data['created_by'] = $adminId;
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        return (int)Db::table('push_template')->insertGetId($data);
    }

    /**
     * 硬删除一条模板。
     *
     * **刻意不做软删除**（表里也没有 `deleted_at` 列）：
     * 模板是「发送用的草稿」，唯一键是 `name` —— 软删除会让被删掉的名字**永远无法复用**
     * （唯一键仍占着，用户看到「名称已存在」却列表里找不到），那是个查起来极其费劲的坑。
     * 删除动作本身有 `admin_audit_log` 留痕（含模板名），足以追溯。
     *
     * @return bool 是否真的删掉了一行（`false` = 该 id 不存在）
     *
     * @throws Throwable 数据库异常
     */
    public static function delete(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        return (int)Db::table('push_template')->where('id', $id)->delete() > 0;
    }

    /**
     * DB 行 → 前端条目。
     *
     * `payload` 同时给出**原文**（`payload_raw`）与解析结果：前者用于核查被人工改坏的 JSON，
     * 后者用于「套用模板」时回填表单。解析失败时 `payload` 是 `null`（不是 `{}`）——
     * `{}` 会让人以为模板本来就是空载荷。
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function toItem(array $row): array
    {
        $raw = (string)($row['payload'] ?? '');
        $decoded = json_decode($raw, true);

        return [
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'target_type' => (string)($row['target_type'] ?? ''),
            'target_label' => Pusher::labelOfTargetType((string)($row['target_type'] ?? '')),
            'payload' => is_array($decoded) ? $decoded : null,
            'payload_raw' => $raw,
            'payload_bytes' => strlen($raw),
            'offline_mode' => (string)($row['offline_mode'] ?? ''),
            'offline_label' => Pusher::labelOfOfflineMode((string)($row['offline_mode'] ?? '')),
            'remark' => (string)($row['remark'] ?? ''),
            'created_by' => (int)($row['created_by'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }
}
