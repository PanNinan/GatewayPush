<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use app\controller\api\MonitorController;
use app\controller\api\OpsController;
// 别名：API 控制器与页面控制器同名（分属 app\controller\api 与 app\controller），
// 二者在 wa_rules.key 里是不同字符串（`...\api\SessionController@x` vs `...\SessionController`），不会互相顶掉。
use app\controller\api\SessionController as SessionApiController;
use app\controller\DashboardController;
use app\controller\SessionController;
use app\middleware\AdminAuth;
use Webman\Route;

// ---------------------------------------------------------------------------
// GatewayPush 后台自有路由
//
// 与 webman-admin 插件路由（`/app/admin/*`）的关系：
// - 插件路由由框架按「插件名 + 控制器」自动解析，并自带 AccessControl 中间件
//   （作用域见 plugin/admin/config/middleware.php），**不包含**本项目新增的页面与 API；
// - 只有本文件显式声明的路由才会挂 AdminAuth，二者互不干扰。
//
// ⚠ 三个硬约束：
// 1. **必须用 `[Controller::class, 'method']` 形式**（不能写字符串路径或闭包路由）——
//    AdminAuth 依赖 `$request->controller` 去匹配 wa_rules.key，权限模型见
//    plugin/admin/api/Auth.php::canAccess。
// 2. 每个涉及推送系统数据的路由都必须挂 AdminAuth::class；漏挂 = 无需登录即可访问。
// 3. **必须同时对本项目的控制器禁用「默认路由」** —— 见下方 disableDefaultRoute 段。
//
// ---------------------------------------------------------------------------
// 关闭「默认路由」：堵住未显式注册动作的免鉴权入口（2026-09-23 实测修复）
//
// webman 的默认路由会把 `/controller/action` 直接解析到控制器的公有动作
// （解析与判定见 vendor/workerman/webman-framework/src/App.php:172-182）。
// 本文件声明的路由挂在 AdminAuth 上，但**默认路由不经过它们**。实测（不带任何 Cookie）：
//
//   GET /dashboard           → 302  （显式路由，AdminAuth 生效）
//   GET /dashboard/index     → 200  （默认路由，直接渲染整页 HTML）        ✘ 绕过
//   GET /api/ops/redisScan   → 200  （默认路由，返回完整 Redis 自检 JSON）  ✘ 绕过
//   GET /api/ops/redis-scan  → 200  （同上，camelCase 的 kebab 变体）      ✘ 绕过
//   GET /api/ops/apiProbe    → 200  （默认路由，泄露 API URL 与密钥状态）   ✘ 绕过
//   GET /api/monitor/live    → 302  （显式路由；其默认路径恰好等于显式路径，
//                                    显式路由先匹配故侥幸未漏 —— 属「靠巧合安全」）
//
// 根因是**默认路径与显式路径不是同一个 URL 时，显式路由压根不参与匹配**：
// 例如显式路由是 /api/ops/redis/scan，而 OpsController::redisScan 的默认路径是
// /api/ops/redisScan，于是后者的鉴权完全是空的。
//
// 故对本项目自己的控制器逐个禁用默认路由：**未在下方显式注册的动作直接回落 404**。
// ⚠ 不能一刀切写成 `Route::disableDefaultRoute()`：webman-admin 插件的 `/app/admin/*`
//   页面正是靠默认路由解析（`plugin/admin/config/route.php` 只显式注册了验证码与字典两条），
//   全站禁用会让整个后台 UI 失效。
// ⚠ **新增本项目控制器时必须在此补一行**，否则又开一个免鉴权入口。
// ---------------------------------------------------------------------------
Route::disableDefaultRoute(DashboardController::class);
Route::disableDefaultRoute(MonitorController::class);
Route::disableDefaultRoute(OpsController::class);
Route::disableDefaultRoute(SessionApiController::class);
// ⚠ 新增**页面**控制器同样要禁 —— `SessionController::index` 的默认路径是 `/session/index`，
//   不禁的话它就是一个免鉴权的整页入口（与已修复的 `/dashboard/index` 同一类洞）。
Route::disableDefaultRoute(SessionController::class);
// ---------------------------------------------------------------------------

// 根路径直接进管理面（骨架默认欢迎页对后台场景无意义）
Route::get('/', static fn () => redirect('/app/admin'));

// ---- 页面 ----
Route::get('/dashboard', [DashboardController::class, 'index'])->middleware([AdminAuth::class]);
// P2 会话查询页。权限点 = wa_rules.key `app\controller\SessionController`（菜单节点，
// 由 scripts/install.php 注册）；漏登记的表现是「登录后点菜单 403」而不是白屏。
Route::get('/sessions', [SessionController::class, 'index'])->middleware([AdminAuth::class]);

// ---- JSON API（全部只读；写操作永远走主项目 HTTP API，不在此暴露）----
Route::group('/api', static function (): void {
    // 快 tick（默认 5s）：Redis 直读 + 派生率 + 进程表。**不打主项目 HTTP** ——
    // 高频路径必须永远快，否则主项目 API 一挂、面板反而开始卡顿。
    Route::get('/monitor/live', [MonitorController::class, 'live']);
    // 慢 tick（默认 30s）/ 首屏：完整快照（含 selfCheck + dbsize + 主项目 /health 与 /stats）
    Route::get('/monitor/summary', [MonitorController::class, 'summary']);
    // 独立探针：只打主项目 /health，用于区分「Redis 腿断」与「主项目 HTTP 腿断」
    Route::get('/monitor/health', [MonitorController::class, 'health']);
    // 运维自检
    Route::get('/ops/redis/scan', [OpsController::class, 'redisScan']);
    Route::get('/ops/api/probe', [OpsController::class, 'apiProbe']);

    /* -----------------------------------------------------------------------
     | M2 会话只读（P2）
     |
     | 路由命名：**复数 `/sessions/*` 承载集合与反查，单数 `/session/{clientId}` 承载单条**。
     | 这是对设计文档 §4.3（写成 `/api/sessions/{clientId}`）的刻意偏离：后者会与
     | `/api/sessions/by-uid/{uid}`、`/api/sessions/offline/{uid}` 同层冲突，只能靠
     | 「具体路由必须写在前面」的书写顺序消歧 —— 后人在同组加一条就会静默吞掉详情接口。
     |
     | 全部只读。写操作（kick / revoke / unbind / 清空离线队列）属 P4，须先落 C1~C5。
     ----------------------------------------------------------------------- */
    Route::get('/sessions', [SessionApiController::class, 'index']);
    Route::get('/sessions/by-uid/{uid}', [SessionApiController::class, 'byUid']);
    Route::get('/sessions/by-device/{deviceId}', [SessionApiController::class, 'byDevice']);
    Route::get('/sessions/offline/{uid}', [SessionApiController::class, 'offline']);
    Route::get('/sessions/subscriptions', [SessionApiController::class, 'subscriptions']);
    Route::get('/session/{clientId}', [SessionApiController::class, 'detail']);
    Route::get('/auth/revoked', [SessionApiController::class, 'revoked']);
})->middleware([AdminAuth::class]);
