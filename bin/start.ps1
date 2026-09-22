#Requires -Version 5.1
<#
============================================================================
 GatewayPush 实时数据推送服务 —— Windows 服务管理脚本
============================================================================

 职责边界
 --------------------------------------------------------------------------
 本脚本只做「进程编排 + 终端编码」，不做任何业务判断。环境自检、角色装配、
 启动流程全部由 start.php 负责。

 为什么 Windows 下不能用 start.php 的 stop / restart / reload / status
 --------------------------------------------------------------------------
 workerman 的命令行解析入口 Worker::parseCommand() 第一行就是：
     if (DIRECTORY_SEPARATOR !== '/') { return; }
 非 Unix 平台直接跳过整个解析流程，于是 `php start.php stop --role=xxx` 不会
 停止任何进程，而是**照常启动一个新实例** —— 每执行一次就多一个进程、多一份
 端口占用。Windows 下也没有 pid 文件（workerman 只在 Unix 侧写盘）。

所以本脚本自己承担进程编排：启动时记录窗口 PID，停止 / 状态则以
`Get-CimInstance Win32_Process` 读取命令行反查 php.exe，二者互相兜底。

 为什么脚本要自己校验 PHP 版本
 --------------------------------------------------------------------------
 项目的真实 PHP 下限由**依赖**决定（workerman 5.x 要求 >= 8.1），不由代码语法
 决定。Composer 会在 autoload 阶段用生成的 platform_check.php 直接抛
 RuntimeException，所以拿旧解释器跑，用户看到的是一段 Composer 堆栈，而不是
 本项目自己的提示，极难定位。

 两个诱因：
   1) PATH 里的第一个 php.exe 未必是本项目能用的版本（本机装了 5 个）；
   2) IDE 会污染 PATH —— PhpStorm 把「项目默认解释器」注入集成终端，本机注入
      的是 8.0.2，于是在 PhpStorm 终端里执行 start 会让 6 个角色全部启动失败。
 故本脚本解析解释器时逐一探测版本，跳过低于下限的候选；全部候选都不合格时
 给出完整清单。下限以 config/app.php 的 php_min 为唯一真源。

中文显示（Windows）
 --------------------------------------------------------------------------
 乱码的根因是「编码对不上」：php.exe 恒以 UTF-8 字节写出，而简体中文版
 Windows 的控制台活动代码页是 936（GBK），按 GBK 解码 UTF-8 字节必然乱码。
 本脚本处理三处：
   1) 自身控制台 —— 设 [Console]::OutputEncoding / InputEncoding / $OutputEncoding
      为 UTF-8，.NET 会同步把控制台代码页切成 65001；
   2) 本文件编码 —— 必须保存为 **UTF-8 带 BOM**，否则 PowerShell 5.1 会按
      ANSI（GBK）解读脚本文件，脚本内的中文字面量先一步烂掉；
   3) 新开的角色窗口 —— 新控制台会退回系统默认代码页，故启动命令统一以
      `cmd /k "chcp 65001>nul && ..."` 包裹。
 另：控制台字体需支持中文（Consolas 无 CJK 字形，建议「新宋体 / 微软雅黑 /
 Lucida Console」或在 Windows Terminal 中使用）。

 用法
 --------------------------------------------------------------------------
     bin\start.ps1 <命令> [参数]
     bin\start.ps1 help
#>

[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [string]$Command = 'help',

    [Parameter(Position = 1, ValueFromRemainingArguments = $true)]
    [string[]]$Arguments,

    # 指定 php.exe 绝对路径；不传则依次尝试 PATH、phpstudy_pro 常见目录
    [string]$PhpPath = ''
)

$ErrorActionPreference = 'Stop'

if ($null -eq $Arguments) { $Arguments = @() }

# ---------------------------------------------------------------------------
# 0. 终端编码（中文不乱码的关键，必须最先执行）
# ---------------------------------------------------------------------------
$Utf8NoBom = New-Object System.Text.UTF8Encoding($false)
try {
    [Console]::OutputEncoding = $Utf8NoBom   # 解读子进程 stdout + 控制台输出代码页
    [Console]::InputEncoding = $Utf8NoBom    # 控制台输入代码页
    $OutputEncoding = $Utf8NoBom             # 管道给原生程序时所用编码
} catch {
    Write-Host '[警告]  切换控制台编码为 UTF-8 失败，中文可能显示为乱码。' -ForegroundColor Yellow
}

# ---------------------------------------------------------------------------
# 1. 基础路径
# ---------------------------------------------------------------------------
if ($PSScriptRoot) {
    $ScriptDir = $PSScriptRoot
} else {
    $ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition
}
$Root = (Resolve-Path -LiteralPath (Join-Path $ScriptDir '..')).Path
Set-Location -LiteralPath $Root

$PidDir = Join-Path $Root 'runtime\pid'
$LogDir = Join-Path $Root 'runtime\logs'

# 启动顺序即依赖顺序：register 必须先就绪，其余角色都要向它注册
$RoleOrder = @('register', 'gateway', 'udp', 'business', 'api', 'dashboard')
$RoleCore = @('register', 'gateway', 'udp', 'business', 'api')

$RoleMeta = @{
    register  = @{ Desc = '注册中心';       AddrKey = 'REGISTER_LISTEN';   Port = $true  }
    gateway   = @{ Desc = 'WebSocket 网关'; AddrKey = 'WS_LISTEN';         Port = $true  }
    udp       = @{ Desc = 'UDP 网关';       AddrKey = 'UDP_LISTEN';        Port = $true  }
    business  = @{ Desc = '业务进程';       AddrKey = '';                  Port = $false }
    api       = @{ Desc = 'HTTP 接口';      AddrKey = 'API_LISTEN';        Port = $true  }
    dashboard = @{ Desc = '监控面板';       AddrKey = 'DASHBOARD_LISTEN';  Port = $true  }
}

# ---------------------------------------------------------------------------
# 2. 输出与表格
# ---------------------------------------------------------------------------
function Write-Step  { param([string]$Text) Write-Host ('==> ' + $Text) -ForegroundColor Cyan }
function Write-Ok    { param([string]$Text) Write-Host ('[OK]    ' + $Text) -ForegroundColor Green }
function Write-Warn2 { param([string]$Text) Write-Host ('[警告]  ' + $Text) -ForegroundColor Yellow }
function Write-Err   { param([string]$Text) Write-Host ('[错误]  ' + $Text) -ForegroundColor Red }

# 字符串在等宽终端下的显示宽度：CJK 字符占 2 列，其余占 1 列。
# 直接用 PadRight() 只按「字符数」补空格，含中文的列会错位 —— 这正是
# 在终端里排中文表格最容易踩的坑。
function Get-TextWidth {
    param([string]$Text)
    $w = 0
    foreach ($ch in $Text.ToCharArray()) {
        $c = [int][char]$ch
        if (($c -ge 0x1100 -and $c -le 0x115F) -or
            ($c -ge 0x2E80 -and $c -le 0xA4CF) -or
            ($c -ge 0xAC00 -and $c -le 0xD7A3) -or
            ($c -ge 0xF900 -and $c -le 0xFAFF) -or
            ($c -ge 0xFE30 -and $c -le 0xFE6F) -or
            ($c -ge 0xFF00 -and $c -le 0xFF60) -or
            ($c -ge 0xFFE0 -and $c -le 0xFFE6)) { $w += 2 } else { $w += 1 }
    }
    return $w
}

function Format-Pad {
    param([string]$Text, [int]$Width)
    if ($null -eq $Text) { $Text = '' }
    $cur = Get-TextWidth $Text
    if ($cur -ge $Width) { return ($Text + ' ') }
    return $Text + (' ' * ($Width - $cur))
}

# 状态表列宽（显示宽度，非字符数）
$COL_ROLE = 12; $COL_STATE = 12; $COL_ADDR = 27
$COL_PID = 10;  $COL_MEM = 10;   $COL_UP = 12

function Write-TableHead {
    Write-Host ((Format-Pad '角色' $COL_ROLE) + (Format-Pad '状态' $COL_STATE) +
                (Format-Pad '监听地址' $COL_ADDR) + (Format-Pad 'PID' $COL_PID) +
                (Format-Pad '内存' $COL_MEM) + (Format-Pad '运行时长' $COL_UP) + '说明')
    # 固定列共 $COL_ROLE+$COL_STATE+$COL_ADDR+$COL_PID+$COL_MEM+$COL_UP 个显示列，
    # 末列「说明」不参与补齐，按表头文字宽度 4 计
    $width = $COL_ROLE + $COL_STATE + $COL_ADDR + $COL_PID + $COL_MEM + $COL_UP + 4
    Write-Host ('-' * $width)
}

# 注意：形参不能取名 $Pid —— PowerShell 的 $PID 是只读自动变量（当前进程 ID），
# 大小写不敏感，绑定同名参数会直接抛 VariableNotWritable。
function Write-TableRow {
    param([string]$Role, [string]$State, [string]$Addr, [string]$ProcId,
          [string]$Mem, [string]$Up, [string]$Desc)
    Write-Host ((Format-Pad $Role $COL_ROLE) + (Format-Pad $State $COL_STATE) +
                (Format-Pad $Addr $COL_ADDR) + (Format-Pad $ProcId $COL_PID) +
                (Format-Pad $Mem $COL_MEM) + (Format-Pad $Up $COL_UP) + $Desc)
}

# ---------------------------------------------------------------------------
# 3. 配置读取
# ---------------------------------------------------------------------------
# .env 为 UTF-8，必须显式按 UTF-8 读取，否则其中的中文注释会以 GBK 解码
function Get-EnvValue {
    param([string]$Key)
    $file = Join-Path $Root '.env'
    if (-not (Test-Path -LiteralPath $file)) { return '' }
    $pattern = '^\s*' + [regex]::Escape($Key) + '\s*=\s*(.*)$'
    $found = ''
    foreach ($line in [System.IO.File]::ReadAllLines($file, [System.Text.Encoding]::UTF8)) {
        if ($line -match $pattern) {
            $found = $matches[1].Trim().Trim('"').Trim("'")
        }
    }
    return $found
}

# 从监听地址中取端口与协议，如 udp://0.0.0.0:8283 -> 8283 / udp
function Get-ListenEndpoint {
    param([string]$Role)
    $key = $RoleMeta[$Role].AddrKey
    if (-not $key) { return $null }
    $raw = Get-EnvValue $key
    if (-not $raw) { return $null }
    if ($raw -match ':(\d+)\s*$') {
        # 必须先把端口取出：$Matches 是自动变量，下一次 -match 会把它整体覆盖，
        # 若在后面才取 $matches[1]，拿到的将是 scheme 而不是端口号。
        $port = [int]$matches[1]
        $scheme = 'tcp'
        if ($raw -match '^([A-Za-z][A-Za-z0-9+.\-]*)://') { $scheme = $matches[1].ToLower() }
        return [pscustomobject]@{ Raw = $raw; Port = $port; Scheme = $scheme }
    }
    return $null
}

# 角色启用状态（角色 -> @{ Enabled; Env }）
#
# 真值只能问 PHP：.env < .env.{env} < .env.local < .env.{env}.local < 真实环境变量的
# 叠加语义只有 Env 类能正确还原，脚本自行解析 .env 必然与之漂移（本脚本读 .env 取监听
# 地址是「展示用」，而启用状态会直接决定启不启动某个进程，错不得）。
#
# 返回 $null 表示「问不到」，调用方按「全部启用」处理 —— 与加入本特性之前的行为一致，
# 不会因为该命令不可用而拒绝启动。
function Get-RoleStates {
    if ($script:RoleStatesReady) { return $script:RoleStates }

    $script:RoleStatesReady = $true
    $script:RoleStates      = $null

    # 原生程序写到 stderr 的内容在 Stop 偏好下会被包装成错误记录，这里只需要 stdout
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $raw = ''
    try {
        $raw = (@(& $script:PhpExe (Join-Path $Root 'start.php') 'roles' 2>$null) -join "`n").Trim()
    } catch {
        $raw = ''
    } finally {
        $ErrorActionPreference = $prev
    }

    if ($raw -eq '') {
        Write-Warn2 '未取到角色启用状态，按「全部启用」处理。'
        return $null
    }

    # PowerShell 5.1 的 ConvertFrom-Json 对非法输入抛的是非终止性错误，
    # 仅靠 try/catch 拿不到 $null 之外的信息，故解析结果还要判空
    $data = $null
    try { $data = $raw | ConvertFrom-Json } catch { $data = $null }
    if ($null -eq $data -or $null -eq $data.roles) {
        Write-Warn2 '角色启用状态解析失败（start.php 版本可能过旧），按「全部启用」处理。'
        return $null
    }

    $map = @{}
    foreach ($item in @($data.roles)) {
        if ($null -eq $item -or -not $item.role) { continue }
        $map[[string]$item.role] = [pscustomobject]@{
            Enabled = [bool]$item.enabled
            Env     = [string]$item.env
        }
    }
    if ($map.Count -eq 0) { return $null }

    $script:RoleStates = $map
    return $map
}

# ---------------------------------------------------------------------------
# 4. 前置检查
# ---------------------------------------------------------------------------
# 下限版本 ID（如 80100 = 8.1.0），以 config/app.php 的 php_min 为唯一真源。
# 解析失败时回落到 8.1.0 —— 宁可报错也不要因为读不到配置而放行旧解释器。
function Get-PhpMinVersionId {
    $fallback = 80100
    $cfg = Join-Path $Root 'config\app.php'
    if (-not (Test-Path -LiteralPath $cfg)) { return $fallback }
    try {
        $text = [System.IO.File]::ReadAllText($cfg, [System.Text.Encoding]::UTF8)
    } catch {
        return $fallback
    }
    if ($text -match "'php_min'\s*=>\s*'(\d+)\.(\d+)\.(\d+)'") {
        return ([int]$matches[1] * 10000 + [int]$matches[2] * 100 + [int]$matches[3])
    }
    return $fallback
}

# 版本 ID -> "x.y.z"
function Format-PhpVersion {
    param([int]$Id)
    return ('{0}.{1}.{2}' -f [int]($Id / 10000), [int](($Id / 100) % 100), [int]($Id % 100))
}

# 探测解释器版本 ID，探测失败返回 0。
# 用 `-r` 而不是 `-v`：只跑一行代码，不加载任何本项目文件 —— 版本过低时正是
# Composer 的 platform_check.php 会抛异常，用它探测根本拿不到版本号。
function Get-PhpVersionId {
    param([string]$Exe)

    # 本函数要容忍解释器报错退出，故临时放开全局 Stop 偏好
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $out = @()
    try {
        $out = @(& $Exe -r 'echo PHP_VERSION_ID;' 2>$null)
    } catch {
        $out = @()
    } finally {
        $ErrorActionPreference = $prev
    }

    foreach ($line in $out) {
        $s = "$line".Trim()
        if ($s -match '^\d+$') { return [int]$s }
    }
    return 0
}

function Resolve-PhpExe {
    param([string]$Override)

    $minId = Get-PhpMinVersionId
    $minTxt = Format-PhpVersion $minId

    # 1) 显式指定：只校验，不回落 —— 用户点名了这个二进制，静默换掉更危险
    if ($Override) {
        if (-not (Test-Path -LiteralPath $Override)) {
            throw "指定的 PHP 可执行文件不存在：$Override"
        }
        $exe = (Resolve-Path -LiteralPath $Override).Path
        $id = Get-PhpVersionId $exe
        if ($id -eq 0) {
            Write-Warn2 ("无法探测 PHP 版本，跳过校验：{0}" -f $exe)
            return $exe
        }
        if ($id -lt $minId) {
            throw ("指定的 PHP 版本过低：{0}（{1}），本项目要求 >= {2}。" -f `
                   $exe, (Format-PhpVersion $id), $minTxt)
        }
        $script:PhpVerTxt = Format-PhpVersion $id
        return $exe
    }

    # 2) 候选列表：PATH 优先（用户的显式选择），其次 phpstudy_pro 常见安装位置。
    #    必须遍历 PATH 的**每一个**目录，而不是只取 Get-Command 的首个命中 ——
    #    首个命中被判定版本过低时，PATH 里排在后面的合格版本应当先于外部目录兜底，
    #    否则会把用户显式配置好的解释器无视掉。
    $cands = @()
    foreach ($name in @('php.exe', 'php')) {
        $cmd = Get-Command $name -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($cmd -and $cmd.Source) { $cands += $cmd.Source }
    }
    foreach ($dir in @($env:PATH -split ';')) {
        if (-not $dir -or $dir.Trim() -eq '') { continue }
        $p = Join-Path ($dir.Trim().Trim('"')) 'php.exe'
        if (Test-Path -LiteralPath $p -PathType Leaf) { $cands += $p }
    }
    foreach ($pat in @('D:\phpstudy_pro\Extensions\php\*\php.exe',
                       'C:\phpstudy_pro\Extensions\php\*\php.exe')) {
        $cands += @(Get-ChildItem -Path $pat -ErrorAction SilentlyContinue |
                    Sort-Object FullName -Descending |
                    ForEach-Object { $_.FullName })
    }

    # 去重且保序（PATH 命中项与 glob 命中项常为同一个文件）
    $seen = @{}
    $uniq = @()
    foreach ($c in $cands) {
        $key = $c.ToLower()
        if ($seen.ContainsKey($key)) { continue }
        $seen[$key] = $true
        $uniq += $c
    }

    $rejected = @()
    foreach ($c in $uniq) {
        $id = Get-PhpVersionId $c
        if ($id -eq 0) {
            $rejected += ('{0}  [无法探测版本]' -f $c)
            continue
        }
        if ($id -ge $minId) {
            if ($rejected.Count -gt 0) {
                Write-Warn2 ('已跳过不满足版本要求的 PHP：' + ($rejected -join ' | '))
            }
            $script:PhpVerTxt = Format-PhpVersion $id
            return $c
        }
        $rejected += ('{0}  [{1}]' -f $c, (Format-PhpVersion $id))
    }

    if ($rejected.Count -gt 0) {
        Write-Err ("以下 PHP 解释器均低于要求的 >= {0}：" -f $minTxt)
        foreach ($r in $rejected) {
            Write-Host ('        ' + $r) -ForegroundColor DarkGray
        }
        throw '没有可用的 PHP 解释器。请安装符合版本要求的 PHP，或用 -PhpPath 指定绝对路径。'
    }
    throw '未找到 php.exe。请把 PHP 加入 PATH，或用 -PhpPath 指定绝对路径。'
}

function Test-Preflight {
    if (-not (Test-Path -LiteralPath (Join-Path $Root 'vendor\autoload.php'))) {
        Write-Err '依赖未安装，请先执行：composer install'
        return $false
    }
    return $true
}

# ---------------------------------------------------------------------------
# 5. 进程查询
# ---------------------------------------------------------------------------
$script:ProcCache = $null

# 取全部 php.exe 进程（含命令行）。Get-CimInstance 一次约百毫秒，故做进程内缓存
function Get-PhpProcesses {
    param([switch]$Refresh)

    if ($null -ne $script:ProcCache -and -not $Refresh) { return $script:ProcCache }

    $list = @()
    try {
        $list = @(Get-CimInstance -ClassName Win32_Process -Filter "Name = 'php.exe'" -ErrorAction Stop)
    } catch {
        # 退化为按进程名匹配：拿不到命令行，只能识别「有 php 在跑」
        foreach ($p in @(Get-Process -Name php -ErrorAction SilentlyContinue)) {
            $list += [pscustomobject]@{ ProcessId = $p.Id; CommandLine = '' }
        }
    }
    $script:ProcCache = $list
    return $list
}

# 反查某角色对应的 php.exe PID 列表（命令行同时含 start.php 与 --role=<角色>）
function Get-RoleIds {
    param([string]$Role)
    $pattern = '--role=' + [regex]::Escape($Role) + '(\s|$)'
    $ids = @()
    foreach ($proc in (Get-PhpProcesses -Refresh)) {
        $cl = $proc.CommandLine
        if (-not $cl) { continue }
        if ($cl -match 'start\.php' -and $cl -match $pattern) {
            $ids += [int]$proc.ProcessId
        }
    }
    return @($ids)
}

function Get-RolePidFile {
    param([string]$Role)
    return (Join-Path $PidDir ('win_{0}.pid' -f $Role))
}

function Get-ProcessMem {
    param([int]$Id)
    $p = Get-Process -Id $Id -ErrorAction SilentlyContinue
    if (-not $p) { return '-' }
    return ('{0:N1} MB' -f ($p.WorkingSet64 / 1MB))
}

function Get-ProcessUptime {
    param([int]$Id)
    $p = Get-Process -Id $Id -ErrorAction SilentlyContinue
    if (-not $p) { return '-' }
    $span = (Get-Date) - $p.StartTime
    return ('{0:00}:{1:00}:{2:00}' -f [int]$span.TotalHours, $span.Minutes, $span.Seconds)
}

function Test-PortListening {
    param([int]$Port, [switch]$Udp)

    if (Get-Command Get-NetTCPConnection -ErrorAction SilentlyContinue) {
        if ($Udp) {
            return (@(Get-NetUDPEndpoint -LocalPort $Port -ErrorAction SilentlyContinue).Count -gt 0)
        }
        return (@(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue).Count -gt 0)
    }

    # 旧系统兜底：解析 netstat
    if ($Udp) {
        $lines = @(& netstat -ano -p UDP 2>$null)
        return [bool](@($lines | Where-Object { $_ -match ('^\s*UDP\s+\S+:' + $Port + '\s') }).Count -gt 0)
    }
    $lines = @(& netstat -ano -p TCP 2>$null)
    return [bool](@($lines | Where-Object { $_ -match ('^\s*TCP\s+\S+:' + $Port + '\s+\S+\s+LISTENING') }).Count -gt 0)
}

# ---------------------------------------------------------------------------
# 6. 启动 / 停止
# ---------------------------------------------------------------------------
# 新开控制台会退回系统默认代码页（简体中文 Windows 为 936），必须在窗口内
# 先 chcp 65001，否则 php 输出的中文在该窗口里依然是乱码。
# 用 cmd /k 而非 /c：php 若启动即失败，窗口不会瞬间关闭，报错可见。
function Start-OneRole {
    param([string]$Role)

    if ((Get-RoleIds $Role).Count -gt 0) {
        return [pscustomobject]@{ Ok = $true; Skipped = $true }
    }

    $inner = 'chcp 65001 >nul && "' + $script:PhpExe + '" start.php start --role=' + $Role
    try {
        $proc = Start-Process -FilePath $env:ComSpec `
                              -ArgumentList @('/k', $inner) `
                              -WorkingDirectory $Root -PassThru -ErrorAction Stop
    } catch {
        Write-Err ('  ' + (Format-Pad $Role $COL_ROLE) + '启动失败：' + $_.Exception.Message)
        return [pscustomobject]@{ Ok = $false; Skipped = $false }
    }

    # 记录的是承载窗口（cmd.exe）的 PID，仅用于停止时连带关闭窗口；
    # 角色存活的权威判据始终是命令行反查 php.exe。
    if (-not (Test-Path -LiteralPath $PidDir)) {
        [void](New-Item -ItemType Directory -Path $PidDir -Force)
    }
    Set-Content -LiteralPath (Get-RolePidFile $Role) -Value $proc.Id -Encoding ASCII

    return [pscustomobject]@{ Ok = (Wait-RoleReady -Role $Role -TimeoutSec 25); Skipped = $false }
}

function Wait-RoleReady {
    param([string]$Role, [int]$TimeoutSec = 25)

    $ep = Get-ListenEndpoint $Role
    $deadline = (Get-Date).AddSeconds($TimeoutSec)
    $seen = 0
    $miss = 0
    $tick = 0

    while ((Get-Date) -lt $deadline) {
        Start-Sleep -Milliseconds 500
        $tick++

        # 命令行反查较慢，每 4 个轮次（约 2s）做一次存活确认
        if (($tick % 4) -eq 1) {
            if ((Get-RoleIds $Role).Count -gt 0) { $seen++; $miss = 0 }
            elseif ($seen -gt 0) { $miss++ }
            if ($miss -ge 2) { return $false }   # 起来过又消失 → 启动失败
        }

        if ($null -ne $ep) {
            if (Test-PortListening -Port $ep.Port -Udp:($ep.Scheme -eq 'udp')) { return $true }
        } elseif ($seen -ge 2) {
            return $true   # 无监听端口的角色（business），只能判进程存活
        }
    }
    return $false
}

function Stop-OneRole {
    param([string]$Role, [switch]$Quiet)

    $ids = Get-RoleIds $Role
    $winPid = 0
    $pidFile = Get-RolePidFile $Role
    if (Test-Path -LiteralPath $pidFile) {
        $txt = (Get-Content -LiteralPath $pidFile -Raw -ErrorAction SilentlyContinue)
        if ($txt) { [void][int]::TryParse($txt.Trim(), [ref]$winPid) }
    }

    if ($ids.Count -eq 0 -and $winPid -le 0) {
        if (-not $Quiet) { Write-Host ('  ' + (Format-Pad $Role $COL_ROLE) + '未在运行') -ForegroundColor DarkGray }
        return $true
    }

    foreach ($id in $ids) {
        try {
            Stop-Process -Id $id -Force -ErrorAction Stop
            Write-Host ('  ' + (Format-Pad $Role $COL_ROLE) + '已停止（PID ' + $id + '）')
        } catch {
            Write-Err ((Format-Pad $Role $COL_ROLE) + '停止 PID ' + $id + ' 失败：' + $_.Exception.Message)
            return $false
        }
    }

    # 连带关掉承载窗口，避免留下一排空命令行
    if ($winPid -gt 0) {
        $w = Get-Process -Id $winPid -ErrorAction SilentlyContinue
        if ($w -and $w.ProcessName -eq 'cmd') {
            try { Stop-Process -Id $winPid -Force -ErrorAction Stop } catch { }
        }
    }
    if (Test-Path -LiteralPath $pidFile) { Remove-Item -LiteralPath $pidFile -Force -ErrorAction SilentlyContinue }

    $script:ProcCache = $null
    return $true
}

# ---------------------------------------------------------------------------
# 7. 命令实现
# ---------------------------------------------------------------------------
function Invoke-Start {
    param([string[]]$Targets)

    $roles = $RoleOrder
    if ($Targets.Count -ge 1 -and $Targets[0]) {
        $first = $Targets[0].ToLower()
        if ($first -eq 'core') {
            $roles = $RoleCore
        } elseif ($RoleMeta.ContainsKey($first)) {
            $roles = @($first)
        } else {
            Write-Err ('非法目标：' + $Targets[0] + '（可选：core 或 ' + ($RoleOrder -join ' / ') + '）')
            return 1
        }
    }

    if (-not (Test-Preflight)) { return 1 }

    # 先跑一次环境自检：失败就不逐个开窗口，避免留下 6 个闪退的窗口难排查
    Write-Step '执行环境自检'
    & $script:PhpExe (Join-Path $Root 'start.php') check | Out-Host
    if ($LASTEXITCODE -ne 0) {
        Write-Err '环境自检未通过，启动终止。'
        return 1
    }

    # 被配置关闭的角色必须在**等待就绪之前**就摘出去：这类角色的进程会以
    # `@@@no worker inited@@@` 立即退出（workerman 在 Windows 单 Worker 模式下的行为），
    # 若照常进 Wait-RoleReady，只会白等满 25s 就绪超时，再把「配置关闭」误报成
    # 「启动失败」，最后以非 0 退出码收尾 —— 一个开关就让整条启动链失去意义。
    $states   = Get-RoleStates
    $pending  = @()
    $disabled = @()
    foreach ($role in $roles) {
        if ($null -ne $states -and $states.ContainsKey($role) -and -not $states[$role].Enabled) {
            $disabled += $role
        } else {
            $pending += $role
        }
    }

    Write-Host ''
    Write-Step ('启动 ' + $pending.Count + ' 个角色（每个角色一个窗口，按依赖顺序）')
    foreach ($role in $disabled) {
        Write-Host ('  ' + (Format-Pad $role $COL_ROLE) + '已禁用（' + $states[$role].Env +
                    '=false），跳过') -ForegroundColor DarkGray
    }
    Write-Host ''

    $failed = @()
    foreach ($role in $pending) {
        # 不用 "\r" 原地刷新进度：输出一旦被重定向到管道/文件，\r 不会覆盖而是拼接，
        # 反而把同一行重复打印出来。这里只打印最终结果，管道与终端下表现一致。
        $r = Start-OneRole -Role $role
        $ids = @(Get-RoleIds $role)
        if ($r.Skipped) {
            Write-Host ('  ' + (Format-Pad $role $COL_ROLE) + '已在运行（PID ' + ($ids -join ', ') + '）')
        } elseif ($r.Ok) {
            Write-Host ('  ' + (Format-Pad $role $COL_ROLE) + '启动成功（PID ' + ($ids -join ', ') + '）')
        } else {
            $failed += $role
            Write-Host ('  ' + (Format-Pad $role $COL_ROLE) + '启动失败，请检查新窗口中的报错') -ForegroundColor Red
        }
        Start-Sleep -Milliseconds 500
    }

    Write-Host ''
    if ($failed.Count -gt 0) {
        Write-Err ('以下角色未就绪：' + ($failed -join ' / '))
        Write-Host ('       排查：' + $script:PhpExe + ' start.php check  或  ' +
                    'bin\start.ps1 log error')
        return 1
    }

    # 全部被关闭：不是「启动成功但无事可做」，而是「请求与配置矛盾」，必须报错 ——
    # 静默返回 0 会让编排（CI / 上层脚本）以为服务已经起来了
    if ($pending.Count -eq 0) {
        Write-Err '没有任何角色需要启动：所请求的角色已全部被配置关闭。'
        Write-Host ('       相关开关（.env）：' +
                    (($disabled | ForEach-Object { $states[$_].Env }) -join ' / '))
        return 1
    }

    $summary = '全部 ' + $pending.Count + ' 个角色已就绪'
    if ($disabled.Count -gt 0) {
        $summary = $summary + '（' + $disabled.Count + ' 个角色已禁用，未启动）'
    }
    Write-Ok $summary
    if ($disabled -notcontains 'dashboard') {
        Write-Host ('       面板地址：' + (Get-EnvValue 'DASHBOARD_LISTEN'))
    }
    Write-Host ''

    # 各角色的启动横幅打印在各自的新窗口里，本窗口收不到；这里补一份汇总到主窗口
    # （环境 / 框架版本 / 服务清单 + 端口探测状态），使本窗口的观感与
    # `php start.php start` 前台启动一致。角色列表按本次实际启动范围传入。
    & $script:PhpExe (Join-Path $Root 'start.php') info ($roles -join ',') | Out-Host
    return 0
}

function Invoke-Stop {
    param([string[]]$Targets)

    $roles = $RoleOrder
    if ($Targets.Count -ge 1 -and $Targets[0]) {
        $first = $Targets[0].ToLower()
        if ($first -ne 'all') {
            if ($RoleMeta.ContainsKey($first)) { $roles = @($first) }
            else {
                Write-Err ('非法角色：' + $Targets[0])
                return 1
            }
        }
    }

    Write-Step '停止角色'
    Write-Host ''
    $rc = 0
    foreach ($role in $roles) {
        if (-not (Stop-OneRole -Role $role -Quiet)) { $rc = 1 }
    }
    Write-Host ''
    if ($rc -eq 0) { Write-Ok '服务已停止' } else { Write-Err '部分角色停止失败，请用 status 复查' }
    return $rc
}

function Invoke-Restart {
    param([string[]]$Targets)
    if ((Invoke-Stop $Targets) -ne 0) { return 1 }
    Start-Sleep -Seconds 2
    return (Invoke-Start $Targets)
}

# Windows 无 master 进程，做不到真正的平滑重载，只能重启承载业务的角色。
# 保留 register 不动，可少一轮全网重新注册。
function Invoke-Reload {
    Write-Warn2 'Windows 不支持平滑重载（无 master 进程），将重启业务相关角色。'
    Write-Warn2 'WebSocket 长连接会中断，客户端需自动重连。'
    Write-Host ''
    $null = Invoke-Stop @('business', 'gateway', 'udp', 'api', 'dashboard')
    Start-Sleep -Seconds 2
    return (Invoke-Start @('gateway', 'udp', 'business', 'api', 'dashboard'))
}

function Invoke-Status {
    Write-TableHead
    $states = Get-RoleStates
    $running = 0
    $disabledCount = 0
    foreach ($role in $RoleOrder) {
        $ep = Get-ListenEndpoint $role
        $addr = '-'
        if ($null -ne $ep) { $addr = $ep.Raw }

        $ids = Get-RoleIds $role
        $state = $null
        if ($null -ne $states -and $states.ContainsKey($role)) { $state = $states[$role] }

        if ($ids.Count -gt 0) {
            $pid0 = $ids[0]
            Write-TableRow $role '运行中' $addr ([string]$pid0) `
                           (Get-ProcessMem $pid0) (Get-ProcessUptime $pid0) $RoleMeta[$role].Desc
            $running++
        } elseif ($null -ne $state -and -not $state.Enabled) {
            # 「按配置就不会启动」与「启动过又崩了 / 被人为停掉」是两回事，
            # 状态列必须能分辨 —— 否则排查时会把配置关闭误读成进程异常
            $disabledCount++
            Write-TableRow $role '已禁用' $addr '-' '-' '-' `
                           ($RoleMeta[$role].Desc + '  关闭开关：' + $state.Env)
        } else {
            Write-TableRow $role '已停止' $addr '-' '-' '-' $RoleMeta[$role].Desc
        }
    }
    Write-Host ''
    Write-Host ('本机 PID：' + $PID + '   项目目录：' + $Root)
    $tail = '运行中 ' + $running + ' / ' + $RoleOrder.Count + ' 个角色'
    if ($disabledCount -gt 0) { $tail = $tail + '（另有 ' + $disabledCount + ' 个已禁用）' }
    Write-Host $tail
    return 0
}

function Invoke-Log {
    param([string[]]$Targets)

    $follow = $false
    $type = 'workerman'
    $lines = 60

    foreach ($arg in $Targets) {
        if ($arg -eq '-f' -or $arg -eq '--follow') { $follow = $true }
        elseif ($arg -match '^\d+$') { $lines = [int]$arg }
        elseif ($arg) { $type = $arg.ToLower() }
    }

    if ($type -eq 'workerman') {
        $file = Join-Path $LogDir 'workerman.log'
    } elseif ($type -eq 'stdout') {
        $file = Join-Path $LogDir 'stdout.log'
    } elseif (@('register', 'gateway', 'udp', 'business', 'api', 'dashboard', 'all', 'app', 'error') -contains $type) {
        $file = (Get-ChildItem -Path (Join-Path $LogDir ($type + '_*.log')) -ErrorAction SilentlyContinue |
                 Sort-Object LastWriteTime -Descending | Select-Object -First 1).FullName
    } else {
        Write-Err ('未知日志通道：' + $type + '（可选 workerman / stdout / 角色名 / error）')
        return 1
    }

    if (-not $file -or -not (Test-Path -LiteralPath $file)) {
        Write-Err ('未找到日志文件：' + $LogDir + '\' + $type + '*')
        return 1
    }

    Write-Step ('日志文件：' + $file)
    Write-Host ''
    # 必须走 Out-Host：本函数返回单值退出码，而外层是 exit (Invoke-Log ...)，
    # 该写法会把函数的「成功流」整体当成退出码收集掉，行内容会凭空消失。
    # Write-Host / Out-Host 写的是宿主流，不参与收集。
    if ($follow) {
        Get-Content -LiteralPath $file -Tail $lines -Encoding UTF8 -Wait | Out-Host
    } else {
        Get-Content -LiteralPath $file -Tail $lines -Encoding UTF8 | Out-Host
    }
    return 0
}

function Show-Help {
    Write-Host @'
GatewayPush 实时数据推送服务 —— Windows 服务管理脚本

用法：
  bin\start.bat <命令> [参数]
  bin\start.ps1 <命令> [参数] -PhpPath D:\php\php.exe

命令：
  start [core|角色]   启动。不带参数 = 全部 6 个角色，每个角色独立窗口；
                      core = 除监控面板外的 5 个核心角色；也可只启动单个角色。
                      被 .env 关闭的角色（如 WS_ENABLE=false）跳过并说明原因，
                      不阻塞后续角色的启动
  stop  [all|角色]    停止。不带参数 = 全部
  restart [all|角色]  重启（先停后启）
  reload              重启业务相关角色（Windows 无 master，做不到真正平滑）
  status              进程状态一览（PID / 内存 / 运行时长 / 监听地址）；
                      被配置关闭的角色标为「已禁用」并列出开关名
  log [-f] [通道] [行数]
                      查看日志。通道：workerman(默认) / stdout / error(跨角色错误汇总)
                      / 角色名(register gateway udp business api dashboard all app)
                      -f 持续跟随；行数默认 60
  check               仅执行环境自检，不启动服务
  info [角色列表]     打印启动信息：环境 / 框架版本 / 服务清单（含端口探测）
  env:init            生成 .env（首部署必执行，自动注入随机密钥）
  token <uid> [device] [ttl]      生成调试用 Token
  push <类型> <目标> [payload] [msg_id] [offline_mode]
                      提交一条定向推送任务
  help                显示本帮助

角色：register  gateway  udp  business  api  dashboard

重要：Windows 下请勿直接使用 start.php 的 stop / restart / reload / status
      —— workerman 在非 Unix 平台会跳过命令行解析，这些命令会「反向启动一个
      新实例」。请一律通过本脚本操作。

示例：
  bin\start.bat start                  # 启动全部 6 个角色
  bin\start.bat start core             # 只启动 5 个核心角色
  bin\start.bat start business         # 只启动业务进程（调试用）
  bin\start.bat status
  bin\start.bat log -f warn            # 跟随告警日志
'@
    return 0
}

# ---------------------------------------------------------------------------
# 8. 入口
# ---------------------------------------------------------------------------
$script:PhpVerTxt = ''
try {
    $script:PhpExe = Resolve-PhpExe -Override $PhpPath
} catch {
    Write-Err $_.Exception.Message
    exit 1
}
# 明确回显实际使用的解释器：本机装了 5 个 PHP，终端里显示清楚可以省掉一轮排查
Write-Host ('      PHP ' + $script:PhpVerTxt + '  ' + $script:PhpExe) -ForegroundColor DarkGray

$cmd = 'help'
if ($Command) { $cmd = $Command.ToLower() }

switch ($cmd) {
    'start'                      { exit (Invoke-Start $Arguments) }
    'stop'                       { exit (Invoke-Stop $Arguments) }
    'restart'                    { exit (Invoke-Restart $Arguments) }
    'reload'                     { exit (Invoke-Reload) }
    'status'                     { exit (Invoke-Status) }
    'svc-status'                 { exit (Invoke-Status) }
    'log'                        { exit (Invoke-Log $Arguments) }
    'logs'                       { exit (Invoke-Log $Arguments) }
    'tail'                       { exit (Invoke-Log $Arguments) }
    'check'                      { & $script:PhpExe (Join-Path $Root 'start.php') check; exit $LASTEXITCODE }
    'info'                       { & $script:PhpExe (Join-Path $Root 'start.php') info @Arguments; exit $LASTEXITCODE }
    'env:init'                   { & $script:PhpExe (Join-Path $Root 'start.php') env:init; exit $LASTEXITCODE }
    'token'                      { & $script:PhpExe (Join-Path $Root 'start.php') token @Arguments; exit $LASTEXITCODE }
    'push'                       { & $script:PhpExe (Join-Path $Root 'start.php') push @Arguments; exit $LASTEXITCODE }
    'help'                       { exit (Show-Help) }
    '-h'                         { exit (Show-Help) }
    '--help'                     { exit (Show-Help) }
    default {
        Write-Err ('未知命令：' + $cmd)
        Write-Host ''
        [void](Show-Help)
        exit 2
    }
}
