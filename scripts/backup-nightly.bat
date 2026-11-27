@echo off
setlocal
cd /d "%~dp0.."

if not exist "storage\logs" (
    mkdir "storage\logs"
)

echo [%DATE% %TIME%] Starting nightly database backup... >> "storage\logs\backup.log"
php artisan temupulang:backup >> "storage\logs\backup.log" 2>&1
set EXIT_CODE=%ERRORLEVEL%
echo [%DATE% %TIME%] Nightly backup finished with exit code %EXIT_CODE%. >> "storage\logs\backup.log"

exit /b %EXIT_CODE%
