#!/usr/bin/env bash
#
# ============================================================================
#  GatewayWorker 实时数据推送服务 —— Linux/macOS 服务管理脚本
# ============================================================================
#
#  职责边界
#  --------------------------------------------------------------------------
#  本脚本只做「进程编排 + 终端编码」，不做任何业务判断。环境自检、角色装配、
#  启动流程全部由 start.php 负责，两边不重复实现，避免出现两套真源。
#
#  中文显示（Linux）
#  --------------------------------------------------------------------------
#  终端能否正确显示中文，取决于两件相互独立的事：
#    1) SSH 客户端 / 终端仿真器自身的字符集是否为 UTF-8 —— 脚本无法更改，
#       只能检测 locale 并给出提示；
#    2) 当前进程 locale 的字符集是否为 UTF-8 —— 脚本会自动纠正。
#  locale 不是 UTF-8 时，除了显示乱码，还会连带影响 PHP 的字符串函数（按字节
#  而非按字符计数）与 sort 顺序，所以脚本一启动就把它修正掉。
#
#  另外两个易踩的点：
#    * `less` 不会自动按 UTF-8 解码，看日志请用 `less -R`，或本脚本的 log 命令；
#    * 终端里排含中文的表格不能直接用 printf 的 %-Ns —— 它按「字符数」补空格，
#      而中文占 2 列，结果必然错位。本脚本用 disp_width() 按显示宽度对齐。
#
#  为什么脚本要自己校验 PHP 版本
#  --------------------------------------------------------------------------
#  项目的真实 PHP 下限由**依赖**决定（workerman 5.x 要求 >= 8.1），不由代码语法
#  决定。Composer 会在 autoload 阶段用生成的 platform_check.php 抛 RuntimeException，
#  所以用旧解释器运行时，用户看到的是一段 Composer 堆栈，而不是本项目的友好提示。
#  生产机上同时存在发行版自带 PHP 与自编译 PHP 时极易踩到（发行版常年落后）。
#  本脚本在 preflight 阶段探测版本并拒绝过低的解释器；下限以 config/app.php 的
#  php_min 为唯一真源，不在脚本里另立一份。
#
#  用法
#  --------------------------------------------------------------------------
#    ./bin/start.sh <命令> [参数]
#    ./bin/start.sh help
#
set -o pipefail

# ---------------------------------------------------------------------------
# 0. 基础路径
# ---------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$ROOT_DIR" || { echo "无法进入项目目录：$ROOT_DIR" >&2; exit 1; }

PHP_BIN="${PHP_BIN:-php}"
PID_DIR="$ROOT_DIR/runtime/pid"
LOG_DIR="$ROOT_DIR/runtime/logs"

# 启动顺序即依赖顺序：register 必须先就绪，其余角色都要向它注册
ROLES_ALL=(register gateway udp business api dashboard)
ROLES_CORE=(register gateway udp business api)

# ---------------------------------------------------------------------------
# 1. 终端编码
# ---------------------------------------------------------------------------
if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
    C_RED=$'\033[31m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'
    C_CYAN=$'\033[36m'; C_DIM=$'\033[2m';  C_OFF=$'\033[0m'
else
    C_RED=''; C_GREEN=''; C_YELLOW=''; C_CYAN=''; C_DIM=''; C_OFF=''
fi

setup_utf8_locale() {
    local cur="${LC_ALL:-${LC_CTYPE:-${LANG:-}}}"
    case "$cur" in
        *[Uu][Tt][Ff]*8*) return 0 ;;
    esac

    local cand
    for cand in zh_CN.UTF-8 zh_CN.utf8 C.UTF-8 C.utf8 en_US.UTF-8 en_US.utf8; do
        if [ "$(LC_ALL="$cand" LANG="$cand" locale charmap 2>/dev/null)" = "UTF-8" ]; then
            export LANG="$cand" LC_ALL="$cand"
            printf '%s\n' "${C_YELLOW}[提示]${C_OFF} 原始 locale 非 UTF-8（${cur:-未设置}），已自动切换为 $cand"
            return 0
        fi
    done

    printf '%s\n' "${C_RED}[警告]${C_OFF} 当前 locale 非 UTF-8（${cur:-未设置}），且系统未安装任何 UTF-8 locale。"
    printf '%s\n' "       中文将显示为乱码。修复方式：安装 locales 后执行 locale-gen zh_CN.UTF-8，"
    printf '%s\n' "       或临时执行 export LANG=C.UTF-8 后重试。"
    return 0
}

# ---------------------------------------------------------------------------
# 2. 输出与表格
# ---------------------------------------------------------------------------
info()  { printf '%s\n' "${C_CYAN}==>${C_OFF} $*"; }
ok()    { printf '%s\n' "${C_GREEN}[OK]${C_OFF}    $*"; }
warn()  { printf '%s\n' "${C_YELLOW}[警告]${C_OFF}  $*" >&2; }
err()   { printf '%s\n' "${C_RED}[错误]${C_OFF}  $*" >&2; }

# 字符串在等宽终端下的显示宽度：CJK 字符占 2 列，其余占 1 列
disp_width() {
    local s="$1" i ch cp w=0
    for (( i = 0; i < ${#s}; i++ )); do
        ch="${s:i:1}"
        cp=$(printf '%d' "'$ch" 2>/dev/null) || cp=0
        if (( cp >= 0x1100 )) && {
                (( cp <= 0x115F )) || (( cp >= 0x2E80 && cp <= 0xA4CF )) ||
                (( cp >= 0xAC00 && cp <= 0xD7A3 )) || (( cp >= 0xF900 && cp <= 0xFAFF )) ||
                (( cp >= 0xFE30 && cp <= 0xFE6F )) || (( cp >= 0xFF00 && cp <= 0xFF60 )) ||
                (( cp >= 0xFFE0 && cp <= 0xFFE6 ));
            }; then
            w=$(( w + 2 ))
        else
            w=$(( w + 1 ))
        fi
    done
    printf '%d' "$w"
}

# 按显示宽度左对齐补空格（printf 的 %-Ns 只按字符数补，含中文必错位）
pad() {
    local s="$1" width="$2" cur
    cur=$(disp_width "$s")
    printf '%s' "$s"
    while (( cur < width )); do printf ' '; cur=$(( cur + 1 )); done
}

# 状态表的列宽（显示宽度，非字符数）
COL_ROLE=12; COL_STATE=12; COL_ADDR=27; COL_PID=10; COL_MEM=10; COL_UP=12

table_rule() {
    local w k
    # 列宽之和 + 末列「说明」的 4 列 —— 与 pad() 的补齐宽度严格对齐，
    # 不能每列额外多打一个空格，否则分隔线会逐列右偏。
    for w in $COL_ROLE $COL_STATE $COL_ADDR $COL_PID $COL_MEM $COL_UP 4; do
        for (( k = 0; k < w; k++ )); do printf '%s' '-'; done
    done
    # 注意：不能写 printf '-\n' —— bash 内建 printf 会把以 - 开头的格式串当成选项而报错。
    printf '\n'
}

table_head() {
    pad '角色' "$COL_ROLE"; pad '状态' "$COL_STATE"; pad '监听地址' "$COL_ADDR"
    pad 'PID' "$COL_PID";  pad '内存' "$COL_MEM";  pad '运行时长' "$COL_UP"
    printf '%s\n' '说明'
    table_rule
}

table_row() {
    pad "$1" "$COL_ROLE"; pad "$2" "$COL_STATE"; pad "$3" "$COL_ADDR"
    pad "$4" "$COL_PID";  pad "$5" "$COL_MEM";  pad "$6" "$COL_UP"
    printf '%s\n' "$7"
}

# ---------------------------------------------------------------------------
# 3. 角色元信息
# ---------------------------------------------------------------------------
role_desc() {
    case "$1" in
        all)       printf '%s' '全部组件' ;;
        register)  printf '%s' '注册中心' ;;
        gateway)   printf '%s' 'WebSocket 网关' ;;
        udp)       printf '%s' 'UDP 网关' ;;
        business)  printf '%s' '业务进程' ;;
        api)       printf '%s' 'HTTP 接口' ;;
        dashboard) printf '%s' '监控面板' ;;
        *)         printf '%s' '-' ;;
    esac
}

role_addr_key() {
    case "$1" in
        register)  printf '%s' 'REGISTER_LISTEN' ;;
        gateway)   printf '%s' 'WS_LISTEN' ;;
        udp)       printf '%s' 'UDP_LISTEN' ;;
        api)       printf '%s' 'API_LISTEN' ;;
        dashboard) printf '%s' 'DASHBOARD_LISTEN' ;;
        *)         printf '%s' '' ;;
    esac
}

is_role() {
    case " ${ROLES_ALL[*]} " in
        *" $1 "*) return 0 ;;
        *) return 1 ;;
    esac
}

# 读取 .env 中的配置项（仅取最后一个匹配项，与 phpdotenv 的覆盖语义一致）
env_get() {
    local key="$1" f="$ROOT_DIR/.env" line
    [ -f "$f" ] || return 1
    line="$(grep -E "^[[:space:]]*${key}[[:space:]]*=" "$f" 2>/dev/null | tail -n1)"
    [ -n "$line" ] || return 1
    line="${line#*=}"
    line="${line%$'\r'}"
    line="${line#\"}"; line="${line%\"}"
    line="${line#\'}"; line="${line%\'}"
    printf '%s' "$line"
}

role_listen() {
    local key raw
    key="$(role_addr_key "$1")"
    [ -n "$key" ] || { printf '%s' '-'; return 0; }
    raw="$(env_get "$key" 2>/dev/null)"
    if [ -n "$raw" ]; then printf '%s' "$raw"; else printf '%s' '-'; fi
}

# ---------------------------------------------------------------------------
# 4. 前置检查
# ---------------------------------------------------------------------------
# PHP 下限（版本 ID，如 80100 = 8.1.0），取自 config/app.php 的 php_min；
# 解析不出来时回落到 8.1.0 —— 宁可报错，也不要因读不到配置而放行旧解释器。
php_min_id() {
    local v major minor patch
    v="$(sed -n "s/.*'php_min'[[:space:]]*=>[[:space:]]*'\([0-9][0-9.]*\)'.*/\1/p" \
            "$ROOT_DIR/config/app.php" 2>/dev/null | head -n 1)"
    case "$v" in
        [0-9]*.[0-9]*.[0-9]*) ;;
        *) v='8.1.0' ;;
    esac
    IFS='.' read -r major minor patch <<<"$v"
    printf '%d' $(( major * 10000 + minor * 100 + patch ))
}

# 版本 ID -> x.y.z
fmt_ver() {
    printf '%d.%d.%d' $(( $1 / 10000 )) $(( ($1 / 100) % 100 )) $(( $1 % 100 ))
}

# 探测解释器版本 ID；失败时无输出且返回非 0。
# 用 -r 而不是 -v：只跑一行代码，不触碰本项目文件 —— 版本过低时正是 Composer 的
# platform_check.php 会抛异常，靠它探测根本拿不到版本号。
php_version_id() {
    local out
    out="$("$1" -r 'echo PHP_VERSION_ID;' 2>/dev/null)" || return 1
    case "$out" in
        ''|*[!0-9]*) return 1 ;;
    esac
    printf '%s' "$out"
}

# 版本校验必须发生在任何 php_run 之前，否则报错会被 Composer 堆栈顶替
check_php_version() {
    local min id
    min="$(php_min_id)"
    if ! id="$(php_version_id "$PHP_BIN")"; then
        warn "无法探测 PHP 版本（$PHP_BIN），已跳过版本校验。"
        return 0
    fi
    if (( id < min )); then
        err "PHP 版本过低：$(fmt_ver "$id")，本项目要求 >= $(fmt_ver "$min")。"
        err "当前解释器：$(command -v "$PHP_BIN" 2>/dev/null || printf '%s' "$PHP_BIN")"
        err "请用 PHP_BIN=/path/to/php 指定符合要求的版本后重试。"
        return 1
    fi
    return 0
}

preflight() {
    if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
        err "未找到 PHP 可执行文件：$PHP_BIN"
        err "请把 PHP 加入 PATH，或用 PHP_BIN=/path/to/php 指定。"
        return 1
    fi
    check_php_version || return 1
    if [ ! -f "$ROOT_DIR/vendor/autoload.php" ]; then
        err "依赖未安装，请先执行：composer install"
        return 1
    fi
    return 0
}

warn_platform() {
    case "$(uname -s 2>/dev/null)" in
        MINGW*|MSYS*|CYGWIN*)
            warn '检测到 Windows 环境（Git Bash / MSYS）。本脚本面向 Linux ——'
            warn 'Windows 下 start.php 不支持 --role=all，请改用 bin\start.bat。'
            return 1
            ;;
    esac
    return 0
}

# Linux 下单进程承载全部组件，长连接数上万时需要足够的文件句柄
warn_ulimit() {
    local n
    n="$(ulimit -n 2>/dev/null)"
    if [ -n "$n" ] && [ "$n" != "unlimited" ] && [ "$n" -lt 10240 ] 2>/dev/null; then
        warn "当前文件句柄上限 ulimit -n = $n，支撑上万长连接建议调至 65535 以上。"
        warn "临时调整：ulimit -n 65535；永久调整：/etc/security/limits.conf"
    fi
}

php_run() {
    "$PHP_BIN" "$ROOT_DIR/start.php" "$@"
}

# ---------------------------------------------------------------------------
# 5. 进程查询
# ---------------------------------------------------------------------------
role_pid() {
    local role="$1" f pid
    f="$PID_DIR/workerman_${role}.pid"
    [ -f "$f" ] || return 1
    pid="$(tr -dc '0-9' < "$f" 2>/dev/null)"
    [ -n "$pid" ] || return 1
    # kill -0 只做权限与存活探测，不发送真实信号
    kill -0 "$pid" 2>/dev/null || return 1
    printf '%s' "$pid"
}

proc_mem() {
    local pid="$1" rss
    rss="$(ps -o rss= -p "$pid" 2>/dev/null | tr -d ' ')"
    [ -n "$rss" ] || { printf '%s' '-'; return 0; }
    printf '%s' "$(( rss / 1024 )).$(( (rss % 1024) * 10 / 1024 )) MB"
}

proc_uptime() {
    local pid="$1" et
    et="$(ps -o etime= -p "$pid" 2>/dev/null | tr -d ' ')"
    [ -n "$et" ] || { printf '%s' '-'; return 0; }
    printf '%s' "$et"
}

# ---------------------------------------------------------------------------
# 6. 命令实现
# ---------------------------------------------------------------------------
cmd_start() {
    local role="${1:-all}"

    if [ "$role" = "all" ]; then
        warn_platform || return 1
        if role_pid all >/dev/null; then
            warn "全部组件已在运行（PID $(role_pid all)），无需重复启动。"
            return 1
        fi
        preflight || return 1
        warn_ulimit
        info '按顺序装配并守护启动全部组件（register / gateway / udp / business / api / dashboard）'
        printf '\n'
        php_run start -d
        local rc=$?
        if [ "$rc" -ne 0 ]; then
            err "启动命令返回 $rc，请查看上方输出。"
            return "$rc"
        fi

        # 守护模式下 php 会立即返回，这里补一次就绪确认，
        # 避免「命令看似成功、服务其实没起来」这种最难排查的状态。
        local i pid
        for i in 1 2 3 4 5 6 7 8 9 10; do
            if pid="$(role_pid all)"; then
                printf '\n'
                ok "全部组件已就绪（PID $pid）"
                printf '\n'
                cmd_status
                return 0
            fi
            sleep 1
        done
        err '守护模式已返回，但 10 秒内未检测到 pid 文件，请用 status 复查。'
        err "若确实启动失败，看日志：$LOG_DIR/stdout.log"
        return 1
    fi

    if ! is_role "$role"; then
        err "非法角色：$role（可选值：all ${ROLES_ALL[*]}）"
        return 1
    fi
    preflight || return 1
    info "前台启动角色 $role（$(role_desc "$role")），Ctrl+C 退出"
    printf '\n'
    php_run start --role="$role"
}

stop_one() {
    local role="$1" quiet="${2:-}" pid

    if ! pid="$(role_pid "$role")"; then
        [ "$quiet" = quiet ] && return 0
        printf '%s\n' "$(pad "$role" "$COL_ROLE")${C_DIM}未在运行${C_OFF}"
        return 0
    fi

    printf '%s\n' "$(pad "$role" "$COL_ROLE")停止中（PID $pid）…"
    if php_run stop --role="$role" >/dev/null 2>&1; then
        ok "$(pad "$role" "$COL_ROLE")已停止"
    else
        err "$(pad "$role" "$COL_ROLE")停止失败，可尝试 kill -TERM $pid"
        return 1
    fi
}

cmd_stop() {
    local role="${1:-all}" r rc=0

    if [ "$role" != "all" ]; then
        is_role "$role" || { err "非法角色：$role"; return 1; }
        stop_one "$role"
        return $?
    fi

    if ! warn_platform; then
        # Windows 下 workerman 不解析 stop 命令，会「反向启动一个新实例」，
        # 这里必须硬拦，否则用户每执行一次 stop 就多一个进程。
        err '当前平台不支持通过本脚本停止服务，请使用 bin\start.bat stop。'
        return 1
    fi

    # 两种部署方式都覆盖：分角色启动的逐个停，单进程启动的再停 all
    for r in "${ROLES_ALL[@]}"; do
        stop_one "$r" quiet || rc=1
    done
    stop_one all quiet || rc=1

    if [ "$rc" -eq 0 ]; then
        ok '服务已停止'
    else
        err '部分角色停止失败，请用 status 复查'
    fi
    return $rc
}

cmd_restart() {
    local role="${1:-all}"
    cmd_stop "$role" || return 1
    sleep 1
    cmd_start "$role"
}

cmd_reload() {
    local role="${1:-all}"
    warn_platform || return 1
    if ! role_pid "$role" >/dev/null; then
        # 单进程部署时只有 workerman_all.pid：按角色 reload 找不到 pid，
        # 回落为整体平滑重启，避免误报「服务未在运行」。
        if [ "$role" != "all" ] && role_pid all >/dev/null; then
            warn "未发现独立角色 $role 的 pid 文件，检测到单进程部署，已转为整体平滑重启。"
            role=all
        else
            err "服务未在运行，无法平滑重启。"
            return 1
        fi
    fi
    info "平滑重启（$role）—— 仅重载业务代码，网关长连接不中断"
    php_run reload --role="$role"
}

cmd_status() {
    local r pid state mem up addr running=0 all_pid=''

    # 单进程部署（./bin/start.sh start 不带角色，守护模式）时，
    # start.php 只写 workerman_all.pid，各角色没有独立 pid 文件。
    # 不先探测 all，status 会把正在运行的服务全部显示成「已停止」。
    if pid="$(role_pid all)"; then
        all_pid="$pid"
    fi

    table_head
    for r in "${ROLES_ALL[@]}"; do
        addr="$(role_listen "$r")"
        if [ -n "$all_pid" ]; then
            # 单进程模式：所有角色由同一 master 承载，PID 列即该进程
            pid="$all_pid"
            state="${C_GREEN}运行中${C_OFF}"
            mem="$(proc_mem "$pid")"
            up="$(proc_uptime "$pid")"
            running=$(( running + 1 ))
        elif pid="$(role_pid "$r")"; then
            state="${C_GREEN}运行中${C_OFF}"
            mem="$(proc_mem "$pid")"
            up="$(proc_uptime "$pid")"
            running=$(( running + 1 ))
        else
            state="${C_DIM}已停止${C_OFF}"
            pid='-'; mem='-'; up='-'
        fi
        table_row "$r" "$state" "$addr" "$pid" "$mem" "$up" "$(role_desc "$r")"
    done
    printf '\n'
    printf '%s\n' "本机 PID：$$   项目目录：$ROOT_DIR"
    printf '%s\n' "运行中 $running / ${#ROLES_ALL[@]} 个角色"
    if [ -n "$all_pid" ]; then
        printf '%s\n' "${C_DIM}部署模式：单进程 —— 全部组件由同一 master 进程（PID $all_pid）承载${C_OFF}"
    fi
}

cmd_log() {
    local follow=0 type='workerman' lines=60 arg file

    while [ $# -gt 0 ]; do
        arg="$1"; shift
        case "$arg" in
            -f|--follow) follow=1 ;;
            [0-9]*)      lines="$arg" ;;
            *)           type="$arg" ;;
        esac
    done

    case "$type" in
        workerman) file="$LOG_DIR/workerman.log" ;;
        stdout)    file="$LOG_DIR/stdout.log" ;;
        register|gateway|udp|business|api|dashboard|all|app|error)
            file="$(ls -1t "$LOG_DIR/${type}_"*.log 2>/dev/null | head -n1)" ;;
        *)
            err "未知日志通道：$type（可选 workerman / stdout / 角色名 / error）"
            return 1 ;;
    esac

    if [ -z "$file" ] || [ ! -f "$file" ]; then
        err "未找到日志文件：$LOG_DIR/${type}*"
        return 1
    fi

    info "日志文件：$file"
    printf '\n'
    if [ "$follow" -eq 1 ]; then
        tail -n "$lines" -f "$file"
    else
        tail -n "$lines" "$file"
    fi
}

cmd_help() {
    cat <<'EOF'
GatewayWorker 实时数据推送服务 —— Linux 服务管理脚本

用法：
  ./bin/start.sh <命令> [参数]

命令：
  start [角色]        启动。不带角色 = 全部组件（守护模式 -d）；
                      带角色 = 前台运行该角色，Ctrl+C 退出（排查单个组件时用）
  stop  [角色|all]    停止。不带参数 = 停全部（分角色与单进程两种部署都覆盖）
  restart [角色|all]  重启（先停后启）
  reload [角色|all]   平滑重启，仅重载业务代码，网关长连接不中断
  status              进程状态一览（PID / 内存 / 运行时长 / 监听地址）
  log [-f] [通道] [行数]
                      查看日志。通道：workerman(默认) / stdout / error(跨角色错误汇总)
                      / 角色名(register gateway udp business api dashboard all app)
                      -f 持续跟随；行数默认 60
  check               仅执行环境自检，不启动服务
  env:init            生成 .env（首部署必执行，自动注入随机密钥）
  token <uid> [device] [ttl]      生成调试用 Token
  push <类型> <目标> [payload] [msg_id] [offline_mode]
                      提交一条定向推送任务
  help                显示本帮助

角色：all  register  gateway  udp  business  api  dashboard

环境变量：
  PHP_BIN             指定 PHP 可执行文件（默认取 PATH 中的 php）
  NO_COLOR            设为任意值可关闭彩色输出

示例：
  ./bin/start.sh start                      # 生产：守护模式启动全部组件
  ./bin/start.sh start business             # 开发：单独前台运行业务进程
  ./bin/start.sh status
  ./bin/start.sh log -f warn                # 跟随告警日志
  PHP_BIN=/usr/local/php/bin/php ./bin/start.sh check
EOF
}

# ---------------------------------------------------------------------------
# 7. 入口
# ---------------------------------------------------------------------------
setup_utf8_locale

cmd="${1:-help}"
[ $# -gt 0 ] && shift

case "$cmd" in
    start)      cmd_start "$@" ;;
    stop)       cmd_stop "$@" ;;
    restart)    cmd_restart "$@" ;;
    reload)     cmd_reload "$@" ;;
    status|svc-status) cmd_status "$@" ;;
    log|logs|tail)     cmd_log "$@" ;;
    check)      preflight && php_run check ;;
    env:init)   preflight && php_run env:init ;;
    token)      preflight && php_run token "$@" ;;
    push)       preflight && php_run push "$@" ;;
    help|-h|--help) cmd_help ;;
    *)
        err "未知命令：$cmd"
        printf '\n'
        cmd_help
        exit 2
        ;;
esac
exit $?
