<?php

namespace App\Controllers\Api;

use App\Http\ApiRequest;
use App\Http\ApiResponse;
use App\Services\DashboardService;

/**
 * Сравнительные данные по категориям за несколько периодов — экран «Динамика».
 */
class AnalyticsAction
{
    private DashboardService $dashboard;

    public function __construct(?DashboardService $dashboard = null)
    {
        $this->dashboard = $dashboard ?? new DashboardService();
    }

    public function get(ApiRequest $request, int $chatId): ApiResponse
    {
        $periodType = (string)$request->query('period_type', 'month');

        // Клэмп: каждый период — отдельная выборка из БД, неограниченное
        // periods_count превращает один запрос в сотни. Верх — 31, чтобы
        // пролезал вариант «30 дней» из Mini App.
        $periodsCount = max(2, min((int)$request->query('periods_count', 12), 31));

        $data = $this->dashboard->getCategoriesComparativeData($chatId, $periodType, $periodsCount);

        return ApiResponse::success(array_map(
            static fn(array $item): array => [
                'label' => $item['label'],
                'categories' => $item['categories'],
            ],
            $data
        ));
    }
}
