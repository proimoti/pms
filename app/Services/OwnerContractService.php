<?php

namespace App\Services;

use App\Enums\CalculationMethod;
use App\Enums\OwnerContractType;
use App\Enums\PaymentMethod;
use App\Models\Beneficiary;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\OwnerContractAnnex;
use App\Models\OwnerContractCommission;
use App\Models\OwnerContractLine;
use App\Models\OwnerContractPayee;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Договор за управление: създаване и анекси (PROJECT.md, т. 2 „Договори“,
 * „Договори, уточнения“, „Комисиона с история“). Общите правила за анекси,
 * дати и защитата срещу два договора за един имот са в ContractService.
 */
class OwnerContractService extends ContractService
{
    protected function contractClass(): string
    {
        return OwnerContract::class;
    }

    protected function annexClass(): string
    {
        return OwnerContractAnnex::class;
    }

    protected function contractKey(): string
    {
        return 'owner_contract_id';
    }

    protected function busyMessage(): string
    {
        return 'property_busy';
    }

    /** Всеки вариант пази начина на изчисление, за да може базата да откаже грешна комбинация. */
    protected function versionAttributes(Model $contract): array
    {
        return ['calculation' => $contract->calculation];
    }

    /**
     * @param  array{type: OwnerContractType|string, owner_id?: ?int, signed_on: mixed, end_on?: mixed,
     *     number?: ?int, note?: ?string, commission_percent?: ?string, beneficiary_id?: ?int,
     *     payment_method?: PaymentMethod|string|null, company_id?: int,
     *     lines: list<array{property_id: int, start_on?: mixed, fixed_amount?: ?string}>}  $data
     */
    public function create(array $data): OwnerContract
    {
        return DB::transaction(function () use ($data) {
            $companyId = $this->companyId($data);
            $type = $data['type'] instanceof OwnerContractType ? $data['type'] : OwnerContractType::tryFrom((string) ($data['type'] ?? ''));
            if ($type === null) {
                $this->reject('type', 'type_unknown');
            }
            $calculation = $type->calculation();

            $signedOn = $this->date($data['signed_on'] ?? null, 'signed_on', 'signed_on_required');
            $owner = $this->owner($companyId, $type, $data['owner_id'] ?? null);
            $percent = $this->percent($calculation, $data['commission_percent'] ?? null);
            [$beneficiaryId, $method] = $this->payee($companyId, $calculation, $data['beneficiary_id'] ?? null, $data['payment_method'] ?? null);
            $lines = $this->lines($companyId, $calculation, $data['lines'] ?? [], $signedOn);

            $contract = new OwnerContract;
            $contract->fill([
                'number' => $data['number'] ?? null,
                'type' => $type,
                'owner_id' => $owner?->id,
                'signed_on' => $signedOn,
                'end_on' => isset($data['end_on']) ? $this->date($data['end_on'], 'end_on', 'signed_on_required') : null,
                'note' => $data['note'] ?? null,
            ]);
            $contract->company_id = $companyId;
            $contract->save();

            foreach ($lines as $line) {
                $this->newLine($contract, $line['property_id'], $line['start_on'], $line['fixed_amount']);
            }

            $start = $contract->startsOn();

            if ($percent !== null) {
                $this->newVersion(new OwnerContractCommission, $contract, $start, null, ['percent' => $percent]);
            }

            if ($calculation !== CalculationMethod::Own) {
                $beneficiaryId ??= $this->ownerBeneficiary($owner)->id;
                $this->newVersion(new OwnerContractPayee, $contract, $start, null, [
                    'beneficiary_id' => $beneficiaryId,
                    'payment_method' => $method,
                ]);
            }

            $this->ensurePropertiesAreFree($contract);

            return $contract->refresh();
        });
    }

    /** Нов процент от дадена дата (само при тип „Представител, %“). */
    public function changeCommission(OwnerContractAnnex $annex, $from, $percent): OwnerContractCommission
    {
        return DB::transaction(function () use ($annex, $from, $percent) {
            $contract = $this->contractOf($annex);
            if ($contract->calculation !== CalculationMethod::Percent) {
                $this->reject('commission_percent', 'percent_not_allowed');
            }

            $percent = $this->percent(CalculationMethod::Percent, $percent);
            $current = $contract->commissions()->whereNull('valid_to')->lockForUpdate()->firstOrFail();

            return $this->replaceVersion($contract, $annex, $current, $from, ['percent' => $percent]);
        });
    }

    /** Нова фиксирана сума за един имот от дадена дата. */
    public function changeFixedAmount(OwnerContractAnnex $annex, Property|int $property, $from, $amount): OwnerContractLine
    {
        return DB::transaction(function () use ($annex, $property, $from, $amount) {
            $contract = $this->contractOf($annex);
            $amount = $this->amount($contract->calculation, $amount);
            $current = $this->openLine($contract, $property);

            $line = $this->replaceVersion($contract, $annex, $current, $from, [
                'property_id' => $current->property_id,
                'fixed_amount' => $amount,
            ]);
            $this->ensurePropertyIsFree($contract->company_id, $current->property_id, $contract);

            return $line;
        });
    }

    /** Добавя имот в договора от дадена дата. */
    public function addProperty(OwnerContractAnnex $annex, Property|int $property, $from, $amount = null): OwnerContractLine
    {
        return DB::transaction(function () use ($annex, $property, $from, $amount) {
            $contract = $this->contractOf($annex);
            $propertyId = $this->propertyId($contract->company_id, $property);
            $amount = $this->amount($contract->calculation, $amount);
            $from = $this->addDate($contract, $from);

            $line = $this->newLine($contract, $propertyId, $from, $amount, $annex);
            $this->ensurePropertyIsFree($contract->company_id, $propertyId, $contract);

            return $line;
        });
    }

    /** Нов бенефициент и/или начин на плащане от дадена дата. */
    public function changePayee(OwnerContractAnnex $annex, $from, ?int $beneficiaryId, PaymentMethod|string|null $method): OwnerContractPayee
    {
        return DB::transaction(function () use ($annex, $from, $beneficiaryId, $method) {
            $contract = $this->contractOf($annex);
            [$beneficiaryId, $method] = $this->payee($contract->company_id, $contract->calculation, $beneficiaryId, $method);
            $beneficiaryId ??= $this->ownerBeneficiary(
                Owner::query()->withoutGlobalScopes()->where('company_id', $contract->company_id)->findOrFail($contract->owner_id)
            )->id;
            $current = $contract->payees()->whereNull('valid_to')->lockForUpdate()->firstOrFail();

            return $this->replaceVersion($contract, $annex, $current, $from, [
                'beneficiary_id' => $beneficiaryId,
                'payment_method' => $method,
            ]);
        });
    }

    // Проверки на входа

    private function owner(int $companyId, OwnerContractType $type, $ownerId): ?Owner
    {
        if ($ownerId === null || $ownerId === '') {
            if ($type !== OwnerContractType::Own) {
                $this->reject('owner_id', 'owner_required');
            }

            return null;
        }

        $owner = Owner::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)->whereNull('archived_at')->find($ownerId);

        return $owner ?? $this->reject('owner_id', 'owner_not_found');
    }

    private function percent(CalculationMethod $calculation, $percent): ?string
    {
        $given = $percent !== null && $percent !== '';

        if ($calculation !== CalculationMethod::Percent) {
            return $given ? $this->reject('commission_percent', 'percent_not_allowed') : null;
        }

        if (! $given || ! preg_match('/^\d{1,2}([.,]\d{1,2})?$/', (string) $percent)) {
            $this->reject('commission_percent', 'percent_required');
        }

        $value = (float) str_replace(',', '.', (string) $percent);

        return $value > 0 && $value < 100 ? number_format($value, 2, '.', '') : $this->reject('commission_percent', 'percent_required');
    }

    private function amount(CalculationMethod $calculation, $amount): ?string
    {
        $given = $amount !== null && $amount !== '';

        if ($calculation !== CalculationMethod::Fixed) {
            return $given ? $this->reject('lines', 'amount_not_allowed') : null;
        }

        return $this->money($amount, 'lines', 'amount_required');
    }

    /** @return array{0: ?int, 1: ?PaymentMethod} */
    private function payee(int $companyId, CalculationMethod $calculation, $beneficiaryId, $method): array
    {
        if ($calculation === CalculationMethod::Own) {
            if (($beneficiaryId ?? '') !== '') {
                $this->reject('beneficiary_id', 'payee_not_allowed');
            }

            return [null, null];
        }

        $method = $method instanceof PaymentMethod ? $method : PaymentMethod::tryFrom((string) $method);
        if ($method === null) {
            $this->reject('payment_method', 'payment_method_required');
        }

        if (($beneficiaryId ?? '') === '') {
            return [null, $method];
        }

        $exists = Beneficiary::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)->whereNull('archived_at')->whereKey($beneficiaryId)->exists();

        return $exists ? [(int) $beneficiaryId, $method] : $this->reject('beneficiary_id', 'beneficiary_not_found');
    }

    /** @return list<array{property_id: int, start_on: CarbonImmutable, fixed_amount: ?string}> */
    private function lines(int $companyId, CalculationMethod $calculation, array $lines, CarbonImmutable $signedOn): array
    {
        if ($lines === []) {
            $this->reject('lines', 'lines_required');
        }

        return array_map(fn (array $line) => [
            'property_id' => $this->propertyId($companyId, $line['property_id'] ?? null),
            'start_on' => $this->lineStart($line, $signedOn),
            'fixed_amount' => $this->amount($calculation, $line['fixed_amount'] ?? null),
        ], array_values($lines));
    }

    private function newLine(OwnerContract $contract, int $propertyId, CarbonImmutable $from, ?string $amount, ?OwnerContractAnnex $annex = null): OwnerContractLine
    {
        return $this->newVersion(new OwnerContractLine, $contract, $from, $annex, [
            'property_id' => $propertyId,
            'fixed_amount' => $amount,
        ]);
    }

    /** Бенефициентът „това е самият собственик“: един запис за всички негови договори. */
    private function ownerBeneficiary(Owner $owner): Beneficiary
    {
        $existing = Beneficiary::query()->withoutGlobalScopes()
            ->where('company_id', $owner->company_id)->where('owner_id', $owner->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $beneficiary = new Beneficiary;
        $beneficiary->forceFill([
            'company_id' => $owner->company_id,
            'owner_id' => $owner->id,
            'name' => $owner->name,
        ])->save();

        return $beneficiary;
    }
}
