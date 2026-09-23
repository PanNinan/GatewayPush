<?php

declare(strict_types=1);

namespace app\controller\api;

use app\service\ApiReply;
use app\service\GatewayPushClient;
use app\service\LogTailService;
use app\service\RedisReader;
use app\service\RoleProbeService;
use app\service\RotationGuide;
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
        $client = GatewayPushClient::fromConfig();
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

    /**
     * 主项目日志只读尾读（P5）。
     *
     * 参数：`role`（白名单）、`date`（Y-m-d）、`lines`（1~TAIL_MAX）、`keyword`（子串过滤）。
     * 参数非法一律 400；文件不存在返回 `ok=true, not_found=true`（不是错误 —— 「今天还没有日志」）。
     */
    public function logs(Request $request): Response
    {
        $role = trim((string)$request->get('role', ''));
        $date = trim((string)$request->get('date', ''));
        $lines = (int)$request->get('lines', '200');
        $keyword = (string)$request->get('keyword', '');

        if ($role === '' || $date === '') {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'role 与 date 必填');
        }
        if (!in_array($role, LogTailService::ROLES, true)) {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'role 不在白名单内');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return ApiReply::fail(400, ApiReply::CODE_INVALID_ARG, 'date 必须是 Y-m-d 形态');
        }

        $res = (new LogTailService())->tail($role, $date, $lines, $keyword);

        return json([
            'code' => 0,
            'msg' => 'ok',
            'data' => [
                'ok' => $res['ok'],
                'not_found' => $res['ok'] === false && $res['hint'] === '日志文件不存在',
                'hint' => $res['hint'],
                'file' => $res['file'],
                'lines' => $res['lines'],
                'matched' => $res['matched'],
                'truncated' => $res['truncated'],
            ],
        ]);
    }

    /**
     * 角色状态三源合一（P5）：roles JSON + netstat 实测 + /health。
     */
    public function roles(Request $request): Response
    {
        return json(['code' => 0, 'msg' => 'ok', 'data' => (new RoleProbeService())->probe()]);
    }

    /**
     * 密钥轮换 checklist + 密钥现状（P5，只读脱敏）。
     *
     * 密钥展示走 SecretMasker（前 4 后 4），**任何情况下不回显原文**。
     */
    public function rotation(Request $request): Response
    {
        return json(['code' => 0, 'msg' => 'ok', 'data' => RotationGuide::build()]);
    }
}
