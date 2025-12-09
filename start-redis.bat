@echo off
echo Starting Redis Server...
cd /d "%~dp0redis-server"
start "Redis Server" redis-server.exe redis.windows.conf
echo Redis Server started!
echo You can close this window.
timeout /t 3
