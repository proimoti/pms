<?php

namespace App\Services\Utilities;

use Carbon\CarbonImmutable;

/**
 * Делението на една част от платена сметка (един имот) между наемателите.
 *
 * - shares: към кого колко (сборът + forDecision = amount);
 * - takenOver: напуснали наематели след срока за последно начисление (т. 3.2),
 *   чиито дни са поели останалите (сума 0);
 * - reason: null, „vacant“ (празен имот през целия период) или
 *   „deadline_passed“ (в периода има само напуснали след срока);
 *   тогава цялата сума е forDecision.
 */
final class UtilitySplit
{
    /**
     * @param  list<UtilityShare>  $shares
     * @param  list<UtilityShare>  $takenOver
     */
    public function __construct(
        public readonly int $partId,
        public readonly int $propertyId,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly int $periodDays,
        public readonly string $amount,
        public readonly array $shares,
        public readonly array $takenOver,
        public readonly int $vacantDays,
        public readonly ?string $reason,
        public readonly string $forDecision,
    ) {}
}
