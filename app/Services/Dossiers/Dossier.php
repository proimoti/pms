<?php

namespace App\Services\Dossiers;

use App\Enums\LedgerAccount;
use App\Models\Beneficiary;
use App\Models\Fund;
use App\Models\LeaseContract;
use App\Models\LedgerEntry;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\OwnerContractAnnex;
use App\Models\Property;
use App\Models\Statement;
use App\Models\Tenant;
use App\Models\UtilityAccount;
use App\Services\Charges\Cents;
use App\Services\Utilities\UnallocatedItem;
use App\Services\Utilities\UnallocatedUtilities;
use App\Services\Utilities\UtilityBalances;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Досиетата (PROJECT.md, „Досиета и първи справки“, решено 23.11.2026): данните,
 * свързани със запис, за разделите на неговата карта. Само чете. Всички заявки
 * минават през филтрите по фирма и по „само прикачените имоти“, затова
 * служителят вижда само своето. Салдата се смятат от книгата; плащанията още
 * ги няма, затова „дължи“ по 418 е начисленото.
 */
final class Dossier
{
    public function __construct(
        private readonly UnallocatedUtilities $unallocated,
        private readonly UtilityBalances $balances,
    ) {}

    // Имот

    /** @return Collection<int, OwnerContract> сегашният най-горе, после останалите (най-новите първи) */
    public function propertyManagements(Property $property): Collection
    {
        return $this->currentFirst(OwnerContract::query()->with('owner')
            ->whereHas('lines', fn (Builder $q) => $q->where('property_id', $property->id))->get());
    }

    /** @return Collection<int, LeaseContract> */
    public function propertyLeases(Property $property): Collection
    {
        return $this->currentFirst(LeaseContract::query()->with(['tenant', 'lines' => fn ($q) => $q->where('property_id', $property->id)->orderBy('valid_from')])
            ->whereHas('lines', fn (Builder $q) => $q->where('property_id', $property->id))->get());
    }

    /** @return Collection<int, UtilityAccount> */
    public function propertyAccounts(Property $property): Collection
    {
        return UtilityAccount::query()->with(['provider', 'fund', 'properties' => fn ($q) => $q->where('property_id', $property->id)])
            ->whereHas('properties', fn (Builder $q) => $q->where('property_id', $property->id))
            ->orderBy('archived_at')->orderBy('id')->get();
    }

    /** @return Collection<int, Fund> */
    public function propertyFunds(Property $property): Collection
    {
        return Fund::query()->whereHas('properties', fn (Builder $q) => $q->where('property_id', $property->id))->orderBy('name')->get();
    }

    /** @return array{decide: list<UnallocatedItem>, waiting: list<UnallocatedItem>} */
    public function propertyUnallocated(Property $property): array
    {
        $items = $this->unallocated->items();
        $own = fn (array $list) => array_values(array_filter($list, fn (UnallocatedItem $item) => $item->propertyId === (int) $property->id));

        return ['decide' => $own($items['decide']), 'waiting' => $own($items['waiting'])];
    }

    /** @return array{tenant: string, owner: string, utility: string} 418 по договорите за наем, 408 към собственика, 407 по имота */
    public function propertyBalances(Property $property): array
    {
        $sum = fn (LedgerAccount $account) => $this->sum(LedgerEntry::query()->where('account', $account->value)->where('property_id', $property->id));

        return [
            'tenant' => $sum(LedgerAccount::Tenant),
            'owner' => $sum(LedgerAccount::Owner),
            'utility' => $this->balances->byProperty((int) $property->company_id)[(int) $property->id] ?? '0.00',
        ];
    }

    // Собственик и бенефициент

    /** @return Collection<int, OwnerContract> */
    public function ownerContracts(Owner $owner): Collection
    {
        return $this->currentFirst(OwnerContract::query()->with('lines.property')->where('owner_id', $owner->id)->get());
    }

    /** @return Collection<int, Beneficiary> */
    public function ownerBeneficiaries(Owner $owner): Collection
    {
        return Beneficiary::query()->where('owner_id', $owner->id)->orderBy('name')->get();
    }

    /** @return Collection<int, OwnerContract> договорите, в които бенефициентът е получател (в някой вариант) */
    public function beneficiaryContracts(Beneficiary $beneficiary): Collection
    {
        return $this->currentFirst(OwnerContract::query()
            ->with(['owner', 'lines.property', 'payees' => fn ($q) => $q->where('beneficiary_id', $beneficiary->id)->orderBy('valid_from')])
            ->whereHas('payees', fn (Builder $q) => $q->where('beneficiary_id', $beneficiary->id))->get());
    }

    /**
     * Имотите по договорите (без повторения), сегашните първи.
     *
     * @param  Collection<int, OwnerContract>  $contracts
     * @return Collection<int, Property>
     */
    public function contractProperties(Collection $contracts): Collection
    {
        return $contracts->flatMap(fn (OwnerContract $contract) => $contract->lines->pluck('property'))
            ->filter()->unique('id')->sortBy('number')->values();
    }

    /**
     * @param  Collection<int, OwnerContract>  $contracts
     * @return Collection<int, OwnerContractAnnex>
     */
    public function annexes(Collection $contracts): Collection
    {
        return OwnerContractAnnex::query()->with('contract')->whereIn('owner_contract_id', $contracts->pluck('id'))
            ->orderBy('signed_on')->orderBy('number')->get();
    }

    // Наемател

    /** @return Collection<int, LeaseContract> */
    public function tenantLeases(Tenant $tenant): Collection
    {
        return $this->currentFirst(LeaseContract::query()->with('lines.property')->where('tenant_id', $tenant->id)->get());
    }

    /** @return Collection<int, Statement> */
    public function tenantStatements(Tenant $tenant): Collection
    {
        return Statement::query()->with('leaseContract')->where('tenant_id', $tenant->id)->orderByDesc('month')->orderByDesc('number')->get();
    }

    /** @return array{total: string, contracts: array<int, string>} дължи по 418: общо и по договор */
    public function tenantOwes(Tenant $tenant): array
    {
        $rows = LedgerEntry::query()->where('account', LedgerAccount::Tenant->value)->where('tenant_id', $tenant->id)
            ->groupBy('lease_contract_id')->orderBy('lease_contract_id')
            ->selectRaw('lease_contract_id, SUM(amount) as total')->get();
        $contracts = $rows->mapWithKeys(fn ($row) => [(int) $row->lease_contract_id => Cents::format(Cents::from((string) $row->total))])->all();

        return ['total' => Cents::format(array_sum(array_map(fn ($v) => Cents::from($v), $contracts))), 'contracts' => $contracts];
    }

    /**
     * Депозитите по договорите на наемателя (т. 2.6, 24.11.2026): договореният
     * (сборът по сегашните и предстоящите имоти на договора) и начисленият (491).
     * Внесеният идва с плащанията.
     *
     * @return array<int, array{contracted: string, charged: string}>
     */
    public function tenantDeposits(Collection $leases): array
    {
        $charged = LedgerEntry::query()->where('account', LedgerAccount::Deposit->value)->whereIn('lease_contract_id', $leases->pluck('id'))
            ->groupBy('lease_contract_id')->selectRaw('lease_contract_id, SUM(amount) as total')->pluck('total', 'lease_contract_id');
        $result = [];
        foreach ($leases as $lease) {
            $latest = $lease->lines->filter(fn ($line) => $line->valid_to === null || $line->valid_to->isFuture())
                ->groupBy('property_id')->map(fn ($versions) => $versions->sortBy('valid_from')->last());
            $result[$lease->id] = [
                'contracted' => Cents::format($latest->sum(fn ($line) => Cents::from((string) ($line->deposit ?? '0')))),
                'charged' => Cents::format(Cents::from((string) ($charged[$lease->id] ?? '0'))),
            ];
        }

        return $result;
    }

    // Договори

    /** Дължи по договора за наем (418). */
    public function leaseOwes(LeaseContract $lease): string
    {
        return $this->sum(LedgerEntry::query()->where('account', LedgerAccount::Tenant->value)->where('lease_contract_id', $lease->id));
    }

    /** @return Collection<int, Statement> */
    public function leaseStatements(LeaseContract $lease): Collection
    {
        return Statement::query()->where('lease_contract_id', $lease->id)->orderByDesc('month')->orderByDesc('number')->get();
    }

    /** @return Collection<int, LedgerEntry> записите по 408 на договора за управление */
    public function ownerEntries(OwnerContract $contract): Collection
    {
        return LedgerEntry::query()->with('property')->where('account', LedgerAccount::Owner->value)
            ->where('owner_contract_id', $contract->id)->orderBy('month')->orderBy('id')->get();
    }

    // Общо

    private function sum(Builder $query): string
    {
        return Cents::format(Cents::from((string) ($query->sum('amount') ?: '0')));
    }

    /**
     * Действащите днес първи, после бъдещите, после останалите; в група — по-новите първи.
     *
     * @template T of OwnerContract|LeaseContract
     *
     * @param  Collection<int, T>  $contracts
     * @return Collection<int, T>
     */
    private function currentFirst(Collection $contracts): Collection
    {
        $rank = ['active' => 0, 'upcoming' => 1, 'terminated' => 2, 'empty' => 2, 'archived' => 3];

        return $contracts->sortBy([
            fn ($a, $b) => ($rank[$a->stateOn()] ?? 2) <=> ($rank[$b->stateOn()] ?? 2),
            fn ($a, $b) => ($b->startsOn()?->timestamp ?? 0) <=> ($a->startsOn()?->timestamp ?? 0),
        ])->values();
    }
}
