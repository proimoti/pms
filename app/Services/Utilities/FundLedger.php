<?php

namespace App\Services\Utilities;

use App\Enums\ChargeType;
use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Models\Fund;
use App\Models\LedgerEntry;
use App\Models\PaidUtilityBill;
use App\Models\Scopes\CompanyScope;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Models\UtilityAccount;
use App\Services\Charges\Cents;
use App\Services\Charges\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Общият фонд в книгата (PROJECT.md, „Общ фонд“, решено 22.11.2026, уточнено
 * 23.11.2026). Сметка 407 на фонда: с връзка към фонда, без имот и без договор.
 *
 * - приход във фонда: фиксираната такса към наемателя (418 + там, 407 − тук);
 *   записва се при издаването (част 4б);
 * - разход от фонда: сметка на абонат „плаща се от фонд“ (407 +), при вноса;
 * - справка: събрано (−сбор на таксите), платено (сбор на сметките) и
 *   остатък (−сбор на всички записи на фонда), за период по месеца на
 *   записа и общо. Нищо не се въвежда на ръка: всичко е от книгата.
 *
 * Ръчен ред „разход от фонд“ (например чистачка) е отложен.
 */
final class FundLedger
{
    /** Приход във фонда от фиксирана такса (сумата в центове, положителна; 0 не се записва). */
    public function income(UtilityAccount $account, CarbonImmutable $month, int $cents, ?int $statementId = null): ?LedgerEntry
    {
        if (! $account->isFixed() || $account->fund_id === null) {
            throw new LogicException('Приход във фонда е само от фиксиран абонат към фонд.');
        }
        $this->ensureCompany((int) $account->company_id);

        return Ledger::book($month->startOfMonth()->startOfDay(), LedgerKind::FundIncome, [
            'account' => LedgerAccount::Utility, 'type' => ChargeType::FixedFee,
            'fund_id' => $account->fund_id, 'statement_id' => $statementId,
        ], -$cents);
    }

    /** Разход от фонда: платената сметка на абонат „плаща се от фонд“, в месеца на плащане. */
    public function expense(PaidUtilityBill $bill, UtilityAccount $account): ?LedgerEntry
    {
        if (! $account->paid_from_fund || $account->fund_id === null || (int) $bill->utility_account_id !== (int) $account->id) {
            throw new LogicException('Разход от фонда е само за сметка на абонат „плаща се от фонд“.');
        }
        $this->ensureCompany((int) $bill->company_id);

        return Ledger::book($bill->paid_at->startOfMonth()->startOfDay(), LedgerKind::FundExpense, [
            'account' => LedgerAccount::Utility, 'type' => ChargeType::Utility,
            'fund_id' => $account->fund_id, 'paid_utility_bill_id' => $bill->id,
        ], Cents::from($bill->amount));
    }

    /**
     * Справката за фонда: за период (от месец до месец, включително) или общо.
     * Фондът трябва да е видим за потребителя (иначе „не е намерен“).
     */
    public function report(Fund $fund, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): FundReport
    {
        $fund = Fund::query()->findOrFail($fund->id);

        $sum = fn (?ChargeType $type) => Cents::from((string) ($this->entries($fund, $from, $to)
            ->when($type !== null, fn (Builder $q) => $q->where('type', $type->value))
            ->sum('amount') ?: '0'));

        $collected = -$sum(ChargeType::FixedFee);
        $paid = $sum(ChargeType::Utility);
        $left = -$sum(null);

        return new FundReport(Cents::format($collected), Cents::format($paid), Cents::format($left));
    }

    /** Записите на фонда (за периода, ако е даден), по реда им. */
    public function entries(Fund $fund, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): Builder
    {
        // Записите на фонда са без имот: виждат се през фонда, не през имотите на служителя.
        return LedgerEntry::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->where('company_id', $fund->company_id)
            ->where('account', LedgerAccount::Utility->value)
            ->where('fund_id', $fund->id)
            ->when($from !== null, fn (Builder $q) => $q->whereDate('month', '>=', $from->startOfMonth()->toDateString()))
            ->when($to !== null, fn (Builder $q) => $q->whereDate('month', '<=', $to->startOfMonth()->toDateString()))
            ->orderBy('month')->orderBy('id');
    }

    private function ensureCompany(int $companyId): void
    {
        $restricted = CompanyScope::restrictedCompanyId();
        if ($restricted !== null && $restricted !== $companyId) {
            throw new LogicException('Записът е на друга фирма.');
        }
    }
}
