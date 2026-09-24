<?php
/**
 * admin · 服务层 —— RedisReader。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace app\service;

use GatewayPush\Common\RedisKeys;
use support\Redis;
use Throwable;

/**
 * 后台读取 GatewayPush 状态的**唯一入口**（只读门面）。
 *
 * 两条纪律（对应主项目红线 ㉝ 与管理后台设计文档 §5.2）：
 * 1. **禁止手写 Redis 键字面量**。所有键名一律经 `GatewayPush\Common\RedisKeys` 产出 ——
 *    该类零依赖、纯常量 + 静态方法，已通过 admin 的 psr-4 映射
 *    （`"GatewayPush\\": "../src/"`）直接复用主项目真源，不存在「镜像副本漂移」问题。
 * 2. **只读**。本类不暴露任何写命令；后台改变推送系统状态一律走 HTTP API。
 *
 * 前缀由 Redis 客户端自动施加（`config/redis.php` 的 `prefix`，默认 `gwpush:`），
 * 因此这里只传 `RedisKeys` 产出的**逻辑键名**，不手工拼前缀 —— 手工拼会与前缀叠加成 `gwpush:gwpush:xxx`。
 */
final class RedisReader
{
    /** 队列键清单：展示顺序即优先级顺序（积压最影响体感的排前面） */
    public const QUEUE_KEYS = [
        RedisKeys::QUEUE_ACTION_IN,
        RedisKeys::QUEUE_PUSH_OUT,
        RedisKeys::QUEUE_UDP_IN,
        RedisKeys::QUEUE_UDP_OUT,
    ];

    /**
     * 当前生效的 Redis 库号（`ADMIN_REDIS_DB`）。
     *
     * 必须由后台**自己从配置读**，不能依赖主项目任何 API 回带 ——
     * `/health` 的 data 里没有这个字段，早期版本误取它导致页面恒显示 DB 0。
     */
    public static function dbIndex(): int
    {
        $value = config('redis.default.database', 0);

        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * 当前生效的键前缀（`ADMIN_REDIS_PREFIX`，须与主项目 `REDIS_PREFIX` 一致）。
     * 非密钥，可安全展示 —— 用于人工核对「后台真的在看同一个键空间」。
     */
    public static function prefix(): string
    {
        $value = config('redis.default.prefix', 'gwpush:');

        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * 连通性与延迟探测。
     *
     * @return array{ok: bool, latency_ms: float, msg: string}
     */
    public function ping(): array
    {
        $start = microtime(true);

        try {
            // 走 `Redis::connection()->command()` 而非门面的 `Redis::command()`：
            // 前者是 support\Redis 的**真实类型化方法**，返回 Illuminate\Redis\Connections\Connection
            // （其 command() 有完整签名），PHPStan 可解析；
            // 后者只在 @method 注解里存在，会触发 staticMethod.notFound。
            // 语义一致：都不依赖具体客户端实现（phpredis / predis 均可）。
            Redis::connection()->command('ping');
        } catch (Throwable $e) {
            return ['ok' => false, 'latency_ms' => 0.0, 'msg' => $e->getMessage()];
        }

        return ['ok' => true, 'latency_ms' => round((microtime(true) - $start) * 1000, 2), 'msg' => ''];
    }

    /**
     * 指标 gauge 快照（`metrics:gauge` Hash）。
     *
     * ⚠ 该 Hash 的 field **没有独立 TTL**：已退出进程的 `pid_at` / `proc` / `tasks` / `memory_bytes`
     * 需靠主项目 `Monitor::staleFields()` + `purgeExitedProcesses()` 主动 HDEL。
     * 后台做「进程存活」判定应使用 `interval × 2`（默认 10s），**不能**用 `MONITOR_TTL`(600s) ——
     * 两者刻意不同、不可互换。
     *
     * @return array<string, string>
     */
    public function gauge(): array
    {
        $raw = Redis::hGetAll(RedisKeys::METRICS_GAUGE);

        return is_array($raw) ? array_map('strval', $raw) : [];
    }

    /**
     * 指标 counter 快照（`metrics:counter:{Ymd}` Hash）。
     *
     * @param null|string $date `Ymd`（如 20260923）；null = 今天。**不是** `Y-m-d`。
     *
     * @return array<string, string>
     */
    public function counter(?string $date = null): array
    {
        $key = RedisKeys::metricsCounter($date);
        $raw = Redis::hGetAll($key);

        return is_array($raw) ? array_map('strval', $raw) : [];
    }

    /**
     * 四个队列的当前深度。
     *
     * @return array<string, int> 键为逻辑队列名（不含前缀）
     */
    public function queueDepths(): array
    {
        $result = [];
        foreach (self::QUEUE_KEYS as $key) {
            $len = Redis::lLen($key);
            $result[$key] = is_int($len) ? $len : (int)$len;
        }

        return $result;
    }

    /**
     * 在线连接数（`online:clients` 共享 Set，天然全局，跨进程无需聚合）。
     */
    public function onlineCount(): int
    {
        $n = Redis::sCard(RedisKeys::ONLINE_CLIENTS);

        return is_int($n) ? $n : (int)$n;
    }

    /**
     * 离线消息总量与覆盖的 uid 数（`SCAN push:offline:*` + 逐键 `LLEN`，有界）。
     *
     * 与 {@see scanKeys()} 同一套有界纪律：禁止 KEYS、轮次/键数双上限、
     * 超限必须 `truncated=true` 交 UI 显式展示（不允许静默截断成「当前离线量」）。
     *
     * @return array{messages: int, uids: int, scanned: int, truncated: bool}
     */
    public function pushOfflineStats(int $count = 200, int $maxRounds = 50, int $maxKeys = 2000): array
    {
        $scan = $this->scanKeys(RedisKeys::PUSH_OFFLINE . '*', $count, $maxRounds, $maxKeys);
        $keys = $scan['keys'];

        $lengths = $this->batch(
            static function ($pipe) use ($keys): void {
                foreach ($keys as $key) {
                    $pipe->llen($key);
                }
            },
            $keys,
            static fn (string $key) => Redis::lLen($key)
        );

        $messages = 0;
        foreach ($lengths as $len) {
            $messages += is_numeric($len) ? (int)$len : 0;
        }

        return [
            'messages' => $messages,
            'uids' => count($keys),
            'scanned' => $scan['scanned'],
            'truncated' => $scan['truncated'],
        ];
    }

    /**
     * 动作回执积压键数（`SCAN action:result:*`，有界）。
     *
     * 每个键对应一条尚在 `ACTION_RESULT_TTL`（默认 60s）窗口内、
     * 可供 `GET /action/{id}` 补查的回执；数量持续偏高通常意味着
     * 调用方在超窗后仍不停补查，或业务进程消费动作队列变慢。
     *
     * @return array{count: int, scanned: int, truncated: bool}
     */
    public function actionResultBacklog(int $count = 200, int $maxRounds = 50, int $maxKeys = 2000): array
    {
        $scan = $this->scanKeys(RedisKeys::ACTION_RESULT . '*', $count, $maxRounds, $maxKeys);

        return [
            'count' => count($scan['keys']),
            'scanned' => $scan['scanned'],
            'truncated' => $scan['truncated'],
        ];
    }

    /**
     * 「预期键存在性」自检 —— **这是防静默错误的关键兜底**。
     *
     * 动因：`ADMIN_REDIS_DB` / `ADMIN_REDIS_PREFIX` 配错时 Redis 不会报错，
     * 只会读到空数据（页面显示 0）。若不做这项检查，运维会以为「系统没有流量」。
     *
     * 刻意**不用 `SCAN` 全库遍历**：那既有成本又可能在大 keyspace 上拖住 worker。
     * 这里只对「必须存在的骨架键」逐个做 EXISTS / TYPE / TTL，成本恒定且结论明确。
     *
     * @return array{
     *     ok: bool,
     *     checks: list<array{key: string, exists: bool, type: string, ttl: int, expect: string, hint: string}>,
     *     hint: string
     * }
     */
    public function selfCheck(): array
    {
        // 每项：[逻辑键, 期望的 Redis 类型, 缺失时的排查提示]
        $targets = [
            [RedisKeys::METRICS_GAUGE, 'hash', '指标采集未开启（MONITOR_ENABLE=false）或 DB/PREFIX 配错'],
            [RedisKeys::ONLINE_CLIENTS, 'set', '当前无在线连接，或 DB/PREFIX 配错'],
            [RedisKeys::metricsCounter(), 'hash', '今日尚无指标写入，或 DB/PREFIX 配错'],
            [RedisKeys::QUEUE_PUSH_OUT, 'list', '键不存在属正常（队列为空时 Redis 会自动删除空列表）'],
        ];

        $checks = [];
        foreach ($targets as [$key, $expectType, $hint]) {
            $exists = (int)Redis::exists($key) > 0;
            $type = $exists ? (string)Redis::type($key) : 'none';
            $ttl = $exists ? (int)Redis::ttl($key) : -2;

            $checks[] = [
                'key' => $key,          // 逻辑键名；实际键 = prefix + 本名
                'exists' => $exists,
                'type' => $type,
                'ttl' => $ttl,          // -1 = 永久，-2 = 键不存在
                'expect' => $expectType,
                'hint' => $exists && $type === $expectType ? '' : $hint,
            ];
        }

        // 骨架判断：`metrics:gauge` 与 `online:clients` 至少有一个存在，
        // 才认为「DB + PREFIX 配对了」。两者都缺 → 几乎必然是 DB/PREFIX 配错。
        $skeletonHits = 0;
        foreach ($checks as $c) {
            if ($c['exists'] && in_array($c['key'], [RedisKeys::METRICS_GAUGE, RedisKeys::ONLINE_CLIENTS], true)) {
                $skeletonHits++;
            }
        }

        $ok = $skeletonHits > 0;

        return [
            'ok' => $ok,
            'checks' => $checks,
            'hint' => $ok
                ? ''
                : 'metrics:gauge 与 online:clients 均不存在：请核对 ADMIN_REDIS_DB 与 '
                    . 'ADMIN_REDIS_PREFIX 是否与主项目一致（本机主项目为 REDIS_DB=9 / PREFIX=gwpush:），'
                    . '或主项目 6 角色是否尚未启动。',
        ];
    }

    /**
     * 当前库的键总数（辅助判断「是否连到了空库」）。
     */
    public function dbSize(): int
    {
        $n = Redis::connection()->command('dbsize');

        return is_int($n) ? $n : (int)$n;
    }

    /* =====================================================================
     | M2 会话只读原语（P2）
     |
     | 与上方 M1 原语同一纪律：只读、键名一律经 RedisKeys、不暴露写命令。
     | ===================================================================== */

    /**
     * 在线 clientId 全量列表（`SMEMBERS online:clients` 或 `online:{protocol}`）。
     *
     * ⚠ **只含「当前连着」的连接**：主项目 `Session::markOffline()` 会把 clientId
     * 从该集合摘除（`src/Business/Session.php:166-168`），故「断开但会话保留」的
     * 连接不会出现在这里 —— 那部分要靠 {@see scanKeys()} 按 `offline_at` 过滤，
     * 或走 {@see uidClients()} / {@see deviceClient()} 反查（这两条索引 markOffline 不清理）。
     *
     * @param string $protocol 空 = 全量；'ws' / 'udp'
     *
     * @return list<string> 元素顺序未定义（Set 语义），调用方须自行排序后再分页
     */
    public function onlineClientIds(string $protocol = ''): array
    {
        $raw = Redis::sMembers(RedisKeys::online($protocol));

        return $this->stringList($raw);
    }

    /**
     * 按 uid 反查 clientId（`SMEMBERS uid:clients:{uid}`）。
     *
     * ⚠ `markOffline()` **不清理**该索引，因此结果可能同时包含在线与「已离线保留」的连接 ——
     * 这正是「按 uid 反查能看到保留会话」的原因；是否离线由会话 Hash 的 `offline_at` 判定。
     *
     * @return list<string>
     */
    public function uidClients(string $uid): array
    {
        $raw = Redis::sMembers(RedisKeys::uidClients($uid));

        return $this->stringList($raw);
    }

    /**
     * 按设备反查当前活跃 clientId（`GET device:client:{deviceId}`）。
     *
     * 单对一定向的定位依据；`unbind()` 仅在映射仍指向自己时才删除，
     * 故 `markOffline()` 后仍可能读到「已离线保留」的连接。
     */
    public function deviceClient(string $deviceId): ?string
    {
        $raw = Redis::get(RedisKeys::deviceClient($deviceId));

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    /**
     * 设备绑定关系（`GET auth:bind:{uid}`，"首绑胜出"）。
     */
    public function authBind(string $uid): ?string
    {
        $raw = Redis::get(RedisKeys::authBind($uid));

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    /**
     * 批量读取多个会话 Hash（**一次往返**）。
     *
     * 为什么不用 `Redis::connection()->pipeline()`：illuminate/redis v12.69.2 的 `pipeline()`
     * **只定义在 `PhpRedisConnection` 子类**上，基类 `Connection` 没有；`Connection::__call()`
     * 会把未知方法转成 `command($method)`，于是 `Redis::connection()->pipeline($cb)` 会被当成
     * 一条名为 `PIPELINE` 的 Redis 命令而报错。实测可用路径是 `connection()->client()->pipeline($cb)`
     * —— phpredis 与 predis **都在原始客户端上**有 pipeline，且**前缀在 pipeline 内部照常生效**。
     * 两条路都不可用时由 {@see batch()} 退化为逐键读取 —— 只影响往返次数，不影响正确性。
     *
     * @param list<string> $clientIds
     *
     * @return array<string, array<string, string>> 键为 clientId；不存在的会话映射为空数组
     */
    public function sessions(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $keys = [];
        foreach ($clientIds as $clientId) {
            $keys[$clientId] = RedisKeys::session($clientId);
        }

        $responses = $this->batch(static function ($pipe) use ($keys): void {
            foreach ($keys as $key) {
                $pipe->hgetall($key);
            }
        }, array_values($keys), static fn (string $key) => Redis::hGetAll($key));

        $out = [];
        $i = 0;
        foreach ($keys as $clientId => $ignored) {
            $row = $responses[$i] ?? [];
            $out[$clientId] = is_array($row) ? array_map('strval', $row) : [];
            $i++;
        }

        return $out;
    }

    /**
     * 会话是否仍存在（`EXISTS session:{clientId}`）。
     *
     * 这是「**保留**」与「**已回收**」的分界：`unbind()` 会删除会话键，
     * 故 EXISTS=false 即代表连接断连后已被回收（而非仅离线）。
     */
    public function sessionExists(string $clientId): bool
    {
        return (int)Redis::exists(RedisKeys::session($clientId)) > 0;
    }

    /**
     * 心跳时间戳（`GET heartbeat:{clientId}`）。
     *
     * `markOffline()` 会删除该键，故返回 null 表示「已离线或从未心跳」；
     * 与 `last_active`（会话 Hash 内字段，保留）刻意不同。
     */
    public function heartbeat(string $clientId): ?int
    {
        $raw = Redis::get(RedisKeys::heartbeat($clientId));

        return is_numeric($raw) ? (int)$raw : null;
    }

    /**
     * 用户订阅的主题集合（正向索引 `SMEMBERS subscribe:uid:{uid}`）。
     *
     * @return list<string>
     */
    public function subscribeUid(string $uid): array
    {
        $raw = Redis::sMembers(RedisKeys::subscribeUid($uid));

        return $this->stringList($raw);
    }

    /**
     * 主题的订阅者 uid 集合（反向索引 `SMEMBERS subscribe:topic:{topic}`）。
     *
     * @return list<string>
     */
    public function subscribeTopic(string $topic): array
    {
        $raw = Redis::sMembers(RedisKeys::subscribeTopic($topic));

        return $this->stringList($raw);
    }

    /**
     * 离线消息队列长度（`LLEN push:offline:{uid}`）。
     */
    public function offlineLen(string $uid): int
    {
        $n = Redis::lLen(RedisKeys::pushOffline($uid));

        return is_int($n) ? $n : (int)$n;
    }

    /**
     * 离线消息队列分页（`LRANGE push:offline:{uid} start stop`）。
     *
     * 队列是 `LPUSH`/`RPOP` 语义，故 index 0 为**最新**一条。
     *
     * @param int $start 起始下标（含）
     * @param int $stop  结束下标（含，-1 = 到末尾）
     *
     * @return list<string> 原始报文串（不在此处解析 JSON）
     */
    public function offlineRange(string $uid, int $start, int $stop): array
    {
        $raw = Redis::lRange(RedisKeys::pushOffline($uid), $start, $stop);

        return $this->stringList($raw);
    }

    /**
     * **有界** SCAN：按前缀列举逻辑键。
     *
     * 两条硬约束（缺一不可，否则会拖住 worker 或给出误导性结论）：
     * 1. **禁止 KEYS**：主项目在线服务与本后台共享同一 Redis 实例，`KEYS` 是单线程阻塞命令
     *    （设计文档 §5.1「SCAN 纪律」）。
     * 2. **必须有界**：`COUNT` 只是**每轮提示值**而非上限，Redis 可能每轮返回任意数量，
     *    故同时对「轮次」「累计键数」双重设限，超限即 `truncated=true` 交由 UI 显式展示 ——
     *    **不允许静默截断**，否则运维会把「扫到的一半」当成全量。
     *
     * ⚠ **前缀陷阱（实测）**：Predis 的 `KeyPrefixProcessor` 映射表里有 `KEYS`/`SSCAN`/`HSCAN`/`ZSCAN`，
     * **唯独没有 `SCAN`**，因此 `MATCH` 模式**不会**被自动加前缀，而返回的键**带**前缀。
     * 故此处手工拼 `prefix . $logicalPattern`，并在返回前手工剥离 —— 实测依据：
     * `MATCH=gwpush:*` 能命中 `gwpush:metrics:gauge`（若被二次加前缀则必然空）。
     *
     * @param string $logicalPattern 逻辑键模式，如 `auth:revoked:*`（**不含**前缀）
     * @param int    $count          每轮 COUNT 提示值
     * @param int    $maxRounds      轮次上限（防御游标不收敛）
     * @param int    $maxKeys        累计键数上限
     *
     * @return array{keys: list<string>, scanned: int, rounds: int, truncated: bool}
     *                                                                               `keys` 为**已剥离前缀**的逻辑键名
     */
    public function scanKeys(string $logicalPattern, int $count = 200, int $maxRounds = 50, int $maxKeys = 2000): array
    {
        $prefix = self::prefix();
        $pattern = $prefix . $logicalPattern;

        $cursor = '0';
        $keys = [];
        $rounds = 0;
        $truncated = false;

        do {
            // 经 `command()` 走 Predis 原生 scan：`$raw->scan($cursor, ['MATCH'=>…, 'COUNT'=>…])`
            $reply = Redis::connection()->command('scan', [$cursor, ['MATCH' => $pattern, 'COUNT' => $count]]);
            $rounds++;

            if (!is_array($reply) || count($reply) < 2) {
                break;
            }

            $cursor = (string)$reply[0];
            $chunk = is_array($reply[1]) ? $reply[1] : [];

            foreach ($chunk as $key) {
                if (!is_string($key)) {
                    continue;
                }
                $keys[] = $prefix !== '' && str_starts_with($key, $prefix)
                    ? substr($key, strlen($prefix))
                    : $key;

                if (count($keys) >= $maxKeys) {
                    $truncated = true;

                    break 2;
                }
            }

            if ($rounds >= $maxRounds) {
                $truncated = $cursor !== '0';

                break;
            }
        } while ($cursor !== '0');

        sort($keys);

        return [
            'keys' => $keys,
            'scanned' => $rounds,
            'rounds' => $rounds,
            'truncated' => $truncated,
        ];
    }

    /**
     * Token 撤销名单（`SCAN auth:revoked:*` + 批量取 TTL）。
     *
     * 该名单**没有索引键**（写入方 `Auth::revoke()` 直接 `SET auth:revoked:{fingerprint}`），
     * 故只能 SCAN —— 这正是本类需要 {@see scanKeys()} 的唯一原因。
     *
     * ⚠ 指纹是 `sha256(token)` 的前 32 位（`Auth::tokenFingerprint()`），**不可逆推 Token**；
     * 后台只展示指纹，不提供任何反解入口。
     *
     * @return array{
     *     items: list<array{fingerprint: string, ttl: int, permanent: bool}>,
     *     scanned: int,
     *     truncated: bool
     * }
     */
    public function revoked(int $count = 200, int $maxRounds = 50, int $maxKeys = 2000): array
    {
        $scan = $this->scanKeys(RedisKeys::AUTH_REVOKED . '*', $count, $maxRounds, $maxKeys);

        $logicalKeys = $scan['keys'];
        $ttls = $this->batch(
            static function ($pipe) use ($logicalKeys): void {
                foreach ($logicalKeys as $key) {
                    $pipe->ttl($key);
                }
            },
            $logicalKeys,
            static fn (string $key) => Redis::ttl($key)
        );

        $items = [];
        foreach ($logicalKeys as $i => $key) {
            $ttlRaw = $ttls[$i] ?? -1;
            $ttl = is_numeric($ttlRaw) ? (int)$ttlRaw : -1;
            $items[] = [
                'fingerprint' => substr($key, strlen(RedisKeys::AUTH_REVOKED)),
                'ttl' => $ttl,               // -1 = 永久，-2 = 键在扫描后已消失
                'permanent' => $ttl === -1,
            ];
        }

        return [
            'items' => $items,
            'scanned' => $scan['scanned'],
            'truncated' => $scan['truncated'],
        ];
    }

    /* ---------------------------------------------------------------------
     | 内部工具
     --------------------------------------------------------------------- */

    /**
     * 把 Redis 返回的集合类结果规整为 `list<string>`（PHPStan L6 下 `mixed` 必须收口）。
     *
     * @param mixed $raw
     *
     * @return list<string>
     */
    private function stringList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (is_scalar($item)) {
                $out[] = (string)$item;
            }
        }

        return $out;
    }

    /**
     * 「批量优先、逐键兜底」的执行器。
     *
     * ⚠ **pipeline 只能下沉到原始客户端上调**，不能在 illuminate 的 `Connection` 上调：
     * `pipeline()` 仅由 `PhpRedisConnection` **子类**实现（`vendor/illuminate/redis/
     * Connections/PhpRedisConnection.php:399`），抽象基类 `Connection` 没有该方法 ——
     * 在 predis 连接上写 `Redis::connection()->pipeline($cb)` 会落入基类 `__call()`，
     * 被当成一条名为 `PIPELINE` 的**原始命令**发给 Redis 而报错。
     * 两条客户端路径：
     *   - predis（回退配置，见 `config/redis.php`）→ `\Predis\Client::pipeline()`
     *   - phpredis（默认配置）                      → `\Redis::pipeline()`
     * 前缀**仍会由客户端自动施加**（`KeyPrefixProcessor` 会处理管道内的命令），
     * 故这里照旧只传 `RedisKeys` 产出的逻辑键名。
     *
     * @param callable(object): void  $enqueue     在 pipeline 上下文里逐键入队
     * @param list<string>            $logicalKeys
     * @param callable(string): mixed $single      单个键的读取方式（兜底路径）
     *
     * @return list<mixed> 与 $logicalKeys 同序
     */
    private function batch(callable $enqueue, array $logicalKeys, callable $single): array
    {
        if ($logicalKeys === []) {
            return [];
        }

        try {
            $client = Redis::connection()->client();
            if (is_object($client) && method_exists($client, 'pipeline')) {
                $result = $client->pipeline(static function ($pipe) use ($enqueue): void {
                    $enqueue($pipe);
                });

                if (is_array($result)) {
                    return array_values($result);
                }
            }
        } catch (Throwable) {
            // 落回逐键路径：只影响往返次数，不影响结果正确性
        }

        $out = [];
        foreach ($logicalKeys as $key) {
            $out[] = $single($key);
        }

        return $out;
    }
}
