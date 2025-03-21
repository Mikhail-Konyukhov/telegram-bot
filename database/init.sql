SET NAMES 'utf8mb4';
SET character_set_database = 'utf8mb4';
SET character_set_server = 'utf8mb4';

CREATE DATABASE IF NOT EXISTS telegram_bot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE telegram_bot;

-- Таблица пользователей
CREATE TABLE IF NOT EXISTS users (
                                     id BIGINT UNSIGNED PRIMARY KEY COMMENT 'Уникальный ID пользователя (Telegram ID)',
                                     balance DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Текущий баланс пользователя в долларах',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата и время регистрации пользователя',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Дата и время последнего обновления баланса'
    ) COMMENT='Таблица пользователей с балансом';

-- Таблица транзакций (пополнения и списания)
CREATE TABLE IF NOT EXISTS transactions (
                                            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT 'Уникальный ID транзакции',
                                            user_id BIGINT UNSIGNED NOT NULL COMMENT 'ID пользователя, к которому относится транзакция',
                                            amount DECIMAL(10,2) NOT NULL COMMENT 'Сумма транзакции: положительная - пополнение, отрицательная - списание',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата и время совершения транзакции',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) COMMENT='История транзакций пользователей (пополнения и списания)';
