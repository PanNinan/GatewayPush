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
 *   timeout     回执超时（秒），处理器未在时限内回执则记指标 + 告警 + 回 5000；
 *               0 表示不启用保护。经 HTTP 调用时必须小于 API_ACTION_WAIT_MS，
 *               否则 Api 会先超窗返回 202。
 *   params      参数规则，见 ParamValidator；'*' 表示原样透传（仅 echo 使用）
 *   options     动作私有配置，处理器经 ActionContext::option() 读取
 *
 * ---------------------------------------------------------------------
 * 兼容 PHP 8.1 ~ 8.5
 */

use GatewayPush\Business\Action\EchoAction;
use GatewayPush\Business\Action\NotifyAction;
use GatewayPush\Business\Action\ReportAction;
use GatewayPush\Business\Action\SessionAction;
use GatewayPush\Business\Action\SubscribeAction;
use GatewayPush\Business\Action\TopicsAction;
use GatewayPush\Business\Action\UnsubscribeAction;
use GatewayPush\Business\ActionContext;
use GatewayPush\Common\Env;

// 主题名规则：字母数字与 _ : . - 组成，长度 1~64。
// 主题名会直接参与 Redis 键拼接，必须限制字符集以避免键空间被污染。
$topicRule = array(
    'type'     => 'string',
    'required' => true,
    'max_len'  => 64,
    'pattern'  => '/^[A-Za-z0-9_:.\-]{1,64}$/',
);

return array(

    'defaults' => array(
        'auth'    => true,
        'reply'   => array(
            ActionContext::CHANNEL_WS   => ActionContext::REPLY_SYNC,
            ActionContext::CHANNEL_UDP  => ActionContext::REPLY_SYNC,
            ActionContext::CHANNEL_HTTP => ActionContext::REPLY_SYNC,
        ),
        'timeout' => Env::int('ACTION_TIMEOUT', 5),
    ),

    'actions' => array(

        /* ---------------------------------------------------------------
         | 链路验证类
         --------------------------------------------------------------- */
        'echo' => array(
            'handler'     => EchoAction::class,
            'description' => '原样回显，用于联通性验证与压测',
            'params'      => '*',
            'http'        => true,
        ),

        'session' => array(
            'handler'     => SessionAction::class,
            'description' => '查询当前连接的会话摘要',
            'params'      => [],
            // 刻意不开放 HTTP：本动作的语义锚点是「当前连接」，HTTP 通道下
            // 没有连接实体（clientId 为 http:{request_id}），调用无意义。
            // 需要按 uid 查会话请走 handleAuth 落库的会话键或新增专用动作。
        ),

        /* ---------------------------------------------------------------
         | 上报类
         |
         | 同一个处理器在 WS 上回执、在 UDP 上静默 —— 处理器内部不含任何
         | 通道判断，差异完全由下方 reply 声明表达。这是本清单的核心示范。
         --------------------------------------------------------------- */
        'report' => array(
            'handler'     => ReportAction::class,
            'description' => '数据上报：按主题累加计数（UDP 下静默不回执）',
            'reply'       => array(
                ActionContext::CHANNEL_WS   => ActionContext::REPLY_SYNC,
                ActionContext::CHANNEL_UDP  => ActionContext::REPLY_NONE,
                ActionContext::CHANNEL_HTTP => ActionContext::REPLY_SYNC,
            ),
            'params'      => array(
                'topic' => $topicRule,
                'count' => array('type' => 'int', 'min' => 1, 'max' => 10000, 'default' => 1),
                'value' => array('type' => 'json'),
            ),
            'options'     => array(
                'ttl' => Env::int('ACTION_REPORT_TTL', 86400),
            ),
            'http'        => true,
        ),

        /* ---------------------------------------------------------------
         | 订阅关系类
         |
         | 订阅写入的是 Redis 双向索引；投递入口为 Push::enqueueTopic()，
         | 由业务代码 / HTTP 接口 / 运维命令调用。
         | 刻意不开放客户端 publish 动作：那等于允许任意连接借服务端
         | 向他人广播，属消息伪造面。
         --------------------------------------------------------------- */
        'subscribe' => array(
            'handler'     => SubscribeAction::class,
            'description' => '订阅主题',
            'params'      => array('topic' => $topicRule),
            'http'        => true,
        ),

        'unsubscribe' => array(
            'handler'     => UnsubscribeAction::class,
            'description' => '取消订阅主题',
            'params'      => array('topic' => $topicRule),
            'http'        => true,
        ),

        'topics' => array(
            'handler'     => TopicsAction::class,
            'description' => '查询本人已订阅的主题列表',
            'params'      => [],
            'http'        => true,
        ),

        /* ---------------------------------------------------------------
         | 推送触发类
         |
         | 目标恒为调用方自身 uid，不接受任意 uid 入参。
         --------------------------------------------------------------- */
        'notify' => array(
            'handler'     => NotifyAction::class,
            'description' => '请求服务端向本人推送一条消息（验证推送闭环）',
            'params'      => array(
                'value'        => array('type' => 'json'),
                'msg_id'       => array('type' => 'string', 'max_len' => 64),
                'offline_mode' => array(
                    'type'    => 'string',
                    'enum'    => array('', 'drop', 'queue'),
                    'default' => '',
                ),
            ),
            'http'        => true,
        ),
    ),
);
