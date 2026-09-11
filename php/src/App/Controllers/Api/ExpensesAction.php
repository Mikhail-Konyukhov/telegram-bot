<?php

namespace App\Controllers\Api;

use App\Cfg;
use App\Http\ApiRequest;
use App\Http\ApiResponse;
use App\Models\CategoryHint;
use App\Models\Category;
use App\Models\Expense;
use App\Services\DashboardService;
use App\Services\ExpenseIntakeService;
use GuzzleHttp\Client as HttpClient;

/**
 * Траты: список, создание, правка, удаление и умный ввод.
 */
class ExpensesAction
{
    private Expense $expenses;
    private Category $categories;
    private CategoryHint $hints;
    private DashboardService $dashboard;

    public function __construct(
        ?Expense $expenses = null,
        ?Category $categories = null,
        ?CategoryHint $hints = null,
        ?DashboardService $dashboard = null,
    ) {
        $this->expenses = $expenses ?? new Expense();
        $this->categories = $categories ?? new Category();
        $this->hints = $hints ?? new CategoryHint();
        $this->dashboard = $dashboard ?? new DashboardService();
    }

    public function get(ApiRequest $request, int $chatId): ApiResponse
    {
        [$start, $end] = $request->period();

        return ApiResponse::success([
            'expenses' => $this->dashboard->getDetailedExpenses(
                $chatId,
                $start,
                $end,
                $request->query('category')
            ),
        ]);
    }

    public function post(ApiRequest $request, int $chatId): ApiResponse
    {
        $name = $request->body('name', '');
        $category = $request->body('category', '');
        $amount = (float)$request->body('amount', 0);
        $date = $request->body('date', date('Y-m-d H:i:s'));

        if (empty($name) || empty($category) || $amount <= 0) {
            return ApiResponse::error('Invalid data', 400);
        }

        $this->expenses->add($chatId, $name, $category, $amount, $date);
        $this->hints->remember($chatId, $name, $category);

        return ApiResponse::success(['message' => 'Expense created successfully']);
    }

    public function put(ApiRequest $request, int $chatId): ApiResponse
    {
        $id = (int)$request->body('id', 0);
        if ($id <= 0) {
            return ApiResponse::error('Invalid expense ID', 400);
        }

        $current = $this->expenses->getById($id, $chatId);
        if ($current === null) {
            return ApiResponse::error('Expense not found', 404);
        }

        // Частичное обновление: клиент шлёт только изменённые поля, остальное
        // подтягиваем из текущей записи.
        $name = trim((string)$request->body('name', $current['name']));
        $category = trim((string)$request->body('category', $current['category']));
        $amount = (float)$request->body('amount', $current['amount']);
        $date = (string)$request->body('date', $current['ts']);

        if ($name === '' || $category === '' || $amount <= 0) {
            return ApiResponse::error('Invalid data', 400);
        }

        // Права уже проверены через getById, поэтому нулевой rowCount означает
        // «значения не изменились», а не ошибку.
        $this->expenses->update($id, $chatId, $name, $category, $amount, $date);

        // Правка в Mini App — бесплатная разметка: следующая такая трата
        // возьмёт категорию из словаря, не доходя до модели.
        $this->hints->remember($chatId, $name, $category);

        return ApiResponse::success(['message' => 'Expense updated successfully']);
    }

    public function delete(ApiRequest $request, int $chatId): ApiResponse
    {
        $id = (int)$request->query('id', 0);

        if ($id <= 0) {
            return ApiResponse::error('Invalid expense ID', 400);
        }

        if (!$this->expenses->delete($id, $chatId)) {
            return ApiResponse::error('Failed to delete expense', 400);
        }

        return ApiResponse::success(['message' => 'Expense deleted successfully']);
    }

    /**
     * Умный ввод: строка «кофе 300, такси 450» разбирается на позиции,
     * категории берутся из истории пользователя или у классификатора.
     */
    public function smart(ApiRequest $request, int $chatId): ApiResponse
    {
        $text = trim((string)$request->body('text', ''));

        if ($text === '') {
            return ApiResponse::error('Text is required', 400);
        }

        $intake = new ExpenseIntakeService(
            // connect_timeout у Guzzle по умолчанию 0 — без ограничения, см. Bot.php
            new HttpClient(['timeout' => 20, 'connect_timeout' => 5]),
            (new Cfg())->getGeminiApiKey()
        );

        $parsed = $intake->parse($chatId, $text, $this->categories->getUserCategories($chatId));
        $saved = $intake->save($chatId, $parsed['items'], $request->body('date'));

        return ApiResponse::success(['expenses' => $saved, 'errors' => $parsed['errors']]);
    }
}
