<?php

namespace App\Services\Utilities;

use App\Enums\UtilityBilling;
use App\Models\PaidUtilityBill;
use App\Models\Scopes\CompanyScope;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Models\UtilityAccount;
use App\Services\Charges\Cents;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Калкулаторът на битовите сметки (PROJECT.md, т. 3.2, 3.3, 3.4 и „Битови
 * сметки, правила за смятане“, 21.11.2026). Само смята: не записва нищо.
 *
 * Реален абонат (всяка част на платената сметка, тоест всеки имот):
 * - периодът е в месеца преди плащането, от „ден от“ до „ден до“ на абоната,
 *   двата края се броят; за наемателя денят на напускане не се брои;
 * - сумата се дели между наемателите в периода по дните им; дните на празния
 *   имот се поемат от тях (делим на техните дни, не на дните на периода);
 * - всеки получава сума × дни ÷ общо дни, надолу до цент; стотинките отиват
 *   към този с най-много дни (при равни: към по-късния);
 * - напуснал наемател, чийто срок за последно начисление (т. 3.2) е минал
 *   към месец М, не се начислява: дните му поемат останалите; ако няма
 *   никой — „за решение“; празен имот през целия период — „за решение“.
 *
 * Фиксиран абонат („Фиксиран абонат и делене“, 22.11.2026): сумата на месец
 * (при няколко имота — първо по съотношението), за ПРЕДХОДНИЯ месец, както
 * реалните сметки: известието за октомври начислява септември, по дните на
 * наемателя през септември; сума × дни ÷ дните в месеца, закръглено до цент;
 * празните дни не се поемат от никого. Важат само дните, в които абонатът
 * действа (датите му са включително). Срокът за последно начисление важи и тук.
 *
 * Не гледа договора за управление: битовите сметки не засягат собственика.
 */
final class UtilityCalculator
{
    /**
     * Периодът на потребление за плащане на дата: [от, до (включително), дни].
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: int}
     */
    public static function period(UtilityAccount $account, CarbonInterface $paidAt): array
    {
        $month = CarbonImmutable::create($paidAt->year, $paidAt->month, 1)->startOfDay()->subMonth();
        $last = $month->daysInMonth;
        $startDay = min(max(1, (int) ($account->period_start_day ?: 1)), $last);
        $endDay = max($startDay, min((int) ($account->period_end_day ?? $last), $last));

        return [$month->setDay($startDay), $month->setDay($endDay), $endDay - $startDay + 1];
    }

    /**
     * Последният месец, за който може да се начисли на напуснал наемател (т. 3.2):
     * първо число на втория месец след последния месец на ползване. Подава се
     * денят на напускане (той не се брои): 20.08 → 10.2026; 01.08 → 09.2026.
     */
    public static function lastChargeMonth(CarbonInterface $leftOn): CarbonImmutable
    {
        return CarbonImmutable::parse($leftOn->format('Y-m-d'))->subDay()->startOfMonth()->addMonths(2);
    }

    /**
     * Делението на платената сметка при начисление за месец М: по една част
     * за всеки имот, по реда на имотите в абоната.
     *
     * @return list<UtilitySplit>
     */
    public function forBill(PaidUtilityBill $bill, CarbonImmutable $month): array
    {
        $companyId = (int) $bill->company_id;
        $this->ensureCompany($companyId);
        $month = $month->startOfMonth()->startOfDay();

        $account = UtilityAccount::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->where('company_id', $companyId)->findOrFail($bill->utility_account_id);
        [$from, $to, $periodDays] = self::period($account, $bill->paid_at);

        $parts = $bill->parts()->withoutGlobalScope(RegistryVisibilityScope::class)->orderBy('id')->get();
        $occupancy = $this->occupancy($companyId, $parts->pluck('property_id')->map(fn ($id) => (int) $id)->all(), $from, $to->addDay());

        $splits = [];
        foreach ($parts as $part) {
            $splits[] = $this->splitPart((int) $part->id, (int) $part->property_id, Cents::from($part->amount),
                $from, $to, $periodDays, $occupancy[(int) $part->property_id] ?? [], $month);
        }

        return $splits;
    }

    /**
     * Фиксираните такси в известието за месец М: за дните през месеца преди М.
     *
     * @return list<FixedUtilityCharge>
     */
    public function fixedForMonth(int $companyId, CarbonImmutable $month): array
    {
        if (! $this->sameCompany($companyId)) {
            return [];
        }

        $notice = $month->startOfMonth()->startOfDay();
        $start = $notice->subMonth();
        $end = $start->addMonth();
        $daysInMonth = $start->daysInMonth;

        $accounts = UtilityAccount::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->where('company_id', $companyId)
            ->where('billing', UtilityBilling::Fixed->value)
            ->whereNull('archived_at')
            ->where('valid_from', '<', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $start->toDateString()))
            ->with(['properties' => fn ($q) => $q->withoutGlobalScope(RegistryVisibilityScope::class)])
            ->orderBy('id')
            ->get();

        $charges = [];
        foreach ($accounts as $account) {
            $from = max($start, CarbonImmutable::parse($account->valid_from->toDateString()));
            $until = $account->valid_to === null ? $end : min($end, CarbonImmutable::parse($account->valid_to->toDateString())->addDay());
            [$parts] = UtilityImporter::split(Cents::from($account->fixed_amount), $account);
            $occupancy = $this->occupancy($companyId, array_keys($parts), $from, $until);

            foreach ($parts as $propertyId => $amount) {
                foreach ($occupancy[$propertyId] ?? [] as $contractId => $line) {
                    if ($this->pastDeadline($line, $notice)) {
                        continue; // напуснал след срока: празните дни при фиксиран абонат не се поемат
                    }
                    $charges[] = new FixedUtilityCharge((int) $account->id, (int) $propertyId, $contractId, $line['tenant'],
                        $notice->format('Y-m'), $start->format('Y-m'), $line['days'], Cents::format(Cents::divide(Cents::from($amount) * $line['days'], $daysInMonth)));
                }
            }
        }

        return $charges;
    }

    /**
     * @param  array<int, array{tenant: int, days: int, first: int, leftOn: ?CarbonImmutable}>  $occupants
     */
    private function splitPart(int $partId, int $propertyId, int $cents, CarbonImmutable $from, CarbonImmutable $to,
        int $periodDays, array $occupants, CarbonImmutable $month): UtilitySplit
    {
        $occupied = array_sum(array_column($occupants, 'days'));
        $make = fn (array $shares, array $takenOver, ?string $reason, int $forDecision) => new UtilitySplit(
            $partId, $propertyId, $from, $to, $periodDays, Cents::format($cents), $shares, $takenOver,
            $periodDays - $occupied, $reason, Cents::format($forDecision));

        if ($occupants === []) {
            return $make([], [], 'vacant', $cents);
        }

        $inTime = $takenOver = [];
        foreach ($occupants as $contractId => $line) {
            if ($this->pastDeadline($line, $month)) {
                $takenOver[] = new UtilityShare($contractId, $line['tenant'], $line['days'], '0.00');
            } else {
                $inTime[$contractId] = $line;
            }
        }

        if ($inTime === []) {
            return $make([], $takenOver, 'deadline_passed', $cents);
        }

        // Стотинките към този с най-много дни; при равни — към по-късния.
        $total = array_sum(array_column($inTime, 'days'));
        $winner = null;
        foreach ($inTime as $contractId => $line) {
            if ($winner === null || $line['days'] >= $inTime[$winner]['days']) {
                $winner = $contractId;
            }
        }

        $amounts = [];
        $given = 0;
        foreach ($inTime as $contractId => $line) {
            if ($contractId !== $winner) {
                $amounts[$contractId] = intdiv($cents * $line['days'], $total);
                $given += $amounts[$contractId];
            }
        }
        $amounts[$winner] = $cents - $given;

        $shares = [];
        foreach ($inTime as $contractId => $line) {
            $shares[] = new UtilityShare($contractId, $line['tenant'], $line['days'], Cents::format($amounts[$contractId]));
        }

        return $make($shares, $takenOver, null, 0);
    }

    /**
     * Кой договор за наем колко дни е в имота между две дати (до без включване),
     * по реда на първия ден. leftOn е денят, от който договорът вече не е в
     * имота (прекратяване или край на последния ред), или null, ако още е.
     * Архивираните договори не се броят.
     *
     * @param  list<int>  $propertyIds
     * @return array<int, array<int, array{tenant: int, days: int, first: int, leftOn: ?CarbonImmutable}>>
     */
    private function occupancy(int $companyId, array $propertyIds, CarbonImmutable $from, CarbonImmutable $until): array
    {
        if ($propertyIds === [] || $from >= $until) {
            return [];
        }

        $rows = DB::table('lease_contract_lines as l')
            ->join('lease_contracts as c', fn ($join) => $join->on('c.id', '=', 'l.lease_contract_id')->on('c.company_id', '=', 'l.company_id'))
            ->where('l.company_id', $companyId)
            ->whereNull('c.archived_at')
            ->whereIn('l.property_id', $propertyIds)
            ->orderBy('l.valid_from')
            ->get(['l.lease_contract_id', 'l.property_id', 'l.valid_from', 'l.valid_to', 'c.terminated_on', 'c.tenant_id']);

        $days = $ends = $tenants = [];
        foreach ($rows as $row) {
            $propertyId = (int) $row->property_id;
            $contractId = (int) $row->lease_contract_id;
            $tenants[$contractId] = (int) $row->tenant_id;

            // Краят на реда: по-ранното от края му и прекратяването (денят не се брои).
            $lineEnd = null;
            foreach ([$row->valid_to, $row->terminated_on] as $limit) {
                if ($limit !== null) {
                    $date = CarbonImmutable::parse($limit)->startOfDay();
                    $lineEnd = $lineEnd === null ? $date : min($lineEnd, $date);
                }
            }
            $key = "{$propertyId}:{$contractId}";
            $ends[$key] = array_key_exists($key, $ends) && ($ends[$key] === null || $lineEnd === null) ? null : max($ends[$key] ?? $lineEnd, $lineEnd);

            $start = max($from, CarbonImmutable::parse($row->valid_from)->startOfDay());
            $stop = $lineEnd === null ? $until : min($until, $lineEnd);
            for ($day = $start; $day < $stop; $day = $day->addDay()) {
                $days[$propertyId][$contractId][(int) $from->diffInDays($day)] = true;
            }
        }

        $result = [];
        foreach ($days as $propertyId => $contracts) {
            foreach ($contracts as $contractId => $set) {
                $result[$propertyId][$contractId] = [
                    'tenant' => $tenants[$contractId], 'days' => count($set), 'first' => min(array_keys($set)),
                    'leftOn' => $ends["{$propertyId}:{$contractId}"],
                ];
            }
            uasort($result[$propertyId], fn ($a, $b) => $a['first'] <=> $b['first']);
        }

        return $result;
    }

    /** Срокът за последно начисление (т. 3.2) е минал към месеца на известието. */
    private function pastDeadline(array $line, CarbonImmutable $month): bool
    {
        return $line['leftOn'] !== null && $month->gt(self::lastChargeMonth($line['leftOn']));
    }

    private function sameCompany(int $companyId): bool
    {
        $restricted = CompanyScope::restrictedCompanyId();

        return $restricted === null || $restricted === $companyId;
    }

    private function ensureCompany(int $companyId): void
    {
        if (! $this->sameCompany($companyId)) {
            throw new LogicException('Сметка на друга фирма.');
        }
    }
}
