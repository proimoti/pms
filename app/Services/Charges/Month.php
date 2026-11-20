<?php

namespace App\Services\Charges;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Месецът на начисленията: „2026-12“ (вътрешно) или „12.2026“ (от формата), първото число. */
final class Month
{
    /** Първото число на месеца или null, ако текстът не е месец. */
    public static function tryParse(?string $text): ?CarbonImmutable
    {
        $text = trim((string) $text);

        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $text, $m)) {
            return CarbonImmutable::create((int) $m[1], (int) $m[2], 1)->startOfDay();
        }

        if (preg_match('/^(0?[1-9]|1[0-2])\.(\d{4})$/', $text, $m)) {
            return CarbonImmutable::create((int) $m[2], (int) $m[1], 1)->startOfDay();
        }

        return null;
    }

    public static function parse(?string $text, string $field = 'month'): CarbonImmutable
    {
        return self::tryParse($text)
            ?? throw ValidationException::withMessages([$field => __('charges.messages.month_invalid')]);
    }

    public static function key(CarbonImmutable $month): string
    {
        return $month->format('Y-m');
    }

    /** „Декември 2026“. */
    public static function label(CarbonImmutable $month): string
    {
        return __('charges.months.'.$month->month).' '.$month->year;
    }
}
