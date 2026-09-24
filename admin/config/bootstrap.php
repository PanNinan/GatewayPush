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

return [
    support\bootstrap\Session::class,
    // Predis-safe Redis closer：替换 webman/redis 默认的 client()->close()
    // （predis 无 close()，idle 清理会刷 CLOSE 命令异常）。见 deploy.md §13.3。
    app\bootstrap\RedisBootstrap::class,
];
