<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Limit;

/**
 * Лимиты вместе с фактическим расходом.
 *
 * Отдельный класс, потому что этот блок нужен двум экранам сразу: он приезжает
 * и в «Обзоре» (чтобы не делать второй запрос с мобильного), и в «Лимитах».
 *
 * Окно — скользящие 30 дней, как в предупреждениях бота.
 */
class LimitsPresenter
{
    private Limit $limits;
    private Expense $expenses;

    public function __construct(?Limit $limits = null, ?Expense $expenses = null)
    {
        $this->limits = $limits ?? new Limit();
        $this->expenses = $expenses ?? new Expense();
    }

    /**
     * @return array{global: array|null, categories: array}
     */
    public function payload(int $chatId): array
    {
        $limits = $this->limits->getAll($chatId);

        $global = null;
        if (isset($limits[Limit::GLOBAL_CATEGORY])) {
            $global = [
                'limit' => $limits[Limit::GLOBAL_CATEGORY],
                'spent' => round($this->expenses->getTotalLast30Days($chatId), 2),
            ];
            unset($limits[Limit::GLOBAL_CATEGORY]);
        }

        $categories = [];
        foreach ($limits as $category => $limit) {
            $categories[] = [
                'category' => $category,
                'limit' => $limit,
                'spent' => round($this->expenses->getMonthlyTotal($chatId, $category), 2),
            ];
        }

        return ['global' => $global, 'categories' => $categories];
    }
}
