@echo off
cd /d "%~dp0"

echo Starter Bambu Dashboard...
start "" "app.py"

echo Venter pa server...
timeout /t 5 /nobreak >nul

start "" "chrome.exe" --kiosk "http://localhost:5000"
