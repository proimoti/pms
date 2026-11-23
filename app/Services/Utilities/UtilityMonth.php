<?php

namespace App\Services\Utilities;

use App\Enums\ChargeType;
use App\Models\PaidUtilityBill;
use App\Models\Scopes\RegistryVisibilityScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Битовите сметки за известието за месец М (PROJECT.md, „Битови сметки в
 * месеца“, 22.11.2026): кои платени сметки взима месецът и какви редове
 * предлага. Само смята: не записва нищо.
 *
 * - месецът взима всички внесени сметки, които не са в работната таблица на
 *   друг месец (една сметка е само в една таблица; там остават и махнатите,
 *   и намалените — разликата е неначислена, за част 5) и не са издадени в
 *   друг месец с „Издай сега“ преди генерирането;
 * - всяка се дели по своя период (калкулаторът, част 3), със срока за
 *   последно начисление спрямо М;
 * - фиксираните абонати са за месеца преди М;
 * - сметките „плаща се от фонд“ не влизат: те са разход на фонда.
 */
final class UtilityMonth
{
    public function __construct(private readonly UtilityCalculator $calculator) {}

    /** @return Collection<int, PaidUtilityBill> */
    public function bills(int $companyId, CarbonImmutable $month): Collection
    {
        $month = $month->startOfMonth()->startOfDay();

        return PaidUtilityBill::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->where('company_id', $companyId)
            // Сметките „плаща се от фонд“ не се делят на наемателите (PROJECT.md, „Общ фонд“).
            ->whereIn('utility_account_id', fn ($q) => $q->select('id')->from('utility_accounts')
                ->where('company_id', $companyId)->where('paid_from_fund', false))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('charge_drafts as d')
                ->whereColumn('d.paid_utility_bill_id', 'paid_utility_bills.id')
                ->whereColumn('d.company_id', 'paid_utility_bills.company_id')
                ->whereDate('d.month', '<>', $month->toDateString()))
            // …и не е издадена в друг месец с „Издай сега“ преди генерирането (без ред в таблицата).
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('statement_lines as l')
                ->join('statements as s', 's.id', '=', 'l.statement_id')
                ->whereColumn('l.paid_utility_bill_id', 'paid_utility_bills.id')
                ->whereColumn('l.company_id', 'paid_utility_bills.company_id')
                ->whereDate('s.month', '<>', $month->toDateString()))
            ->with(['account' => fn ($q) => $q->withoutGlobalScope(RegistryVisibilityScope::class)->with('provider')])
            ->orderBy('paid_at')->orderBy('id')
            ->get();
    }

    /**
     * Предложените редове и сметките (по имот) „за решение“.
     *
     * @return array{rows: list<array{type: ChargeType, lease: int, tenant: int, property: int, bill: ?int, account: int, from: CarbonImmutable, to: CarbonImmutable, days: int, amount: string}>,
     *     forDecision: list<array{bill: PaidUtilityBill, split: UtilitySplit}>}
     */
    public function proposals(int $companyId, CarbonImmutable $month): array
    {
        $month = $month->startOfMonth()->startOfDay();
        $rows = $forDecision = [];

        foreach ($this->bills($companyId, $month) as $bill) {
            foreach ($this->calculator->forBill($bill, $month) as $split) {
                foreach ($split->shares as $share) {
                    $rows[] = [
                        'type' => ChargeType::Utility, 'lease' => $share->leaseContractId, 'tenant' => $share->tenantId,
                        'property' => $split->propertyId, 'bill' => (int) $bill->id, 'account' => (int) $bill->utility_account_id,
                        'from' => $split->from, 'to' => $split->to, 'days' => $share->days, 'amount' => $share->amount,
                    ];
                }
                if ($split->reason !== null) {
                    $forDecision[] = ['bill' => $bill, 'split' => $split];
                }
            }
        }

        $previous = $month->subMonth();
        foreach ($this->calculator->fixedForMonth($companyId, $month) as $charge) {
            $rows[] = [
                'type' => ChargeType::FixedFee, 'lease' => $charge->leaseContractId, 'tenant' => $charge->tenantId,
                'property' => $charge->propertyId, 'bill' => null, 'account' => $charge->utilityAccountId,
                'from' => $previous, 'to' => $previous->endOfMonth()->startOfDay(), 'days' => $charge->days, 'amount' => $charge->amount,
            ];
        }

        return ['rows' => $rows, 'forDecision' => $forDecision];
    }

    /**
     * Колко сметки биха дали редове, а още не са в работната таблица на М
     * (внесени след генерирането: „генерирай отново“). Сметките само „за
     * решение“ не се броят: те не са редове.
     */
    public function notInWorksheet(int $companyId, CarbonImmutable $month): int
    {
        $month = $month->startOfMonth()->startOfDay();
        $inSheet = DB::table('charge_drafts')->where('company_id', $companyId)->whereDate('month', $month->toDateString())
            ->whereNotNull('paid_utility_bill_id')->distinct()->pluck('paid_utility_bill_id')->map(fn ($id) => (int) $id)->flip();

        $bills = [];
        foreach ($this->proposals($companyId, $month)['rows'] as $row) {
            if ($row['bill'] !== null && ! $inSheet->has($row['bill'])) {
                $bills[$row['bill']] = true;
            }
        }

        return count($bills);
    }
}
