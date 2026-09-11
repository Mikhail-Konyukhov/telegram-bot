<?php

namespace App\Tests\Unit;

use App\Models\User;
use App\Services\ApiAuthenticator;
use App\Services\LedgerResolver;
use App\Services\WebSession;
use App\Tests\Support\InitDataFactory;
use PHPUnit\Framework\TestCase;

/**
 * Два пути в одну книгу: подписанный initData из Mini App и cookie веб-версии.
 *
 * Проверяется сам выбор пути и отказ на каждом из них — разбор подписей лежит
 * в {@see TelegramAuthTest} и {@see WebSessionTest}.
 */
class ApiAuthenticatorTest extends TestCase
{
    private const TOKEN = InitDataFactory::BOT_TOKEN;

    private const LEDGER = 424242;

    public function testCookieAuthenticatesWithoutTelegram(): void
    {
        $cookie = WebSession::cookie(self::LEDGER, self::TOKEN);

        $users = $this->createMock(User::class);
        // Книга могла ещё не существовать: /web можно отправить, не добавив ни одной траты.
        $users->expects($this->once())->method('ensure')->with(self::LEDGER);

        $this->assertSame(self::LEDGER, $this->auth($users)->authenticate('', $cookie));
    }

    public function testBrokenCookieIsRejected(): void
    {
        $cookie = WebSession::cookie(self::LEDGER, self::TOKEN) . 'мусор';

        $users = $this->createMock(User::class);
        $users->expects($this->never())->method('ensure');

        $this->assertNull($this->auth($users)->authenticate('', $cookie));
    }

    public function testNothingProvidedIsRejected(): void
    {
        $this->assertNull($this->auth()->authenticate(''));
    }

    /**
     * Внутри Telegram cookie игнорируется: там книгу выбирает `?startapp=`,
     * и право на неё проверяет LedgerResolver, а не подпись месячной давности.
     */
    public function testInitDataWinsOverCookie(): void
    {
        $ledgers = $this->createMock(LedgerResolver::class);
        $ledgers->method('resolve')->willReturn(-100500);

        $result = $this->auth(null, $ledgers)->authenticate(
            InitDataFactory::create(),
            WebSession::cookie(self::LEDGER, self::TOKEN)
        );

        $this->assertSame(-100500, $result);
    }

    /** Cookie не должна спасать запрос с испорченной подписью Telegram. */
    public function testTamperedInitDataIsRejectedEvenWithValidCookie(): void
    {
        $result = $this->auth()->authenticate(
            InitDataFactory::create() . 'мусор',
            WebSession::cookie(self::LEDGER, self::TOKEN)
        );

        $this->assertNull($result);
    }

    private function auth(?User $users = null, ?LedgerResolver $ledgers = null): ApiAuthenticator
    {
        return new ApiAuthenticator(
            self::TOKEN,
            $ledgers ?? $this->createMock(LedgerResolver::class),
            $users ?? $this->createMock(User::class)
        );
    }
}
