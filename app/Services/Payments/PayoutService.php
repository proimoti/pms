<?php

namespace App\Services\Payments;

use App\Enums\ChargeType;
use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Models\LedgerEntry;
use App\Models\OwnerContract;
use App\Models\OwnerPayout;
use App\Models\Scopes\CompanyScope;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Services\Charges\Cents;
use App\Services\Charges\Ledger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Изплащания към собственици и бенефициенти (PROJECT.md, „Плащания и салда“,
 * 26.11.2026): 408 − по имот (разделено по OwnerAllocation::split), с вид
 * „изплащане“; сторното е 408 + за същите имоти. Полетата се проверяват като
 * при плащанията. Без UPDATE и DELETE.
 */
final class PayoutService
{
    public function __construct(
        private readonly OwnerAllocation $allocation,
        private readonly PaymentService $payments,
    ) {}

    private function companyId(): int
    {
        return CompanyScope::restrictedCompanyId() ?? throw new \LogicException('Изплащането иска влязъл служител на фирма.');
    }

    public function record(OwnerContract $contract, array $data): OwnerPayout
    {
        ['cents' => $cents, 'paid_on' => $paidOn, 'method' => $method, 'bank_account_id' => $bankId] = $this->payments->validate($data);
        $note = trim((string) ($data['note'] ?? ''));

        return DB::transaction(function () use ($contract, $cents, $paidOn, $method, $bankId, $note) {
            Ledger::lockCompany($this->companyId());

            $payout = new OwnerPayout;
            $payout->forceFill([
                'owner_contract_id' => $contract->id, 'owner_id' => $contract->owner_id,
                'beneficiary_id' => $contract->payeeOn($paidOn)?->beneficiary_id,
                'amount' => Cents::format($cents), 'paid_on' => $paidOn, 'method' => $method, 'bank_account_id' => $bankId,
                'note' => $note === '' ? null : mb_substr($note, 0, 5000), 'created_by' => Auth::id(), 'created_at' => now(),
            ])->save();

            foreach ($this->allocation->split($this->companyId(), $contract->id, $cents) as $propertyId => $part) {
                Ledger::book($paidOn->startOfMonth(), LedgerKind::Payout, [
                    'account' => LedgerAccount::Owner, 'type' => ChargeType::Payment, 'owner_contract_id' => $contract->id,
                    'owner_id' => $contract->owner_id, 'property_id' => $propertyId, 'owner_payout_id' => $payout->id,
                    'reason' => $payout->note,
                ], -$part);
            }

            return $payout;
        });
    }

    public function reverse(OwnerPayout $payout, ?string $reason): OwnerPayout
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('payments.errors.reason')]);
        }

        return DB::transaction(function () use ($payout, $reason) {
            Ledger::lockCompany($this->companyId());
            if (OwnerPayout::query()->where('reversal_of_id', $payout->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['reason' => __('payouts.errors.already_reversed')]);
            }

            $reversal = new OwnerPayout;
            $reversal->forceFill([
                'owner_contract_id' => $payout->owner_contract_id, 'owner_id' => $payout->owner_id, 'beneficiary_id' => $payout->beneficiary_id,
                'amount' => Cents::format(-Cents::from((string) $payout->amount)), 'paid_on' => $payout->paid_on, 'method' => $payout->method,
                'bank_account_id' => $payout->bank_account_id, 'reversal_of_id' => $payout->id, 'reason' => mb_substr($reason, 0, 500),
                'created_by' => Auth::id(), 'created_at' => now(),
            ])->save();

            $entries = LedgerEntry::query()->withoutGlobalScope(RegistryVisibilityScope::class)
                ->where('owner_payout_id', $payout->id)->where('kind', LedgerKind::Payout->value)->get();
            foreach ($entries as $entry) {
                Ledger::book($entry->month, LedgerKind::PayoutReversal, [
                    'account' => LedgerAccount::Owner, 'type' => ChargeType::Payment, 'owner_contract_id' => $entry->owner_contract_id,
                    'owner_id' => $entry->owner_id, 'property_id' => $entry->property_id, 'owner_payout_id' => $reversal->id, 'reason' => $reversal->reason,
                ], -Cents::from((string) $entry->amount));
            }

            return $reversal;
        });
    }
}
