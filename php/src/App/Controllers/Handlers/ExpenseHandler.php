<?php

namespace App\Controllers\Handlers;

use App\Models\Category;
use App\Models\User;
use App\Services\ExpenseConfirmation;
use App\Services\ExpenseIntakeService;
use App\Services\GeminiUnavailable;
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
    private User $userModel;
    private ExpenseIntakeService $intake;
    private ExpenseConfirmation $confirmation;

    public function __construct(Client $tg, HttpClient $http, string $geminiApiKey)
    {
        $this->tg = $tg;
        $this->categoryModel = new Category();
        $this->userModel = new User();
        $this->intake = new ExpenseIntakeService($http, $geminiApiKey);
        $this->confirmation = new ExpenseConfirmation();
    }

    /**
     * Достаёт из апдейта то, что нужно обработке, и передаёт в {@see process()}.
     *
     * Обычный путь — очередь: {@see \App\Queue\ExpenseQueue} публикует те же три
     * значения, а `process()` вызывает воркер. Этот метод остаётся для отката на
     * синхронную обработку, когда брокер недоступен (см. Bot::handleUpdate).
     */
    public function handle(Update $update): void
    {
        $message = $update->getMessage();

        $this->process(
            $message->getChat()->getId(),
            trim($message->getText()),
            // У пересланного сообщения берём дату оригинала: в учёт должен
            // попасть день покупки, а не день пересылки чека.
            date('Y-m-d H:i:s', $message->getForwardDate() ?? $message->getDate())
        );
    }

    /**
     * Разбирает текст, сохраняет траты и отвечает подтверждением.
     *
     * Повторные попытки со sleep(30) убраны намеренно: они выполнялись прямо в
     * обработчике вебхука, Telegram не дожидался ответа и присылал апдейт
     * заново — трата записывалась дважды. Теперь ретраями занимается очередь,
     * поэтому {@see GeminiUnavailable} отсюда выходит наружу, а не глотается:
     * по ней воркер понимает, что сообщение надо отложить и повторить.
     */
    public function process(int $chatId, string $text, string $date): void
    {
        try {
            // В группе владелец книги — сам чат, и /start ему никто не отправлял.
            // Без этой строки первая же трата упала бы на внешнем ключе.
            $this->userModel->ensure($chatId);

            $parsed = $this->intake->parse(
                $chatId,
                $text,
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
        } catch (GeminiUnavailable $e) {
            // Единственная ошибка, которую здесь гасить нельзя: на ней держатся
            // отложенные повторы. Ничего ещё не сохранено — parse() падает до save().
            throw $e;
        } catch (\Throwable $e) {
            error_log($e->getMessage());
            $this->tg->sendMessage($chatId, 'Ошибка: ' . $e->getMessage());
        }
    }
}
