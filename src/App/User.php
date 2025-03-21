<?php

namespace App;

use PDO;

/**
 * Класс User отвечает за управление пользователями и их балансами.
 */
class User
{
    /**
     * @var PDO $db Экземпляр подключения к базе данных.
     */
    private PDO $db;

    /**
     * Конструктор класса.
     * Устанавливает соединение с базой данных.
     */
    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Регистрирует пользователя, если его нет в базе данных.
     *
     * @param int $userId Telegram ID пользователя.
     * @return void
     */
    public function registerUser(int $userId): void
    {
        $stmt = $this->db->prepare("INSERT IGNORE INTO users (id, balance) VALUES (:id, 0.00)");
        $stmt->execute(['id' => $userId]);
    }

    /**
     * Проверяет, существует ли пользователь в базе данных.
     *
     * @param int $userId Telegram ID пользователя.
     * @return bool Возвращает true, если пользователь найден, иначе false.
     */
    public function isExists(int $userId): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $count = $stmt->fetchColumn();

        return $count > 0;
    }

    /**
     * Пополняет или списывает средства с баланса пользователя.
     *
     * @param int $userId Telegram ID пользователя.
     * @param float|string $amount Сумма для пополнения (положительное число) или списания (отрицательное число).
     * @return string Сообщение с новым балансом или ошибкой.
     */
    public function updateBalance(int $userId, $amount): string
    {
        $this->db->beginTransaction();

        // Получение текущего баланс
        $stmt = $this->db->prepare("SELECT balance FROM users WHERE id = :id FOR UPDATE");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            $this->db->rollBack();
            return "Ошибка: пользователь не найден.";
        }

        $amount = str_replace(',', '.', (string) $amount);

        error_log("Сумма до округления: " . $amount);
        $amount = round((float) $amount, 2);
        error_log("Сумма после округления: " . $amount);

        $newBalance = $user['balance'] + $amount;

        if ($newBalance < 0) {
            $this->db->rollBack();
            return "Ошибка: недостаточно средств на счете.";
        }

        // Обновление баланса
        $stmt = $this->db->prepare("UPDATE users SET balance = :balance WHERE id = :id");
        $stmt->execute(['balance' => $newBalance, 'id' => $userId]);

        // Запись транзакции
        $stmt = $this->db->prepare("INSERT INTO transactions (user_id, amount) VALUES (:id, :amount)");
        $stmt->execute(['id' => $userId, 'amount' => $amount]);

        $this->db->commit();
        return "Ваш баланс: $" . number_format($newBalance, 2);
    }
}
