<?php

namespace App\Controllers\Handlers;

use App\Models\Category;
use App\Models\CategoryHint;
use App\Models\Expense;
use App\Services\ExpenseConfirmation;
use TelegramBot\Api\Client;
use TelegramBot\Api\Types\CallbackQuery;
use TelegramBot\Api\Types\Inline\InlineKeyboardMarkup;

/**
 * Обрабатывает нажатия кнопок под подтверждением траты.
 *
 * Формат callback_data описан в {@see ExpenseConfirmation}. Выбор категории
 * подменяет только клавиатуру, но не текст сообщения: подтверждение с суммами
 * должно оставаться на экране, пока пользователь выбирает.
 */
class CallbackHandler
{
    private Client $tg;
    private Expense $expenseModel;
    private Category $categoryModel;
    private CategoryHint $hints;
    private ExpenseConfirmation $confirmation;

    public function __construct(Client $tg)
    {
        $this->tg = $tg;
        $this->expenseModel = new Expense();
        $this->categoryModel = new Category();
        $this->hints = new CategoryHint();
        $this->confirmation = new ExpenseConfirmation();
    }

    /**
     * @param CallbackQuery $callbackQuery
     * @return void
     */
    public function handle(CallbackQuery $callbackQuery): void
    {
        $callbackId = $callbackQuery->getId();
        $message = $callbackQuery->getMessage();

        // Сообщений старше 48 часов Telegram уже не присылает — редактировать
        // нечего, но ответить на нажатие всё равно нужно.
        if ($message === null) {
            $this->tg->answerCallbackQuery($callbackId, 'Сообщение устарело');
            return;
        }

        // limit=5: название категории может содержать двоеточие и должно
        // остаться целым в последнем элементе.
        $parts = explode(':', $callbackQuery->getData(), 5);
        $chatId = $message->getChat()->getId();
        $messageId = $message->getMessageId();

        switch ($parts[0]) {
            case 'u':
                $this->undo($chatId, $messageId, (int)$parts[1], (int)$parts[2], $callbackId);
                break;

            case 'b':
                $this->tg->answerCallbackQuery($callbackId);
                $this->redraw($chatId, $messageId, (int)$parts[1], (int)$parts[2]);
                break;

            case 'c':
                $this->showCategories($chatId, $messageId, (int)$parts[1], (int)$parts[2], (int)$parts[3], false, $callbackId);
                break;

            case 'a':
                $this->showCategories($chatId, $messageId, (int)$parts[1], (int)$parts[2], (int)$parts[3], true, $callbackId);
                break;

            case 's':
                $this->setCategory($chatId, $messageId, (int)$parts[1], (int)$parts[2], (int)$parts[3], $parts[4], $callbackId);
                break;

            default:
                // Кнопка из сообщения, отправленного прошлой версией бота. Без
                // ответа Telegram крутит спиннер на кнопке до таймаута.
                $this->tg->answerCallbackQuery($callbackId, 'Кнопка устарела');
        }
    }

    /**
     * Удаляет только что добавленные траты и гасит подтверждение.
     *
     * @param int $chatId
     * @param int $messageId
     * @param int $fromId
     * @param int $toId
     * @param string $callbackId
     * @return void
     */
    private function undo(int $chatId, int $messageId, int $fromId, int $toId, string $callbackId): void
    {
        if ($this->expenseModel->deleteRange($chatId, $fromId, $toId) === 0) {
            $this->tg->answerCallbackQuery($callbackId, 'Уже удалено');
            return;
        }

        $this->tg->answerCallbackQuery($callbackId, '↩️ Отменено');

        // Исходное сообщение пользователя осталось выше в чате, поэтому текст
        // траты здесь можно не сохранять — повторить ввод легко.
        $this->edit($chatId, $messageId, '↩️ Отменено', null);
    }

    /**
     * Показывает категории для смены: либо частые плюс «…», либо все.
     *
     * @param int $chatId
     * @param int $messageId
     * @param int $expenseId
     * @param int $fromId
     * @param int $toId
     * @param bool $all Показать полный список
     * @param string $callbackId
     * @return void
     */
    private function showCategories(int $chatId, int $messageId, int $expenseId, int $fromId, int $toId, bool $all, string $callbackId): void
    {
        $expense = $this->expenseModel->getById($expenseId, $chatId);

        if (!$expense) {
            $this->tg->answerCallbackQuery($callbackId, 'Трата не найдена', true);
            return;
        }

        $this->tg->answerCallbackQuery($callbackId);

        if (!$all) {
            $rows = [
                $this->confirmation->alternativesRow($chatId, $expenseId, $expense['category'], $fromId, $toId),
                [['text' => '« Назад', 'callback_data' => "b:{$fromId}:{$toId}"]],
            ];

            $this->replaceKeyboard($chatId, $messageId, $rows);
            return;
        }

        $rows = [];
        foreach ($this->categoryModel->getUserCategories($chatId) as $category) {
            if ($category === $expense['category']) {
                continue;
            }

            $button = $this->confirmation->categoryButton($expenseId, $category, $fromId, $toId, $category);
            if ($button !== null) {
                $rows[] = [$button];
            }
        }

        $rows[] = [['text' => '« Назад', 'callback_data' => "c:{$expenseId}:{$fromId}:{$toId}"]];

        $this->replaceKeyboard($chatId, $messageId, $rows);
    }

    /**
     * Переносит трату в выбранную категорию и перерисовывает подтверждение.
     *
     * @param int $chatId
     * @param int $messageId
     * @param int $expenseId
     * @param int $fromId
     * @param int $toId
     * @param string $category
     * @param string $callbackId
     * @return void
     */
    private function setCategory(int $chatId, int $messageId, int $expenseId, int $fromId, int $toId, string $category, string $callbackId): void
    {
        $expense = $this->expenseModel->getById($expenseId, $chatId);

        if (!$expense) {
            $this->tg->answerCallbackQuery($callbackId, 'Трата не найдена', true);
            return;
        }

        $updated = $this->expenseModel->update(
            $expenseId,
            $chatId,
            $expense['name'],
            $category,
            (float)$expense['amount'],
            $expense['ts']
        );

        if (!$updated) {
            $this->tg->answerCallbackQuery($callbackId, '❌ Не удалось обновить', true);
            return;
        }

        // Правка пользователя — самый достоверный источник категории,
        // поэтому она перекрывает то, что стояло в словаре.
        $this->hints->remember($chatId, $expense['name'], $category);

        $this->tg->answerCallbackQuery($callbackId, "✅ {$expense['name']} → {$category}");
        $this->redraw($chatId, $messageId, $fromId, $toId);
    }

    /**
     * Перерисовывает подтверждение по текущему состоянию базы.
     *
     * @param int $chatId
     * @param int $messageId
     * @param int $fromId
     * @param int $toId
     * @return void
     */
    private function redraw(int $chatId, int $messageId, int $fromId, int $toId): void
    {
        $view = $this->confirmation->render($chatId, $fromId, $toId);

        if ($view !== null) {
            $this->edit($chatId, $messageId, $view['text'], $view['keyboard']);
        }
    }

    /**
     * @param int $chatId
     * @param int $messageId
     * @param array $rows
     * @return void
     */
    private function replaceKeyboard(int $chatId, int $messageId, array $rows): void
    {
        try {
            $this->tg->editMessageReplyMarkup($chatId, $messageId, new InlineKeyboardMarkup($rows));
        } catch (\Exception $e) {
            // «message is not modified» — не ошибка с точки зрения пользователя.
        }
    }

    /**
     * @param int $chatId
     * @param int $messageId
     * @param string $text
     * @param InlineKeyboardMarkup|null $keyboard
     * @return void
     */
    private function edit(int $chatId, int $messageId, string $text, ?InlineKeyboardMarkup $keyboard): void
    {
        try {
            $this->tg->editMessageText($chatId, $messageId, $text, null, false, $keyboard);
        } catch (\Exception $e) {
            // См. выше.
        }
    }
}
