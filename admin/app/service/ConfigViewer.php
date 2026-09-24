<?php
/**
 * admin · 服务层 —— ConfigViewer。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

declare(strict_types=1);

namespace app\service;

/**
 * 主项目关键配置只读查看（2.0 §2.1「配置查看（脱敏）」的服务层）。
 *
 * 动因：**配置漂移**是本项目高频误判源 —— 无热重载 + `.env` 只对新启动的
 * 进程生效，改完忘重启时新旧值并存、行为不可预测。登录机器看 `.env` 太重，
 * 故在运维页提供一份**脱敏后的关键配置快照**。
 *
 * ---------------------------------------------------------------------
 * 职责边界（三条硬约束）
 * ---------------------------------------------------------------------
 * 1. **只读、只看主项目 `.env`**。不读 `config/*.php`（那些经 `Env::` 多层
 *    叠加，后台进程加载不到主项目的环境语义，解析出来会是「代码默认值」冒充
 *    「生效值」——比不展示更误导）。`.env` 是部署态最直白的一层；
 *    多层加载顺序由 UI 备注说明（见 {@see notes()}）。
 * 2. **白名单 + 密钥脱敏**。只展示 {@see GROUPS} 里列出的键；命中的键若属于
 *    密钥类（{@see SECRET_KEYS}），一律走 {@see SecretMasker::mask()}。
 *    未在白名单里的 `.env` 行**直接丢弃**，不回显 —— 防止误把新密钥键加进
 *    文件后被本页原样带出去。
 * 3. **路径三关**（与 LogTailService 同口径）：`project_root` realpath 收口 →
 *    固定文件名 `.env`（无用户可控路径段）→ realpath 后仍落在 project_root 内。
 *
 * 与 `MainProjectMirrorsTest` 的关系：那边钉的是「后台手写镜像 vs 主项目
 * config 默认值」；本类展示的是「.env 部署值」，两者互补、不互相替代。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */
final class ConfigViewer
{
    /**
     * 按组组织的展示白名单。
     *
     * 分组顺序即 UI 顺序；组内顺序即行序。新增键时**先想清楚它该进哪组** ——
     * 无组的键永远不会被展示（这是刻意的 fail-closed）。
     *
     * @var array<string, list<string>>
     */
    public const GROUPS = [
        '基础' => ['APP_ENV', 'APP_DEBUG', 'APP_NAME', 'APP_TIMEZONE'],
        '日志' => [
            'LOG_LEVEL', 'LOG_STDOUT', 'LOG_KEEP_DAYS', 'LOG_MAX_MB',
            'LOG_ARCHIVE_ENABLE', 'LOG_ARCHIVE_AFTER_DAYS', 'LOG_ARCHIVE_KEEP_DAYS',
        ],
        'Redis' => ['REDIS_HOST', 'REDIS_PORT', 'REDIS_DB', 'REDIS_PREFIX', 'REDIS_TIMEOUT'],
        '角色开关' => [
            'REGISTER_ENABLE', 'WS_ENABLE', 'UDP_ENABLE', 'API_ENABLE', 'DASHBOARD_ENABLE',
            'PUSH_ENABLE', 'MONITOR_ENABLE', 'AUTH_ENABLE', 'AUTH_SIGN_ENABLE',
            'API_SIGN_ENABLE', 'RATE_LIMIT_ENABLE', 'SUBSCRIBE_ENABLE',
        ],
        '监听' => ['REGISTER_LISTEN', 'WS_LISTEN', 'UDP_LISTEN', 'API_LISTEN', 'DASHBOARD_LISTEN'],
        '阈值与时限' => [
            'AUTH_TOKEN_TTL', 'AUTH_CLOCK_SKEW', 'SESSION_TTL', 'SESSION_HEARTBEAT_TTL',
            'API_SIGN_TTL', 'API_RATE_LIMIT', 'API_BODY_MAX', 'API_ACTION_WAIT_MS',
            'ACTION_TIMEOUT', 'ACTION_RESULT_TTL',
            'PUSH_OFFLINE_MODE', 'PUSH_OFFLINE_TTL', 'PUSH_OFFLINE_MAX', 'PUSH_PAYLOAD_MAX',
            'UDP_QUEUE_MAX_LEN', 'PUSH_QUEUE_MAX_LEN', 'ACTION_QUEUE_MAX_LEN',
            'MONITOR_INTERVAL', 'MONITOR_TTL',
        ],
        '密钥（脱敏）' => ['AUTH_SECRET', 'API_SECRET', 'INTERNAL_SECRET', 'REDIS_PASSWORD'],
    ];

    /**
     * 密钥类键名 —— 值一律走 SecretMasker，**任何情况下不回显原文**。
     *
     * @var list<string>
     */
    public const SECRET_KEYS = [
        'AUTH_SECRET', 'API_SECRET', 'INTERNAL_SECRET', 'REDIS_PASSWORD',
    ];

    /**
     * 展示「未配置」而非空串的键（空值 = 未配置语义，与 SecretMasker 口径一致）。
     *
     * @var list<string>
     */
    public const OPTIONAL_EMPTY = [
        'AUTH_SECRET', 'API_SECRET', 'INTERNAL_SECRET', 'REDIS_PASSWORD',
        'REGISTER_ADDRESS', 'SSL_CERT', 'SSL_PK', 'LOG_ARCHIVE_DIR',
    ];

    /**
     * 只读快照。
     *
     * @return array{
     *     ok: bool,
     *     hint: string,
     *     file: string,
     *     mtime: int,
     *     mtime_text: string,
     *     groups: list<array{
     *         name: string,
     *         items: list<array{
     *             key: string, value: string, configured: bool,
     *             secret: bool, masked: bool
     *         }>
     *     }>,
     *     notes: list<string>
     * }
     */
    public function view(): array
    {
        $resolved = $this->resolveEnvFile();
        if (!$resolved['ok']) {
            return [
                'ok' => false,
                'hint' => $resolved['hint'],
                'file' => '',
                'mtime' => 0,
                'mtime_text' => '',
                'groups' => [],
                'notes' => self::notes(),
            ];
        }

        $path = $resolved['path'];
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [
                'ok' => false,
                'hint' => '主项目 .env 不可读',
                'file' => $path,
                'mtime' => 0,
                'mtime_text' => '',
                'groups' => [],
                'notes' => self::notes(),
            ];
        }

        $kv = self::parseEnv($raw);
        $mtime = (int)@filemtime($path);

        return [
            'ok' => true,
            'hint' => '',
            'file' => $path,
            'mtime' => $mtime,
            'mtime_text' => $mtime > 0 ? date('Y-m-d H:i:s', $mtime) : '',
            'groups' => self::renderGroups($kv),
            'notes' => self::notes(),
        ];
    }

    /**
     * 解析 `.env` 文本为 `KEY => VALUE`。
     *
     * 纯函数。容忍：CRLF、`export ` 前缀、行内 `#` 注释、成对引号包裹；
     * **不**展开 `${VAR}` 插值（主项目 dotenv 语义与展示需求都用不到，展开反而
     * 可能把别的变量拖进来）。非法行静默跳过 —— 展示侧不该因一行手误整页失败。
     *
     * @return array<string, string>
     */
    public static function parseEnv(string $raw): array
    {
        $out = [];
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            if ($key === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                continue;
            }
            $value = trim(substr($line, $eq + 1));
            // 成对引号（单/双）剥一层；引号内可能含 #，故先于行内注释处理。
            // 引号外允许再挂 ` # comment`（现实 .env 常见形态）。
            if (strlen($value) >= 2) {
                $q = $value[0];
                // 成对引号（单/双）剥一层；引号内可能含 #，故先于行内注释处理。
                // 引号外允许再挂 ` # comment`（现实 .env 常见形态）。
                $end = ($q === '"' || $q === "'") ? strpos($value, $q, 1) : false;
                if ($end !== false && $end > 1) {
                    $value = substr($value, 1, $end - 1);
                } elseif (!str_starts_with($value, '#')) {
                    $hash = strpos($value, ' #');
                    if ($hash !== false) {
                        $value = rtrim(substr($value, 0, $hash));
                    }
                }
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * 白名单投影 + 密钥脱敏。纯函数。
     *
     * @param array<string, string> $kv
     *
     * @return list<array{name: string, items: list<array{
     *     key: string, value: string, configured: bool, secret: bool, masked: bool
     * }>}>
     */
    public static function renderGroups(array $kv): array
    {
        $groups = [];
        foreach (self::GROUPS as $name => $keys) {
            $items = [];
            foreach ($keys as $key) {
                // 空值 = 未配置语义（与 SecretMasker 口径一致）：
                // OPTIONAL_EMPTY 键（含密钥）显式「未配置」；其他键空串同样按
                // 「未设置，走代码默认」展示，不把空串伪装成已配置值。
                $present = array_key_exists($key, $kv);
                $raw = $present ? $kv[$key] : '';
                $configured = $present && $raw !== '';
                $secret = in_array($key, self::SECRET_KEYS, true);

                if ($secret) {
                    $display = SecretMasker::mask($raw);
                    $masked = true;
                } else {
                    $display = $raw;
                    $masked = false;
                }

                $items[] = [
                    'key' => $key,
                    'value' => $display,
                    'configured' => $configured,
                    'secret' => $secret,
                    'masked' => $masked,
                ];
            }
            $groups[] = ['name' => $name, 'items' => $items];
        }

        return $groups;
    }

    /**
     * UI 备注（加载顺序 / 无热重载 / 与 roles 命令的分工）。纯函数。
     *
     * @return list<string>
     */
    public static function notes(): array
    {
        return [
            '只展示主项目 .env 的白名单键；config/*.php 的多层叠加语义不在本页复刻'
                . '（生效角色开关见「角色状态」，真源是 php start.php roles）。',
            '加载优先级：代码默认值 < .env < .env.{APP_ENV} < .env.local < .env.{APP_ENV}.local < 系统环境变量。',
            '配置无热重载：.env 改动只对新启动的进程生效，改完必须重启对应角色'
                . '（mtime 晚于进程启动时间即为「改了未重启」的线索）。',
            '密钥类一律前4后4脱敏，本页任何情况下不回显原文。',
        ];
    }

    /**
     * 解析主项目 `.env` 的绝对路径（三道防线）。
     *
     * @return array{ok: bool, path: string, hint: string}
     */
    private function resolveEnvFile(): array
    {
        $rootRaw = (string)config('gateway_push.project_root', '..');
        $root = realpath($rootRaw);
        if ($root === false) {
            return ['ok' => false, 'path' => '', 'hint' => '主项目根目录不存在：' . $rootRaw];
        }

        // 文件名固定，无用户可控路径段；realpath 复核防 project_root 被链到别处
        $file = $root . DIRECTORY_SEPARATOR . '.env';
        $real = realpath($file);
        if ($real === false) {
            return ['ok' => false, 'path' => '', 'hint' => '主项目 .env 不存在（先 php start.php env:init）'];
        }

        if (!str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return ['ok' => false, 'path' => '', 'hint' => '主项目 .env 路径越界，已拒绝'];
        }

        return ['ok' => true, 'path' => $real, 'hint' => ''];
    }
}
