<?php

namespace App\Services;

/**
 * Доступ к дашборду из обычного браузера, без Telegram.
 *
 * Внутри Telegram книгу трат называет подписанный initData ({@see TelegramAuth}),
 * в браузере его нет. Вместо него — значение, подписанное тем же токеном бота:
 * бот выдаёт его ссылкой на неделю, страница `/web` меняет ссылку на cookie
 * на месяц и убирает токен из адреса. Хранить нечего, поэтому и схема БД
 * не меняется: и срок, и книгу несёт сама подпись.
 */
class WebSession
{
    /** Имя cookie. Оно же читается в {@see \App\Http\ApiRequest}. */
    public const COOKIE = 'web_session';

    /** Сколько живёт cookie. Публичная: тем же сроком её ставит `/web`. */
    public const SESSION_TTL = 2592000;

    /** Сколько живёт ссылка из бота. Меньше сессии: ссылка оседает в истории чата. */
    private const LINK_TTL = 604800;

    public static function link(int $ledgerId, string $botToken, ?int $now = null): string
    {
        return self::issue('l', $ledgerId, self::LINK_TTL, $botToken, $now);
    }

    public static function verifyLink(string $value, string $botToken, ?int $now = null): ?int
    {
        return self::parse('l', $value, $botToken, $now);
    }

    public static function cookie(int $ledgerId, string $botToken, ?int $now = null): string
    {
        return self::issue('s', $ledgerId, self::SESSION_TTL, $botToken, $now);
    }

    public static function verifyCookie(string $value, string $botToken, ?int $now = null): ?int
    {
        return self::parse('s', $value, $botToken, $now);
    }

    /**
     * @param string $purpose 'l' — ссылка, 's' — сессия
     * @return string `<книга>.<срок>.<подпись>`
     */
    private static function issue(string $purpose, int $ledgerId, int $ttl, string $botToken, ?int $now): string
    {
        $body = $ledgerId . '.' . (($now ?? time()) + $ttl);

        return $body . '.' . self::mac($purpose, $body, $botToken);
    }

    /**
     * @return int|null id книги трат; null — не разобралось, подпись чужая,
     *                  назначение другое или срок вышел
     */
    private static function parse(string $purpose, string $value, string $botToken, ?int $now): ?int
    {
        // Книга группы — её chat_id, а он отрицательный.
        if (!preg_match('/^(-?\d+)\.(\d+)\.([0-9a-f]{64})$/', $value, $m)) {
            return null;
        }

        // Назначение входит в подписываемую строку, но не в само значение:
        // ссылку проверяют как ссылку, и cookie из неё не сделать.
        if (!hash_equals(self::mac($purpose, $m[1] . '.' . $m[2], $botToken), $m[3])) {
            return null;
        }

        if ((int)$m[2] < ($now ?? time())) {
            return null;
        }

        return (int)$m[1];
    }

    private static function mac(string $purpose, string $body, string $botToken): string
    {
        return hash_hmac('sha256', $purpose . '.' . $body, hash_hmac('sha256', $botToken, 'WebDashboard', true));
    }
}
