<?php

namespace App\Tests\Unit;

use App\Models\Expense;
use App\Models\Limit as LimitModel;
use App\Services\ExpenseConfirmation;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Сообщение-подтверждение после добавления траты и кнопки под ним.
 *
 * Два места здесь ломаются молча: лимит Telegram на callback_data считается
 * в БАЙТАХ (кириллица — два на символ, то есть «влезает» вдвое меньше, чем
 * кажется по длине строки), и маркеры лимита по категории — единственное,
 * ради чего бот вообще прерывает пользователя после ввода.
 */
class ExpenseConfirmationTest extends TestCase
{
    private const CHAT = -1001234567890;

    // --- callback_data: лимит в 64 байта ---------------------------------------

    public function testBuildsCategoryButton(): void
    {
        $confirmation = new ExpenseConfirmation($this->expenses(), $this->limits());

        $button = $confirmation->categoryButton(7, 'Еда', 10, 12, '→ Еда');

        $this->assertSame(['text' => '→ Еда', 'callback_data' => 's:7:10:12:Еда'], $button);
    }

    /**
     * Ровно на границе. Префикс «s:1:1:1:» — 8 байт, значит категории остаётся 56,
     * то есть 28 кириллических символов.
     */
    public function testCategoryButtonAtExactByteLimitIsAllowed(): void
    {
        $confirmation = new ExpenseConfirmation($this->expenses(), $this->limits());
        $category = str_repeat('я', 28);

        $button = $confirmation->categoryButton(1, $category, 1, 1, 'метка');

        $this->assertSame(64, strlen($button['callback_data']), 'Ровно предел Telegram');
    }

    /**
     * Один лишний символ — и кнопки нет. Отдать её всё равно нельзя: Telegram
     * отклонит всю клавиатуру целиком, а не одну кнопку.
     */
    public function testCategoryButtonOverByteLimitIsDropped(): void
    {
        $confirmation = new ExpenseConfirmation($this->expenses(), $this->limits());

        $this->assertNull($confirmation->categoryButton(1, str_repeat('я', 29), 1, 1, 'метка'));
    }

    /**
     * Кириллица весит два байта на символ: 40 символов — это 80 байт, хотя
     * strlen по символам показал бы «влезает».
     */
    public function testLongCyrillicCategoryIsDroppedDespiteShortCharacterCount(): void
    {
        $confirmation = new ExpenseConfirmation($this->expenses(), $this->limits());
        $category = 'Развлечения и путешествия по выходным дням';

        $this->assertLessThan(64, mb_strlen($category), 'По символам укладывается');
        $this->assertGreaterThan(64, strlen($category), 'По байтам — нет');
        $this->assertNull($confirmation->categoryButton(1, $category, 1, 1, 'метка'));
    }

    // --- Ряд альтернатив -------------------------------------------------------

    public function testAlternativesRowSkipsCurrentCategory(): void
    {
        $expenses = $this->expenses();
        $expenses->method('getTopCategories')->willReturn(['Еда', 'Транспорт', 'Дом', 'Развлечения']);

        $confirmation = new ExpenseConfirmation($expenses, $this->limits());

        $row = $confirmation->alternativesRow(self::CHAT, 7, 'Еда', 10, 12);
        $labels = array_column($row, 'text');

        $this->assertNotContains('→ Еда', $labels, 'Текущая категория не предлагается');
        $this->assertContains('→ Транспорт', $labels);
    }

    public function testAlternativesRowOffersAtMostThreeCategoriesPlusMore(): void
    {
        $expenses = $this->expenses();
        $expenses->method('getTopCategories')->willReturn(['Еда', 'Транспорт', 'Дом', 'Развлечения']);

        $confirmation = new ExpenseConfirmation($expenses, $this->limits());

        $row = $confirmation->alternativesRow(self::CHAT, 7, 'Прочее', 10, 12);

        $this->assertCount(4, $row, 'Три категории плюс «…»');
        $this->assertSame('…', $row[3]['text']);
        $this->assertSame('a:7:10:12', $row[3]['callback_data']);
    }

    /** «…» должен быть всегда: без него из длинного списка не выбраться. */
    public function testAlternativesRowAlwaysHasMoreButton(): void
    {
        $expenses = $this->expenses();
        $expenses->method('getTopCategories')->willReturn([]);

        $confirmation = new ExpenseConfirmation($expenses, $this->limits());

        $row = $confirmation->alternativesRow(self::CHAT, 7, 'Еда', 10, 12);

        $this->assertSame([['text' => '…', 'callback_data' => 'a:7:10:12']], $row);
    }

    // --- Отрисовка подтверждения ----------------------------------------------

    /** Траты уже отменили — перерисовывать нечего, и это не ошибка. */
    public function testRenderReturnsNullWhenExpensesAreGone(): void
    {
        $expenses = $this->expenses();
        $expenses->method('findRange')->willReturn([]);

        $confirmation = new ExpenseConfirmation($expenses, $this->limits());

        $this->assertNull($confirmation->render(self::CHAT, 10, 12));
    }

    public function testRenderShowsItemAndThirtyDayTotal(): void
    {
        $confirmation = new ExpenseConfirmation(
            $this->expensesWith([$this->item(1, 'кофе', 300.0, 'Еда')], total30: 5000.0),
            $this->limits()
        );

        $text = $confirmation->render(self::CHAT, 1, 1)['text'];

        $this->assertStringContainsString('✅ кофе 300 · Еда', $text);
        $this->assertStringContainsString('За 30 дней: 5 000', $text);
    }

    /** Копейки показываются только когда они есть. */
    public function testRenderShowsFractionalAmountsWithKopecks(): void
    {
        $confirmation = new ExpenseConfirmation(
            $this->expensesWith([$this->item(1, 'кофе', 300.5, 'Еда')], total30: 300.5),
            $this->limits()
        );

        $text = $confirmation->render(self::CHAT, 1, 1)['text'];

        $this->assertStringContainsString('кофе 300,50', $text);
    }

    /** Одна трата — альтернативы сразу, без промежуточного нажатия. */
    public function testSingleExpenseGetsAlternativesImmediately(): void
    {
        $expenses = $this->expensesWith(
            [$this->item(1, 'кофе', 300.0, 'Еда')],
            total30: 300.0,
            topCategories: ['Транспорт']
        );

        $confirmation = new ExpenseConfirmation($expenses, $this->limits());

        $keyboard = $confirmation->render(self::CHAT, 1, 1)['keyboard']->getInlineKeyboard();

        $this->assertSame('↩️ Отменить', $keyboard[0][0]['text']);
        $this->assertSame('→ Транспорт', $keyboard[1][0]['text']);
    }

    /** Несколько трат — по строке на каждую, чтобы можно было поправить любую. */
    public function testMultipleExpensesGetRowPerItem(): void
    {
        $expenses = $this->expensesWith(
            [$this->item(1, 'кофе', 300.0, 'Еда'), $this->item(2, 'такси', 450.0, 'Транспорт')],
            total30: 750.0
        );

        $confirmation = new ExpenseConfirmation($expenses, $this->limits());

        $keyboard = $confirmation->render(self::CHAT, 1, 2)['keyboard']->getInlineKeyboard();

        $this->assertSame('↩️ Отменить всё', $keyboard[0][0]['text']);
        $this->assertSame('c:1:1:2', $keyboard[1][0]['callback_data']);
        $this->assertSame('c:2:1:2', $keyboard[2][0]['callback_data']);
    }

    // --- Лимит по категории ----------------------------------------------------

    /**
     * Строка с лимитом идёт под каждой тратой, а не только у порога, — состояние
     * различает маркер.
     *
     * @param float $spent Потрачено по категории за месяц
     * @param string $expected Ожидаемый маркер
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideCategoryLimitCases')]
    public function testCategoryLimitMarker(float $spent, string $expected, string $why): void
    {
        $expenses = $this->expensesWith([$this->item(1, 'кофе', 300.0, 'Еда')], total30: $spent);
        $expenses->method('getMonthlyTotal')->willReturn($spent);

        $limits = $this->limits();
        $limits->method('get')->willReturn(1000.0);
        $limits->method('getGlobal')->willReturn(null);

        $confirmation = new ExpenseConfirmation($expenses, $limits);
        $text = $confirmation->render(self::CHAT, 1, 1)['text'];

        $this->assertStringContainsString("{$expected} «Еда»: ", $text, $why);
    }

    /**
     * @return iterable<string, array{float, string, string}>
     */
    public static function provideCategoryLimitCases(): iterable
    {
        yield 'сильно ниже порога' => [500.0, '🟢', '50% лимита — но лимит всё равно показываем'];
        yield 'под самым порогом' => [799.0, '🟢', 'Порог предупреждения — 80%'];
        yield 'ровно на пороге' => [800.0, '🟡', 'Ровно 80% уже повод предупредить'];
        yield 'между порогами' => [999.0, '🟡', 'Ещё не превышение'];
        yield 'ровно лимит' => [1000.0, '🔴', 'Ровно 100% — уже превышение'];
        yield 'выше лимита' => [1500.0, '🔴', 'Превышение'];
    }

    /** Ради чего всё и затевалось: сколько потрачено из лимита, прямо в подтверждении. */
    public function testCategoryLimitShowsSpentAndLimit(): void
    {
        $expenses = $this->expensesWith([$this->item(1, 'кофе', 300.0, 'Еда')], total30: 300.0);
        $expenses->method('getMonthlyTotal')->willReturn(4500.0);

        $limits = $this->limits();
        $limits->method('get')->willReturn(10000.0);
        $limits->method('getGlobal')->willReturn(null);

        $confirmation = new ExpenseConfirmation($expenses, $limits);

        $this->assertStringContainsString(
            '🟢 «Еда»: 4 500 / 10 000',
            $confirmation->render(self::CHAT, 1, 1)['text']
        );
    }

    public function testGlobalLimitWarning(): void
    {
        $expenses = $this->expensesWith([$this->item(1, 'кофе', 300.0, 'Еда')], total30: 10000.0);
        $expenses->method('getMonthlyTotal')->willReturn(0.0);

        $limits = $this->limits();
        $limits->method('get')->willReturn(null);
        $limits->method('getGlobal')->willReturn(10000.0);

        $confirmation = new ExpenseConfirmation($expenses, $limits);
        $text = $confirmation->render(self::CHAT, 1, 1)['text'];

        $this->assertStringContainsString('🔴 Превышение общего лимита', $text);
        $this->assertStringContainsString('За 30 дней: 10 000 / 10 000', $text);
    }

    /** Лимит не задан — строки нет вовсе, «лимит не задан» под каждой тратой не нужен. */
    public function testNoLimitsMeansNoLimitLines(): void
    {
        $expenses = $this->expensesWith([$this->item(1, 'кофе', 300.0, 'Еда')], total30: 999999.0);
        $expenses->method('getMonthlyTotal')->willReturn(999999.0);

        $limits = $this->limits();
        $limits->method('get')->willReturn(null);
        $limits->method('getGlobal')->willReturn(null);

        $confirmation = new ExpenseConfirmation($expenses, $limits);
        $text = $confirmation->render(self::CHAT, 1, 1)['text'];

        $this->assertStringNotContainsString('🔴', $text);
        $this->assertStringNotContainsString('🟡', $text);
        $this->assertStringNotContainsString('🟢', $text);
    }

    // --- Заглушки --------------------------------------------------------------

    /**
     * Все заглушки задаются здесь и только здесь: повторный `method()` на уже
     * настроенном методе PHPUnit игнорирует, поэтому переопределить их
     * в самом тесте не получится.
     *
     * @param array<int, array<string, mixed>> $items
     * @param string[] $topCategories
     */
    private function expensesWith(array $items, float $total30, array $topCategories = []): Expense&MockObject
    {
        $expenses = $this->expenses();
        $expenses->method('findRange')->willReturn($items);
        $expenses->method('getTotalLast30Days')->willReturn($total30);
        $expenses->method('getTopCategories')->willReturn($topCategories);

        return $expenses;
    }

    /**
     * Трата сегодняшней датой: вчерашняя добавила бы к строке « · дд.мм»,
     * а проверяется здесь не это.
     *
     * @return array<string, mixed>
     */
    private function item(int $id, string $name, float $amount, string $category): array
    {
        return [
            'id'       => $id,
            'name'     => $name,
            'amount'   => $amount,
            'category' => $category,
            'ts'       => date('Y-m-d H:i:s'),
        ];
    }

    private function expenses(): Expense&MockObject
    {
        return $this->createMock(Expense::class);
    }

    private function limits(): LimitModel&MockObject
    {
        return $this->createMock(LimitModel::class);
    }
}
