<?php

namespace App\Services\Registry;

use App\Models\LeaseContract;
use App\Models\OwnerContract;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Допълнителните колони в списъците на регистъра (PROJECT.md, т. 2.1–2.5,
 * 24.11.2026): номерата на договорите на собственика, бенефициента и
 * наемателя; сегашните собственик и наемател на имота. Само за записите на
 * страницата; минава през филтрите по фирма и „само прикачените имоти“.
 */
final class ListColumns
{
    /** @return array<int, list<OwnerContract>> собственик => неархивираните му договори за управление */
    public function ownerContracts(Collection $ownerIds): array
    {
        return OwnerContract::query()->whereIn('owner_id', $ownerIds)->whereNull('archived_at')->orderBy('number')->get()
            ->groupBy('owner_id')->map(fn ($contracts) => $contracts->values()->all())->all();
    }

    /** @return array<int, list<OwnerContract>> бенефициент => неархивираните договори, в които е бенефициент */
    public function beneficiaryContracts(Collection $beneficiaryIds): array
    {
        $result = [];
        foreach (OwnerContract::query()->with('payees')->whereNull('archived_at')
            ->whereHas('payees', fn (Builder $q) => $q->whereIn('beneficiary_id', $beneficiaryIds))->orderBy('number')->get() as $contract) {
            foreach ($contract->payees->pluck('beneficiary_id')->filter()->unique() as $beneficiaryId) {
                $result[(int) $beneficiaryId][] = $contract;
            }
        }

        return $result;
    }

    /** @return array<int, list<LeaseContract>> наемател => договорите за наем, които действат днес */
    public function currentLeases(Collection $tenantIds, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        return LeaseContract::query()->whereIn('tenant_id', $tenantIds)->whereNull('archived_at')->orderBy('number')->get()
            ->filter(fn (LeaseContract $lease) => $lease->isActiveOn($today))
            ->groupBy('tenant_id')->map(fn ($leases) => $leases->values()->all())->all();
    }

    /** @return array<int, array{owner: ?\App\Models\Owner, tenant: ?\App\Models\Tenant}> имот => сегашните собственик и наемател */
    public function propertyParties(Collection $properties, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $ids = $properties->pluck('id');
        $managements = OwnerContract::query()->with(['owner', 'lines'])->whereNull('archived_at')
            ->whereHas('lines', fn (Builder $q) => $q->whereIn('property_id', $ids))->get()
            ->filter(fn (OwnerContract $c) => $c->isActiveOn($today));
        $leases = LeaseContract::query()->with(['tenant', 'lines'])->whereNull('archived_at')
            ->whereHas('lines', fn (Builder $q) => $q->whereIn('property_id', $ids))->get()
            ->filter(fn (LeaseContract $c) => $c->isActiveOn($today));

        $result = [];
        foreach ($properties as $property) {
            /** @var Property $property */
            $result[$property->id] = [
                'owner' => $managements->first(fn (OwnerContract $c) => $c->lineOn($property, $today) !== null)?->owner,
                'tenant' => $leases->first(fn (LeaseContract $c) => $c->lineOn($property, $today) !== null)?->tenant,
            ];
        }

        return $result;
    }
}
