<?php

namespace App\Tests\Unit;

use App\Models\ChatMember;
use App\Services\LedgerResolver;
use App\Tests\Support\FakeTelegramClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Решение «пускать ли к этой книге трат».
 *
 * Книгу выбирает клиент через `?startapp=`, и подпись initData гарантирует лишь
 * то, что значение не подменили по дороге, — но не право на него. Поэтому здесь
 * граница безопасности: ошибка в любую сторону означает либо чужие траты
 * в чужих руках, либо потерянный доступ к своим.
 */
class LedgerResolverTest extends TestCase
{
    private const USER = 424242;
    private const GROUP = -1001234567890;

    /** Без startapp работаем с личной книгой — самый частый путь. */
    public function testNoStartParamGivesOwnLedger(): void
    {
        $resolver = new LedgerResolver(new FakeTelegramClient(), $this->members());

        $this->assertSame(self::USER, $resolver->resolve(self::USER, null));
    }

    /** Мусор в startapp не должен ни ронять, ни открывать лишнего. */
    #[DataProvider('provideJunkStartParams')]
    public function testJunkStartParamGivesOwnLedger(string $startParam): void
    {
        $resolver = new LedgerResolver(new FakeTelegramClient(), $this->members());

        $this->assertSame(self::USER, $resolver->resolve(self::USER, $startParam));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideJunkStartParams(): iterable
    {
        yield 'текст' => ['не-число'];
        yield 'смешанное' => ['-100abc'];
        yield 'дробное' => ['-100.5'];
    }

    public function testOwnIdInStartParamIsAllowed(): void
    {
        $resolver = new LedgerResolver(new FakeTelegramClient(), $this->members());

        $this->assertSame(self::USER, $resolver->resolve(self::USER, (string)self::USER));
    }

    /**
     * Главная проверка класса: чужая личная книга. id групп в Telegram всегда
     * отрицательные, поэтому положительное чужое число — это попытка открыть
     * личные траты другого человека, и спрашивать Telegram тут не о чем.
     */
    public function testForeignPersonalLedgerIsDenied(): void
    {
        $telegram = new FakeTelegramClient('member');

        $resolver = new LedgerResolver($telegram, $this->members());

        $this->assertNull($resolver->resolve(self::USER, '999999'));
        $this->assertSame(0, $telegram->getChatMemberCalls, 'Личная книга проверяется без запроса в Telegram');
    }

    // --- Групповые книги ------------------------------------------------------

    #[DataProvider('provideMemberStatuses')]
    public function testGroupLedgerAllowedForMembers(string $status): void
    {
        $members = $this->members();
        $members->method('isFresh')->willReturn(false);
        $members->expects($this->once())->method('remember')->with(self::GROUP, self::USER);

        $resolver = new LedgerResolver(new FakeTelegramClient($status), $members);

        $this->assertSame(self::GROUP, $resolver->resolve(self::USER, (string)self::GROUP));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMemberStatuses(): iterable
    {
        yield 'создатель' => ['creator'];
        yield 'админ' => ['administrator'];
        yield 'участник' => ['member'];
        yield 'ограниченный' => ['restricted'];
    }

    /**
     * Вышедший из группы теряет доступ, а закэшированное «да» стирается —
     * иначе оно продержало бы доступ ещё час.
     */
    #[DataProvider('provideNonMemberStatuses')]
    public function testGroupLedgerDeniedForNonMembers(string $status): void
    {
        $members = $this->members();
        $members->method('isFresh')->willReturn(false);
        $members->expects($this->once())->method('forget')->with(self::GROUP, self::USER);
        $members->expects($this->never())->method('remember');

        $resolver = new LedgerResolver(new FakeTelegramClient($status), $members);

        $this->assertNull($resolver->resolve(self::USER, (string)self::GROUP));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonMemberStatuses(): iterable
    {
        yield 'вышел' => ['left'];
        yield 'выгнали' => ['kicked'];
    }

    /** Свежий кэш экономит запрос в Telegram на каждом обращении к API. */
    public function testFreshCacheSkipsTelegramCall(): void
    {
        $members = $this->members();
        $members->method('isFresh')->with(self::GROUP, self::USER, 3600)->willReturn(true);

        $telegram = new FakeTelegramClient('member');
        $resolver = new LedgerResolver($telegram, $members);

        $this->assertSame(self::GROUP, $resolver->resolve(self::USER, (string)self::GROUP));
        $this->assertSame(0, $telegram->getChatMemberCalls);
    }

    /**
     * Бота выгнали из чата, чат удалён или Telegram недоступен. Отказ безопаснее
     * доступа: непойманное исключение здесь означало бы 500 вместо 403.
     */
    public function testTelegramFailureDeniesAccess(): void
    {
        $members = $this->members();
        $members->method('isFresh')->willReturn(false);

        $telegram = new FakeTelegramClient(null, new \RuntimeException('Bad Request: chat not found'));

        $resolver = new LedgerResolver($telegram, $members);

        $this->assertNull($resolver->resolve(self::USER, (string)self::GROUP));
    }

    /** Неизвестный статус трактуется как «не участник»: список разрешённых закрытый. */
    public function testUnknownStatusIsDenied(): void
    {
        $members = $this->members();
        $members->method('isFresh')->willReturn(false);

        $resolver = new LedgerResolver(new FakeTelegramClient('какой-то новый статус'), $members);

        $this->assertNull($resolver->resolve(self::USER, (string)self::GROUP));
    }

    /**
     * createMock не вызывает оригинальный конструктор, поэтому Database
     * не поднимается и соединение с MySQL не требуется.
     */
    private function members(): ChatMember&MockObject
    {
        return $this->createMock(ChatMember::class);
    }
}
