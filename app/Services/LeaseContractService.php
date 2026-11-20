<?php

namespace App\Services;

use App\Models\LeaseContract;
use App\Models\LeaseContractAnnex;
use App\Models\LeaseContractLine;
use App\Models\Property;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Договор за наем: създаване и анекси (PROJECT.md, т. 2 „Договори“). Всеки
 * ред (имот) има месечен наем (над 0) и договорен депозит (0 и повече).
 * Един имот не може да е в два договора за наем за един и същи период.
 * Общите правила за анекси и дати са в ContractService.
 */
class LeaseContractService extends ContractService
{
    protected function contractClass(): string
    {
        return LeaseContract::class;
    }

    protected function annexClass(): string
    {
        return LeaseContractAnnex::class;
    }

    protected function contractKey(): string
    {
        return 'lease_contract_id';
    }

    protected function busyMessage(): string
    {
        return 'lease_property_busy';
    }

    /**
     * @param  array{tenant_id: ?int, signed_on: mixed, end_on?: mixed, number?: ?int, note?: ?string,
     *     company_id?: int, lines: list<array{property_id: int, start_on?: mixed, rent: string, deposit?: ?string}>}  $data
     */
    public function create(array $data): LeaseContract
    {
        return DB::transaction(function () use ($data) {
            $companyId = $this->companyId($data);
            $signedOn = $this->date($data['signed_on'] ?? null, 'signed_on', 'signed_on_required');
            $tenantId = $this->tenantId($companyId, $data['tenant_id'] ?? null);
            $lines = $this->lines($companyId, $data['lines'] ?? [], $signedOn);

            $contract = new LeaseContract;
            $contract->fill([
                'number' => $data['number'] ?? null,
                'tenant_id' => $tenantId,
                'signed_on' => $signedOn,
                'end_on' => isset($data['end_on']) ? $this->date($data['end_on'], 'end_on', 'signed_on_required') : null,
                'note' => $data['note'] ?? null,
            ]);
            $contract->company_id = $companyId;
            $contract->save();

            foreach ($lines as $line) {
                $this->newLine($contract, $line['property_id'], $line['start_on'], $line['rent'], $line['deposit']);
            }

            $this->ensurePropertiesAreFree($contract);

            return $contract->refresh();
        });
    }

    /** Нов наем за един имот от дадена дата (депозитът остава). */
    public function changeRent(LeaseContractAnnex $annex, Property|int $property, $from, $rent): LeaseContractLine
    {
        return $this->changeLine($annex, $property, $from, ['rent' => $this->rent($rent)]);
    }

    /** Нов договорен депозит за един имот от дадена дата (наемът остава). */
    public function changeDeposit(LeaseContractAnnex $annex, Property|int $property, $from, $deposit): LeaseContractLine
    {
        return $this->changeLine($annex, $property, $from, ['deposit' => $this->deposit($deposit)]);
    }

    /** Добавя имот в договора от дадена дата. */
    public function addProperty(LeaseContractAnnex $annex, Property|int $property, $from, $rent, $deposit = null): LeaseContractLine
    {
        return DB::transaction(function () use ($annex, $property, $from, $rent, $deposit) {
            $contract = $this->contractOf($annex);
            $propertyId = $this->propertyId($contract->company_id, $property);
            $rent = $this->rent($rent);
            $deposit = $this->deposit($deposit);
            $from = $this->addDate($contract, $from);

            $line = $this->newLine($contract, $propertyId, $from, $rent, $deposit, $annex);
            $this->ensurePropertyIsFree($contract->company_id, $propertyId, $contract);

            return $line;
        });
    }

    private function changeLine(LeaseContractAnnex $annex, Property|int $property, $from, array $change): LeaseContractLine
    {
        return DB::transaction(function () use ($annex, $property, $from, $change) {
            $contract = $this->contractOf($annex);
            $current = $this->openLine($contract, $property);

            $line = $this->replaceVersion($contract, $annex, $current, $from, array_merge([
                'property_id' => $current->property_id,
                'rent' => $current->rent,
                'deposit' => $current->deposit,
            ], $change));
            $this->ensurePropertyIsFree($contract->company_id, $current->property_id, $contract);

            return $line;
        });
    }

    // Проверки на входа

    private function tenantId(int $companyId, $tenantId): int
    {
        if ($tenantId === null || $tenantId === '') {
            $this->reject('tenant_id', 'tenant_required');
        }

        $exists = Tenant::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)->whereNull('archived_at')->whereKey($tenantId)->exists();

        return $exists ? (int) $tenantId : $this->reject('tenant_id', 'tenant_not_found');
    }

    private function rent($rent): string
    {
        return $this->money($rent, 'lines', 'rent_required');
    }

    /** Празен депозит = 0 (без депозит). */
    private function deposit($deposit): string
    {
        return $deposit === null || $deposit === ''
            ? '0.00'
            : $this->money($deposit, 'lines', 'deposit_invalid', allowZero: true);
    }

    /** @return list<array{property_id: int, start_on: CarbonImmutable, rent: string, deposit: string}> */
    private function lines(int $companyId, array $lines, CarbonImmutable $signedOn): array
    {
        if ($lines === []) {
            $this->reject('lines', 'lines_required');
        }

        return array_map(fn (array $line) => [
            'property_id' => $this->propertyId($companyId, $line['property_id'] ?? null),
            'start_on' => $this->lineStart($line, $signedOn),
            'rent' => $this->rent($line['rent'] ?? null),
            'deposit' => $this->deposit($line['deposit'] ?? null),
        ], array_values($lines));
    }

    private function newLine(LeaseContract $contract, int $propertyId, CarbonImmutable $from, string $rent, string $deposit, ?LeaseContractAnnex $annex = null): LeaseContractLine
    {
        return $this->newVersion(new LeaseContractLine, $contract, $from, $annex, [
            'property_id' => $propertyId,
            'rent' => $rent,
            'deposit' => $deposit,
        ]);
    }
}
