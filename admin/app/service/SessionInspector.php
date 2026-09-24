<?php
/**
 * admin · 服务层 —— SessionInspector。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace app\service;

use GatewayPush\Common\RedisKeys;
use Throwable;

/**
 * M2 会话只读聚合（SessionInspector）
 *
 * 职责：把散落在 4 类键里的会话信息聚合成「列表 / 详情 / 反查」三个视图用的结构。
 * 数据来源一律经 {@see RedisReader}（后台唯一的 Redis 只读门面），本类**不直接碰 Redis**，
 * 也不含任何写命令 —— 对应设计文档 §5.2「Redis 写边界」与后台四条硬不变量之「只读」。
 *
 * ---------------------------------------------------------------------
 * 三条与直觉不符的实测结论（决定了本类的判定逻辑，改动前务必先读）
 * ---------------------------------------------------------------------
 * **① 在线判据只能用 `online:clients` 的成员资格，不能用 `offline_at`。**
 *   - 主项目 `Session::markOffline()`（`src/Business/Session.php:161-186`）会把 clientId
 *     从 `online:clients` / `online:{protocol}` 摘除，并给会话 Hash 补一个 `offline_at`；
 *   - 但 `bind()` 只 `hMSet` 9 个业务字段，**从不 `hDel('offline_at')`**（全 `src/Business/` 内
 *     只有 `Monitor.php` 调 `hDel`），因此 `offline_at` 是**粘性字段**；
 *   - UDP 的 clientId 是 `udp:{ip}:{port}`，**同一客户端可以重绑同一个 clientId**，
 *     于是「断开过 → 再活跃」的连接会**长期带着** `offline_at`。
 *   ⇒ 若拿 `offline_at` 判在线，会把活跃的 UDP 连接误判为离线。故本类：
 *     在线 = `SISMEMBER online:clients`（权威）；`offline_at` 仅作「最近一次断开时间」展示。
 *
 * **② 「保留会话」在 `online:clients` 里查不到，只能 SCAN。**
 *   `markOffline()` 摘除在线索引但保留 `session:{clientId}`，所以要枚举「已断开但保留」
 *   必须 `SCAN session:*`。SCAN 一律走 {@see RedisReader::scanKeys()} 的**有界**版本，
 *   并把 `truncated` 透传到 UI —— 不允许静默截断。
 *
 * **③ 「本会话是否已被撤销」不可判定。**
 *   撤销名单的键是 `auth:revoked:{sha256(token) 前 32 位}`（`Auth::tokenFingerprint()`），
 *   而服务端**从不存储 Token 原文**，会话 Hash 里也没有指纹字段 ⇒ 无法由 clientId/uid 反推。
 *   故本类**刻意不提供** `revoked` 标志（宁缺勿假），只在 {@see revoked()} 里列出名单本身。
 *
 * ---------------------------------------------------------------------
 * 可测性设计
 * ---------------------------------------------------------------------
 * 全部「判定 / 归类 / 排序 / 分页」都是**纯静态函数**（`stateOf` / `rowOf` / `sortRows` / `slice`…），
 * 单测无需 Redis；实例方法只负责取数 + 串起来。与主项目 `Monitor::staleFields()`、
 * 后台 `MetricsDeriver` 的做法一致。
 *
 * 兼容 PHP 8.2 ~ 8.5
 *
 * @phpstan-type SessionRow array{
 *     client_id: string,
 *     uid: string,
 *     device_id: string,
 *     protocol: string,
 *     client_ip: string,
 *     client_port: string,
 *     gateway: string,
 *     connect_at: int,
 *     last_active: int,
 *     offline_at: int,
 *     state: string,
 *     connect_secs: null|int,
 *     idle_secs: null|int,
 *     offline_secs: null|int
 * }
 * @phpstan-type ScanInfo array{scanned: int, truncated: bool, keys: int}
 * @phpstan-type SessionList array{
 *     items: list<SessionRow>,
 *     total: int,
 *     page: int,
 *     size: int,
 *     pages: int,
 *     scope: string,
 *     online_total: int,
 *     scan: null|ScanInfo,
 *     skeleton_ok: bool,
 *     hint: string
 * }
 * @phpstan-type SessionDetail array{
 *     found: bool,
 *     state: string,
 *     session: array<string, string>,
 *     heartbeat: null|int,
 *     heartbeat_age_secs: null|int,
 *     connect_secs: null|int,
 *     idle_secs: null|int,
 *     offline_secs: null|int,
 *     subscriptions: list<string>,
 *     offline_queue: array{len: int, items: list<string>, page: int, size: int, pages: int},
 *     auth: array{bind: null|string, device_bound: bool, note: string},
 *     note: string
 * }
 */
final class SessionInspector
{
    /** 列表范围：仅在线 */
    public const SCOPE_ONLINE = 'online';

    /** 列表范围：仅「断开但保留」 */
    public const SCOPE_RETAINED = 'retained';

    /** 列表范围：在线 + 保留 */
    public const SCOPE_ALL = 'all';

    /** 会话状态：在线（在 `online:clients` 内） */
    public const STATE_ONLINE = 'online';

    /** 会话状态：已断开但会话保留（键存在、不在在线集合内） */
    public const STATE_RETAINED = 'retained';

    /** 会话状态：已回收（会话键不存在） */
    public const STATE_GONE = 'gone';

    /** 入参长度上限（uid / device_id / clientId 统一口径） */
    public const ID_MAX_LEN = 128;

    /** 分页大小下限 / 上限（上限是「无 N+1」不变量的前提之一） */
    public const SIZE_MIN = 1;
    public const SIZE_MAX = 100;

    /** 客户端可用协议（与主项目 `Session::PROTOCOL_*` 一致） */
    public const PROTOCOLS = ['ws', 'udp'];

    /** 「撤销状态不可判定」的固定说明文案（详情页原样展示，避免前端各自编词） */
    public const REVOKE_NOTE = '撤销名单以 Token 指纹（sha256 前 32 位）为键，服务端不存 Token 原文，'
        . '因此无法由会话反推是否已撤销；请到「Token 撤销名单」核对指纹。';

    /* =====================================================================
     | 纯静态函数（单测覆盖，无 IO）
     ===================================================================== */

    /** 状态说明文案（详情页用；与 `stateOf()` 的取值一一对应） */
    public const STATE_NOTES = [
        self::STATE_ONLINE => '在 online:clients 内，连接当前有效。',
        self::STATE_RETAINED => '不在 online:clients 内但会话键仍存在：连接已断开，会话按 SESSION_TTL 保留供重连。',
        self::STATE_GONE => '会话键已不存在：连接断开后已被回收（unbind），或该 clientId 从未建连。',
    ];

    /** @var RedisReader */
    private RedisReader $reader;

    /**
     * @param null|RedisReader $reader 缺省自建；测试可注入
     */
    public function __construct(?RedisReader $reader = null)
    {
        $this->reader = $reader ?? new RedisReader();
    }

    /* =====================================================================
     | 列表
     ===================================================================== */

    /**
     * 会话列表（分页）。
     *
     * `scope=online` 走最省的路径（SMEMBERS + 一次批量 HGETALL，**不 SCAN**）；
     * `retained` / `all` 必须 SCAN，故会带上 `scan` 元信息供 UI 显式展示「是否截断」。
     *
     * ⚠ SCAN 产物是**逻辑键名**，必须经 {@see clientIdsFromKeys()} 还原成 clientId 后才能
     * 交给 `sessions()` —— 否则会被二次加前缀而全部读空（详见该函数的注释）。
     * `scan.keys` 上报的是**扫描到的会话键数**（转换前），便于与 `total` 对照排查。
     *
     * @param array<string, mixed> $query 原始查询串：scope / protocol / uid / page / size
     * @param int                  $now   当前时间戳（注入以便测试）
     *
     * @return SessionList
     */
    public function list(array $query, int $now): array
    {
        $scope = self::scopeOf($query['scope'] ?? null);
        $protocol = self::protocolOf($query['protocol'] ?? null);
        $uid = self::cleanId($query['uid'] ?? null);
        [$page, $size] = $this->pagingFor($query['page'] ?? null, $query['size'] ?? null);

        $onlineIds = $this->reader->onlineClientIds($protocol);
        $onlineSet = array_fill_keys($onlineIds, true);

        $scan = null;
        if ($scope === self::SCOPE_ONLINE) {
            $ids = $onlineIds;
            if ($uid !== '') {
                $ids = array_values(array_intersect($ids, $this->reader->uidClients($uid)));
            }
            $rows = $this->rowsFor($ids, $onlineSet, $now);
        } else {
            $scanRaw = $this->reader->scanKeys(
                RedisKeys::SESSION . '*',
                self::scanCount(),
                self::scanRounds(),
                self::scanMaxKeys()
            );
            $rows = $this->rowsFor(self::clientIdsFromKeys($scanRaw['keys']), $onlineSet, $now);
            $scan = [
                'scanned' => $scanRaw['scanned'],
                'truncated' => $scanRaw['truncated'],
                'keys' => count($scanRaw['keys']),
            ];

            if ($scope === self::SCOPE_RETAINED) {
                $rows = array_values(array_filter(
                    $rows,
                    static fn (array $row): bool => $row['state'] === self::STATE_RETAINED
                ));
            }
            if ($uid !== '') {
                $rows = array_values(array_filter(
                    $rows,
                    static fn (array $row): bool => $row['uid'] === $uid
                ));
            }
        }

        $rows = self::sortRows($rows);
        $total = count($rows);
        $sliced = self::slice($rows, $page, $size);

        // 空列表必须能区分「确实没人连」与「DB/PREFIX 配错」—— 复用 P0 的骨架自检
        $skeletonOk = true;
        $hint = '';
        if ($total === 0) {
            $check = $this->reader->selfCheck();
            $skeletonOk = $check['ok'];
            $hint = $check['hint'];
        }

        return [
            'items' => $sliced,
            'total' => $total,
            'page' => $page,
            'size' => $size,
            'pages' => $size > 0 ? (int)ceil($total / $size) : 0,
            'scope' => $scope,
            'online_total' => count($onlineIds),
            'scan' => $scan,
            'skeleton_ok' => $skeletonOk,
            'hint' => $hint,
        ];
    }

    /* =====================================================================
     | 详情
     ===================================================================== */

    /**
     * 会话详情。
     *
     * `found=false` 表示会话键已不存在（已被 `unbind()` 回收），与「保留但离线」刻意区分 ——
     * 这正是设计文档 P2 验收里「区分保留会话与已回收」的落点。
     *
     * @param array<string, mixed> $query 可选：offline_page / offline_size（离线队列分页）
     *
     * @return SessionDetail
     */
    public function detail(string $clientId, array $query, int $now): array
    {
        $exists = $this->reader->sessionExists($clientId);
        $session = $exists ? ($this->reader->sessions([$clientId])[$clientId] ?? []) : [];
        if ($session === []) {
            $exists = false;
        }

        $inOnline = $exists && in_array($clientId, $this->reader->onlineClientIds(), true);
        $state = self::stateOf($inOnline, $exists);

        $heartbeat = $exists ? $this->reader->heartbeat($clientId) : null;
        $uid = isset($session['uid']) ? (string)$session['uid'] : '';
        $deviceId = isset($session['device_id']) ? (string)$session['device_id'] : '';
        $bind = $uid !== '' ? $this->reader->authBind($uid) : null;

        [$offlinePage, $offlineSize] = $this->pagingFor($query['offline_page'] ?? null, $query['offline_size'] ?? null);
        $offline = $exists && $uid !== ''
            ? $this->offlineQueue($uid, $offlinePage, $offlineSize)
            : ['len' => 0, 'items' => [], 'page' => 1, 'size' => $offlineSize, 'pages' => 0];

        return [
            'found' => $exists,
            'state' => $state,
            'session' => $session,
            'heartbeat' => $heartbeat,
            'heartbeat_age_secs' => $heartbeat !== null ? max(0, $now - $heartbeat) : null,
            'connect_secs' => self::ageOf($session, 'connect_at', $now),
            'idle_secs' => self::ageOf($session, 'last_active', $now),
            'offline_secs' => self::ageOf($session, 'offline_at', $now),
            'subscriptions' => $uid !== '' ? $this->reader->subscribeUid($uid) : [],
            'offline_queue' => $offline,
            'auth' => [
                'bind' => $bind,
                'device_bound' => $bind !== null && $deviceId !== '' && $bind === $deviceId,
                'note' => self::REVOKE_NOTE,
            ],
            'note' => self::STATE_NOTES[$state] ?? '',
        ];
    }

    /* =====================================================================
     | 反查
     ===================================================================== */

    /**
     * 按 uid 反查（`uid:clients:{uid}`）。
     *
     * ⚠ 该索引 `markOffline()` **不清理**，故结果会同时包含在线与「保留」的连接 ——
     * 这是后台唯一能不靠 SCAN 就看到保留会话的入口。
     *
     * @return SessionList
     */
    public function findByUid(string $uid, int $now): array
    {
        return $this->byIndex('uid', $uid, $this->reader->uidClients($uid), $now);
    }

    /**
     * 按 device 反查（`device:client:{deviceId}`，单对一定向的定位依据）。
     *
     * @return SessionList
     */
    public function findByDevice(string $deviceId, int $now): array
    {
        $clientId = $this->reader->deviceClient($deviceId);

        return $this->byIndex('device', $deviceId, $clientId === null ? [] : [$clientId], $now);
    }

    /* =====================================================================
     | 离线队列 / 订阅 / 撤销名单
     ===================================================================== */

    /**
     * 离线消息队列（只读）。
     *
     * 队列为 `RPUSH` + 重连后按序投递语义，index 0 为**最早**一条；
     * `len` 是 `LLEN` 的真实长度，`items` 只是当前页切片。
     *
     * @return array{uid: string, len: int, items: list<string>, page: int, size: int, pages: int}
     */
    public function offlineQueue(string $uid, int $page, int $size): array
    {
        $len = $this->reader->offlineLen($uid);
        $start = ($page - 1) * $size;
        $items = $len > 0 && $start < $len
            ? $this->reader->offlineRange($uid, $start, min($start + $size - 1, $len - 1))
            : [];

        return [
            'uid' => $uid,
            'len' => $len,
            'items' => $items,
            'page' => $page,
            'size' => $size,
            'pages' => $size > 0 ? (int)ceil($len / $size) : 0,
        ];
    }

    /**
     * 订阅关系双向视图：给 uid 看它订阅了哪些主题，给 topic 看有哪些订阅者。
     *
     * 两者都传时**同时返回**（便于「这个 uid 是否订阅了该 topic」一次问清）。
     *
     * @return array{uid: string, topics: list<string>, topic: string, subscribers: list<string>}
     */
    public function subscriptions(string $uid = '', string $topic = ''): array
    {
        return [
            'uid' => $uid,
            'topics' => $uid !== '' ? $this->reader->subscribeUid($uid) : [],
            'topic' => $topic,
            'subscribers' => $topic !== '' ? $this->reader->subscribeTopic($topic) : [],
        ];
    }

    /**
     * Token 撤销名单（指纹 + TTL）。**只为核对指纹**，不提供任何反解入口。
     *
     * @return array{items: list<array{fingerprint: string, ttl: int, permanent: bool}>, scanned: int, truncated: bool}
     */
    public function revoked(): array
    {
        try {
            return $this->reader->revoked(self::scanCount(), self::scanRounds(), self::scanMaxKeys());
        } catch (Throwable) {
            // SCAN 失败（Redis 抖动 / 权限）不应把整页打成 500 —— 返回空 + 截断标记，
            // 由 UI 的「已截断/取数失败」提示兜住。
            return ['items' => [], 'scanned' => 0, 'truncated' => true];
        }
    }

    /**
     * 会话状态判定（纯函数）。
     *
     * **判定顺序即语义**：在线以集合成员资格为准，不以 `offline_at` 是否为空为准 ——
     * 后者是粘性字段（见类注释 ①），拿它判会把活跃 UDP 连接误判为离线。
     *
     * @param bool $inOnlineSet   clientId 是否在 `online:clients` 内
     * @param bool $sessionExists `session:{clientId}` 是否存在
     *
     * @return string 见 `STATE_*` 常量
     */
    public static function stateOf(bool $inOnlineSet, bool $sessionExists): string
    {
        if ($inOnlineSet) {
            return self::STATE_ONLINE;
        }

        return $sessionExists ? self::STATE_RETAINED : self::STATE_GONE;
    }

    /**
     * 把会话 Hash + 状态组装成一行视图结构（纯函数）。
     *
     * `uid` 等字段缺失时回落空串，数值字段缺失回落 0 —— 与 `MetricsDeriver` 的
     * 「缺失即空、不猜值」口径一致。
     *
     * @param array<string, string> $session
     *
     * @return SessionRow
     */
    public static function rowOf(string $clientId, array $session, bool $inOnlineSet, int $now): array
    {
        $state = self::stateOf($inOnlineSet, true);

        return [
            'client_id' => self::str($session, 'client_id', $clientId),
            'uid' => self::str($session, 'uid'),
            'device_id' => self::str($session, 'device_id'),
            'protocol' => self::str($session, 'protocol'),
            'client_ip' => self::str($session, 'client_ip'),
            'client_port' => self::str($session, 'client_port'),
            'gateway' => self::str($session, 'gateway'),
            'connect_at' => self::int($session, 'connect_at'),
            'last_active' => self::int($session, 'last_active'),
            'offline_at' => self::int($session, 'offline_at'),
            'state' => $state,
            'connect_secs' => self::ageOf($session, 'connect_at', $now),
            'idle_secs' => self::ageOf($session, 'last_active', $now),
            'offline_secs' => self::ageOf($session, 'offline_at', $now),
        ];
    }

    /**
     * 列表排序（纯函数，稳定）：**在线优先 → 最近活跃优先 → clientId 升序**。
     *
     * 末尾的 clientId 比较是不可省的 —— 否则同一秒内建连的多条记录顺序随机，
     * 分页翻动时会出现「同一条记录在两页里都出现/都不出现」。
     *
     * @param list<SessionRow> $rows
     *
     * @return list<SessionRow>
     */
    public static function sortRows(array $rows): array
    {
        usort($rows, static function (array $a, array $b): int {
            $aOnline = $a['state'] === self::STATE_ONLINE ? 0 : 1;
            $bOnline = $b['state'] === self::STATE_ONLINE ? 0 : 1;
            if ($aOnline !== $bOnline) {
                return $aOnline <=> $bOnline;
            }
            if ($a['last_active'] !== $b['last_active']) {
                return $b['last_active'] <=> $a['last_active'];
            }

            return strcmp($a['client_id'], $b['client_id']);
        });

        // `usort` 就地重排且会重建索引，故此处**已是 list**，无需再 array_values（L6 会报冗余）
        return $rows;
    }

    /**
     * 分页切片（纯函数）。页码越界返回空列表，**不做环绕**。
     *
     * @param list<SessionRow> $rows
     *
     * @return list<SessionRow>
     */
    public static function slice(array $rows, int $page, int $size): array
    {
        if ($page < 1 || $size < 1) {
            return [];
        }

        return array_slice($rows, ($page - 1) * $size, $size);
    }

    /**
     * 秒数差（纯函数）。时间戳缺失（<=0）返回 null 而非 0 ——
     * 「没有这个时间」与「正好 0 秒前」是两件事，前端要能分辨。
     *
     * @param array<string, mixed> $row
     */
    public static function ageOf(array $row, string $field, int $now): ?int
    {
        $value = isset($row[$field]) && is_numeric($row[$field]) ? (int)$row[$field] : 0;
        if ($value <= 0) {
            return null;
        }

        return max(0, $now - $value);
    }

    /**
     * 入参 id 校验（纯函数）：非空、长度受限、**不含控制字符**。
     *
     * 这里的目的是防御而非权限：uid / device_id / clientId 都会被直接拼进 Redis 键后缀，
     * 含换行等控制字符会让日志与页面出现无法解释的换行/截断。不做字符集白名单 ——
     * 本项目 uid 形态未定型，过严会误杀（如未来的邮箱式 uid）。
     */
    public static function validId(string $value): bool
    {
        if ($value === '' || strlen($value) > self::ID_MAX_LEN) {
            return false;
        }

        return preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    /**
     * 非法入参回落空串（调用方无需到处写分支）。
     */
    public static function cleanId(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return self::validId($value) ? $value : '';
    }

    /**
     * 由**逻辑键名**还原 clientId（纯函数）。
     *
     * 这是两条不同「键空间」之间的**唯一转换点**，漏掉必然静默读空：
     *   - {@see RedisReader::scanKeys()} 返回的是**已剥离 Redis 前缀**的**逻辑键名**
     *     （`session:ws-abc`），文档亦如此声明；
     *   - {@see RedisReader::sessions()} 收的是 **clientId**（`ws-abc`），它会自行拼
     *     `RedisKeys::session()` 再交给 `Redis` 门面（门面再叠加 `REDIS_PREFIX`）。
     * 两者直接对接会得到 `session:session:ws-abc` ⇒ 全部读空，表现为
     * 「`scope=retained` 返回 0 条」而 SCAN 明明扫到了键 —— 2026-09-23 实测踩过。
     *
     * 无 `session:` 前缀的元素（理论不该出现）**整条丢弃**而非原样保留：
     * 保留会制造一条永远查不到的幽灵记录，静默比显式失败更难排查。
     *
     * @param list<string> $logicalKeys `scanKeys()` 的 `keys`（逻辑键名）
     *
     * @return list<string> clientId 列表（已去重）
     */
    public static function clientIdsFromKeys(array $logicalKeys): array
    {
        $prefix = RedisKeys::SESSION;
        $out = [];
        foreach ($logicalKeys as $key) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }
            $clientId = substr($key, strlen($prefix));
            if ($clientId === '') {
                continue;
            }
            $out[] = $clientId;
        }

        return array_values(array_unique($out));
    }

    /**
     * scope 归一：未知值回落 `online`（最省路径，也是默认视图）。
     */
    public static function scopeOf(mixed $value): string
    {
        $allowed = [self::SCOPE_ONLINE, self::SCOPE_RETAINED, self::SCOPE_ALL];

        return is_string($value) && in_array($value, $allowed, true) ? $value : self::SCOPE_ONLINE;
    }

    /**
     * protocol 归一：空 = 全量；未知值同样回落空（而非报错，避免筛选项被手改就把页面打红）。
     */
    public static function protocolOf(mixed $value): string
    {
        return is_string($value) && in_array($value, self::PROTOCOLS, true) ? $value : '';
    }

    /**
     * 分页参数归一（纯函数）。
     *
     * 语义（按顺序）：
     *   1. 非数字（`null` / 空串 / `'abc'`）与数值 `<= 0` 的 size **一律回落到 `$defaultSize`**；
     *   2. 回落之后再夹到 `[SIZE_MIN, SIZE_MAX]`。
     * 故从查询串来的 `size` **永远碰不到 `SIZE_MIN`** —— 下界实际是在防御
     * `admin_settings.session.page_size` 被改成非法值（见单测
     * `testPagingSizeMinGuardsAMisconfiguredDefault`）。
     *
     * 上限是「无 N+1 不变量」的前提：列表取数按 `size` 个键做一批 HGETALL，
     * 不设上限则一次请求就能把 Redis 与 worker 内存打满。
     *
     * `$defaultSize` 由调用方显式传入（而非在此处读 `Settings`）—— 这样本函数保持零 IO、
     * 可脱离 Redis 与 MySQL 单测。与 `MetricsDeriver` 用构造参数注入阈值同一口径。
     *
     * @return array{0: int, 1: int} [page, size]
     */
    public static function paging(mixed $page, mixed $size, int $defaultSize = 20): array
    {
        $p = is_numeric($page) ? (int)$page : 1;
        $s = is_numeric($size) ? (int)$size : 0;
        if ($s <= 0) {
            $s = $defaultSize;
        }

        return [max(1, $p), max(self::SIZE_MIN, min(self::SIZE_MAX, $s))];
    }

    /**
     * 分页参数归一（实例版）：用配置里的默认页大小。
     *
     * @return array{0: int, 1: int} [page, size]
     */
    public function pagingFor(mixed $page, mixed $size): array
    {
        return self::paging($page, $size, $this->pageSize());
    }

    /* ---------------------------------------------------------------------
     | 内部
     --------------------------------------------------------------------- */

    /**
     * 反查的统一出口（uid / device 共用）。
     *
     * @param list<string> $clientIds
     *
     * @return SessionList
     */
    private function byIndex(string $kind, string $value, array $clientIds, int $now): array
    {
        $onlineSet = array_fill_keys($this->reader->onlineClientIds(), true);
        $rows = self::sortRows($this->rowsFor($clientIds, $onlineSet, $now));

        return [
            'items' => $rows,
            'total' => count($rows),
            'page' => 1,
            'size' => self::SIZE_MAX,
            'pages' => $rows === [] ? 0 : 1,
            'scope' => $kind . ':' . $value,
            'online_total' => count($onlineSet),
            'scan' => null,
            'skeleton_ok' => true,
            'hint' => $rows === []
                ? sprintf('未找到 %s=%s 对应的会话；可能从未建连，或已被回收（unbind）。', $kind, $value)
                : '',
        ];
    }

    /**
     * 批量读会话并组装行（**一次往返**，见 `RedisReader::sessions()`）。
     *
     * @param list<string>        $clientIds
     * @param array<string, true> $onlineSet 以 clientId 为键的在线集合（`array_fill_keys` 产物，
     *                                       故用 `isset()` 判定 O(1)，比 `in_array` 的 O(n) 更适合大列表）
     *
     * @return list<SessionRow>
     */
    private function rowsFor(array $clientIds, array $onlineSet, int $now): array
    {
        if ($clientIds === []) {
            return [];
        }

        $sessions = $this->reader->sessions($clientIds);

        $rows = [];
        foreach ($sessions as $clientId => $session) {
            if ($session === []) {
                // 键在扫描/列举与批量读取之间过期：跳过，不产出一行全空的记录
                continue;
            }
            $rows[] = self::rowOf($clientId, $session, isset($onlineSet[$clientId]), $now);
        }

        return $rows;
    }

    /**
     * @param array<string, string> $row
     */
    private static function str(array $row, string $field, string $default = ''): string
    {
        return isset($row[$field]) && $row[$field] !== '' ? $row[$field] : $default;
    }

    /**
     * @param array<string, string> $row
     */
    private static function int(array $row, string $field): int
    {
        return isset($row[$field]) && is_numeric($row[$field]) ? (int)$row[$field] : 0;
    }

    // 配置读取：集中在此，便于单测与后续换源（admin_settings）

    private function pageSize(): int
    {
        return max(self::SIZE_MIN, min(self::SIZE_MAX, Settings::int('session.page_size', 20)));
    }

    /**
     * SCAN 每轮 COUNT 提示值（配置可调）。
     */
    private static function scanCount(): int
    {
        return max(10, Settings::int('session.scan_count', 200));
    }

    /**
     * SCAN 最大轮次上限（配置可调）。
     */
    private static function scanRounds(): int
    {
        return max(1, Settings::int('session.scan_max_rounds', 50));
    }

    /**
     * SCAN 累计键数上限（配置可调）。
     */
    private static function scanMaxKeys(): int
    {
        return max(100, Settings::int('session.scan_max_keys', 2000));
    }
}
