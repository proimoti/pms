<?php

namespace App\Services\Utilities;

/** Частта на един договор за наем от битова сметка (или от фиксиран абонат). */
final class UtilityShare
{
    public function __construct(
        public readonly int $leaseContractId,
        public readonly int $tenantId,
        public readonly int $days,
        public readonly string $amount,
    ) {}
}
