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
        return $this->collecting($data, function () use ($data) {
            $companyId = $this->companyId($data);
            $type = $data['type'] instanceof OwnerContractType ? $data['type'] : OwnerContractType::tryFrom((string) ($data['type'] ?? ''));
            if ($type === null) {
                $this->reject('type', 'type_unknown');
            }
            $calculation = $type->calculation();

            $signedOn = $this->attempt(fn () => $this->date($data['signed_on'] ?? null, 'signed_on', 'signed_on_required'));
            $endOn = $this->attempt(fn () => isset($data['end_on']) ? $this->date($data['end_on'], 'end_on', 'signed_on_required') : null);
            $owner = $this->attempt(fn () => $this->owner($companyId, $type, $data['owner_id'] ?? null));
            if ($owner !== null) {
                $this->attempt(fn () => $this->blank($companyId, ['type' => $type, 'owner_id' => $owner->id])->ensureOwnerKindFitsType());
            }
            $percent = $this->attempt(fn () => $this->percent($calculation, $data['commission_percent'] ?? null));
            [$beneficiaryId, $method] = $this->attempt(
                fn () => $this->payee($companyId, $calculation, $data['beneficiary_id'] ?? null, $data['payment_method'] ?? null),
                [null, null]);
            $this->attempt(fn () => $this->ensureContractNumberIsFree($this->blank($companyId), $data['number'] ?? null));
            $lines = $this->lines($companyId, $calculation, $data['lines'] ?? [], $signedOn);

            if ($this->failures() > 0) {
                return null;
            }

            $contract = new OwnerContract;
            $contract->fill([
                'number' => $data['number'] ?? null,
                'type' => $type,
                'owner_id' => $owner?->id,
                'signed_on' => $signedOn,
                'end_on' => $endOn,
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
            $before = $this->failures();
            $propertyId = $this->attempt(fn () => $this->propertyId($contract->company_id, $property));
            $amount = $this->attempt(fn () => $this->amount($contract->calculation, $amount));
            $from = $this->attempt(fn () => $this->addDate($contract, $from));
            if ($this->failures() > $before) {
                throw new SkippedCheck;
            }

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

    /**
     * Един анекс с няколко промени наведнъж (екранът „Нов анекс“). Промените
     * важат от обща дата „from“; всеки имот може да има своя. При грешка в
     * която и да е промяна не се записва нищо, и самият анекс.
     *
     * @param  array{number?: ?int, signed_on: mixed, note?: ?string, from?: mixed,
     *     commission_percent?: ?string, payee?: ?array{beneficiary_id?: ?int, payment_method?: ?string},
     *     end_on?: mixed, lines?: list<array{action: string, property_id: int, from?: mixed, fixed_amount?: ?string}>}  $data
     */
    public function applyAnnex(OwnerContract $contract, array $data): OwnerContractAnnex
    {
        return $this->collecting($data, function () use ($contract, $data) {
            $percent = $data['commission_percent'] ?? null;
            $payee = $data['payee'] ?? null;
            $endOn = $data['end_on'] ?? null;
            $lines = array_values($data['lines'] ?? []);

            $changesPercent = $percent !== null && $percent !== '';
            $changesTerm = $endOn !== null && $endOn !== '';

            if (! $changesPercent && $payee === null && ! $changesTerm && $lines === []) {
                $this->attempt(fn () => $this->reject('changes', 'annex_no_changes'));
            }

            $from = $this->annexFrom($lines, $data['from'] ?? null, alsoNeeded: $changesPercent || $payee !== null);
            $annex = $this->annexForChanges($contract, $data);

            if ($changesPercent) {
                $this->attempt(fn () => $this->changeCommission($annex, $from, $percent));
            }

            if ($payee !== null) {
                $beneficiaryId = $payee['beneficiary_id'] ?? null;
                $this->attempt(fn () => $this->changePayee($annex, $from,
                    $beneficiaryId === null || $beneficiaryId === '' ? null : (int) $beneficiaryId, $payee['payment_method'] ?? null));
            }

            foreach ($lines as $line) {
                $lineFrom = ($line['from'] ?? null) ?: $from;
                $property = (int) ($line['property_id'] ?? 0);

                $this->attempt(fn () => match ($line['action'] ?? null) {
                    'amount' => $this->changeFixedAmount($annex, $property, $lineFrom, $line['fixed_amount'] ?? null),
                    'add' => $this->addProperty($annex, $property, $lineFrom, $line['fixed_amount'] ?? null),
                    'remove' => $this->removeProperty($annex, $property, $lineFrom),
                    default => $this->reject('lines', 'line_action_unknown'),
                });
            }

            if ($changesTerm) {
                $this->attempt(fn () => $this->extendTerm($annex, $endOn));
            }

            return $annex->refresh();
        });
    }

    /**
     * Поправка направо на полетата, от които зависят сумите (PROJECT.md,
     * „Поправки“), само докато няма начисления. Сменя записите на място (с
     * журнал), без нов вариант. Ключовете са номерата (id) на редовете.
     *
     * @param  array{owner_id?: ?int,
     *     lines?: array<int, array{property_id?: int, valid_from?: mixed, valid_to?: mixed, fixed_amount?: ?string}>,
     *     commissions?: array<int, string>,
     *     payees?: array<int, array{beneficiary_id?: ?int, payment_method?: ?string}>}  $data
     */
    public function correct(OwnerContract $contract, array $data): OwnerContract
    {
        if ($contract->hasCharges()) {
            $this->reject('changes', 'correction_after_charges');
        }

        return $this->collecting($data, function () use ($contract, $data) {
            $contract = OwnerContract::query()->withoutGlobalScopes()
                ->where('company_id', $contract->company_id)->lockForUpdate()->findOrFail($contract->id);

            if (array_key_exists('owner_id', $data)) {
                $this->attempt(fn () => $this->correctOwner($contract, $data['owner_id']));
            }

            foreach ($data['lines'] ?? [] as $id => $values) {
                $this->attempt(fn () => $this->correctLine($contract, (int) $id, (array) $values));
            }

            foreach ($data['commissions'] ?? [] as $id => $percent) {
                $this->attempt(function () use ($contract, $id, $percent) {
                    $version = $contract->commissions()->whereKey($id)->lockForUpdate()->first()
                        ?? $this->reject('commissions', 'row_not_in_contract');
                    $version->forceFill(['percent' => $this->percent($contract->calculation, $percent)])->save();
                });
            }

            foreach ($data['payees'] ?? [] as $id => $values) {
                $this->attempt(function () use ($contract, $id, $values) {
                    $version = $contract->payees()->whereKey($id)->lockForUpdate()->first()
                        ?? $this->reject('payees', 'row_not_in_contract');
                    [$beneficiaryId, $method] = $this->payee($contract->company_id, $contract->calculation,
                        $values['beneficiary_id'] ?? null, $values['payment_method'] ?? null);
                    $beneficiaryId ??= $this->ownerBeneficiary(
                        Owner::query()->withoutGlobalScopes()->where('company_id', $contract->company_id)->findOrFail($contract->owner_id)
                    )->id;
                    $version->forceFill(['beneficiary_id' => $beneficiaryId, 'payment_method' => $method])->save();
                });
            }

            $this->attempt(fn () => $this->alignContractStart($contract));
            $this->ensurePropertiesAreFree($contract);

            return $contract->refresh();
        });
    }

    /** Смяна на собственика: бенефициентът „самият собственик“ минава към новия. */
    private function correctOwner(OwnerContract $contract, $ownerId): void
    {
        $owner = $this->owner($contract->company_id, $contract->type, $ownerId);
        $oldOwnerId = $contract->owner_id;

        $contract->owner_id = $owner?->id;
        $contract->save();

        if ($oldOwnerId === null || $owner === null || $oldOwnerId === $owner->id) {
            return;
        }

        $old = Beneficiary::query()->withoutGlobalScopes()
            ->where('company_id', $contract->company_id)->where('owner_id', $oldOwnerId)->first();
        if ($old === null) {
            return;
        }

        $new = $this->ownerBeneficiary($owner);
        foreach ($contract->payees()->where('beneficiary_id', $old->id)->lockForUpdate()->get() as $version) {
            $version->forceFill(['beneficiary_id' => $new->id])->save();
        }
    }

    /** Сумата на реда при поправка (само при фиксиран тип). */
    protected function correctLineValues(Model $contract, Model $line, array $values): void
    {
        if (array_key_exists('fixed_amount', $values)) {
            $line->fixed_amount = $this->amount($contract->calculation, $values['fixed_amount']);
        }
    }

    /** Процентът и бенефициентът започват с договора (с най-ранния имот). */
    private function alignContractStart(OwnerContract $contract): void
    {
        $start = $contract->startsOn();

        foreach ([$contract->commissions(), $contract->payees()] as $versions) {
            $first = $versions->orderBy('valid_from')->lockForUpdate()->first();
            if ($first === null || $first->valid_from->equalTo($start)) {
                continue;
            }

            if ($first->valid_to !== null && $first->valid_to->lte($start)) {
                $this->reject('lines', 'start_after_first_version');
            }
            $first->forceFill(['valid_from' => $start])->save();
        }

        if ($contract->terminated_on !== null && $contract->terminated_on->lte($start)) {
            $this->reject('lines', 'terminated_before_start');
        }
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

        // 0 е за придружаващ имот, например гараж (PROJECT.md, променено 20.11.2026).
        return $this->money($amount, 'lines', 'amount_required', allowZero: true);
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

    /**
     * Редовете на нов договор: всяко поле на всеки ред се проверява отделно,
     * а застъпването, щом имотът и датата на реда са верни.
     *
     * @return list<array{property_id: int, start_on: CarbonImmutable, fixed_amount: ?string}>
     */
    private function lines(int $companyId, CalculationMethod $calculation, array $lines, ?CarbonImmutable $signedOn): array
    {
        if ($lines === []) {
            $this->attempt(fn () => $this->reject('lines', 'lines_required'));
        }

        $result = [];
        $earlier = [];

        foreach (array_values($lines) as $line) {
            $before = $this->failures();
            $propertyId = $this->attempt(fn () => $this->propertyId($companyId, $line['property_id'] ?? null));
            $start = $this->attempt(fn () => $this->lineStart($line, $signedOn));
            $amount = $this->attempt(fn () => $this->amount($calculation, $line['fixed_amount'] ?? null));

            if ($propertyId !== null && $start !== null) {
                $this->attempt(fn () => $this->ensureFreeFrom($this->blank($companyId), $companyId, $propertyId, $start, $earlier));
                $earlier[] = $propertyId;
            }

            if ($this->failures() === $before) {
                $result[] = ['property_id' => $propertyId, 'start_on' => $start, 'fixed_amount' => $amount];
            }
        }

        return $result;
    }

    /** Празен договор на фирмата: само за проверките преди записа. */
    private function blank(int $companyId, array $attributes = []): OwnerContract
    {
        $contract = new OwnerContract;
        $contract->forceFill($attributes);
        $contract->company_id = $companyId;

        return $contract;
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
