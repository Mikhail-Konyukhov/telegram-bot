<?php

namespace App\Controllers;

use App\Models\User;
use App\Models\Expense;
use App\Models\Category;
use App\Services\DashboardService;

/**
 * API контроллер для работы с тратами
 */
class ExpenseApiController
{
    private User $userModel;
    private Expense $expenseModel;
    private Category $categoryModel;
    private DashboardService $dashboardService;

    public function __construct()
    {
        $this->userModel = new User();
        $this->expenseModel = new Expense();
        $this->categoryModel = new Category();
        $this->dashboardService = new DashboardService();
    }

    /**
     * Обрабатывает API запросы
     */
    public function handle(): void
    {
        // Устанавливаем заголовки для JSON API
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            return;
        }

        // Проверяем авторизацию
        $chatId = $_GET['user_id'] ?? $_POST['user_id'] ?? null;
        $token = $_GET['token'] ?? $_POST['token'] ?? null;

        if (!$chatId || !$token || !$this->userModel->checkToken($chatId, $token)) {
            $this->sendError('Access denied', 403);
            return;
        }

        $method = $_SERVER['REQUEST_METHOD'];
        $action = $_GET['action'] ?? '';

        try {
            switch ($method) {
                case 'GET':
                    $this->handleGet($action, $chatId);
                    break;
                case 'POST':
                    $this->handlePost($action, $chatId);
                    break;
                case 'PUT':
                    $this->handlePut($action, $chatId);
                    break;
                case 'DELETE':
                    $this->handleDelete($action, $chatId);
                    break;
                default:
                    $this->sendError('Method not allowed', 405);
            }
        } catch (\Exception $e) {
            $this->sendError($e->getMessage(), 500);
        }
    }

    /**
     * Обработка GET запросов
     */
    private function handleGet(string $action, int $chatId): void
    {
        switch ($action) {
            case 'expenses':
                $this->getExpenses($chatId);
                break;
            case 'categories':
                $this->getCategories($chatId);
                break;
            case 'analytics_by_period':
                $this->getAnalyticsByPeriod($chatId);
                break;
            default:
                $this->sendError('Unknown action', 400);
        }
    }

    /**
     * Обработка POST запросов
     */
    private function handlePost(string $action, int $chatId): void
    {
        switch ($action) {
            case 'expense':
                $this->createExpense($chatId);
                break;
            case 'category':
                $this->createCategory($chatId);
                break;
            default:
                $this->sendError('Unknown action', 400);
        }
    }

    /**
     * Обработка PUT запросов
     */
    private function handlePut(string $action, int $chatId): void
    {
        switch ($action) {
            case 'expense':
                $this->updateExpense($chatId);
                break;
            default:
                $this->sendError('Unknown action', 400);
        }
    }

    /**
     * Обработка DELETE запросов
     */
    private function handleDelete(string $action, int $chatId): void
    {
        switch ($action) {
            case 'expense':
                $this->deleteExpense($chatId);
                break;
            case 'category':
                $this->deleteCategory($chatId);
                break;
            default:
                $this->sendError('Unknown action', 400);
        }
    }

    /**
     * Получение списка трат
     */
    private function getExpenses(int $chatId): void
    {
        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-t');
        $category = $_GET['category'] ?? null;

        $start = new \DateTimeImmutable($startDate);
        $end = new \DateTimeImmutable($endDate);

        $expenses = $this->dashboardService->getDetailedExpenses($chatId, $start, $end, $category);
        
        $this->sendSuccess(['expenses' => $expenses]);
    }

    /**
     * Получение категорий пользователя
     */
    private function getCategories(int $chatId): void
    {
        $categories = $this->categoryModel->getUserCategories($chatId);
        $personalCategories = $this->categoryModel->getUserPersonalCategories($chatId);
        
        $this->sendSuccess([
            'all_categories' => $categories,
            'personal_categories' => $personalCategories
        ]);
    }


    /**
     *  Получение сравнительных данных по категориям
     */
    private function getAnalyticsByPeriod(int $chatId): void
    {
        $periodType = $_GET['period_type'] ?? 'month';
        $periodsCount = (int)($_GET['periods_count'] ?? 12);

        $categoriesData = $this->dashboardService->getCategoriesComparativeData($chatId, $periodType, $periodsCount);
        $result = array_map(function($item) {
            return [
                'label' => $item['label'],
                'categories' => $item['categories']
            ];
        }, $categoriesData);

        $this->sendSuccess($result);
    }

    /**
     * Создание новой траты
     */
    private function createExpense(int $chatId): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $name = $data['name'] ?? '';
        $category = $data['category'] ?? '';
        $amount = (float)($data['amount'] ?? 0);
        $date = $data['date'] ?? date('Y-m-d H:i:s');

        if (empty($name) || empty($category) || $amount <= 0) {
            $this->sendError('Invalid data', 400);
            return;
        }

        $this->expenseModel->add($chatId, $name, $category, $amount, $date);
        $this->sendSuccess(['message' => 'Expense created successfully']);
    }

    /**
     * Обновление траты
     */
    private function updateExpense(int $chatId): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $id = (int)($data['id'] ?? 0);
        $name = $data['name'] ?? '';
        $category = $data['category'] ?? '';
        $amount = (float)($data['amount'] ?? 0);
        $date = $data['date'] ?? '';

        if ($id <= 0 || empty($name) || empty($category) || $amount <= 0) {
            $this->sendError('Invalid data', 400);
            return;
        }

        $success = $this->expenseModel->update($id, $chatId, $name, $category, $amount, $date);
        
        if ($success) {
            $this->sendSuccess(['message' => 'Expense updated successfully']);
        } else {
            $this->sendError('Failed to update expense', 400);
        }
    }

    /**
     * Удаление траты
     */
    private function deleteExpense(int $chatId): void
    {
        $id = (int)($_GET['id'] ?? 0);

        if ($id <= 0) {
            $this->sendError('Invalid expense ID', 400);
            return;
        }

        $success = $this->expenseModel->delete($id, $chatId);
        
        if ($success) {
            $this->sendSuccess(['message' => 'Expense deleted successfully']);
        } else {
            $this->sendError('Failed to delete expense', 400);
        }
    }

    /**
     * Создание новой категории
     */
    private function createCategory(int $chatId): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $name = trim($data['name'] ?? '');

        if (empty($name)) {
            $this->sendError('Category name is required', 400);
            return;
        }

        if ($this->categoryModel->addUserCategory($chatId, $name)) {
            $this->sendSuccess(['message' => 'Category created successfully']);
        } else {
            $this->sendError('Category already exists or failed to create', 400);
        }
    }

    /**
     * Удаление категории
     */
    private function deleteCategory(int $chatId): void
    {
        $name = $_GET['name'] ?? '';

        if (empty($name)) {
            $this->sendError('Category name is required', 400);
            return;
        }

        if ($this->categoryModel->deleteUserCategory($chatId, $name)) {
            $this->sendSuccess(['message' => 'Category deleted successfully']);
        } else {
            $this->sendError('Failed to delete category or category not found', 400);
        }
    }

    /**
     * Отправка успешного ответа
     */
    private function sendSuccess(array $data, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Отправка ошибки
     */
    private function sendError(string $message, int $code = 400): void
    {
        http_response_code($code);
        echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    }
} 