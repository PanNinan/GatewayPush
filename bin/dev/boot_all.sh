#!/usr/bin/env bash
# 本机开发编排：按依赖顺序拉起全部 6 个角色并常驻
#
# 为什么需要它：Windows 下 workerman 不支持单文件启动全部组件（start.php 对
# --role=all 直接报错退出），开发时只能逐角色起进程；而这些进程是本脚本的子进程，
# 一旦脚本退出即被一并回收。故本脚本末尾 wait 常驻，由调用方用 run_in_background 持有。
#
# 用法（需在支持后台任务持有的环境里执行，前台跑则会占用当前终端）：
#   bash bin/dev/boot_all.sh
#
# 与 bin/start.* 的分工：本脚本是**开发/调试编排**，只负责把角色都拉起来并常驻；
# 生产启停一律走 bin/start.sh（Linux）或 bin/start.bat（Windows）—— 它们会做
# 环境预检、按角色就绪轮询、跳过被配置关闭的角色，本脚本不做这些。

set -u

# 以仓库根为工作目录：LOG 与 start.php 均相对仓库根引用
cd "$(dirname "$0")/../.." || exit 1

LOG_DIR="runtime/logs"
mkdir -p "$LOG_DIR"
LOG="$LOG_DIR/dev_boot.log"
: > "$LOG"

for role in register gateway udp business api dashboard; do
    php start.php start --role="$role" >> "$LOG" 2>&1 &
    echo "started $role (pid $!)" >> "$LOG"
    sleep 1.5
done

echo "=== all roles launched, holding ===" >> "$LOG"
wait
