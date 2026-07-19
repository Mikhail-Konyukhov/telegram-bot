<?php

namespace App\Models;

use App\Database;
use PDO;

/**
 * Class Limit
 *
 * Управление лимитами пользователя по категориям.
 *
 * @package App\Models
 */
class Limit
{
    /** @var string Специальное имя категории для общего лимита */
    public const GLOBAL_CATEGORY = '__global__';

    /** @var PDO */
    private PDO $db;

    /**
     * Limit constructor.
     */
    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Устанавливает или обновляет лимит по категории.
     *
     * @param int $userId
     * @param string $category
     * @param float $limit
     * @return void
     */
    public function set(int $userId, string $category, float $limit): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO limits (user_id, category, `limit`)
             VALUES (:user_id, :category, :limit)
             ON DUPLICATE KEY UPDATE `limit` = VALUES(`limit`), updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([
            'user_id'  => $userId,
            'category' => $category,
            'limit'    => $limit,
        ]);
    }

    /**
     * Возвращает лимит пользователя по категории.
     *
     * @param int $userId
     * @param string $category
     * @return float|null
     */
    public function get(int $userId, string $category): ?float
    {
        $stmt = $this->db->prepare(
            "SELECT `limit` FROM limits
             WHERE user_id = :user_id AND category = :category"
        );
        $stmt->execute([
            'user_id'  => $userId,
            'category' => $category,
        ]);
        $value = $stmt->fetchColumn();
        return $value !== false ? (float) $value : null;
    }

    /**
     * Возвращает все лимиты пользователя.
     *
     * @param int $userId
     * @return array<string, float>
     */
    public function getAll(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT category, `limit` FROM limits WHERE user_id = :user_id"
        );
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[$row['category']] = (float) $row['limit'];
        }
        return $result;
    }

    /**
     * Устанавливает общий лимит на все категории.
     *
     * @param int $userId
     * @param float $limit
     * @return void
     */
    public function setGlobal(int $userId, float $limit): void
    {
        $this->set($userId, self::GLOBAL_CATEGORY, $limit);
    }

    /**
     * Возвращает общий лимит пользователя на все категории.
     *
     * @param int $userId
     * @return float|null
     */
    public function getGlobal(int $userId): ?float
    {
        return $this->get($userId, self::GLOBAL_CATEGORY);
    }

    /**
     * Получает все лимиты для DataLens с опциональной фильтрацией по пользователю
     *
     * @param int|null $userId Фильтр по пользователю (опционально)
     * @return array Список лимитов
     */
    public function getAllForDataLens(?int $userId = null): array
    {
        $sql = "SELECT user_id, category, `limit`, updated_at 
                FROM limits 
                WHERE 1=1";
        
        $params = [];
        
        if ($userId !== null) {
            $sql .= " AND user_id = :user_id";
            $params['user_id'] = $userId;
        }
        
        $sql .= " ORDER BY user_id, category";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}