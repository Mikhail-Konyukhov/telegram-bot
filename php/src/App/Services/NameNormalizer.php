<?php

namespace App\Services;

/**
 * Приведение названия траты к ключу словаря категорий.
 *
 * «Кофе », «кофe 2 шт», «КОФЁ!» должны попадать в одну и ту же запись —
 * иначе словарь промахивается и вместо него зря вызывается модель.
 */
class NameNormalizer
{
    /** Длина колонки name_norm: 190 × 4 байта utf8mb4 влезает в индекс MySQL 5.7 */
    private const MAX_LENGTH = 190;

    public static function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        $name = str_replace('ё', 'е', $name);

        // Количество и единицы к категории отношения не имеют: «молоко 2 шт»
        // и «молоко» — одна и та же строка словаря. Порядок важен: снимаем до
        // чистки пунктуации, пока «0.5 л» ещё не рассыпалось на «0 5 л».
        $name = preg_replace('/\d+(?:[.,]\d+)?\s*(?:шт|кг|гр|г|мл|л|уп|пач)\b\.?/u', ' ', $name);
        $name = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $name);
        $name = preg_replace('/\s+/u', ' ', $name);

        return mb_substr(trim($name), 0, self::MAX_LENGTH, 'UTF-8');
    }
}
