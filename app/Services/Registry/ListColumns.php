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

    /**
     * @return array<int, list<LeaseContract>> наемател => договорите за наем, които действат днес, и предстоящите
     *     (24.11.2026: предстоящият се вижда с бележка „(от дд.мм.гггг)“; при него е попълнено upcomingFrom)
     */
    public function currentLeases(Collection $tenantIds, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        return LeaseContract::query()->whereIn('tenant_id', $tenantIds)->whereNull('archived_at')->orderBy('number')->get()
            ->filter(function (LeaseContract $lease) use ($today) {
                if ($lease->isActiveOn($today)) {
                    return true;
                }
                if ($lease->stateOn($today) === 'upcoming') {
                    $lease->upcomingFrom = $lease->startsOn();

                    return true;
                }

                return false;
            })
            ->groupBy('tenant_id')->map(fn ($leases) => $leases->values()->all())->all();
    }

    /** @return array<int, array{owner: ?\App\Models\Owner, tenant: ?\App\Models\Tenant, tenant_from: ?CarbonImmutable}> имот => сегашните собственик и наемател (или предстоящият наемател и от кога) */
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

        // Без сегашен наемател: първият предстоящ (по датата, от която имотът е в договора).
        $upcoming = LeaseContract::query()->with(['tenant', 'lines'])->whereNull('archived_at')->whereNull('terminated_on')
            ->whereHas('lines', fn (Builder $q) => $q->whereIn('property_id', $ids)->whereDate('valid_from', '>', $today->toDateString()))->get();

        $result = [];
        foreach ($properties as $property) {
            /** @var Property $property */
            $tenant = $leases->first(fn (LeaseContract $c) => $c->lineOn($property, $today) !== null)?->tenant;
            $from = null;
            if ($tenant === null) {
                $next = $upcoming->map(fn (LeaseContract $c) => [$c, $c->lines->where('property_id', $property->id)
                    ->map(fn ($line) => CarbonImmutable::parse($line->valid_from)->startOfDay())->filter(fn ($d) => $d->gt($today))->sort()->first()])
                    ->filter(fn ($pair) => $pair[1] !== null)->sortBy(fn ($pair) => $pair[1]->timestamp)->first();
                [$tenant, $from] = $next ? [$next[0]->tenant, $next[1]] : [null, null];
            }
            $result[$property->id] = [
                'owner' => $managements->first(fn (OwnerContract $c) => $c->lineOn($property, $today) !== null)?->owner,
                'tenant' => $tenant,
                'tenant_from' => $from,
            ];
        }

        return $result;
    }
}
