<?php

namespace App\Controllers\Handlers;

use App\Models\Category;
use App\Services\ExpenseConfirmation;
use App\Services\ExpenseIntakeService;
use GuzzleHttp\Client as HttpClient;
use TelegramBot\Api\Client;
use TelegramBot\Api\Types\Update;

/**
 * Обрабатывает сообщения с расходами (по умолчанию)
 */
class ExpenseHandler
{
    private Client $tg;
    private Category $categoryModel;
    private ExpenseIntakeService $intake;
    private ExpenseConfirmation $confirmation;

    public function __construct(Client $tg, HttpClient $http, string $geminiApiKey)
    {
        $this->tg = $tg;
        $this->categoryModel = new Category();
        $this->intake = new ExpenseIntakeService($http, $geminiApiKey);
        $this->confirmation = new ExpenseConfirmation();
    }

    /**
     * Разбирает сообщение, сохраняет траты и отвечает подтверждением.
     *
     * Повторные попытки со sleep(30) убраны намеренно: они выполнялись прямо в
     * обработчике вебхука, Telegram не дожидался ответа и присылал апдейт
     * заново — трата записывалась дважды. Ретраи вернутся вместе с очередью.
     *
     * @param Update $update
     * @return void
     */
    public function handle(Update $update): void
    {
        $chatId = null;

        try {
            $message = $update->getMessage();
            $chatId = $message->getChat()->getId();

            // У пересланного сообщения берём дату оригинала: в учёт должен
            // попасть день покупки, а не день пересылки чека.
            $date = date('Y-m-d H:i:s', $message->getForwardDate() ?? $message->getDate());

            $parsed = $this->intake->parse(
                $chatId,
                trim($message->getText()),
                $this->categoryModel->getUserCategories($chatId)
            );

            if ($parsed['errors']) {
                $this->tg->sendMessage($chatId, implode("\n", $parsed['errors']));
            }

            if (!$parsed['items']) {
                return;
            }

            $ids = array_column($this->intake->save($chatId, $parsed['items'], $date), 'id');
            $view = $this->confirmation->render($chatId, min($ids), max($ids));

            if ($view === null) {
                return;
            }

            $this->tg->sendMessage($chatId, $view['text'], null, false, null, $view['keyboard']);
        } catch (\Throwable $e) {
            error_log($e->getMessage());

            if ($chatId !== null) {
                $this->tg->sendMessage($chatId, 'Ошибка: ' . $e->getMessage());
            }
        }
    }
}
