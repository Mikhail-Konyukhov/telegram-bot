<?php

namespace App\Tests\Integration;

use App\Models\Expense;
use App\Tests\Support\IntegrationTestCase;

/**
 * Граница владения данными: любая выборка и любое изменение траты обязаны
 * ограничиваться её владельцем.
 *
 * Проверка прав здесь и есть весь контроль доступа — выше по стеку id траты
 * приходит от клиента (кнопка бота, PUT из Mini App), и если запрос забудет
 * про user_id, чужая трата отдастся или удалится без единой жалобы.
 *
 * Владельцы в тестах — личный (положительный id) и групповой (отрицательный):
 * знак id уже один раз ломал сортировку в словаре категорий.
 */
class ExpenseScopeTest extends IntegrationTestCase
{
    private const OWNER = 424242;
    private const STRANGER = -1001234567890;

    private Expense $expenses;

    protected function setUp(): void
    {
        parent::setUp();

        $this->expenses = new Expense();
        $this->seedUser(self::OWNER);
        $this->seedUser(self::STRANGER);
    }

    public function testAddReturnsInsertedId(): void
    {
        $id = $this->expenses->add(self::OWNER, 'кофе', 'Еда', 300.0);

        $this->assertGreaterThan(0, $id);
        $this->assertSame('кофе', $this->expenses->getById($id, self::OWNER)['name']);
    }

    // --- Чтение ----------------------------------------------------------------

    public function testGetByIdHidesForeignExpense(): void
    {
        $id = $this->expenses->add(self::OWNER, 'кофе', 'Еда', 300.0);

        $this->assertNull($this->expenses->getById($id, self::STRANGER));
    }

    public function testFindRangeHidesForeignExpenses(): void
    {
        $mine = $this->expenses->add(self::OWNER, 'кофе', 'Еда', 300.0);
        $theirs = $this->expenses->add(self::STRANGER, 'чужое', 'Еда', 999.0);

        $found = $this->expenses->findRange(self::OWNER, $mine, $theirs);

        $this->assertSame([$mine], array_map('intval', array_column($found, 'id')));
    }

    /**
     * Диапазон id, а не список: между «моими» траты могли вклиниться от другого
     * пользователя, и отсекает их именно user_id.
     */
    public function testFindRangeSkipsInterleavedForeignExpenses(): void
    {
        $first = $this->expenses->add(self::OWNER, 'кофе', 'Еда', 300.0);
        $this->expenses->add(self::STRANGER, 'чужое', 'Еда', 999.0);
        $last = $this->expenses->add(self::OWNER, 'такси', 'Транспорт', 450.0);

        $found = $this->expenses->findRange(self::OWNER, $first, $last);

        $this->assertCount(2, $found);
        $this->assertSame(['кофе', 'такси'], array_column($found, 'name'));
    }

    // --- Изменение --------------------------------------------------------------

    public function testUpdateDoesNotTouchForeignExpense(): void
    {
        $id = $this->expenses->add(self::OWNER, 'кофе', 'Еда', 300.0);

        $this->expenses->update($id, self::STRANGER, 'взломано', 'Взлом', 1.0, date('Y-m-d H:i:s'));

        $this->assertSame('кофе', $this->expenses->getById($id, self::OWNER)['name']);
    }

    public function testUpdateChangesOwnExpense(): void
    {
        $id = $this->expenses->add(self::OWNER, 'кофе', 'Еда', 300.0);

        $this->expenses->update($id, self::OWNER, 'кофе латте', 'Кафе', 350.0, '2026-08-14 10:00:00');

        $updated = $this->expenses->getById($id, self::OWNER);

        $this->assertSame('кофе латте', $updated['name']);
        $this->assertSame('Кафе', $updated['category']);
        $this->assertSame('350.00', $updated['amount']);
    }

    // --- Удаление ---------------------------------------------------------------

    public function testDeleteDoesNotRemoveForeignExpense(): void
    {
        $id = $this->expenses->add(self::OWNER, 'кофе', 'Еда', 300.0);

        $this->assertFalse($this->expenses->delete($id, self::STRANGER));
        $this->assertNotNull($this->expenses->getById($id, self::OWNER));
    }

    public function testDeleteRemovesOwnExpense(): void
    {
        $id = $this->expenses->add(self::OWNER, 'кофе', 'Еда', 300.0);

        $this->assertTrue($this->expenses->delete($id, self::OWNER));
        $this->assertNull($this->expenses->getById($id, self::OWNER));
    }

    /** Отмена сообщения не должна задевать чужие траты, попавшие в диапазон id. */
    public function testDeleteRangeSpareForeignExpenses(): void
    {
        $first = $this->expenses->add(self::OWNER, 'кофе', 'Еда', 300.0);
        $foreign = $this->expenses->add(self::STRANGER, 'чужое', 'Еда', 999.0);
        $last = $this->expenses->add(self::OWNER, 'такси', 'Транспорт', 450.0);

        $deleted = $this->expenses->deleteRange(self::OWNER, $first, $last);

        $this->assertSame(2, $deleted);
        $this->assertNotNull($this->expenses->getById($foreign, self::STRANGER), 'Чужая трата уцелела');
    }

    // --- Агрегаты ---------------------------------------------------------------

    public function testTotalsCountOnlyOwnExpenses(): void
    {
        $this->expenses->add(self::OWNER, 'кофе', 'Еда', 300.0);
        $this->expenses->add(self::STRANGER, 'чужое', 'Еда', 999.0);

        $this->assertSame(300.0, $this->expenses->getTotalLast30Days(self::OWNER));
        $this->assertSame(300.0, $this->expenses->getMonthlyTotal(self::OWNER, 'Еда'));
    }

    public function testTopCategoriesAreOrderedByFrequencyAndScoped(): void
    {
        foreach (['Еда', 'Еда', 'Еда', 'Транспорт'] as $category) {
            $this->expenses->add(self::OWNER, 'позиция', $category, 100.0);
        }
        $this->expenses->add(self::STRANGER, 'чужое', 'Развлечения', 100.0);

        $top = $this->expenses->getTopCategories(self::OWNER);

        $this->assertSame(['Еда', 'Транспорт'], $top);
    }

    public function testFrequentNamesAreScopedToOwner(): void
    {
        $this->expenses->add(self::OWNER, 'кофе', 'Еда', 300.0);
        $this->expenses->add(self::STRANGER, 'чужое', 'Еда', 999.0);

        $names = array_column($this->expenses->getFrequentNames(self::OWNER), 'name');

        $this->assertSame(['кофе'], $names);
    }
}
