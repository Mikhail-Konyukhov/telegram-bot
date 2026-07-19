<?php

namespace App;

use App\Controllers\Handlers\SetLimitHandler;
use App\Controllers\Handlers\SetGlobalLimitHandler;
use App\Controllers\Handlers\StartHandler;
use App\Controllers\Handlers\DashboardHandler;
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
    private string $classifierUrl;
    private string $dashboardUrl;

    public function __construct()
    {
        $cfg = new Cfg();
        $this->classifierUrl   = $cfg->getClassifierUrl();
        $this->dashboardUrl    = $cfg->getDashboardUrl();
        $token                 = $cfg->getTelegramBotToken();

        if (empty($token)) {
            throw new \RuntimeException('TELEGRAM_BOT_TOKEN not set');
        }
        if (empty($this->classifierUrl)) {
            throw new \RuntimeException('CLASSIFIER_URL not set');
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

        if (stripos($msgText, '/dashboard') === 0) {
            (new DashboardHandler($this->tg))->handle($update);
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
        
        error_log($update->toJson(true));

        // По умолчанию — ExpenseHandler (обычные сообщения)
        (new ExpenseHandler($this->tg, $this->http, $this->classifierUrl))->handle($update);
    }

    /**
     * Отправляет POST запрос с JSON и возвращает ответ в виде массива
     *
     * @param string $url
     * @param array $data
     * @return array
     * @throws \Exception
     */
    function postJson(string $url, array $data): array
    {
        $payload = json_encode($data);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);

        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \Exception("Curl error: $err");
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            throw new \Exception("HTTP error status: $status");
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            throw new \Exception("Ошибка декодирования JSON");
        }

        return $decoded;
    }

}
