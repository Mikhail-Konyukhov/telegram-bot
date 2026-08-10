-- Дедупликация апдейтов Telegram: обработка трат уехала в очередь.
--
-- RabbitMQ доставляет at-least-once. Если воркер упал между записью трат и
-- подтверждением сообщения, оно вернётся в очередь и обработается второй раз —
-- без отметки трата записалась бы дважды. Отметка ставится в одной транзакции
-- с самими тратами (см. bin/worker.php).
--
-- Локально:
--
--   docker compose exec -T mysql mysql --default-character-set=utf8mb4 -uroot -proot telegram_bot < php/database/migrations/005_processed_updates.sql
--
-- На проде:
--
--   docker compose -f docker-compose.prod.yml exec -T mysql \
--     sh -c 'mysql --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" telegram_bot' \
--     < php/database/migrations/005_processed_updates.sql
--
-- Идемпотентна.
--
-- Таблица растёт без ограничений, но строка занимает единицы байт. Если однажды
-- станет тесно, старое чистится безопасно — апдейты этого возраста Telegram
-- уже не переприсылает:
--   DELETE FROM processed_updates WHERE created_at < NOW() - INTERVAL 30 DAY;

CREATE TABLE IF NOT EXISTS processed_updates (
    update_id  BIGINT NOT NULL PRIMARY KEY COMMENT 'update_id из апдейта Telegram',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Когда апдейт был обработан'
) COMMENT='Обработанные апдейты: доставка из очереди at-least-once';
