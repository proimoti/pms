<?php

namespace App\Services\Payments;

use App\Enums\ChargeType;
use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Models\BankAccount;
use App\Models\DepositClosing;
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
 * Приключване на депозит (PROJECT.md, „Приключване на депозит“, 26.11.2026):
 * ръчно, по договор за наем, с един или няколко начина наведнъж, по имот:
 *
 * - прихващане срещу дълга по 418: 491 − и 418 − (погасява дълга като плащане);
 * - връщане на наемателя (банка или в брой): 491 −; не се експортира;
 * - приход на фирмата: 491 − (в експорта към сметката от настройките).
 *
 * По всеки имот сборът е до държания депозит (сборът по 491); прихващането общо
 * е до дълга по договора. Сторно с причина: същите видове с обратен знак.
 */
final class DepositClosingService
{
    public const METHODS = ['offset' => LedgerKind::DepositOffset, 'refund' => LedgerKind::DepositRefund, 'income' => LedgerKind::DepositIncome];

    private function companyId(): int
    {
        return CompanyScope::restrictedCompanyId() ?? throw new \LogicException('Приключването иска влязъл служител на фирма.');
    }

    /** Държаният депозит по имот (сборът по 491), в центове: имот => центове. */
    public function held(LeaseContract $lease): array
    {
        return LedgerEntry::query()->withoutGlobalScope(RegistryVisibilityScope::class)->where('account', LedgerAccount::Deposit->value)
            ->where('lease_contract_id', $lease->id)->groupBy('property_id')->selectRaw('property_id, SUM(amount) as total')
            ->pluck('total', 'property_id')->map(fn ($total) => Cents::from((string) $total))->all();
    }

    /** Дългът по договора (сборът по 418), в центове. */
    public function owes(LeaseContract $lease): int
    {
        return Cents::from((string) LedgerEntry::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->where('account', LedgerAccount::Tenant->value)->where('lease_contract_id', $lease->id)->sum('amount'));
    }

    /**
     * Проверка; всички грешки наведнъж.
     *
     * @return array{closed_on: \Carbon\CarbonImmutable, lines: array<int, array<string, int>>, refund_method: ?string, bank_account_id: ?int}
     */
    public function validate(LeaseContract $lease, array $data): array
    {
        $errors = new MessageBag;
        $closedOn = BgDate::parse($data['closed_on'] ?? null);
        $closedOn ?? $errors->add('closed_on', __('deposits.errors.closed_on'));

        $held = $this->held($lease);
        $properties = DB::table('lease_contract_lines')->where('company_id', $lease->company_id)->where('lease_contract_id', $lease->id)
            ->pluck('property_id')->map(fn ($id) => (int) $id)->unique()->all();
        $lines = [];
        $offset = 0;
        $refund = 0;
        foreach ((array) ($data['lines'] ?? []) as $propertyId => $values) {
            $propertyId = (int) $propertyId;
            $amounts = [];
            foreach (array_keys(self::METHODS) as $method) {
                $cents = $this->amount($values[$method] ?? null);
                if ($cents === null) {
                    $errors->add('lines.'.$propertyId, __('deposits.errors.amount'));
                    continue 2;
                }
                if ($cents > 0) {
                    $amounts[$method] = $cents;
                }
            }
            if ($amounts === []) {
                continue;
            }
            if (! in_array($propertyId, $properties, true)) {
                $errors->add('lines.'.$propertyId, __('deposits.errors.property'));
                continue;
            }
            if (array_sum($amounts) > ($held[$propertyId] ?? 0)) {
                $errors->add('lines.'.$propertyId, __('deposits.errors.more_than_held', ['amount' => \App\Support\Format::money(Cents::format($held[$propertyId] ?? 0))]));
                continue;
            }
            $offset += $amounts['offset'] ?? 0;
            $refund += $amounts['refund'] ?? 0;
            $lines[$propertyId] = $amounts;
        }
        if ($lines === [] && ! $errors->has('lines.*')) {
            $errors->add('lines', __('deposits.errors.nothing'));
        }
        if ($offset > max(0, $this->owes($lease))) {
            $errors->add('offset', __('deposits.errors.more_than_owed', ['amount' => \App\Support\Format::money(Cents::format(max(0, $this->owes($lease))))]));
        }

        $method = null;
        $bankId = null;
        if ($refund > 0) {
            $method = (string) ($data['refund_method'] ?? '');
            if (! in_array($method, [Payment::BANK, Payment::CASH], true)) {
                $errors->add('refund_method', __('deposits.errors.refund_method'));
            } elseif ($method === Payment::BANK) {
                $bank = BankAccount::query()->whereNull('archived_at')->find($data['bank_account_id'] ?? null);
                $bank === null ? $errors->add('bank_account_id', __('payments.errors.bank_account')) : $bankId = $bank->id;
            }
        }
        if ($errors->isNotEmpty()) {
            throw ValidationException::withMessages($errors->toArray());
        }

        return ['closed_on' => $closedOn, 'lines' => $lines, 'refund_method' => $method, 'bank_account_id' => $bankId];
    }

    public function record(LeaseContract $lease, array $data): DepositClosing
    {
        return DB::transaction(function () use ($lease, $data) {
            Ledger::lockCompany($this->companyId());
            ['closed_on' => $closedOn, 'lines' => $lines, 'refund_method' => $method, 'bank_account_id' => $bankId] = $this->validate($lease, $data);

            $closing = new DepositClosing;
            $closing->forceFill([
                'lease_contract_id' => $lease->id, 'tenant_id' => $lease->tenant_id, 'closed_on' => $closedOn,
                'refund_method' => $method, 'bank_account_id' => $bankId, 'note' => $this->text($data['note'] ?? null, 5000),
                'created_by' => Auth::id(), 'created_at' => now(),
            ])->save();

            $reason = $this->text($data['note'] ?? null, 255) ?? __('deposits.reason');
            foreach ($lines as $propertyId => $amounts) {
                foreach ($amounts as $name => $cents) {
                    $this->book($closing, self::METHODS[$name], $lease->id, $lease->tenant_id, $propertyId, $reason, $cents);
                }
            }

            return $closing;
        });
    }

    /** Сторно: обратни записи със същите видове, с причина; само веднъж и не на сторно. */
    public function reverse(DepositClosing $closing, ?string $reason): DepositClosing
    {
        $reason = $this->text($reason, 500);
        if ($reason === null) {
            throw ValidationException::withMessages(['reason' => __('deposits.errors.reason')]);
        }
        if ($closing->isReversal()) {
            throw ValidationException::withMessages(['reason' => __('deposits.errors.is_reversal')]);
        }

        return DB::transaction(function () use ($closing, $reason) {
            Ledger::lockCompany($this->companyId());
            if (DepositClosing::query()->where('reversal_of_id', $closing->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['reason' => __('deposits.errors.already_reversed')]);
            }

            $reversal = new DepositClosing;
            $reversal->forceFill([
                'lease_contract_id' => $closing->lease_contract_id, 'tenant_id' => $closing->tenant_id, 'closed_on' => $closing->closed_on,
                'refund_method' => $closing->refund_method, 'bank_account_id' => $closing->bank_account_id, 'reversal_of_id' => $closing->id,
                'reason' => $reason, 'created_by' => Auth::id(), 'created_at' => now(),
            ])->save();

            $entries = LedgerEntry::query()->withoutGlobalScope(RegistryVisibilityScope::class)->where('deposit_closing_id', $closing->id)->orderBy('id')->get();
            foreach ($entries as $entry) {
                Ledger::book($entry->month, $entry->kind, [
                    'account' => $entry->account, 'type' => ChargeType::Deposit, 'lease_contract_id' => $entry->lease_contract_id,
                    'tenant_id' => $entry->tenant_id, 'property_id' => $entry->property_id, 'deposit_closing_id' => $reversal->id, 'reason' => mb_substr($reason, 0, 255),
                ], -Cents::from((string) $entry->amount));
            }

            return $reversal;
        });
    }

    /** Записите на един начин: 491 − и при прихващане 418 −. */
    private function book(DepositClosing $closing, LedgerKind $kind, int $leaseId, int $tenantId, int $propertyId, string $reason, int $cents): void
    {
        $attributes = ['type' => ChargeType::Deposit, 'lease_contract_id' => $leaseId, 'tenant_id' => $tenantId, 'property_id' => $propertyId,
            'deposit_closing_id' => $closing->id, 'reason' => $reason];
        $month = $closing->closed_on->startOfMonth();
        Ledger::book($month, $kind, $attributes + ['account' => LedgerAccount::Deposit], -$cents);
        if ($kind === LedgerKind::DepositOffset) {
            Ledger::book($month, $kind, $attributes + ['account' => LedgerAccount::Tenant], -$cents);
        }
    }

    /** Сума в центове; празно = 0; null при грешка. */
    private function amount(mixed $value): ?int
    {
        $text = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim((string) $value));
        if ($text === '') {
            return 0;
        }

        return preg_match('/^\d{1,10}(\.\d{1,2})?$/', $text) ? Cents::from($text) : null;
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
