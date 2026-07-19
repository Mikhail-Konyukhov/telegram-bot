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
     * Получает все расходы для DataLens с опциональной фильтрацией по датам
     *
     * @param string|null $startDate Начальная дата (Y-m-d)
     * @param string|null $endDate Конечная дата (Y-m-d)
     * @return array Список расходов
     */
    public function getAllForDataLens(?string $startDate = null, ?string $endDate = null): array
    {
        $sql = "SELECT id, user_id, name, category, amount, ts 
                FROM expenses 
                WHERE 1=1";
        
        $params = [];
        
        if ($startDate !== null) {
            $sql .= " AND ts >= :start_date";
            $params['start_date'] = $startDate . ' 00:00:00';
        }
        
        if ($endDate !== null) {
            $sql .= " AND ts <= :end_date";
            $params['end_date'] = $endDate . ' 23:59:59';
        }
        
        $sql .= " ORDER BY ts DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Получает агрегированные расходы по категориям
     *
     * @param string|null $startDate Начальная дата (Y-m-d)
     * @param string|null $endDate Конечная дата (Y-m-d)
     * @param int|null $userId Фильтр по пользователю (опционально)
     * @return array Список агрегированных данных
     */
    public function getAggregatedByCategory(?string $startDate = null, ?string $endDate = null, ?int $userId = null): array
    {
        $sql = "SELECT user_id, category, 
                       SUM(amount) as total_amount, 
                       COUNT(*) as count
                FROM expenses 
                WHERE 1=1";
        
        $params = [];
        
        if ($startDate !== null) {
            $sql .= " AND ts >= :start_date";
            $params['start_date'] = $startDate . ' 00:00:00';
        }
        
        if ($endDate !== null) {
            $sql .= " AND ts <= :end_date";
            $params['end_date'] = $endDate . ' 23:59:59';
        }
        
        if ($userId !== null) {
            $sql .= " AND user_id = :user_id";
            $params['user_id'] = $userId;
        }
        
        $sql .= " GROUP BY user_id, category 
                  ORDER BY user_id, total_amount DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Получает агрегированные расходы по периодам (день/неделя/месяц)
     *
     * @param string $period Тип периода: 'day', 'week', 'month'
     * @param string|null $startDate Начальная дата (Y-m-d)
     * @param string|null $endDate Конечная дата (Y-m-d)
     * @param int|null $userId Фильтр по пользователю (опционально)
     * @return array Список агрегированных данных по периодам
     */
    public function getAggregatedByPeriod(string $period = 'month', ?string $startDate = null, ?string $endDate = null, ?int $userId = null): array
    {
        // Определяем формат группировки в зависимости от периода
        switch ($period) {
            case 'day':
                $dateFormat = '%Y-%m-%d';
                break;
            case 'week':
                $dateFormat = '%Y-%u';
                break;
            case 'month':
            default:
                $dateFormat = '%Y-%m';
                break;
        }
        
        $sql = "SELECT user_id, 
                       DATE_FORMAT(ts, '$dateFormat') as period,
                       category,
                       SUM(amount) as total_amount, 
                       COUNT(*) as count
                FROM expenses 
                WHERE 1=1";
        
        $params = [];
        
        if ($startDate !== null) {
            $sql .= " AND ts >= :start_date";
            $params['start_date'] = $startDate . ' 00:00:00';
        }
        
        if ($endDate !== null) {
            $sql .= " AND ts <= :end_date";
            $params['end_date'] = $endDate . ' 23:59:59';
        }
        
        if ($userId !== null) {
            $sql .= " AND user_id = :user_id";
            $params['user_id'] = $userId;
        }
        
        $sql .= " GROUP BY user_id, period, category 
                  ORDER BY period DESC, user_id, category";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}