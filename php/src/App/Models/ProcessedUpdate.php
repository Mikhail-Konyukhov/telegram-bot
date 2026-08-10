<?php

namespace App\Models;

use App\Database;
use PDO;

/**
 * Отметки об обработанных апдейтах Telegram.
 *
 * Доставка из RabbitMQ — at-least-once: если воркер упал между записью трат и
 * подтверждением сообщения, оно придёт повторно. Без этой таблицы трата
 * записалась бы дважды — ровно то, из-за чего в своё время убрали повторные
 * попытки из вебхука (см. {@see \App\Controllers\Handlers\ExpenseHandler}).
 *
 * Отметка ставится в той же транзакции, что и сами траты, иначе остаётся окно,
 * в котором траты уже записаны, а апдейт ещё не помечен.
 */
class ProcessedUpdate
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function isProcessed(int $updateId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM processed_updates WHERE update_id = ?');
        $stmt->execute([$updateId]);

        return $stmt->fetchColumn() !== false;
    }

    public function mark(int $updateId): void
    {
        $stmt = $this->db->prepare('INSERT IGNORE INTO processed_updates (update_id) VALUES (?)');
        $stmt->execute([$updateId]);
    }
}
