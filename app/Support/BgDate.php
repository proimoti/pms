<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Дати във вида дд.мм.гггг (PROJECT.md, т. 2 „Дати в интерфейса“), независимо
 * от езика на браузъра. Приема се и вътрешният вид гггг-мм-дд. Несъществуваща
 * дата (31.02) не се „прехвърля“ в следващия месец, а се отказва.
 */
final class BgDate
{
    public static function parse(?string $value): ?CarbonImmutable
    {
        $value = trim((string) $value);

        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m)) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($month, $day, $year) ? CarbonImmutable::create($year, $month, $day)->startOfDay() : null;
    }

    /** Вътрешният вид (гггг-мм-дд) или null, ако датата е празна или грешна. */
    public static function toIso(?string $value): ?string
    {
        return self::parse($value)?->toDateString();
    }

    /** За поле във формата: вярна дата като дд.мм.гггг; грешно въведеното остава, както е написано. */
    public static function toInput(CarbonInterface|string|null $value): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('d.m.Y');
        }

        if ($value === null || $value === '') {
            return '';
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && ($date = self::parse($value)) !== null
            ? $date->format('d.m.Y')
            : $value;
    }
}
