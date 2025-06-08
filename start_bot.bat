@echo off
chcp 65001 >nul
setlocal EnableDelayedExpansion

@echo off
setlocal

echo ============================================
echo 🚀 Запуск Docker-контейнера с Telegram-ботом
echo ============================================

REM Запускаем docker-compose (предполагаем, что файл называется docker-compose.yml)
docker-compose up -d

if errorlevel 1 (
    echo ❌ Ошибка при запуске docker-compose.
    pause
    exit /b 1
)

echo ⏳ Ожидаем 5 секунд, чтобы контейнер успел подняться...
timeout /t 5 /nobreak >nul

echo ============================================
echo 🌐 Настройка ngrok и Telegram webhook
echo ============================================
call set_ngrok_and_webhook.bat

echo ============================================
echo ✅ Всё готово!
echo Контейнер работает, вебхук установлен.
echo ============================================
pause
