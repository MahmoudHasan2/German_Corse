@echo off
rem Stops the app and the database started by "Start German Coach.bat" (only this copy's ports).
set "DBBIN=%~dp0tools\mariadb\bin"
for /f "tokens=5" %%p in ('netstat -ano ^| findstr "127.0.0.1:8017" ^| findstr "LISTENING"') do taskkill /F /PID %%p >nul 2>&1
"%DBBIN%\mariadb-admin.exe" -uroot -pcoachlocal -P3317 -h127.0.0.1 shutdown >nul 2>&1
echo German Coach stopped.
timeout /t 2 /nobreak >nul
