<?php

namespace App\Tests\Integration;

use App\Models\CategoryHint;
use App\Tests\Support\IntegrationTestCase;

/**
 * Словарь «название → категория»: два бесплатных уровня классификации перед
 * платным Gemini.
 *
 * Главное, что здесь проверяется, — приоритет личной записи над общей. Он
 * сделан нетипично: `ORDER BY user_id = 0 ASC`, то есть по признаку «это общая
 * запись», а не по самому user_id. Причина в том, что книга группы — это её
 * chat_id, а он отрицательный, и привычное `ORDER BY user_id DESC` ставило бы
 * общий словарь (0) впереди личного (-100...).
 *
 * Регрессия здесь абсолютно бесшумная: словарь продолжает отвечать, просто
 * чужими категориями вместо своих.
 */
class CategoryHintTest extends IntegrationTestCase
{
    private const PERSON = 424242;
    private const GROUP = -1001234567890;

    private CategoryHint $hints;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hints = new CategoryHint();
    }

    public function testFindsExactMatch(): void
    {
        $this->insertHint(self::PERSON, 'кофе', 'Еда');

        $this->assertSame('Еда', $this->hints->find(self::PERSON, 'кофе'));
    }

    public function testMissReturnsNull(): void
    {
        $this->assertNull($this->hints->find(self::PERSON, 'ничего такого не было'));
    }

    /** Ключ ищется нормализованным, поэтому регистр и «ё» не мешают. */
    public function testFindsThroughNormalization(): void
    {
        $this->insertHint(self::PERSON, 'кофе', 'Еда');

        $this->assertSame('Еда', $this->hints->find(self::PERSON, 'КОФЁ!'));
        $this->assertSame('Еда', $this->hints->find(self::PERSON, 'кофе 2 шт'));
    }

    public function testFallsBackToSharedDictionary(): void
    {
        $this->insertHint(CategoryHint::SHARED, 'такси', 'Транспорт');

        $this->assertSame('Транспорт', $this->hints->find(self::PERSON, 'такси'));
    }

    // --- Приоритет личного над общим ------------------------------------------

    public function testPersonalHintBeatsSharedForPositiveUserId(): void
    {
        $this->insertHint(CategoryHint::SHARED, 'кофе', 'Общая');
        $this->insertHint(self::PERSON, 'кофе', 'Личная');

        $this->assertSame('Личная', $this->hints->find(self::PERSON, 'кофе'));
    }

    /**
     * Тот же случай, но книга — группа с отрицательным id. Именно здесь
     * ломается сортировка по самому user_id: -1001234567890 < 0.
     */
    public function testPersonalHintBeatsSharedForNegativeGroupId(): void
    {
        $this->insertHint(CategoryHint::SHARED, 'кофе', 'Общая');
        $this->insertHint(self::GROUP, 'кофе', 'Личная');

        $this->assertSame(
            'Личная',
            $this->hints->find(self::GROUP, 'кофе'),
            'ORDER BY user_id DESC поставил бы общий словарь впереди личного'
        );
    }

    /** Чужая личная запись не должна подсказывать никому. */
    public function testForeignPersonalHintIsInvisible(): void
    {
        $this->insertHint(999999, 'кофе', 'Чужая');

        $this->assertNull($this->hints->find(self::PERSON, 'кофе'));
    }

    // --- Поиск по отдельному слову --------------------------------------------

    /**
     * Точное совпадение промахивается на всём, что длиннее одного слова, — а в
     * чек попадают и марка, и объём, и название магазина.
     */
    public function testFindsBySingleToken(): void
    {
        $this->insertHint(CategoryHint::SHARED, 'молоко', 'Еда');

        $this->assertSame('Еда', $this->hints->find(self::PERSON, 'молоко простоквашино'));
    }

    /** Короткие токены не ищутся: предлоги совпадений не дают, а запрос раздувают. */
    public function testShortTokensAreIgnored(): void
    {
        $this->insertHint(CategoryHint::SHARED, 'по', 'Ерунда');

        $this->assertNull($this->hints->find(self::PERSON, 'по дороге домой 100'));
    }

    /** При нескольких попаданиях побеждает более длинная запись — она конкретнее. */
    public function testLongerHintWinsAmongTokens(): void
    {
        $this->insertHint(CategoryHint::SHARED, 'молоко', 'Еда');
        $this->insertHint(CategoryHint::SHARED, 'простоквашино', 'Молочка');

        $this->assertSame('Молочка', $this->hints->find(self::PERSON, 'молоко простоквашино'));
    }

    /** И среди токенов личное важнее общего. */
    public function testPersonalTokenBeatsSharedToken(): void
    {
        $this->insertHint(CategoryHint::SHARED, 'молоко', 'Общая');
        $this->insertHint(self::GROUP, 'молоко', 'Личная');

        $this->assertSame('Личная', $this->hints->find(self::GROUP, 'молоко простоквашино'));
    }

    // --- Запись ----------------------------------------------------------------

    /**
     * Правка пишется и в личный словарь, и в общий: общий нужен ради холодного
     * старта новых пользователей.
     */
    public function testRememberWritesToBothScopes(): void
    {
        $this->hints->remember(self::PERSON, 'кофе', 'Еда');

        $this->assertSame('Еда', $this->hintFor(self::PERSON, 'кофе'));
        $this->assertSame('Еда', $this->hintFor(CategoryHint::SHARED, 'кофе'));
    }

    /**
     * Ключевое ограничение: запоминается ТОЛЬКО строка целиком.
     *
     * Однословные записи приходят исключительно из bin/import-hints.php. Если
     * remember() начнёт писать отдельные слова, правки пользователей растащат
     * словарь на токены, и поиск по слову станет выдавать случайные категории.
     */
    public function testRememberDoesNotSplitIntoTokens(): void
    {
        $this->hints->remember(self::PERSON, 'молоко простоквашино', 'Еда');

        $this->assertSame('Еда', $this->hintFor(self::PERSON, 'молоко простоквашино'));
        $this->assertNull($this->hintFor(self::PERSON, 'молоко'), 'Отдельного слова в словаре быть не должно');
        $this->assertNull($this->hintFor(self::PERSON, 'простоквашино'));
    }

    /** Побеждает последняя правка, а не самая частая: исправление должно действовать сразу. */
    public function testRememberOverwritesPreviousCategory(): void
    {
        $this->hints->remember(self::PERSON, 'кофе', 'Еда');
        $this->hints->remember(self::PERSON, 'кофе', 'Кафе и рестораны');

        $this->assertSame('Кафе и рестораны', $this->hintFor(self::PERSON, 'кофе'));
        $this->assertSame(2, $this->countRows('category_hints'), 'Личная плюс общая, без накопления версий');
    }

    public function testRememberNormalizesKey(): void
    {
        $this->hints->remember(self::PERSON, 'КОФЁ 2 шт', 'Еда');

        $this->assertSame('Еда', $this->hintFor(self::PERSON, 'кофе'));
    }

    /** Из строки без букв и цифр ключа не выходит — записывать нечего. */
    public function testRememberIgnoresEmptyKey(): void
    {
        $this->hints->remember(self::PERSON, '!!!', 'Еда');

        $this->assertSame(0, $this->countRows('category_hints'));
    }

    // --- Хелперы ---------------------------------------------------------------

    private function insertHint(int $userId, string $nameNorm, string $category): void
    {
        $this->db
            ->prepare('INSERT INTO category_hints (user_id, name_norm, category) VALUES (?, ?, ?)')
            ->execute([$userId, $nameNorm, $category]);
    }

    private function hintFor(int $userId, string $nameNorm): ?string
    {
        $rows = $this->fetchAll(
            'SELECT category FROM category_hints WHERE user_id = ? AND name_norm = ?',
            [$userId, $nameNorm]
        );

        return $rows === [] ? null : $rows[0]['category'];
    }
}
