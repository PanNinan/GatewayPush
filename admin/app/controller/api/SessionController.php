<?php

declare(strict_types=1);

namespace app\controller\api;

use app\service\ApiReply;
use app\service\SessionInspector;
use support\Request;
use support\Response;

/**
 * M2 会话只读 API（**全部只读**）。
 *
 * 权限点（`wa_rules.key` = `{控制器全类名}@{action}`，由 `scripts/install.php` 写入）：
 * - `app\controller\api\SessionController@index`
 * - `app\controller\api\SessionController@detail`
 * - `app\controller\api\SessionController@byUid`
 * - `app\controller\api\SessionController@byDevice`
 * - `app\controller\api\SessionController@offline`
 * - `app\controller\api\SessionController@subscriptions`
 * - `app\controller\api\SessionController@revoked`
 *
 * 鉴权由 `config/route.php` 挂载的 `app\middleware\AdminAuth` 完成；
 * **漏注册的 action 不会自动放行** —— `config/route.php` 已对本控制器调
 * `Route::disableDefaultRoute()`，未注册动作回落 404（2026-09-23 修复的鉴权洞）。
 *
 * ---------------------------------------------------------------------
 * 路由命名的一处刻意偏离（设计文档 §4.3 是 `/api/sessions/{clientId}`）
 * ---------------------------------------------------------------------
 * 本实现改为**单数 `/api/session/{clientId}`** 承载「单条」，复数 `/api/sessions/*` 承载
 * 「集合与反查」。理由：若按文档把详情写成 `/api/sessions/{clientId}`，它会与
 * `/api/sessions/by-uid/{uid}`、`/api/sessions/offline/{uid}` 等**同层冲突**，
 * 只能靠「先注册具体路由」的书写顺序来消歧 —— 后人在同一组里加一条 `/api/sessions/xxx`
 * 就会静默把详情接口吞掉。单复数分离把这条隐式依赖彻底消掉。已在设计文档登记为偏差。
 *
 * 快慢分层沿用 P1 的结论：本控制器的所有端点都是**按需**（用户点击 / 翻页触发），
 * 不含任何轮询、也不打主项目 HTTP —— 会话视图的数据全在 Redis 里，读不到才需要怀疑配置。
 */
final class SessionController
{
    /**
     * 会话列表（分页）。
     *
     * 查询串：`scope=online|retained|all`、`protocol=ws|udp`、`uid=`、`page=`、`size=`。
     *
     * `scope=online`（默认）走 SMEMBERS + 一次批量 HGETALL，**不 SCAN**；
     * `retained` / `all` 会 SCAN，响应里带 `scan.{scanned,truncated,keys}` ——
     * 前端**必须**展示截断标记，否则运维会把「扫到的一半」当全量。
     */
    public function index(Request $request): Response
    {
        $query = $request->get();
        $inspector = new SessionInspector();

        return ApiReply::ok($inspector->list($query, time()));
    }

    /**
     * 会话详情。
     *
     * `data.found=false` + HTTP 404 表示该 clientId 的会话键已不存在（已被 `unbind()` 回收）；
     * 与之相对，`found=true` 且 `state=retained` 表示「已断开但会话保留」——
     * 这两者的区分正是设计文档 P2 的验收点之一。
     */
    public function detail(string $clientId, Request $request): Response
    {
        if (!SessionInspector::validId($clientId)) {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'clientId 非法（为空、超长或含控制字符）');
        }

        $data = (new SessionInspector())->detail($clientId, $request->get(), time());
        if (!$data['found']) {
            return ApiReply::fail(
                404,
                ApiReply::CODE_NOT_FOUND,
                '会话不存在：该 clientId 的会话键已被回收，或从未建连',
                $data
            );
        }

        return ApiReply::ok($data);
    }

    /**
     * 按 uid 反查会话（`SMEMBERS uid:clients:{uid}`）。
     *
     * ⚠ 该索引 `markOffline()` 不清理，故结果**可能同时包含在线与保留**的连接 ——
     * 这是不依赖 SCAN 就能看到保留会话的唯一入口，前端需按 `state` 分别渲染。
     */
    public function byUid(string $uid, Request $request): Response
    {
        if (!SessionInspector::validId($uid)) {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'uid 非法（为空、超长或含控制字符）');
        }

        return ApiReply::ok((new SessionInspector())->findByUid($uid, time()));
    }

    /**
     * 按设备反查当前活跃 clientId（`GET device:client:{deviceId}`）。
     *
     * 单对一定向的定位依据。同一设备只保留**一个** clientId（`bind()` 覆盖写）。
     */
    public function byDevice(string $deviceId, Request $request): Response
    {
        if (!SessionInspector::validId($deviceId)) {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'deviceId 非法（为空、超长或含控制字符）');
        }

        return ApiReply::ok((new SessionInspector())->findByDevice($deviceId, time()));
    }

    /**
     * 离线队列只读分页（`LLEN` + `LRANGE push:offline:{uid}`）。
     *
     * **刻意不提供任何删除 / 清空入口**：清空离线队列是写操作，属 P4 的显式授权范围
     * （且必须落审计）。一期只读。
     */
    public function offline(string $uid, Request $request): Response
    {
        if (!SessionInspector::validId($uid)) {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'uid 非法（为空、超长或含控制字符）');
        }

        $query = $request->get();
        $inspector = new SessionInspector();
        [$page, $size] = $inspector->pagingFor($query['page'] ?? null, $query['size'] ?? null);

        return ApiReply::ok($inspector->offlineQueue($uid, $page, $size));
    }

    /**
     * 订阅关系双向视图：`?uid=` 看它订阅的主题，`?topic=` 看该主题的订阅者 uid。
     *
     * 两个参数都可选且可同时给 —— `subscribe:uid:{uid}` 与 `subscribe:topic:{topic}`
     * 是两份独立索引，后台不在此处做「一致性校验」（不一致属主项目写入侧问题，
     * 应由 P5 的键巡检暴露，而不是在只读视图里悄悄对齐）。
     */
    public function subscriptions(Request $request): Response
    {
        $query = $request->get();
        $uid = SessionInspector::cleanId($query['uid'] ?? null);
        $topic = SessionInspector::cleanId($query['topic'] ?? null);

        if ($uid === '' && $topic === '') {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, '至少需要 uid 或 topic 之一');
        }

        return ApiReply::ok((new SessionInspector())->subscriptions($uid, $topic));
    }

    /**
     * Token 撤销名单（**指纹** + TTL）。
     *
     * ⚠ 名单键为 `auth:revoked:{sha256(token) 前 32 位}`，**不可逆推 Token**；
     * 后台只展示指纹，不提供任何反解、也不接受 Token 作为入参。
     * 该名单无索引键，只能 SCAN ⇒ 响应必带 `truncated` 标记。
     */
    public function revoked(Request $request): Response
    {
        return ApiReply::ok((new SessionInspector())->revoked());
    }
}
