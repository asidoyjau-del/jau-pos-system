@echo off
title ProCast - Auto Print (Render Online)
cd /d "%~dp0"

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

:: 2. Setup Dedicated POS Profile Directory (avoids conflicts with regular browser sessions)
set "PROFILE_DIR=C:\POS-Profile"
mkdir "C:\POS-Profile" 2>nul
if not exist "C:\POS-Profile" (
    set "PROFILE_DIR=%LOCALAPPDATA%\POS-Profile"
)

:: 3. Start Native Print Agent in background (if not already running)
if exist "%~dp0start-print-agent-silent.vbs" (
    start "" wscript.exe "%~dp0start-print-agent-silent.vbs"
)

:: 4. Launch POS in Standalone App Window with Silent Thermal Kiosk Printing
:: Flags:
:: --kiosk-printing: Prints directly to default thermal printer without print preview dialog
:: --unsafely-treat-insecure-origin-as-secure: Allows HTTPS cloud POS to talk to local HTTP print agent on 127.0.0.1:9100
:: --allow-running-insecure-content: Prevents mixed-content blocking of port 9100
:: --app: Launches as a clean standalone desktop PWA window
start "" %BROWSER_PATH% --kiosk-printing --user-data-dir="%PROFILE_DIR%" --unsafely-treat-insecure-origin-as-secure=http://127.0.0.1:9100 --allow-running-insecure-content --app="https://pos-system-so8z.onrender.com/?page=dashboard"

exit
