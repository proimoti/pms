<?php

namespace App\Services\Utilities;

use App\Enums\ChargeType;
use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Models\ChargeDraft;
use App\Models\Concerns\WorksheetRules;
use App\Models\LeaseContract;
use App\Models\PaidUtilityBill;
use App\Models\PeriodLock;
use App\Models\Scopes\CompanyScope;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Models\UtilityDecision;
use App\Services\Charges\Cents;
use App\Services\Charges\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Решенията за неразпределените битови сметки (PROJECT.md, част 5, 23.11.2026).
 * Решението е за целия остатък на една сметка за един имот, с причина, и само
 * за сметка от основния списък (не за „Чакат месеца“). Само „четене и запис“ +
 * „всички имоти“. Книгата е само за добавяне.
 *
 * - „за сметка на управителя“: 407 − за остатъка (вид „за сметка на
 *   управителя“, в месеца на решението); отмяна: обратен запис 407 +;
 * - „към следващия наемател“: договор за имота, който започва след периода;
 *   книгата не се пипа — редът излиза при следващото генериране и се издава
 *   като битов ред (418 +, 407 −); отменя се, докато редът не е в издаден месец.
 *
 * Нищо не отива към собственика.
 */
final class UtilityDecisions
{
    public function __construct(private readonly UnallocatedUtilities $unallocated) {}

    public function toManager(PaidUtilityBill $bill, int $propertyId, ?string $reason): UtilityDecision
    {
        $this->ensureCanManage();
        $reason = $this->reason($reason, 'reason');

        return DB::transaction(function () use ($bill, $propertyId, $reason) {
            Ledger::lockCompany($this->companyId());
            $item = $this->item($bill, $propertyId);

            $decision = $this->decision($item, UtilityDecision::MANAGER, null, $reason);
            Ledger::book($this->today(), LedgerKind::ManagerCost, [
                'account' => LedgerAccount::Utility, 'type' => ChargeType::Utility, 'property_id' => $propertyId,
                'paid_utility_bill_id' => $item->bill->id, 'utility_decision_id' => $decision->id, 'reason' => $reason,
            ], -Cents::from($item->left));

            return $decision;
        });
    }

    public function toNextTenant(PaidUtilityBill $bill, int $propertyId, LeaseContract $lease, ?string $reason): UtilityDecision
    {
        $this->ensureCanManage();
        $reason = $this->reason($reason, 'reason');

        return DB::transaction(function () use ($bill, $propertyId, $lease, $reason) {
            Ledger::lockCompany($this->companyId());
            $item = $this->item($bill, $propertyId);
            if (! in_array((int) $lease->id, $item->nextLeases, true)) {
                throw ValidationException::withMessages(['lease_contract_id' => __('utilities.unallocated.errors.not_next_lease')]);
            }

            return $this->decision($item, UtilityDecision::NEXT_TENANT, (int) $lease->id, $reason);
        });
    }

    public function cancel(UtilityDecision $decision, ?string $reason): UtilityDecision
    {
        $this->ensureCanManage();
        $reason = $this->reason($reason, 'reason');

        return DB::transaction(function () use ($decision, $reason) {
            Ledger::lockCompany($this->companyId());
            $decision = UtilityDecision::query()->lockForUpdate()->findOrFail($decision->id);
            if (! $decision->isActive()) {
                $this->reject('already_cancelled');
            }

            if ($decision->kind === UtilityDecision::MANAGER) {
                // Обратен запис: първият не се пипа.
                Ledger::book($this->today(), LedgerKind::ManagerCost, [
                    'account' => LedgerAccount::Utility, 'type' => ChargeType::Utility, 'property_id' => $decision->property_id,
                    'paid_utility_bill_id' => $decision->paid_utility_bill_id, 'utility_decision_id' => $decision->id, 'reason' => $reason,
                ], Cents::from($decision->amount));
            } else {
                $rows = ChargeDraft::query()->withoutGlobalScope(RegistryVisibilityScope::class)->where('utility_decision_id', $decision->id)->get();
                if ($rows->contains(fn (ChargeDraft $row) => $row->statement_id !== null || PeriodLock::isLocked($row->month))) {
                    $this->reject('already_issued');
                }
                foreach ($rows as $row) {
                    $row->delete(); // редът е само от решението: излиза от неиздадената таблица
                }
            }

            $decision->forceFill(['cancelled_at' => now(), 'cancelled_by' => Auth::id(), 'cancel_reason' => $reason])->save();

            return $decision;
        });
    }

    /** Дали решението още може да се отмени (за бутона). */
    public function cancellable(UtilityDecision $decision): bool
    {
        if (! $decision->isActive()) {
            return false;
        }
        if ($decision->kind === UtilityDecision::MANAGER) {
            return true;
        }

        return ! ChargeDraft::query()->withoutGlobalScope(RegistryVisibilityScope::class)->where('utility_decision_id', $decision->id)->get()
            ->contains(fn (ChargeDraft $row) => $row->statement_id !== null || PeriodLock::isLocked($row->month));
    }

    private function item(PaidUtilityBill $bill, int $propertyId): UnallocatedItem
    {
        $item = (int) $bill->company_id === $this->companyId() ?$this->unallocated->forDecision($bill, $propertyId) : null;
        if ($item === null) {
            $this->reject('not_for_decision');
        }
        if ($this->active($item->bill->id, $propertyId)) {
            $this->reject('already_decided');
        }

        return $item;
    }

    private function decision(UnallocatedItem $item, string $kind, ?int $leaseId, string $reason): UtilityDecision
    {
        $decision = new UtilityDecision;
        $decision->forceFill([
            'paid_utility_bill_id' => $item->bill->id, 'property_id' => $item->propertyId, 'kind' => $kind,
            'lease_contract_id' => $leaseId, 'amount' => $item->left, 'reason' => $reason,
            'decided_by' => Auth::id(), 'decided_at' => now(),
        ])->save();

        return $decision;
    }

    private function active(int $billId, int $propertyId): bool
    {
        return UtilityDecision::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->where('paid_utility_bill_id', $billId)->where('property_id', $propertyId)->whereNull('cancelled_at')
            ->where(fn ($q) => $q->where('kind', UtilityDecision::MANAGER)
                ->orWhereNotIn('id', fn ($s) => $s->select('utility_decision_id')->from('charge_drafts')->whereNotNull('utility_decision_id')
                    ->whereIn('month', fn ($m) => $m->select('month')->from('period_locks')->where('company_id', $this->companyId()))))
            ->exists();
    }

    private function reason(?string $reason, string $field): string
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw ValidationException::withMessages([$field => __('utilities.unallocated.errors.reason')]);
        }

        return mb_substr($reason, 0, 500);
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::today()->startOfMonth();
    }

    private function ensureCanManage(): void
    {
        if (! WorksheetRules::canManage(Auth::user())) {
            throw new AuthorizationException(__('charges.messages.cannot_manage'));
        }
    }

    private function companyId(): int
    {
        return CompanyScope::restrictedCompanyId() ?? throw new LogicException('Решението иска влязъл служител на фирма.');
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['decision' => __('utilities.unallocated.errors.'.$message)]);
    }
}
