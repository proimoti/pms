<?php

namespace App\Services\Charges;

use InvalidArgumentException;

/**
 * Сумите се смятат в цели центове, без десетични дроби (float), за да няма
 * грешки при закръглянето. Сумите от базата са DECIMAL(12,2), като текст.
 */
final class Cents
{
    /** „600.00“ → 60000 (и отрицателни: „-218.06“ → -21806). */
    public static function from(string $amount): int
    {
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $amount, $m)) {
            throw new InvalidArgumentException("Не е сума: {$amount}");
        }

        $cents = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

        return $m[1] === '-' ? -$cents : $cents;
    }

    /** 24194 → „241.94“, -21806 → „-218.06“. */
    public static function format(int $cents): string
    {
        $abs = abs($cents);

        return ($cents < 0 ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Дели и закръгля до цял цент: 0,5 и нагоре се закръгля нагоре. Само за положителни числа. */
    public static function divide(int $numerator, int $denominator): int
    {
        if ($numerator < 0 || $denominator <= 0) {
            throw new InvalidArgumentException('Делението е само за положителни числа.');
        }

        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }
}
