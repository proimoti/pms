<?php

namespace App\Services\Utilities;

use App\Models\PaidUtilityBill;
use App\Models\UtilityDecision;
use Carbon\CarbonImmutable;

/**
 * Един ред в „Неразпределени битови сметки“: платена сметка за един имот.
 *
 * - reason (основният списък): vacant (празен имот), deadline_passed (срокът е
 *   минал), reduced (ръчно намален ред), removed (махнат ред), partial
 *   (частично начислена); null = чака месеца;
 * - waiting („Чакат месеца“): month (месецът ѝ още не е генериран), worksheet
 *   (в работната таблица на неиздаден месец), next_tenant (решено „към
 *   следващия наемател“, още не е издадено);
 * - status: not_charged (нищо не е начислено) или partly (частично).
 */
final class UnallocatedItem
{
    /** @param list<int> $nextLeases договорите за имота след периода (по реда им) */
    public function __construct(
        public readonly PaidUtilityBill $bill,
        public readonly int $propertyId,
        public readonly CarbonImmutable $periodFrom,
        public readonly CarbonImmutable $periodTo,
        public readonly string $paid,
        public readonly string $charged,
        public readonly string $left,
        public readonly string $status,
        public readonly ?string $reason,
        public readonly ?string $waiting,
        public readonly ?CarbonImmutable $month,
        public readonly ?string $suggested,
        public readonly array $nextLeases,
        public readonly ?UtilityDecision $decision,
    ) {}
}
