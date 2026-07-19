<?php

namespace App\Services;

use App\Models\Expense;
use DateTime;
use DateTimeInterface;

/**
 * Сервис для работы с дашбордом
 */
class DashboardService
{
    private Expense $expenseModel;

    public function __construct()
    {
        $this->expenseModel = new Expense();
    }

    /**
     * Возвращает список расходов за выбранный период
     *
     * @param int $chatId
     * @param DateTimeInterface $from
     * @param DateTimeInterface $to
     * @return array
     */
    public function getExpensesForPeriod(int $chatId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return $this->expenseModel->getExpensesForPeriod($chatId, $from, $to);
    }

    /**
     * Возвращает суммы по категориям за выбранный период
     *
     * @param int $chatId
     * @param DateTimeInterface $from
     * @param DateTimeInterface $to
     * @return array [category => totalAmount]
     */
    public function getExpensesForPeriodByCategory(int $chatId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $expenses = $this->getExpensesForPeriod($chatId, $from, $to);

        $summary = [];
        foreach ($expenses as $expense) {
            $category = $expense['category'];
            $summary[$category] = ($summary[$category] ?? 0) + $expense['amount'];
        }

        return $summary;
    }

    /**
     * Возвращает детальный список трат с возможностью фильтрации по категории
     *
     * @param int $chatId
     * @param DateTimeInterface $from
     * @param DateTimeInterface $to
     * @param string|null $category Фильтр по категории
     * @return array
     */
    public function getDetailedExpenses(int $chatId, DateTimeInterface $from, DateTimeInterface $to, ?string $category = null): array
    {
        $expenses = $this->getExpensesForPeriod($chatId, $from, $to);
        
        if ($category) {
            $expenses = array_filter($expenses, function($expense) use ($category) {
                return $expense['category'] === $category;
            });
        }
        
        return array_values($expenses);
    }

    /**
     * Получает данные для сравнения трат за несколько периодов
     *
     * @param int $chatId
     * @param string $periodType 'day', 'week', 'month'
     * @param int $periodsCount Количество периодов
     * @param array|null $categories Фильтр по категориям
     * @return array
     */
    public function getComparativeData(int $chatId, string $periodType, int $periodsCount, ?array $categories = null): array
    {
        $periods = $this->generatePeriods($periodType, $periodsCount);
        $data = [];

        foreach ($periods as $periodData) {
            $expenses = $this->getExpensesForPeriod($chatId, $periodData['start'], $periodData['end']);
            
            if ($categories) {
                $expenses = array_filter($expenses, function($expense) use ($categories) {
                    return in_array($expense['category'], $categories);
                });
            }

            $totalAmount = array_sum(array_column($expenses, 'amount'));
            
            $data[] = [
                'label' => $periodData['label'],
                'start' => $periodData['start']->format('Y-m-d'),
                'end' => $periodData['end']->format('Y-m-d'),
                'total' => $totalAmount,
                'expenses_count' => count($expenses)
            ];
        }

        return array_reverse($data); // Новые периоды в начале
    }

    /**
     * Генерирует массив периодов для сравнения
     *
     * @param string $periodType
     * @param int $count
     * @return array
     */
    private function generatePeriods(string $periodType, int $count): array
    {
        $periods = [];
        $now = new DateTime();

        switch ($periodType) {
            case 'month':
            default:
                // Считаем от первого дня текущего месяца, чтобы избежать дубликатов
                // при вычитании месяцев от 31-го числа (особенность PHP DateTime)
                $base = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
                break;
        }

        for ($i = 0; $i < $count; $i++) {
            switch ($periodType) {
                case 'day':
                    $start = (clone $now)->modify("-{$i} days")->setTime(0, 0, 0);
                    $end = (clone $start)->setTime(23, 59, 59);
                    $label = $start->format('d.m.Y');
                    break;

                case 'week':
                    $start = (clone $now)->modify("-{$i} weeks")->modify('monday this week')->setTime(0, 0, 0);
                    $end = (clone $start)->modify('sunday this week')->setTime(23, 59, 59);
                    $label = $start->format('d.m') . ' - ' . $end->format('d.m.Y');
                    break;

                case 'month':
                default:
                    $start = (clone $base)->modify("-{$i} months");
                    $end = (clone $start)->modify('last day of this month')->setTime(23, 59, 59);
                    $start->setTime(0, 0, 0);
                    $label = $start->format('M Y');
                    break;
            }

            $periods[] = [
                'start' => $start,
                'end' => $end,
                'label' => $label
            ];
        }

        return $periods;
    }

    /**
     * Получает данные по категориям за несколько периодов
     *
     * @param int $chatId
     * @param string $periodType
     * @param int $periodsCount
     * @param array|null $categories
     * @return array
     */
    public function getCategoriesComparativeData(int $chatId, string $periodType, int $periodsCount, ?array $categories = null): array
    {
        $periods = $this->generatePeriods($periodType, $periodsCount);
        $data = [];

        foreach ($periods as $periodData) {
            $expensesByCategory = $this->getExpensesForPeriodByCategory($chatId, $periodData['start'], $periodData['end']);
            
            if ($categories) {
                $expensesByCategory = array_intersect_key($expensesByCategory, array_flip($categories));
            }

            $data[] = [
                'label' => $periodData['label'],
                'start' => $periodData['start']->format('Y-m-d'),
                'end' => $periodData['end']->format('Y-m-d'),
                'categories' => $expensesByCategory
            ];
        }

        return array_reverse($data);
    }
}
