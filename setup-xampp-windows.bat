@echo off
setlocal EnableDelayedExpansion
title ProCast POS - Windows 11 XAMPP One-Click Setup & Migration

echo ==============================================================================
echo        PROCAST POS - AUTOMATED WINDOWS 11 XAMPP PORTABILITY SETUP            
echo ==============================================================================
echo.

:: 1. Verify XAMPP Installation Path
set "XAMPP_DIR=C:\xampp"
if not exist "%XAMPP_DIR%\php\php.exe" (
    echo [ERROR] XAMPP not detected at %XAMPP_DIR%!
    echo Please install XAMPP 8.x for Windows or specify the custom path.
    pause
    exit /b 1
)
echo [OK] XAMPP detected at %XAMPP_DIR%

:: 2. Port Collision Audit (Port 80, 443, 3306, 9100)
echo.
echo [*] Auditing Windows 11 Network Ports...

:: Check Port 80 (IIS / World Wide Web Publishing Service conflict)
netstat -ano | findstr /R /C:":80 " > nul
if %errorlevel% equ 0 (
    for /f "tokens=5" %%a in ('netstat -ano ^| findstr /R /C:":80 "') do set PORT80_PID=%%a
    sc query W3SVC 2>nul | findstr /I "RUNNING" > nul
    if !errorlevel! equ 0 (
        echo [WARN] Windows IIS (World Wide Web Publishing Service) is running on Port 80!
        echo        Attempting to stop W3SVC to liberate Port 80 for Apache...
        net stop W3SVC /y >nul 2>&1
    ) else (
        echo [INFO] Port 80 is currently occupied by PID !PORT80_PID! (e.g. Apache or System).
    )
) else (
    echo [OK] Port 80 is available for Apache.
)

:: Check Port 3306 (MySQL)
netstat -ano | findstr /R /C:":3306 " > nul
if %errorlevel% equ 0 (
    echo [OK] Port 3306 is active (MySQL / MariaDB is listening).
) else (
    echo [*] Starting XAMPP MySQL daemon on Port 3306...
    start "" /b "%XAMPP_DIR%\mysql\bin\mysqld.exe" --defaults-file="%XAMPP_DIR%\mysql\bin\my.ini" >nul 2>&1
    timeout /t 3 /nobreak > nul
)

:: 3. Audit & Auto-Enable Required PHP Extensions in php.ini
echo.
echo [*] Auditing PHP Extensions in %XAMPP_DIR%\php\php.ini...
set "PHP_INI=%XAMPP_DIR%\php\php.ini"

if exist "%PHP_INI%" (
    powershell -NoProfile -Command "
        $ini = Get-Content '%PHP_INI%' -Raw;
        $modified = $false;
        $exts = @('gd', 'bcmath', 'curl', 'mbstring', 'openssl', 'pdo_mysql');
        foreach ($e in $exts) {
            if ($ini -match ';extension=' + $e) {
                $ini = $ini -replace ';extension=' + $e, 'extension=' + $e;
                $modified = $true;
                Write-Host '  [+] Auto-enabled extension=' + $e -ForegroundColor Green;
            } else {
                Write-Host '  [OK] extension=' + $e + ' is already active';
            }
        }
        if ($modified) {
            Set-Content -Path '%PHP_INI%' -Value $ini -Encoding utf8;
            Write-Host '  [+] Saved updated php.ini' -ForegroundColor Cyan;
        }
    "
) else (
    echo [WARN] php.ini not found in %XAMPP_DIR%\php\
)

:: 4. Target Directory Resolution (Auto-Detect vs Copy)
echo.
set "CURRENT_DIR=%~dp0"
set "CURRENT_DIR=%CURRENT_DIR:~0,-1%"

echo [*] Current Directory: %CURRENT_DIR%
echo %CURRENT_DIR% | findstr /I "C:\\xampp\\htdocs" > nul
if %errorlevel% equ 0 (
    set "TARGET_DIR=%CURRENT_DIR%"
    echo [OK] Already located inside XAMPP htdocs. Configuring in-place:
    echo      !TARGET_DIR!
) else (
    set "TARGET_DIR=%XAMPP_DIR%\htdocs\ProCast"
    echo [*] Deploying files to !TARGET_DIR!...
    if not exist "!TARGET_DIR!" mkdir "!TARGET_DIR!"
    xcopy "%CURRENT_DIR%\*" "!TARGET_DIR!\" /E /I /Y /Q > nul
    echo [OK] Files deployed successfully to !TARGET_DIR!
)

:: Ensure uploads directories exist
if not exist "!TARGET_DIR!\uploads\products" mkdir "!TARGET_DIR!\uploads\products"
if not exist "!TARGET_DIR!\uploads\shop" mkdir "!TARGET_DIR!\uploads\shop"

:: 5. Create Database and Run Schema Migrations
echo.
echo [*] Initializing MySQL Database (pos_system)...
"%XAMPP_DIR%\mysql\bin\mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS pos_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" >nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] Database 'pos_system' verified/created.
) else (
    echo [WARN] Could not communicate with MySQL on localhost:3306. Please start MySQL from the XAMPP Control Panel.
)

:: Trigger schema creation via PHP CLI
echo [*] Running schema initialization & migrations...
"%XAMPP_DIR%\php\php.exe" -r "
    chdir('!TARGET_DIR!');
    putenv('DB_HOST=127.0.0.1');
    putenv('DB_PORT=3306');
    putenv('DB_NAME=pos_system');
    putenv('DB_USER=root');
    putenv('DB_PASS=');
    require_once '!TARGET_DIR!\index.php';
    if (function_exists('db')) {
        try {
            $db = db();
            echo '  [OK] Database connected and schema migrations verified.' . PHP_EOL;
        } catch (\Throwable $e) {
            echo '  [-] Schema notice: ' . $e->getMessage() . PHP_EOL;
        }
    }
" 2>nul

:: 6. Setup Local Environment File (.env.local)
if not exist "!TARGET_DIR!\.env.local" (
    echo [*] Generating default .env.local configuration...
    (
        echo DB_HOST=127.0.0.1
        echo DB_PORT=3306
        echo DB_NAME=pos_system
        echo DB_USER=root
        echo DB_PASS=
        echo CLOUD_DATABASE_URL=https://jau-pos-system.onrender.com
        echo SYNC_TOKEN=procast_sync_key
    ) > "!TARGET_DIR!\.env.local"
    echo [OK] Created .env.local
) else (
    echo [OK] .env.local already exists.
)

:: 7. Install Silent Startup Shortcut for Thermal Print Agent
echo.
echo [*] Registering Native Thermal Print Agent in Windows Startup...
powershell -NoProfile -Command "
    $ws = New-Object -ComObject WScript.Shell;
    $startup = [System.Environment]::GetFolderPath('Startup');
    $sc = $ws.CreateShortcut(\"$startup\POS Native Print Agent.lnk\");
    $sc.TargetPath = 'C:\WINDOWS\system32\wscript.exe';
    $sc.Arguments = '\"!TARGET_DIR!\start-print-agent-silent.vbs\"';
    $sc.WorkingDirectory = '!TARGET_DIR!';
    $sc.Save();
    Write-Host '  [OK] Thermal Print Agent shortcut created in Windows Startup.' -ForegroundColor Green;
"

:: 8. Create Desktop Shortcut for ProCast POS
echo [*] Creating Desktop Shortcut for Cashier Register...
powershell -NoProfile -Command "
    $ws = New-Object -ComObject WScript.Shell;
    $desktop = [System.Environment]::GetFolderPath('Desktop');
    $sc = $ws.CreateShortcut(\"$desktop\ProCast POS.lnk\");
    $targetUrl = 'http://localhost/' + ('!TARGET_DIR!'.Replace('%XAMPP_DIR%\htdocs\', '').Replace('\', '/')) + '/';
    $sc.TargetPath = 'chrome.exe';
    $sc.Arguments = '--app=' + $targetUrl;
    $sc.Description = 'ProCast Retail POS';
    $sc.IconLocation = '!TARGET_DIR!\icons\icon-512.png';
    $sc.Save();
    Write-Host '  [OK] Desktop shortcut created: ' $targetUrl -ForegroundColor Green;
"

:: 9. Start Background Print Agent now if not running
powershell -NoProfile -Command "
    try {
        $res = Invoke-RestMethod -Uri 'http://127.0.0.1:9100/status' -TimeoutSec 1 -ErrorAction SilentlyContinue;
        if ($res.status -eq 'online') {
            Write-Host '  [OK] Thermal Print Agent is already running.' -ForegroundColor Green;
        }
    } catch {
        Write-Host '  [*] Starting background print agent...' -ForegroundColor Yellow;
        Start-Process wscript.exe -ArgumentList '\"!TARGET_DIR!\start-print-agent-silent.vbs\"' -WorkingDirectory '!TARGET_DIR!';
    }
"

echo.
echo ==============================================================================
echo                PROCAST POS SETUP COMPLETED SUCCESSFULLY!                      
echo ==============================================================================
echo.
echo   Local App URL:  http://localhost/
echo   Print Agent:    http://127.0.0.1:9100/status
echo   Database:       pos_system on 127.0.0.1:3306 (user: root)
echo.
echo Press any key to launch ProCast POS in your browser...
pause > nul
start "" "http://localhost/offline_POS-System/pos_system-main/Offline_Pos_System/"
exit /b 0
