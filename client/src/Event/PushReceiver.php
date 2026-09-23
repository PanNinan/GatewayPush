<?php
/**
 * 推送接收器 —— 识别 cmd=push 下行，回调业务并自动回执
 *
 * 职责（设计稿 §5.5）：
 *   - 解析推送报文为「载荷 + 元信息」二元组：
 *       payload = data（业务载荷）
 *       meta    = msg_id / seq / source / offline / pushed_at / ts
 *   - 回调业务 onPush(payload, meta)
 *   - **自动回 cmd=ack**（data.msg_id 对齐；服务端 handleClientAck 据此统计
 *     投递质量 push_ack 指标）。推送是「至少一次」语义，回执不用于去重。
 *   - meta.offline = 1 表示重连补投（服务端离线队列补发），业务可据此区分实时/补投。
 *
 * 用法：
 *   $receiver = new PushReceiver($session);
 *   $receiver->onPush(function (array $payload, array $meta) { ... });
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Client\Event;

use GatewayPush\Client\Session\SessionManager;

/**
 * 推送接收器：识别 cmd=push 下行，回调业务并自动回执
 *
 * 把报文拆为 payload 与 meta 二元组；meta.offline = 1 表示重连补投。
 */
final class PushReceiver
{
    /**
     * @var SessionManager
     */
    private SessionManager $session;

    /**
     * @var null|callable function (array $payload, array $meta): void
     */
    private mixed $onPushCb = null;

    /**
     * 已自动回执的推送数
     *
     * @var int
     */
    private int $acked = 0;

    /**
     * @param SessionManager $session 构造即接管其 onPush 分发
     */
    public function __construct(SessionManager $session)
    {
        $this->session = $session;
        $session->onPush(function (array $packet) {
            $this->handle($packet);
        });
    }

    /**
     * 注册业务推送回调
     *
     * @param callable $cb function (array $payload, array $meta): void
     *                     $payload = 推送载荷（push 报文的 data）
     *                     $meta    = {msg_id, seq, source, offline, pushed_at, ts}
     *
     * @return void
     */
    public function onPush($cb): void
    {
        $this->onPushCb = $cb;
    }

    /**
     * 处理一条推送报文（SessionManager 分发入口）
     *
     * @param array<string, mixed> $packet
     *
     * @return void
     */
    public function handle(array $packet): void
    {
        $msgId = isset($packet['msg_id']) && (string)$packet['msg_id'] !== ''
            ? (string)$packet['msg_id']
            : (string)$packet['seq'];

        $meta = [
            'msg_id'    => $msgId,
            'seq'       => isset($packet['seq']) ? (string)$packet['seq'] : '',
            'source'    => isset($packet['source']) ? (string)$packet['source'] : '',
            'offline'   => isset($packet['offline']) ? (int)$packet['offline'] : 0,
            'pushed_at' => isset($packet['pushed_at']) ? (int)$packet['pushed_at'] : 0,
            'ts'        => isset($packet['ts']) ? (int)$packet['ts'] : 0,
        ];

        $payload = isset($packet['data']) && is_array($packet['data']) ? $packet['data'] : [];

        if ($this->onPushCb !== null) {
            ($this->onPushCb)($payload, $meta);
        }

        // 自动回执（至少一次语义；未 ready 时静默跳过，如断线瞬间收到的最后一条）
        if ($this->session->sendAck($msgId)) {
            $this->acked++;
        }
    }

    /**
     * 已自动回执的推送数
     *
     * @return int
     */
    public function ackedCount(): int
    {
        return $this->acked;
    }
}
