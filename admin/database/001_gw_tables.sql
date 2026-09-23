-- ---------------------------------------------------------------------------
-- GatewayPush 管理后台 —— 后台自有业务表（P0）
--
-- 与 webman-admin 自带的 wa_* 七张表分工：
--   wa_*       → 管理员 / 角色 / 权限节点 / 菜单 / 上传（插件所有，见 plugin/admin/install.sql）
--   本文件表   → 后台自己的业务数据（推送受理记录 / 模板 / 审计 / 设置）
--
-- 排序规则统一 utf8mb4_general_ci：与 plugin/admin/install.sql 完全同源。
-- ⚠ 不要改成 utf8mb4_unicode_ci / utf8mb4_0900_ai_ci —— 与 wa_* 表混排会在
--   跨表 JOIN / UNION 时报 "Illegal mix of collations"。
--
-- 幂等：全部 IF NOT EXISTS + INSERT IGNORE，可重复执行。
-- ---------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- 推送任务受理记录（M3）
-- 定位：/push 是「入队即返回」的异步受理，服务端不落逐条投递历史，
--       因此这里只记录「后台提交过什么、服务端怎么受理的」。
--       status 的终态是 accepted（受理成功）或 rejected（受理失败），
--       刻意不引入 delivered —— 服务端本身不承诺逐条投递回执。
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `push_task` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id`    CHAR(16)        NOT NULL COMMENT '后台本地请求 ID（bin2hex(random_bytes(8))）',
  `target_type`   VARCHAR(16)     NOT NULL COMMENT 'uid|device|client',
  `target`        VARCHAR(191)    NOT NULL,
  `payload`       JSON            NOT NULL,
  `payload_bytes` INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT '序列化后字节数，对照 PUSH_PAYLOAD_MAX(4096)',
  `msg_id`        VARCHAR(64)     NOT NULL DEFAULT '' COMMENT '参与服务端幂等去重',
  `offline_mode`  VARCHAR(8)      NOT NULL DEFAULT '' COMMENT '实际生效值（服务端回带）',
  `http_status`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `code`          INT             NOT NULL DEFAULT 0 COMMENT '响应体业务码，0=受理成功',
  `msg`           VARCHAR(255)    NOT NULL DEFAULT '',
  `status`        VARCHAR(16)     NOT NULL DEFAULT 'accepted' COMMENT 'accepted|rejected',
  `operator_id`   INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'wa_admins.id',
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_request_id` (`request_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_target` (`target_type`, `target`),
  KEY `idx_operator` (`operator_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='后台发起的推送受理记录';

-- ---------------------------------------------------------------------------
-- 推送模板（M3，后台自有数据）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `push_template` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(64)  NOT NULL,
  `target_type`  VARCHAR(16)  NOT NULL COMMENT 'uid|device|client',
  `payload`      JSON         NOT NULL,
  `offline_mode` VARCHAR(8)   NOT NULL DEFAULT '',
  `remark`       VARCHAR(255) NOT NULL DEFAULT '',
  `created_by`   INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='推送载荷模板';

-- ---------------------------------------------------------------------------
-- 写操作审计（M4/M5）
-- 定位：所有「会改变推送系统状态」的后台操作必须落此行，且只允许经 Auditor 写入。
-- 与 webman-admin 自带日志的分工：本表记业务语义（对谁做了什么），自带日志记访问行为。
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_audit_log` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`    INT UNSIGNED    NOT NULL DEFAULT 0,
  `admin_name`  VARCHAR(64)     NOT NULL DEFAULT '',
  `action`      VARCHAR(64)     NOT NULL COMMENT 'push.create|session.kick|auth.revoke|auth.unbind|...',
  `target_type` VARCHAR(16)     NOT NULL DEFAULT '' COMMENT 'uid|device|client|token|system',
  `target`      VARCHAR(191)    NOT NULL DEFAULT '' COMMENT '目标标识；token 类只记指纹',
  `params`      JSON            NULL COMMENT '请求参数（密钥类字段已脱敏/省略）',
  `result`      VARCHAR(16)     NOT NULL DEFAULT 'ok' COMMENT 'ok|failed',
  `code`        INT             NOT NULL DEFAULT 0 COMMENT '服务端返回的业务码',
  `msg`         VARCHAR(255)    NOT NULL DEFAULT '',
  `ip`          VARCHAR(45)     NOT NULL DEFAULT '',
  `user_agent`  VARCHAR(255)    NOT NULL DEFAULT '',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_time` (`admin_id`, `created_at`),
  KEY `idx_action_time` (`action`, `created_at`),
  KEY `idx_target` (`target_type`, `target`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='后台对推送系统的写操作审计';

-- ---------------------------------------------------------------------------
-- 后台自身设置（轮询间隔 / 告警阈值 / 展示偏好）
-- 键值对形态：新增配置无需 DDL
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_settings` (
  `k`          VARCHAR(64)  NOT NULL,
  `v`          TEXT         NOT NULL,
  `remark`     VARCHAR(255) NOT NULL DEFAULT '',
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='后台自身设置';

-- 初始值：INSERT IGNORE 保证重复执行不会覆盖运维手工调整过的值
--
-- ⚠ monitor.ratio_thresholds 刻意留空 `{}`，**不**把默认阈值抄一份进来：
--   默认值只存在于 MetricsDeriver::RATIOS 常量（单一真源）。若把数值抄进本文件，
--   将来改类常量时这条 seed 不会跟着变（INSERT IGNORE 不覆盖既有行），
--   就会出现「代码里写着 1.0，库里还留着 2.0」的静默漂移。
--   留空 = 全部走类常量；要调哪项就只写哪项，其余自动继承。
INSERT IGNORE INTO `admin_settings` (`k`, `v`, `remark`) VALUES
  ('monitor.poll_interval',    '5',    '监控页快 tick 轮询间隔（秒）'),
  ('monitor.slow_interval',    '30',   '监控页慢 tick 间隔（秒）：selfCheck / dbsize / 主项目 API'),
  ('monitor.queue_warn_depth', '1000', '队列深度告警阈值'),
  ('monitor.gauge_stale_secs', '10',   '进程存活展示判据 = MONITOR_INTERVAL × 2（勿用 MONITOR_TTL）'),
  ('monitor.ratio_thresholds', '{}',   '派生率阈值覆盖；{} = 全走类常量默认。可覆盖键：msg_fail_rate/auth_fail_rate/action_fail_rate/action_timeout_rate/push_fail_rate/udp_out_fail_rate/heartbeat_timeout_rate，形如 {"msg_fail_rate":{"warn":1,"bad":5}}'),
  ('session.page_size',        '20',   '会话列表分页大小'),
  -- P2 有界 SCAN 的三道闸门。**三者缺一不可**：COUNT 只是「每轮提示值」而非上限，
  -- Redis 可能每轮返回任意数量，故必须同时限制轮次与累计键数，超限即把 truncated=true
  -- 透传给 UI —— 不允许静默截断，否则运维会把「扫到的一半」当成全量。
  ('session.scan_count',       '200',  '会话/撤销名单 SCAN 每轮 COUNT 提示值（非上限）'),
  ('session.scan_max_rounds',  '50',   'SCAN 轮次上限（防御游标不收敛）'),
  ('session.scan_max_keys',    '2000', 'SCAN 累计键数上限（超限则 truncated=true）'),
  ('ops.log_tail_lines',       '500',  '日志尾读默认行数');
