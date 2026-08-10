<?php

namespace App\Controllers;

use App\Cfg;
use App\Models\User;
use App\Models\Expense;
use App\Models\Category;
use App\Models\CategoryHint;
use App\Models\Limit;
use App\Services\DashboardService;
use App\Services\ExpenseIntakeService;
use App\Services\LedgerResolver;
use App\Services\TelegramAuth;
use GuzzleHttp\Client as HttpClient;
use TelegramBot\Api\Client;

/**
 * API контроллер для работы с тратами
 */
class ExpenseApiController
{
    private User $userModel;
    private Expense $expenseModel;
    private Category $categoryModel;
    private Limit $limitModel;
    private CategoryHint $hints;
    private DashboardService $dashboardService;

    public function __construct()
    {
        $this->userModel = new User();
        $this->expenseModel = new Expense();
        $this->categoryModel = new Category();
        $this->limitModel = new Limit();
        $this->hints = new CategoryHint();
        $this->dashboardService = new DashboardService();
    }

    /**
     * Обрабатывает API запросы
     */
    public function handle(): void
    {
        // Mini App отдаётся с того же origin, что и API — CORS не нужен.
        header('Content-Type: application/json; charset=utf-8');

        $method = $_SERVER['REQUEST_METHOD'];
        $action = $_GET['action'] ?? '';

        try {
            // Внутри try, чтобы отсутствующий config.txt отдавал JSON-500,
            // а не HTML-фатал посреди ответа API.
            $chatId = $this->authenticate();
            if ($chatId === null) {
                $this->sendError('Access denied', 403);
                return;
            }

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
        } catch (\InvalidArgumentException $e) {
            $this->sendError($e->getMessage(), 400);
        } catch (\Exception $e) {
            $this->sendError($e->getMessage(), 500);
        }
    }

    /**
     * Проверяет подпись initData и возвращает id книги трат.
     *
     * Книга — это чат: в личке сам пользователь, в группе группа (её id приезжает
     * в `?startapp=`, право на неё подтверждает {@see LedgerResolver}). Незнакомый
     * владелец регистрируется автоматически — открыть Mini App можно и не отправляя
     * боту /start.
     *
     * @return int|null null, если заголовок отсутствует, подпись невалидна
     *                  или пользователь не состоит в запрошенном чате
     */
    private function authenticate(): ?int
    {
        $initData = $_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? '';
        if ($initData === '') {
            return null;
        }

        $botToken = (new Cfg())->getTelegramBotToken();

        $auth = TelegramAuth::verify($initData, $botToken);
        if ($auth === null) {
            return null;
        }

        $ledgerId = (new LedgerResolver(new Client($botToken)))
            ->resolve($auth['user_id'], $auth['start_param']);

        if ($ledgerId === null) {
            return null;
        }

        $this->userModel->ensure($ledgerId);

        return $ledgerId;
    }

    /**
     * Обработка GET запросов
     */
    private function handleGet(string $action, int $chatId): void
    {
        switch ($action) {
            case 'overview':
                $this->getOverview($chatId);
                break;
            case 'expenses':
                $this->getExpenses($chatId);
                break;
            case 'categories':
                $this->getCategories($chatId);
                break;
            case 'limits':
                $this->getLimits($chatId);
                break;
            case 'suggestions':
                $this->getSuggestions($chatId);
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
            case 'expense_smart':
                $this->createExpensesFromText($chatId);
                break;
            case 'category':
                $this->createCategory($chatId);
                break;
            case 'limit':
                $this->setLimit($chatId);
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
            case 'limit':
                $this->deleteLimit($chatId);
                break;
            default:
                $this->sendError('Unknown action', 400);
        }
    }

    /**
     * Границы запрошенного периода.
     *
     * @return \DateTimeImmutable[] [$start, $end]
     * @throws \InvalidArgumentException При неразбираемой дате
     */
    private function period(): array
    {
        try {
            $start = new \DateTimeImmutable($_GET['start_date'] ?? date('Y-m-01'));
            $end = new \DateTimeImmutable($_GET['end_date'] ?? date('Y-m-t'));
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Invalid date format');
        }

        return [$start, $end];
    }

    /**
     * Всё для экрана «Обзор» одним запросом — на мобильном каждый лишний
     * round-trip дороже, чем пара лишних выборок на сервере.
     */
    private function getOverview(int $chatId): void
    {
        [$start, $end] = $this->period();

        $expenses = $this->dashboardService->getDetailedExpenses($chatId, $start, $end);
        $total = array_sum(array_column($expenses, 'amount'));

        // Предыдущий период такой же длины — для сравнения «↑ 12% к прошлому месяцу»
        $length = (int)$start->diff($end)->days;
        $prevEnd = $start->modify('-1 day');
        $prevStart = $prevEnd->modify("-{$length} days");
        $prevTotal = array_sum(array_column(
            $this->dashboardService->getDetailedExpenses($chatId, $prevStart, $prevEnd),
            'amount'
        ));

        $byCategory = [];
        foreach ($expenses as $expense) {
            $category = $expense['category'];
            $byCategory[$category] = ($byCategory[$category] ?? 0) + (float)$expense['amount'];
        }
        arsort($byCategory);

        $this->sendSuccess([
            'period' => ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')],
            'total' => round((float)$total, 2),
            'count' => count($expenses),
            'average' => $expenses ? round((float)$total / count($expenses), 2) : 0.0,
            'previous_total' => round((float)$prevTotal, 2),
            'by_category' => $byCategory,
            'limits' => $this->limitsPayload($chatId),
            'recent' => array_slice($expenses, 0, 5),
        ]);
    }

    /**
     * Получение списка трат
     */
    private function getExpenses(int $chatId): void
    {
        [$start, $end] = $this->period();
        $category = $_GET['category'] ?? null;

        $expenses = $this->dashboardService->getDetailedExpenses($chatId, $start, $end, $category);

        $this->sendSuccess(['expenses' => $expenses]);
    }

    /**
     * Лимиты пользователя вместе с фактическим расходом за 30 дней.
     */
    private function getLimits(int $chatId): void
    {
        $this->sendSuccess($this->limitsPayload($chatId));
    }

    /**
     * Общий лимит и лимиты по категориям с потраченным.
     *
     * Окно — скользящие 30 дней, как в предупреждениях бота.
     *
     * @return array{global: array|null, categories: array}
     */
    private function limitsPayload(int $chatId): array
    {
        $limits = $this->limitModel->getAll($chatId);

        $global = null;
        if (isset($limits[Limit::GLOBAL_CATEGORY])) {
            $global = [
                'limit' => $limits[Limit::GLOBAL_CATEGORY],
                'spent' => round($this->expenseModel->getTotalLast30Days($chatId), 2),
            ];
            unset($limits[Limit::GLOBAL_CATEGORY]);
        }

        $categories = [];
        foreach ($limits as $category => $limit) {
            $categories[] = [
                'category' => $category,
                'limit' => $limit,
                'spent' => round($this->expenseModel->getMonthlyTotal($chatId, $category), 2),
            ];
        }

        return ['global' => $global, 'categories' => $categories];
    }

    /**
     * Частые позиции пользователя для подсказок при вводе.
     */
    private function getSuggestions(int $chatId): void
    {
        $limit = (int)($_GET['limit'] ?? 8);

        $this->sendSuccess(['suggestions' => $this->expenseModel->getFrequentNames($chatId, $limit)]);
    }

    /**
     * Устанавливает лимит. Пустая категория означает общий лимит.
     */
    private function setLimit(int $chatId): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        $category = $this->limitCategory($data['category'] ?? null);
        $amount = (float)($data['amount'] ?? 0);

        if ($amount <= 0) {
            $this->sendError('Limit must be greater than zero', 400);
            return;
        }

        if ($category !== Limit::GLOBAL_CATEGORY && !$this->categoryModel->categoryExists($chatId, $category)) {
            $this->sendError('Unknown category', 400);
            return;
        }

        $this->limitModel->set($chatId, $category, $amount);
        $this->sendSuccess(['message' => 'Limit saved']);
    }

    /**
     * Удаляет лимит. Пустая категория означает общий лимит.
     */
    private function deleteLimit(int $chatId): void
    {
        $category = $this->limitCategory($_GET['category'] ?? null);

        if ($this->limitModel->delete($chatId, $category)) {
            $this->sendSuccess(['message' => 'Limit deleted']);
        } else {
            $this->sendError('Limit not found', 404);
        }
    }

    /**
     * Пустая или отсутствующая категория означает общий лимит.
     */
    private function limitCategory($category): string
    {
        $category = trim((string)$category);

        return $category === '' ? Limit::GLOBAL_CATEGORY : $category;
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
        // Клэмп: каждый период — отдельная выборка из БД, неограниченное
        // periods_count превращает один запрос в сотни. Верх — 31, чтобы
        // пролезал вариант «30 дней» из Mini App.
        $periodsCount = max(2, min((int)($_GET['periods_count'] ?? 12), 31));

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
        $this->hints->remember($chatId, $name, $category);

        $this->sendSuccess(['message' => 'Expense created successfully']);
    }

    /**
     * Обновление траты
     */
    private function updateExpense(int $chatId): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            $this->sendError('Invalid expense ID', 400);
            return;
        }

        $current = $this->expenseModel->getById($id, $chatId);
        if ($current === null) {
            $this->sendError('Expense not found', 404);
            return;
        }

        // Частичное обновление: клиент шлёт только изменённые поля, остальное
        // подтягиваем из текущей записи.
        $name = trim((string)($data['name'] ?? $current['name']));
        $category = trim((string)($data['category'] ?? $current['category']));
        $amount = (float)($data['amount'] ?? $current['amount']);
        $date = (string)($data['date'] ?? $current['ts']);

        if ($name === '' || $category === '' || $amount <= 0) {
            $this->sendError('Invalid data', 400);
            return;
        }

        // Права уже проверены через getById, поэтому нулевой rowCount означает
        // «значения не изменились», а не ошибку.
        $this->expenseModel->update($id, $chatId, $name, $category, $amount, $date);

        // Правка в Mini App — бесплатная разметка: следующая такая трата
        // возьмёт категорию из словаря, не доходя до модели.
        $this->hints->remember($chatId, $name, $category);

        $this->sendSuccess(['message' => 'Expense updated successfully']);
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
     * Умный ввод: строка «кофе 300, такси 450» разбирается на позиции,
     * категории берутся из истории пользователя или у классификатора.
     */
    private function createExpensesFromText(int $chatId): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $text = trim((string)($data['text'] ?? ''));

        if ($text === '') {
            $this->sendError('Text is required', 400);
            return;
        }

        $intake = new ExpenseIntakeService(
            // connect_timeout у Guzzle по умолчанию 0 — без ограничения, см. Bot.php
            new HttpClient(['timeout' => 20, 'connect_timeout' => 5]),
            (new Cfg())->getGeminiApiKey()
        );

        $parsed = $intake->parse($chatId, $text, $this->categoryModel->getUserCategories($chatId));
        $saved = $intake->save($chatId, $parsed['items'], $data['date'] ?? null);

        $this->sendSuccess(['expenses' => $saved, 'errors' => $parsed['errors']]);
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