<?php

namespace App\Services\Charges;

/**
 * Имот с наем, който за поне един ден от месеца няма договор за управление:
 * наемът му за месеца не се начислява и чака решение (PROJECT.md, „Начисления,
 * уточнения“). Почти винаги е грешка в данните.
 */
final class ChargeForDecision
{
    public const NO_MANAGEMENT = 'no_management_contract';

    public function __construct(
        public readonly string $month,
        public readonly int $propertyId,
        public readonly int $leaseContractId,
        public readonly int $daysWithoutManagement,
        public readonly string $reason = self::NO_MANAGEMENT,
    ) {}
}
