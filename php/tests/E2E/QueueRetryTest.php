<?php

namespace App\Tests\E2E;

use App\Queue\ExpenseQueue;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;

/**
 * Цепочка отложенных повторов против живого RabbitMQ.
 *
 * Повторы сделаны без плагинов, одной топологией: отказ обработчика отправляет
 * сообщение в отстойник, оттуда по истечении TTL оно само возвращается в работу.
 * Пауза получается на стороне брокера, а не через sleep в воркере — спящий
 * воркер не разбирал бы очередь.
 *
 * Проверять это руками дорого: на боевом TTL один круг занимает минуту, а кругов
 * пять. Тест поднимает свою топологию с префиксом `test.` и TTL в секунду, поэтому
 * рабочие очереди не задеваются, а весь путь до dead-letter проходит за пару секунд.
 */
class QueueRetryTest extends TestCase
{
    /** Приставка к именам очередей: рабочие `expenses*` должны остаться нетронутыми. */
    private const PREFIX = 'test.';

    /** Секунда вместо боевой минуты. */
    private const TTL_MS = 1000;

    private ExpenseQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->queue = new ExpenseQueue(self::PREFIX, self::TTL_MS);
        } catch (\Throwable $e) {
            $this->markTestSkipped('RabbitMQ недоступен: ' . $e->getMessage());
        }

        $this->purge();
    }

    protected function tearDown(): void
    {
        // Удаляем, а не чистим: иначе после прогона в брокере навсегда остаются
        // три пустые очереди, и `rabbitmqctl list_queues` перестаёт быть читаемым.
        $this->deleteQueues();
        $this->queue->close();

        parent::tearDown();
    }

    public function testPublishAndConsumeDeliversPayloadIntact(): void
    {
        $this->queue->publish(['update_id' => 42, 'chat_id' => -100123, 'text' => 'кофё 300']);

        $received = $this->consumeOne(static fn(AMQPMessage $m) => $m->ack());

        $this->assertSame(
            ['update_id' => 42, 'chat_id' => -100123, 'text' => 'кофё 300'],
            ExpenseQueue::payload($received)
        );
    }

    public function testFirstDeliveryIsAttemptOne(): void
    {
        $this->queue->publish(['update_id' => 1]);

        $received = $this->consumeOne(static fn(AMQPMessage $m) => $m->ack());

        $this->assertSame(1, ExpenseQueue::attempt($received));
    }

    /**
     * Отказ уводит сообщение в отстойник, а не возвращает в работу немедленно:
     * при недоступном Gemini мгновенный повтор только сжёг бы попытки.
     */
    public function testRejectedMessageLandsInRetryQueue(): void
    {
        $this->queue->publish(['update_id' => 2]);

        $this->consumeOne(static fn(AMQPMessage $m) => $m->nack(false));

        // Сразу после отказа сообщение лежит в отстойнике и ждёт TTL.
        $this->assertSame(1, $this->depth($this->queue->retryQueue()));
        $this->assertSame(0, $this->depth($this->queue->mainQueue()));
    }

    /** По истечении TTL брокер сам возвращает сообщение в работу. */
    public function testMessageReturnsToMainQueueAfterTtl(): void
    {
        $this->queue->publish(['update_id' => 3]);
        $this->consumeOne(static fn(AMQPMessage $m) => $m->nack(false));

        $this->assertTrue(
            $this->waitForDepth($this->queue->mainQueue(), 1),
            'Сообщение не вернулось в рабочую очередь по истечении TTL'
        );
        $this->assertSame(0, $this->depth($this->queue->retryQueue()));
    }

    /**
     * Счётчик попыток ведёт сам RabbitMQ в заголовке x-death и переносит его
     * между очередями. На нём держится выход из цикла в воркере.
     */
    public function testAttemptCounterGrowsWithEachRejection(): void
    {
        $this->queue->publish(['update_id' => 4]);

        $attempts = [];
        for ($circle = 0; $circle < 3; $circle++) {
            $message = $this->consumeOne(static fn(AMQPMessage $m) => $m->nack(false));
            $attempts[] = ExpenseQueue::attempt($message);

            $this->waitForDepth($this->queue->mainQueue(), 1);
        }

        $this->assertSame([1, 2, 3], $attempts);
    }

    /**
     * Полный путь до окончательного отказа — ровно то, как ведёт себя воркер:
     * пока попытки не исчерпаны, он отказывается; на превышении — публикует
     * в dead-letter и подтверждает.
     */
    public function testMessageEndsInDeadLetterAfterMaxAttempts(): void
    {
        $maxAttempts = 5;
        $this->queue->publish(['update_id' => 5, 'text' => 'кофе 300']);

        $lastAttempt = 0;
        while (true) {
            $message = null;
            $attempt = 0;

            $this->queue->consume(function (AMQPMessage $m) use (&$message, &$attempt, $maxAttempts): void {
                $message = $m;
                $attempt = ExpenseQueue::attempt($m);

                if ($attempt > $maxAttempts) {
                    $this->queue->publish(ExpenseQueue::payload($m), $this->queue->deadQueue());
                    $m->ack();
                } else {
                    $m->nack(false);
                }

                $this->queue->stop();
            });

            $lastAttempt = $attempt;
            if ($attempt > $maxAttempts) {
                break;
            }

            $this->waitForDepth($this->queue->mainQueue(), 1);
        }

        $this->assertSame($maxAttempts + 1, $lastAttempt, 'Выход должен случиться на шестой попытке');
        $this->assertSame(1, $this->depth($this->queue->deadQueue()));
        $this->assertSame(0, $this->depth($this->queue->mainQueue()));
        $this->assertSame(0, $this->depth($this->queue->retryQueue()));
    }

    /** Подтверждённое сообщение из очереди исчезает — повторов быть не должно. */
    public function testAcknowledgedMessageIsGone(): void
    {
        $this->queue->publish(['update_id' => 6]);

        $this->consumeOne(static fn(AMQPMessage $m) => $m->ack());

        $this->assertSame(0, $this->depth($this->queue->mainQueue()));
        $this->assertSame(0, $this->depth($this->queue->retryQueue()));
    }

    // --- Инфраструктура теста ---------------------------------------------------

    /**
     * Принимает ровно одно сообщение и прекращает приём.
     *
     * @param callable(AMQPMessage): void $handle
     */
    private function consumeOne(callable $handle): AMQPMessage
    {
        $received = null;

        $this->queue->consume(function (AMQPMessage $message) use ($handle, &$received): void {
            $received = $message;
            $handle($message);
            $this->queue->stop();
        });

        $this->assertInstanceOf(AMQPMessage::class, $received, 'Сообщение не пришло');

        return $received;
    }

    /**
     * Ждёт, пока в очереди окажется нужное число сообщений.
     *
     * Опрос, а не блокирующее ожидание: если топология сломана, тест должен
     * упасть по таймауту, а не повиснуть навсегда.
     */
    private function waitForDepth(string $queue, int $expected, float $timeoutSeconds = 5.0): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if ($this->depth($queue) === $expected) {
                return true;
            }

            usleep(100_000);
        }

        return false;
    }

    /**
     * Сколько сообщений лежит в очереди.
     *
     * Считается на отдельном соединении: passive-объявление на рабочем канале
     * php-amqplib мешалось бы с активным потребителем.
     */
    private function depth(string $queue): int
    {
        $connection = $this->inspector();
        $channel = $connection->channel();

        try {
            [, $messageCount] = $channel->queue_declare($queue, true);
        } finally {
            $channel->close();
            $connection->close();
        }

        return (int)$messageCount;
    }

    private function purge(): void
    {
        $this->eachTestQueue(static fn($channel, string $queue) => $channel->queue_purge($queue));
    }

    private function deleteQueues(): void
    {
        $this->eachTestQueue(static fn($channel, string $queue) => $channel->queue_delete($queue));
    }

    /**
     * Применяет операцию ко всем трём тестовым очередям на отдельном соединении.
     *
     * Каждая — в своём канале: любая ошибка протокола (очереди ещё нет) закрывает
     * канал целиком, и на общем канале следующие операции падали бы каскадом.
     *
     * @param callable(\PhpAmqpLib\Channel\AMQPChannel, string): mixed $operation
     */
    private function eachTestQueue(callable $operation): void
    {
        $connection = $this->inspector();

        foreach ([$this->queue->mainQueue(), $this->queue->retryQueue(), $this->queue->deadQueue()] as $queue) {
            $channel = $connection->channel();

            try {
                $operation($channel, $queue);
                $channel->close();
            } catch (\Throwable) {
                // Очереди ещё (или уже) нет — делать нечего.
            }
        }

        $connection->close();
    }

    private function inspector(): AMQPStreamConnection
    {
        return new AMQPStreamConnection(
            getenv('RABBITMQ_HOST') ?: 'rabbitmq',
            (int)(getenv('RABBITMQ_PORT') ?: 5672),
            getenv('RABBITMQ_USER') ?: 'guest',
            getenv('RABBITMQ_PASS') ?: 'guest'
        );
    }
}
