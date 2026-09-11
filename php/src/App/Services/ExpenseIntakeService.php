<?php

namespace App\Services;

use App\Models\Category;
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

    /**
     * Модели — необязательные параметры: прод создаёт их сам, тесты подставляют
     * заглушки, потому что настоящие в конструкторе поднимают соединение с БД.
     * Классификатору отдельный параметр не нужен: он и так собирается из
     * переданного HTTP-клиента, а тому подсовывается MockHandler.
     */
    public function __construct(
        HttpClient $http,
        string $geminiApiKey,
        ?Expense $expenses = null,
        ?CategoryHint $hints = null
    ) {
        $this->expenseModel = $expenses ?? new Expense();
        $this->hints = $hints ?? new CategoryHint();
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
        $parsed = ExpenseTextParser::parse($text);

        $items = [];
        $errors = $parsed['errors'];
        $unknown = [];

        foreach ($parsed['items'] as $index => $item) {
            $known = $this->hints->find($userId, $item['name']);

            // Подсказка на удалённую категорию не должна её воскрешать.
            if ($known !== null && in_array($known, $categories, true)) {
                $items[$index] = $item + ['category' => $known, 'source' => 'hint'];
                continue;
            }

            $items[$index] = $item + ['category' => null, 'source' => 'gemini'];
            $unknown[$index] = $item['name'];
        }

        if ($unknown) {
            $guessed = $this->gemini->classify(array_values($unknown), $categories);

            foreach ($unknown as $index => $name) {
                // Модель промолчала о позиции. Трата всё равно записывается:
                // выброшенная, она пропадала совсем — ни в истории, ни в
                // лимитах, и вернуть её было неоткуда. Категорию пользователь
                // проставит кнопкой в подтверждении, а до тех пор сумма уже
                // учтена в общем лимите. Отдельной строки об ошибке нет:
                // в подтверждении и так стоит «без категории».
                $items[$index]['category'] = $guessed[$name] ?? Category::UNCATEGORIZED;
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

            // Ответ модели попадает в словарь, чтобы второй раз за него не
            // платить. Пустышку туда писать нельзя: «психолог» навсегда остался
            // бы «без категории» и до классификатора больше не дошёл.
            if ($item['category'] !== Category::UNCATEGORIZED) {
                $this->hints->remember($userId, $item['name'], $item['category']);
            }

            return $item;
        }, $items);
    }

}
