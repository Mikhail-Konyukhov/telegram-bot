-- Словарь категорий вместо zero-shot классификатора.
--
-- init.sql применяется только на пустом томе mysql_data, поэтому на живой базе
-- эту миграцию нужно прогнать руками:
--
--   docker compose exec -T mysql mysql --default-character-set=utf8mb4 -uroot -proot telegram_bot < php/database/migrations/001_category_hints.sql
--
-- `--default-character-set=utf8mb4` обязателен: клиент mysql 5.7 по умолчанию
-- работает в latin1 и молча кладёт кириллицу в utf8mb4-колонку дважды закодированной.
-- Повторный запуск упадёт на CREATE TABLE — она одноразовая.

USE telegram_bot;

CREATE TABLE IF NOT EXISTS category_hints (
    user_id BIGINT NOT NULL COMMENT 'ID пользователя, 0 — общий словарь',
    name_norm VARCHAR(190) NOT NULL COMMENT 'Нормализованное название (см. NameNormalizer)',
    category VARCHAR(100) NOT NULL COMMENT 'Категория, победившая последней',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Когда подсказка обновлялась',
    PRIMARY KEY (user_id, name_norm)
) COMMENT='Словарь категорий: личный и общий';

-- Разовый посев из накопленной истории, чтобы словарь не начинал с нуля.
-- Нормализация тут беднее, чем в NameNormalizer (SQL не умеет чистить единицы
-- измерения) — остальное доберётся само при следующем вводе.
INSERT IGNORE INTO category_hints (user_id, name_norm, category)
SELECT e.user_id,
       LOWER(REPLACE(TRIM(e.name), 'ё', 'е')),
       e.category
FROM expenses e
         JOIN (SELECT user_id, name, MAX(ts) AS ts
               FROM expenses
               GROUP BY user_id, name) last
              ON last.user_id = e.user_id AND last.name = e.name AND last.ts = e.ts
WHERE TRIM(e.name) <> '';

-- Тот же посев в общий словарь.
INSERT IGNORE INTO category_hints (user_id, name_norm, category)
SELECT 0, h.name_norm, h.category
FROM category_hints h
WHERE h.user_id <> 0;
