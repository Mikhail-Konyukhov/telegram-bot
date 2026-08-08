<?php

namespace App\Controllers\Handlers;

use App\Cfg;
use App\Models\Expense;
use App\Models\User;
use TelegramBot\Api\Client;
use TelegramBot\Api\Types\ReplyKeyboardMarkup;
use TelegramBot\Api\Types\Update;

/**
 * Обрабатывает команды /start и /help
 */
class StartHandler
{
    /** Сколько частых позиций выносить на клавиатуру быстрого ввода. */
    private const QUICK_ITEMS = 6;

    private Client $tg;
    private User $userModel;
    private Expense $expenseModel;
    private string $webAppUrl;

    public function __construct(Client $tg)
    {
        $this->tg = $tg;
        $this->userModel = new User();
        $this->expenseModel = new Expense();
        $this->webAppUrl = (new Cfg())->getWebAppUrl();
    }

    /**
     * Регистрирует пользователя при первом обращении и показывает приветствие.
     *
     * @param Update $update
     * @return void
     */
    public function handle(Update $update): void
    {
        $chatId = $update->getMessage()->getChat()->getId();

        if (!$this->userModel->exists($chatId)) {
            try {
                $this->userModel->register($chatId);
            } catch (\Exception $e) {
                error_log($e->getMessage());
                $this->tg->sendMessage($chatId, '❌ Произошла ошибка при регистрации.');
                return;
            }
        }

        $quick = $this->quickKeyboard($chatId);

        $message = "👋 Бот учёта расходов.\n\n"
            . "💡 Отправляйте траты обычным сообщением:\n"
            . "• кофе 200\n"
            . "• такси 300, обед 450\n"
            . "Категория определится автоматически.\n\n"
            . ($quick !== null
                ? "⚡ Частые траты — на клавиатуре под полем ввода, одно нажатие.\n"
                    . "Приложение — кнопка «Меню» слева от поля или /app.\n\n"
                : '')
            . "📋 Команды:\n"
            . "/app — открыть приложение\n"
            . "/categories — управление категориями\n"
            . "/setlimit — лимит по категории\n"
            . "/setgloballimit — общий лимит";

        // Одно сообщение несёт одну разметку, поэтому новичок получает кнопку
        // приложения, а тот, у кого уже есть история, — быстрый ввод.
        $markup = $quick !== null ? $quick : AppHandler::button($this->webAppUrl);

        $this->tg->sendMessage($chatId, $message, null, false, null, $markup);
    }

    /**
     * Клавиатура быстрого ввода из частых позиций пользователя.
     *
     * Нажатие отправляет обычный текст «кофе 200» — ровно то, что пользователь
     * набрал бы руками, поэтому ExpenseHandler разбирает его без изменений,
     * а категория берётся из словаря подсказок, минуя модель.
     *
     * ponytail: перестраивается только на /start — привычки меняются медленно,
     * отдельная команда обновления никому не нужна.
     *
     * @param int $chatId
     * @return ReplyKeyboardMarkup|null null, если истории ещё нет
     */
    private function quickKeyboard(int $chatId): ?ReplyKeyboardMarkup
    {
        $buttons = [];

        foreach ($this->expenseModel->getFrequentNames($chatId, self::QUICK_ITEMS) as $item) {
            $buttons[] = $item['name'] . ' ' . (int)round((float)$item['avg_amount']);
        }

        if (!$buttons) {
            return null;
        }

        return new ReplyKeyboardMarkup(
            array_chunk($buttons, 2),
            null,
            true,
            null,
            true,
            'Трата или сумма'
        );
    }
}
