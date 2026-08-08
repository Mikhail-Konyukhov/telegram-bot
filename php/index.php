<?php

/**
 * Основной входной файл для обработки обновлений от Telegram-бота + dashboard.
 */

require_once 'vendor/autoload.php';

use App\Bot;
use TelegramBot\Api\Types\Update;

// Mini App лежит статикой в /webapp и отдаётся встроенным сервером напрямую.
// Сюда попадают только вебхуки от Telegram.
$inputData = json_decode(file_get_contents('php://input'), true);

if (!$inputData) {
    echo "No data received. Waiting for updates from Telegram...\n";
    return;
}

try {
    $update = Update::fromResponse($inputData);
} catch (\TelegramBot\Api\InvalidArgumentException $e) {
    error_log($e->getMessage());
    return;
}

(new Bot())->handleUpdate($update);
