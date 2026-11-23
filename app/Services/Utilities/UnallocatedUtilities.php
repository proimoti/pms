<?php

namespace App\Services\Utilities;

use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Models\ChargeDraft;
use App\Models\ChargeMonth;
use App\Models\LedgerEntry;
use App\Models\PaidUtilityBill;
use App\Models\PeriodLock;
use App\Models\Property;
use App\Models\Scopes\CompanyScope;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Models\StatementLine;
use App\Models\UtilityDecision;
use App\Services\Charges\Cents;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * „Неразпределени битови сметки“ (PROJECT.md, т. 3.4 и част 5, решено
 * 23.11.2026): всяка платена сметка за имот, за която 407 на имота не е 0.
 * Само смята: не записва нищо.
 *
 * Основен списък (искат решение):
 * - празен имот през целия период; напуснал наемател след срока (предложено
 *   „за сметка на управителя“) — сметката още не е начислена никому;
 * - ръчно намален или махнат ред, частично начислена — в издаден месец;
 * - частта на другите наематели, когато „Издай сега“ е бил преди
 *   генерирането, а месецът е прескочен (генериран е по-късен месец).
 *
 * „Чакат месеца“ (без бутони): месецът ѝ още не е генериран, или е в работната
 * таблица на неиздаден месец, или е решена „към следващия наемател“ и чака
 * издаването. Сметките от фонд не са тук (те са на фонда, не на имота).
 */
final class UnallocatedUtilities
{
    public function __construct(private readonly UtilityCalculator $calculator) {}

    /** @return array{decide: list<UnallocatedItem>, waiting: list<UnallocatedItem>} */
    public function items(): array
    {
        $companyId = CompanyScope::restrictedCompanyId() ?? throw new LogicException('Списъкът иска влязъл служител на фирма.');
        $visible = Property::query()->pluck('id')->map(fn ($id) => (int) $id)->flip();

        $balances = LedgerEntry::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->where('company_id', $companyId)->where('account', LedgerAccount::Utility->value)
            ->whereNull('fund_id')->whereNotNull('property_id')->whereNotNull('paid_utility_bill_id')
            ->groupBy('paid_utility_bill_id', 'property_id')
            ->selectRaw('paid_utility_bill_id, property_id, SUM(amount) as balance, SUM(CASE WHEN kind = ? THEN amount ELSE 0 END) as paid,
                SUM(CASE WHEN kind = ? THEN amount ELSE 0 END) as manager', [LedgerKind::UtilityPaid->value, LedgerKind::ManagerCost->value])
            ->havingRaw('SUM(amount) <> 0')
            ->orderBy('paid_utility_bill_id')->orderBy('property_id')
            ->get();

        $result = ['decide' => [], 'waiting' => []];
        foreach ($balances as $row) {
            if (! $visible->has((int) $row->property_id)) {
                continue;
            }
            $item = $this->item($companyId, (int) $row->paid_utility_bill_id, (int) $row->property_id,
                Cents::from((string) $row->paid), Cents::from((string) $row->balance), Cents::from((string) $row->manager));
            $result[$item->reason !== null ? 'decide' : 'waiting'][] = $item;
        }

        return $result;
    }

    /** Редът за сметка и имот, ако иска решение; иначе null. */
    public function forDecision(PaidUtilityBill $bill, int $propertyId): ?UnallocatedItem
    {
        foreach ($this->items()['decide'] as $item) {
            if ($item->bill->id === $bill->id && $item->propertyId === $propertyId) {
                return $item;
            }
        }

        return null;
    }

    private function item(int $companyId, int $billId, int $propertyId, int $paid, int $balance, int $manager): UnallocatedItem
    {
        $bill = PaidUtilityBill::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->with(['account' => fn ($q) => $q->withoutGlobalScope(RegistryVisibilityScope::class)->with('provider')])
            ->where('company_id', $companyId)->findOrFail($billId);
        [$from, $to] = UtilityCalculator::period($bill->account, $bill->paid_at);
        $charged = $paid - $balance + $manager; // начисленото на наематели (без решенията)

        [$reason, $waiting, $month, $decision] = $this->classify($companyId, $bill, $propertyId);

        return new UnallocatedItem(
            $bill, $propertyId, $from, $to,
            Cents::format($paid), Cents::format($charged), Cents::format($balance),
            $charged === 0 ? 'not_charged' : 'partly',
            $reason, $waiting, $month,
            $reason === 'deadline_passed' ? UtilityDecision::MANAGER : null,
            $reason === null ? [] : $this->nextLeases($companyId, $propertyId, $to),
            $decision,
        );
    }

    /** @return array{0: ?string, 1: ?string, 2: ?CarbonImmutable, 3: ?UtilityDecision} причина, чакане, месец, решение */
    private function classify(int $companyId, PaidUtilityBill $bill, int $propertyId): array
    {
        // Решено „към следващия наемател“ и още не е в издаден месец.
        foreach ($this->decisions($companyId, $bill->id, $propertyId) as $decision) {
            if ($decision->kind !== UtilityDecision::NEXT_TENANT) {
                continue;
            }
            $draftMonth = ChargeDraft::query()->withoutGlobalScope(RegistryVisibilityScope::class)
                ->where('company_id', $companyId)->where('utility_decision_id', $decision->id)->value('month');
            $draftMonth = $draftMonth === null ? null : CarbonImmutable::parse($draftMonth);
            if ($draftMonth === null || ! PeriodLock::isLocked($draftMonth)) {
                return [null, 'next_tenant', $draftMonth, $decision];
            }
        }

        // В работната таблица: последният месец, в който е.
        $drafts = ChargeDraft::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->where('company_id', $companyId)->where('paid_utility_bill_id', $bill->id)->where('property_id', $propertyId)->get();
        if ($drafts->isNotEmpty()) {
            $month = CarbonImmutable::parse($drafts->max(fn ($row) => $row->month->toDateString()));
            if (! PeriodLock::isLocked($month)) {
                return [null, 'worksheet', $month, null];
            }
            $last = $drafts->filter(fn ($row) => $row->month->isSameDay($month));

            return match (true) {
                $last->contains(fn ($row) => $row->removed_at !== null) => ['removed', null, $month, null],
                $last->contains(fn ($row) => $row->edited_at !== null && Cents::from($row->amount) < Cents::from((string) ($row->proposed_amount ?? $row->amount))) => ['reduced', null, $month, null],
                default => ['partial', null, $month, null],
            };
        }

        // Само с „Издай сега“ (без ред в таблицата): частично, щом месецът е издаден или е прескочен.
        $issuedIn = StatementLine::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->where('statement_lines.company_id', $companyId)->where('paid_utility_bill_id', $bill->id)->where('property_id', $propertyId)
            ->join('statements', 'statements.id', '=', 'statement_lines.statement_id')->max('statements.month');
        if ($issuedIn !== null) {
            $month = CarbonImmutable::parse($issuedIn);
            $skipped = ChargeMonth::query()->whereDate('month', '>', $month->toDateString())->exists();

            return PeriodLock::isLocked($month) || $skipped ? ['partial', null, $month, null] : [null, 'month', $month, null];
        }

        // Още в никоя таблица: месецът, в който би влязла; празен имот или минал срок искат решение.
        $month = $this->monthFor($bill);
        foreach ($this->calculator->forBill($bill, $month) as $split) {
            if ($split->propertyId === $propertyId && in_array($split->reason, ['vacant', 'deadline_passed'], true)) {
                return [$split->reason, null, $month, null];
            }
        }

        return [null, 'month', $month, null];
    }

    /** Месецът, в който сметката би влязла: месецът на плащане или първият след последния издаден. */
    private function monthFor(PaidUtilityBill $bill): CarbonImmutable
    {
        $paid = CarbonImmutable::create($bill->paid_at->year, $bill->paid_at->month, 1)->startOfDay();
        $lastLocked = PeriodLock::query()->max('month');
        $next = $lastLocked === null ? null : CarbonImmutable::parse($lastLocked)->startOfMonth()->addMonth();

        return $next !== null && $next->gt($paid) ? $next : $paid;
    }

    /** @return \Illuminate\Support\Collection<int, UtilityDecision> активните решения за сметката и имота */
    private function decisions(int $companyId, int $billId, int $propertyId)
    {
        return UtilityDecision::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->where('company_id', $companyId)->where('paid_utility_bill_id', $billId)->where('property_id', $propertyId)
            ->whereNull('cancelled_at')->orderBy('id')->get();
    }

    /** @return list<int> неархивираните договори за наем, чийто ред за имота започва след периода */
    private function nextLeases(int $companyId, int $propertyId, CarbonImmutable $periodTo): array
    {
        return DB::table('lease_contract_lines as l')
            ->join('lease_contracts as c', 'c.id', '=', 'l.lease_contract_id')
            ->where('l.company_id', $companyId)->where('l.property_id', $propertyId)
            ->whereDate('l.valid_from', '>', $periodTo->toDateString())
            ->whereNull('c.archived_at')
            ->groupBy('l.lease_contract_id')
            ->orderByRaw('MIN(l.valid_from)')->orderBy('l.lease_contract_id')
            ->pluck('l.lease_contract_id')->map(fn ($id) => (int) $id)->all();
    }
}
