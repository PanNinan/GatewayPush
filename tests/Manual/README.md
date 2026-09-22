# 手工验证脚本（tests/Manual/）

P3 / P4 / P5 里程碑的**一次性实测脚本**，用于对「运行中的服务」做人工验收。
它们不属于任何自动化套件（PHPUnit 只扫 `tests/Unit` 与 `client/tests/Unit`），
需要按下方前置条件手工执行，退出码 `0` 表示全部场景符合预期。

> 历史：这些脚本原堆在 `runtime/` 根目录下。`runtime/` 已在 `.gitignore` 中，
> 而它们是**需要版本化的验收资产**，故迁出到 `tests/Manual/`；`runtime/` 只保留
> 运行时产物（`logs/` `pid/` `phpstan/`）。

## 脚本清单

| 脚本 | 验证内容 | 前置角色 |
|---|---|---|
| `_p3_udp_check.php` | UDP 通道 7 场景：传输层 ack / 业务层回执双层判别 / 篡改签名拦截 / 静默声明 / 推送回执闭环 | register · udp · business |
| `_p4_admin_check.php` | AdminApi 5 场景：`/health` 免鉴权 / 验签通过 / 错误密钥 401 / 过期时间戳 401 | api |
| `_p5_bind_check.php` | 设备绑定「首个绑定者胜出」：首次绑定 / 换 device 被拒 / 清绑定键后放行 | register · gateway · business |
| `_p5_push_offline.php` | 经 AdminApi 向指定 uid 投递一条离线消息（目标须不在线） | api |
| `_p5_reconnect_check.php` | WS 重连 + 自动重鉴权 + 离线补投，需外部中途停/起 gateway | register · gateway · udp · business |
| `_p5_run.sh` | 上述重连用例的**一键编排**：起客户端 → 停网关 → 离线投递 → 重启网关 → 校验补投 | 同 `_p5_reconnect_check.php` |

## 用法

跑单个脚本（示例）：

```bash
php tests/Manual/_p3_udp_check.php <uid> <device_id>
php tests/Manual/_p4_admin_check.php
php tests/Manual/_p5_bind_check.php
php tests/Manual/_p5_push_offline.php <uid>
```

一键编排（会自动停掉再拉起 gateway）：

```bash
bash tests/Manual/_p5_run.sh [uid] [device]
```

## 注意事项

- **uid 必须每轮更换**。UDP 会话不会因客户端退出而在 Redis 中失效（UDP 无断连事件），
  复用同一 uid 会让上一轮遗留的会话被判定为在线，离线补投类用例随即失败。
- 编排脚本的中间输出写入 `runtime/logs/manual_p5_*.log`。
- 这些脚本含 `exit()`：它们是**独立 CLI 脚本**，不受「`src/` 内零 `exit/die`」约束
  （该约束针对常驻进程代码，见 `docs/Workerman 框架 AI 编码规范.md`）。
