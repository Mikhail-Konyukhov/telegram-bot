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