<?php

namespace App\Services;

/**
 * Форматирование чисел и текстовых «графиков» для сообщений бота.
 *
 * PHP-двойник {@see webapp/js/format.js}: те же правила, но для чата.
 * Класс существует, потому что подтверждение траты и текстовый дашборд
 * показывают одни и те же величины и обязаны показывать их одинаково.
 */
final class Format
{
    /** Ширина текстовой полосы в символах. */
    private const BAR = 8;

    /**
     * Деньги: копейки показываются только когда они есть — «300» читается
     * лучше, чем «300,00».
     */
    public static function money(float $value): string
    {
        return number_format($value, fmod($value, 1) == 0.0 ? 0 : 2, ',', ' ');
    }

    /** @param float $share Доля от 0 до 1 */
    public static function percent(float $share): string
    {
        return round($share * 100) . '%';
    }

    /**
     * Состояние лимита: 🟢 до 80%, 🟡 с 80%, 🔴 со 100%.
     *
     * Нулевой лимит — сразу 🔴: «тратить нельзя» и есть превышение.
     */
    public static function marker(float $spent, float $limit): string
    {
        return match (true) {
            $spent >= $limit => '🔴',
            $spent >= $limit * 0.8 => '🟡',
            default => '🟢',
        };
    }

    /**
     * Полоса вида «██████░░» — доля в виде, читаемом с одного взгляда.
     *
     * @param float $share Доля от 0 до 1; за границами обрезается
     */
    public static function bar(float $share): string
    {
        $filled = (int)round(max(0.0, min(1.0, $share)) * self::BAR);

        return str_repeat('█', $filled) . str_repeat('░', self::BAR - $filled);
    }

    /**
     * Дополняет строку пробелами до нужной ширины — колонки в <pre> держатся
     * только на них.
     *
     * Своя реализация, а не `mb_str_pad`: та появилась в PHP 8.3, а контейнер
     * работает на 8.2.
     */
    public static function pad(string $text, int $width, bool $left = false): string
    {
        $gap = max(0, $width - mb_strlen($text, 'UTF-8'));
        $spaces = str_repeat(' ', $gap);

        return $left ? $spaces . $text : $text . $spaces;
    }

    /** Обрезает длинное название, чтобы не развалить колонку. */
    public static function truncate(string $text, int $width): string
    {
        return mb_strimwidth($text, 0, $width, '…', 'UTF-8');
    }
}
