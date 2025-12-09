@echo off
echo Stopping Redis Server...
cd /d "%~dp0redis-server"
redis-cli.exe shutdown
echo Redis Server stopped!
timeout /t 2
