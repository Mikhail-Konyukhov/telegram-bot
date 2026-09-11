<?php

namespace App\Tests\Unit;

use App\Services\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Форматирование чисел и текстовых полос для сообщений бота.
 *
 * Здесь ломается тихо ровно одно: `pad()` считает символы, а не байты. С
 * `str_pad` кириллическая колонка разъезжается, потому что каждая буква весит
 * два байта, и в <pre> это видно сразу.
 */
class FormatTest extends TestCase
{
    // --- Деньги ----------------------------------------------------------------

    public function testMoneyHidesKopecksWhenThereAreNone(): void
    {
        $this->assertSame('1 800', Format::money(1800.0));
    }

    public function testMoneyShowsKopecksWhenTheyExist(): void
    {
        $this->assertSame('300,50', Format::money(300.5));
    }

    public function testMoneyGroupsThousandsWithSpaces(): void
    {
        $this->assertSame('1 234 567', Format::money(1234567.0));
    }

    public function testPercentRounds(): void
    {
        $this->assertSame('56%', Format::percent(0.5551));
    }

    // --- Маркер лимита ---------------------------------------------------------

    #[DataProvider('provideMarkerCases')]
    public function testMarker(float $spent, string $expected, string $why): void
    {
        $this->assertSame($expected, Format::marker($spent, 1000.0), $why);
    }

    /**
     * @return iterable<string, array{float, string, string}>
     */
    public static function provideMarkerCases(): iterable
    {
        yield 'половина' => [500.0, '🟢', '50% — беспокоиться не о чем'];
        yield 'под порогом' => [799.0, '🟢', 'Порог предупреждения — 80%'];
        yield 'ровно порог' => [800.0, '🟡', 'Ровно 80% уже повод предупредить'];
        yield 'почти лимит' => [999.0, '🟡', 'Ещё не превышение'];
        yield 'ровно лимит' => [1000.0, '🔴', 'Ровно 100% — уже превышение'];
        yield 'перерасход' => [1500.0, '🔴', 'Превышение'];
    }

    /** Нулевой лимит — «тратить нельзя», любая трата уже превышение. */
    public function testZeroLimitIsAlwaysOver(): void
    {
        $this->assertSame('🔴', Format::marker(0.0, 0.0));
    }

    // --- Полоса ----------------------------------------------------------------

    public function testBarIsEmptyAtZero(): void
    {
        $this->assertSame('░░░░░░░░', Format::bar(0.0));
    }

    public function testBarIsFullAtOne(): void
    {
        $this->assertSame('████████', Format::bar(1.0));
    }

    public function testBarIsHalfAtHalf(): void
    {
        $this->assertSame('████░░░░', Format::bar(0.5));
    }

    /** Доля больше единицы приходит при перерасходе — полосу это ломать не должно. */
    public function testBarClampsOutOfRangeShares(): void
    {
        $this->assertSame('████████', Format::bar(3.0));
        $this->assertSame('░░░░░░░░', Format::bar(-1.0));
    }

    public function testBarAlwaysHasSameWidth(): void
    {
        foreach ([0.0, 0.13, 0.5, 0.87, 1.0] as $share) {
            $this->assertSame(8, mb_strlen(Format::bar($share)), "Доля {$share}");
        }
    }

    // --- Колонки ---------------------------------------------------------------

    /**
     * Ради этого класс и появился: str_pad считает байты, и «еда» (6 байт при
     * трёх символах) получила бы на три пробела меньше остальных.
     */
    public function testPadCountsCharactersNotBytes(): void
    {
        $padded = Format::pad('еда', 10);

        $this->assertSame(10, mb_strlen($padded));
        $this->assertSame('еда       ', $padded);
    }

    public function testPadLeftAlignsToTheRight(): void
    {
        $this->assertSame('   1 800', Format::pad('1 800', 8, true));
    }

    /** Строка длиннее колонки не обрезается: за это отвечает truncate(). */
    public function testPadKeepsTextLongerThanWidth(): void
    {
        $this->assertSame('домашние животные', Format::pad('домашние животные', 5));
    }

    public function testTruncateShortensWithEllipsis(): void
    {
        $truncated = Format::truncate('домашние животные', 10);

        $this->assertSame(10, mb_strlen($truncated));
        $this->assertStringEndsWith('…', $truncated);
    }

    public function testTruncateLeavesShortTextAlone(): void
    {
        $this->assertSame('еда', Format::truncate('еда', 14));
    }
}
