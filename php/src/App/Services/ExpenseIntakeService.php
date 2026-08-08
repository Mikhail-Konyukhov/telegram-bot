<?php

namespace App\Services;

use App\Models\CategoryHint;
use App\Models\Expense;
use GuzzleHttp\Client as HttpClient;

/**
 * Разбор строки трат вида «кофе 300, такси 450» и определение категорий.
 *
 * Используется и ботом, и Mini App, чтобы логика ввода не расходилась.
 */
class ExpenseIntakeService
{
    private Expense $expenseModel;
    private CategoryHint $hints;
    private GeminiClassifier $gemini;

    public function __construct(HttpClient $http, string $geminiApiKey)
    {
        $this->expenseModel = new Expense();
        $this->hints = new CategoryHint();
        $this->gemini = new GeminiClassifier($http, $geminiApiKey);
    }

    /**
     * Разбирает текст на позиции и проставляет каждой категорию.
     *
     * Каскад: сначала словарь категорий (личный, затем общий) — он бесплатный
     * и точнее любой модели на повторных покупках. В Gemini уходит одним
     * запросом только то, чего в словаре не нашлось.
     *
     * @param int $userId
     * @param string $text
     * @param string[] $categories Категории пользователя
     * @return array{items: array, errors: string[]}
     */
    public function parse(int $userId, string $text, array $categories): array
    {
        $items = [];
        $errors = [];
        $unknown = [];

        foreach ($this->splitItems($text) as $index => $chunk) {
            $parsed = $this->splitNameAndPrice($chunk);

            if ($parsed === null) {
                $errors[] = "Не удалось разобрать «{$chunk}»";
                continue;
            }

            $known = $this->hints->find($userId, $parsed['name']);

            // Подсказка на удалённую категорию не должна её воскрешать.
            if ($known !== null && in_array($known, $categories, true)) {
                $items[$index] = $parsed + ['category' => $known, 'source' => 'hint'];
                continue;
            }

            $items[$index] = $parsed + ['category' => null, 'source' => 'gemini'];
            $unknown[$index] = $parsed['name'];
        }

        if ($unknown) {
            $guessed = $this->gemini->classify(array_values($unknown), $categories);

            foreach ($unknown as $index => $name) {
                if (isset($guessed[$name])) {
                    $items[$index]['category'] = $guessed[$name];
                } else {
                    $errors[] = "Не удалось определить категорию для «{$name}»";
                    unset($items[$index]);
                }
            }
        }

        return ['items' => array_values($items), 'errors' => $errors];
    }

    /**
     * Сохраняет разобранные позиции и пополняет словарь категорий.
     *
     * @param int $userId
     * @param array $items
     * @param string|null $date
     * @return array Позиции с проставленными id
     */
    public function save(int $userId, array $items, ?string $date = null): array
    {
        $date = $date ?? date('Y-m-d H:i:s');

        return array_map(function (array $item) use ($userId, $date) {
            $item['id'] = $this->expenseModel->add(
                $userId,
                $item['name'],
                $item['category'],
                (float)$item['price'],
                $date
            );

            // Ответ модели попадает в словарь, чтобы второй раз за него не платить.
            $this->hints->remember($userId, $item['name'], $item['category']);

            return $item;
        }, $items);
    }

    /**
     * Режет сообщение на позиции.
     *
     * Запятая — разделитель, только если за ней не идёт цифра: иначе
     * «кофе 300,50» распалось бы на «кофе 300» и «50», и трата записывалась
     * бы без копеек. Перевод строки разделяет всегда — так работает вставка
     * списка из заметок.
     *
     * @param string $text
     * @return string[]
     */
    private function splitItems(string $text): array
    {
        $chunks = preg_split('/[;\r\n]+|,(?!\d)/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter(array_map('trim', $chunks), 'strlen'));
    }

    /**
     * Отделяет сумму от названия: «кофе 300» и «300 кофе» разбираются одинаково.
     *
     * @param string $chunk
     * @return array{name: string, price: float}|null
     */
    private function splitNameAndPrice(string $chunk): ?array
    {
        if (!preg_match('/(\d+(?:[.,]\d+)?)/', $chunk, $match)) {
            return null;
        }

        $price = (float)str_replace(',', '.', $match[1]);
        $name = trim(str_replace($match[1], '', $chunk));

        if ($price <= 0 || $name === '') {
            return null;
        }

        return ['name' => $name, 'price' => $price];
    }
}
