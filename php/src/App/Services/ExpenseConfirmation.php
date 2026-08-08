<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Limit as LimitModel;
use TelegramBot\Api\Types\Inline\InlineKeyboardMarkup;

/**
 * Подтверждение добавленных трат: текст сообщения и кнопки под ним.
 *
 * Вынесено из обработчика, потому что то же сообщение перерисовывает
 * CallbackHandler после смены категории — иначе текст разойдётся с базой.
 *
 * Формат callback_data (лимит Telegram — 64 байта, отсюда однобуквенные коды):
 *   u:<from>:<to>            — отменить, удалить траты диапазона
 *   c:<id>:<from>:<to>       — показать вероятные категории для траты
 *   a:<id>:<from>:<to>       — показать все категории
 *   s:<id>:<from>:<to>:<cat> — назначить категорию
 *   b:<from>:<to>            — назад к подтверждению
 */
class ExpenseConfirmation
{
    /** Сколько чужих категорий предлагать одним нажатием, не считая «…». */
    private const ALTERNATIVES = 3;

    /** Лимит Telegram на callback_data. */
    private const CALLBACK_LIMIT = 64;

    private Expense $expenseModel;
    private LimitModel $limitModel;

    public function __construct()
    {
        $this->expenseModel = new Expense();
        $this->limitModel = new LimitModel();
    }

    /**
     * Собирает подтверждение по диапазону id.
     *
     * @param int $chatId
     * @param int $fromId
     * @param int $toId
     * @return array{text: string, keyboard: InlineKeyboardMarkup}|null
     *         null, если траты уже удалены
     */
    public function render(int $chatId, int $fromId, int $toId): ?array
    {
        $items = $this->expenseModel->findRange($chatId, $fromId, $toId);
        if (!$items) {
            return null;
        }

        return [
            'text'     => $this->text($chatId, $items),
            'keyboard' => $this->keyboard($chatId, $items, $fromId, $toId),
        ];
    }

    /**
     * Ряд «переложить в другую категорию»: частые категории пользователя
     * плюс «…» на полный список.
     *
     * @param int $chatId
     * @param int $expenseId
     * @param string $current Текущая категория траты — её не предлагаем
     * @param int $fromId
     * @param int $toId
     * @return array Ряд кнопок для InlineKeyboardMarkup
     */
    public function alternativesRow(int $chatId, int $expenseId, string $current, int $fromId, int $toId): array
    {
        $buttons = [];

        foreach ($this->expenseModel->getTopCategories($chatId, self::ALTERNATIVES + 1) as $category) {
            if (count($buttons) >= self::ALTERNATIVES || $category === $current) {
                continue;
            }

            $button = $this->categoryButton($expenseId, $category, $fromId, $toId, '→ ' . $category);
            if ($button !== null) {
                $buttons[] = $button;
            }
        }

        $buttons[] = ['text' => '…', 'callback_data' => "a:{$expenseId}:{$fromId}:{$toId}"];

        return $buttons;
    }

    /**
     * Кнопка назначения категории.
     *
     * @return array|null null, если название категории не влезает в callback_data
     */
    public function categoryButton(int $expenseId, string $category, int $fromId, int $toId, string $label): ?array
    {
        $data = "s:{$expenseId}:{$fromId}:{$toId}:{$category}";

        if (strlen($data) > self::CALLBACK_LIMIT) {
            return null;
        }

        return ['text' => $label, 'callback_data' => $data];
    }

    /**
     * Одна строка на трату плюс итог за 30 дней. Разбивка по категориям
     * намеренно убрана: подтверждение читают полсекунды, детали — в приложении.
     *
     * @param int $chatId
     * @param array $items
     * @return string
     */
    private function text(int $chatId, array $items): string
    {
        $today = date('Y-m-d');
        $lines = [];

        foreach ($items as $item) {
            $ts = strtotime($item['ts']);
            // Дату показываем, только если трата не сегодняшняя (переслали чек за вчера).
            $when = date('Y-m-d', $ts) !== $today ? ' · ' . date('d.m', $ts) : '';

            $lines[] = '✅ ' . $item['name'] . ' ' . $this->money((float)$item['amount'])
                . ' · ' . $item['category'] . $when;
        }

        $total = $this->expenseModel->getTotalLast30Days($chatId);
        $globalLimit = $this->limitModel->getGlobal($chatId);

        $lines[] = '';
        $lines[] = 'За 30 дней: ' . $this->money($total)
            . ($globalLimit !== null ? ' / ' . $this->money($globalLimit) : '');

        foreach ($this->warnings($chatId, $items, $total, $globalLimit) as $warning) {
            $lines[] = $warning;
        }

        return implode("\n", $lines);
    }

    /**
     * @param int $chatId
     * @param array $items
     * @param int $fromId
     * @param int $toId
     * @return InlineKeyboardMarkup
     */
    private function keyboard(int $chatId, array $items, int $fromId, int $toId): InlineKeyboardMarkup
    {
        $rows = [[[
            'text'          => count($items) > 1 ? '↩️ Отменить всё' : '↩️ Отменить',
            'callback_data' => "u:{$fromId}:{$toId}",
        ]]];

        if (count($items) === 1) {
            // Одна трата — альтернативы сразу, без промежуточного нажатия.
            $rows[] = $this->alternativesRow($chatId, (int)$items[0]['id'], $items[0]['category'], $fromId, $toId);

            return new InlineKeyboardMarkup($rows);
        }

        foreach ($items as $item) {
            $rows[] = [[
                'text'          => '✏️ ' . mb_substr($item['name'], 0, 20) . ' · ' . $item['category'],
                'callback_data' => "c:{$item['id']}:{$fromId}:{$toId}",
            ]];
        }

        return new InlineKeyboardMarkup($rows);
    }

    /**
     * Предупреждения по лимитам — единственная часть отчёта, ради которой
     * стоит прерывать пользователя сразу после ввода.
     *
     * @param int $chatId
     * @param array $items
     * @param float $total Сумма за 30 дней, уже посчитанная для итоговой строки
     * @param float|null $globalLimit
     * @return string[]
     */
    private function warnings(int $chatId, array $items, float $total, ?float $globalLimit): array
    {
        $warnings = [];

        foreach (array_unique(array_column($items, 'category')) as $category) {
            $limit = $this->limitModel->get($chatId, $category);
            if ($limit === null) {
                continue;
            }

            $spent = $this->expenseModel->getMonthlyTotal($chatId, $category);
            if ($spent >= $limit) {
                $warnings[] = "🔴 Превышение «{$category}»: {$this->money($spent)} / {$this->money($limit)}";
            } elseif ($spent >= $limit * 0.8) {
                $warnings[] = "🟡 Внимание «{$category}»: {$this->money($spent)} / {$this->money($limit)}";
            }
        }

        if ($globalLimit !== null && $total >= $globalLimit) {
            $warnings[] = '🔴 Превышение общего лимита';
        } elseif ($globalLimit !== null && $total >= $globalLimit * 0.8) {
            $warnings[] = '🟡 Общий лимит на исходе';
        }

        return $warnings;
    }

    /**
     * @param float $value
     * @return string
     */
    private function money(float $value): string
    {
        return number_format($value, fmod($value, 1) == 0.0 ? 0 : 2, ',', ' ');
    }
}
