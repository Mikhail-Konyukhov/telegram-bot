<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Controllers\ExpenseApiController;
use App\Controllers\DataLensApiController;

// Определяем тип API по пути запроса
$requestUri = $_SERVER['REQUEST_URI'];
$parsedUrl = parse_url($requestUri);
$path = $parsedUrl['path'] ?? '';

// Маршрутизация для DataLens API
if (strpos($path, '/api/datalens') !== false || isset($_GET['endpoint'])) {
    $controller = new DataLensApiController();
    $controller->handle();
} else {
    // Стандартный API для работы с тратами
    $controller = new ExpenseApiController();
    $controller->handle();
} 