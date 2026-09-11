<?php

namespace App\Models;

use App\Database;
use PDO;

/**
 * Модель для работы с категориями расходов
 */
class Category
{
    /**
     * Категория для трат, которым её не подобрали.
     *
     * Раньше такая позиция просто выбрасывалась: пользователь видел «не удалось
     * определить», а трата не попадала никуда — ни в историю, ни в лимиты, и
     * восстановить её было неоткуда. Теперь она записывается как есть.
     *
     * В `categories` этой строки нет и быть не должно: она не выбирается
     * вручную, не предлагается кнопками и живёт ровно до того момента, как
     * пользователь укажет настоящую. В общий лимит трата попадает сразу —
     * он считается по сумме всех трат, независимо от категории.
     */
    public const UNCATEGORIZED = 'без категории';

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * Получить все категории для пользователя (системные + персональные)
     *
     * @param int $userId ID пользователя
     * @return array Массив названий категорий
     */
    public function getUserCategories(int $userId): array
    {
        $sql = "SELECT name FROM categories 
                WHERE user_id = 0 OR user_id = :user_id 
                ORDER BY is_default DESC, name ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Добавить персональную категорию пользователя
     *
     * @param int $userId ID пользователя
     * @param string $name Название категории
     * @return bool Успешность операции
     */
    public function addUserCategory(int $userId, string $name): bool
    {
        $name = trim($name);
        if (empty($name)) {
            return false;
        }

        $sql = "INSERT INTO categories (user_id, name, is_default) 
                VALUES (:user_id, :name, FALSE)";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindParam(':name', $name, PDO::PARAM_STR);
            return $stmt->execute();
        } catch (\PDOException $e) {
            // Игнорируем ошибку дублирования (UNIQUE constraint)
            if ($e->getCode() == 23000) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Удалить персональную категорию пользователя
     *
     * @param int $userId ID пользователя
     * @param string $name Название категории
     * @return bool Успешность операции
     */
    public function deleteUserCategory(int $userId, string $name): bool
    {
        $sql = "DELETE FROM categories 
                WHERE user_id = :user_id AND name = :name AND is_default = FALSE";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        
        return $stmt->execute() && $stmt->rowCount() > 0;
    }

    /**
     * Получить только персональные категории пользователя
     *
     * @param int $userId ID пользователя
     * @return array Массив категорий с полной информацией
     */
    public function getUserPersonalCategories(int $userId): array
    {
        $sql = "SELECT id, name, created_at FROM categories 
                WHERE user_id = :user_id AND is_default = FALSE 
                ORDER BY name ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Проверить, существует ли категория у пользователя
     *
     * @param int $userId ID пользователя
     * @param string $name Название категории
     * @return bool Существует ли категория
     */
    public function categoryExists(int $userId, string $name): bool
    {
        $sql = "SELECT COUNT(*) FROM categories 
                WHERE (user_id = 0 OR user_id = :user_id) AND name = :name";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        $stmt->execute();
        
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Получить системные категории по умолчанию
     *
     * @return array Массив названий системных категорий
     */
    public function getDefaultCategories(): array
    {
        $sql = "SELECT name FROM categories WHERE user_id = 0 AND is_default = TRUE ORDER BY name ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
} 