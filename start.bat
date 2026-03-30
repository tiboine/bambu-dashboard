@echo off
cd /d "%~dp0"

echo Starter Bambu Dashboard...
start "Bambu Dashboard" /min C:\Windows\py.exe app.py

echo Venter pa server...
timeout /t 5 /nobreak >nul

start "" "http://localhost:5000"
