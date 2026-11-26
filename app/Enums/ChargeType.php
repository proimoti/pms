<?php

namespace App\Enums;

/**
 * Видовете начисления (PROJECT.md, т. 6 и „Работна таблица на месеца“,
 * 20.11.2026): списък в кода, не таблица. Всеки вид носи своите правила.
 * Сметките при експорта идват с част 5 (export_rules).
 */
enum ChargeType: string
{
    case Rent = 'rent';           // наем, по дни, с дял на собственика
    case Utility = 'utility';     // битова сметка (отделна стъпка)
    case FixedFee = 'fixed_fee';  // фиксирана сума, по дни (с битовите сметки)
    case Deposit = 'deposit';     // депозит (491), еднократно или на вноски
    case Penalty = 'penalty';     // неустойка, еднократно
    case Other = 'other';         // друго, с бележка
    case Owner = 'owner';         // към собственика (408)
    case Payment = 'payment';     // плащане (в книгата, не начисление; 26.11.2026)
    case Opening = 'opening';     // начално салдо (в книгата, не начисление; 26.11.2026)

    /** Към наемателя (418) ли е; иначе към собственика. */
    public function forTenant(): bool
    {
        return ! in_array($this, [self::Owner, self::Payment, self::Opening], true);
    }

    /** Смята се по дни (наем × дни ÷ дни в месеца); иначе е еднократна сума. */
    public function byDays(): bool
    {
        return in_array($this, [self::Rent, self::FixedFee], true);
    }

    /** Дава ли дял на собственика (по договора за управление). */
    public function sharedWithOwner(): bool
    {
        return $this === self::Rent;
    }

    /** Може ли да е предстоящо начисление. */
    public function canBeUpcoming(): bool
    {
        return in_array($this, [self::Deposit, self::Penalty, self::Other], true);
    }

    /** Може ли да се добави на ръка в работната таблица (битовите сметки идват с отделната стъпка). */
    public function canBeManual(): bool
    {
        return in_array($this, [self::Rent, self::Deposit, self::Penalty, self::Other, self::Owner], true);
    }

    /** Иска ли бележка (текстът на реда). */
    public function needsNote(): bool
    {
        return $this === self::Other;
    }

    /** @return list<self> */
    public static function upcoming(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type) => $type->canBeUpcoming()));
    }

    /** @return list<self> */
    public static function manual(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type) => $type->canBeManual()));
    }
}
