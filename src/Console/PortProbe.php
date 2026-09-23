<?php
/**
 * 端口占用探测
 *
 * 独立成类而非内联进自检：EnvChecker（自检报告）与 Banner（服务清单）都要判断端口
 * 监听状态，两者的判定必须完全一致 —— 否则会出现「自检说端口被占用、横幅说未监听」
 * 这种自相矛盾的报告。
 *
 * Windows 上不能用 bind 探测，详见 isUsed() 内注释。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Console;

/**
 * 端口占用探测
 *
 * 独立成类以保证自检报告与启动横幅判定一致；Windows 走 netstat 快照而非 bind 探测。
 */
final class PortProbe
{
    /**
     * netstat 快照缓存（按协议）
     *
     * check 一次要探测 5 个端口，逐端口调用 netstat 会带来数百毫秒的无谓开销，
     * 故一次进程调用取回全部端口后按协议缓存。
     *
     * @var array<string,array<int,bool>>
     */
    private static array $netstatCache = [];

    /**
     * 判断监听地址对应的端口是否已被占用
     *
     * @param string $listen 形如 websocket://0.0.0.0:8282 / udp://0.0.0.0:8283 / tcp://127.0.0.1:1238
     *
     * @return bool true 表示已被占用
     */
    public static function isUsed($listen)
    {
        $isUdp  = stripos((string)$listen, 'udp://') === 0;
        $target = (string)preg_replace('#^[a-z]+://#i', '', (string)$listen);

        // Windows 的 socket 默认允许重复 bind（PHP 未暴露 SO_EXCLUSIVEADDRUSE，
        // stream_socket_server 也不会设置它），端口已被监听时本地 bind 依然成功 ——
        // 用 bind 判定会恒返回"未占用"，使占用提示与监听状态彻底失效。
        // 故 Windows 改用 netstat 快照判定；Linux 无此特性，bind 探测即准确。
        if (DIRECTORY_SEPARATOR !== '/' && function_exists('exec')) {
            $colon = strrpos($target, ':');
            if ($colon !== false) {
                $ports = self::usedPortsByNetstat($isUdp ? 'udp' : 'tcp');

                return isset($ports[(int)substr($target, $colon + 1)]);
            }
        }

        $target = str_replace('0.0.0.0', '127.0.0.1', $target);

        $errno  = 0;
        $errstr = '';
        $socket = @stream_socket_server(
            ($isUdp ? 'udp://' : 'tcp://') . $target,
            $errno,
            $errstr,
            STREAM_SERVER_BIND
        );

        if ($socket === false) {
            return true;
        }
        @fclose($socket);

        return false;
    }

    /**
     * 本机已占用端口快照（仅 Windows 使用）
     *
     * netstat 输出的状态列在中文 Windows 下仍为英文（LISTENING），可安全匹配。
     *
     * @param string $protocol 'tcp' 或 'udp'
     *
     * @return array<int|string, mixed> 端口号 => true
     */
    private static function usedPortsByNetstat($protocol)
    {
        if (isset(self::$netstatCache[$protocol])) {
            return self::$netstatCache[$protocol];
        }

        $ports = [];
        $lines = [];
        @exec('netstat -a -n -p ' . strtoupper($protocol), $lines);

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (!is_array($parts) || count($parts) < 3) {
                continue;
            }
            if (strcasecmp($parts[0], $protocol) !== 0) {
                continue;
            }
            // TCP 只认监听态：ESTABLISHED 行里的"本地地址"是本机客户端用的临时端口，
            // 与服务监听无关，计入会凭空制造端口冲突假象。
            if ($protocol === 'tcp' && !in_array('LISTENING', $parts, true)) {
                continue;
            }

            $colon = strrpos($parts[1], ':');
            if ($colon === false) {
                continue;
            }
            $port = (int)substr($parts[1], $colon + 1);
            if ($port > 0) {
                $ports[$port] = true;
            }
        }

        self::$netstatCache[$protocol] = $ports;

        return $ports;
    }
}
