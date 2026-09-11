-- Слияние книги трат после апгрейда группы до супергруппы.
--
-- 10.08.2026 группа была переведена в супергруппу, и Telegram выдал чату новый
-- id: -4727194767 → -1004469856007. Книга трат — это чат (expenses.user_id =
-- chat_id), поэтому бот завёл пустую книгу и начал с нуля. Под старым id
-- остались 2392 траты, 3 своих категории, 6 лимитов и 899 личных подсказок
-- словаря — из-за этого /stats не показывал ничего раньше 10 августа, а раздел
-- «Лимиты» сообщал, что лимиты не заданы.
--
-- Повтор этой ситуации закрывает User::migrate(), вызываемый из Bot::handleUpdate
-- по служебному сообщению migrate_to_chat_id/migrate_from_chat_id. Эта миграция
-- разбирает только уже случившийся переезд.
--
-- Локально:
--
--   docker compose exec -T mysql mysql --default-character-set=utf8mb4 -uroot -proot telegram_bot < php/database/migrations/006_merge_migrated_chat.sql
--
-- На проде:
--
--   docker compose -f docker-compose.prod.yml exec -T mysql \
--     sh -c 'mysql --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" telegram_bot' \
--     < php/database/migrations/006_merge_migrated_chat.sql
--
-- `--default-character-set=utf8mb4` обязателен: клиент mysql 5.7 по умолчанию
-- работает в latin1 и молча кладёт кириллицу в utf8mb4-колонку дважды закодированной.
--
-- ПЕРЕД ЗАПУСКОМ СНИМИТЕ ДАМП — перенос необратим:
--   docker compose exec -T mysql mysqldump --default-character-set=utf8mb4 -uroot -proot \
--     telegram_bot users expenses categories limits category_hints chat_members > backup.sql
--
-- Идемпотентна: повторный запуск не найдёт строк под старым id.

START TRANSACTION;

-- Новая книга уже заведена ботом, но на categories/limits/expenses висит внешний
-- ключ на users.id, и на пустой базе миграция без этой строки упала бы.
INSERT IGNORE INTO users (id) VALUES (-1004469856007);

-- У expenses уникальных ключей на user_id нет, поэтому UPDATE без IGNORE:
-- пропущенная строка означала бы потерянную трату.
UPDATE expenses SET user_id = -1004469856007 WHERE user_id = -4727194767;

-- В categories, limits и category_hints уникальный ключ включает user_id, и
-- одноимённая запись может быть в обеих книгах (личных подсказок новая успела
-- накопить 40). IGNORE оставляет столкнувшуюся строку под старым id, DELETE её
-- убирает: побеждает новая книга — её значение пользователь правил последним.
UPDATE IGNORE categories      SET user_id = -1004469856007 WHERE user_id = -4727194767;
DELETE FROM categories        WHERE user_id = -4727194767;

UPDATE IGNORE `limits`        SET user_id = -1004469856007 WHERE user_id = -4727194767;
DELETE FROM `limits`          WHERE user_id = -4727194767;

UPDATE IGNORE category_hints  SET user_id = -1004469856007 WHERE user_id = -4727194767;
DELETE FROM category_hints    WHERE user_id = -4727194767;

-- Кэш членства живёт час и пересобирается сам, но строки чата, которого больше
-- нет, не пересоберутся никогда.
DELETE FROM chat_members WHERE chat_id = -4727194767;

COMMIT;

-- Строка в users под старым id остаётся намеренно: на ней внешний ключ с
-- ON DELETE CASCADE, и удаление снесло бы всё, что вдруг не перенеслось.
