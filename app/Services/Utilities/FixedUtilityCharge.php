<?php

namespace App\Services\Utilities;

/**
 * Начисление от фиксиран абонат към един договор за наем, по дни като наема.
 * month е месецът на известието (М), periodMonth — месецът на таксата (М − 1).
 */
final class FixedUtilityCharge
{
    public function __construct(
        public readonly int $utilityAccountId,
        public readonly int $propertyId,
        public readonly int $leaseContractId,
        public readonly int $tenantId,
        public readonly string $month,
        public readonly string $periodMonth,
        public readonly int $days,
        public readonly string $amount,
    ) {}
}
