<?php

namespace App\Services\Charges;

/**
 * Едно предложено начисление за месеца (още не е в книгата): кой, какво, за
 * кой месец, колко (PROJECT.md, „Начисления, уточнения“).
 *
 * type „rent“: наем към наемателя (418, по договора за наем и имота).
 * type „owner“: дължимото към собственика (408, по договора за управление и имота).
 */
final class ProposedCharge
{
    public const RENT = 'rent';

    public const OWNER = 'owner';

    public function __construct(
        public readonly string $type,
        public readonly string $month,          // „2026-12“
        public readonly int $propertyId,
        public readonly string $amount,         // евро, два знака: „241.94“
        public readonly int $days,              // дни в месеца, за които се начислява
        public readonly ?int $leaseContractId = null,
        public readonly ?int $tenantId = null,
        public readonly ?int $ownerContractId = null,
        public readonly ?int $ownerId = null,
        public readonly ?string $computedAmount = null, // пресметнатият наем, когато е ползван договореният за първия период
    ) {}
}
