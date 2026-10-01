@echo off
title ProCast - Create Desktop Shortcut
cd /d "%~dp0"
echo ========================================================
echo   ProCast POS - Auto Print Desktop Shortcut Creator
echo ========================================================
echo.

set "TARGET_BAT=%~dp0START-POS-ONLINE.bat"
if not exist "%TARGET_BAT%" (
    echo [ERROR] Could not find START-POS-ONLINE.bat in %~dp0
    pause
    exit /b 1
)

echo Creating desktop shortcut for:
echo %TARGET_BAT%
echo.

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
    "$ws = New-Object -ComObject WScript.Shell;" ^
    "$desktop = [System.Environment]::GetFolderPath('Desktop');" ^
    "$sc = $ws.CreateShortcut((Join-Path $desktop 'ProCast POS - Auto Print.lnk'));" ^
    "$sc.TargetPath = '%TARGET_BAT%';" ^
    "$sc.WorkingDirectory = '%~dp0';" ^
    "$sc.WindowStyle = 7;" ^
    "$sc.Description = 'ProCast POS Online with 0-Click Auto Thermal Printing';" ^
    "$icon = '';" ^
    "if (Test-Path 'C:\Program Files\Google\Chrome\Application\chrome.exe') { $icon = 'C:\Program Files\Google\Chrome\Application\chrome.exe,0' }" ^
    "elseif (Test-Path 'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe') { $icon = 'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe,0' }" ^
    "elseif (Test-Path 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe') { $icon = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe,0' }" ^
    "elseif (Test-Path 'C:\Program Files\Microsoft\Edge\Application\msedge.exe') { $icon = 'C:\Program Files\Microsoft\Edge\Application\msedge.exe,0' };" ^
    "if ($icon -ne '') { $sc.IconLocation = $icon };" ^
    "$sc.Save();"

if %ERRORLEVEL% EQU 0 (
    echo [SUCCESS] Shortcut 'ProCast POS - Auto Print' created on your Desktop!
    echo.
    echo Double-click the desktop shortcut anytime to run POS with:
    echo  - 0-Click silent auto-printing to your thermal printer
    echo  - Automatic connection to local thermal print agent
    echo.
) else (
    echo [ERROR] Failed to create desktop shortcut.
)

pause
