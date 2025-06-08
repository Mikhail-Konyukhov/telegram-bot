@echo off
chcp 65001 >nul
setlocal EnableDelayedExpansion

REM ======================================================
REM 1) Читаем TELEGRAM_BOT_TOKEN из config.txt
REM ======================================================
set "BOT_TOKEN="
for /f "usebackq tokens=1* delims==" %%A in ("config.txt") do (
    if /i "%%A"=="TELEGRAM_BOT_TOKEN" (
        set "BOT_TOKEN=%%B"
    )
)

if not defined BOT_TOKEN (
    echo ERROR: TELEGRAM_BOT_TOKEN не найден в config.txt
    echo Поместите строку вида:
    echo   TELEGRAM_BOT_TOKEN=123456789:ABCdefGhIJK_lmnopQRstUvWxyZ
    echo в файл config.txt рядом с этим батником.
    pause
    goto :EOF
)

echo ?? Telegram BOT token: %BOT_TOKEN%
echo.

REM ======================================================
REM 2) Запускаем ngrok в отдельном окне, пробрасывая порт 8081
REM ======================================================
echo ?? Запускаем ngrok: "ngrok http 8081"
REM Если ngrok не в PATH, замените "ngrok" на полный путь к ngrok.exe
start "Ngrok Tunnel" ngrok http 8081 --log=ngrok.log
echo Ожидаем, пока ngrok поднимет туннель и отдаст публичный URL...
echo.

REM ======================================================
REM 3) Ждём публичный URL из локального API ngrok (http://127.0.0.1:4040)
REM    Максимум 30 попыток (30 секунд)
REM ======================================================
set /a COUNTER=0
set "NGROK_URL="

:WAIT_LOOP
    powershell -Command "(Invoke-RestMethod 'http://127.0.0.1:4040/api/tunnels').tunnels[0].public_url" > ngrok_url.txt 2>nul
    set /p NGROK_URL=<ngrok_url.txt

    if defined NGROK_URL (
        goto GOT_URL
    )

    if !COUNTER! GEQ 30 (
        echo ERROR: Не удалось получить ngrok URL за 30 секунд.
        echo Проверьте, что ngrok запущен и доступен http://127.0.0.1:4040
        pause
        goto :EOF
    )

    set /a COUNTER+=1
    timeout /t 1 >nul
goto WAIT_LOOP

:GOT_URL
del ngrok_url.txt 2>nul
echo ?? Ngrok public URL: !NGROK_URL!
echo.

REM … после setlocal EnableDelayedExpansion
set "CFG_FILE=config.txt"

REM ======================================================
REM 4) Устанавливаем webhook для Telegram Bot API
REM ======================================================
echo ?? Устанавливаем Telegram Webhook: !NGROK_URL!/
curl -s -X POST "https://api.telegram.org/bot%BOT_TOKEN%/setWebhook" -d "url=!NGROK_URL!/" > sethook_response.json
echo Ответ сервера Telegram (setWebhook) сохранен в sethook_response.json
type sethook_response.json
echo.

REM ======================================================
REM 4а) Обновляем config.txt
REM ======================================================
REM Удаляем старые записи
findstr /v /b /c:"CLASSIFIER_URL=" /c:"DASHBOARD_URL=" "%CFG_FILE%" > "%CFG_FILE%.tmp"
move /y "%CFG_FILE%.tmp" "%CFG_FILE%" >nul

echo CLASSIFIER_URL=%NGROK_URL%/classify>> "%CFG_FILE%"
echo DASHBOARD_URL=%NGROK_URL%/dashboard.php>> "%CFG_FILE%"

echo ? Обновили %CFG_FILE%:
echo    CLASSIFIER_URL=%NGROK_URL%/classify
echo    DASHBOARD_URL=%NGROK_URL%/dashboard.php
echo.


REM ======================================================
REM 5) Запрашиваем getWebhookInfo для диагностики
REM ======================================================

echo Получаем текущее состояние webhook:
curl -s "https://api.telegram.org/bot%BOT_TOKEN%/getWebhookInfo"

echo.

echo ? Всё готово! Бот теперь должен принимать запросы на адрес:
echo    !NGROK_URL!
echo.

pause
