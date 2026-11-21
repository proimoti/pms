<?php

namespace App\Services\Charges;

/**
 * Свободен имот за месеца: има действащ договор за управление, а наемател няма
 * за целия месец или за част от него (PROJECT.md, „Начисления, съдържание“).
 */
final class Vacancy
{
    public function __construct(
        public readonly int $propertyId,
        public readonly int $ownerContractId,
        public readonly ?int $ownerId,
        public readonly string $calculation,     // percent, fixed, own
        public readonly int $freeDays,           // дни с договор за управление и без наемател
        public readonly int $firstDay,           // първият свободен ден (0 = първо число)
        public readonly int $lastDay,            // последният свободен ден (включително)
        public readonly bool $wholeMonth,        // без наемател целия месец
        public readonly ?string $ownerAmount,    // при фиксиран: дължимото към собственика за свободните дни
    ) {}
}
