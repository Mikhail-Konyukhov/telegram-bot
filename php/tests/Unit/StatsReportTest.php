<?php

namespace App\Tests\Unit;

use App\Services\DashboardService;
use App\Services\LimitsPresenter;
use App\Services\StatsReport;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Текстовый дашборд: сообщение с разделами и клавиатура под ним.
 *
 * Тихо ломаются здесь три вещи. Сообщение уходит с parse_mode = HTML, поэтому
 * название категории с «<» обязано быть экранировано — иначе Telegram отклонит
 * отправку целиком, а не одну строку. Активный раздел помечается только меткой
 * на кнопке: состояния «нажато» у инлайн-кнопок нет. И «Лимиты» намеренно живут
 * вне выбранного периода — лимит задан на скользящие 30 дней.
 */
class StatsReportTest extends TestCase
{
    private const CHAT = 548701534;

    // --- Обзор -----------------------------------------------------------------

    public function testOverviewShowsTotalCountAndAverage(): void
    {
        $report = $this->report([
            $this->expense('Кофе', 500.0, 'кафе и рестораны'),
            $this->expense('Кино', 500.0, 'развлечения'),
            $this->expense('Продукты', 300.0, 'еда'),
            $this->expense('Такси', 500.0, 'транспорт'),
        ]);

        $text = $report->render(self::CHAT, 'ov', 'm')['text'];

        $this->assertStringContainsString('1 800', $text);
        $this->assertStringContainsString('4 траты', $text);
        $this->assertStringContainsString('в среднем 450', $text);
    }

    public function testOverviewComparesWithPreviousPeriod(): void
    {
        $report = $this->report(
            [$this->expense('Кофе', 400.0, 'еда')],
            [$this->expense('Кофе', 1000.0, 'еда')]
        );

        $this->assertStringContainsString('↓ 60% к прошлому периоду', $report->render(self::CHAT, 'ov', 'm')['text']);
    }

    public function testOverviewShowsGrowthWithUpArrow(): void
    {
        $report = $this->report(
            [$this->expense('Кофе', 1500.0, 'еда')],
            [$this->expense('Кофе', 1000.0, 'еда')]
        );

        $this->assertStringContainsString('↑ 50% к прошлому периоду', $report->render(self::CHAT, 'ov', 'm')['text']);
    }

    /** Рост «на бесконечность» с нуля ничего не сообщает — строки быть не должно. */
    public function testOverviewSkipsComparisonWhenPreviousPeriodIsEmpty(): void
    {
        $report = $this->report([$this->expense('Кофе', 500.0, 'еда')], []);

        $text = $report->render(self::CHAT, 'ov', 'm')['text'];

        $this->assertStringNotContainsString('к прошлому периоду', $text);
    }

    public function testOverviewShowsGlobalLimitWithRemainder(): void
    {
        $report = $this->report(
            [$this->expense('Кофе', 500.0, 'еда')],
            [],
            ['global' => ['limit' => 50000.0, 'spent' => 1800.0], 'categories' => []]
        );

        $text = $report->render(self::CHAT, 'ov', 'm')['text'];

        $this->assertStringContainsString('Общий лимит', $text);
        $this->assertStringContainsString('🟢 1 800 / 50 000 · осталось 48 200', $text);
    }

    /** Верхушка — пять категорий; остальные лежат в своём разделе. */
    public function testOverviewShowsOnlyTopFiveCategories(): void
    {
        $expenses = [];
        foreach (['еда', 'транспорт', 'кино', 'спорт', 'дом', 'связь'] as $i => $category) {
            $expenses[] = $this->expense('Трата', 100.0 * (6 - $i), $category);
        }

        $text = $this->report($expenses)->render(self::CHAT, 'ov', 'm')['text'];

        $this->assertStringContainsString('еда', $text);
        $this->assertStringNotContainsString('связь', $text, 'Самая мелкая категория не попадает в топ-5');
    }

    // --- Категории -------------------------------------------------------------

    public function testCategoriesSectionListsEverythingWithBars(): void
    {
        $report = $this->report([
            $this->expense('Кофе', 1000.0, 'кафе и рестораны'),
            $this->expense('Кино', 500.0, 'развлечения'),
            $this->expense('Продукты', 500.0, 'еда'),
        ]);

        $text = $report->render(self::CHAT, 'cat', 'm')['text'];

        $this->assertStringContainsString('Всего <b>2 000</b> в 3 категориях', $text);
        $this->assertStringContainsString('████░░░░', $text, 'Половина суммы — половина полосы');
        $this->assertStringContainsString('развлечения', $text);
    }

    /** Категории идут по убыванию суммы, а не в порядке трат. */
    public function testCategoriesAreSortedByAmount(): void
    {
        $report = $this->report([
            $this->expense('Кино', 200.0, 'развлечения'),
            $this->expense('Продукты', 900.0, 'еда'),
        ]);

        $text = $report->render(self::CHAT, 'cat', 'm')['text'];

        $this->assertLessThan(
            mb_strpos($text, 'развлечения'),
            mb_strpos($text, 'еда'),
            'Большая категория выше'
        );
    }

    // --- Траты -----------------------------------------------------------------

    public function testExpensesAreGroupedByDay(): void
    {
        $report = $this->report([
            $this->expense('Кино', 500.0, 'развлечения', date('Y-m-d H:i:s')),
            $this->expense('Кофе', 300.0, 'еда', date('Y-m-d H:i:s', strtotime('-1 day'))),
            $this->expense('Такси', 400.0, 'транспорт', date('Y-m-d H:i:s', strtotime('-5 days'))),
        ]);

        $text = $report->render(self::CHAT, 'exp', 'm')['text'];

        $this->assertStringContainsString('<b>Сегодня</b>', $text);
        $this->assertStringContainsString('<b>Вчера</b>', $text);
        $this->assertStringContainsString('<b>' . date('d.m', strtotime('-5 days')) . '</b>', $text);
    }

    /**
     * За месяц трат набирается на порядок больше двадцати, а в сообщение
     * Telegram влезает 4096 символов. Всё, кроме первой страницы, раньше было
     * недостижимо — кнопок листания не было вовсе.
     */
    public function testExpensesArePagedTwentyAtATime(): void
    {
        $view = $this->report(array_fill(0, 25, $this->expense('Кофе', 100.0, 'еда')))
            ->render(self::CHAT, 'exp', 'm');

        $this->assertStringContainsString('Показаны 1—20 из 25', $view['text']);
        $this->assertSame(20, substr_count($view['text'], 'Кофе'));
        $this->assertSame(
            [['text' => '← Раньше', 'callback_data' => 'd:exp:m:1']],
            $view['keyboard']->getInlineKeyboard()[3],
            'На первой странице назад некуда'
        );
    }

    public function testSecondPageShowsTheRestAndTheWayBack(): void
    {
        $view = $this->report(array_fill(0, 25, $this->expense('Кофе', 100.0, 'еда')))
            ->render(self::CHAT, 'exp', 'm', 1);

        $this->assertStringContainsString('Показаны 21—25 из 25', $view['text']);
        $this->assertSame(5, substr_count($view['text'], 'Кофе'));
        $this->assertSame(
            [['text' => 'Позже →', 'callback_data' => 'd:exp:m:0']],
            $view['keyboard']->getInlineKeyboard()[3],
            'На последней странице раньше некуда'
        );
    }

    /** Кнопка со старым номером живёт в чате дольше, чем сами траты. */
    public function testPageBeyondTheEndFallsBackToTheLastOne(): void
    {
        $view = $this->report(array_fill(0, 25, $this->expense('Кофе', 100.0, 'еда')))
            ->render(self::CHAT, 'exp', 'm', 99);

        $this->assertStringContainsString('Показаны 21—25 из 25', $view['text']);
    }

    public function testShortExpenseListHasNeitherNoteNorPaging(): void
    {
        $view = $this->report([$this->expense('Кофе', 100.0, 'еда')])->render(self::CHAT, 'exp', 'm');

        $this->assertStringNotContainsString('Показаны', $view['text']);
        $this->assertCount(3, $view['keyboard']->getInlineKeyboard(), 'Ряда листания быть не должно');
    }

    /** Страница из прошлого набора на новом означала бы не то. */
    public function testSwitchingSectionOrPeriodDropsThePage(): void
    {
        $keyboard = $this->report(array_fill(0, 25, $this->expense('Кофе', 100.0, 'еда')))
            ->render(self::CHAT, 'exp', 'm', 1)['keyboard']->getInlineKeyboard();

        $this->assertSame('d:cat:m', $keyboard[0][1]['callback_data']);
        $this->assertSame('d:exp:w', $keyboard[2][0]['callback_data']);
    }

    // --- Лимиты ----------------------------------------------------------------

    public function testLimitsSectionShowsGlobalAndCategories(): void
    {
        $report = $this->report([], [], [
            'global' => ['limit' => 50000.0, 'spent' => 1800.0],
            'categories' => [
                ['category' => 'еда', 'limit' => 20000.0, 'spent' => 300.0],
                ['category' => 'транспорт', 'limit' => 3000.0, 'spent' => 2500.0],
            ],
        ]);

        $text = $report->render(self::CHAT, 'lim', 'm')['text'];

        $this->assertStringContainsString('за 30 дней', $text, 'Лимит не зависит от выбранного периода');
        $this->assertStringContainsString('🟢 еда · 300 / 20 000 · осталось 19 700', $text);
        $this->assertStringContainsString('🟡 транспорт · 2 500 / 3 000', $text);
    }

    public function testLimitsSectionShowsOverspend(): void
    {
        $report = $this->report([], [], [
            'global' => null,
            'categories' => [['category' => 'еда', 'limit' => 1000.0, 'spent' => 1500.0]],
        ]);

        $this->assertStringContainsString(
            '🔴 еда · 1 500 / 1 000 · перерасход 500',
            $report->render(self::CHAT, 'lim', 'm')['text']
        );
    }

    /** Без лимитов раздел не пустой: он подсказывает, как их завести. */
    public function testLimitsSectionExplainsHowToSetThem(): void
    {
        $text = $this->report([])->render(self::CHAT, 'lim', 'm')['text'];

        $this->assertStringContainsString('Лимиты не заданы', $text);
        $this->assertStringContainsString('/setlimit', $text);
        $this->assertStringContainsString('/setgloballimit', $text);
    }

    // --- Заголовок периода -----------------------------------------------------

    /**
     * Годовое окно всегда пересекает границу года, и без года в заголовке оно
     * читалось как перевёрнутый диапазон: «01.09 — 31.08».
     */
    public function testYearPeriodHeaderKeepsTheYear(): void
    {
        $text = $this->report([$this->expense('Кофе', 500.0, 'еда')])->render(self::CHAT, 'ov', 'y')['text'];

        $this->assertSame(1, preg_match('~· (\d{2}\.\d{2}\.\d{4}) — (\d{2}\.\d{2}\.\d{4})~u', $text, $range));
        $this->assertLessThan(
            DateTimeImmutable::createFromFormat('d.m.Y', $range[2]),
            DateTimeImmutable::createFromFormat('d.m.Y', $range[1]),
            'Начало периода не может быть позже конца'
        );
    }

    /** Внутри одного года год — шум: «Неделя» и «Месяц» обходятся без него. */
    public function testShortPeriodHeaderOmitsTheYearWithinOneYear(): void
    {
        $text = $this->report([$this->expense('Кофе', 500.0, 'еда')])->render(self::CHAT, 'ov', 'w')['text'];
        $sameYear = (new DateTimeImmutable('today'))->format('Y')
            === (new DateTimeImmutable('today -6 days'))->format('Y');

        if (!$sameYear) {
            $this->markTestSkipped('Неделя пересекает Новый год — год в заголовке уместен');
        }

        $this->assertSame(1, preg_match('~· \d{2}\.\d{2} — \d{2}\.\d{2}$~um', $text));
    }

    // --- Пустой период и защита от мусора --------------------------------------

    public function testEmptyPeriodStillKeepsKeyboard(): void
    {
        $view = $this->report([])->render(self::CHAT, 'ov', 'w');

        $this->assertStringContainsString('За этот период трат нет', $view['text']);
        $this->assertNotEmpty($view['keyboard']->getInlineKeyboard(), 'Иначе период не переключить');
    }

    /** callback_data приходит с клиента и может остаться от прошлой версии бота. */
    public function testUnknownSectionAndPeriodFallBackToOverviewMonth(): void
    {
        $view = $this->report([$this->expense('Кофе', 500.0, 'еда')])->render(self::CHAT, 'мусор', 'мусор');

        $this->assertStringContainsString('Обзор', $view['text']);
        $this->assertSame('· Обзор ·', $view['keyboard']->getInlineKeyboard()[0][0]['text']);
        $this->assertSame('· Месяц ·', $view['keyboard']->getInlineKeyboard()[2][1]['text']);
    }

    /**
     * Название категории вводит пользователь. Неэкранированный «<» уронил бы
     * не строку, а всю отправку: Telegram разбирает HTML целиком.
     */
    public function testUserTextIsEscapedForHtml(): void
    {
        $report = $this->report([$this->expense('Кофе <b>', 500.0, 'еда & напитки')]);

        $text = $report->render(self::CHAT, 'cat', 'm')['text'];

        $this->assertStringContainsString('еда &amp; напитки', $text);
        $this->assertStringNotContainsString('<b>', explode('<pre>', $text)[1]);
    }

    // --- Клавиатура ------------------------------------------------------------

    public function testKeyboardKeepsPeriodWhenSwitchingSections(): void
    {
        $keyboard = $this->report([])->render(self::CHAT, 'ov', 'y')['keyboard']->getInlineKeyboard();

        $this->assertSame('d:cat:y', $keyboard[0][1]['callback_data'], 'Раздел меняется, период остаётся');
        $this->assertSame('d:exp:y', $keyboard[1][0]['callback_data']);
    }

    public function testKeyboardKeepsSectionWhenSwitchingPeriods(): void
    {
        $keyboard = $this->report([])->render(self::CHAT, 'lim', 'm')['keyboard']->getInlineKeyboard();

        $this->assertSame('d:lim:w', $keyboard[2][0]['callback_data'], 'Период меняется, раздел остаётся');
        $this->assertSame('d:lim:y', $keyboard[2][2]['callback_data']);
    }

    public function testActiveSectionIsMarked(): void
    {
        $keyboard = $this->report([])->render(self::CHAT, 'lim', 'm')['keyboard']->getInlineKeyboard();

        $this->assertSame('· Лимиты ·', $keyboard[1][1]['text']);
        $this->assertSame('Обзор', $keyboard[0][0]['text'], 'Неактивный — без меток');
    }

    /** Лимит Telegram — 64 байта, и кириллица весит по два на символ. */
    public function testCallbackDataFitsTelegramLimit(): void
    {
        $keyboard = $this->report([])->render(self::CHAT, 'ov', 'm')['keyboard']->getInlineKeyboard();

        foreach ($keyboard as $row) {
            foreach ($row as $button) {
                $this->assertLessThanOrEqual(64, strlen($button['callback_data']), $button['text']);
            }
        }
    }

    // --- Заглушки --------------------------------------------------------------

    /**
     * Все заглушки задаются здесь и только здесь: повторный `method()` на уже
     * настроенном методе PHPUnit игнорирует.
     *
     * Порядок обращений к getDetailedExpenses важен: «Обзор» спрашивает сначала
     * текущий период, потом предыдущий — на нём и строится сравнение.
     *
     * @param array $expenses Траты текущего периода
     * @param array $previous Траты предыдущего периода
     * @param array|null $limits Payload LimitsPresenter
     */
    private function report(array $expenses, array $previous = [], ?array $limits = null): StatsReport
    {
        $dashboard = $this->createMock(DashboardService::class);
        $dashboard->method('getDetailedExpenses')->willReturnOnConsecutiveCalls($expenses, $previous);
        $dashboard->method('previousPeriod')->willReturn([
            new DateTimeImmutable('-60 days'),
            new DateTimeImmutable('-31 days'),
        ]);

        return new StatsReport($dashboard, $this->limits($limits));
    }

    private function limits(?array $payload): LimitsPresenter&MockObject
    {
        $limits = $this->createMock(LimitsPresenter::class);
        $limits->method('payload')->willReturn($payload ?? ['global' => null, 'categories' => []]);

        return $limits;
    }

    /**
     * @return array<string, mixed>
     */
    private function expense(string $name, float $amount, string $category, ?string $ts = null): array
    {
        return [
            'id'       => 1,
            'name'     => $name,
            'amount'   => $amount,
            'category' => $category,
            'ts'       => $ts ?? date('Y-m-d H:i:s'),
        ];
    }
}
