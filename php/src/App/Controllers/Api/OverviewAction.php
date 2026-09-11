<?php

namespace App\Controllers\Api;

use App\Http\ApiRequest;
use App\Http\ApiResponse;
use App\Services\DashboardService;
use App\Services\LimitsPresenter;

/**
 * Экран «Обзор».
 *
 * Всё одним запросом — на мобильном каждый лишний round-trip дороже, чем пара
 * лишних выборок на сервере.
 */
class OverviewAction
{
    private DashboardService $dashboard;
    private LimitsPresenter $limits;

    public function __construct(?DashboardService $dashboard = null, ?LimitsPresenter $limits = null)
    {
        $this->dashboard = $dashboard ?? new DashboardService();
        $this->limits = $limits ?? new LimitsPresenter();
    }

    public function get(ApiRequest $request, int $chatId): ApiResponse
    {
        [$start, $end] = $request->period();

        $expenses = $this->dashboard->getDetailedExpenses($chatId, $start, $end);
        $total = array_sum(array_column($expenses, 'amount'));

        // Предыдущий период такой же длины — для сравнения «↑ 12% к прошлому месяцу»
        [$prevStart, $prevEnd] = $this->dashboard->previousPeriod($start, $end);
        $prevTotal = array_sum(array_column(
            $this->dashboard->getDetailedExpenses($chatId, $prevStart, $prevEnd),
            'amount'
        ));

        $byCategory = [];
        foreach ($expenses as $expense) {
            $category = $expense['category'];
            $byCategory[$category] = ($byCategory[$category] ?? 0) + (float)$expense['amount'];
        }
        arsort($byCategory);

        return ApiResponse::success([
            'period' => ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')],
            'total' => round((float)$total, 2),
            'count' => count($expenses),
            'average' => $expenses ? round((float)$total / count($expenses), 2) : 0.0,
            'previous_total' => round((float)$prevTotal, 2),
            'by_category' => $byCategory,
            'limits' => $this->limits->payload($chatId),
            'recent' => array_slice($expenses, 0, 5),
        ]);
    }
}
