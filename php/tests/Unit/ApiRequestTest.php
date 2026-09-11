<?php

namespace App\Tests\Unit;

use App\Http\ApiRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Разбор входящего запроса.
 *
 * Раньше это жило прямо в контроллере и читало $_GET с php://input, поэтому
 * проверялось только настоящим HTTP-запросом к поднятому серверу.
 */
class ApiRequestTest extends TestCase
{
    public function testExposesMethodActionAndInitData(): void
    {
        $request = $this->request(query: ['action' => 'overview'], initData: 'подпись');

        $this->assertSame('GET', $request->method());
        $this->assertSame('overview', $request->action());
        $this->assertSame('подпись', $request->initData());
        $this->assertSame('', $request->webSession());
    }

    /** Веб-версия приходит без initData: её авторизует cookie. */
    public function testExposesWebSessionCookie(): void
    {
        $request = $this->request(webSession: 'сессия');

        $this->assertSame('сессия', $request->webSession());
        $this->assertSame('', $request->initData());
    }

    public function testQueryAndBodyFallBackToDefaults(): void
    {
        $request = $this->request(query: ['limit' => '20'], body: ['name' => 'кофе']);

        $this->assertSame('20', $request->query('limit'));
        $this->assertSame('кофе', $request->body('name'));
        $this->assertNull($request->query('нет такого'));
        $this->assertSame(8, $request->query('нет такого', 8));
        $this->assertSame('по умолчанию', $request->body('нет такого', 'по умолчанию'));
    }

    // --- Период ---------------------------------------------------------------

    public function testPeriodDefaultsToCurrentMonth(): void
    {
        [$start, $end] = $this->request()->period();

        $this->assertSame(date('Y-m-01'), $start->format('Y-m-d'));
        $this->assertSame(date('Y-m-t'), $end->format('Y-m-d'), 'Конец — последний день месяца');
    }

    public function testPeriodTakesExplicitDates(): void
    {
        $request = $this->request(query: ['start_date' => '2026-08-01', 'end_date' => '2026-08-14']);

        [$start, $end] = $request->period();

        $this->assertSame('2026-08-01', $start->format('Y-m-d'));
        $this->assertSame('2026-08-14', $end->format('Y-m-d'));
    }

    /**
     * Неразбираемая дата — ошибка клиента, а не сервера: контроллер ловит
     * InvalidArgumentException и отвечает 400, а не 500.
     */
    public function testUnparsableDateThrowsInvalidArgument(): void
    {
        $request = $this->request(query: ['start_date' => 'не дата']);

        $this->expectException(InvalidArgumentException::class);
        $request->period();
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    private function request(
        string $method = 'GET',
        array $query = [],
        array $body = [],
        string $initData = '',
        string $webSession = '',
    ): ApiRequest {
        return new ApiRequest($method, (string)($query['action'] ?? ''), $query, $body, $initData, $webSession);
    }
}
