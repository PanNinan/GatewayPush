<?php

declare(strict_types=1);

namespace app\controller\api;

use app\service\ApiReply;
use app\service\Auditor;
use app\service\GatewayPushClient;
use app\service\Pusher;
use app\service\PushRepository;
use app\service\Settings;
use app\service\TemplateRepository;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * M3 推送管理 API。
 *
 * 权限点（`wa_rules.key` = `{控制器全类名}@{action}`，由 `scripts/install.php` 写入）：
 * - `app\controller\api\PushController@create`        发起推送（**写**）
 * - `app\controller\api\PushController@history`       推送历史（读）
 * - `app\controller\api\PushController@templateList`  模板列表（读）
 * - `app\controller\api\PushController@templateSave`  模板新建/编辑（**写**）
 * - `app\controller\api\PushController@templateDelete` 模板删除（**写**）
 *
 * 鉴权由 `config/route.php` 挂载的 `app\middleware\AdminAuth` 完成。
 * 本控制器已 `Route::disableDefaultRoute()`，未注册动作不会兜底放行。
 *
 * ## 三条贯穿本控制器的纪律
 *
 * 1. **写操作一律走主项目已签名 HTTP API**（`config/gateway_push.php` 的关键原则：
 *    「读走 Redis，写走 HTTP」）。后台**不**直连 Redis 改推送系统状态 ——
 *    否则就绕过了主项目的校验、限流与指标。
 * 2. **三种「部分失败」必须各自可分辨**，不能合并成一个 `code`：
 *    - 主项目**未受理** → `HTTP 502` + `CODE_UPSTREAM`（推送没发生）；
 *    - 主项目已受理但 `push_task` **落库失败** → `HTTP 200` + `recorded=false`；
 *    - 主项目已受理但**审计落库失败** → `HTTP 200` + `audit_ok=false`。
 *    前者的处置是「改参数重发」，后两者的处置是「动作已生效，去补留痕」—— 混成一个码就没了处置依据。
 * 3. **响应里出现的每一个「成功」字样都必须有对应的服务端事实**。`/push` 的 `code=0`
 *    只代表**已入队**，故本控制器一律把主项目的原始三元组（`http_status` / `code`）
 *    与三条语义说明（`notes`）一并回带，绝不在文案上说「已发送 / 已投递」。
 */
final class PushController
{
    /**
     * 推送历史列表的默认页大小（可被 `admin_settings.push.page_size` 覆盖）。
     *
     * `public` 是刻意的：页面控制器 `app\controller\PushController` 需要按**同一口径**
     * 计算首屏页大小（若两侧各写一个字面量，改一处就会出现
     * 「首屏显示 20 条但下拉写着 50」这类自相矛盾）。同 `SessionController::SIZE_OPTIONS` 的做法。
     */
    public const HISTORY_PAGE_SIZE = 20;

    /**
     * 发起一次定向推送（**异步受理**）。
     *
     * 请求体（JSON 或表单皆可）：`target_type` / `target` / `payload` / `msg_id` / `offline_mode`。
     * `payload` 同时接受**对象**与**JSON 字符串**两种形态（见 `Pusher::decodePayload()`）。
     *
     * 成败口径（务必按此顺序理解）：
     *   - 后台自身校验不过 → `400` + `4007`（用户改表单）；
     *   - 主项目未受理     → `502` + `5020`（**两侧契约问题，不是用户填错**）；
     *   - 主项目已受理     → `200` + `code=0`，**仅表示已入队**（见响应的 `notes`）。
     *
     * `msg_id` 留空时由后台自动补一个 16 位 hex：服务端只在提供了 `msg_id` 时启用幂等，
     * 留空等于放弃保护 —— 与运维直觉相反，故不让它发生。
     */
    public function create(Request $request): Response
    {
        $input = $request->post();
        $payloadMax = Pusher::payloadMax(Settings::int('push.payload_max', 0));

        $check = Pusher::validatePush($input, $payloadMax);
        if (!$check['ok']) {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, implode('；', $check['errors']), [
                'errors' => $check['errors'],
                'bytes' => $check['bytes'],
                'max' => $check['max'],
            ]);
        }

        $job = $check['job'];
        $requestId = bin2hex(random_bytes(8));

        $res = GatewayPushClient::fromConfig()->push($job);
        $accepted = ($res['ok'] ?? false) === true;
        $upstreamMsg = (string)($res['msg'] ?? '');

        // 服务端回带的 `offline_mode` 是**实际生效值**（未传时是服务端默认值），
        // 故落库与展示都用它，而不是我们请求里那个可能为空的 `''`。
        $effectiveMode = isset($res['data']['offline_mode']) && is_string($res['data']['offline_mode'])
            ? $res['data']['offline_mode']
            : $job['offline_mode'];

        // ── 受理记录（落库失败不改变「推送是否已受理」这个事实，故只标记不抛） ──
        $recorded = true;
        $recordError = '';
        try {
            PushRepository::insert([
                'request_id' => $requestId,
                'target_type' => $job['target_type'],
                'target' => $job['target'],
                'payload' => $job['payload'],
                'payload_bytes' => $check['bytes'],
                'msg_id' => $job['msg_id'],
                'offline_mode' => $effectiveMode,
                'http_status' => (int)($res['status'] ?? 0),
                'code' => (int)($res['code'] ?? 0),
                'msg' => $accepted ? 'accepted' : $upstreamMsg,
                'status' => $accepted ? PushRepository::STATUS_ACCEPTED : PushRepository::STATUS_REJECTED,
                'operator_id' => Auditor::identity()['admin_id'],
            ]);
        } catch (Throwable $e) {
            $recorded = false;
            $recordError = $e->getMessage();
            Log::error('推送受理记录落库失败（推送本身的结果不受影响）', [
                'request_id' => $requestId,
                'accepted' => $accepted,
                'error' => $e->getMessage(),
            ]);
        }

        $auditOk = Auditor::record([
            'action' => Auditor::ACTION_PUSH_CREATE,
            'target_type' => $job['target_type'],
            'target' => $job['target'],
            // ⚠ **不把 payload 写进审计**：它已完整落在 `push_task.payload`，
            // 重复存一份只会放大敏感数据的暴露面（载荷里有没有密钥，后台无从判断）。
            'params' => [
                'request_id' => $requestId,
                'msg_id' => $job['msg_id'],
                'msg_id_generated' => $check['msg_id_generated'],
                'offline_mode' => $effectiveMode,
                'payload_bytes' => $check['bytes'],
            ],
            'result' => $accepted ? Auditor::RESULT_OK : Auditor::RESULT_FAILED,
            'code' => (int)($res['code'] ?? 0),
            'msg' => $accepted ? 'accepted' : $upstreamMsg,
        ], $request);

        $body = [
            'request_id' => $requestId,
            'accepted' => $accepted,
            'target_type' => $job['target_type'],
            'target' => $job['target'],
            'target_label' => Pusher::labelOfTargetType($job['target_type']),
            'msg_id' => $job['msg_id'],
            'msg_id_generated' => $check['msg_id_generated'],
            'offline_mode' => $effectiveMode,
            'offline_label' => Pusher::labelOfOfflineMode($effectiveMode),
            'payload_bytes' => $check['bytes'],
            'payload_max' => $check['max'],
            // 主项目原始三元组：判读问题时不需要再翻日志
            'http_status' => (int)($res['status'] ?? 0),
            'code' => (int)($res['code'] ?? 0),
            'upstream_msg' => $upstreamMsg,
            'recorded' => $recorded,
            'record_error' => $recordError,
            'audit_ok' => $auditOk,
            // 三条「响应里读不出来」的语义，由后端下发（前端不得自行编词）
            'notes' => [
                Pusher::ACCEPTED_NOTE,
                Pusher::DEDUP_NOTE,
                Pusher::PAYLOAD_DROP_NOTE,
            ],
            'dashboard_url' => self::configString('gateway_push.dashboard_url', ''),
        ];

        if (!$accepted) {
            return ApiReply::fail(
                502,
                ApiReply::CODE_UPSTREAM,
                '主项目未受理：' . ($upstreamMsg !== '' ? $upstreamMsg : 'HTTP ' . (int)($res['status'] ?? 0)),
                $body
            );
        }

        return ApiReply::ok($body);
    }

    /**
     * 推送历史（读 `push_task`）。
     *
     * 查询串：`page` / `size` / `target_type` / `target` / `status` / `msg_id` / `from` / `to`。
     * `from` / `to` 为 `Y-m-d`，**闭区间**（`to` 含当日 23:59:59）。
     *
     * ⚠ 这是**受理记录**，不是投递回执 —— 服务端不落逐条投递历史，故本接口的
     * 「成功」只意味着后台提交并获得了 `code=0`。该事实由 `record_note` 随响应下发。
     */
    public function history(Request $request): Response
    {
        $query = $request->get();

        try {
            $page = PushRepository::page(
                $query,
                $query['page'] ?? null,
                $query['size'] ?? null,
                Settings::int('push.page_size', self::HISTORY_PAGE_SIZE)
            );
            $summary = PushRepository::summary();
        } catch (Throwable $e) {
            // 读空会撒谎（使用者会以为「没人发过推送」），故明确报错而不是回空列表
            return ApiReply::fail(
                503,
                ApiReply::CODE_DB_UNAVAILABLE,
                '读取 push_task 失败（后台 MySQL 不可用或表未迁移）：' . $e->getMessage(),
                ['db_ok' => false, 'items' => [], 'total' => 0]
            );
        }

        return ApiReply::ok([
            'items' => $page['items'],
            'total' => $page['total'],
            'page' => $page['page'],
            'size' => $page['size'],
            'pages' => $page['pages'],
            'filters' => $page['filters'],
            'summary' => $summary,
            'statuses' => PushRepository::STATUSES,
            'size_max' => PushRepository::SIZE_MAX,
            'record_note' => PushRepository::RECORD_NOTE,
        ]);
    }

    /**
     * 模板列表（读 `push_template`）。
     *
     * 不分页：模板是人工维护的少量资产（上限 {@see TemplateRepository::LIST_MAX}）。
     * 真到了需要分页的规模，说明模板被当成数据集在用 —— 那是另一个问题。
     */
    public function templateList(Request $request): Response
    {
        try {
            $items = TemplateRepository::all();
        } catch (Throwable $e) {
            return ApiReply::fail(
                503,
                ApiReply::CODE_DB_UNAVAILABLE,
                '读取 push_template 失败：' . $e->getMessage(),
                ['db_ok' => false, 'items' => []]
            );
        }

        return ApiReply::ok([
            'items' => $items,
            'total' => count($items),
            'list_max' => TemplateRepository::LIST_MAX,
            'note' => Pusher::TEMPLATE_NOTE,
        ]);
    }

    /**
     * 新建或编辑一条模板（**写**）。
     *
     * 请求体：`id`（可选，给了就是编辑）/ `name` / `target_type` / `payload` / `offline_mode` / `remark`。
     *
     * `payload` 与推送一致，接受对象或 JSON 字符串；超限在这里就拦下 ——
     * 存进一个「永远发不出去」的模板比报错更糟（用户以为已经准备好了）。
     */
    public function templateSave(Request $request): Response
    {
        $input = $request->post();
        $payloadMax = Pusher::payloadMax(Settings::int('push.payload_max', 0));

        $check = Pusher::validateTemplate($input, $payloadMax);
        if (!$check['ok']) {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, implode('；', $check['errors']), [
                'errors' => $check['errors'],
                'bytes' => $check['bytes'],
                'max' => $check['max'],
            ]);
        }

        $id = isset($input['id']) && is_numeric($input['id']) ? (int)$input['id'] : null;
        $isEdit = $id !== null && $id > 0;

        try {
            if ($isEdit && TemplateRepository::find($id) === null) {
                return ApiReply::fail(404, ApiReply::CODE_NOT_FOUND, '模板不存在：id=' . $id);
            }

            // 名称唯一性前置检查：`uk_name` 冲突若靠捕获 1062，无法区分是哪个唯一键，
            // 也给不出「换个名字」这种可执行的建议。
            if (TemplateRepository::nameTaken($check['row']['name'], $isEdit ? (int)$id : 0)) {
                return ApiReply::fail(
                    400,
                    ApiReply::CODE_INVALID_ARG,
                    '模板名已存在：' . $check['row']['name'] . '（名称是唯一键，请换一个或改为编辑既有模板）'
                );
            }

            $savedId = TemplateRepository::save(
                $isEdit ? (int)$id : null,
                $check['row'],
                Auditor::identity()['admin_id']
            );
        } catch (Throwable $e) {
            return ApiReply::fail(
                503,
                ApiReply::CODE_DB_UNAVAILABLE,
                '写入 push_template 失败：' . $e->getMessage(),
                ['db_ok' => false]
            );
        }

        $auditOk = Auditor::record([
            'action' => Auditor::ACTION_TEMPLATE_SAVE,
            'target_type' => $check['row']['target_type'],
            'target' => $check['row']['name'],
            'params' => [
                'id' => $savedId,
                'is_edit' => $isEdit,
                'offline_mode' => $check['row']['offline_mode'],
                'payload_bytes' => $check['bytes'],
                'remark' => $check['row']['remark'],
            ],
            'result' => Auditor::RESULT_OK,
            'code' => 0,
            'msg' => $isEdit ? 'updated' : 'created',
        ], $request);

        return ApiReply::ok([
            'id' => $savedId,
            'is_edit' => $isEdit,
            'template' => TemplateRepository::find($savedId),
            'payload_bytes' => $check['bytes'],
            'payload_max' => $check['max'],
            'audit_ok' => $auditOk,
            'note' => Pusher::TEMPLATE_NOTE,
        ]);
    }

    /**
     * 删除一条模板（**写**，硬删除）。
     *
     * ⚠ `id` 走**路径参数**（`DELETE /api/push/templates/{id}`）而不是请求体：
     * DELETE 携带 body 在 fetch / 反向代理 / 网关链路上的兼容性参差不齐，
     * 而路径参数无歧义。签名与 `api\SessionController::detail()` 保持同一范式。
     *
     * 审计里记的是**模板名**（而不是 id）：模板删掉后 id 就查不到了，
     * 只剩 id 的审计记录等于没记。
     */
    public function templateDelete(string $id, Request $request): Response
    {
        if (!ctype_digit($id) || (int)$id <= 0) {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'id 必须是正整数（当前：' . $id . '）');
        }
        $id = (int)$id;

        try {
            $existing = TemplateRepository::find($id);
            if ($existing === null) {
                return ApiReply::fail(404, ApiReply::CODE_NOT_FOUND, '模板不存在：id=' . $id);
            }

            $deleted = TemplateRepository::delete($id);
        } catch (Throwable $e) {
            return ApiReply::fail(
                503,
                ApiReply::CODE_DB_UNAVAILABLE,
                '删除 push_template 失败：' . $e->getMessage(),
                ['db_ok' => false]
            );
        }

        $auditOk = Auditor::record([
            'action' => Auditor::ACTION_TEMPLATE_DELETE,
            'target_type' => (string)$existing['target_type'],
            'target' => (string)$existing['name'],
            'params' => ['id' => $id, 'deleted' => $deleted],
            'result' => $deleted ? Auditor::RESULT_OK : Auditor::RESULT_FAILED,
            'code' => 0,
            'msg' => $deleted ? 'deleted' : 'not-deleted',
        ], $request);

        return ApiReply::ok([
            'id' => $id,
            'name' => (string)$existing['name'],
            'deleted' => $deleted,
            'audit_ok' => $auditOk,
        ]);
    }

    /**
     * 取配置里的字符串项（`config()` 返回 `mixed`，此处统一收口）。
     *
     * 不用 `(string)` 直接强转：`config()` 的返回值可能是数组，
     * 强转数组会得到 `"Array"` 并触发 notice —— 一个只会在日志里出现、页面上表现为
     * 「URL 变成了 Array」的隐性 bug。
     */
    private static function configString(string $key, string $default = ''): string
    {
        $value = config($key, $default);

        return is_scalar($value) ? (string)$value : $default;
    }
}
