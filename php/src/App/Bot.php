<?php

namespace App;

use App\Controllers\Handlers\SetLimitHandler;
use App\Controllers\Handlers\SetGlobalLimitHandler;
use App\Controllers\Handlers\StartHandler;
use App\Controllers\Handlers\AppHandler;
use App\Controllers\Handlers\ExpenseHandler;
use App\Controllers\Handlers\CategoryHandler;
use App\Controllers\Handlers\CallbackHandler;
use App\Queue\ExpenseQueue;
use GuzzleHttp\Client as HttpClient;
use TelegramBot\Api\Client;
use TelegramBot\Api\Types\Update;
use App\Cfg;

class Bot
{
    private Client $tg;
    private HttpClient $http;
    private int $httpTimout = 20;
    private string $geminiApiKey;

    public function __construct()
    {
        $cfg = new Cfg();
        $this->geminiApiKey    = $cfg->getGeminiApiKey();
        $token                 = $cfg->getTelegramBotToken();

        if (empty($token)) {
            throw new \RuntimeException('TELEGRAM_BOT_TOKEN not set');
        }
        if (empty($this->geminiApiKey)) {
            throw new \RuntimeException('GEMINI_API_KEY not set');
        }

        $this->tg = new Client($token);
        $this->http = new HttpClient([
            'timeout' => $this->httpTimout,
            // connect_timeout у Guzzle по умолчанию 0 — без ограничения. Зависший
            // TCP-connect к Gemini держал бы процесс бесконечно, а в воркере это
            // означало бы вставшую очередь.
            'connect_timeout' => 5,
        ]);
    }

    public function handleUpdate(Update $update): void
    {
        // Обработка callback-запросов от inline кнопок
        if ($update->getCallbackQuery() !== null) {
            (new CallbackHandler($this->tg))->handle($update->getCallbackQuery());
            return;
        }

        // Проверяем, есть ли сообщение
        if ($update->getMessage() === null) {
            return;
        }

        $msgText = trim($update->getMessage()->getText());

        // Вызов соответствующего Handler в зависимости от команды
        if (stripos($msgText, '/start') === 0 || stripos($msgText, '/help') === 0) {
            (new StartHandler($this->tg))->handle($update);
            return;
        }

        // /dashboard оставлен алиасом — ссылка на него могла остаться у пользователя в истории
        if (stripos($msgText, '/app') === 0 || stripos($msgText, '/dashboard') === 0) {
            (new AppHandler($this->tg))->handle($update);
            return;
        }

        if (stripos($msgText, '/setgloballimit') === 0) {
            (new SetGlobalLimitHandler($this->tg))->handle($update);
            return;
        }

        if (stripos($msgText, '/setlimit') === 0) {
            (new SetLimitHandler($this->tg))->handle($update);
            return;
        }

        if (stripos($msgText, '/categories') === 0) {
            (new CategoryHandler($this->tg))->handle($update);
            return;
        }

        // По умолчанию — трата. В отличие от команд выше, её разбор ходит в
        // Gemini, поэтому сообщение уезжает в очередь: вебхук должен ответить
        // Telegram сразу, иначе тот присылает апдейт заново.
        $this->queueExpense($update, $msgText);
    }

    /**
     * Ставит трату в очередь, а при недоступности брокера разбирает на месте.
     *
     * Откат на синхронную обработку нужен не для красоты: без него падение
     * RabbitMQ означало бы молча съеденные траты — пользователь отправил
     * сообщение, бот ответил Telegram «200» и забыл про него.
     */
    private function queueExpense(Update $update, string $text): void
    {
        $message = $update->getMessage();

        try {
            (new ExpenseQueue())->publish([
                'update_id' => $update->getUpdateId(),
                'chat_id'   => $message->getChat()->getId(),
                'text'      => $text,
                // Дата оригинала пересланного сообщения: в учёт должен попасть
                // день покупки, а не день пересылки чека.
                'date'      => date('Y-m-d H:i:s', $message->getForwardDate() ?? $message->getDate()),
            ]);
        } catch (\Throwable $e) {
            error_log('Очередь недоступна, обрабатываю синхронно: ' . $e->getMessage());
            (new ExpenseHandler($this->tg, $this->http, $this->geminiApiKey))->handle($update);
        }
    }
}
