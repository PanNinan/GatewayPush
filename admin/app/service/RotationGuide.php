<?php
/**
 * admin · 服务层 —— RotationGuide。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace app\service;

/**
 * 密钥轮换引导（P5，只读）—— 生成**步骤清单**，不执行任何轮换。
 *
 * 后台刻意不做「一键轮换」：换密钥的爆炸半径横跨主项目 6 角色、后台自身与
 * 全部终端客户端，自动化执行一旦半途失败会把系统留在「新旧密钥不一致」的
 * 中间态（客户端全部 4001），这不是后台该替人拍板的动作。
 *
 * 本类只把「要按什么顺序做、哪些地方必须同步、漏了会怎样」固化成清单，
 * 由人按清单执行。密钥现状展示走 {@see SecretMasker}（前 4 后 4）。
 */
final class RotationGuide
{
    /**
     * 生成轮换 checklist
     *
     * @return array{
     *   secrets: list<array{name: string, scope: string, masked: string, configured: bool}>,
     *   steps: list<array{title: string, detail: string}>
     * }
     */
    public static function build(): array
    {
        $authSecret = (string)getenv('AUTH_SECRET');
        $apiSecret = (string)(getenv('ADMIN_API_SECRET') ?: getenv('API_SECRET') ?: '');

        $secrets = [
            [
                'name' => 'AUTH_SECRET',
                'scope' => 'WS / UDP 报文签名与 Token 签发（主项目 .env）',
                'masked' => SecretMasker::mask($authSecret),
                'configured' => $authSecret !== '',
            ],
            [
                'name' => 'API_SECRET / ADMIN_API_SECRET',
                'scope' => 'HTTP 验签 + 管理面凭证（主项目 .env 与后台 .env 必须同步）',
                'masked' => SecretMasker::mask($apiSecret),
                'configured' => $apiSecret !== '',
            ],
        ];

        return [
            'secrets' => $secrets,
            'steps' => [
                [
                    'title' => '1. 评估影响面，选定变更窗口',
                    'detail' => '换 AUTH_SECRET 会使**全部已签发的 Token 失效**（客户端需重新获取 Token）；'
                        . '换 API_SECRET 会使后台与所有持旧密钥的 HTTP 调用方立即 401。选低峰期执行。',
                ],
                [
                    'title' => '2. 换主项目 AUTH_SECRET（如需）',
                    'detail' => '改主项目 .env 的 AUTH_SECRET → 重启全部 6 角色（配置无热重载，'
                        . '改完不重启 = 新旧密钥并存，行为不可预测）。通知业务方让客户端重新获取 Token。',
                ],
                [
                    'title' => '3. 换 API_SECRET（如需）—— 三个地方必须同步',
                    'detail' => '① 主项目 .env 的 API_SECRET；② 后台 .env 的 ADMIN_API_SECRET（'
                        . '漏掉这一步 = 后台转签运维动作 / 推送全部 401）；'
                        . '③ 全部其他 HTTP 调用方。⚠ API_SECRET 兼任管理面凭证（选项 A）：'
                        . '新密钥同样**不得下发给业务调用方**。',
                ],
                [
                    'title' => '4. 按顺序重启：register → gateway → business → api / dashboard',
                    'detail' => '启动前先 netstat 确认端口空闲（红线 ㊳：Windows 不拒绝重复 bind，'
                        . '两套实例叠加后表现为「e2e 随机失败」而非报错）。',
                ],
                [
                    'title' => '5. 验证清单（全部通过才算完成）',
                    'detail' => '① 主项目 composer test:sign（HTTP 验签 8 形态）；'
                        . '② 后台健康页（/health 探测通过、密钥状态显示新指纹）；'
                        . '③ 后台推送 / 运维动作各真实执行一次（转签链路通畅）；'
                        . '④ composer test:e2e 全绿。',
                ],
            ],
        ];
    }
}
