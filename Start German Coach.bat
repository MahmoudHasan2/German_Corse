@echo off
chcp 65001 >nul
setlocal
rem German Coach - portable launcher. Nothing needs to be installed: PHP and the
rem database are in the "tools" folder. Learning data is kept on this computer in
rem %LOCALAPPDATA%\GermanCoach (not inside this folder).
title German Coach
set "ROOT=%~dp0"
set "TOOLS=%ROOT%tools"
set "APP=%ROOT%app"
set "DATA=%LOCALAPPDATA%\GermanCoach\db"
set "PHP=%TOOLS%\php\php.exe"
set "DBBIN=%TOOLS%\mariadb\bin"
set PHPARGS=-c "%TOOLS%\php\php.ini" -d extension_dir="%TOOLS%\php\ext" -d curl.cainfo="%TOOLS%\php\extras\ssl\cacert.pem" -d openssl.cafile="%TOOLS%\php\extras\ssl\cacert.pem"
cd /d "%APP%"

if not exist "%APP%\.env" copy /y "%APP%\.env.portable" "%APP%\.env" >nul

netstat -ano | findstr "127.0.0.1:8017" | findstr "LISTENING" >nul
if not errorlevel 1 goto open

if not exist "%DATA%\mysql" (
    if exist "%DATA%" rd /s /q "%DATA%"
    echo First start: preparing the database, please wait...
    if not exist "%LOCALAPPDATA%\GermanCoach" mkdir "%LOCALAPPDATA%\GermanCoach"
    "%DBBIN%\mariadb-install-db.exe" --datadir="%DATA%" --password=coachlocal --port=3317 >"%LOCALAPPDATA%\GermanCoach\install-db.log" 2>&1
    if not exist "%DATA%\mysql" (
        if exist "%DATA%" rd /s /q "%DATA%"
        echo.
        echo The database could not be created. Details: %LOCALAPPDATA%\GermanCoach\install-db.log
        pause
        exit /b 1
    )
)

netstat -ano | findstr "127.0.0.1:3317" | findstr "LISTENING" >nul
if errorlevel 1 (
    echo Starting database...
    start "German Coach DB" /min "%DBBIN%\mariadbd.exe" --no-defaults --datadir="%DATA%" --port=3317 --bind-address=127.0.0.1 --console
)
for /l %%i in (1,1,30) do (
    "%DBBIN%\mariadb-admin.exe" -uroot -pcoachlocal -P3317 -h127.0.0.1 ping >nul 2>&1 && goto dbready
    timeout /t 1 /nobreak >nul
)
echo.
echo The database did not start. Close all German Coach windows and try again.
pause
exit /b 1
:dbready

echo Preparing lessons...
"%DBBIN%\mariadb.exe" -uroot -pcoachlocal -P3317 -h127.0.0.1 -e "CREATE DATABASE IF NOT EXISTS german_coach CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"%PHP%" %PHPARGS% bin\console.php migrate || goto failed
"%PHP%" %PHPARGS% bin\console.php seed || goto failed

echo Starting the app...
start "German Coach Server" /min "%PHP%" %PHPARGS% -S 127.0.0.1:8017 -t public public\index.php
timeout /t 2 /nobreak >nul

:open
start "" http://127.0.0.1:8017/
exit /b 0

:failed
echo.
echo Something went wrong while preparing the lessons (see the messages above).
pause
exit /b 1
