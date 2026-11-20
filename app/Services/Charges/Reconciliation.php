<?php

namespace App\Services\Charges;

use App\Enums\CalculationMethod;
use App\Enums\ChargeType;
use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Models\ChargeDraft;
use App\Models\LeaseContract;
use App\Models\LedgerEntry;
use App\Models\OwnerContract;
use App\Models\PeriodLock;
use App\Models\Property;
use App\Models\Scopes\CompanyScope;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Models\Statement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Сверка и корекции на издаден месец (PROJECT.md, т. 2 „Промяна в издаден
 * месец“, „Сверка при „Генерирай месеца““, „Издаване и заключване“).
 *
 * Пресмята месеца по сегашните договори и го сравнява с книгата. Разликата
 * се записва като корекция за същия месец (за наемателя и за собственика),
 * само след потвърждение и с причина. Издаденото известие не се променя.
 * Наем, сменен на ръка (при издаването или с ръчна корекция), не се сравнява:
 * ръчното решение важи, а делът на собственика при процент се смята от него.
 */
class Reconciliation
{
    public const ISSUED_DIFFERENCE = 'issued_difference';

    /** Колко назад се гледат издадените месеци при сверката (месеци). */
    private const LOOK_BACK = 12;

    public function __construct(private readonly ChargeCalculator $calculator) {}

    /** @return list<Difference> разликите за месеца, за видимите имоти */
    public function differences(string $month): array
    {
        $start = Month::parse($month);
        if (! $this->entries()->whereDate('month', $start)->exists()) {
            return [];
        }

        $visible = Property::query()->pluck('id')->flip();

        return array_values(array_filter($this->compute($start),
            fn (Difference $difference) => $visible->has($difference->propertyId)));
    }

    /** Разликите, които засягат договора (за предупреждението след запис на договора). */
    public function differencesFor(LeaseContract|OwnerContract $contract): array
    {
        $column = $contract instanceof LeaseContract ? 'lease_contract_id' : 'owner_contract_id';
        $months = $this->entries()->where($column, $contract->id)->distinct()->pluck('month');

        $found = [];
        foreach ($months as $month) {
            foreach ($this->differences(Month::key(CarbonImmutable::parse($month))) as $difference) {
                $own = $contract instanceof LeaseContract
                    ? $difference->leaseContractId === $contract->id
                    : $difference->ownerContractId === $contract->id;
                $sameProperty = $contract->lines()->where('property_id', $difference->propertyId)->exists();

                if ($own || $sameProperty) {
                    $found[Month::key(CarbonImmutable::parse($month))][] = $difference;
                }
            }
        }

        return $found;
    }

    /** Записва корекцията за една разлика (след потвърждение, с причина). */
    public function correct(string $month, string $key, ?string $reason): ?LedgerEntry
    {
        $start = Month::parse($month);
        $input = new ChargeInput;
        $reason = $input->reason($reason);
        $input->check();

        return DB::transaction(function () use ($start, $key, $reason) {
            Ledger::lockCompany($this->companyId());

            $difference = collect($this->compute($start))->firstWhere('key', $key)
                ?? throw ValidationException::withMessages(['key' => __('charges.messages.difference_gone')]);

            return Ledger::book($start, LedgerKind::Correction, $this->attributes($difference) + ['reason' => $reason],
                Cents::from($difference->difference));
        });
    }

    /**
     * Ръчна корекция в издаден месец, със знак и с причина. При наем делът
     * на собственика при процент се коригира сам.
     *
     * @param  array{type: string, lease_contract_id?: int, owner_contract_id?: int, property_id: int, amount: string, reason: string}  $data
     */
    public function manual(string $month, array $data): array
    {
        $input = new ChargeInput;
        $start = Month::tryParse($month);
        if ($start === null || ! $this->entries()->whereDate('month', $start)->exists()) {
            $input->fail('month', 'month_not_issued');
        }

        $type = ChargeType::tryFrom((string) ($data['type'] ?? ''));
        if ($type === null || ! $type->canBeManual()) {
            $input->fail('type', 'type_not_manual');
        }

        $propertyId = $data['property_id'] ?? null;
        $lease = $management = null;
        if ($type === ChargeType::Owner) {
            $management = $input->managementWithProperty($data['owner_contract_id'] ?? null, $propertyId);
        } elseif ($type !== null) {
            $lease = $input->leaseWithProperty($data['lease_contract_id'] ?? null, $propertyId);
        }
        $amount = $input->signedAmount($data['amount'] ?? null);
        $reason = $input->reason($data['reason'] ?? null);
        $input->check();

        return DB::transaction(function () use ($start, $type, $lease, $management, $propertyId, $amount, $reason) {
            Ledger::lockCompany($this->companyId());
            $cents = Cents::from($amount);
            $common = ['type' => $type, 'property_id' => (int) $propertyId, 'reason' => $reason];
            $entries = [];

            if ($management !== null) {
                $entries[] = Ledger::book($start, LedgerKind::Manual, $common + [
                    'account' => LedgerAccount::Owner, 'owner_contract_id' => $management->id, 'owner_id' => $management->owner_id,
                ], $cents);

                return $entries;
            }

            $tenant = $common + ['lease_contract_id' => $lease->id, 'tenant_id' => $lease->tenant_id];
            $entries[] = Ledger::book($start, LedgerKind::Manual, $tenant + ['account' => LedgerAccount::Tenant], $cents);
            if ($type === ChargeType::Deposit) {
                $entries[] = Ledger::book($start, LedgerKind::Manual, $tenant + ['account' => LedgerAccount::Deposit], $cents);
            }

            // Наемът сега е ръчен: дялът на собственика при процент се смята от записания наем.
            if ($type === ChargeType::Rent) {
                foreach ($this->ownerChanges($start, (int) $propertyId) as $change) {
                    $entries[] = Ledger::book($start, LedgerKind::Correction, $change['attributes'] + ['reason' => $reason], $change['cents']);
                }
            }

            return array_values(array_filter($entries));
        });
    }

    /** Предупреждения за месец М: издадени месеци преди него с разлики. */
    public function warnings(string $month): array
    {
        $start = Month::parse($month);
        $months = $this->entries()
            ->whereDate('month', '<', $start)->whereDate('month', '>=', $start->subMonths(self::LOOK_BACK))
            ->distinct()->orderBy('month')->pluck('month');

        $warnings = [];
        foreach ($months as $issued) {
            $issued = CarbonImmutable::parse($issued);
            $count = count($this->differences(Month::key($issued)));
            if ($count > 0) {
                $warnings[] = new WorksheetWarning(self::ISSUED_DIFFERENCE, __('charges.warnings.issued_difference', [
                    'month' => Month::label($issued), 'count' => $count,
                ]));
            }
        }

        return $warnings;
    }

    /**
     * Целевите суми към собственика за месеца: по договорите за наем $leases
     * (издадените), с ръчния наем, където има такъв. Фиксираните — само ако
     * $withFixed (издаден месец: тогава са записани).
     *
     * @param  list<int>  $leases
     * @param  array<string, string>  $extraOverrides  „договор:имот“ → наем (напр. от работната таблица при „Издай сега“)
     * @return array<string, array{cents: int, owner: ?int, contract: int, property: int, calculation: string}>
     */
    public function ownerTargets(CarbonImmutable $start, array $leases, bool $withFixed, array $extraOverrides = []): array
    {
        $overrides = array_merge($this->overrides($start), $extraOverrides);
        $charges = $this->calculator->forMonth($this->companyId(), Month::key($start), $overrides, $leases)->charges();
        $calculations = OwnerContract::query()->withoutGlobalScopes()->where('company_id', $this->companyId())->pluck('calculation', 'id');

        $targets = [];
        foreach ($charges as $charge) {
            if ($charge->type !== ProposedCharge::OWNER) {
                continue;
            }
            $value = $calculations[$charge->ownerContractId] ?? null;
            $calculation = $value instanceof CalculationMethod ? $value->value : (string) $value;
            if ($calculation === CalculationMethod::Fixed->value && ! $withFixed) {
                continue;
            }
            $targets[$charge->ownerContractId.':'.$charge->propertyId] = [
                'cents' => Cents::from($charge->amount), 'owner' => $charge->ownerId,
                'contract' => $charge->ownerContractId, 'property' => $charge->propertyId, 'calculation' => $calculation,
            ];
        }

        return $targets;
    }

    /** Записаното в книгата за месеца: „сметка:договор:имот“ → центове (наемът отделно като „418:rent:…“). */
    public function booked(CarbonImmutable $start): array
    {
        $booked = [];
        foreach ($this->entries()->whereDate('month', $start)->get() as $entry) {
            $cents = Cents::from($entry->amount);
            if ($entry->account === LedgerAccount::Owner) {
                $key = '408:'.$entry->owner_contract_id.':'.$entry->property_id;
                $booked[$key] = ($booked[$key] ?? 0) + $cents;
            } elseif ($entry->account === LedgerAccount::Tenant && $entry->type === ChargeType::Rent) {
                $key = '418:'.$entry->lease_contract_id.':'.$entry->property_id;
                $booked[$key] = ($booked[$key] ?? 0) + $cents;
            }
        }

        return $booked;
    }

    /** Договорите за наем с известие за месеца. */
    public function issuedLeases(CarbonImmutable $start): array
    {
        return Statement::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->whereDate('month', $start)->pluck('lease_contract_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Двойките „договор:имот“ с ръчен наем: променен в работната таблица или с ръчна корекция. */
    public function manualRent(CarbonImmutable $start): array
    {
        $manual = [];
        $drafts = ChargeDraft::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->whereDate('month', $start)->where('type', ChargeType::Rent)->get();
        foreach ($drafts as $row) {
            if ($row->isManual() && $row->statement_id !== null) {
                $manual[$row->lease_contract_id.':'.$row->property_id] = true;
            }
        }
        foreach ($this->entries()->whereDate('month', $start)->where('type', ChargeType::Rent)
            ->where('account', LedgerAccount::Tenant)->where('kind', LedgerKind::Manual)->get() as $entry) {
            $manual[$entry->lease_contract_id.':'.$entry->property_id] = true;
        }

        return $manual;
    }

    /** При ръчен наем: записаният наем (за дела на собственика при процент). */
    private function overrides(CarbonImmutable $start): array
    {
        $booked = $this->booked($start);
        $overrides = [];
        foreach (array_keys($this->manualRent($start)) as $pair) {
            $overrides[$pair] = Cents::format($booked['418:'.$pair] ?? 0);
        }

        return $overrides;
    }

    /** Промените към собственика за имота след ръчна корекция на наема (само при процент). */
    private function ownerChanges(CarbonImmutable $start, int $propertyId): array
    {
        $booked = $this->booked($start);
        $changes = [];

        foreach ($this->ownerTargets($start, $this->issuedLeases($start), PeriodLock::isLocked($start)) as $key => $target) {
            if ($target['property'] !== $propertyId || $target['calculation'] !== CalculationMethod::Percent->value) {
                continue;
            }
            $cents = $target['cents'] - ($booked['408:'.$key] ?? 0);
            if ($cents !== 0) {
                $changes[] = ['cents' => $cents, 'attributes' => [
                    'account' => LedgerAccount::Owner, 'type' => ChargeType::Owner, 'owner_contract_id' => $target['contract'],
                    'owner_id' => $target['owner'], 'property_id' => $propertyId,
                ]];
            }
        }

        return $changes;
    }

    /** @return list<Difference> всички разлики за месеца (без филтър по видимост) */
    private function compute(CarbonImmutable $start): array
    {
        $leases = $this->issuedLeases($start);
        $booked = $this->booked($start);
        $manual = $this->manualRent($start);
        $overrides = $this->overrides($start);
        $charges = $this->calculator->forMonth($this->companyId(), Month::key($start), $overrides, $leases)->charges();
        $differences = [];

        // Към наемателя: наемът по издадените договори.
        $targets = $tenants = [];
        foreach ($charges as $charge) {
            if ($charge->type === ProposedCharge::RENT) {
                $targets[$charge->leaseContractId.':'.$charge->propertyId] = Cents::from($charge->amount);
                $tenants[$charge->leaseContractId] = $charge->tenantId;
            }
        }
        $pairs = array_unique(array_merge(array_keys($targets),
            array_map(fn ($key) => substr($key, 4), array_filter(array_keys($booked), fn ($key) => str_starts_with($key, '418:')))));

        foreach ($pairs as $pair) {
            if (isset($manual[$pair])) {
                continue;
            }
            [$lease, $property] = array_map('intval', explode(':', $pair));
            $differences[] = $this->difference('418:'.$pair, LedgerAccount::Tenant, ChargeType::Rent, $property,
                $lease, null, $tenants[$lease] ?? LeaseContract::query()->withoutGlobalScopes()->whereKey($lease)->value('tenant_id'), null,
                $booked['418:'.$pair] ?? 0, $targets[$pair] ?? 0);
        }

        // Към собственика: при издаден месец и фиксираните.
        $owners = $this->ownerTargets($start, $leases, PeriodLock::isLocked($start));
        $manualOwners = $this->manualOwners($start);
        $keys = array_unique(array_merge(array_keys($owners),
            array_map(fn ($key) => substr($key, 4), array_filter(array_keys($booked), fn ($key) => str_starts_with($key, '408:')))));

        foreach ($keys as $key) {
            if (isset($manualOwners[$key])) {
                continue;
            }
            [$contract, $property] = array_map('intval', explode(':', $key));
            $differences[] = $this->difference('408:'.$key, LedgerAccount::Owner, ChargeType::Owner, $property,
                null, $contract, null, $owners[$key]['owner'] ?? OwnerContract::query()->withoutGlobalScopes()->whereKey($contract)->value('owner_id'),
                $booked['408:'.$key] ?? 0, $owners[$key]['cents'] ?? 0);
        }

        return array_values(array_filter($differences));
    }

    /** Двойките „договор за управление:имот“, сменени на ръка (в работната таблица или с ръчна корекция към собственика). */
    private function manualOwners(CarbonImmutable $start): array
    {
        $manual = [];
        foreach (ChargeDraft::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->whereDate('month', $start)->where('type', ChargeType::Owner)->get() as $row) {
            if ($row->isManual()) {
                $manual[$row->owner_contract_id.':'.$row->property_id] = true;
            }
        }
        foreach ($this->entries()->whereDate('month', $start)->where('account', LedgerAccount::Owner)
            ->where('kind', LedgerKind::Manual)->get() as $entry) {
            $manual[$entry->owner_contract_id.':'.$entry->property_id] = true;
        }

        return $manual;
    }

    private function difference(string $key, LedgerAccount $account, ChargeType $type, int $property, ?int $lease, ?int $owner,
        ?int $tenantId, ?int $ownerId, int $booked, int $target): ?Difference
    {
        if ($booked === $target) {
            return null;
        }

        return new Difference($key, $account, $type, $property, $lease, $owner, $tenantId, $ownerId,
            Cents::format($booked), Cents::format($target), Cents::format($target - $booked));
    }

    private function attributes(Difference $difference): array
    {
        return [
            'account' => $difference->account,
            'type' => $difference->type,
            'property_id' => $difference->propertyId,
            'lease_contract_id' => $difference->leaseContractId,
            'owner_contract_id' => $difference->ownerContractId,
            'tenant_id' => $difference->tenantId,
            'owner_id' => $difference->ownerId,
        ];
    }

    /** Записите в книгата на фирмата (без филтъра „само прикачените“: сметката е за целия месец). */
    private function entries()
    {
        return LedgerEntry::query()->withoutGlobalScope(RegistryVisibilityScope::class);
    }

    private function companyId(): int
    {
        return CompanyScope::restrictedCompanyId()
            ?? throw new LogicException('Сверката иска влязъл служител на фирма.');
    }
}
