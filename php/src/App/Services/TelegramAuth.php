<?php

namespace App\Services;

/**
 * Проверка подписи initData из Telegram Mini App.
 *
 * @see https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
 */
class TelegramAuth
{
    /** @var int Максимальный возраст initData в секундах. */
    private const MAX_AGE = 86400;

    /**
     * Проверяет подпись initData и возвращает id пользователя Telegram.
     *
     * @param string $initData Сырая query-строка из Telegram.WebApp.initData
     * @param string $botToken Токен бота
     * @return int|null id пользователя либо null, если подпись невалидна или данные протухли
     */
    public static function userId(string $initData, string $botToken): ?int
    {
        $data = self::verify($initData, $botToken);

        return $data === null ? null : $data['user_id'];
    }

    /**
     * Проверяет подпись initData и возвращает разобранные данные запуска.
     *
     * `start_param` — это то, что приехало в `?startapp=` у direct link Mini App.
     * Значение задаёт клиент, поэтому подпись гарантирует лишь его неизменность
     * по дороге, но не право на доступ: что за ним стоит, решает вызывающий
     * (см. {@see LedgerResolver}).
     *
     * @param string $initData Сырая query-строка из Telegram.WebApp.initData
     * @param string $botToken Токен бота
     * @return array{user_id: int, start_param: string|null}|null null при невалидной подписи
     */
    public static function verify(string $initData, string $botToken): ?array
    {
        parse_str($initData, $params);

        $hash = $params['hash'] ?? null;
        if (!is_string($hash) || $hash === '') {
            return null;
        }
        unset($params['hash']);

        $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);

        // Поле signature появилось позже hash и в data-check-string не входит, но
        // трактовки в клиентах расходятся — проверяем оба варианта, безопасность
        // от этого не страдает: обе строки подписаны одним и тем же секретом.
        $candidates = [$params];
        if (isset($params['signature'])) {
            $withoutSignature = $params;
            unset($withoutSignature['signature']);
            array_unshift($candidates, $withoutSignature);
        }

        $matched = false;
        foreach ($candidates as $candidate) {
            if (hash_equals(hash_hmac('sha256', self::checkString($candidate), $secret), $hash)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            return null;
        }

        $authDate = (int)($params['auth_date'] ?? 0);
        if ($authDate <= 0 || time() - $authDate > self::MAX_AGE) {
            return null;
        }

        $user = json_decode($params['user'] ?? '', true);
        $id = $user['id'] ?? null;

        if (!is_int($id) || $id <= 0) {
            return null;
        }

        $startParam = $params['start_param'] ?? null;

        return [
            'user_id' => $id,
            'start_param' => is_string($startParam) && $startParam !== '' ? $startParam : null,
        ];
    }

    /**
     * Собирает data-check-string: пары key=value, отсортированные по ключу, через \n.
     *
     * @param array<string,string> $params
     * @return string
     */
    private static function checkString(array $params): string
    {
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        return implode("\n", $pairs);
    }
}
