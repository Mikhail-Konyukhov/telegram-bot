<?php

namespace App\Tests\Support;

/**
 * Сборка подписанного initData — того, что Telegram передаёт Mini App.
 *
 * Подпись считается тем же алгоритмом, что проверяет {@see \App\Services\TelegramAuth}:
 * секрет — HMAC от токена бота по ключу `WebAppData`, подписывается строка
 * `key=value`, отсортированная по ключу и склеенная через \n.
 *
 * Алгоритм здесь продублирован намеренно, а не переиспользован из TelegramAuth:
 * тест, который подписывает данные тем же кодом, что и проверяет, доказывает лишь
 * самосогласованность и пройдёт даже на полностью сломанной схеме подписи.
 */
final class InitDataFactory
{
    /** Токен ненастоящий: подпись от него зависит, а Telegram в юнитах не участвует. */
    public const BOT_TOKEN = '123456:AA-test-token';

    public const USER_ID = 424242;

    /**
     * Готовый подписанный initData с валидными значениями по умолчанию.
     *
     * @param array<string, string|int|null> $overrides Значения поверх дефолтных;
     *                                                  null удаляет ключ целиком
     */
    public static function create(array $overrides = [], string $botToken = self::BOT_TOKEN): string
    {
        $params = [
            'auth_date' => (string)time(),
            'query_id'  => 'AAHtest_query_id',
            'user'      => json_encode(
                ['id' => self::USER_ID, 'first_name' => 'Тест', 'username' => 'tester'],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
        ];

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($params[$key]);
                continue;
            }

            $params[$key] = (string)$value;
        }

        return self::sign($params, $botToken);
    }

    /**
     * Подписывает произвольный набор полей и отдаёт готовую query-строку.
     *
     * @param array<string, string> $params
     */
    public static function sign(array $params, string $botToken = self::BOT_TOKEN): string
    {
        $params['hash'] = self::hash($params, $botToken);

        return http_build_query($params);
    }

    /**
     * Подпись набора полей. Поле `hash` в неё не входит; всё остальное — входит,
     * поэтому для проверки варианта с исключённым `signature` его нужно убрать
     * из массива до вызова.
     *
     * @param array<string, string> $params
     */
    public static function hash(array $params, string $botToken = self::BOT_TOKEN): string
    {
        unset($params['hash']);
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);

        return hash_hmac('sha256', implode("\n", $pairs), $secret);
    }
}
