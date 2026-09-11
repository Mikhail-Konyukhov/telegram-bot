<?php

namespace App\Tests\Support;

use App\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * База для тестов, которым нужна живая MySQL.
 *
 * Работают они против отдельной базы `telegram_bot_test` — её имя выставляет
 * tests/bootstrap.php до первого обращения к {@see Database}. Рабочая
 * `telegram_bot` не затрагивается.
 *
 * Чистка между тестами — DELETE, а не транзакция с откатом: воркер открывает
 * собственную транзакцию, а вложенных MySQL не поддерживает, и внешний откат
 * съел бы ровно то поведение, которое проверяется.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected PDO $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guardAgainstWrongDatabase();

        $this->db = Database::getInstance()->getConnection();
        $this->resetData();
    }

    /**
     * Страховка от запуска по рабочей базе. Ошибка здесь стоила бы всей истории
     * трат, поэтому проверка стоит до первого DELETE, а не после.
     */
    private function guardAgainstWrongDatabase(): void
    {
        $name = getenv('DB_NAME');

        if (!is_string($name) || !str_ends_with($name, '_test')) {
            $this->fail(
                'Интеграционные тесты запускаются только по базе с суффиксом _test, '
                . 'а DB_NAME = ' . var_export($name, true)
            );
        }
    }

    /**
     * Сносит данные тестов, оставляя системный сид: пользователя 0 и категории
     * по умолчанию. Они приезжают из database/init.sql, на них висит внешний
     * ключ, и без них Category::getUserCategories() вернул бы пустой список.
     */
    protected function resetData(): void
    {
        $this->db->exec('DELETE FROM expenses');
        $this->db->exec('DELETE FROM limits');
        $this->db->exec('DELETE FROM category_hints');
        $this->db->exec('DELETE FROM chat_members');
        $this->db->exec('DELETE FROM processed_updates');
        $this->db->exec('DELETE FROM categories WHERE user_id <> 0');
        $this->db->exec('DELETE FROM users WHERE id <> 0');
    }

    /**
     * Заводит владельца книги трат. Нужен до первой записи: на expenses.user_id,
     * categories.user_id и limits.user_id висит внешний ключ на users.id.
     */
    protected function seedUser(int $id): void
    {
        $this->db->prepare('INSERT IGNORE INTO users (id) VALUES (?)')->execute([$id]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    protected function countRows(string $table): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
}
