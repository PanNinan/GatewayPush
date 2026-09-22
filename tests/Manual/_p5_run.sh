#!/usr/bin/env bash
# P5 一次性编排：起客户端 -> 停网关 -> 离线推送 -> 重启网关 -> 验证重连/重鉴权/补投
#
# 用法：bash tests/Manual/_p5_run.sh [uid] [device]
# 前置：register / udp / business / api 角色已启动（gateway 由本脚本中途停掉再拉起）。
#
# 必须以仓库根为工作目录：脚本内一律使用仓库根相对路径（runtime/logs、tests/Manual、start.php）
cd "$(dirname "$0")/../.." || exit 1

set -u

UID_ARG=${1:-p5-uid-auto}
DEV_ARG=${2:-p5-dev-auto}
LOG_DIR=runtime/logs
mkdir -p "$LOG_DIR"
A_LOG="$LOG_DIR/manual_p5_a.log"
GW_LOG="$LOG_DIR/manual_p5_gw2.log"

echo "== P5 编排启动 uid=${UID_ARG} device=${DEV_ARG} =="

php tests/Manual/_p5_reconnect_check.php "$UID_ARG" "$DEV_ARG" > "$A_LOG" 2>&1 &
CLIENT_PID=$!

sleep 6
echo "--- [1] 客户端首轮输出 ---"
cat "$A_LOG"

GWPID=$(netstat -ano | grep ':8282' | grep -i listening | awk '{print $5}' | head -1)
echo "--- [2] 停止 gateway pid=${GWPID} ---"
if [ -n "${GWPID}" ]; then
  taskkill //F //PID "${GWPID}" 2>&1 | head -2
else
  echo "未找到 gateway 监听，编排中止"
fi
sleep 3
netstat -ano | grep ':8282' | grep -i listening | head -1 || echo "8282 已释放"

echo "--- [3] 经 AdminApi 投递离线消息 ---"
php tests/Manual/_p5_push_offline.php "$UID_ARG" 2>&1 | tail -3

echo "--- [4] 重启 gateway ---"
php start.php start --role=gateway > "$GW_LOG" 2>&1 &
sleep 40

echo "--- [5] 客户端完整输出 ---"
cat "$A_LOG"
kill "$CLIENT_PID" 2>/dev/null
echo "== 编排结束 =="
