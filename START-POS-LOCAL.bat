@echo off
title ProCast POS - Local Auto-Print Launcher
cd /d "%~dp0"

:: 1. Locate Google Chrome or Microsoft Edge
set "BROWSER_PATH="
set "BROWSER_NAME="

if exist "C:\Program Files\Google\Chrome\Application\chrome.exe" (
    set "BROWSER_PATH=C:\Program Files\Google\Chrome\Application\chrome.exe"
    set "BROWSER_NAME=Google Chrome"
)
if not defined BROWSER_PATH if exist "C:\Program Files (x86)\Google\Chrome\Application\chrome.exe" (
    set "BROWSER_PATH=C:\Program Files (x86)\Google\Chrome\Application\chrome.exe"
    set "BROWSER_NAME=Google Chrome"
)
if not defined BROWSER_PATH if exist "%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe" (
    set "BROWSER_PATH=%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe"
    set "BROWSER_NAME=Google Chrome"
)

:: Edge Fallback
if not defined BROWSER_PATH if exist "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe" (
    set "BROWSER_PATH=C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
    set "BROWSER_NAME=Microsoft Edge"
)
if not defined BROWSER_PATH if exist "C:\Program Files\Microsoft\Edge\Application\msedge.exe" (
    set "BROWSER_PATH=C:\Program Files\Microsoft\Edge\Application\msedge.exe"
    set "BROWSER_NAME=Microsoft Edge"
)
if not defined BROWSER_PATH if exist "%LOCALAPPDATA%\Microsoft\Edge\Application\msedge.exe" (
    set "BROWSER_PATH=%LOCALAPPDATA%\Microsoft\Edge\Application\msedge.exe"
    set "BROWSER_NAME=Microsoft Edge"
)

if not defined BROWSER_PATH (
    echo [ERROR] Neither Google Chrome nor Microsoft Edge was found.
    pause
    exit /b 1
)

:: 2. Ensure Apache and MySQL are running
powershell -NoProfile -Command "$t = New-Object System.Net.Sockets.TcpClient; try { $t.Connect('127.0.0.1', 80); exit 0 } catch { exit 1 }"
if %ERRORLEVEL% NEQ 0 (
    if exist "C:\xampp\apache\bin\httpd.exe" (
        start "" "C:\xampp\apache\bin\httpd.exe"
    )
)

powershell -NoProfile -Command "$t = New-Object System.Net.Sockets.TcpClient; try { $t.Connect('127.0.0.1', 3306); exit 0 } catch { exit 1 }"
if %ERRORLEVEL% NEQ 0 (
    if exist "C:\xampp\mysql\bin\mysqld.exe" (
        start "" "C:\xampp\mysql\bin\mysqld.exe" --defaults-file="C:\xampp\mysql\bin\my.ini"
    )
)

:: 3. Start Native Print Agent in background (Port 9100)
if exist "%~dp0start-print-agent-silent.vbs" (
    start "" wscript.exe "%~dp0start-print-agent-silent.vbs"
)

:: 4. Setup Dedicated POS Profile Directory
set "PROFILE_DIR=C:\POS-Profile"
if not exist "C:\POS-Profile" (
    mkdir "C:\POS-Profile" 2>nul
)
if not exist "C:\POS-Profile" (
    set "PROFILE_DIR=%LOCALAPPDATA%\POS-Profile"
)

:: 5. Determine Local URL
set "TARGET_URL=http://localhost/offline_POS-System/pos_system-main/Offline_Pos_System/?page=dashboard"

:: 6. Launch in Standalone App Window with 0-Click Silent Kiosk Printing
start "" "%BROWSER_PATH%" --kiosk-printing --user-data-dir="%PROFILE_DIR%" --unsafely-treat-insecure-origin-as-secure=http://127.0.0.1:9100,http://localhost:9100 --allow-running-insecure-content --disable-features=Translate --app="%TARGET_URL%"

exit /b 0
