<?php
/**
 * admin 配置 —— bootstrap。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

return [
    support\bootstrap\Session::class,
    // Predis-safe Redis closer：替换 webman/redis 默认的 client()->close()
    // （predis 无 close()，idle 清理会刷 CLOSE 命令异常）。见 deploy.md §13.3。
    app\bootstrap\RedisBootstrap::class,
];
