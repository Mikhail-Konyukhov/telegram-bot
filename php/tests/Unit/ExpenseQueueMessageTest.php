<?php

namespace App\Tests\Unit;

use App\Queue\ExpenseQueue;
use JsonException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\TestCase;

/**
 * Разбор сообщений очереди — статические методы, брокер для них не нужен.
 *
 * `attempt()` считает попытки не сам, а по заголовку `x-death`, который ведёт
 * RabbitMQ и переносит между очередями. Это выгодно (счётчик переживает
 * перезапуск воркера), но привязывает нас к чужому формату: обновление
 * php-amqplib или брокера может поменять форму заголовка, и тогда счётчик
 * молча замрёт на единице — сообщение будет крутиться по кругу вечно вместо
 * того, чтобы через пять попыток уехать в expenses.dead.
 */
class ExpenseQueueMessageTest extends TestCase
{
    // --- payload() ------------------------------------------------------------

    public function testDecodesJsonBody(): void
    {
        $message = new AMQPMessage(json_encode([
            'update_id' => 42,
            'chat_id'   => -1001234567890,
            'text'      => 'кофе 300',
        ], JSON_UNESCAPED_UNICODE));

        $this->assertSame([
            'update_id' => 42,
            'chat_id'   => -1001234567890,
            'text'      => 'кофе 300',
        ], ExpenseQueue::payload($message));
    }

    public function testKeepsUnicodeIntact(): void
    {
        $message = new AMQPMessage(json_encode(['text' => 'кофё 300'], JSON_UNESCAPED_UNICODE));

        $this->assertSame('кофё 300', ExpenseQueue::payload($message)['text']);
    }

    /** Скаляр — валидный JSON, но не полезная нагрузка: воркеру нужен массив полей. */
    public function testNonArrayBodyGivesEmptyArray(): void
    {
        $this->assertSame([], ExpenseQueue::payload(new AMQPMessage('42')));
    }

    /**
     * Битое тело — исключение, а не пустой массив: воркер поймает его как
     * «прочую ошибку» и подтвердит сообщение, вместо того чтобы гонять по кругу
     * то, что никогда не разберётся.
     */
    public function testBrokenJsonThrows(): void
    {
        $this->expectException(JsonException::class);

        ExpenseQueue::payload(new AMQPMessage('{не json'));
    }

    // --- attempt() ------------------------------------------------------------

    /** Первая доставка: заголовков ещё нет, потому что отказов не было. */
    public function testFirstDeliveryIsAttemptOne(): void
    {
        $this->assertSame(1, ExpenseQueue::attempt(new AMQPMessage('{}')));
    }

    public function testCountsDeathsAsPreviousAttempts(): void
    {
        $message = new AMQPMessage('{}', [
            'application_headers' => new AMQPTable([
                'x-death' => [['count' => 4, 'queue' => ExpenseQueue::MAIN, 'reason' => 'rejected']],
            ]),
        ]);

        $this->assertSame(5, ExpenseQueue::attempt($message), 'Четыре отказа позади — идёт пятая попытка');
    }

    public function testSingleDeathIsSecondAttempt(): void
    {
        $message = new AMQPMessage('{}', [
            'application_headers' => new AMQPTable([
                'x-death' => [['count' => 1, 'queue' => ExpenseQueue::MAIN]],
            ]),
        ]);

        $this->assertSame(2, ExpenseQueue::attempt($message));
    }

    /** Заголовки есть, но x-death в них нет — отказов не было. */
    public function testHeadersWithoutDeathAreAttemptOne(): void
    {
        $message = new AMQPMessage('{}', [
            'application_headers' => new AMQPTable(['content-type' => 'application/json']),
        ]);

        $this->assertSame(1, ExpenseQueue::attempt($message));
    }

    /** Чужой формат заголовков не должен ронять воркер — считаем первой попыткой. */
    public function testNonTableHeadersAreAttemptOne(): void
    {
        $message = new AMQPMessage('{}', ['application_headers' => ['x-death' => 'что-то не то']]);

        $this->assertSame(1, ExpenseQueue::attempt($message));
    }

    /**
     * Порог из воркера: на шестой попытке сообщение должно уехать в dead-letter.
     * Тест держит связку константы MAX_ATTEMPTS и того, что считает attempt().
     */
    public function testFiveDeathsExceedWorkerLimit(): void
    {
        $message = new AMQPMessage('{}', [
            'application_headers' => new AMQPTable([
                'x-death' => [['count' => 5, 'queue' => ExpenseQueue::MAIN]],
            ]),
        ]);

        $this->assertSame(6, ExpenseQueue::attempt($message));
        $this->assertGreaterThan(5, ExpenseQueue::attempt($message), 'MAX_ATTEMPTS в bin/worker.php — 5');
    }
}
