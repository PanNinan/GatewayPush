@echo off
rem ==========================================================================
rem  GatewayPush realtime push service - Windows launcher
rem
rem  This file is intentionally ASCII-ONLY. Do not add Chinese text here.
rem
rem  Why: CMD decodes a batch file line by line using the *current* code page,
rem  but advances its file pointer by bytes. Any multibyte (e.g. Chinese)
rem  content makes the two diverge: CMD resumes reading from the middle of a
rem  character and executes the fragments as commands. Verified on Windows 10
rem  this run - the raw output contained a truncated UTF-8 lead byte, proving
rem  the offset desync. All Chinese UI therefore lives in start.ps1, which is
rem  saved as UTF-8 *with BOM* so PowerShell 5.1 parses it correctly.
rem
rem  This launcher only does two things: switch the console code page to UTF-8,
rem  and forward the arguments to start.ps1 (which owns all process management).
rem
rem  Usage: bin\start.bat <command> [args]        (bin\start.bat help for list)
rem ==========================================================================

rem Capture the original code page BEFORE switching, so it can be restored on
rem exit and the caller's console is left untouched.
for /f "tokens=2 delims=:" %%a in ('chcp') do set "OLDCP=%%a"
set "OLDCP=%OLDCP: =%"

rem 65001 = UTF-8. Without this, Chinese written by php.exe renders as garbage
rem because the default console code page on zh-CN Windows is 936 (GBK).
chcp 65001 >nul 2>&1

setlocal enableextensions

set "SCRIPT_DIR=%~dp0"
set "PS1=%SCRIPT_DIR%start.ps1"
set "RC=1"

if not exist "%PS1%" (
    echo [ERROR] Not found: %PS1%
    goto :finish
)

where powershell >nul 2>&1
if errorlevel 1 (
    echo [ERROR] powershell.exe not found. Windows PowerShell 5.1+ is required.
    goto :finish
)

rem No arguments (typical double-click) -> show help and keep the window open.
rem Without pause the console closes instantly and nothing can be read.
if "%~1"=="" set "PAUSE_AT_END=1"

rem New role windows fall back to the system default code page, so start.ps1
rem wraps each role command in "cmd /k chcp 65001>nul && ...".
powershell -NoProfile -ExecutionPolicy Bypass -File "%PS1%" %*
set "RC=%ERRORLEVEL%"

:finish
if defined PAUSE_AT_END pause
if defined OLDCP chcp %OLDCP% >nul 2>&1
endlocal & exit /b %RC%
