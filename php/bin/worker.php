<?php

/**
 * Потребитель очереди трат.
 *
 * Вебхук больше не разбирает сообщение сам: он публикует его в `expenses`
 * и отвечает Telegram сразу. Классификация, запись и подтверждение происходят
 * здесь. Это даёт то, чего в вебхуке не было: глобальный лимит темпа обращений
 * к Gemini (потребитель один) и отложенные повторы при временных отказах.
 *
 * Запускается контейнером `worker` из docker-compose, руками — так:
 *
 *   docker compose exec php php bin/worker.php
 *
 * Код едет из bind mount'а `./php`, но процесс долгоживущий и правки сам
 * не подхватит: после изменений нужен `docker compose restart worker`.
 */

// bin/ лежит под докрутом встроенного сервера (`php -S -t /var/www/html`),
// то есть файл доступен и по HTTP. Поднимать потребителя запросом снаружи нельзя.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

use App\Cfg;
use App\Controllers\Handlers\ExpenseHandler;
use App\Database;
use App\Models\ProcessedUpdate;
use App\Queue\ExpenseQueue;
use App\Services\GeminiUnavailable;
use GuzzleHttp\Client as HttpClient;
use PhpAmqpLib\Message\AMQPMessage;
use TelegramBot\Api\Client;

/** После стольких неудачных попыток сообщение уезжает в expenses.dead */
const MAX_ATTEMPTS = 5;

$cfg = new Cfg();
$tg = new Client($cfg->getTelegramBotToken());

$handler = new ExpenseHandler(
    $tg,
    new HttpClient(['timeout' => 20, 'connect_timeout' => 5]),
    $cfg->getGeminiApiKey()
);

$processed = new ProcessedUpdate();
$db = Database::getInstance()->getConnection();
$queue = new ExpenseQueue();

// Даём доработать текущее сообщение, а не рвём обработку на середине:
// `docker compose down` шлёт SIGTERM и ждёт десять секунд.
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);

    $shutdown = static function (int $signal) use ($queue): void {
        error_log("worker: сигнал {$signal}, дорабатываю текущее сообщение и выхожу");
        $queue->stop();
    };

    pcntl_signal(SIGTERM, $shutdown);
    pcntl_signal(SIGINT, $shutdown);
}

$rollback = static function () use ($db): void {
    if (!$db->inTransaction()) {
        return;
    }

    try {
        $db->rollBack();
    } catch (\Throwable) {
        // Соединение уже мертво — откатывать нечего и незачем шуметь.
    }
};

error_log('worker: слушаю очередь ' . ExpenseQueue::MAIN);

try {
    $queue->consume(function (AMQPMessage $message) use ($handler, $processed, $db, $queue, $tg, $rollback): void {
        $payload  = ExpenseQueue::payload($message);
        $updateId = (int)($payload['update_id'] ?? 0);
        $chatId   = (int)($payload['chat_id'] ?? 0);
        $text     = trim((string)($payload['text'] ?? ''));
        $date     = (string)($payload['date'] ?? date('Y-m-d H:i:s'));

        if ($chatId === 0 || $text === '') {
            error_log('worker: сообщение без чата или текста, выбрасываю: ' . $message->getBody());
            $message->ack();
            return;
        }

        // Повторная доставка после падения между записью и подтверждением.
        if ($updateId > 0 && $processed->isProcessed($updateId)) {
            error_log("worker: апдейт {$updateId} уже обработан, пропускаю");
            $message->ack();
            return;
        }

        $attempt = ExpenseQueue::attempt($message);

        if ($attempt > MAX_ATTEMPTS) {
            error_log("worker: апдейт {$updateId} исчерпал попытки, отправляю в " . ExpenseQueue::DEAD);
            $queue->publish($payload, ExpenseQueue::DEAD);

            // Уведомление не должно мешать подтверждению: если чат недоступен
            // (бота заблокировали, группу удалили), непойманное исключение увело бы
            // воркер в цикл «упал → сообщение вернулось → снова упал».
            try {
                $tg->sendMessage(
                    $chatId,
                    'Не удалось определить категории: сервис классификации не отвечает. '
                    . 'Попробуйте отправить трату ещё раз.'
                );
            } catch (\Throwable $e) {
                error_log('worker: не смог уведомить чат ' . $chatId . ' — ' . $e->getMessage());
            }

            $message->ack();
            return;
        }

        // Отметка об апдейте и сами траты пишутся вместе: иначе остаётся окно,
        // в котором траты уже записаны, а апдейт ещё не помечен, и повторная
        // доставка запишет их второй раз.
        $db->beginTransaction();

        try {
            $handler->process($chatId, $text, $date);

            if ($updateId > 0) {
                $processed->mark($updateId);
            }

            $db->commit();
        } catch (GeminiUnavailable $e) {
            $rollback();
            error_log("worker: попытка {$attempt} не удалась — " . $e->getMessage());
            // requeue = false: сообщение уходит в отстойник и вернётся по TTL.
            $message->nack(false);
            return;
        } catch (\PDOException $e) {
            // База отвалилась (классика — «server has gone away» после простоя).
            // Подтверждать нельзя: трата не записана. Выходим, контейнер
            // перезапустится по restart: unless-stopped, сообщение вернётся.
            $rollback();
            throw $e;
        } catch (\Throwable $e) {
            // Всё прочее повторять бессмысленно — ExpenseHandler уже написал
            // пользователю, что пошло не так.
            $rollback();
            error_log('worker: ' . $e->getMessage());
            $message->ack();
            return;
        }

        $message->ack();
    });
} catch (\Throwable $e) {
    error_log('worker: остановлен — ' . $e->getMessage());
    $queue->close();
    exit(1);
}

$queue->close();
error_log('worker: завершился штатно');
