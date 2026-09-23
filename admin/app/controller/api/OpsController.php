<?php

declare(strict_types=1);

namespace app\controller\api;

use app\service\GatewayPushClient;
use app\service\RedisReader;
use support\Request;
use support\Response;

/**
 * 运维自检 API（只读）。
 *
 * 权限点（`wa_rules.key`）：`app\controller\api\OpsController@redisScan`
 *
 * 本控制器**刻意不提供**任何写操作（不做「直连 Redis 执行命令」这类通用入口）——
 * 后台改变推送系统状态一律走主项目 HTTP API，见设计文档 §4.2 / §9.2「审计绕过」风险项。
 */
final class OpsController
{
    /**
     * Redis 键空间巡检：既证明「后台确实连到了主项目那个 Redis」，
     * 也把「配错 DB / PREFIX 导致的静默空数据」变成一条可读的诊断结论。
     *
     * `RedisReader::selfCheck()` 只对**骨架键**做 EXISTS / TYPE / TTL，成本恒定，
     * 刻意不 SCAN 全库（避免在大 keyspace 上拖住 worker）。
     */
    public function redisScan(Request $request): Response
    {
        $reader = new RedisReader();
        $selfCheck = $reader->selfCheck();

        return json([
            'code' => $selfCheck['ok'] ? 0 : 1,
            'msg' => $selfCheck['ok'] ? 'ok' : $selfCheck['hint'],
            'data' => [
                'ok' => $selfCheck['ok'],
                'prefix' => RedisReader::prefix(),
                'db' => RedisReader::dbIndex(),
                'ping' => $reader->ping(),
                'db_size' => $reader->dbSize(),
                'checks' => $selfCheck['checks'],
                'hint' => $selfCheck['hint'],
            ],
        ]);
    }

    /**
     * 主项目 API 连通性与密钥状态（只暴露「是否已配置」，绝不回显密钥）。
     */
    public function apiProbe(Request $request): Response
    {
        $client = new GatewayPushClient(
            (string)config('gateway_push.api_url', 'http://127.0.0.1:8290'),
            (string)config('gateway_push.api_secret', '')
        );
        $health = $client->health();

        return json([
            'code' => 0,
            'msg' => 'ok',
            'data' => [
                'url' => $client->apiUrl(),
                'has_secret' => $client->hasSecret(),
                'ok' => $health['ok'],
                'status' => $health['status'],
                'code_detail' => $health['code'],
                'msg_detail' => $health['msg'],
                'health' => $health['data'],
            ],
        ]);
    }
}
