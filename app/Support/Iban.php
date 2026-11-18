<?php

namespace App\Support;

/**
 * Проверка на IBAN по международния стандарт (ISO 13616):
 * формат, дължина за България и контролна сума mod 97.
 */
final class Iban
{
    /** Без интервали, с главни букви. */
    public static function normalize(string $iban): string
    {
        return strtoupper(preg_replace('/\s+/', '', $iban));
    }

    public static function isValid(string $iban): bool
    {
        $iban = self::normalize($iban);

        // Държава (2 букви), контролни цифри (2), после 11 до 30 букви или цифри.
        if (! preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }

        // Българските IBAN са точно 22 знака.
        if (str_starts_with($iban, 'BG') && strlen($iban) !== 22) {
            return false;
        }

        // Първите 4 знака отиват в края, буквите стават числа (A = 10 … Z = 35),
        // и остатъкът при деление на 97 трябва да е 1.
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $remainder = 0;
        foreach (str_split($rearranged) as $char) {
            $digits = ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder === 1;
    }
}
