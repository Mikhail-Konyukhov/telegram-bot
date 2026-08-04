# Быстрый старт DataLens

## Проверка работы API

### 1. Убедитесь, что проект запущен
```bash
docker compose ps
```
Должны быть запущены контейнеры: `php-bot`, `mysql-db`, `classify-api`, `ngrok`

### 2. Проверьте ngrok URL
Откройте в браузере: http://localhost:4040

Скопируйте HTTPS URL (например: `https://abc123.ngrok-free.app`)

### 3. Протестируйте API локально

**Windows:**
```bash
test-datalens-api.bat
```

**Linux/Mac** (нужен `jq`):
```bash
chmod +x test-datalens-api.sh
./test-datalens-api.sh
```

**Или вручную:**
```bash
curl "http://localhost:8081/api.php?endpoint=expenses"
```

### 4. Настройте DataLens (5 минут)

1. Войдите на https://datalens.yandex.ru
2. Создайте подключение "HTTP API"
3. URL: `https://YOUR-NGROK-URL/api.php`
4. Создайте датасет с параметром `?endpoint=expenses`
5. Создайте визуализацию

## Доступные endpoint'ы

| Endpoint | Описание | Пример URL |
|----------|----------|------------|
| `expenses` | Все расходы | `?endpoint=expenses` |
| `expenses-by-category` | По категориям | `?endpoint=expenses-by-category` |
| `expenses-by-period` | По периодам | `?endpoint=expenses-by-period&period=month` |
| `limits` | Лимиты | `?endpoint=limits` |
| `expenses-vs-limits` | Факт vs план | `?endpoint=expenses-vs-limits` |

## Формат данных DataLens

```json
{
  "columns": [
    {"name": "category", "type": "string"},
    {"name": "amount", "type": "float"}
  ],
  "data": [
    ["еда", 1500.00],
    ["транспорт", 800.00]
  ]
}
```

## Безопасность (опционально)

Добавьте в `php/config.txt`:
```
DATALENS_API_TOKEN=ваш_случайный_токен
```

Используйте токен в DataLens:
```
Authorization: Bearer ваш_токен
```

> **Внимание:** `php/config.txt` заново генерируется контейнером `init-script`
> на каждом `docker compose up`, поэтому строку придётся дописывать после каждого
> запуска. Подробности — в [DATALENS_SETUP.md](DATALENS_SETUP.md#настройка-безопасности-опционально).

## Полная документация

См. подробную инструкцию: [DATALENS_SETUP.md](DATALENS_SETUP.md)

## Типовые проблемы

**API возвращает пустой ответ?**
- Добавьте данные через Telegram бота
- Проверьте: `docker compose logs php`
- Если БД пуста и на чистом томе — убедитесь, что схема применилась:
  `docker compose down -v && docker compose up --build`

**API отдаёт HTML вместо JSON?**
- Проверяйте именно `/api.php?endpoint=...`. Rewrite-правил во встроенном сервере
  PHP нет, путь `/api/datalens` не существует и уходит в `index.php`

**DataLens не подключается?**
- Используйте HTTPS URL от ngrok
- Проверьте, что контейнеры запущены

**Ngrok URL изменился?**
- Обновите URL в настройках DataLens
- При каждом перезапуске ngrok генерирует новый URL


