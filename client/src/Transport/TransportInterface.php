<?php
/**
 * 传输层统一接口
 *
 * 与设计稿 §5.1 的差异：增加 `onOpen()` —— 15 秒鉴权窗口要求「握手完成的瞬间」
 * 就能触发 auth，SessionManager 无法从 onMessage/onClose 推导出该时机。
 *
 * 回调签名约定（实现类负责把底层签名归一化到这三条）：
 *   onOpen    function (): void                          握手完成（ws 为 HTTP 升级成功）
 *   onMessage function (string $frame): void             收到一帧文本
 *   onClose   function (): void                          连接断开（含本地主动 close）
 *   onError   function (int $code, string $message): void  底层错误（随后通常伴随 onClose）
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Client\Transport;

interface TransportInterface
{
    /**
     * 建立连接（异步：返回不代表已连上，onOpen 才是就绪信号）
     *
     * @return void
     */
    public function connect();

    /**
     * 发送一帧文本
     *
     * @param string $frame
     *
     * @return void
     *
     * @throws \GatewayPush\Client\Error\ClientException 连接未建立时
     */
    public function send($frame);

    /**
     * 主动关闭
     *
     * @return void
     */
    public function close();

    /**
     * 是否处于可用连接状态
     *
     * @return bool
     */
    public function isConnected();

    /**
     * 注册握手完成回调
     *
     * @param callable $cb function (): void
     *
     * @return void
     */
    public function onOpen(callable $cb);

    /**
     * 注册收帧回调
     *
     * @param callable $cb function (string $frame): void
     *
     * @return void
     */
    public function onMessage(callable $cb);

    /**
     * 注册断开回调
     *
     * @param callable $cb function (): void
     *
     * @return void
     */
    public function onClose(callable $cb);

    /**
     * 注册底层错误回调
     *
     * @param callable $cb function (int $code, string $message): void
     *
     * @return void
     */
    public function onError(callable $cb);
}
