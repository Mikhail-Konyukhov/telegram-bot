<?php

namespace App\Controllers\Handlers;

use App\Models\Expense;
use App\Models\Category;
use TelegramBot\Api\Client;
use TelegramBot\Api\Types\CallbackQuery;

/**
 * Обрабатывает callback запросы от inline кнопок
 */
class CallbackHandler
{
    private Client $tg;
    private Expense $expenseModel;
    private Category $categoryModel;

    public function __construct(Client $tg)
    {
        $this->tg = $tg;
        $this->expenseModel = new Expense();
        $this->categoryModel = new Category();
    }

    /**
     * Обрабатывает callback query
     *
     * @param CallbackQuery $callbackQuery
     * @return void
     */
    public function handle(CallbackQuery $callbackQuery): void
    {
        $data = $callbackQuery->getData();
        $chatId = $callbackQuery->getMessage()->getChat()->getId();
        $messageId = $callbackQuery->getMessage()->getMessageId();

        // Обработка запроса на смену категории
        if (strpos($data, 'change_cat:') === 0) {
            $expenseId = (int)substr($data, strlen('change_cat:'));
            $this->showCategorySelection($chatId, $messageId, $expenseId, $callbackQuery->getId());
        }
        // Обработка выбора новой категории
        elseif (strpos($data, 'set_cat:') === 0) {
            $parts = explode(':', $data);
            $expenseId = (int)$parts[1];
            $newCategory = $parts[2];
            $this->updateExpenseCategory($chatId, $messageId, $expenseId, $newCategory, $callbackQuery->getId());
        }
    }

    /**
     * Показывает список категорий для выбора
     *
     * @param int $chatId
     * @param int $messageId
     * @param int $expenseId
     * @param string $callbackId
     * @return void
     */
    private function showCategorySelection(int $chatId, int $messageId, int $expenseId, string $callbackId): void
    {
        // Получаем трату для проверки доступа
        $expense = $this->expenseModel->getById($expenseId, $chatId);
        
        if (!$expense) {
            $this->tg->answerCallbackQuery($callbackId, 'Трата не найдена');
            return;
        }

        // Получаем категории пользователя
        $categories = $this->categoryModel->getUserCategories($chatId);
        
        // Создаем кнопки с категориями
        $buttons = [];
        foreach ($categories as $cat) {
            $buttons[] = [
                [
                    'text' => $cat,
                    'callback_data' => "set_cat:{$expenseId}:{$cat}"
                ]
            ];
        }
        
        // Добавляем кнопку "Назад"
        $buttons[] = [
            [
                'text' => '« Назад',
                'callback_data' => "back_to_expenses"
            ]
        ];
        
        $keyboard = new \TelegramBot\Api\Types\Inline\InlineKeyboardMarkup($buttons);
        
        $text = "Выберите новую категорию для траты:\n\n" .
                "📝 {$expense['name']}\n" .
                "💰 {$expense['amount']} ₽\n" .
                "📂 Текущая категория: {$expense['category']}";
        
        $this->tg->editMessageText($chatId, $messageId, $text, null, false, $keyboard);
        $this->tg->answerCallbackQuery($callbackId);
    }

    /**
     * Обновляет категорию траты
     *
     * @param int $chatId
     * @param int $messageId
     * @param int $expenseId
     * @param string $newCategory
     * @param string $callbackId
     * @return void
     */
    private function updateExpenseCategory(int $chatId, int $messageId, int $expenseId, string $newCategory, string $callbackId): void
    {
        // Получаем трату
        $expense = $this->expenseModel->getById($expenseId, $chatId);
        
        if (!$expense) {
            $this->tg->answerCallbackQuery($callbackId, 'Трата не найдена', true);
            return;
        }

        // Обновляем категорию
        $success = $this->expenseModel->update(
            $expenseId,
            $chatId,
            $expense['name'],
            $newCategory,
            (float)$expense['amount'],
            $expense['ts']
        );

        if ($success) {
            // Показываем короткое уведомление
            $this->tg->answerCallbackQuery($callbackId, "✅ {$expense['name']} → {$newCategory}", true);
            
            // Удаляем inline-клавиатуру, оставляя исходное сообщение
            try {
                $this->tg->editMessageReplyMarkup($chatId, $messageId, null);
            } catch (\Exception $e) {
                // Игнорируем ошибку если сообщение не изменилось
            }
        } else {
            $this->tg->answerCallbackQuery($callbackId, '❌ Ошибка при обновлении', true);
        }
    }
}

