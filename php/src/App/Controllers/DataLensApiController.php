<?php

namespace App\Controllers;

use App\Models\Expense;
use App\Models\Limit;

/**
 * API контроллер для интеграции с Yandex DataLens
 */
class DataLensApiController
{
    private Expense $expenseModel;
    private Limit $limitModel;
    private ?string $apiToken;

    public function __construct()
    {
        $this->expenseModel = new Expense();
        $this->limitModel = new Limit();
        
        // Загружаем токен из config.txt (опционально)
        $configFile = __DIR__ . '/../../../config.txt';
        if (file_exists($configFile)) {
            $config = parse_ini_file($configFile);
            $this->apiToken = $config['DATALENS_API_TOKEN'] ?? null;
        } else {
            $this->apiToken = null;
        }
    }

    /**
     * Обрабатывает API запросы для DataLens
     */
    public function handle(): void
    {
        // Устанавливаем заголовки для JSON API
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            return;
        }

        // Проверяем токен авторизации (если настроен)
        if ($this->apiToken && !$this->checkAuthorization()) {
            $this->sendError('Unauthorized', 401);
            return;
        }

        $endpoint = $_GET['endpoint'] ?? '';

        try {
            switch ($endpoint) {
                case 'expenses':
                    $this->getExpenses();
                    break;
                case 'expenses-by-category':
                    $this->getExpensesByCategory();
                    break;
                case 'expenses-by-period':
                    $this->getExpensesByPeriod();
                    break;
                case 'limits':
                    $this->getLimits();
                    break;
                case 'expenses-vs-limits':
                    $this->getExpensesVsLimits();
                    break;
                default:
                    $this->sendError('Unknown endpoint', 400);
            }
        } catch (\Exception $e) {
            $this->sendError($e->getMessage(), 500);
        }
    }

    /**
     * Проверяет токен авторизации в заголовке Authorization
     * 
     * @return bool
     */
    private function checkAuthorization(): bool
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
        
        if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
            return $matches[1] === $this->apiToken;
        }
        
        return false;
    }

    /**
     * Возвращает все расходы в формате DataLens
     * GET /api/datalens?endpoint=expenses
     * Параметры: start_date, end_date (опционально)
     */
    private function getExpenses(): void
    {
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;

        $expenses = $this->expenseModel->getAllForDataLens($startDate, $endDate);

        $this->sendDataLensResponse([
            'columns' => [
                ['name' => 'id', 'type' => 'integer'],
                ['name' => 'user_id', 'type' => 'integer'],
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'category', 'type' => 'string'],
                ['name' => 'amount', 'type' => 'float'],
                ['name' => 'date', 'type' => 'datetime']
            ],
            'data' => array_map(function($expense) {
                return [
                    (int)$expense['id'],
                    (int)$expense['user_id'],
                    $expense['name'],
                    $expense['category'],
                    (float)$expense['amount'],
                    $expense['ts']
                ];
            }, $expenses)
        ]);
    }

    /**
     * Возвращает агрегированные расходы по категориям
     * GET /api/datalens?endpoint=expenses-by-category
     * Параметры: start_date, end_date, user_id (опционально)
     */
    private function getExpensesByCategory(): void
    {
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;
        $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;

        $data = $this->expenseModel->getAggregatedByCategory($startDate, $endDate, $userId);

        $this->sendDataLensResponse([
            'columns' => [
                ['name' => 'user_id', 'type' => 'integer'],
                ['name' => 'category', 'type' => 'string'],
                ['name' => 'total_amount', 'type' => 'float'],
                ['name' => 'count', 'type' => 'integer']
            ],
            'data' => array_map(function($row) {
                return [
                    (int)$row['user_id'],
                    $row['category'],
                    (float)$row['total_amount'],
                    (int)$row['count']
                ];
            }, $data)
        ]);
    }

    /**
     * Возвращает агрегированные расходы по периодам
     * GET /api/datalens?endpoint=expenses-by-period
     * Параметры: period (day/week/month), start_date, end_date, user_id (опционально)
     */
    private function getExpensesByPeriod(): void
    {
        $period = $_GET['period'] ?? 'month';
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;
        $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;

        $data = $this->expenseModel->getAggregatedByPeriod($period, $startDate, $endDate, $userId);

        $this->sendDataLensResponse([
            'columns' => [
                ['name' => 'user_id', 'type' => 'integer'],
                ['name' => 'period', 'type' => 'string'],
                ['name' => 'category', 'type' => 'string'],
                ['name' => 'total_amount', 'type' => 'float'],
                ['name' => 'count', 'type' => 'integer']
            ],
            'data' => array_map(function($row) {
                return [
                    (int)$row['user_id'],
                    $row['period'],
                    $row['category'],
                    (float)$row['total_amount'],
                    (int)$row['count']
                ];
            }, $data)
        ]);
    }

    /**
     * Возвращает все лимиты пользователей
     * GET /api/datalens?endpoint=limits
     * Параметры: user_id (опционально)
     */
    private function getLimits(): void
    {
        $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
        
        $data = $this->limitModel->getAllForDataLens($userId);

        $this->sendDataLensResponse([
            'columns' => [
                ['name' => 'user_id', 'type' => 'integer'],
                ['name' => 'category', 'type' => 'string'],
                ['name' => 'limit', 'type' => 'float'],
                ['name' => 'updated_at', 'type' => 'datetime']
            ],
            'data' => array_map(function($row) {
                return [
                    (int)$row['user_id'],
                    $row['category'],
                    (float)$row['limit'],
                    $row['updated_at']
                ];
            }, $data)
        ]);
    }

    /**
     * Возвращает сравнение расходов и лимитов
     * GET /api/datalens?endpoint=expenses-vs-limits
     * Параметры: start_date, end_date, user_id (опционально)
     */
    private function getExpensesVsLimits(): void
    {
        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-t');
        $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;

        // Получаем расходы по категориям
        $expenses = $this->expenseModel->getAggregatedByCategory($startDate, $endDate, $userId);
        
        // Получаем лимиты
        $limits = $this->limitModel->getAllForDataLens($userId);
        
        // Создаем карту лимитов для быстрого поиска
        $limitsMap = [];
        foreach ($limits as $limit) {
            $key = $limit['user_id'] . '_' . $limit['category'];
            $limitsMap[$key] = (float)$limit['limit'];
        }

        // Объединяем данные
        $result = [];
        foreach ($expenses as $expense) {
            $key = $expense['user_id'] . '_' . $expense['category'];
            $limitValue = $limitsMap[$key] ?? null;
            $totalAmount = (float)$expense['total_amount'];
            
            $result[] = [
                (int)$expense['user_id'],
                $expense['category'],
                $totalAmount,
                $limitValue,
                $limitValue ? ($totalAmount / $limitValue * 100) : null,
                $limitValue ? ($totalAmount > $limitValue) : null
            ];
        }

        $this->sendDataLensResponse([
            'columns' => [
                ['name' => 'user_id', 'type' => 'integer'],
                ['name' => 'category', 'type' => 'string'],
                ['name' => 'total_amount', 'type' => 'float'],
                ['name' => 'limit', 'type' => 'float'],
                ['name' => 'usage_percent', 'type' => 'float'],
                ['name' => 'is_exceeded', 'type' => 'boolean']
            ],
            'data' => $result
        ]);
    }

    /**
     * Отправка ответа в формате DataLens
     * 
     * @param array $response
     */
    private function sendDataLensResponse(array $response): void
    {
        http_response_code(200);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Отправка ошибки
     * 
     * @param string $message
     * @param int $code
     */
    private function sendError(string $message, int $code = 400): void
    {
        http_response_code($code);
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    }
}


