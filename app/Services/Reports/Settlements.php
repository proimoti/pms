<?php

namespace App\Services\Reports;

use App\Enums\ChargeType;
use App\Enums\LedgerAccount;
use App\Models\LeaseContract;
use App\Models\LedgerEntry;
use App\Models\OwnerContract;
use App\Models\PeriodLock;
use App\Models\Property;
use App\Services\Charges\Cents;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Справка „Разчети за период“ (PROJECT.md, т. 2.14, 24.11.2026). Само чете.
 *
 * Броят само издадените месеци в периода (от месец до месец); неиздадените се
 * изброяват в бележка. Ред за всеки имот със записи в книгата (и при договор с
 * няколко имота): начисленият наем (418, вид „наем“), сумата към собственика
 * (408) и резултатът = наемът минус 408 — при „Собствен“ това е целият наем,
 * при фиксиран наемът минус фиксираната сума. Всички заявки минават през
 * филтрите по фирма и „само прикачените имоти“.
 */
final class Settlements
{
    /**
     * @return array{issued: list<CarbonImmutable>, not_issued: list<CarbonImmutable>, rows: list<array>, totals: array{rent: string, owner: string, result: string}}
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfMonth()->startOfDay();
        $to = $to->startOfMonth()->startOfDay();
        $months = [];
        for ($month = $from; $month->lte($to); $month = $month->addMonth()) {
            $months[] = $month;
        }
        $locked = PeriodLock::query()->whereDate('month', '>=', $from->toDateString())->whereDate('month', '<=', $to->toDateString())
            ->get()->map(fn ($lock) => CarbonImmutable::parse($lock->month)->format('Y-m-d'))->flip();
        $issued = array_values(array_filter($months, fn ($m) => $locked->has($m->format('Y-m-d'))));
        $notIssued = array_values(array_filter($months, fn ($m) => ! $locked->has($m->format('Y-m-d'))));

        $rows = $issued === [] ? [] : $this->rows($issued, $to->endOfMonth()->startOfDay());
        $sum = fn (string $key) => Cents::format(array_sum(array_map(fn ($row) => Cents::from($row[$key]), $rows)));

        return [
            'issued' => $issued,
            'not_issued' => $notIssued,
            'rows' => $rows,
            'totals' => ['rent' => $sum('rent'), 'owner' => $sum('owner'), 'result' => $sum('result')],
        ];
    }

    /** @param list<CarbonImmutable> $months */
    private function rows(array $months, CarbonImmutable $lastDay): array
    {
        $dates = array_map(fn ($m) => $m->format('Y-m-d'), $months);
        $rent = LedgerEntry::query()->where('account', LedgerAccount::Tenant->value)->where('type', ChargeType::Rent->value)
            ->whereIn('month', $dates)->whereNotNull('property_id')
            ->groupBy('property_id', 'lease_contract_id')->selectRaw('property_id, lease_contract_id, SUM(amount) as total')->get();
        $owner = LedgerEntry::query()->where('account', LedgerAccount::Owner->value)
            ->whereIn('month', $dates)->whereNotNull('property_id')
            ->groupBy('property_id', 'owner_contract_id')->selectRaw('property_id, owner_contract_id, SUM(amount) as total')->get();

        $properties = Property::query()->with('broker')->whereIn('id', $rent->pluck('property_id')->merge($owner->pluck('property_id'))->unique())
            ->orderBy('number')->get();
        $leases = LeaseContract::query()->with(['tenant', 'lines'])->whereIn('id', $rent->pluck('lease_contract_id')->filter()->unique())->orderBy('number')->get()->keyBy('id');
        $managements = OwnerContract::query()->with(['owner', 'lines'])
            ->whereHas('lines', fn (Builder $q) => $q->whereIn('property_id', $properties->pluck('id')))->orderBy('number')->get();

        $rows = [];
        foreach ($properties as $property) {
            $rentParts = $rent->where('property_id', $property->id);
            $ownerParts = $owner->where('property_id', $property->id);
            $rentCents = $rentParts->sum(fn ($p) => Cents::from((string) $p->total));
            $ownerCents = $ownerParts->sum(fn ($p) => Cents::from((string) $p->total));
            $propertyLeases = $rentParts->pluck('lease_contract_id')->filter()->unique()->map(fn ($id) => $leases->get($id))->filter()->sortBy('number')->values();
            // Договорите за управление: тези със записи по 408 и действащият в края на периода (например „Собствен“, без 408).
            $ownerIds = $ownerParts->pluck('owner_contract_id')->filter()->map(fn ($id) => (int) $id);
            $propertyManagements = $managements->filter(fn (OwnerContract $c) => $ownerIds->contains($c->id)
                || ($c->isActiveOn($lastDay) && $c->lineOn($property, $lastDay) !== null))->values();

            $rows[] = [
                'property' => $property,
                'leases' => $propertyLeases,
                'managements' => $propertyManagements,
                'monthly' => $propertyLeases->map(fn (LeaseContract $lease) => $this->monthlyRent($lease, $property, $lastDay))->filter()->values(),
                'rent' => Cents::format($rentCents),
                'owner' => Cents::format($ownerCents),
                'result' => Cents::format($rentCents - $ownerCents),
            ];
        }

        return $rows;
    }

    /** Месечният наем по договора за имота: последният вариант, започнал до края на периода. */
    private function monthlyRent(LeaseContract $lease, Property $property, CarbonImmutable $lastDay): ?string
    {
        $line = $lease->lines->where('property_id', $property->id)
            ->filter(fn ($line) => CarbonImmutable::parse($line->valid_from)->lte($lastDay))
            ->sortBy(fn ($line) => CarbonImmutable::parse($line->valid_from)->timestamp)->last();

        return $line ? Cents::format(Cents::from((string) $line->rent)) : null;
    }

}
