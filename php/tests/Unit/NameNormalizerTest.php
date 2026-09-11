<?php

namespace App\Tests\Unit;

use App\Services\NameNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Нормализатор — ключ всего словаря категорий: по его результату ищется запись
 * в `category_hints`, и промах означает лишний платный запрос в Gemini.
 *
 * Три `preg_replace` внутри идут в строго определённом порядке (в коде про это
 * есть комментарий), и перестановка ломает случай «0,5 л» незаметно — отдельные
 * тесты ниже держат именно этот порядок.
 */
class NameNormalizerTest extends TestCase
{
    #[DataProvider('provideNames')]
    public function testNormalize(string $input, string $expected, string $why): void
    {
        $this->assertSame($expected, NameNormalizer::normalize($input), $why);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideNames(): iterable
    {
        yield 'регистр' => ['КОФЕ', 'кофе', 'Иначе «Кофе» и «кофе» — разные записи словаря'];
        yield 'пробелы по краям' => ['  кофе  ', 'кофе', 'Пробелы приезжают из копипасты'];
        yield 'пробелы внутри' => ["кофе\t\t  латте", 'кофе латте', 'Табы и повторы схлопываются в один пробел'];
        yield 'ё приводится к е' => ['КОФЁ!', 'кофе', 'Пользователи пишут и «ё», и «е»'];

        // Количество и единицы к категории отношения не имеют.
        yield 'единица через пробел' => ['молоко 2 шт', 'молоко', 'Количество отбрасывается'];
        yield 'единица слитно с точкой' => ['молоко 2шт.', 'молоко', 'Точка после единицы тоже снимается'];
        yield 'дробное с точкой' => ['сыр 0.5 кг', 'сыр', 'Порядок правил: снимаем до чистки пунктуации'];
        yield 'дробное с запятой' => ['сыр 0,5 л', 'сыр', 'Иначе «0,5 л» рассыпалось бы в «0 5 л»'];
        yield 'единица уп' => ['хлеб 1 уп', 'хлеб', 'Полный список единиц из регулярки'];
        yield 'единица впереди' => ['1 л молока', 'молока', 'Единица снимается независимо от позиции'];

        yield 'пунктуация' => ['чай-пакетики (100 гр)', 'чай пакетики', 'Дефис и скобки заменяются пробелом'];
        yield 'только пунктуация' => ['!!!...', '', 'Пустой ключ вызывающий обязан отбросить'];
        yield 'пустая строка' => ['', '', 'Не должно падать'];

        // Процент единицей измерения не считается, поэтому «3,2» остаётся в ключе
        // — но уже разбитое пунктуацией на «3 2».
        yield 'жирность остаётся' => [
            'молоко простоквашино 3,2%',
            'молоко простоквашино 3 2',
            '% не единица измерения, число остаётся частью ключа',
        ];
    }

    /**
     * Колонка name_norm — 190 символов, и обрезка идёт mb_substr: обрезка byte-функцией
     * оставила бы битый последний символ, а он ломает и индекс, и сравнение.
     */
    public function testTruncatesToColumnLengthWithoutBreakingCharacters(): void
    {
        $normalized = NameNormalizer::normalize(str_repeat('я', 250));

        $this->assertSame(190, mb_strlen($normalized, 'UTF-8'));
        $this->assertSame(str_repeat('я', 190), $normalized);
    }

    /**
     * Ради чего всё затевалось: разные написания одной покупки дают один ключ.
     */
    public function testVariantsOfSamePurchaseCollapseToOneKey(): void
    {
        $keys = array_map(
            NameNormalizer::normalize(...),
            ['Кофе', 'кофе ', 'КОФЁ!', 'кофе 2 шт']
        );

        $this->assertCount(1, array_unique($keys));
    }
}
