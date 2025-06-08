-- Настройки кодировки
SET NAMES 'utf8mb4';
SET character_set_database = 'utf8mb4';
SET character_set_server = 'utf8mb4';

-- Создаем базу
CREATE DATABASE IF NOT EXISTS telegram_bot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE telegram_bot;

-- Таблица пользователей
CREATE TABLE IF NOT EXISTS users (
                                     id BIGINT UNSIGNED PRIMARY KEY COMMENT 'Уникальный ID пользователя (Telegram ID)',
                                     dashboard_token VARCHAR(64) DEFAULT NULL COMMENT 'Токен для доступа к веб-дашборду',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата и время регистрации пользователя',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Дата и время последнего обновления профиля'
    ) COMMENT='Таблица пользователей';

-- Таблица расходов (expenses)
CREATE TABLE IF NOT EXISTS expenses (
                                        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT 'Уникальный ID записи о расходе',
                                        user_id BIGINT UNSIGNED NOT NULL COMMENT 'ID пользователя',
                                        name VARCHAR(255) NOT NULL COMMENT 'Исходная метка (например, еда, такси)',
    category VARCHAR(100) NOT NULL COMMENT 'Категория, определенная классификатором',
    amount DECIMAL(10,2) NOT NULL COMMENT 'Сумма траты (всегда положительное число)',
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата и время записи',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) COMMENT='История расходов пользователей';

-- Таблица лимитов (limits)
CREATE TABLE IF NOT EXISTS limits (
                                      user_id BIGINT UNSIGNED NOT NULL COMMENT 'ID пользователя',
                                      category VARCHAR(100) NOT NULL COMMENT 'Категория расходов',
    `limit` DECIMAL(10,2) NOT NULL COMMENT 'Лимит на категорию в текущем месяце',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Дата последнего изменения лимита',
    PRIMARY KEY (user_id, category),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) COMMENT='Лимиты расходов по категориям';
