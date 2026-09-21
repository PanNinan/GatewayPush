#!/usr/bin/env bash
# 临时编排脚本：按依赖顺序拉起全部 6 个角色并常驻
#
# 为什么必须压进单个后台任务：Windows 下 workerman 不支持单文件启动全部组件
# （start.php 会对 --role=all 直接报错退出），因此只能逐角色起进程；
# 而这些进程是本脚本的子进程，一旦脚本退出即被一并回收。
# 故本脚本末尾 wait 常驻，由调用方用 run_in_background 持有它。
# runtime/ 已在 .gitignore 中，本文件为实测临时产物。

set -u

cd "$(dirname "$0")/.." || exit 1

LOG="runtime/_boot.log"
: > "$LOG"

for role in register gateway udp business api dashboard; do
    php start.php start --role="$role" >> "$LOG" 2>&1 &
    echo "started $role (pid $!)" >> "$LOG"
    sleep 1.5
done

echo "=== all roles launched, holding ===" >> "$LOG"
wait
