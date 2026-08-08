-- Починка сида системных категорий на уже созданной базе.
--
-- На categories.user_id висит внешний ключ на users.id. Сид в init.sql вставлял
-- категории с user_id = 0, когда такого пользователя нет, — INSERT IGNORE глотал
-- нарушение ключа как warning, и таблица categories оставалась пустой. Внешне это
-- выглядело как «бот не может определить категорию ни у одной траты»:
-- getUserCategories() отдавал пустой список, а классификатор без категорий
-- не возвращает ничего.
--
--   docker compose -f docker-compose.prod.yml exec -T mysql \
--     sh -c 'mysql --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" telegram_bot' \
--     < php/database/migrations/002_seed_system_categories.sql
--
-- `--default-character-set=utf8mb4` обязателен: клиент mysql 5.7 по умолчанию
-- работает в latin1 и молча кладёт кириллицу в utf8mb4-колонку дважды
-- закодированной («еда» вместо d0b5d0b4d0b0 становится C390C2B5...).
-- Идемпотентна: повторный запуск ничего не изменит.

INSERT IGNORE INTO users (id) VALUES (0);

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
