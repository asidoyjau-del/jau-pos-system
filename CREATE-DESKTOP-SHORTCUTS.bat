@echo off
title ProCast - Create Desktop Shortcuts
cd /d "%~dp0"
echo ========================================================
echo   ProCast POS - Auto-Print Desktop Shortcut Creator
echo ========================================================
echo.

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
    "$ws = New-Object -ComObject WScript.Shell;" ^
    "$desktop = [System.Environment]::GetFolderPath('Desktop');" ^
    "$icon = '';" ^
    "if (Test-Path 'C:\Program Files\Google\Chrome\Application\chrome.exe') { $icon = 'C:\Program Files\Google\Chrome\Application\chrome.exe,0' }" ^
    "elseif (Test-Path 'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe') { $icon = 'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe,0' }" ^
    "elseif (Test-Path 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe') { $icon = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe,0' }" ^
    "elseif (Test-Path 'C:\Program Files\Microsoft\Edge\Application\msedge.exe') { $icon = 'C:\Program Files\Microsoft\Edge\Application\msedge.exe,0' };" ^
    "$sc1 = $ws.CreateShortcut((Join-Path $desktop 'ProCast POS (Local - Silent Auto-Print).lnk'));" ^
    "$sc1.TargetPath = (Join-Path '%~dp0' 'START-POS-LOCAL.bat');" ^
    "$sc1.WorkingDirectory = '%~dp0';" ^
    "$sc1.WindowStyle = 7;" ^
    "$sc1.Description = 'ProCast POS Local with 0-Click Auto Thermal Printing (No Ctrl+P)';" ^
    "if ($icon -ne '') { $sc1.IconLocation = $icon };" ^
    "$sc1.Save();" ^
    "$sc2 = $ws.CreateShortcut((Join-Path $desktop 'ProCast POS (Online Cloud - Silent Auto-Print).lnk'));" ^
    "$sc2.TargetPath = (Join-Path '%~dp0' 'START-POS-ONLINE.bat');" ^
    "$sc2.WorkingDirectory = '%~dp0';" ^
    "$sc2.WindowStyle = 7;" ^
    "$sc2.Description = 'ProCast POS Online Cloud with 0-Click Auto Thermal Printing (No Ctrl+P)';" ^
    "if ($icon -ne '') { $sc2.IconLocation = $icon };" ^
    "$sc2.Save();" ^
    "Write-Host '  [OK] Created ProCast POS (Local - Silent Auto-Print).lnk' -ForegroundColor Green;" ^
    "Write-Host '  [OK] Created ProCast POS (Online Cloud - Silent Auto-Print).lnk' -ForegroundColor Green;"

if %ERRORLEVEL% EQU 0 (
    echo.
    echo [SUCCESS] Both shortcuts created on your Desktop!
    echo.
    echo Launching POS through these shortcuts ensures:
    echo  - 0-Click silent auto-printing to Xprinter XP-58
    echo  - NO print preview dialogs
    echo  - NO Ctrl + P or Enter needed
) else (
    echo [ERROR] Failed to create shortcuts.
)

pause
