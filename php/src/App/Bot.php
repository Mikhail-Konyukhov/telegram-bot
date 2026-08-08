<?php

namespace App;

use App\Controllers\Handlers\SetLimitHandler;
use App\Controllers\Handlers\SetGlobalLimitHandler;
use App\Controllers\Handlers\StartHandler;
use App\Controllers\Handlers\AppHandler;
use App\Controllers\Handlers\ExpenseHandler;
use App\Controllers\Handlers\CategoryHandler;
use App\Controllers\Handlers\CallbackHandler;
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
        $this->http = new HttpClient(['timeout' => $this->httpTimout]);
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

        // По умолчанию — ExpenseHandler (обычные сообщения)
        (new ExpenseHandler($this->tg, $this->http, $this->geminiApiKey))->handle($update);
    }
}
