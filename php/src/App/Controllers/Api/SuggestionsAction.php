<?php

namespace App\Controllers\Api;

use App\Http\ApiRequest;
use App\Http\ApiResponse;
use App\Models\Expense;

/**
 * Частые позиции пользователя для подсказок при вводе.
 */
class SuggestionsAction
{
    private Expense $expenses;

    public function __construct(?Expense $expenses = null)
    {
        $this->expenses = $expenses ?? new Expense();
    }

    public function get(ApiRequest $request, int $chatId): ApiResponse
    {
        $limit = (int)$request->query('limit', 8);

        return ApiResponse::success([
            'suggestions' => $this->expenses->getFrequentNames($chatId, $limit),
        ]);
    }
}
