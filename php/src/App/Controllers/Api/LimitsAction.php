<?php

namespace App\Controllers\Api;

use App\Http\ApiRequest;
use App\Http\ApiResponse;
use App\Models\Category;
use App\Models\Limit;
use App\Services\LimitsPresenter;

/**
 * Лимиты: общий и по категориям.
 *
 * Пустая или отсутствующая категория означает общий лимит — на этом держится
 * единая форма ввода в Mini App.
 */
class LimitsAction
{
    private Limit $limits;
    private Category $categories;
    private LimitsPresenter $presenter;

    public function __construct(
        ?Limit $limits = null,
        ?Category $categories = null,
        ?LimitsPresenter $presenter = null,
    ) {
        $this->limits = $limits ?? new Limit();
        $this->categories = $categories ?? new Category();
        $this->presenter = $presenter ?? new LimitsPresenter();
    }

    public function get(ApiRequest $request, int $chatId): ApiResponse
    {
        return ApiResponse::success($this->presenter->payload($chatId));
    }

    public function post(ApiRequest $request, int $chatId): ApiResponse
    {
        $category = $this->category($request->body('category'));
        $amount = (float)$request->body('amount', 0);

        if ($amount <= 0) {
            return ApiResponse::error('Limit must be greater than zero', 400);
        }

        if ($category !== Limit::GLOBAL_CATEGORY && !$this->categories->categoryExists($chatId, $category)) {
            return ApiResponse::error('Unknown category', 400);
        }

        $this->limits->set($chatId, $category, $amount);

        return ApiResponse::success(['message' => 'Limit saved']);
    }

    public function delete(ApiRequest $request, int $chatId): ApiResponse
    {
        $category = $this->category($request->query('category'));

        if (!$this->limits->delete($chatId, $category)) {
            return ApiResponse::error('Limit not found', 404);
        }

        return ApiResponse::success(['message' => 'Limit deleted']);
    }

    /**
     * Пустая или отсутствующая категория означает общий лимит.
     */
    private function category(mixed $category): string
    {
        $category = trim((string)$category);

        return $category === '' ? Limit::GLOBAL_CATEGORY : $category;
    }
}
