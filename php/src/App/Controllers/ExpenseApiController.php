<?php

namespace App\Controllers;

use App\Controllers\Api\AnalyticsAction;
use App\Controllers\Api\CategoriesAction;
use App\Controllers\Api\ExpensesAction;
use App\Controllers\Api\LimitsAction;
use App\Controllers\Api\OverviewAction;
use App\Controllers\Api\SuggestionsAction;
use App\Http\ApiRequest;
use App\Http\ApiResponse;
use App\Services\ApiAuthenticator;

/**
 * Точка входа API: проверка доступа, маршрутизация, обработка исключений.
 *
 * Сами действия живут в {@see \App\Controllers\Api} — по классу на ресурс.
 * Здесь остаётся только таблица маршрутов, и добавление действия сводится
 * к строчке в ней.
 */
class ExpenseApiController
{
    /**
     * Метод и action → [класс действия, метод класса].
     *
     * @var array<string, array<string, array{class-string, string}>>
     */
    private const ROUTES = [
        'GET' => [
            'overview'            => [OverviewAction::class, 'get'],
            'expenses'            => [ExpensesAction::class, 'get'],
            'categories'          => [CategoriesAction::class, 'get'],
            'limits'              => [LimitsAction::class, 'get'],
            'suggestions'         => [SuggestionsAction::class, 'get'],
            'analytics_by_period' => [AnalyticsAction::class, 'get'],
        ],
        'POST' => [
            'expense'       => [ExpensesAction::class, 'post'],
            'expense_smart' => [ExpensesAction::class, 'smart'],
            'category'      => [CategoriesAction::class, 'post'],
            'limit'         => [LimitsAction::class, 'post'],
        ],
        'PUT' => [
            'expense' => [ExpensesAction::class, 'put'],
        ],
        'DELETE' => [
            'expense'  => [ExpensesAction::class, 'delete'],
            'category' => [CategoriesAction::class, 'delete'],
            'limit'    => [LimitsAction::class, 'delete'],
        ],
    ];

    private ApiAuthenticator $auth;

    public function __construct(?ApiAuthenticator $auth = null)
    {
        $this->auth = $auth ?? new ApiAuthenticator();
    }

    public function handle(): void
    {
        // Mini App отдаётся с того же origin, что и API — CORS не нужен.
        header('Content-Type: application/json; charset=utf-8');

        $this->dispatch(ApiRequest::fromGlobals())->send();
    }

    /**
     * Разбирает запрос и отдаёт ответ, ничего не печатая.
     *
     * Отделено от {@see handle()} ради тестов: так действие проверяется вызовом,
     * без поднятого HTTP-сервера и перехвата вывода.
     */
    public function dispatch(ApiRequest $request): ApiResponse
    {
        try {
            // Внутри try, чтобы отсутствующий config.txt отдавал JSON-500,
            // а не HTML-фатал посреди ответа API.
            $chatId = $this->auth->authenticate($request->initData(), $request->webSession());
            if ($chatId === null) {
                return ApiResponse::error('Access denied', 403);
            }

            $route = self::ROUTES[$request->method()] ?? null;
            if ($route === null) {
                return ApiResponse::error('Method not allowed', 405);
            }

            $handler = $route[$request->action()] ?? null;
            if ($handler === null) {
                return ApiResponse::error('Unknown action', 400);
            }

            [$class, $method] = $handler;

            return (new $class())->$method($request, $chatId);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 400);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 500);
        }
    }
}
