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

    /**
     * Токены короче этого в словаре не ищем: предлоги и остатки чисел совпадений
     * не дают, а список параметров запроса раздувают.
     */
    private const MIN_TOKEN = 3;

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

        return $this->findExact($userId, $norm) ?? $this->findByToken($userId, $norm);
    }

    /**
     * Точное совпадение по всей нормализованной строке.
     */
    private function findExact(int $userId, string $norm): ?string
    {
        // ORDER BY по признаку «это общая запись», а не по самому user_id:
        // книга группы — это её chat_id, а он отрицательный, и обычное
        // `ORDER BY user_id DESC` ставило общий словарь (0) впереди личного.
        // Константа подставляется дважды, потому что позиционные плейсхолдеры
        // нельзя переиспользовать.
        $stmt = $this->db->prepare(
            "SELECT category FROM category_hints
             WHERE name_norm = ? AND user_id IN (?, ?)
             ORDER BY user_id = ? ASC
             LIMIT 1"
        );
        $stmt->execute([$norm, $userId, self::SHARED, self::SHARED]);

        $category = $stmt->fetchColumn();

        return $category !== false ? (string)$category : null;
    }

    /**
     * Совпадение по отдельному слову: «молоко простоквашино» находит «молоко».
     *
     * Точное совпадение промахивается на всём, что длиннее одного слова, — а в
     * чек попадают и марка, и объём, и название магазина. Однословные записи
     * приходят из импорта общего словаря ({@see \bin/import-hints.php});
     * {@see remember()} по-прежнему пишет только строку целиком, иначе правки
     * пользователей растащили бы словарь на отдельные слова.
     */
    private function findByToken(int $userId, string $norm): ?string
    {
        $tokens = array_values(array_filter(
            explode(' ', $norm),
            static fn(string $token): bool => mb_strlen($token) >= self::MIN_TOKEN
        ));

        // Ровно то же самое уже спросил findExact() — второй раз незачем.
        if ($tokens === [] || $tokens === [$norm]) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($tokens), '?'));

        // Побеждает личная запись, при равенстве — более длинная: она конкретнее.
        // Обе колонки сортировки берутся из строк, уже найденных по первичному
        // ключу, поэтому отдельный индекс не нужен.
        $stmt = $this->db->prepare(
            "SELECT category FROM category_hints
             WHERE user_id IN (?, ?) AND name_norm IN ({$placeholders})
             ORDER BY user_id = ? ASC, CHAR_LENGTH(name_norm) DESC
             LIMIT 1"
        );
        $stmt->execute(array_merge([$userId, self::SHARED], $tokens, [self::SHARED]));

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
