<?php
/**
 * 业务动作清单（声明式）
 *
 * ---------------------------------------------------------------------
 * 为什么放在配置文件而不是代码里
 * ---------------------------------------------------------------------
 * 动作清单属于「结构性配置」——它描述本服务支持哪些业务指令、各自如何
 * 鉴权、参数形状如何、回执怎么走，不随部署环境变化，因此保留在 config 中
 * （与鉴权白名单、定时任务注册表、指标名列表的处理原则一致）。
 *
 * 业务方接入新动作只需两步：
 *   1. 写一个实现 ActionInterface 的处理器类；
 *   2. 在下方 actions 段登记一行。
 * 无需修改 Bootstrap / Router / ActionRunner 任何代码。
 *
 * ---------------------------------------------------------------------
 * 声明字段
 * ---------------------------------------------------------------------
 *   handler     处理器类名，必须实现 GatewayPush\Business\ActionInterface
 *   description 说明文案，供 start.php check 与运维接口展示
 *   auth        是否要求已鉴权（默认 true）。注意：UDP 通道没有连接级鉴权
 *               闸门，其身份完全依赖报文 uid + 签名，故本字段是 UDP 侧
 *               唯一的业务鉴权防线，业务动作不应轻易置 false。
 *   reply       回执方式，按通道分别声明：
 *                 sync  处理结果下发客户端
 *                 none  静默处理，不下发任何报文
 *               注意 UDP 的 sync 是经「出站队列 -> 网关 sendto」下发，
 *               而非直接回包（UDP clientId 不在 Gateway 连接表内）。
 *               HTTP 的 sync 是写入 action:result:{request_id} 由 Api 取回。
 *               未声明的通道回落 sync，故既有双通道声明无需改动。
 *   http        是否开放 HTTP 通道（默认 **false**，即 POST /action 不可调用）。
 *               HTTP 调用方持有接口密钥即代表任意 uid 发起动作，属显式授权，
 *               故采用「默认拒绝、逐动作开启」的白名单语义。
 *               依赖 clientId 语义的动作（如 session）不应开启。
 *               ⚠ 本字段是**单向**的：它只表达「额外开放 HTTP」，
 *               不表达「仅限 HTTP」。WS / UDP 侧**凡注册即可用**。
 *   channels    **反向**通道白名单：`[ActionContext::CHANNEL_HTTP]` 表示
 *               该动作**只**在 HTTP 通道开放，WS / UDP 一律回 4006。
 *               缺省（不声明）= 全通道放行，与既有动作完全向后兼容。
 *               ⚠ 运维动作（kick / revoke / unbind）**必须**声明本字段 ——
 *               否则任何持自己合法 Token 的终端客户端都能经 WS 调用，
 *               属终端提权（管理面能力开放给所有终端用户）。
 *               判定入口：`ActionRunner::channelExposed()`（执行侧裁定）。
 *   timeout     回执超时（秒），处理器未在时限内回执则记指标 + 告警 + 回 5000；
 *               0 表示不启用保护。经 HTTP 调用时必须小于 API_ACTION_WAIT_MS，
 *               否则 Api 会先超窗返回 202。
 *   params      参数规则，见 ParamValidator；'*' 表示原样透传（仅 echo 使用）
 *   options     动作私有配置，处理器经 ActionContext::option() 读取
 *
 * ---------------------------------------------------------------------
 * 兼容 PHP 8.2 ~ 8.5
 */

use GatewayPush\Business\Action\EchoAction;
use GatewayPush\Business\Action\KickAction;
use GatewayPush\Business\Action\NotifyAction;
use GatewayPush\Business\Action\PurgeOfflineAction;
use GatewayPush\Business\Action\ReportAction;
use GatewayPush\Business\Action\RevokeTokenAction;
use GatewayPush\Business\Action\SessionAction;
use GatewayPush\Business\Action\SubscribeAction;
use GatewayPush\Business\Action\TopicsAction;
use GatewayPush\Business\Action\UnbindDeviceAction;
use GatewayPush\Business\Action\UnsubscribeAction;
use GatewayPush\Business\ActionContext;
use GatewayPush\Common\Env;

// 主题名规则：字母数字与 _ : . - 组成，长度 1~64。
// 主题名会直接参与 Redis 键拼接，必须限制字符集以避免键空间被污染。
$topicRule = [
    'type'     => 'string',
    'required' => true,
    'max_len'  => 64,
    'pattern'  => '/^[A-Za-z0-9_:.\-]{1,64}$/',
];

return [
    'defaults' => [
        'auth'    => true,
        'reply'   => [
            ActionContext::CHANNEL_WS   => ActionContext::REPLY_SYNC,
            ActionContext::CHANNEL_UDP  => ActionContext::REPLY_SYNC,
            ActionContext::CHANNEL_HTTP => ActionContext::REPLY_SYNC,
        ],
        'timeout' => Env::int('ACTION_TIMEOUT', 5),
    ],

    'actions' => [
        /* ---------------------------------------------------------------
         | 链路验证类
         --------------------------------------------------------------- */
        'echo' => [
            'handler'     => EchoAction::class,
            'description' => '原样回显，用于联通性验证与压测',
            'params'      => '*',
            'http'        => true,
        ],

        'session' => [
            'handler'     => SessionAction::class,
            'description' => '查询当前连接的会话摘要',
            'params'      => [],
            // 刻意不开放 HTTP：本动作的语义锚点是「当前连接」，HTTP 通道下
            // 没有连接实体（clientId 为 http:{request_id}），调用无意义。
            // 需要按 uid 查会话请走 handleAuth 落库的会话键或新增专用动作。
        ],

        /* ---------------------------------------------------------------
         | 上报类
         |
         | 同一个处理器在 WS 上回执、在 UDP 上静默 —— 处理器内部不含任何
         | 通道判断，差异完全由下方 reply 声明表达。这是本清单的核心示范。
         --------------------------------------------------------------- */
        'report' => [
            'handler'     => ReportAction::class,
            'description' => '数据上报：按主题累加计数（UDP 下静默不回执）',
            'reply'       => [
                ActionContext::CHANNEL_WS   => ActionContext::REPLY_SYNC,
                ActionContext::CHANNEL_UDP  => ActionContext::REPLY_NONE,
                ActionContext::CHANNEL_HTTP => ActionContext::REPLY_SYNC,
            ],
            'params'      => [
                'topic' => $topicRule,
                'count' => ['type' => 'int', 'min' => 1, 'max' => 10000, 'default' => 1],
                'value' => ['type' => 'json'],
            ],
            'options'     => [
                'ttl' => Env::int('ACTION_REPORT_TTL', 86400),
            ],
            'http'        => true,
        ],

        /* ---------------------------------------------------------------
         | 订阅关系类
         |
         | 订阅写入的是 Redis 双向索引；投递入口为 Push::enqueueTopic()，
         | 由业务代码 / HTTP 接口 / 运维命令调用。
         | 刻意不开放客户端 publish 动作：那等于允许任意连接借服务端
         | 向他人广播，属消息伪造面。
         --------------------------------------------------------------- */
        'subscribe' => [
            'handler'     => SubscribeAction::class,
            'description' => '订阅主题',
            'params'      => ['topic' => $topicRule],
            'http'        => true,
        ],

        'unsubscribe' => [
            'handler'     => UnsubscribeAction::class,
            'description' => '取消订阅主题',
            'params'      => ['topic' => $topicRule],
            'http'        => true,
        ],

        'topics' => [
            'handler'     => TopicsAction::class,
            'description' => '查询本人已订阅的主题列表',
            'params'      => [],
            'http'        => true,
        ],

        /* ---------------------------------------------------------------
         | 推送触发类
         |
         | 目标恒为调用方自身 uid，不接受任意 uid 入参。
         --------------------------------------------------------------- */
        'notify' => [
            'handler'     => NotifyAction::class,
            'description' => '请求服务端向本人推送一条消息（验证推送闭环）',
            'params'      => [
                'value'        => ['type' => 'json'],
                'msg_id'       => ['type' => 'string', 'max_len' => 64],
                'offline_mode' => [
                    'type'    => 'string',
                    'enum'    => ['', 'drop', 'queue'],
                    'default' => '',
                ],
            ],
            'http'        => true,
        ],

        /* ---------------------------------------------------------------
         | 运维动作类
         |
         | 三条共同点，改动前务必读懂：
         |   1. `channels => [http]` **不可省略**。省略即「WS/UDP 侧也可用」，
         |      任何已鉴权的终端客户端都能踢掉任意 clientId —— 终端提权。
         |   2. `auth => false`。调用方是持 API_SECRET 的运维端，没有 uid 语义；
         |      若置 true，则 HTTP 调用必须带 uid，而运维动作的目标 uid 是
         |      入参而非调用方身份，两者会混淆。
         |   3. 三者都**不撤会话 / 不踢线**（unbind 不解绑在线连接、revoke 不断连接、
         |      kick 不撤 Token）。组合语义见各处理器类注释，顺序错了会静默失效。
         --------------------------------------------------------------- */
        'kick' => [
            'handler'     => KickAction::class,
            'description' => '【运维】断开指定连接（按 client_id 或 uid）；仅断 TCP，Token 仍有效',
            'channels'    => [ActionContext::CHANNEL_HTTP],
            'auth'        => false,
            'params'      => [
                'client_id' => ['type' => 'string', 'max_len' => 128],
                'uid'       => ['type' => 'string', 'max_len' => 64],
                'reason'    => ['type' => 'string', 'max_len' => 128],
            ],
            'http'        => true,
        ],

        'revoke' => [
            'handler'     => RevokeTokenAction::class,
            'description' => '【运维】撤销一个 Token（按明文 token）；已有连接不立即断开',
            'channels'    => [ActionContext::CHANNEL_HTTP],
            'auth'        => false,
            'params'      => [
                // 只接受明文 token，不接受 fingerprint —— 否则等于开放
                // 「按猜测指纹撤销任意 Token」的接口面。
                'token' => ['type' => 'string', 'required' => true, 'max_len' => 2048],
                'ttl'   => ['type' => 'int', 'min' => 0, 'max' => 2592000],
            ],
            'http'        => true,
        ],

        'unbind' => [
            'handler'     => UnbindDeviceAction::class,
            'description' => '【运维】解绑 uid 与设备；不踢线，已在线的旧设备不受影响',
            'channels'    => [ActionContext::CHANNEL_HTTP],
            'auth'        => false,
            'params'      => [
                'uid' => ['type' => 'string', 'required' => true, 'max_len' => 64],
            ],
            'http'        => true,
        ],

        'purge_offline' => [
            'handler'     => PurgeOfflineAction::class,
            'description' => '【运维】清空 uid 的离线队列（丢弃且不可恢复）；不影响在线投递',
            'channels'    => [ActionContext::CHANNEL_HTTP],
            'auth'        => false,
            'params'      => [
                'uid' => ['type' => 'string', 'required' => true, 'max_len' => 64],
            ],
            'http'        => true,
        ],
    ],
];
