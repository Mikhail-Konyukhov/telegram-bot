-- Общая книга трат на группу: кэш проверок членства.
--
-- Книга = чат. В группе траты пишутся под id самой группы, а Mini App узнаёт,
-- какую книгу открывать, из ?startapp=. Параметр задаёт клиент, поэтому право
-- на чужую книгу подтверждается у Telegram через getChatMember — эта таблица
-- держит результат час, чтобы не дёргать API на каждый запрос приложения.
--
--   docker compose -f docker-compose.prod.yml exec -T mysql \
--     sh -c 'mysql --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" telegram_bot' \
--     < php/database/migrations/003_chat_members.sql
--
-- Идемпотентна.

CREATE TABLE IF NOT EXISTS chat_members (
    chat_id BIGINT NOT NULL COMMENT 'ID группы',
    user_id BIGINT NOT NULL COMMENT 'ID пользователя Telegram',
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Когда членство подтверждалось',
    PRIMARY KEY (chat_id, user_id)
) COMMENT='Кэш членства в группах (getChatMember)';
