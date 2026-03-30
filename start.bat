@echo off
cd /d "%~dp0"

echo Starter Bambu Dashboard...
powershell -WindowStyle Hidden -Command "Start-Process py -ArgumentList 'app.py' -WorkingDirectory '%~dp0'"

echo Venter pa server...
timeout /t 5 /nobreak >nul

start "" "http://localhost:5000"
