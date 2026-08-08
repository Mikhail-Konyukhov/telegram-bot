<?php

namespace App\Models;

use App\Database;
use DateTime;
use DateTimeInterface;
use PDO;

/**
 * Class Expense
 *
 * Работа с расходами: создание записи и получение статистики.
 *
 * @package App\Models
 */
class Expense
{
    /** @var PDO */
    private PDO $db;

    /**
     * Expense constructor.
     */
    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Сохраняет новую трату в базе данных.
     *
     * @param int $userId ID пользователя
     * @param string $name Исходная метка расхода
     * @param string $category Категория
     * @param float $amount Сумма (положительное число)
     * @param string|null $ts Время (ISO-строка), по умолчанию текущее
     * @return int ID добавленной записи
     */
    public function add(int $userId, string $name, string $category, float $amount, ?string $ts = null): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO expenses (user_id, name, category, amount, ts)
             VALUES (:user_id, :name, :category, :amount, COALESCE(:ts, CURRENT_TIMESTAMP))"
        );
        $stmt->execute([
            'user_id' => $userId,
            'name' => $name,
            'category' => $category,
            'amount' => $amount,
            'ts' => $ts,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Возвращает общую сумму расходов пользователя по категории за последние 30 дней.
     *
     * @param int $userId
     * @param string $category
     * @return float
     */
    public function getMonthlyTotal(int $userId, string $category): float
    {
        $stmt = $this->db->prepare(
            "SELECT SUM(amount) FROM expenses
             WHERE user_id = :user_id
               AND category = :category
               AND ts >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        $stmt->execute([
            'user_id' => $userId,
            'category' => $category,
        ]);
        return (float)$stmt->fetchColumn();
    }

    /**
     * Возвращает общую сумму всех расходов пользователя за последние 30 дней.
     *
     * @param int $userId
     * @return float
     */
    public function getTotalLast30Days(int $userId): float
    {
        $stmt = $this->db->prepare(
            "SELECT SUM(amount) FROM expenses
             WHERE user_id = :user_id
               AND ts >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        $stmt->execute(['user_id' => $userId]);
        return (float)$stmt->fetchColumn();
    }

    /**
     * Возвращает расходы за выбранный период
     *
     * @param int $chatId
     * @param DateTimeInterface $from
     * @param DateTimeInterface $to
     * @return array
     */
    public function getExpensesForPeriod(int $chatId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $sql = "SELECT id, category, name, amount, ts
        FROM expenses
        WHERE user_id = :user_id
          AND ts BETWEEN :from_date AND :to_date
        ORDER BY ts DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id' => $chatId,
            ':from_date' => $from->format('Y-m-d 00:00:00'),
            ':to_date' => $to->format('Y-m-d 23:59:59'),
        ]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Обновляет существующую трату
     *
     * @param int $id ID траты
     * @param int $userId ID пользователя (для проверки доступа)
     * @param string $name Название траты
     * @param string $category Категория
     * @param float $amount Сумма
     * @param string $ts Дата и время
     * @return bool Успешность операции
     */
    public function update(int $id, int $userId, string $name, string $category, float $amount, string $ts): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE expenses 
             SET name = :name, category = :category, amount = :amount, ts = :ts
             WHERE id = :id AND user_id = :user_id"
        );
        
        return $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
            'name' => $name,
            'category' => $category,
            'amount' => $amount,
            'ts' => $ts,
        ]) && $stmt->rowCount() > 0;
    }

    /**
     * Удаляет трату
     *
     * @param int $id ID траты
     * @param int $userId ID пользователя (для проверки доступа)
     * @return bool Успешность операции
     */
    public function delete(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM expenses WHERE id = :id AND user_id = :user_id"
        );
        
        return $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
        ]) && $stmt->rowCount() > 0;
    }

    /**
     * Возвращает траты пользователя, попавшие в диапазон id.
     *
     * Диапазон, а не список id, потому что им адресуется одно подтверждение
     * в чате: в callback_data Telegram влезает 64 байта, и «от и до» держится
     * в этом лимите при любом числе позиций. Чужие записи, чьи id оказались
     * в промежутке, отсекает user_id.
     *
     * @param int $userId
     * @param int $fromId
     * @param int $toId
     * @return array
     */
    public function findRange(int $userId, int $fromId, int $toId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, name, category, amount, ts
             FROM expenses
             WHERE user_id = :user_id AND id BETWEEN :from_id AND :to_id
             ORDER BY id"
        );
        $stmt->execute([
            'user_id' => $userId,
            'from_id' => $fromId,
            'to_id'   => $toId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Удаляет траты в диапазоне id — отмена только что добавленного сообщения.
     *
     * @param int $userId
     * @param int $fromId
     * @param int $toId
     * @return int Сколько записей удалено
     */
    public function deleteRange(int $userId, int $fromId, int $toId): int
    {
        $stmt = $this->db->prepare(
            "DELETE FROM expenses WHERE user_id = :user_id AND id BETWEEN :from_id AND :to_id"
        );
        $stmt->execute([
            'user_id' => $userId,
            'from_id' => $fromId,
            'to_id'   => $toId,
        ]);

        return $stmt->rowCount();
    }

    /**
     * Категории, в которые пользователь тратит чаще всего.
     *
     * Нужны, чтобы предложить исправление категории в одно нажатие: траты
     * концентрируются в двух-трёх категориях, и нужная почти всегда там.
     *
     * @param int $userId
     * @param int $limit
     * @return string[]
     */
    public function getTopCategories(int $userId, int $limit = 4): array
    {
        $stmt = $this->db->prepare(
            "SELECT category
             FROM expenses
             WHERE user_id = :user_id AND ts >= DATE_SUB(NOW(), INTERVAL 90 DAY)
             GROUP BY category
             ORDER BY COUNT(*) DESC
             LIMIT " . max(1, min($limit, 20))
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Получает трату по ID с проверкой доступа
     *
     * @param int $id ID траты
     * @param int $userId ID пользователя
     * @return array|null Данные траты или null если не найдена
     */
    public function getById(int $id, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, name, category, amount, ts 
             FROM expenses 
             WHERE id = :id AND user_id = :user_id"
        );
        
        $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
        ]);
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Возвращает частые позиции пользователя для подсказок при вводе.
     *
     * Ранжирование — сумма экспоненциально затухающих весов: недавние покупки
     * важнее давних, поэтому «раз в неделю последний месяц» опережает
     * «двадцать раз полгода назад».
     *
     * @param int $userId
     * @param int $limit Сколько позиций вернуть
     * @return array Список [name, category, uses, avg_amount, last_used]
     */
    public function getFrequentNames(int $userId, int $limit = 8): array
    {
        $stmt = $this->db->prepare(
            "SELECT name,
                    category,
                    COUNT(*) AS uses,
                    ROUND(AVG(amount), 2) AS avg_amount,
                    MAX(ts) AS last_used
             FROM expenses
             WHERE user_id = :user_id
               AND ts >= DATE_SUB(NOW(), INTERVAL 90 DAY)
             GROUP BY name, category
             ORDER BY SUM(EXP(-DATEDIFF(NOW(), ts) / 30)) DESC
             LIMIT " . max(1, min($limit, 50))
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
