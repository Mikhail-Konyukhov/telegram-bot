<?php

namespace App;

use App\Controllers\Handlers\SetLimitHandler;
use App\Controllers\Handlers\SetGlobalLimitHandler;
use App\Controllers\Handlers\StartHandler;
use App\Controllers\Handlers\AppHandler;
use App\Controllers\Handlers\ExpenseHandler;
use App\Controllers\Handlers\CategoryHandler;
use App\Controllers\Handlers\CallbackHandler;
use App\Controllers\Handlers\StatsHandler;
use App\Controllers\Handlers\WebHandler;
use App\Models\User;
use App\Queue\ExpenseQueue;
use GuzzleHttp\Client as HttpClient;
use TelegramBot\Api\Client;
use TelegramBot\Api\Types\Message;
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

    /**
     * @param array|null $raw Сырой апдейт: из типов библиотеки часть полей
     *                        выпадает, см. {@see Bot::originalDate()}
     */
    public function handleUpdate(Update $update, ?array $raw = null): void
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

        // Апгрейд группы до супергруппы: Telegram выдаёт чату новый id и шлёт
        // служебное сообщение в оба чата — в старый с migrate_to_chat_id, в
        // новый с migrate_from_chat_id. Книга трат — это чат, поэтому без
        // переноса история осталась бы под прежним id. Хватило бы и одного из
        // двух сообщений, но какое дойдёт первым — не наше дело: перенос
        // идемпотентен.
        $migrateFrom = $update->getMessage()->getMigrateFromChatId();
        $migrateTo = $update->getMessage()->getMigrateToChatId();

        if ($migrateFrom !== null || $migrateTo !== null) {
            $chatId = $update->getMessage()->getChat()->getId();
            (new User())->migrate((int)($migrateFrom ?? $chatId), (int)($migrateTo ?? $chatId));
            return;
        }

        $msgText = trim($update->getMessage()->getText());

        // Кнопка клавиатуры присылает обычный текст. Подменяем его на команду,
        // и дальше работает тот же роутинг, что и для набранной вручную, —
        // отдельная ветка на каждую кнопку не нужна.
        $msgText = StartHandler::CONTROLS[$msgText] ?? $msgText;

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

        // Те же экраны в браузере: Mini App живёт только внутри Telegram.
        if (stripos($msgText, '/web') === 0) {
            (new WebHandler($this->tg))->handle($update);
            return;
        }

        if (stripos($msgText, '/stats') === 0) {
            (new StatsHandler($this->tg))->handle($update);
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
        $this->queueExpense($update, $msgText, $raw);
    }

    /**
     * Дата, под которой трата попадёт в учёт.
     *
     * У пересланного чека это день покупки, а не день пересылки — иначе
     * восстановить пропущенное перепиской с ботом невозможно.
     *
     * Bot API 7.0 убрал `forward_date` и заменил его на `forward_origin`, а
     * telegram-bot/api 7.15 знает только старое поле: `getForwardDate()` на
     * живом Telegram всегда null, и пересланные траты ложились сегодняшним
     * числом. Новое поле читается из сырого апдейта — `Message` молча
     * выбрасывает всё, чего нет в его карте полей. Старое оставлено запасным.
     */
    private static function originalDate(Message $message, ?array $raw): string
    {
        $forwarded = $raw['message']['forward_origin']['date'] ?? $message->getForwardDate();

        return date('Y-m-d H:i:s', $forwarded ?? $message->getDate());
    }

    /**
     * Ставит трату в очередь, а при недоступности брокера разбирает на месте.
     *
     * Откат на синхронную обработку нужен не для красоты: без него падение
     * RabbitMQ означало бы молча съеденные траты — пользователь отправил
     * сообщение, бот ответил Telegram «200» и забыл про него.
     */
    private function queueExpense(Update $update, string $text, ?array $raw = null): void
    {
        $message = $update->getMessage();

        try {
            (new ExpenseQueue())->publish([
                'update_id' => $update->getUpdateId(),
                'chat_id'   => $message->getChat()->getId(),
                'text'      => $text,
                'date'      => self::originalDate($message, $raw),
            ]);
        } catch (\Throwable $e) {
            error_log('Очередь недоступна, обрабатываю синхронно: ' . $e->getMessage());
            (new ExpenseHandler($this->tg, $this->http, $this->geminiApiKey))->handle($update);
        }
    }
}
