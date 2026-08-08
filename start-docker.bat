@echo off
chcp 65001 >nul
setlocal EnableDelayedExpansion

set "CFG=config.txt"
set "TELEGRAM_BOT_TOKEN="
set "NGROK_AUTHTOKEN="
set "GEMINI_API_KEY="

for /f "usebackq tokens=1,* delims==" %%A in ("%CFG%") do (
  if /i "%%A"=="TELEGRAM_BOT_TOKEN" set "TELEGRAM_BOT_TOKEN=%%B"
  if /i "%%A"=="NGROK_AUTHTOKEN"   set "NGROK_AUTHTOKEN=%%B"
  if /i "%%A"=="GEMINI_API_KEY"    set "GEMINI_API_KEY=%%B"
)

if not defined TELEGRAM_BOT_TOKEN (
  echo ERROR: TELEGRAM_BOT_TOKEN не найден
  pause
  exit /b
)
if not defined NGROK_AUTHTOKEN (
  echo ERROR: NGROK_AUTHTOKEN не найден
  pause
  exit /b
)
if not defined GEMINI_API_KEY (
  echo ERROR: GEMINI_API_KEY не найден
  pause
  exit /b
)

> .env (
  echo TELEGRAM_BOT_TOKEN=%TELEGRAM_BOT_TOKEN%
  echo NGROK_AUTHTOKEN=%NGROK_AUTHTOKEN%
  echo GEMINI_API_KEY=%GEMINI_API_KEY%
)

echo .env создан:
type .env
echo.

echo Запуск Docker Compose...
docker-compose up --build -d

pause
