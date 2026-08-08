<?php

namespace App\Models;

use App\Database;
use App\Services\NameNormalizer;
use PDO;

/**
 * Словарь «название траты → категория».
 *
 * Первые два уровня классификации, оба бесплатные: личный словарь пользователя
 * и общий, накопленный всеми. Модель вызывается только на том, чего нет ни там,
 * ни там. Общий словарь нужен ради холодного старта — новому пользователю
 * без истории «такси» и «кофе» проставляются сразу.
 *
 * В общей записи (user_id = 0) хранится только пара «строка → категория»,
 * без привязки к тому, кто её ввёл.
 */
class CategoryHint
{
    /** user_id общих подсказок — 0 не может быть чьим-то Telegram ID */
    public const SHARED = 0;

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Ищет категорию по названию: сначала в личном словаре, потом в общем.
     *
     * @return string|null null, если строка не встречалась ни разу
     */
    public function find(int $userId, string $name): ?string
    {
        $norm = NameNormalizer::normalize($name);
        if ($norm === '') {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT category FROM category_hints
             WHERE name_norm = :name_norm AND user_id IN (:user_id, :shared)
             ORDER BY user_id DESC
             LIMIT 1"
        );
        $stmt->execute([
            'name_norm' => $norm,
            'user_id'   => $userId,
            'shared'    => self::SHARED,
        ]);

        $category = $stmt->fetchColumn();

        return $category !== false ? (string)$category : null;
    }

    /**
     * Запоминает выбор категории — и для пользователя, и в общий словарь.
     *
     * Побеждает последняя запись, а не самая частая: правка пользователя должна
     * действовать сразу, а не после того, как перевесит по числу голосов.
     */
    public function remember(int $userId, string $name, string $category): void
    {
        $norm = NameNormalizer::normalize($name);
        if ($norm === '') {
            return;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO category_hints (user_id, name_norm, category)
             VALUES (:user_id, :name_norm, :category)
             ON DUPLICATE KEY UPDATE category = VALUES(category)"
        );

        foreach ([$userId, self::SHARED] as $scope) {
            $stmt->execute([
                'user_id'   => $scope,
                'name_norm' => $norm,
                'category'  => $category,
            ]);
        }
    }
}
