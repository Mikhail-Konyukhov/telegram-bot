<?php

namespace App\Tests\Unit;

use App\Controllers\ExpenseApiController;
use App\Http\ApiRequest;
use App\Services\ApiAuthenticator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Маршрутизация и отказы api.php.
 *
 * Проверяются ветки, которые срабатывают ДО обращения к действию, — именно они
 * решают, дойдёт ли запрос до данных вообще. Само поведение действий покрыто
 * сквозными тестами в tests/E2E/ApiContractTest.php, где есть живая база.
 */
class ApiRouterTest extends TestCase
{
    private const CHAT = 424242;

    /** Без подписи наружу не должно уходить ничего, кроме отказа. */
    public function testUnauthenticatedRequestIsForbidden(): void
    {
        $response = $this->dispatch('GET', 'overview', authenticated: false);

        $this->assertSame(403, $response->code);
        $this->assertFalse($response->body['success']);
        $this->assertSame('Access denied', $response->body['error']);
    }

    /**
     * Отказ проверяется раньше маршрутизации: на несуществующем действии
     * неавторизованный запрос тоже обязан получить 403, а не 400 — иначе по
     * коду ответа можно перебирать список действий.
     */
    public function testUnauthenticatedRequestToUnknownActionIsAlsoForbidden(): void
    {
        $response = $this->dispatch('GET', 'такого нет', authenticated: false);

        $this->assertSame(403, $response->code);
    }

    public function testUnsupportedMethodIsNotAllowed(): void
    {
        $response = $this->dispatch('PATCH', 'expense');

        $this->assertSame(405, $response->code);
        $this->assertSame('Method not allowed', $response->body['error']);
    }

    public function testUnknownActionIsBadRequest(): void
    {
        $response = $this->dispatch('GET', 'такого действия нет');

        $this->assertSame(400, $response->code);
        $this->assertSame('Unknown action', $response->body['error']);
    }

    public function testEmptyActionIsBadRequest(): void
    {
        $this->assertSame(400, $this->dispatch('GET', '')->code);
    }

    /**
     * Действие, существующее для одного метода, не должно отвечать на другом:
     * `expenses` — только GET, и POST на него обязан упереться в 400.
     */
    public function testActionIsNotReachableThroughWrongMethod(): void
    {
        $this->assertSame(400, $this->dispatch('POST', 'expenses')->code);
        $this->assertSame(400, $this->dispatch('DELETE', 'overview')->code);
    }

    /** Сбой конфигурации отдаёт JSON-500, а не HTML-фатал посреди ответа. */
    public function testConfigurationFailureBecomesJsonError(): void
    {
        $auth = $this->createMock(ApiAuthenticator::class);
        $auth->method('authenticate')->willThrowException(new \RuntimeException('Config variable is not set'));

        $response = (new ExpenseApiController($auth))
            ->dispatch(new ApiRequest('GET', 'overview', [], [], 'подпись'));

        $this->assertSame(500, $response->code);
        $this->assertFalse($response->body['success']);
    }

    private function dispatch(string $method, string $action, bool $authenticated = true): \App\Http\ApiResponse
    {
        return (new ExpenseApiController($this->auth($authenticated)))
            ->dispatch(new ApiRequest($method, $action, [], [], $authenticated ? 'подпись' : ''));
    }

    private function auth(bool $authenticated): ApiAuthenticator&MockObject
    {
        $auth = $this->createMock(ApiAuthenticator::class);
        $auth->method('authenticate')->willReturn($authenticated ? self::CHAT : null);

        return $auth;
    }
}
