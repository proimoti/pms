<?php

namespace App\Services\Charges;

use App\Models\LeaseContract;
use App\Models\OwnerContract;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;

/**
 * Проверките на ръчно въведените начисления. Грешките се събират и излизат
 * всички наведнъж (PROJECT.md, „Дати в интерфейса“).
 */
final class ChargeInput
{
    private MessageBag $errors;

    public function __construct()
    {
        $this->errors = new MessageBag;
    }

    public function fail(string $field, string $message, array $replace = []): void
    {
        $this->errors->add($field, __('charges.messages.'.$message, $replace));
    }

    /** Хвърля всички събрани грешки, ако има. */
    public function check(): void
    {
        if ($this->errors->isNotEmpty()) {
            throw ValidationException::withMessages($this->errors->toArray());
        }
    }

    /** Причина за ръчна промяна или махане: задължителна. */
    public function reason($reason): ?string
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            $this->fail('reason', 'reason_required');

            return null;
        }

        return mb_substr($reason, 0, 500);
    }

    /** Сума в евро с до два знака: 0 или повече (или над 0 при $positive). */
    public function amount($value, bool $positive = false): ?string
    {
        $text = str_replace([' ', ','], ['', '.'], trim((string) $value));

        if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $text) || ($positive && (float) $text <= 0)) {
            $this->fail('amount', $positive ? 'amount_positive' : 'amount_invalid');

            return null;
        }

        return number_format((float) $text, 2, '.', '');
    }

    public function note($note, bool $required): ?string
    {
        $note = trim((string) $note);
        if ($required && $note === '') {
            $this->fail('note', 'note_required');
        }

        return $note === '' ? null : mb_substr($note, 0, 2000);
    }

    /** Неархивиран договор за наем на фирмата, в който е имотът. */
    public function leaseWithProperty($leaseId, $propertyId): ?LeaseContract
    {
        $lease = $leaseId === null || $leaseId === '' ? null
            : LeaseContract::query()->whereNull('archived_at')->find($leaseId);

        if ($lease === null) {
            $this->fail('lease_contract_id', 'lease_not_found');

            return null;
        }

        if (! $lease->lines()->where('property_id', $propertyId)->exists()) {
            $this->fail('property_id', 'property_not_in_contract');

            return null;
        }

        return $lease;
    }

    /** Неархивиран договор за управление на фирмата, в който е имотът. */
    public function managementWithProperty($contractId, $propertyId): ?OwnerContract
    {
        $contract = $contractId === null || $contractId === '' ? null
            : OwnerContract::query()->whereNull('archived_at')->find($contractId);

        if ($contract === null) {
            $this->fail('owner_contract_id', 'management_not_found');

            return null;
        }

        if (! $contract->lines()->where('property_id', $propertyId)->exists()) {
            $this->fail('property_id', 'property_not_in_contract');

            return null;
        }

        return $contract;
    }
}
