<?php

namespace App\Services\Charges;

use App\Enums\ChargeType;
use App\Enums\LedgerAccount;

/** Разлика между издаденото (в книгата) и пресметнатото сега по договорите, за един месец. */
final class Difference
{
    public function __construct(
        public readonly string $key,          // „418:договор:имот“ или „408:договор:имот“
        public readonly LedgerAccount $account,
        public readonly ChargeType $type,
        public readonly int $propertyId,
        public readonly ?int $leaseContractId,
        public readonly ?int $ownerContractId,
        public readonly ?int $tenantId,
        public readonly ?int $ownerId,
        public readonly string $booked,       // записано в книгата
        public readonly string $target,       // пресметнато сега
        public readonly string $difference,   // сега − записано
    ) {}
}
