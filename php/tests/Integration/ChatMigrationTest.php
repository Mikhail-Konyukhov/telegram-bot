<?php

namespace App\Tests\Integration;

use App\Models\User;
use App\Tests\Support\IntegrationTestCase;

/**
 * Переезд книги трат на новый id чата.
 *
 * Книга — это чат, а при апгрейде группы до супергруппы Telegram выдаёт чату
 * новый id. Один раз это уже случилось незамеченным (10.08.2026): бот завёл
 * пустую книгу, и /stats перестал показывать что-либо старше даты переезда.
 * Тесты закрывают три места, где перенос молча теряет данные, — траты,
 * столкновение одноимённых записей и повторную доставку служебного сообщения.
 */
class ChatMigrationTest extends IntegrationTestCase
{
    /** Обычная группа. */
    private const OLD = -4727194767;

    /** Она же после апгрейда: у супергрупп id начинается с -100. */
    private const NEW = -1004469856007;

    private User $users;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new User();
        $this->seedUser(self::OLD);
    }

    public function testExpensesFollowTheChatToItsNewId(): void
    {
        $this->addExpense(self::OLD, 'кофе', 300.0);
        $this->addExpense(self::OLD, 'такси', 500.0);

        $this->users->migrate(self::OLD, self::NEW);

        $this->assertSame(0, $this->countFor('expenses', self::OLD));
        $this->assertSame(2, $this->countFor('expenses', self::NEW));
    }

    /** Новая книга могла ещё не существовать: на expenses висит внешний ключ. */
    public function testTargetLedgerIsCreatedWhenMissing(): void
    {
        $this->addExpense(self::OLD, 'кофе', 300.0);

        $this->users->migrate(self::OLD, self::NEW);

        $this->assertSame(1, $this->countFor('users', self::NEW));
    }

    public function testCategoriesLimitsAndHintsFollowToo(): void
    {
        $this->db->prepare('INSERT INTO categories (user_id, name) VALUES (?, ?)')
            ->execute([self::OLD, 'Психолог']);
        $this->db->prepare('INSERT INTO `limits` (user_id, category, `limit`) VALUES (?, ?, ?)')
            ->execute([self::OLD, 'еда', 20000]);
        $this->db->prepare('INSERT INTO category_hints (user_id, name_norm, category) VALUES (?, ?, ?)')
            ->execute([self::OLD, 'молоко', 'еда']);

        $this->users->migrate(self::OLD, self::NEW);

        $this->assertSame(1, $this->countFor('categories', self::NEW));
        $this->assertSame(1, $this->countFor('limits', self::NEW));
        $this->assertSame(1, $this->countFor('category_hints', self::NEW));
    }

    /**
     * Пока переезд оставался незамеченным, новая книга успевала накопить своё.
     * Уникальный ключ этих таблиц включает user_id, поэтому одноимённая запись
     * есть в обеих — выиграть должна новая: её пользователь правил последней.
     */
    public function testNewerLedgerWinsOnConflict(): void
    {
        $this->seedUser(self::NEW);
        $insert = $this->db->prepare('INSERT INTO category_hints (user_id, name_norm, category) VALUES (?, ?, ?)');
        $insert->execute([self::OLD, 'молоко', 'еда']);
        $insert->execute([self::NEW, 'молоко', 'товары для дома']);

        $this->users->migrate(self::OLD, self::NEW);

        $this->assertSame(0, $this->countFor('category_hints', self::OLD), 'Проигравшая строка не остаётся');
        $this->assertSame(
            'товары для дома',
            $this->fetchAll('SELECT category FROM category_hints WHERE user_id = ?', [self::NEW])[0]['category']
        );
    }

    /**
     * Служебное сообщение приходит в оба чата — в старый и в новый, — поэтому
     * перенос выполняется дважды. Второй раз обязан быть пустым.
     */
    public function testSecondRunChangesNothing(): void
    {
        $this->addExpense(self::OLD, 'кофе', 300.0);

        $this->users->migrate(self::OLD, self::NEW);
        $this->users->migrate(self::OLD, self::NEW);

        $this->assertSame(1, $this->countFor('expenses', self::NEW));
    }

    /** Защита от служебного сообщения, где оба id совпали. */
    public function testMigrationToTheSameIdIsANoop(): void
    {
        $this->addExpense(self::OLD, 'кофе', 300.0);

        $this->users->migrate(self::OLD, self::OLD);

        $this->assertSame(1, $this->countFor('expenses', self::OLD));
    }

    private function addExpense(int $ownerId, string $name, float $amount): void
    {
        $this->db->prepare('INSERT INTO expenses (user_id, name, category, amount) VALUES (?, ?, ?, ?)')
            ->execute([$ownerId, $name, 'еда', $amount]);
    }

    private function countFor(string $table, int $ownerId): int
    {
        $column = $table === 'users' ? 'id' : 'user_id';
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE {$column} = ?");
        $stmt->execute([$ownerId]);

        return (int)$stmt->fetchColumn();
    }
}
