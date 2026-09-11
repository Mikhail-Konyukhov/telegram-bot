<?php

namespace App\Tests\Integration;

use App\Database;
use App\Models\Expense;
use App\Models\ProcessedUpdate;
use App\Models\User;
use App\Tests\Support\IntegrationTestCase;

/**
 * Механизм, на котором держится идемпотентность воркера.
 *
 * bin/worker.php оборачивает разбор траты и отметку об апдейте в одну транзакцию,
 * причём открывает её сам, а пишут — модели. Работает это только потому, что
 * {@see Database} синглтон и PDO у всех моделей физически один и тот же объект.
 * Стоит кому-нибудь завести второе соединение — транзакция перестанет накрывать
 * записи, причём молча: в обычной жизни всё будет выглядеть исправно, и разойдётся
 * только при падении посреди обработки.
 *
 * Сама логика воркера живёт в замыкании внутри bin/worker.php и напрямую
 * не вызывается, поэтому здесь проверяется инвариант, на который она опирается.
 */
class WorkerTransactionTest extends IntegrationTestCase
{
    private const CHAT = -1001234567890;
    private const UPDATE_ID = 987654;

    private Expense $expenses;
    private ProcessedUpdate $processed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->expenses = new Expense();
        $this->processed = new ProcessedUpdate();
        $this->seedUser(self::CHAT);
    }

    /** Соединение у моделей и у вызывающего должно быть буквально одним объектом. */
    public function testModelsShareSingleConnection(): void
    {
        $this->assertSame(
            Database::getInstance()->getConnection(),
            $this->db,
            'Разные PDO означали бы, что транзакция воркера не накрывает записи моделей'
        );
    }

    /**
     * Падение между записью траты и подтверждением сообщения. В базе не должно
     * остаться ни траты, ни отметки — иначе повторная доставка либо запишет
     * трату второй раз, либо пропустит её навсегда.
     */
    public function testRollbackUndoesExpenseAndMarkTogether(): void
    {
        $this->db->beginTransaction();

        $this->expenses->add(self::CHAT, 'кофе', 'Еда', 300.0);
        $this->processed->mark(self::UPDATE_ID);

        $this->db->rollBack();

        $this->assertSame(0, $this->countRows('expenses'), 'Трата не должна была уцелеть');
        $this->assertFalse($this->processed->isProcessed(self::UPDATE_ID));
    }

    public function testCommitPersistsExpenseAndMarkTogether(): void
    {
        $this->db->beginTransaction();

        $this->expenses->add(self::CHAT, 'кофе', 'Еда', 300.0);
        $this->processed->mark(self::UPDATE_ID);

        $this->db->commit();

        $this->assertSame(1, $this->countRows('expenses'));
        $this->assertTrue($this->processed->isProcessed(self::UPDATE_ID));
    }

    /**
     * Тот самый повтор, ради которого таблица и заведена: сообщение доставлено
     * второй раз, отметка уже стоит, значит трату писать не надо.
     */
    public function testRedeliveryIsRecognisedAsProcessed(): void
    {
        $this->processed->mark(self::UPDATE_ID);

        $this->assertTrue($this->processed->isProcessed(self::UPDATE_ID));
        $this->assertFalse($this->processed->isProcessed(self::UPDATE_ID + 1));
    }

    /** Повторная отметка не должна ронять воркер конфликтом первичного ключа. */
    public function testMarkingTwiceIsHarmless(): void
    {
        $this->processed->mark(self::UPDATE_ID);
        $this->processed->mark(self::UPDATE_ID);

        $this->assertSame(1, $this->countRows('processed_updates'));
    }

    // --- Владелец книги --------------------------------------------------------

    /**
     * Владельцем книги может быть группа, и её id отрицательный. Без строки
     * в users первая же запись упала бы на внешнем ключе.
     */
    public function testEnsureCreatesOwnerForNegativeGroupId(): void
    {
        $users = new User();
        $group = -1009999999999;

        $users->ensure($group);

        $this->assertTrue($users->exists($group));
        $this->assertGreaterThan(0, $this->expenses->add($group, 'кофе', 'Еда', 300.0));
    }

    public function testEnsureIsIdempotent(): void
    {
        $users = new User();

        $users->ensure(self::CHAT);
        $users->ensure(self::CHAT);

        $this->assertSame(
            1,
            (int)$this->db->query('SELECT COUNT(*) FROM users WHERE id = ' . self::CHAT)->fetchColumn()
        );
    }

    /** Запись в книгу без владельца обязана падать, а не создавать сироту. */
    public function testExpenseWithoutOwnerIsRejected(): void
    {
        $this->expectException(\PDOException::class);

        $this->expenses->add(-1000000000001, 'кофе', 'Еда', 300.0);
    }
}
