<?php

namespace App\Tests\Integration;

use App\Models\Category;
use App\Models\Expense;
use App\Models\Limit;
use App\Services\LimitsPresenter;
use App\Tests\Support\IntegrationTestCase;

/**
 * Трата, которой не подобрали категорию.
 *
 * Раньше такая позиция выбрасывалась ещё до записи — не попадала ни в историю,
 * ни в лимиты, и вернуть её было неоткуда. Теперь она сохраняется как есть,
 * и смысл этого держится на одном: в общий лимит она входит сразу, а
 * категорийные не задевает — приписать её к чужой категории было бы враньём.
 */
class UncategorizedExpenseTest extends IntegrationTestCase
{
    private const CHAT = -1009900112233;

    private Expense $expenses;
    private LimitsPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->expenses = new Expense();
        $this->presenter = new LimitsPresenter();
        $this->seedUser(self::CHAT);
    }

    public function testUncategorizedSpendingCountsTowardTheGlobalLimit(): void
    {
        (new Limit())->setGlobal(self::CHAT, 10000.0);
        $this->expenses->add(self::CHAT, 'кофе', 'еда', 300.0);
        $this->expenses->add(self::CHAT, 'психолог', Category::UNCATEGORIZED, 3055.0);

        $global = $this->presenter->payload(self::CHAT)['global'];

        $this->assertSame(3355.0, (float)$global['spent']);
    }

    /** Приписать её к чужой категории значило бы испортить и лимит, и статистику. */
    public function testItDoesNotLeakIntoCategoryLimits(): void
    {
        $this->expenses->add(self::CHAT, 'кофе', 'еда', 300.0);
        $this->expenses->add(self::CHAT, 'психолог', Category::UNCATEGORIZED, 3055.0);

        $this->assertSame(300.0, $this->expenses->getMonthlyTotal(self::CHAT, 'еда'));
    }

    /** Она обычная строка в expenses: видна в истории и правится как любая другая. */
    public function testItIsAnOrdinaryRowAndCanBeRecategorized(): void
    {
        $id = $this->expenses->add(self::CHAT, 'психолог', Category::UNCATEGORIZED, 3055.0);
        $stored = $this->expenses->getById($id, self::CHAT);

        $this->assertSame(Category::UNCATEGORIZED, $stored['category']);
        $this->assertTrue(
            $this->expenses->update($id, self::CHAT, 'психолог', 'здоровье', 3055.0, $stored['ts'])
        );
        $this->assertSame('здоровье', $this->expenses->getById($id, self::CHAT)['category']);
    }
}
