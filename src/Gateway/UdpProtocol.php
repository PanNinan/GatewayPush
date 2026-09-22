<?php
/**
 * UDP 应用层协议：报文拆分、编解码与合法性校验
 *
 * UDP 无连接、无原生会话状态，本协议承担「应用层会话识别」的边界职责：
 *  1. input()  判断单包边界（UDP 单包一次到齐，直接返回整包长度）
 *  2. decode() 调用统一报文编解码，非法包直接丢弃
 *  3. encode() 下发报文统一 JSON 编码
 *
 * 重要约束：decode() 内部不可抛出异常。
 * workerman 的 Worker::acceptUdpConnection() 会捕获协议异常并调用 Worker::stopAll()，
 * 导致整个 UDP 网关进程退出，因此所有异常必须在协议层内消化。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Gateway;

use GatewayPush\Business\Message;
use GatewayPush\Common\Logger;
use Workerman\Connection\ConnectionInterface;

/**
 * UDP 应用层协议：拆包、编解码与合法性校验
 *
 * decode() 内部不可抛异常 —— workerman 会捕获协议异常并 stopAll()，导致网关进程退出。
 */
class UdpProtocol
{
    /**
     * 单包最大字节数，<= 0 表示不限制
     *
     * @var int
     */
    public static $maxPacketSize = 8192;

    /**
     * 判断包长
     *
     * @param string              $buffer
     * @param ConnectionInterface $connection
     * @return int 正数表示包长，0 表示继续等待，-1 表示非法包
     */
    public static function input(string $buffer, ConnectionInterface $connection): int
    {
        $length = strlen($buffer);
        if ($length === 0) {
            return 0;
        }
        if (self::$maxPacketSize > 0 && $length > self::$maxPacketSize) {
            return -1;
        }
        // UDP 单包一次到齐，不存在粘包，直接交给 decode
        return $length;
    }

    /**
     * 解码报文
     *
     * @param string              $buffer
     * @param ConnectionInterface $connection
     * @return mixed 返回 false 时框架丢弃该包且不触发 onMessage
     */
    public static function decode(string $buffer, ConnectionInterface $connection): mixed
    {
        try {
            $error  = '';
            $packet = Message::decode($buffer, $error);

            if ($packet === null) {
                Logger::warn('UDP 报文解析失败，已丢弃', array(
                    'remote' => $connection->getRemoteIp() . ':' . $connection->getRemotePort(),
                    'size'   => strlen($buffer),
                    'error'  => $error,
                ));
                return false;
            }

            return $packet;
        } catch (\Throwable $e) {
            // 兜底：协议层异常绝不外抛，避免拖垮整个 worker 进程
            Logger::exception($e, 'udp.protocol.decode');
            return false;
        }
    }

    /**
     * 编码下发报文
     *
     * @param mixed               $data
     * @param ConnectionInterface $connection
     * @return string
     */
    public static function encode(mixed $data, ConnectionInterface $connection): string
    {
        try {
            return Message::encode($data);
        } catch (\Throwable $e) {
            Logger::exception($e, 'udp.protocol.encode');
            return '{}';
        }
    }
}
