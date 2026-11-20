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
 * ред (имот) има месечен наем и договорен депозит, и двата 0 и повече (наем 0
 * е за придружаващ имот, например гараж; PROJECT.md, променено 20.11.2026).
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
     *     company_id?: int, lines: list<array{property_id: int, start_on?: mixed, rent: string, deposit?: ?string, first_period_rent?: ?string}>}  $data
     */
    public function create(array $data): LeaseContract
    {
        return $this->collecting($data, function () use ($data) {
            $companyId = $this->companyId($data);
            $signedOn = $this->attempt(fn () => $this->date($data['signed_on'] ?? null, 'signed_on', 'signed_on_required'));
            $endOn = $this->attempt(fn () => isset($data['end_on']) ? $this->date($data['end_on'], 'end_on', 'signed_on_required') : null);
            $tenantId = $this->attempt(fn () => $this->tenantId($companyId, $data['tenant_id'] ?? null));
            $this->attempt(fn () => $this->ensureContractNumberIsFree($this->blank($companyId), $data['number'] ?? null));
            $lines = $this->lines($companyId, $data['lines'] ?? [], $signedOn);

            if ($this->failures() > 0) {
                return null;
            }

            $contract = new LeaseContract;
            $contract->fill([
                'number' => $data['number'] ?? null,
                'tenant_id' => $tenantId,
                'signed_on' => $signedOn,
                'end_on' => $endOn,
                'note' => $data['note'] ?? null,
            ]);
            $contract->company_id = $companyId;
            $contract->save();

            foreach ($lines as $line) {
                $this->newLine($contract, $line['property_id'], $line['start_on'], $line['rent'], $line['deposit'], firstPeriodRent: $line['first_period_rent']);
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
            $before = $this->failures();
            $propertyId = $this->attempt(fn () => $this->propertyId($contract->company_id, $property));
            $rent = $this->attempt(fn () => $this->rent($rent));
            $deposit = $this->attempt(fn () => $this->deposit($deposit));
            $from = $this->attempt(fn () => $this->addDate($contract, $from));
            if ($this->failures() > $before) {
                throw new SkippedCheck;
            }

            $line = $this->newLine($contract, $propertyId, $from, $rent, $deposit, $annex);
            $this->ensurePropertyIsFree($contract->company_id, $propertyId, $contract);

            return $line;
        });
    }

    /**
     * Един анекс с няколко промени наведнъж (екранът „Нов анекс“): нов наем
     * и/или депозит, добавен или махнат имот, удължаване. Промените важат от
     * обща дата „from“; всеки имот може да има своя. При грешка не остава нищо.
     *
     * @param  array{number?: ?int, signed_on: mixed, note?: ?string, from?: mixed, end_on?: mixed,
     *     lines?: list<array{action: string, property_id: int, from?: mixed, rent?: ?string, deposit?: ?string}>}  $data
     */
    public function applyAnnex(LeaseContract $contract, array $data): LeaseContractAnnex
    {
        return $this->collecting($data, function () use ($contract, $data) {
            $endOn = $data['end_on'] ?? null;
            $lines = array_values($data['lines'] ?? []);
            $changesTerm = $endOn !== null && $endOn !== '';

            if (! $changesTerm && $lines === []) {
                $this->attempt(fn () => $this->reject('changes', 'annex_no_changes'));
            }

            $from = $this->annexFrom($lines, $data['from'] ?? null);
            $annex = $this->annexForChanges($contract, $data);

            foreach ($lines as $line) {
                $lineFrom = ($line['from'] ?? null) ?: $from;
                $property = (int) ($line['property_id'] ?? 0);

                $this->attempt(fn () => match ($line['action'] ?? null) {
                    'change' => $this->changeTerms($annex, $property, $lineFrom, $line['rent'] ?? null, $line['deposit'] ?? null),
                    'add' => $this->addProperty($annex, $property, $lineFrom, $line['rent'] ?? null, $line['deposit'] ?? null),
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

    /** Нов наем и/или депозит от дадена дата; празното остава, каквото е. */
    public function changeTerms(LeaseContractAnnex $annex, Property|int $property, $from, $rent, $deposit): LeaseContractLine
    {
        $change = [];
        $before = $this->failures();
        if ($rent !== null && $rent !== '') {
            $change['rent'] = $this->attempt(fn () => $this->rent($rent));
        }
        if ($deposit !== null && $deposit !== '') {
            $change['deposit'] = $this->attempt(fn () => $this->deposit($deposit));
        }

        if ($change === []) {
            $this->reject('lines', 'lease_change_empty');
        }
        if ($this->failures() > $before) {
            throw new SkippedCheck;
        }

        return $this->changeLine($annex, $property, $from, $change);
    }

    /**
     * Поправка направо на полетата, от които зависят сумите (PROJECT.md,
     * „Поправки“, „Договор за наем“): наемател, имот, дати, наем, депозит.
     * Само докато няма начисления; на място, с журнал, със същите проверки.
     *
     * @param  array{tenant_id?: ?int,
     *     lines?: array<int, array{property_id?: int, valid_from?: mixed, valid_to?: mixed, rent?: ?string, deposit?: ?string}>}  $data
     */
    public function correct(LeaseContract $contract, array $data): LeaseContract
    {
        if ($contract->hasCharges()) {
            $this->reject('changes', 'correction_after_charges');
        }

        return $this->collecting($data, function () use ($contract, $data) {
            $contract = LeaseContract::query()->withoutGlobalScopes()
                ->where('company_id', $contract->company_id)->lockForUpdate()->findOrFail($contract->id);

            if (array_key_exists('tenant_id', $data)) {
                $this->attempt(function () use ($contract, $data) {
                    $contract->tenant_id = $this->tenantId($contract->company_id, $data['tenant_id']);
                    $contract->save();
                });
            }

            foreach ($data['lines'] ?? [] as $id => $values) {
                $this->attempt(fn () => $this->correctLine($contract, (int) $id, (array) $values));
            }

            $this->attempt(function () use ($contract) {
                $start = $contract->startsOn();
                if ($contract->terminated_on !== null && $start !== null && $contract->terminated_on->lte($start)) {
                    $this->reject('lines', 'terminated_before_start');
                }
            });

            $this->ensurePropertiesAreFree($contract);

            return $contract->refresh();
        });
    }

    protected function correctLineValues($contract, $line, array $values): void
    {
        if (array_key_exists('rent', $values)) {
            $line->rent = $this->rent($values['rent']);
        }

        if (array_key_exists('deposit', $values)) {
            $line->deposit = $this->deposit($values['deposit']);
        }
    }

    /**
     * Имотите (номерата), които за част от периода си в договора за наем нямат
     * договор за управление (PROJECT.md, „Договор за наем“). Сега е само
     * предупреждение; отказът идва с начисленията. Архивираните не се броят.
     *
     * @return list<int>
     */
    public function propertiesWithoutManagement(LeaseContract $contract): array
    {
        $missing = [];

        $lines = DB::table('lease_contract_lines as l')
            ->join('properties as p', fn ($join) => $join->on('p.id', '=', 'l.property_id')->on('p.company_id', '=', 'l.company_id'))
            ->where('l.company_id', $contract->company_id)
            ->where('l.lease_contract_id', $contract->id)
            ->get(['l.property_id', 'l.valid_from', 'l.valid_to', 'p.number']);

        foreach ($lines as $line) {
            [$from, $to] = $this->period($line->valid_from, $line->valid_to, $contract->terminated_on?->toDateString());
            if ($to !== null && $to <= $from) {
                continue;
            }

            $management = DB::table('owner_contract_lines as l')
                ->join('owner_contracts as c', fn ($join) => $join->on('c.id', '=', 'l.owner_contract_id')->on('c.company_id', '=', 'l.company_id'))
                ->where('l.company_id', $contract->company_id)
                ->where('l.property_id', $line->property_id)
                ->whereNull('c.archived_at')
                ->orderBy('l.valid_from')
                ->get(['l.valid_from', 'l.valid_to', 'c.terminated_on'])
                ->map(fn ($row) => $this->period($row->valid_from, $row->valid_to, $row->terminated_on));

            if (! $this->covers($management->all(), $from, $to)) {
                $missing[] = (int) $line->number;
            }
        }

        $missing = array_values(array_unique($missing));
        sort($missing);

        return $missing;
    }

    /** Период „от–до“ (до без него); краят е по-ранният от „до“ и прекратяването. null = без край. */
    private function period(string $from, ?string $to, ?string $terminated): array
    {
        $ends = array_filter([$to, $terminated]);

        return [substr($from, 0, 10), $ends === [] ? null : substr(min($ends), 0, 10)];
    }

    /** Покриват ли периодите (подредени по начало) целия период [from, to). */
    private function covers(array $periods, string $from, ?string $to): bool
    {
        $cursor = $from;

        foreach ($periods as [$start, $end]) {
            if ($start > $cursor) {
                break; // дупка
            }
            if ($end === null) {
                return true;
            }
            if ($end > $cursor) {
                $cursor = $end;
            }
            if ($to !== null && $cursor >= $to) {
                return true;
            }
        }

        return $to !== null && $cursor >= $to;
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
        return $this->money($rent, 'lines', 'rent_required', allowZero: true);
    }

    /** Празен депозит = 0 (без депозит). */
    private function deposit($deposit): string
    {
        return $deposit === null || $deposit === ''
            ? '0.00'
            : $this->money($deposit, 'lines', 'deposit_invalid', allowZero: true);
    }

    /**
     * Редовете на нов договор: всяко поле на всеки ред се проверява отделно,
     * а застъпването, щом имотът и датата на реда са верни.
     *
     * @return list<array{property_id: int, start_on: CarbonImmutable, rent: string, deposit: string}>
     */
    private function lines(int $companyId, array $lines, ?CarbonImmutable $signedOn): array
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
            $rent = $this->attempt(fn () => $this->rent($line['rent'] ?? null));
            $deposit = $this->attempt(fn () => $this->deposit($line['deposit'] ?? null));
            $firstPeriodRent = $this->attempt(fn () => $this->firstPeriodRent($line['first_period_rent'] ?? null));

            if ($propertyId !== null && $start !== null) {
                $this->attempt(fn () => $this->ensureFreeFrom($this->blank($companyId), $companyId, $propertyId, $start, $earlier));
                $earlier[] = $propertyId;
            }

            if ($this->failures() === $before) {
                $result[] = ['property_id' => $propertyId, 'start_on' => $start, 'rent' => $rent, 'deposit' => $deposit,
                    'first_period_rent' => $firstPeriodRent];
            }
        }

        return $result;
    }

    /** Празен договор на фирмата: само за проверките преди записа. */
    private function blank(int $companyId): LeaseContract
    {
        $contract = new LeaseContract;
        $contract->company_id = $companyId;

        return $contract;
    }

    /**
     * Договорен наем за първия период (PROJECT.md, „Начисления, уточнения“):
     * незадължителен, 0 или повече. Празен = наемът се пресмята по дни.
     */
    private function firstPeriodRent($amount): ?string
    {
        return $amount === null || $amount === ''
            ? null
            : $this->money($amount, 'lines', 'first_period_rent_invalid', allowZero: true);
    }

    private function newLine(LeaseContract $contract, int $propertyId, CarbonImmutable $from, string $rent, string $deposit,
        ?LeaseContractAnnex $annex = null, ?string $firstPeriodRent = null): LeaseContractLine
    {
        return $this->newVersion(new LeaseContractLine, $contract, $from, $annex, [
            'property_id' => $propertyId,
            'rent' => $rent,
            'deposit' => $deposit,
            'first_period_rent' => $firstPeriodRent,
        ]);
    }
}
