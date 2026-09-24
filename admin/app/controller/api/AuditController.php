<?php

declare(strict_types=1);

namespace app\controller\api;

use app\service\ApiReply;
use app\service\Auditor;
use support\Db;
use support\Request;
use support\Response;
use Throwable;

/**
 * 行为日志只读 API（`admin_audit_log`）。
 *
 * 权限点（`wa_rules.key`，由 `scripts/install.php` 写入）：
 * - `app\controller\api\AuditController@index`
 *
 * 鉴权由 `config/route.php` 挂载的 `app\middleware\AdminAuth` 完成；
 * **漏注册的 action 不会自动放行** —— 已对本控制器 `Route::disableDefaultRoute()`。
 *
 * 查询串：`action=`、`result=`、`admin_id=`、`page=`、`size=`、`from=`、`to=`（Y-m-d H:i:s 或 Unix 秒）。
 * 全部只读；params 在**写入时**已由 {@see Auditor::redact()} 脱敏，本端点不再二次处理。
 */
final class AuditController
{
    public function index(Request $request): Response
    {
        $query = $request->get();

        $page = max(1, (int)($query['page'] ?? 1));
        $size = max(1, min(100, (int)($query['size'] ?? 20)));

        $action = trim((string)($query['action'] ?? ''));
        if ($action !== '' && !in_array($action, Auditor::ACTIONS, true)) {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'action 不在已登记动作表内');
        }

        $result = trim((string)($query['result'] ?? ''));
        if ($result !== '' && !in_array($result, [Auditor::RESULT_OK, Auditor::RESULT_FAILED], true)) {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'result 只能是 ok 或 failed');
        }

        try {
            $q = Db::table('admin_audit_log');
            if ($action !== '') {
                $q->where('action', $action);
            }
            if ($result !== '') {
                $q->where('result', $result);
            }
            $adminId = (int)($query['admin_id'] ?? 0);
            if ($adminId > 0) {
                $q->where('admin_id', $adminId);
            }
            $from = $this->parseTime($query['from'] ?? null);
            if ($from !== null) {
                $q->where('created_at', '>=', $from);
            }
            $to = $this->parseTime($query['to'] ?? null);
            if ($to !== null) {
                $q->where('created_at', '<=', $to);
            }

            $total = (int)(clone $q)->count();
            $rows = $q->orderBy('id', 'desc')
                ->offset(($page - 1) * $size)
                ->limit($size)
                ->get()
                ->toArray();

            return ApiReply::ok([
                'rows' => $rows,
                'page' => $page,
                'size' => $size,
                'total' => $total,
            ]);
        } catch (Throwable $e) {
            return ApiReply::fail(
                503,
                ApiReply::CODE_DB_UNAVAILABLE,
                '审计库不可读：' . $e->getMessage()
            );
        }
    }

    /**
     * 解析时间过滤：接受 Unix 秒（纯数字）或 `Y-m-d H:i:s`；空 / 非法返回 null（不过滤）。
     */
    private function parseTime(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $s = (string)$raw;
        if (ctype_digit($s)) {
            return date('Y-m-d H:i:s', (int)$s);
        }
        $ts = strtotime($s);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }
}
