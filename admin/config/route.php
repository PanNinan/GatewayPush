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
use app\controller\DashboardController;
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
// ⚠ 两个硬约束：
// 1. **必须用 `[Controller::class, 'method']` 形式**（不能写字符串路径或闭包路由）——
//    AdminAuth 依赖 `$request->controller` 去匹配 wa_rules.key，权限模型见
//    plugin/admin/api/Auth.php::canAccess。
// 2. 每个涉及推送系统数据的路由都必须挂 AdminAuth::class；漏挂 = 无需登录即可访问。
// ---------------------------------------------------------------------------

// 根路径直接进管理面（骨架默认欢迎页对后台场景无意义）
Route::get('/', static fn () => redirect('/app/admin'));

// ---- 页面 ----
Route::get('/dashboard', [DashboardController::class, 'index'])->middleware([AdminAuth::class]);

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
})->middleware([AdminAuth::class]);
