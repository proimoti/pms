<?php

namespace App\Services\Payments;

use App\Enums\ChargeType;
use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Models\BankAccount;
use App\Models\LeaseContract;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Scopes\CompanyScope;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Services\Charges\Cents;
use App\Services\Charges\Ledger;
use App\Support\BgDate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;

/**
 * Плащания от наематели (PROJECT.md, „Плащания и салда“, 26.11.2026): запис и
 * сторно. В книгата 418 − по имот (разделено по Allocation::split), с вид
 * „плащане“; сторното е 418 + за същите имоти. Без UPDATE и DELETE.
 */
final class PaymentService
{
    public function __construct(private readonly Allocation $allocation) {}

    private function companyId(): int
    {
        return CompanyScope::restrictedCompanyId() ?? throw new \LogicException('Плащането иска влязъл служител на фирма.');
    }

    public function record(LeaseContract $lease, array $data): Payment
    {
        $errors = new MessageBag;
        $text = str_replace([' ', ','], ['', '.'], trim((string) ($data['amount'] ?? '')));
        $cents = preg_match('/^\d{1,10}(\.\d{1,2})?$/', $text) ? Cents::from($text) : 0;
        if ($cents <= 0) {
            $errors->add('amount', __('payments.errors.amount'));
        }
        $paidOn = BgDate::parse($data['paid_on'] ?? null);
        $paidOn ?? $errors->add('paid_on', __('payments.errors.paid_on'));
        $method = (string) ($data['method'] ?? '');
        if (! in_array($method, [Payment::BANK, Payment::CASH], true)) {
            $errors->add('method', __('payments.errors.method'));
        }
        $bankId = null;
        if ($method === Payment::BANK) {
            $bank = BankAccount::query()->whereNull('archived_at')->find($data['bank_account_id'] ?? null);
            $bank === null ? $errors->add('bank_account_id', __('payments.errors.bank_account')) : $bankId = $bank->id;
        }
        if ($errors->isNotEmpty()) {
            throw ValidationException::withMessages($errors->toArray());
        }

        return DB::transaction(function () use ($lease, $cents, $paidOn, $method, $bankId, $data) {
            Ledger::lockCompany($this->companyId());

            $payment = new Payment;
            $payment->forceFill([
                'lease_contract_id' => $lease->id, 'tenant_id' => $lease->tenant_id, 'amount' => Cents::format($cents),
                'paid_on' => $paidOn, 'method' => $method, 'bank_account_id' => $bankId,
                'reference' => $this->text($data['reference'] ?? null, 255), 'note' => $this->text($data['note'] ?? null, 5000),
                'created_by' => Auth::id(), 'created_at' => now(),
            ])->save();

            foreach ($this->allocation->split($this->companyId(), $lease->id, $cents) as $propertyId => $part) {
                Ledger::book($paidOn->startOfMonth(), LedgerKind::Payment, [
                    'account' => LedgerAccount::Tenant, 'type' => ChargeType::Payment, 'lease_contract_id' => $lease->id,
                    'tenant_id' => $lease->tenant_id, 'property_id' => $propertyId, 'payment_id' => $payment->id,
                    'reason' => $payment->reference,
                ], -$part);
            }

            return $payment;
        });
    }

    /** Сторно: обратен запис за същите имоти, с причина; само веднъж и не на сторно. */
    public function reverse(Payment $payment, ?string $reason): Payment
    {
        $reason = $this->text($reason, 500);
        if ($reason === null) {
            throw ValidationException::withMessages(['reason' => __('payments.errors.reason')]);
        }

        return DB::transaction(function () use ($payment, $reason) {
            Ledger::lockCompany($this->companyId());
            if (Payment::query()->where('reversal_of_id', $payment->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['reason' => __('payments.errors.already_reversed')]);
            }

            $reversal = new Payment;
            $reversal->forceFill([
                'lease_contract_id' => $payment->lease_contract_id, 'tenant_id' => $payment->tenant_id,
                'amount' => Cents::format(-Cents::from((string) $payment->amount)), 'paid_on' => $payment->paid_on, 'method' => $payment->method,
                'bank_account_id' => $payment->bank_account_id, 'reference' => $payment->reference, 'reversal_of_id' => $payment->id,
                'reason' => $reason, 'created_by' => Auth::id(), 'created_at' => now(),
            ])->save();

            $entries = LedgerEntry::query()->withoutGlobalScope(RegistryVisibilityScope::class)
                ->where('payment_id', $payment->id)->where('kind', LedgerKind::Payment->value)->get();
            foreach ($entries as $entry) {
                Ledger::book($entry->month, LedgerKind::PaymentReversal, [
                    'account' => LedgerAccount::Tenant, 'type' => ChargeType::Payment, 'lease_contract_id' => $entry->lease_contract_id,
                    'tenant_id' => $entry->tenant_id, 'property_id' => $entry->property_id, 'payment_id' => $reversal->id, 'reason' => $reason,
                ], -Cents::from((string) $entry->amount));
            }

            return $reversal;
        });
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
