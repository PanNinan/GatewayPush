<?php
/**
 * admin · 页面 / API 控制器 —— AccessLogController。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace app\controller\api;

use app\service\AccessLogger;
use app\service\ApiReply;
use app\service\Perm;
use support\Db;
use support\Request;
use support\Response;
use Throwable;

/**
 * 访问日志只读 API（`wa_admin_log`）。
 *
 * 权限点（`wa_rules.key`，由 `scripts/install.php` 写入）：
 * - `app\controller\api\AccessLogController@index`
 *
 * 查询串：`event=`、`result=`、`method=`、`path=`（前缀）、`admin_id=`、
 *         `page=`、`size=`、`from=`、`to=`（Y-m-d H:i:s 或 Unix 秒）。
 *
 * 与 {@see AuditController}（`admin_audit_log`）平行：本端点只读访问行为，
 * params/body 在写入时已由 AccessLogger 脱敏。
 */
final class AccessLogController
{
    /**
     * 访问日志列表（只读检索）。
     */
    public function index(Request $request): Response
    {
        $query = $request->get();

        $page = max(1, (int)($query['page'] ?? 1));
        $size = max(1, min(100, (int)($query['size'] ?? 20)));

        $event = trim((string)($query['event'] ?? ''));
        if ($event !== '' && !in_array($event, AccessLogger::EVENTS, true)) {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'event 不在 login|logout|access 内');
        }

        $result = trim((string)($query['result'] ?? ''));
        if ($result !== '' && !in_array($result, AccessLogger::RESULTS, true)) {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'result 只能是 ok 或 failed');
        }

        $method = strtoupper(trim((string)($query['method'] ?? '')));
        if ($method !== '' && !in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'method 仅支持 HTTP 动词');
        }

        $pathPrefix = trim((string)($query['path'] ?? ''));
        if (strlen($pathPrefix) > 191) {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'path 前缀过长');
        }

        try {
            $q = Db::table('wa_admin_log');
            if ($event !== '') {
                $q->where('event', $event);
            }
            if ($result !== '') {
                $q->where('result', $result);
            }
            if ($method !== '') {
                $q->where('method', $method);
            }
            if ($pathPrefix !== '') {
                $q->where('path', 'like', $pathPrefix . '%');
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
                ->toArray()
            ;

            return ApiReply::ok([
                'rows' => $rows,
                'page' => $page,
                'size' => $size,
                'total' => $total,
                'events' => AccessLogger::EVENTS,
                'results' => AccessLogger::RESULTS,
                'perms' => Perm::map([
                    'list' => [self::class, 'index'],
                ]),
            ]);
        } catch (Throwable $e) {
            return ApiReply::fail(
                503,
                ApiReply::CODE_DB_UNAVAILABLE,
                '访问日志库不可读：' . $e->getMessage()
            );
        }
    }

    /**
     * 解析时间过滤：接受 Unix 秒（纯数字）或 `Y-m-d H:i:s`；空 / 非法返回 null。
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
