-- Настройки кодировки
SET NAMES 'utf8mb4';
SET character_set_database = 'utf8mb4';
SET character_set_server = 'utf8mb4';

-- Создаем базу
CREATE DATABASE IF NOT EXISTS telegram_bot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE telegram_bot;

-- Таблица пользователей
CREATE TABLE IF NOT EXISTS users (
                                     id BIGINT PRIMARY KEY COMMENT 'Уникальный ID пользователя (Telegram ID)',
                                     dashboard_token VARCHAR(64) DEFAULT NULL COMMENT 'Токен для доступа к веб-дашборду',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата и время регистрации пользователя',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Дата и время последнего обновления профиля'
    ) COMMENT='Таблица пользователей';

-- Таблица расходов (expenses)
CREATE TABLE IF NOT EXISTS expenses (
                                        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT 'Уникальный ID записи о расходе',
                                        user_id BIGINT NOT NULL COMMENT 'ID пользователя',
                                        name VARCHAR(255) NOT NULL COMMENT 'Исходная метка (например, еда, такси)',
    category VARCHAR(100) NOT NULL COMMENT 'Категория, определенная классификатором',
    amount DECIMAL(10,2) NOT NULL COMMENT 'Сумма траты (всегда положительное число)',
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата и время записи',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) COMMENT='История расходов пользователей';

-- Таблица категорий (categories)
CREATE TABLE IF NOT EXISTS categories (
                                          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT 'Уникальный ID категории',
                                          user_id BIGINT NOT NULL COMMENT 'ID пользователя (для персональных категорий)',
                                          name VARCHAR(100) NOT NULL COMMENT 'Название категории',
    is_default BOOLEAN DEFAULT FALSE COMMENT 'Является ли категория системной (по умолчанию)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата создания категории',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Дата последнего обновления',
    UNIQUE KEY unique_user_category (user_id, name),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) COMMENT='Категории расходов пользователей';

-- Таблица лимитов (limits)
CREATE TABLE IF NOT EXISTS limits (
                                      user_id BIGINT NOT NULL COMMENT 'ID пользователя',
                                      category VARCHAR(100) NOT NULL COMMENT 'Категория расходов',
    `limit` DECIMAL(10,2) NOT NULL COMMENT 'Лимит на категорию в текущем месяце',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Дата последнего изменения лимита',
    PRIMARY KEY (user_id, category),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) COMMENT='Лимиты расходов по категориям';

-- Словарь «название траты → категория»: первые два уровня классификации.
-- user_id = 0 — общий словарь, из него берутся категории для новых
-- пользователей, у которых своей истории ещё нет. Внешнего ключа нет
-- намеренно: 0 не соответствует ни одному реальному пользователю.
CREATE TABLE IF NOT EXISTS category_hints (
                                              user_id BIGINT NOT NULL COMMENT 'ID пользователя, 0 — общий словарь',
                                              name_norm VARCHAR(190) NOT NULL COMMENT 'Нормализованное название (см. NameNormalizer)',
    category VARCHAR(100) NOT NULL COMMENT 'Категория, победившая последней',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Когда подсказка обновлялась',
    PRIMARY KEY (user_id, name_norm)
    ) COMMENT='Словарь категорий: личный и общий';

-- Псевдопользователь для системных строк. Нужен именно здесь: на categories.user_id
-- висит внешний ключ на users.id, и без этой строки сид ниже нарушает его, а
-- INSERT IGNORE превращает нарушение в warning — категории молча не вставляются,
-- getUserCategories() возвращает пустой список и бот перестаёт раскладывать траты.
INSERT IGNORE INTO users (id) VALUES (0);

-- Добавляем системные категории по умолчанию для всех пользователей (user_id = 0 - системные)
INSERT IGNORE INTO categories (user_id, name, is_default) VALUES
(0, 'еда', TRUE),
(0, 'транспорт', TRUE),
(0, 'жилье', TRUE),
(0, 'одежда', TRUE),
(0, 'развлечения', TRUE),
(0, 'здоровье', TRUE),
(0, 'гигиена', TRUE),
(0, 'техника', TRUE),
(0, 'коммуналка', TRUE),
(0, 'спортпит', TRUE),
(0, 'кафе и рестораны', TRUE),
(0, 'домашние животные', TRUE),
(0, 'товары для дома', TRUE);
