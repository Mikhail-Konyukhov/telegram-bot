<?php

namespace App\Models;

use App\Database;
use PDO;

/**
 * Кэш проверок «состоит ли пользователь в чате».
 *
 * Mini App при открытии дёргает несколько эндпоинтов подряд, и спрашивать
 * Telegram на каждом — это лишние сотни миллисекунд на запрос. Ответ живёт
 * недолго намеренно: выход из группы должен закрывать доступ к её книге
 * трат в пределах часа, а не навсегда.
 */
class ChatMember
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Проверялось ли членство не позже, чем $ttl секунд назад.
     */
    public function isFresh(int $chatId, int $userId, int $ttl): bool
    {
        // TTL подставляется в текст запроса: MySQL не везде принимает плейсхолдер
        // в INTERVAL. Значение приходит константой из кода, но приведение к int
        // оставлено, чтобы это не стало дырой при первом же новом вызывающем.
        $stmt = $this->db->prepare(
            "SELECT 1 FROM chat_members
             WHERE chat_id = :chat_id
               AND user_id = :user_id
               AND checked_at >= DATE_SUB(NOW(), INTERVAL " . (int)$ttl . " SECOND)"
        );
        $stmt->execute([
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * Отмечает, что членство только что подтвердилось.
     */
    public function remember(int $chatId, int $userId): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO chat_members (chat_id, user_id) VALUES (:chat_id, :user_id)
             ON DUPLICATE KEY UPDATE checked_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    /**
     * Убирает отметку — пользователь в чате больше не состоит.
     */
    public function forget(int $chatId, int $userId): void
    {
        $stmt = $this->db->prepare(
            "DELETE FROM chat_members WHERE chat_id = :chat_id AND user_id = :user_id"
        );
        $stmt->execute([
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }
}
