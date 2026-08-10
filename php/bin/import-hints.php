<?php

/**
 * Заливка общего словаря категорий (`category_hints`, `user_id = 0`) из CSV.
 *
 *   docker compose exec php php bin/import-hints.php
 *   docker compose exec php php bin/import-hints.php database/my-hints.csv
 *
 * Формат файла — `название;категория`, UTF-8, по строке на запись. Пустые строки
 * и начинающиеся с `#` пропускаются, заголовок `name;category` распознаётся сам.
 *
 * Почему PHP, а не SQL-миграция: ключ словаря должен считать настоящий
 * {@see \App\Services\NameNormalizer} — на SQL его не повторить, и упрощённый
 * вариант в `migrations/001_category_hints.sql` уже отмечен там как более бедный.
 *
 * Записи должны быть короткими, в одно-два слова: поиск по токенам
 * ({@see \App\Models\CategoryHint::find()}) сопоставляет слова пользовательского
 * ввода с целыми записями словаря, поэтому «молоко питьевое пастеризованное 3.2%»
 * бесполезна, а «молоко» ловит весь ряд подобных чеков.
 *
 * Пишет `INSERT IGNORE`, то есть не затирает то, что уже накопилось от живых
 * пользователей. Перезалить словарь с нуля:
 *
 *   DELETE FROM category_hints WHERE user_id = 0;
 */

// Каталог bin/ лежит под докрутом встроенного сервера (`php -S -t /var/www/html`),
// то есть скрипт доступен и по HTTP. Через веб его запускать нельзя.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\Models\Category;
use App\Models\CategoryHint;
use App\Services\NameNormalizer;

/** Строк в одном INSERT: по одной запросу на запись стотысячный файл льётся минутами. */
const BATCH_SIZE = 500;

$path = $argv[1] ?? __DIR__ . '/../database/hints.csv';

if (!is_readable($path)) {
    fwrite(STDERR, "Не могу прочитать {$path}\n");
    exit(1);
}

// Категории сверяем со системными: подсказка на категорию, которой нет ни у кого,
// всё равно не пройдёт проверку in_array() в ExpenseIntakeService.
$known = [];
foreach ((new Category())->getDefaultCategories() as $name) {
    $known[mb_strtolower($name, 'UTF-8')] = $name;
}

if (!$known) {
    fwrite(STDERR, "В базе нет системных категорий — сначала прогоните init.sql или миграцию 002\n");
    exit(1);
}

$db = Database::getInstance()->getConnection();
$handle = fopen($path, 'r');

$stats = ['read' => 0, 'inserted' => 0, 'duplicate' => 0, 'bad_category' => 0, 'empty_name' => 0];
$batch = [];
$first = true;

while (($row = fgetcsv($handle, 0, ';')) !== false) {
    if ($first) {
        $first = false;
        // Excel и Google Sheets ставят BOM, из-за него первая ячейка не совпадает ни с чем.
        $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)($row[0] ?? ''));

        if (mb_strtolower(trim($row[0]), 'UTF-8') === 'name') {
            continue;
        }
    }

    if ($row === [null] || count($row) < 2) {
        continue;
    }

    $rawName = trim((string)$row[0]);
    if ($rawName === '' || str_starts_with($rawName, '#')) {
        continue;
    }

    $stats['read']++;

    $category = $known[mb_strtolower(trim((string)$row[1]), 'UTF-8')] ?? null;
    if ($category === null) {
        $stats['bad_category']++;
        continue;
    }

    $norm = NameNormalizer::normalize($rawName);
    if ($norm === '') {
        $stats['empty_name']++;
        continue;
    }

    $batch[] = [$norm, $category];

    if (count($batch) >= BATCH_SIZE) {
        $stats['inserted'] += flushBatch($db, $batch);
        $batch = [];
    }
}

$stats['inserted'] += flushBatch($db, $batch);
fclose($handle);

$stats['duplicate'] = $stats['read'] - $stats['inserted'] - $stats['bad_category'] - $stats['empty_name'];

printf(
    "Прочитано: %d\nВставлено: %d\nУже было: %d\nНеизвестная категория: %d\nПустое имя после нормализации: %d\n",
    $stats['read'],
    $stats['inserted'],
    $stats['duplicate'],
    $stats['bad_category'],
    $stats['empty_name']
);

/**
 * @param array<array{0: string, 1: string}> $batch
 * @return int Сколько строк реально вставилось (INSERT IGNORE молча пропускает дубли)
 */
function flushBatch(PDO $db, array $batch): int
{
    if (!$batch) {
        return 0;
    }

    $values = [];
    $params = [];

    foreach ($batch as [$norm, $category]) {
        $values[] = '(?, ?, ?)';
        array_push($params, CategoryHint::SHARED, $norm, $category);
    }

    $stmt = $db->prepare(
        'INSERT IGNORE INTO category_hints (user_id, name_norm, category) VALUES '
        . implode(', ', $values)
    );
    $stmt->execute($params);

    return $stmt->rowCount();
}
