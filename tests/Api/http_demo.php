<?php
/**
 * GatewayPush HTTP 接口 —— 调用示例（可直接运行）
 * =====================================================================
 * 用途
 * ---------------------------------------------------------------------
 * 一份「拿到就能跑、照着就能抄」的 HTTP 对接样例。它同时演示两件事：
 *
 *   1. 签名怎么构造 —— hex(hmac_sha256("{X-Timestamp}|{原始请求体}"))
 *   2. 两类接口的语义差异 ——
 *        推送类（/push）  异步受理：入队即返回，不等待投递结果
 *        动作类（/action）同步等待：驱动 BusinessWorker 真正执行业务动作，
 *                        并把动作回执经 Redis 回程键带回 HTTP 响应
 *
 * 代码只用 PHP 标准扩展（ext-curl + ext-json），不依赖本项目任何类，
 * 因此可直接照搬为业务系统的接入代码（PHP curl / Guzzle、Java HttpClient、
 * Go net/http，签名算法完全一致）。
 *
 * ---------------------------------------------------------------------
 * 前置条件
 * ---------------------------------------------------------------------
 *   1. Redis 可用；
 *   2. 已启动 api 角色（动作类接口还需 business 角色）：
 *        php start.php start --role=register
 *        php start.php start --role=gateway
 *        php start.php start --role=business
 *        php start.php start --role=api
 *      Windows 下改走 bin\start.bat start（各角色分别开窗口）。
 *
 * ---------------------------------------------------------------------
 * 运行
 * ---------------------------------------------------------------------
 *   php tests/Api/http_demo.php                  # 地址取 .env 的 API_LISTEN
 *   php tests/Api/http_demo.php 127.0.0.1:8290   # 显式指定地址
 *   php tests/Api/http_demo.php --curl           # 额外打印等价的 curl 命令
 *
 * 退出码：0 = 全部断言通过；1 = 存在失败项（含前置条件不满足）。
 *
 * 脚本开头的 [0] 会先探测服务端验签模式（不带签名请求 /stats）：当服务端处于
 * **免签模式**（API_SIGN_ENABLE=false 且监听回环地址）时，第 3 场景
 * 「错误签名必须被拒」不成立，会被标记为 SKIP 而非失败，退出码仍为 0。
 * 其余场景在两种模式下都成立。
 *
 * ---------------------------------------------------------------------
 * 密钥
 * ---------------------------------------------------------------------
 * 密钥按服务端 Api\Bootstrap::apiSecret() 的口径解析：API_SECRET 非空用它，
 * 否则回退 AUTH_SECRET。两者都从项目根 .env 读取，**全程不打印明文**，
 * 只输出来源与长度。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

declare(strict_types=1);

/* =====================================================================
 | 0. 参数与凭据
 ===================================================================== */

$root    = dirname(__DIR__, 2);
$env     = loadEnv($root . '/.env');
$showCurl = in_array('--curl', array_slice($argv, 1), true);

// 地址：命令行 > .env API_LISTEN > 代码默认值
$cliAddr = '';
foreach (array_slice($argv, 1) as $arg) {
    if ($arg !== '' && strpos($arg, '--') !== 0) {
        $cliAddr = $arg;
        break;
    }
}
$listen = $cliAddr !== '' ? $cliAddr : (string)($env['API_LISTEN'] ?? 'http://127.0.0.1:8290');
// 允许写成 http://127.0.0.1:8290 或 127.0.0.1:8290 两种形式
$listen  = preg_replace('#^[a-z]+://#i', '', $listen);
$baseUrl = 'http://' . $listen;

$decideSecret = (string)($env['API_SECRET'] ?? '') !== ''
    ? array((string)$env['API_SECRET'], 'API_SECRET')
    : array((string)($env['AUTH_SECRET'] ?? ''), 'AUTH_SECRET（API_SECRET 未配置，按服务端口径回退）');
$secret = $decideSecret[0];

// 动作类接口的同步等待窗（毫秒）——客户端超时必须大于它，否则会把
// 「服务端仍在正常等待」误判为请求失败
$actionWaitMs = (int)($env['API_ACTION_WAIT_MS'] ?? 6000);
$httpTimeout  = (int)ceil($actionWaitMs / 1000) + 10;

// 演示身份：动作类接口要求 uid（白名单内动作 auth 均为 true）
$uid      = 'demo-http';
$deviceId = 'demo-device';
$topic    = 'demo_http_topic';

echo "GatewayPush HTTP 接口调用示例\n";
echo str_repeat('=', 70) . "\n";
echo "服务地址  : {$baseUrl}\n";
echo "密钥来源  : {$decideSecret[1]}\n";
echo '密钥长度  : ' . strlen($secret) . "（明文不打印）\n";
echo "动作等待窗: {$actionWaitMs} ms（客户端超时取 {$httpTimeout}s）\n";
echo '演示身份  : uid=' . $uid . ' device_id=' . $deviceId . "\n";
echo str_repeat('=', 70) . "\n\n";

$results = [];

/**
 * @param bool $skip 免签模式下不适用的断言：既不算通过也不算失败，显式标记
 */
$check = function (string $name, bool $ok, string $detail = '', bool $skip = false) use (&$results): void {
    // detail 必须一并入档：末尾的失败汇总会读 $item['detail']，
    // 不入档既触发「未定义数组键」告警，又导致失败详情永远打印不出来
    $results[] = array('name' => $name, 'ok' => $ok, 'detail' => $detail, 'skip' => $skip);
    if ($skip) {
        printf("  [SKIP] %s%s\n", $name, $detail !== '' ? '  ' . $detail : '');
        return;
    }
    printf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $detail !== '' ? '  ' . $detail : '');
};

/* =====================================================================
 | 0. 探测服务端验签模式
 |
 | 不带签名请求 /stats：验签开启时必被拒（401 / 4001），免签模式直接 200。
 | 第 3 场景断言的是「错误签名必须被拒」，免签模式下该断言不成立 —— 按 SKIP 处理，
 | 否则本地调试环境会一直误报失败。其余场景两种模式下均成立。
 ===================================================================== */

echo "[0] 探测验签模式 —— 不带签名请求 /stats\n";
$probe = httpCall('GET', $baseUrl . '/stats', [], '', $httpTimeout);
if (!$probe['ok']) {
    fwrite(STDERR, '[FATAL] 接口不可达：' . $probe['error'] . "\n");
    fwrite(STDERR, "       请确认 api 角色已启动，且地址为 {$baseUrl}\n");
    exit(1);
}

$freeMode = $probe['status'] === 200;
printf(
    "    HTTP %d -> %s\n\n",
    $probe['status'],
    $freeMode
        ? '免签模式（API_SIGN_ENABLE=false 且监听回环地址），第 3 场景将 SKIP'
        : '验签模式'
);

// 免签模式下密钥不参与校验，为空也能正常演示
if ($secret === '' && !$freeMode) {
    fwrite(STDERR, "[FATAL] 未从 .env 读到 API_SECRET / AUTH_SECRET，无法构造签名。\n");
    exit(1);
}

/* =====================================================================
 | 1. 存活探测 —— 唯一免鉴权接口
 ===================================================================== */

echo "[1] GET /health —— 免鉴权存活探测\n";
$res = httpCall('GET', $baseUrl . '/health', [], '', $httpTimeout);
if (!$res['ok']) {
    fwrite(STDERR, '[FATAL] 接口不可达：' . $res['error'] . "\n");
    fwrite(STDERR, "       请确认 api 角色已启动，且地址为 {$baseUrl}\n");
    exit(1);
}
$check(
    'HTTP 200 且 code=0',
    $res['status'] === 200 && (int)$res['json']['code'] === 0,
    "HTTP {$res['status']} / code " . json_encode($res['json']['code'] ?? null)
);
echo "\n";

/* =====================================================================
 | 2. 指标快照 —— GET 无请求体，签名基于空串
 ===================================================================== */

echo "[2] GET /stats —— 空请求体签名（hmac_sha256(\"{ts}|\")）\n";
$res = httpCall('GET', $baseUrl . '/stats', signedHeaders('', $secret), '', $httpTimeout);
$snapshotKeys = isset($res['json']['data']) && is_array($res['json']['data'])
    ? implode(' / ', array_keys($res['json']['data']))
    : '';
$check(
    'HTTP 200 且返回指标快照',
    $res['status'] === 200 && (int)$res['json']['code'] === 0,
    $snapshotKeys !== '' ? "分区：{$snapshotKeys}" : "响应：{$res['body']}"
);
echo "\n";

/* =====================================================================
 | 3. 错误签名 —— 必须被拒
 ===================================================================== */

echo "[3] GET /stats —— 错误签名" . ($freeMode ? "（免签模式，本项 SKIP）\n" : "（期望 401）\n");
$ts  = (string)time();
$bad = array(
    'X-Timestamp: ' . $ts,
    'X-Sign: ' . hash_hmac('sha256', $ts . '|garbage', $secret),
);
$res = httpCall('GET', $baseUrl . '/stats', $bad, '', $httpTimeout);
$check(
    '错误签名被拒绝',
    $res['status'] === 401,
    $freeMode
        ? '免签模式下签名不参与校验，本项不适用'
        : "HTTP {$res['status']} / code " . json_encode($res['json']['code'] ?? null),
    $freeMode
);
echo "\n";

/* =====================================================================
 | 4. 推送类接口 —— 异步受理，不等待投递结果
 ===================================================================== */

echo "[4] POST /push —— 异步受理（入队即返回）\n";
$pushBody = json_encode(array(
    'target_type'  => 'uid',
    'target'       => $uid,
    'payload'      => array('action' => 'notify', 'from' => 'http_demo', 'content' => 'hello over http'),
    'msg_id'       => 'demo-push-' . time(),
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$res = httpCall('POST', $baseUrl . '/push', signedHeaders($pushBody, $secret, true), $pushBody, $httpTimeout);
$check(
    'HTTP 200 且任务被受理',
    $res['status'] === 200 && (int)$res['json']['code'] === 0,
    '响应 msg_id：' . json_encode($res['json']['data']['msg_id'] ?? null)
);
echo "      注：这里只表示「任务已入队」。真实投递结果需由在线客户端观察，\n";
echo "          或用 php start.php push ... 复现，参见 tests/e2e_check.php 用例 E。\n\n";

/* =====================================================================
 | 5. 动作类接口 —— 同步等待 BusinessWorker 执行结果
 | ---------------------------------------------------------------------
 | 链路：POST /action -> 白名单校验 -> RPUSH queue:action:in
 |       -> business 进程取批 -> ActionRunner::run(channel=http)
 |       -> 回执写 action:result:{request_id} -> api 轮询取回 -> HTTP 响应
 ===================================================================== */

echo "[5] POST /action {action:echo} —— 同步等待，params 原样回显\n";
$echoParams = array('probe' => 'http-demo', 'n' => 42);
$res = callAction($baseUrl, $secret, array(
    'action' => 'echo',
    'uid'    => $uid,
    'params' => $echoParams,
), $httpTimeout);

$requestId = (string)($res['json']['data']['request_id'] ?? '');
$result    = isset($res['json']['data']['result']) && is_array($res['json']['data']['result'])
    ? $res['json']['data']['result'] : [];

$check('HTTP 200 / code 0 / status=done', $res['status'] === 200
    && (int)$res['json']['code'] === 0
    && ($res['json']['data']['status'] ?? '') === 'done',
    "HTTP {$res['status']} / status " . json_encode($res['json']['data']['status'] ?? null));
$check('params 原样回显', ($result['params'] ?? null) === $echoParams);
$check(
    '回执回报 channel=http',
    ($result['channel'] ?? '') === 'http',
    'channel=' . json_encode($result['channel'] ?? null) . '（证明 ActionRunner 通道判定正确）'
);
$check('响应带 request_id（可用于补查）', $requestId !== '', 'request_id=' . ($requestId !== '' ? $requestId : '(缺失)'));
echo "\n";

/* =====================================================================
 | 6. 有状态动作 —— 证明动作真的在业务进程执行
 ===================================================================== */

echo "[6] POST /action {action:report} ×2 —— 计数递增（证明落库而非 api 自造回执）\n";
$reportBody = array(
    'action' => 'report',
    'uid'    => $uid,
    'params' => array('topic' => $topic, 'count' => 1),
);
$first  = callAction($baseUrl, $secret, $reportBody, $httpTimeout);
$second = callAction($baseUrl, $secret, $reportBody, $httpTimeout);

$t1 = (int)($first['json']['data']['result']['total'] ?? -1);
$t2 = (int)($second['json']['data']['result']['total'] ?? -1);
$check(
    '两次调用均成功且 total 递增 1',
    $first['status'] === 200 && (int)$first['json']['code'] === 0
        && $second['status'] === 200 && (int)$second['json']['code'] === 0
        && $t2 === $t1 + 1,
    "topic={$topic} total {$t1} -> {$t2}"
);
echo "\n";

/* =====================================================================
 | 7. 订阅关系 —— 跨请求状态保持（subscribe -> topics -> unsubscribe）
 ===================================================================== */

echo "[7] POST /action {subscribe / topics / unsubscribe} —— 订阅闭环\n";
$sub = callAction($baseUrl, $secret, array(
    'action' => 'subscribe', 'uid' => $uid, 'params' => array('topic' => $topic),
), $httpTimeout);
$check('subscribe 成功', $sub['status'] === 200 && (int)$sub['json']['code'] === 0,
    'HTTP ' . $sub['status'] . ' / code ' . json_encode($sub['json']['code'] ?? null));

$list = callAction($baseUrl, $secret, array('action' => 'topics', 'uid' => $uid), $httpTimeout);
$topics = $list['json']['data']['result']['topics'] ?? [];
$check('topics 查得到刚订阅的主题', is_array($topics) && in_array($topic, $topics, true),
    'topics=' . json_encode($topics, JSON_UNESCAPED_UNICODE));

$unsub = callAction($baseUrl, $secret, array(
    'action' => 'unsubscribe', 'uid' => $uid, 'params' => array('topic' => $topic),
), $httpTimeout);
$check('unsubscribe 成功', $unsub['status'] === 200 && (int)$unsub['json']['code'] === 0);

$list2  = callAction($baseUrl, $secret, array('action' => 'topics', 'uid' => $uid), $httpTimeout);
$topics2 = $list2['json']['data']['result']['topics'] ?? [];
$check('取消后主题已移除', is_array($topics2) && !in_array($topic, $topics2, true),
    'topics=' . json_encode($topics2, JSON_UNESCAPED_UNICODE));
echo "\n";

/* =====================================================================
 | 8. 触发型动作 —— 动作内调用推送服务
 ===================================================================== */

echo "[8] POST /action {action:notify} —— 动作内触发一次定向推送\n";
$msgId = 'demo-notify-' . time();
$res = callAction($baseUrl, $secret, array(
    'action' => 'notify',
    'uid'    => $uid,
    'params' => array('value' => array('hello' => 'world'), 'msg_id' => $msgId),
), $httpTimeout);
$notifyResult = $res['json']['data']['result'] ?? [];
$check(
    'HTTP 200 且推送任务已入队',
    $res['status'] === 200 && (int)$res['json']['code'] === 0 && (int)($notifyResult['queued'] ?? 0) === 1,
    'msg_id=' . json_encode($notifyResult['msg_id'] ?? null)
);
echo "      注：目标是调用方自身 uid，实际收到与否取决于该 uid 是否在线。\n\n";

/* =====================================================================
 | 9~11. 拒绝路径 —— 三类失败的语义分层
 ===================================================================== */

echo "[9] POST /action {action:session} —— 未开放 HTTP 通道（期望 400 / 4006）\n";
$res = callAction($baseUrl, $secret, array('action' => 'session', 'uid' => $uid), $httpTimeout);
$check(
    '未开放的动作被拒绝',
    $res['status'] === 400 && (int)$res['json']['code'] === 4006,
    "HTTP {$res['status']} / code " . json_encode($res['json']['code'] ?? null)
    . ' / ' . json_encode($res['json']['msg'] ?? '', JSON_UNESCAPED_UNICODE)
);
echo "\n";

echo "[10] POST /action {action:no_such_action} —— 未知动作（期望 400 / 4006）\n";
$res = callAction($baseUrl, $secret, array('action' => 'no_such_action', 'uid' => $uid), $httpTimeout);
$check(
    '未知动作被拒绝',
    $res['status'] === 400 && (int)$res['json']['code'] === 4006,
    "HTTP {$res['status']} / code " . json_encode($res['json']['code'] ?? null)
);
echo "\n";

echo "[11] POST /action {action:report} 缺 topic —— 动作级参数错误（期望 HTTP 200 / code 4007）\n";
$res = callAction($baseUrl, $secret, array('action' => 'report', 'uid' => $uid, 'params' => array()), $httpTimeout);
$check(
    '语义分层：传输成功（200）+ 业务失败（4007）',
    $res['status'] === 200 && (int)$res['json']['code'] === 4007
        && ($res['json']['data']['status'] ?? '') === 'failed',
    "HTTP {$res['status']} / code " . json_encode($res['json']['code'] ?? null)
);
echo "      这一条是动作类接口最容易被误判的地方：HTTP 200 只代表「报文被正确\n";
echo "      受理并执行完毕」，业务成败看响应体里的 code（0 才是成功）。\n\n";

/* =====================================================================
 | 12~13. 回执补查
 ===================================================================== */

echo "[12] GET /action/{request_id} —— 用第 5 步的 request_id 补查回执\n";
$res = httpCall('GET', $baseUrl . '/action/' . $requestId, signedHeaders('', $secret), '', $httpTimeout);
$check(
    'TTL 内可重复读取到已完成回执',
    $res['status'] === 200 && (int)$res['json']['code'] === 0
        && ($res['json']['data']['status'] ?? '') === 'done',
    "HTTP {$res['status']} / status " . json_encode($res['json']['data']['status'] ?? null)
);
echo "      注：POST /action 若超过等待窗未完成会返回 202 + request_id，\n";
echo "          调用方凭该 id 查询此接口即可取得最终结果（结果由 ACTION_RESULT_TTL 决定存续时长）。\n\n";

echo "[13] GET /action/{不存在的 id} —— 期望 404\n";
$res = httpCall('GET', $baseUrl . '/action/ffffffffffffffff', signedHeaders('', $secret), '', $httpTimeout);
$check(
    '不存在的 request_id 返回 404',
    $res['status'] === 404,
    "HTTP {$res['status']} / code " . json_encode($res['json']['code'] ?? null)
);
echo "\n";

/* =====================================================================
 | 汇总
 ===================================================================== */

$fail = 0;
$skip = 0;
foreach ($results as $item) {
    if (!empty($item['skip'])) {
        $skip++;
        continue;
    }
    if (!$item['ok']) {
        $fail++;
    }
}

$checked = count($results) - $skip;
echo str_repeat('=', 70) . "\n";
printf(
    "示例执行结论：%s（%d/%d%s）\n",
    $fail === 0 ? '全部通过' : "失败 {$fail} 项",
    $checked - $fail,
    $checked,
    $skip > 0 ? "，另 SKIP {$skip} 项（免签模式）" : ''
);
if ($fail > 0) {
    foreach ($results as $item) {
        if (!$item['ok'] && empty($item['skip'])) {
            echo '  [FAIL] ' . $item['name'] . ($item['detail'] !== '' ? '  ' . $item['detail'] : '') . "\n";
        }
    }
}
echo str_repeat('=', 70) . "\n";

if ($showCurl) {
    printCurlAppendix($baseUrl, $uid, $secret, $actionWaitMs);
}

exit($fail === 0 ? 0 : 1);

/* =====================================================================
 | 辅助函数
 ===================================================================== */

/**
 * 极简 .env 解析（保持示例自包含，不依赖服务端 Env 类）
 *
 * @param string $path
 * @return array<string,string>
 */
function loadEnv(string $path): array
{
    $out = [];
    if (!is_file($path)) {
        return $out;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim((string)$line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $m) === 1) {
            $out[$m[1]] = trim($m[2], "\"' \t");
        }
    }

    return $out;
}

/**
 * 构造签名请求头
 *
 * 签名规则：hex(hmac_sha256("{X-Timestamp}|{原始请求体}", secret))
 * 注意参与签名的是**未经任何改写的原始 body 字节**，因此请求体一旦序列化
 * 就不可再动（Postman 里常见坑：{{$timestamp}} 等动态变量在签名之后才解析，
 * 导致签名与实际发送字节不一致而 401）。
 *
 * @param string $rawBody
 * @param string $secret
 * @param bool   $withJsonType 是否附带 Content-Type: application/json
 * @return array<int,string>
 */
function signedHeaders(string $rawBody, string $secret, bool $withJsonType = false): array
{
    $ts = (string)time();

    $headers = array(
        'X-Timestamp: ' . $ts,
        'X-Sign: ' . hash_hmac('sha256', $ts . '|' . $rawBody, $secret),
    );

    if ($withJsonType) {
        array_unshift($headers, 'Content-Type: application/json');
    }

    return $headers;
}

/**
 * 发起 HTTP 请求
 *
 * @param string             $method
 * @param string             $url
 * @param array<int,string>  $headers 已格式化的请求头
 * @param string             $body
 * @param int                $timeout 秒
 * @return array{ok:bool,status:int,body:string,json:array<string,mixed>,error:string}
 */
function httpCall(string $method, string $url, array $headers, string $body, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => $timeout,
    ));
    if ($body !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw   = curl_exec($ch);
    $errNo = curl_errno($ch);
    $err   = (string)curl_error($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false || $errNo !== 0) {
        return array(
            'ok'     => false,
            'status' => $code,
            'body'   => '',
            'json'   => [],
            'error'  => $err !== '' ? $err : 'curl error #' . $errNo,
        );
    }

    $decoded = json_decode((string)$raw, true);

    return array(
        'ok'     => true,
        'status' => $code,
        'body'   => (string)$raw,
        'json'   => is_array($decoded) ? $decoded : [],
        'error'  => '',
    );
}

/**
 * 调用 POST /action（自动签名）
 *
 * @param string              $baseUrl
 * @param string              $secret
 * @param array<string,mixed> $job
 * @param int                 $timeout
 * @return array{ok:bool,status:int,body:string,json:array<string,mixed>,error:string}
 */
function callAction(string $baseUrl, string $secret, array $job, int $timeout): array
{
    $raw = (string)json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    echo '      -> POST /action ' . $raw . "\n";

    $res = httpCall('POST', $baseUrl . '/action', signedHeaders($raw, $secret, true), $raw, $timeout);

    echo '      <- HTTP ' . $res['status'] . ' ' . truncate($res['body'], 220) . "\n";

    return $res;
}

/**
 * 截断长文本，保持输出可读
 */
function truncate(string $text, int $max): string
{
    $text = str_replace(array("\r", "\n"), ' ', $text);

    return strlen($text) > $max ? substr($text, 0, $max) . '...(截断)' : $text;
}

/**
 * 附录：等价 curl 命令
 *
 * 密钥以环境变量 $API_SECRET 占位，避免明文落到终端回滚缓冲区。
 * Windows Git Bash 自带 openssl；若不可用可直接 `php tests/Api/http_demo.php`。
 *
 * @param string $baseUrl
 * @param string $uid
 * @param string $secret
 * @param int    $actionWaitMs
 * @return void
 */
function printCurlAppendix(string $baseUrl, string $uid, string $secret, int $actionWaitMs): void
{
    echo "\n附：等价 curl 调用（自行把 API_SECRET 导出为环境变量，签名用同一算法）\n";
    echo str_repeat('-', 70) . "\n";

    $ts  = (string)time();
    $raw = (string)json_encode(array(
        'action' => 'echo',
        'uid'    => $uid,
        'params' => array('probe' => 'curl'),
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sign = hash_hmac('sha256', $ts . '|' . $raw, $secret);

    echo "# 动作调用（同步等待，--max-time 需大于 API_ACTION_WAIT_MS={$actionWaitMs}ms）\n";
    echo "TS={$ts}\n";
    echo "BODY='" . $raw . "'\n";
    // 仅演示计算方式；真实使用时请用 $API_SECRET 由 openssl 现算，不要硬编码签名
    echo "SIGN=\$(printf '%s|%s' \"\$TS\" \"\$BODY\" | openssl dgst -sha256 -hmac \"\$API_SECRET\" -r | cut -d' ' -f1)\n";
    echo "curl -sS -X POST {$baseUrl}/action \\\n";
    echo "  -H 'Content-Type: application/json' \\\n";
    echo "  -H \"X-Timestamp: \$TS\" -H \"X-Sign: \$SIGN\" \\\n";
    echo "  --max-time 20 -d \"\$BODY\"\n";
    echo "\n# 本次运行的 echo 报文实际签名（仅供比对，十分钟内有效）\n";
    echo "  X-Timestamp: {$ts}\n";
    echo "  X-Sign: {$sign}\n";
    echo "\n# 回执补查（GET 无请求体，签名基于空串）\n";
    echo "curl -sS -H \"X-Timestamp: \$TS\" -H \"X-Sign: \$(printf '%s|' \"\$TS\" | openssl dgst -sha256 -hmac \"\$API_SECRET\" -r | cut -d' ' -f1)\" \\\n";
    echo "  {$baseUrl}/action/<request_id>\n";
    echo str_repeat('-', 70) . "\n";
}
