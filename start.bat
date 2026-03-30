@echo off
cd /d "%~dp0"

echo Starter Bambu Dashboard...
start "" /B py app.py

echo Venter på server...
timeout /t 4 /nobreak >nul

start "" "http://localhost:5000"
