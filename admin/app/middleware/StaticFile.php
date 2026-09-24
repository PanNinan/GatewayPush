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
 *
 * @see      http://www.workerman.net/
 *
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * Class StaticFile
 */
class StaticFile implements MiddlewareInterface
{
    /**
     * 拒绝以 /. 开头的隐藏文件路径。
     */
    public function process(Request $request, callable $handler): Response
    {
        // Access to files beginning with. Is prohibited
        if (str_contains($request->path(), '/.')) {
            return response('<h1>403 forbidden</h1>', 403);
        }

        return $handler($request);
    }
}
