<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Controllers\ExpenseApiController;

$controller = new ExpenseApiController();
$controller->handle();
