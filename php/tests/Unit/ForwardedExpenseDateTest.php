<?php

namespace App\Tests\Unit;

use App\Bot;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use TelegramBot\Api\Types\Message;

/**
 * Дата пересланной траты.
 *
 * Пересылка чека боту — единственный способ занести покупку задним числом,
 * и держится он на дате оригинала. Bot API 7.0 убрал `forward_date` в пользу
 * `forward_origin`, а telegram-bot/api 7.15 знает только старое поле: пока
 * новое не читалось из сырого апдейта, всё пересланное молча ложилось
 * сегодняшним числом.
 *
 * Конструктор Bot требует токены и поднимает HTTP-клиент, поэтому метод
 * дёргается у объекта без конструктора — он статический и ни от чего не зависит.
 */
class ForwardedExpenseDateTest extends TestCase
{
    /** День покупки: им трата и должна лечь в учёт. */
    private const ORIGINAL = 1787313600;

    /** День пересылки — шестью сутками позже. */
    private const FORWARDED = 1787832000;

    public function testForwardOriginFromRawUpdateWins(): void
    {
        $date = $this->originalDate(
            $this->message(self::FORWARDED),
            ['message' => ['forward_origin' => ['type' => 'user', 'date' => self::ORIGINAL]]]
        );

        $this->assertSame(date('Y-m-d H:i:s', self::ORIGINAL), $date);
    }

    /** Старое поле всё ещё приходит от клиентов, не обновивших формат. */
    public function testLegacyForwardDateStillWorks(): void
    {
        $message = $this->message(self::FORWARDED);
        $message->setForwardDate(self::ORIGINAL);

        $this->assertSame(date('Y-m-d H:i:s', self::ORIGINAL), $this->originalDate($message, null));
    }

    public function testPlainMessageKeepsItsOwnDate(): void
    {
        $date = $this->originalDate($this->message(self::FORWARDED), ['message' => ['text' => 'кофе 200']]);

        $this->assertSame(date('Y-m-d H:i:s', self::FORWARDED), $date);
    }

    private function message(int $date): Message
    {
        $message = new Message();
        $message->setDate($date);

        return $message;
    }

    private function originalDate(Message $message, ?array $raw): string
    {
        $method = new ReflectionMethod(Bot::class, 'originalDate');

        return $method->invoke((new ReflectionClass(Bot::class))->newInstanceWithoutConstructor(), $message, $raw);
    }
}
