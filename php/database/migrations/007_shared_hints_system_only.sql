-- Общий словарь (category_hints.user_id = 0) — только системные категории.
--
-- До 27.09.2026 CategoryHint::remember() писал в общий словарь любую выбранную
-- категорию, в том числе свою категорию книги («вкусняшки»).
-- У остальных книг такой категории нет: ExpenseIntakeService::parse() отбрасывает
-- подсказку, и позиция уходит в Gemini, хотя до правки общий словарь отвечал
-- системной категорией. Туда же попали записи с категориями, которые убрали
-- из системных («коммуналка», «спортпит»), — их тоже не видит никто.
--
-- Удаляются только общие записи: у каждой такой строки есть личная копия
-- (remember() писал обе сразу), так что книга-владелец ничего не теряет.
--
-- Локально:
--
--   docker compose exec -T mysql mysql --default-character-set=utf8mb4 -uroot -proot telegram_bot < php/database/migrations/007_shared_hints_system_only.sql
--
-- Идемпотентна: повторный запуск ничего не удалит.

DELETE h FROM category_hints h
WHERE h.user_id = 0
  AND NOT EXISTS (SELECT 1 FROM categories c WHERE c.user_id = 0 AND c.name = h.category);
