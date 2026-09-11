<?php

namespace App\Http;

/**
 * Ответ API: код и тело, но пока без отправки.
 *
 * Обработчики действий возвращают этот объект вместо того, чтобы печатать
 * ответ самим. Так они становятся обычными функциями «запрос → ответ»,
 * которые можно вызвать в тесте и сравнить результат, не поднимая HTTP-сервер
 * и не перехватывая вывод.
 *
 * Печатает ответ ровно одно место — {@see send()}, вызываемый из api.php.
 */
final class ApiResponse
{
    /**
     * @param array<string, mixed> $body Тело в том виде, в каком уйдёт клиенту
     */
    private function __construct(
        public readonly int $code,
        public readonly array $body,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function success(array $data, int $code = 200): self
    {
        return new self($code, ['success' => true, 'data' => $data]);
    }

    public static function error(string $message, int $code = 400): self
    {
        return new self($code, ['success' => false, 'error' => $message]);
    }

    /** Данные успешного ответа — короткий путь для тестов. */
    public function data(): mixed
    {
        return $this->body['data'] ?? null;
    }

    public function send(): void
    {
        http_response_code($this->code);
        echo json_encode($this->body, JSON_UNESCAPED_UNICODE);
    }
}
