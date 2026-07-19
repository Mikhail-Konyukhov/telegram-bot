<?php

/**
 * Основной входной файл для обработки обновлений от Telegram-бота + dashboard.
 */

require_once 'vendor/autoload.php';

use App\Bot;
use App\Controllers\DashboardController;
use TelegramBot\Api\Types\Update;

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Если пришел запрос к /dashboard → рендерим HTML dashboard
if (preg_match('#^/dashboard$#', $requestUri)) {
    (new DashboardController())->show();
    exit;
}

// Для всех остальных запросов — обрабатываем как webhook от Telegram
$inputData = json_decode(file_get_contents('php://input'), true);

if ($inputData) {
    try {
        $update = Update::fromResponse($inputData);
    } catch (\TelegramBot\Api\InvalidArgumentException $e) {
        error_log($e->getMessage());
    }
    $bot = new Bot();
    $bot->handleUpdate($update);
} else {
    // Это GET-запрос НЕ к /dashboard → можно просто показать заглушку
    echo "No data received. Waiting for updates from Telegram...\n";
}
