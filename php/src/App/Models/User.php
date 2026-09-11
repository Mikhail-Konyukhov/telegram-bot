<?php

namespace App\Models;

use PDO;
use App\Database;
use Random\RandomException;

/**
 * Class User
 *
 * Управление пользователем, регистрация и токен доступа для дашборда.
 *
 * @package App\Models
 */
class User
{
    /** @var PDO */
    private PDO $db;

    /**
     * User constructor.
     */
    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Проверяет, существует ли пользователь в таблице users.
     *
     * @param int $userId Telegram ID пользователя
     * @return bool
     */
    public function exists(int $userId): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Регистрирует нового пользователя с заданным ID и генерирует токен для дашборда.
     *
     * @param int $userId Telegram ID пользователя
     * @return string Сгенерированный токен
     * @throws RandomException
     */
    public function register(int $userId): string
    {
        $token = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare(
            "INSERT INTO users (id, dashboard_token) VALUES (:id, :token)"
        );
        $stmt->execute(['id' => $userId, 'token' => $token]);
        return $token;
    }

    /**
     * Гарантирует, что владелец книги трат есть в таблице users.
     *
     * Владельцем может быть не только человек: у групповой книги id — это id
     * самого чата (отрицательный). На `expenses`, `categories` и `limits` висят
     * внешние ключи на `users.id`, поэтому без этой строки первая же запись
     * в групповую книгу упала бы.
     *
     * @param int $ownerId Telegram ID пользователя или чата
     * @return void
     */
    public function ensure(int $ownerId): void
    {
        if (!$this->exists($ownerId)) {
            $this->register($ownerId);
        }
    }

    /**
     * Переносит книгу трат на новый id чата.
     *
     * При апгрейде группы до супергруппы Telegram выдаёт чату новый id, а книга
     * трат — это чат. Без переноса вся история осталась бы под прежним id, и бот
     * начал бы с чистого листа: именно так 10.08.2026 из /stats пропало всё,
     * что было раньше (разбор случившегося — в миграции
     * `006_merge_migrated_chat.sql`).
     *
     * Вызывается только с синхронного пути (`Bot::handleUpdate`), поэтому своя
     * транзакция здесь не вложится в транзакцию воркера.
     *
     * @param int $from Прежний id чата
     * @param int $to Новый id чата
     */
    public function migrate(int $from, int $to): void
    {
        if ($from === $to) {
            return;
        }

        $this->ensure($to);

        $this->db->beginTransaction();

        try {
            // У expenses уникальных ключей на user_id нет, поэтому UPDATE без
            // IGNORE: пропущенная строка означала бы потерянную трату, и лучше
            // откатить перенос целиком.
            $this->db->prepare('UPDATE expenses SET user_id = :to WHERE user_id = :from')
                ->execute(['from' => $from, 'to' => $to]);

            // В categories, limits и category_hints уникальный ключ включает
            // user_id, и одноимённая запись может быть в обеих книгах. IGNORE
            // оставляет столкнувшуюся строку под старым id, DELETE её убирает:
            // побеждает новая книга — её значение пользователь правил последним.
            foreach (['categories', '`limits`', 'category_hints'] as $table) {
                $this->db->prepare("UPDATE IGNORE $table SET user_id = :to WHERE user_id = :from")
                    ->execute(['from' => $from, 'to' => $to]);
                $this->db->prepare("DELETE FROM $table WHERE user_id = :from")
                    ->execute(['from' => $from]);
            }

            // Кэш членства живёт час и пересобирается сам, но строки чата,
            // которого больше нет, не пересоберутся никогда.
            $this->db->prepare('DELETE FROM chat_members WHERE chat_id = :from')
                ->execute(['from' => $from]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Возвращает токен дашборда для пользователя.
     *
     * @param int $userId
     * @return string|null
     */
    public function getToken(int $userId): ?string
    {
        $stmt = $this->db->prepare("SELECT dashboard_token FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        return $stmt->fetchColumn() ?: null;
    }

    /**
     * Проверяет, что для данного чата установлен правильный токен.
     *
     * @param int|string $userId Идентификатор чата.
     * @param string $token Токен для проверки.
     * @return bool True, если токен верен, иначе false.
     */
    public function checkToken($userId, $token): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM users WHERE id = :user_id AND dashboard_token = :token'
        );

        $stmt->execute([
            ':user_id' => $userId,
            ':token'   => $token,
        ]);

        return $stmt->fetchColumn() > 0;
    }

}