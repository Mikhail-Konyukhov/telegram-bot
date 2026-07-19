<?php
namespace App\Controllers;

use App\Models\User;
use App\Models\Expense;
use App\Models\Category;
use App\Models\Limit;
use App\Services\DashboardService;
use Exception;
use Smarty\Smarty;

/**
 * Контроллер DashboardController отвечает за отображение дашборда расходов пользователя.
 */
class DashboardController
{
    /**
     * @var User Модель пользователей.
     */
    private User $userModel;

    /**
     * @var DashboardService Сервис дашборда.
     */
    private DashboardService $dashboardService;

    /**
     * @var Category Модель категорий.
     */
    private Category $categoryModel;

    /**
     * @var Limit Модель лимитов.
     */
    private Limit $limitModel;

    /**
     * @var Expense Модель расходов.
     */
    private Expense $expenseModel;

    /**
     * Конструктор. Инициализирует модели.
     */
    public function __construct()
    {
        $this->userModel = new User();
        $this->dashboardService = new DashboardService();
        $this->categoryModel = new Category();
        $this->limitModel = new Limit();
        $this->expenseModel = new Expense();
    }

    /**
     * Отображает страницу дашборда.
     *
     * Принимает параметры GET:
     * - user: идентификатор пользователя (chatId)
     * - token: токен для аутентификации
     * - period (необязательно): today | week | month (по умолчанию month)
     *
     * Если токен некорректен, возвращает 403.
     * В противном случае отображает страницу dashboard.tpl.
     *
     * @return void
     * @throws Exception
     */
    public function show(): void
    {
        $chatId = $_GET['user_id'] ?? null;
        $token  = $_GET['token'] ?? null;
        $startDate = $_GET['start_date'] ?? null;
        $endDate   = $_GET['end_date'] ?? null;

        // Проверка доступа
        if (!$chatId || !$token || !$this->userModel->checkToken($chatId, $token)) {
            http_response_code(403);
            echo 'Access denied';
            return;
        }

        // Валидация дат
        if (!$startDate || !$endDate) {
            $endDate = new \DateTimeImmutable('today');
            $startDate = $endDate->modify('-1 month');
        } else {
            try {
                $startDate = new \DateTimeImmutable($startDate);
                $endDate = new \DateTimeImmutable($endDate);
            } catch (\Exception $e) {
                http_response_code(400);
                echo 'Invalid date format';
                return;
            }
        }

        // Получение данных
        $expenses = $this->dashboardService->getExpensesForPeriodByCategory($chatId, $startDate, $endDate);
        $detailedExpenses = $this->dashboardService->getDetailedExpenses($chatId, $startDate, $endDate);
        $userCategories = $this->categoryModel->getUserCategories($chatId);
        $personalCategories = $this->categoryModel->getUserPersonalCategories($chatId);

        // Сводная статистика
        $totalExpenses = array_sum($expenses);
        $avgExpense = count($detailedExpenses) ? ($totalExpenses / count($detailedExpenses)) : 0;
        
        // Получаем общий лимит и общую сумму за последние 30 дней
        $globalLimit = $this->limitModel->getGlobal($chatId);
        $globalTotal = $this->expenseModel->getTotalLast30Days($chatId);

        // Приводим к формату YYYY-MM-DD
        $startDateStr = $startDate->format('Y-m-d');
        $endDateStr   = $endDate->format('Y-m-d');
        // Форматируем даты для отображения в формате дд.мм.гг
        $startDateDisplay = $startDate->format('d.m.y');
        $endDateDisplay = $endDate->format('d.m.y');

        // Настройка Smarty
        $smarty = new Smarty();
        $smarty->setTemplateDir(__DIR__ . '/../templates/');
        $smarty->setCompileDir(__DIR__ . '/../templates_c/');

        // Передача данных в шаблон
        $smarty->assign('expenses', $expenses);
        $smarty->assign('detailedExpenses', $detailedExpenses);
        $smarty->assign('userCategories', $userCategories);
        $smarty->assign('personalCategories', $personalCategories);
        $smarty->assign('startDate', $startDateStr);
        $smarty->assign('endDate', $endDateStr);
        $smarty->assign('startDateDisplay', $startDateDisplay);
        $smarty->assign('endDateDisplay', $endDateDisplay);
        $smarty->assign('params', $_GET); // для подстановки в hidden-поля
        $smarty->assign('totalExpenses', $totalExpenses);
        $smarty->assign('averageExpense', $avgExpense);
        $smarty->assign('globalLimit', $globalLimit);
        $smarty->assign('globalTotal', $globalTotal);
        // Процент заполнения лимита (не более 100%) для прогресс-бара
        $progressPercent = ($globalLimit !== null && $globalLimit > 0)
            ? min($globalTotal / $globalLimit * 100, 100)
            : 0;
        $smarty->assign('progressPercent', $progressPercent);

        $smarty->display('dashboard.tpl');
    }


    /**
     * Возвращает границы периода в формате [startDate, endDate].
     *
     * @param string $period Период (today | week | month).
     * @return array{string, string} Массив с начальной и конечной датой в формате Y-m-d H:i:s.
     * @throws Exception
     */
    private function getPeriodDates(string $period): array
    {
        $now = new \DateTimeImmutable();

        switch ($period) {
            case 'today':
                $start = new \DateTimeImmutable($now->format('Y-m-d') . ' 00:00:00');
                $end = new \DateTimeImmutable($now->format('Y-m-d') . ' 23:59:59');
                break;

            case 'week':
                $start = new \DateTimeImmutable($now->modify('monday this week')->format('Y-m-d') . ' 00:00:00');
                $end = new \DateTimeImmutable($now->modify('sunday this week')->format('Y-m-d') . ' 23:59:59');
                break;

            case 'month':
            default:
                $start = new \DateTimeImmutable($now->modify('first day of this month')->format('Y-m-d') . ' 00:00:00');
                $end = new \DateTimeImmutable($now->modify('last day of this month')->format('Y-m-d') . ' 23:59:59');
                break;
        }

        return [$start, $end];
    }
}
