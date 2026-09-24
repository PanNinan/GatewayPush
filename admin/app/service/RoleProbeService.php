<?php
/**
 * admin · 服务层 —— RoleProbeService。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace app\service;

/**
 * 角色状态三源合一（P5，只读）。
 *
 * 回答一个运维最常问的问题：**「6 个角色现在到底谁活着？」**
 *
 * 三个信息源各答一半，**互不替代**：
 *
 * | 源 | 回答 | 局限 |
 * |---|---|---|
 * | ① `roles_cmd`（主项目 `start.php roles` 的 JSON 契约） | **期望**哪些角色启用 | 只看 env 开关，进程死了它照样说启用 |
 * | ② 本机 `netstat` | 端口是否真的在监听 | 只覆盖有监听端口的角色；business 无端口 |
 * | ③ 主项目 `/health` | api→business→Redis 链路是否端到端可用 | 只能反映 api 一角的视角 |
 *
 * 经典误判「e2e 随机失败但没有任何报错」＝两套实例叠在同一个端口上
 * （红线 ㊳：Windows 不拒绝重复 bind）。因此源 ② 还统计**每端口的监听行数**，
 * >1 即提示「疑似重复实例」。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */
final class RoleProbeService
{
    /**
     * 各角色的诊断端口（默认值；主项目 env 改过端口时以 netstat 实测为准）
     *
     * `business` 无监听端口 —— 它以客户端身份连 register / gateway，`null` = 无法端口探测。
     *
     * @var array<string, null|int>
     */
    public const PORTS = [
        'register' => 1238,
        'gateway' => 8282,
        'udp' => 8283,
        'business' => null,
        'api' => 8290,
        'dashboard' => 8291,
    ];

    /** 单端口的「监听行数」警告阈值（>1 = 疑似两套实例叠加，红线 ㊳） */
    private const DUPLICATE_THRESHOLD = 1;

    /**
     * 三源合一
     *
     * @return array{
     *   roles: list<array{role: string, enabled: ?bool, listening: ?bool, listen_count: int, health_ok: ?bool}>,
     *   netstat_available: bool, roles_cmd_available: bool, health: array<string, mixed>,
     *   problems: list<string>
     * }
     */
    public function probe(): array
    {
        [$expected, $rolesCmdAvailable] = $this->expectedRoles();
        [$listenRows, $netstatAvailable] = $this->listenSnapshot();
        $health = GatewayPushClient::fromConfig()->health();

        $roles = [];
        $problems = [];

        foreach (self::PORTS as $role => $port) {
            $enabled = $expected[$role]['enabled'] ?? null;
            $listening = null;
            $listenCount = 0;

            if ($port !== null) {
                if ($netstatAvailable && isset($listenRows[$port])) {
                    $listenCount = $listenRows[$port];
                    $listening = $listenCount > 0;
                }
                if ($listenCount > self::DUPLICATE_THRESHOLD) {
                    $problems[] = "端口 {$port}（{$role}）有 {$listenCount} 个监听 —— 疑似两套实例叠加"
                        . '（红线 ㊳：Windows 不拒绝重复 bind，表现为「e2e 随机失败」而非报错）';
                }
            }

            $healthOk = null;
            if ($role === 'api') {
                $healthOk = (bool)($health['ok'] ?? false);
                if ($enabled === true && $listening === true && $healthOk === false) {
                    $problems[] = 'api 端口在监听但 /health 不通 —— business 或 Redis 可能有问题'
                        . '（/health 是端到端探测，不只测 api 自身）';
                }
            }

            if ($enabled === true && $listening === false) {
                $problems[] = "角色 {$role} 声明启用但端口 {$port} 无监听 —— 进程未启动或已退出";
            }
            if ($enabled === false && $listening === true) {
                $problems[] = "角色 {$role} 声明停用但端口 {$port} 有监听 —— env 开关与实际进程不一致";
            }

            $roles[] = [
                'role' => $role,
                'enabled' => $enabled,
                'listening' => $listening,
                'listen_count' => $listenCount,
                'health_ok' => $healthOk,
            ];
        }

        return [
            'roles' => $roles,
            'netstat_available' => $netstatAvailable,
            'roles_cmd_available' => $rolesCmdAvailable,
            'health' => $health,
            'problems' => $problems,
        ];
    }

    /**
     * 解析 netstat 输出（纯函数，便于单测 Windows / Linux 两种形态）
     *
     * @param string $out `netstat -a -n` 的原始输出
     *
     * @return array<int, int> 端口 => 监听行数
     */
    public static function parseNetstat(string $out): array
    {
        $rows = [];
        foreach (explode("\n", $out) as $line) {
            $line = trim($line);
            if ($line === '' || stripos($line, 'proto') === 0) {
                continue;
            }
            $isTcp = stripos($line, 'tcp') === 0;
            $isUdp = stripos($line, 'udp') === 0;
            if (!$isTcp && !$isUdp) {
                continue;
            }
            // ★ 「listen」不带 ING —— Linux 的 TCP 监听行写的是 `LISTEN`；
            //   Windows 的 `LISTENING` 同样包含它。ESTABLISHED / TIME_WAIT 不含。
            if ($isTcp && stripos($line, 'listen') === false) {
                continue;
            }

            // 抓「本地地址」列的端口：0.0.0.0:8282 / [::]:8282 / 127.0.0.1:1238
            if (preg_match('/:\s*(\d+)\s/', $line, $m) !== 1) {
                continue;
            }
            $port = (int)$m[1];
            $rows[$port] = ($rows[$port] ?? 0) + 1;
        }

        return $rows;
    }

    /**
     * 源 ①：期望角色清单（`roles_cmd` 的 JSON 契约）
     *
     * @return array{0: array<string, array{enabled: bool}>, 1: bool}
     */
    private function expectedRoles(): array
    {
        $cmd = (string)config('gateway_push.roles_cmd', '');
        if ($cmd === '') {
            return [[], false];
        }

        $out = @shell_exec($cmd . ' 2>&1');
        if (!is_string($out) || trim($out) === '') {
            return [[], false];
        }

        $json = json_decode(trim($out), true);
        if (!is_array($json) || !isset($json['roles']) || !is_array($json['roles'])) {
            return [[], false];
        }

        $expected = [];
        foreach ($json['roles'] as $r) {
            if (is_array($r) && isset($r['role'])) {
                $expected[(string)$r['role']] = ['enabled' => (bool)($r['enabled'] ?? false)];
            }
        }

        return [$expected, true];
    }

    /**
     * 源 ②：本机监听快照 —— 端口 => 监听行数
     *
     * ⚠ 判 **UDP** 不能加 `LISTENING` 过滤（Windows 的 UDP 行没有 LISTENING 字样）；
     *   TCP 只认 LISTENING，避免把一条 outgoing 连接当成监听。
     *
     * @return array{0: array<int, int>, 1: bool}
     */
    private function listenSnapshot(): array
    {
        $out = @shell_exec('netstat -a -n 2>&1');
        if (!is_string($out) || trim($out) === '') {
            return [[], false];
        }

        return [self::parseNetstat($out), true];
    }
}
