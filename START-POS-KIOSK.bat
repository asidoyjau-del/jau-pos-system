@echo off
title ProCast - Silent Printing Kiosk Mode
cd /d "%~dp0"
echo ========================================================
echo   PROCAST - Starting in Kiosk / Silent Print Mode
echo ========================================================
echo.

:: 1. Locate Chromium Browser (Google Chrome preferred, Microsoft Edge fallback)
set BROWSER_PATH=
set BROWSER_NAME=

if exist "C:\Program Files\Google\Chrome\Application\chrome.exe" (
    set BROWSER_PATH="C:\Program Files\Google\Chrome\Application\chrome.exe"
    set BROWSER_NAME=Google Chrome
)
if "%BROWSER_PATH%"=="" if exist "C:\Program Files (x86)\Google\Chrome\Application\chrome.exe" (
    set BROWSER_PATH="C:\Program Files (x86)\Google\Chrome\Application\chrome.exe"
    set BROWSER_NAME=Google Chrome
)
if "%BROWSER_PATH%"=="" if exist "%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe" (
    set BROWSER_PATH="%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe"
    set BROWSER_NAME=Google Chrome
)

:: Edge Fallback (built-in on Windows 10 & Windows 11)
if "%BROWSER_PATH%"=="" if exist "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe" (
    set BROWSER_PATH="C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
    set BROWSER_NAME=Microsoft Edge
)
if "%BROWSER_PATH%"=="" if exist "C:\Program Files\Microsoft\Edge\Application\msedge.exe" (
    set BROWSER_PATH="C:\Program Files\Microsoft\Edge\Application\msedge.exe"
    set BROWSER_NAME=Microsoft Edge
)
if "%BROWSER_PATH%"=="" if exist "%LOCALAPPDATA%\Microsoft\Edge\Application\msedge.exe" (
    set BROWSER_PATH="%LOCALAPPDATA%\Microsoft\Edge\Application\msedge.exe"
    set BROWSER_NAME=Microsoft Edge
)

if "%BROWSER_PATH%"=="" (
    echo [ERROR] Neither Google Chrome nor Microsoft Edge was found on this system.
    echo Please install Google Chrome or Microsoft Edge to use Auto-Print Kiosk mode.
    pause
    exit /b 1
)

:: 2. Check local server (XAMPP on port 80 or standalone PHP on port 8000)
set TARGET_URL=http://localhost/offline_POS-System/pos_system-main/Offline_Pos_System/index.php

powershell -NoProfile -Command "$t = New-Object System.Net.Sockets.TcpClient; try { $t.Connect('127.0.0.1', 80); exit 0 } catch { exit 1 }"
if %ERRORLEVEL% EQU 0 (
    echo [OK] XAMPP Apache detected on port 80.
    set TARGET_URL=http://localhost/offline_POS-System/pos_system-main/Offline_Pos_System/index.php
) else (
    powershell -NoProfile -Command "$t = New-Object System.Net.Sockets.TcpClient; try { $t.Connect('127.0.0.1', 8000); exit 0 } catch { exit 1 }"
    if %ERRORLEVEL% EQU 0 (
        echo [OK] POS local server detected on port 8000.
        set TARGET_URL=http://localhost:8000/index.php
    ) else (
        echo [INFO] Starting background PHP server on port 8000...
        start /b powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0start-local.ps1"
        timeout /t 2 /nobreak >nul
        set TARGET_URL=http://localhost:8000/index.php
    )
)

:: 3. Setup Dedicated POS Profile Directory
set "PROFILE_DIR=C:\POS-Profile"
mkdir "C:\POS-Profile" 2>nul
if not exist "C:\POS-Profile" (
    set "PROFILE_DIR=%LOCALAPPDATA%\POS-Profile"
)

:: 4. Start Native Print Agent in background (if not already running)
if exist "%~dp0start-print-agent-silent.vbs" (
    start "" wscript.exe "%~dp0start-print-agent-silent.vbs"
)

echo [OK] Launching POS via %BROWSER_NAME% with Silent Printing enabled...
start "" %BROWSER_PATH% --kiosk-printing --user-data-dir="%PROFILE_DIR%" --unsafely-treat-insecure-origin-as-secure=http://127.0.0.1:9100 --allow-running-insecure-content --app="%TARGET_URL%"
echo.
echo POS is now running with:
echo  - Silent direct thermal printing (no print preview dialog)
echo  - Automatic cash drawer trigger (via Xprinter XP-58)
echo  - Auto-closing receipt modal
echo.
