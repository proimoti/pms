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
use App\Models\Scopes\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/**
 * Договор за управление: създаване и анекси (PROJECT.md, т. 2 „Договори“,
 * „Договори, уточнения“, „Комисиона с история“).
 *
 * Анексът не редактира стария вариант: затваря го („до“ = датата на промяната)
 * и добавя нов от тази дата. Всяка операция е в една транзакция: при отказ
 * не остава нищо. Защитата „един имот в един договор за управление за един
 * период“ е тук: имотът се заключва в базата, докато трае проверката.
 */
class OwnerContractService
{
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

    public function addAnnex(OwnerContract $contract, int $number, $signedOn, ?string $note = null): OwnerContractAnnex
    {
        return DB::transaction(function () use ($contract, $number, $signedOn, $note) {
            $taken = OwnerContractAnnex::query()->withoutGlobalScopes()
                ->where('company_id', $contract->company_id)
                ->where('owner_contract_id', $contract->id)
                ->where('number', $number)
                ->exists();

            if ($number < 1 || $taken) {
                $this->reject('number', 'annex_number_taken', ['number' => $number]);
            }

            $annex = new OwnerContractAnnex;
            $annex->forceFill([
                'company_id' => $contract->company_id,
                'owner_contract_id' => $contract->id,
                'number' => $number,
                'signed_on' => $this->date($signedOn, 'signed_on', 'signed_on_required'),
                'note' => $note,
            ])->save();

            return $annex;
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
            $current = $this->openVersion($contract->commissions());

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
            $from = $this->date($from, 'valid_from', 'change_too_early');

            $start = $contract->startsOn();
            if ($start !== null && $from->lt($start)) {
                $this->reject('valid_from', 'change_too_early');
            }
            $this->ensureBeforeTermination($contract, $from);

            $line = $this->newLine($contract, $propertyId, $from, $amount, $annex);
            $this->ensurePropertyIsFree($contract->company_id, $propertyId, $contract);

            return $line;
        });
    }

    /** Маха имот от договора от дадена дата (от нея нататък не е в договора). */
    public function removeProperty(OwnerContractAnnex $annex, Property|int $property, $from): OwnerContractLine
    {
        return DB::transaction(function () use ($annex, $property, $from) {
            $contract = $this->contractOf($annex);
            $current = $this->openLine($contract, $property);
            $from = $this->changeDate($contract, $current, $from);

            $current->forceFill(['valid_to' => $from, 'closed_by_annex_id' => $annex->id])->save();

            return $current;
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
            $current = $this->openVersion($contract->payees());

            return $this->replaceVersion($contract, $annex, $current, $from, [
                'beneficiary_id' => $beneficiaryId,
                'payment_method' => $method,
            ]);
        });
    }

    /** Проверява всички имоти на договора (след прекратяване, отмяна, връщане от архива). */
    public function ensurePropertiesAreFree(OwnerContract $contract): void
    {
        $ids = DB::table('owner_contract_lines')
            ->where('company_id', $contract->company_id)
            ->where('owner_contract_id', $contract->id)
            ->distinct()->orderBy('property_id')->pluck('property_id');

        foreach ($ids as $id) {
            $this->ensurePropertyIsFree($contract->company_id, (int) $id, $contract);
        }
    }

    /**
     * Един имот не може да е в два договора за управление (нито два пъти в един)
     * за един и същи период. Архивираните договори не се броят. Периодът на ред
     * свършва с „до“ на реда или с прекратяването на договора, което е по-рано.
     */
    public function ensurePropertyIsFree(int $companyId, int $propertyId, OwnerContract $current): void
    {
        // Заключва имота до края на транзакцията: два записа едновременно чакат един друг.
        $property = DB::table('properties')->where('company_id', $companyId)->where('id', $propertyId)->lockForUpdate()->first();

        $periods = DB::table('owner_contract_lines as l')
            ->join('owner_contracts as c', fn ($join) => $join->on('c.id', '=', 'l.owner_contract_id')->on('c.company_id', '=', 'l.company_id'))
            ->where('l.company_id', $companyId)
            ->where('l.property_id', $propertyId)
            ->whereNull('c.archived_at')
            ->lockForUpdate()
            ->get(['l.owner_contract_id', 'l.valid_from', 'l.valid_to', 'c.terminated_on', 'c.number'])
            ->map(function ($row) {
                $ends = array_filter([$row->valid_to, $row->terminated_on]);

                return ['contract' => (int) $row->owner_contract_id, 'number' => (int) $row->number,
                    'from' => $row->valid_from, 'to' => $ends === [] ? null : min($ends)];
            })
            ->filter(fn ($p) => $p['to'] === null || $p['to'] > $p['from'])
            ->values();

        foreach ($periods as $i => $a) {
            foreach ($periods->slice($i + 1) as $b) {
                $overlap = ($b['to'] === null || $a['from'] < $b['to']) && ($a['to'] === null || $b['from'] < $a['to']);
                if (! $overlap) {
                    continue;
                }

                if ($a['contract'] === $b['contract']) {
                    $this->reject('lines', 'property_twice', ['property' => $property->number]);
                }

                $other = $a['contract'] === $current->id ? $b : $a;
                $this->reject('lines', 'property_busy', ['property' => $property->number, 'contract' => $other['number']]);
            }
        }
    }

    // Проверки на входа

    private function companyId(array $data): int
    {
        $companyId = CompanyScope::restrictedCompanyId() ?? ($data['company_id'] ?? null);
        if ($companyId === null) {
            throw new LogicException('Без влязъл потребител трябва да се подаде company_id.');
        }

        return (int) $companyId;
    }

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

        if (! $given || ! preg_match('/^\d{1,10}([.,]\d{1,2})?$/', (string) $amount)) {
            $this->reject('lines', 'amount_required');
        }

        $normalized = str_replace(',', '.', (string) $amount);

        // До 12 цифри: точно в float. Без добавката bcmath (може да я няма на хостинга).
        return (float) $normalized > 0 ? number_format((float) $normalized, 2, '.', '') : $this->reject('lines', 'amount_required');
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
            'start_on' => isset($line['start_on']) && $line['start_on'] !== ''
                ? $this->date($line['start_on'], 'lines', 'signed_on_required')
                : $signedOn,
            'fixed_amount' => $this->amount($calculation, $line['fixed_amount'] ?? null),
        ], array_values($lines));
    }

    private function propertyId(int $companyId, Property|int|string|null $property): int
    {
        $id = $property instanceof Property ? $property->id : $property;

        $exists = $id !== null && Property::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)->whereNull('archived_at')->whereKey($id)->exists();

        return $exists ? (int) $id : $this->reject('lines', 'property_not_found');
    }

    private function date($value, string $field, string $message): CarbonImmutable
    {
        if ($value === null || $value === '') {
            $this->reject($field, $message);
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            $this->reject($field, $message);
        }
    }

    // Варианти

    private function contractOf(OwnerContractAnnex $annex): OwnerContract
    {
        return OwnerContract::query()->withoutGlobalScopes()
            ->where('company_id', $annex->company_id)
            ->lockForUpdate()
            ->findOrFail($annex->owner_contract_id);
    }

    private function openVersion($versions)
    {
        return $versions->whereNull('valid_to')->lockForUpdate()->firstOrFail();
    }

    private function openLine(OwnerContract $contract, Property|int $property): OwnerContractLine
    {
        $id = $property instanceof Property ? $property->id : $property;

        $line = $contract->lines()->where('property_id', $id)->whereNull('valid_to')->lockForUpdate()->first();

        if ($line === null) {
            $number = DB::table('properties')->where('company_id', $contract->company_id)->where('id', $id)->value('number');
            $this->reject('lines', 'property_not_in_contract', ['property' => $number]);
        }

        return $line;
    }

    private function changeDate(OwnerContract $contract, $current, $from): CarbonImmutable
    {
        $from = $this->date($from, 'valid_from', 'change_too_early');

        if ($from->lte($current->valid_from)) {
            $this->reject('valid_from', 'change_too_early');
        }
        $this->ensureBeforeTermination($contract, $from);

        return $from;
    }

    private function ensureBeforeTermination(OwnerContract $contract, CarbonImmutable $from): void
    {
        if ($contract->terminated_on !== null && $from->gte($contract->terminated_on)) {
            $this->reject('valid_from', 'change_after_termination');
        }
    }

    /** Затваря сегашния вариант и добавя нов от датата, по анекса. */
    private function replaceVersion(OwnerContract $contract, OwnerContractAnnex $annex, $current, $from, array $values)
    {
        $from = $this->changeDate($contract, $current, $from);

        $current->forceFill(['valid_to' => $from])->save();

        return $this->newVersion($current->newInstance(), $contract, $from, $annex, $values);
    }

    private function newVersion($version, OwnerContract $contract, CarbonImmutable $from, ?OwnerContractAnnex $annex, array $values)
    {
        $version->forceFill(array_merge([
            'company_id' => $contract->company_id,
            'owner_contract_id' => $contract->id,
            'calculation' => $contract->calculation,
            'valid_from' => $from,
            'valid_to' => null,
            'annex_id' => $annex?->id,
        ], $values))->save();

        return $version;
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

    private function reject(string $field, string $message, array $replace = []): never
    {
        throw ValidationException::withMessages([$field => __('contracts.messages.'.$message, $replace)]);
    }
}
