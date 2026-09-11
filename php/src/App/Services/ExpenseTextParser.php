<?php

namespace App\Services;

/**
 * Разбор строки трат вида «кофе 300, такси 450» на позиции.
 *
 * Вынесено из {@see ExpenseIntakeService} без изменения поведения: там этот код
 * был заперт внутри класса, который в конструкторе поднимает соединение с БД и
 * клиента Gemini, и проверить разбор строки отдельно было невозможно.
 *
 * Категории здесь не определяются — только «что купили» и «за сколько».
 */
class ExpenseTextParser
{
    /**
     * @return array{items: array<int, array{name: string, price: float}>, errors: string[]}
     */
    public static function parse(string $text): array
    {
        $items = [];
        $errors = [];

        foreach (self::splitItems($text) as $chunk) {
            $parsed = self::splitNameAndPrice($chunk);

            if ($parsed === null) {
                $errors[] = "Не удалось разобрать «{$chunk}»";
                continue;
            }

            $items[] = $parsed;
        }

        return ['items' => $items, 'errors' => $errors];
    }

    /**
     * Режет сообщение на позиции.
     *
     * Запятая — разделитель, только если за ней не идёт цифра: иначе
     * «кофе 300,50» распалось бы на «кофе 300» и «50», и трата записывалась
     * бы без копеек. Перевод строки разделяет всегда — так работает вставка
     * списка из заметок.
     *
     * @return string[]
     */
    private static function splitItems(string $text): array
    {
        $chunks = preg_split('/[;\r\n]+|,(?!\d)/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter(array_map('trim', $chunks), 'strlen'));
    }

    /**
     * Отделяет сумму от названия: «кофе 300» и «300 кофе» разбираются одинаково.
     *
     * Ценой считается **первое** число в строке, а вырезается оно из названия
     * во всех вхождениях сразу. Из-за этого «2 кофе 300» читается как две
     * единицы чего-то под названием «кофе 300», а не как кофе за 300 — единицы
     * измерения снимает {@see NameNormalizer}, но он работает уже после разбора.
     * Поведение закреплено тестами; менять его — отдельная задача.
     *
     * @return array{name: string, price: float}|null
     */
    private static function splitNameAndPrice(string $chunk): ?array
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
