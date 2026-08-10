<?php

namespace App\Queue;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Очередь на разбор трат: подключение, топология, публикация и приём.
 *
 * Зачем очередь вообще: ключ Gemini один на всех пользователей, и лимит у него
 * на ключ. Из независимых процессов вебхука темп обращений не ограничить никак —
 * а один потребитель, тянущий сообщения по одному, ограничивает его естественно.
 * Вторым эффектом появляются отложенные повторы, невозможные внутри вебхука:
 * Telegram не дожидался ответа и присылал апдейт заново.
 *
 * Параметры подключения читаются из окружения — как в {@see \App\Database},
 * а не через {@see \App\Cfg}: это инфраструктура, а не настройки приложения.
 */
class ExpenseQueue
{
    /** Рабочая очередь */
    public const MAIN = 'expenses';

    /** Отстойник: сюда сообщение попадает после отказа и лежит RETRY_TTL_MS */
    public const RETRY = 'expenses.retry';

    /** Окончательные отказы, разбор руками */
    public const DEAD = 'expenses.dead';

    /** Дефолтный обмен маршрутизирует по имени очереди — свой заводить незачем */
    private const EXCHANGE = '';

    /** Пауза между попытками */
    private const RETRY_TTL_MS = 60000;

    private AMQPStreamConnection $connection;
    private AMQPChannel $channel;
    private ?string $consumerTag = null;

    public function __construct()
    {
        $this->connection = new AMQPStreamConnection(
            getenv('RABBITMQ_HOST') ?: 'rabbitmq',
            (int)(getenv('RABBITMQ_PORT') ?: 5672),
            getenv('RABBITMQ_USER') ?: 'guest',
            getenv('RABBITMQ_PASS') ?: 'guest',
            '/',
            false,
            'AMQPLAIN',
            null,
            'en_US',
            // Публикатор сидит внутри вебхука: если брокер лежит, отказ нужен
            // быстрый, чтобы успеть обработать трату синхронно.
            3.0,
            // read_write_timeout вдвое больше heartbeat — требование php-amqplib.
            60.0,
            null,
            // Воркер висит на этом соединении сутками: без keepalive и heartbeat
            // его тихо рвёт NAT, и потребитель перестаёт получать сообщения,
            // не замечая этого.
            true,
            30
        );

        $this->channel = $this->connection->channel();
        $this->declareQueues();
    }

    /**
     * Отложенный повтор без плагинов: отказ обработчика отправляет сообщение
     * в отстойник, оттуда по истечении TTL оно само возвращается в работу.
     * Пауза получается на стороне брокера, а не через sleep внутри воркера —
     * спящий воркер не разбирал бы очередь.
     */
    private function declareQueues(): void
    {
        $this->channel->queue_declare(self::MAIN, false, true, false, false, false, new AMQPTable([
            'x-dead-letter-exchange'    => self::EXCHANGE,
            'x-dead-letter-routing-key' => self::RETRY,
        ]));

        $this->channel->queue_declare(self::RETRY, false, true, false, false, false, new AMQPTable([
            'x-message-ttl'             => self::RETRY_TTL_MS,
            'x-dead-letter-exchange'    => self::EXCHANGE,
            'x-dead-letter-routing-key' => self::MAIN,
        ]));

        $this->channel->queue_declare(self::DEAD, false, true, false, false, false);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function publish(array $payload, string $queue = self::MAIN): void
    {
        $message = new AMQPMessage(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            [
                'content_type' => 'application/json',
                // Иначе очередь теряет содержимое при перезапуске брокера.
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ]
        );

        $this->channel->basic_publish($message, self::EXCHANGE, $queue);
    }

    /**
     * Приём сообщений. Подтверждение — забота обработчика: только он знает,
     * стоит ли повторять.
     *
     * @param callable(AMQPMessage): void $handle
     */
    public function consume(callable $handle): void
    {
        // Одно сообщение за раз. На этом же держится лимит темпа обращений
        // к Gemini: параллельной обработки нет, значит достаточно выдерживать
        // паузу в самом воркере.
        $this->channel->basic_qos(0, 1, false);
        $this->consumerTag = $this->channel->basic_consume(
            self::MAIN,
            '',
            false,
            false,
            false,
            false,
            $handle
        );

        while ($this->channel->is_consuming()) {
            $this->channel->wait();
        }
    }

    /**
     * Прекращает приём новых сообщений; текущее обработчик успевает доработать.
     */
    public function stop(): void
    {
        if ($this->consumerTag !== null) {
            $this->channel->basic_cancel($this->consumerTag);
            $this->consumerTag = null;
        }
    }

    public function close(): void
    {
        $this->channel->close();
        $this->connection->close();
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(AMQPMessage $message): array
    {
        $data = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : [];
    }

    /**
     * Какая это попытка по счёту. Считать самим не нужно: RabbitMQ ведёт счётчик
     * отказов в заголовке `x-death` и переносит его между очередями.
     */
    public static function attempt(AMQPMessage $message): int
    {
        $headers = $message->get_properties()['application_headers'] ?? null;

        if (!$headers instanceof AMQPTable) {
            return 1;
        }

        $death = $headers->getNativeData()['x-death'][0]['count'] ?? 0;

        return 1 + (int)$death;
    }
}
