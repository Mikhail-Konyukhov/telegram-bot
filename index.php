<?php

/**
 * Основной входной файл для обработки обновлений от Telegram-бота.
 */

require_once 'vendor/autoload.php';

use App\Bot;
use TelegramBot\Api\Types\Update;

$inputData = json_decode(file_get_contents('php://input'), true);

// Если данные есть, обрабатываем обновление
if ($inputData) {
    $update = Update::fromResponse($inputData);
    $bot = new Bot();

    // Обрабатываем входящее обновление
    $bot->handleUpdate($update);
} else {
    // Если данных нет, выводим сообщение в лог или на экран (при отладке)
    echo "No data received. Waiting for updates from Telegram...\n";
}
