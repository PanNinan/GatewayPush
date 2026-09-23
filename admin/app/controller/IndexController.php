<?php

namespace app\controller;

use support\Request;
use support\Response;

/**
 * webman 骨架自带的首页控制器（保留以维持框架默认行为）。
 *
 * 后台真正的入口是 webman/admin 的 `/app/admin`，本控制器仅在访问根路径时出现。
 * 三个方法补齐了原生返回类型以满足 PHPStan L6 —— 骨架生成时未带类型标注。
 */
class IndexController
{
    public function index(Request $request): string
    {
        return <<<EOF
<style>
  * {
    padding: 0;
    margin: 0;
  }
  iframe {
    border: none;
    overflow: scroll;
  }
</style>
<iframe
  src="https://www.workerman.net/wellcome"
  width="100%"
  height="100%"
  allow="clipboard-write"
  sandbox="allow-scripts allow-same-origin allow-popups allow-downloads"
></iframe>
EOF;
    }

    public function view(Request $request): Response
    {
        return view('index/view', ['name' => 'webman']);
    }

    public function json(Request $request): Response
    {
        return json(['code' => 0, 'msg' => 'ok']);
    }

}
