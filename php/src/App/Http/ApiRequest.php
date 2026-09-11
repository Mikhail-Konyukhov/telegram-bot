<?php

namespace App\Http;

use App\Services\WebSession;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Входящий запрос к api.php.
 *
 * Нужен, чтобы обработчики действий не читали суперглобалы напрямую: с ними
 * они проверяются только через настоящий HTTP, а это отдельный поднятый сервер
 * и живая база на каждый тест.
 */
final class ApiRequest
{
    /**
     * @param array<string, mixed> $query Параметры строки запроса
     * @param array<string, mixed> $body Разобранное тело JSON
     */
    public function __construct(
        private readonly string $method,
        private readonly string $action,
        private readonly array $query,
        private readonly array $body,
        private readonly string $initData,
        private readonly string $webSession = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $raw = file_get_contents('php://input');
        $body = $raw === false || $raw === '' ? null : json_decode($raw, true);

        return new self(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            (string)($_GET['action'] ?? ''),
            $_GET,
            is_array($body) ? $body : [],
            (string)($_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? ''),
            (string)($_COOKIE[WebSession::COOKIE] ?? ''),
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function initData(): string
    {
        return $this->initData;
    }

    /** Cookie веб-версии дашборда: ею браузер авторизуется вместо initData. */
    public function webSession(): string
    {
        return $this->webSession;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function body(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * Границы запрошенного периода. По умолчанию — текущий месяц целиком.
     *
     * @return DateTimeImmutable[] [$start, $end]
     * @throws InvalidArgumentException При неразбираемой дате
     */
    public function period(): array
    {
        try {
            $start = new DateTimeImmutable((string)$this->query('start_date', date('Y-m-01')));
            $end = new DateTimeImmutable((string)$this->query('end_date', date('Y-m-t')));
        } catch (\Exception) {
            throw new InvalidArgumentException('Invalid date format');
        }

        return [$start, $end];
    }
}
