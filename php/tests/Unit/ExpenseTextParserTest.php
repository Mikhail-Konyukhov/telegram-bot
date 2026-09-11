<?php

namespace App\Tests\Unit;

use App\Services\ExpenseTextParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Разбор пользовательского ввода — самое заметное место в проекте: сюда попадает
 * каждое сообщение боту и каждый «умный ввод» из Mini App.
 *
 * Часть тестов ниже закрепляет поведение, которое пользователь вряд ли ожидает
 * (см. блок «Закреплённые странности»). Они написаны не потому, что так правильно,
 * а потому, что так есть: пока решение чинить не принято, тест показывает цену
 * будущей правки — упадёт ровно то, что изменится.
 */
class ExpenseTextParserTest extends TestCase
{
    // --- Основной разбор ------------------------------------------------------

    public function testParsesNameAndPrice(): void
    {
        $result = ExpenseTextParser::parse('кофе 300');

        $this->assertSame([['name' => 'кофе', 'price' => 300.0]], $result['items']);
        $this->assertSame([], $result['errors']);
    }

    /** Порядок не важен: люди пишут и «кофе 300», и «300 кофе». */
    public function testPriceBeforeNameParsesTheSame(): void
    {
        $this->assertSame(
            ExpenseTextParser::parse('кофе 300')['items'],
            ExpenseTextParser::parse('300 кофе')['items']
        );
    }

    #[DataProvider('provideSeparators')]
    public function testSplitsItems(string $text, string $why): void
    {
        $result = ExpenseTextParser::parse($text);

        $this->assertSame(
            [
                ['name' => 'кофе', 'price' => 300.0],
                ['name' => 'такси', 'price' => 450.0],
            ],
            $result['items'],
            $why
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideSeparators(): iterable
    {
        yield 'запятая' => ['кофе 300, такси 450', 'Основной разделитель'];
        yield 'точка с запятой' => ['кофе 300; такси 450', 'Альтернатива запятой'];
        yield 'перевод строки' => ["кофе 300\nтакси 450", 'Вставка списка из заметок'];
        yield 'CRLF' => ["кофе 300\r\nтакси 450", 'Windows-перевод строки не должен давать пустых позиций'];
        yield 'смешанные' => ["кофе 300;\nтакси 450", 'Подряд идущие разделители схлопываются'];
    }

    /**
     * Запятая внутри числа — не разделитель. Иначе «кофе 300,50» распалось бы
     * на «кофе 300» и «50», и трата записалась бы без копеек, а лишние 50 рублей
     * ушли бы отдельной позицией без названия.
     */
    #[DataProvider('provideDecimals')]
    public function testDecimalSeparatorIsNotAnItemSeparator(string $text): void
    {
        $result = ExpenseTextParser::parse($text);

        $this->assertSame([['name' => 'кофе', 'price' => 300.5]], $result['items']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideDecimals(): iterable
    {
        yield 'запятая' => ['кофе 300,50'];
        yield 'точка' => ['кофе 300.50'];
    }

    public function testDropsEmptyChunks(): void
    {
        $result = ExpenseTextParser::parse(', , кофе 300 ,');

        $this->assertSame([['name' => 'кофе', 'price' => 300.0]], $result['items']);
        $this->assertSame([], $result['errors'], 'Пустые куски — не ошибка ввода');
    }

    // --- Ошибки ---------------------------------------------------------------

    #[DataProvider('provideUnparsable')]
    public function testReportsUnparsableChunk(string $text, string $why): void
    {
        $result = ExpenseTextParser::parse($text);

        $this->assertSame([], $result['items'], $why);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString($text, $result['errors'][0], 'В ошибке должен быть виден исходный кусок');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideUnparsable(): iterable
    {
        yield 'без цены' => ['кофе', 'Непонятно, сколько потрачено'];
        yield 'без названия' => ['300', 'Непонятно, на что потрачено'];
        yield 'нулевая цена' => ['кофе 0', 'Трата на ноль рублей смысла не имеет'];
    }

    /** Разбираемые позиции сохраняются, даже если соседняя не разобралась. */
    public function testKeepsGoodItemsAlongsideErrors(): void
    {
        $result = ExpenseTextParser::parse('кофе 300, ерунда, такси 450');

        $this->assertSame(
            [
                ['name' => 'кофе', 'price' => 300.0],
                ['name' => 'такси', 'price' => 450.0],
            ],
            $result['items']
        );
        $this->assertCount(1, $result['errors']);
    }

    public function testEmptyTextGivesNothing(): void
    {
        $this->assertSame(['items' => [], 'errors' => []], ExpenseTextParser::parse(''));
    }

    // --- Закреплённые странности ----------------------------------------------
    //
    // Ценой считается ПЕРВОЕ число в строке, а вырезается оно из названия во всех
    // вхождениях сразу. Единицы измерения снимает NameNormalizer, но он работает
    // уже после разбора и цену не спасает.
    //
    // Тесты ниже фиксируют это как есть. Если поведение решат чинить — упадут
    // ровно они, и будет видно полный объём изменения.

    public function testQuantityBeforeNameIsTakenAsPrice(): void
    {
        $result = ExpenseTextParser::parse('2 кофе 300');

        $this->assertSame(
            [['name' => 'кофе 300', 'price' => 2.0]],
            $result['items'],
            'Ожидаемое пользователем: кофе за 300. Фактическое: две штуки «кофе 300»'
        );
    }

    public function testVolumeIsTakenAsPrice(): void
    {
        $result = ExpenseTextParser::parse('молоко 0,5 л 90');

        $this->assertSame(
            [['name' => 'молоко  л 90', 'price' => 0.5]],
            $result['items'],
            'Ожидаемое пользователем: молоко за 90. Фактическое: молоко за 50 копеек'
        );
    }

    public function testRepeatedNumberIsStrippedEverywhere(): void
    {
        $result = ExpenseTextParser::parse('чай 300 300');

        $this->assertSame(
            [['name' => 'чай', 'price' => 300.0]],
            $result['items'],
            'str_replace вырезает все вхождения числа, а не только распознанную цену'
        );
    }

    public function testMinusIsNotPartOfPrice(): void
    {
        $result = ExpenseTextParser::parse('кофе -50');

        $this->assertSame(
            [['name' => 'кофе -', 'price' => 50.0]],
            $result['items'],
            'Минус не входит в регулярку цены и остаётся в названии'
        );
    }
}
