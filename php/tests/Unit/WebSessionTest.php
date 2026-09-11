<?php

namespace App\Tests\Unit;

use App\Services\WebSession;
use App\Tests\Support\InitDataFactory;
use PHPUnit\Framework\TestCase;

/**
 * Подпись веб-сессии — то же, чем для Mini App является подпись initData:
 * единственное, что отделяет книгу трат от кого угодно с адресом дашборда.
 *
 * Ожидаемые значения считаются здесь своей реализацией алгоритма, а не вызовом
 * проверяемого класса: тест, который подписывает тем же кодом, что и проверяет,
 * доказывал бы лишь самосогласованность и прошёл бы на сломанной схеме.
 */
class WebSessionTest extends TestCase
{
    private const TOKEN = InitDataFactory::BOT_TOKEN;

    private const LEDGER = 424242;

    /** Книга группы — её chat_id, а он отрицательный. */
    private const GROUP = -1004469856007;

    public function testLinkIsSignedWithBotToken(): void
    {
        $now = 1756800000;

        $this->assertSame(
            self::sign('l', self::LEDGER, $now + 604800),
            WebSession::link(self::LEDGER, self::TOKEN, $now),
            'Ссылка живёт неделю'
        );
    }

    public function testCookieIsSignedWithBotToken(): void
    {
        $now = 1756800000;

        $this->assertSame(
            self::sign('s', self::LEDGER, $now + 2592000),
            WebSession::cookie(self::LEDGER, self::TOKEN, $now),
            'Сессия живёт месяц'
        );
    }

    public function testValidLinkAndCookieReturnLedger(): void
    {
        $now = 1756800000;

        $this->assertSame(
            self::LEDGER,
            WebSession::verifyLink(self::sign('l', self::LEDGER, $now + 60), self::TOKEN, $now)
        );
        $this->assertSame(
            self::LEDGER,
            WebSession::verifyCookie(self::sign('s', self::LEDGER, $now + 60), self::TOKEN, $now)
        );
    }

    public function testGroupLedgerSurvivesRoundTrip(): void
    {
        $now = 1756800000;

        $this->assertSame(
            self::GROUP,
            WebSession::verifyCookie(self::sign('s', self::GROUP, $now + 60), self::TOKEN, $now)
        );
    }

    /**
     * Ради этого подпись и нужна: подставив свой id, любой открыл бы чужую книгу.
     */
    public function testTamperedLedgerIsRejected(): void
    {
        $value = self::sign('s', self::LEDGER, 1756800060);
        $forged = preg_replace('/^' . self::LEDGER . '/', '999999', $value);

        $this->assertNull(WebSession::verifyCookie($forged, self::TOKEN, 1756800000));
    }

    /** Срок тоже подписан — продлить сессию правкой значения нельзя. */
    public function testTamperedExpiryIsRejected(): void
    {
        $value = self::sign('s', self::LEDGER, 1756800060);
        $forged = str_replace('.1756800060.', '.9999999999.', $value);

        $this->assertNull(WebSession::verifyCookie($forged, self::TOKEN, 1756800000));
    }

    public function testForeignTokenIsRejected(): void
    {
        $value = self::sign('s', self::LEDGER, 1756800060, 'другой:токен');

        $this->assertNull(WebSession::verifyCookie($value, self::TOKEN, 1756800000));
    }

    public function testExpiredValueIsRejected(): void
    {
        $value = self::sign('s', self::LEDGER, 1756800000);

        $this->assertNull(WebSession::verifyCookie($value, self::TOKEN, 1756800001));
    }

    /**
     * Назначение входит в подписываемую строку: иначе ссылка из чата работала бы
     * как сессия, а срок в неделю ничего бы не ограничивал.
     */
    public function testLinkIsNotAcceptedAsCookie(): void
    {
        $now = 1756800000;
        $link = WebSession::link(self::LEDGER, self::TOKEN, $now);

        $this->assertNull(WebSession::verifyCookie($link, self::TOKEN, $now));
        $this->assertNull(
            WebSession::verifyLink(WebSession::cookie(self::LEDGER, self::TOKEN, $now), self::TOKEN, $now)
        );
    }

    /**
     * @param string $value
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideMalformedValues')]
    public function testMalformedValueIsRejected(string $value): void
    {
        $this->assertNull(WebSession::verifyCookie($value, self::TOKEN, 1756800000));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMalformedValues(): iterable
    {
        yield 'пусто' => [''];
        yield 'без подписи' => ['424242.1756800060'];
        yield 'подпись не hex' => ['424242.1756800060.' . str_repeat('z', 64)];
        yield 'подпись короче' => ['424242.1756800060.' . str_repeat('a', 63)];
        yield 'книга не число' => ['abc.1756800060.' . str_repeat('a', 64)];
        yield 'мусор' => ['../../etc/passwd'];
    }

    /** Та же схема, что в WebSession, но записанная независимо. */
    private static function sign(string $purpose, int $ledgerId, int $expires, string $botToken = self::TOKEN): string
    {
        $body = $ledgerId . '.' . $expires;
        $secret = hash_hmac('sha256', $botToken, 'WebDashboard', true);

        return $body . '.' . hash_hmac('sha256', $purpose . '.' . $body, $secret);
    }
}
