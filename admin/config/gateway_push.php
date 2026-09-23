<?php
/**
 * 与 GatewayPush 主项目对接的全部参数。
 *
 * 原则（设计文档 §2.1「关键原则」）：
 * - **读走 Redis，写走 HTTP API**。所有会改变推送系统状态的操作
 *   （推送 / 动作）一律走已签名的 HTTP API，不直连 Redis 改状态。
 * - 后台与主项目**不共享进程、不共享鉴权开关**：管理员登录用 webman/admin 的 RBAC（MySQL），
 *   与主项目的 `AUTH_ENABLE` / `API_SIGN_ENABLE` 完全无关。
 * - `ADMIN_API_SECRET` **不进 MySQL**，只从环境变量注入。
 */

$projectRoot = getenv('ADMIN_PROJECT_ROOT') ?: '..';

return [
    // ---- 主项目监听地址 ----
    // 仅回环：主项目 API 无 TLS，不得对外。
    'api_url' => rtrim(getenv('ADMIN_API_URL') ?: 'http://127.0.0.1:8290', '/'),
    'dashboard_url' => rtrim(getenv('ADMIN_DASHBOARD_URL') ?: 'http://127.0.0.1:8291', '/'),

    // ---- 调用主项目 API 的签名密钥 ----
    // 留空则由**服务端**回退到 AUTH_SECRET（与服务端 apiSecret() 行为一致），
    // 后台不需要、也不应该知道回退逻辑。
    //
    // ⚠ 信任域（已决策项 A，2026-09-23）：本密钥同时是**管理面凭证** ——
    //   P4 起 kick / revoke / unbind 三个运维动作以 `channels => [http]` 开放，
    //   持本密钥者即可调用它们。**不得下发给任何业务调用方**。
    //   详见 docs/GatewayPush WebmanAdmin 管理后台详细设计.md §0.4 与 §9.2-R11。
    'api_secret' => getenv('ADMIN_API_SECRET') ?: '',

    // ---- 主项目只读资产（同机挂载）----
    'project_root' => $projectRoot,
    'log_dir' => getenv('ADMIN_LOG_DIR') ?: $projectRoot . '/runtime/logs',
    // 角色启用清单的唯一真源走主项目 CLI 的 JSON 契约（src/Console/Commands.php:48），
    // 后台**不得自行解析 .env**。
    'roles_cmd' => getenv('ADMIN_ROLES_CMD') ?: 'php ' . $projectRoot . '/start.php roles',

    // ---- 主项目 Redis 键空间（只读，见 config/redis.php）----
    'redis_prefix' => getenv('ADMIN_REDIS_PREFIX') ?: 'gwpush:',

    // ---- 后台自身 ----
    // 监听地址必须回环（上游无 TLS）。改这里要同步 .env 与 netstat 检查清单（红线 ㊳）。
    'listen' => getenv('ADMIN_LISTEN') ?: 'http://127.0.0.1:8292',
];
