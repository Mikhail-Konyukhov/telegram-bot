<?php

namespace App\Controllers\Api;

use App\Http\ApiRequest;
use App\Http\ApiResponse;
use App\Models\Category;

/**
 * Категории пользователя.
 *
 * `all_categories` — имена (системные плюс свои), `personal_categories` —
 * строки таблицы целиком. Формы намеренно разные: первый список идёт в выбор
 * категории, второй — в экран управления, где нужны id и дата создания.
 */
class CategoriesAction
{
    private Category $categories;

    public function __construct(?Category $categories = null)
    {
        $this->categories = $categories ?? new Category();
    }

    public function get(ApiRequest $request, int $chatId): ApiResponse
    {
        return ApiResponse::success([
            'all_categories' => $this->categories->getUserCategories($chatId),
            'personal_categories' => $this->categories->getUserPersonalCategories($chatId),
        ]);
    }

    public function post(ApiRequest $request, int $chatId): ApiResponse
    {
        $name = trim((string)$request->body('name', ''));

        if (empty($name)) {
            return ApiResponse::error('Category name is required', 400);
        }

        if (!$this->categories->addUserCategory($chatId, $name)) {
            return ApiResponse::error('Category already exists or failed to create', 400);
        }

        return ApiResponse::success(['message' => 'Category created successfully']);
    }

    public function delete(ApiRequest $request, int $chatId): ApiResponse
    {
        $name = (string)$request->query('name', '');

        if (empty($name)) {
            return ApiResponse::error('Category name is required', 400);
        }

        if (!$this->categories->deleteUserCategory($chatId, $name)) {
            return ApiResponse::error('Failed to delete category or category not found', 400);
        }

        return ApiResponse::success(['message' => 'Category deleted successfully']);
    }
}
