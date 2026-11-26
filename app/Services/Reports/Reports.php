<?php

namespace App\Services\Reports;

use App\Enums\LedgerAccount;
use App\Models\Beneficiary;
use App\Models\LeaseContract;
use App\Models\LedgerEntry;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\PeriodLock;
use App\Models\Property;
use App\Services\Charges\Cents;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;

/**
 * Първите справки (PROJECT.md, „Досиета и първи справки“, решено 23.11.2026).
 * Само четат: нищо не се записва (и в книгата). Всички заявки минават през
 * филтрите по фирма и по „само прикачените имоти“.
 *
 * - изтичащи договори за наем в следващите N дни (1–365);
 * - свободни имоти към днес и от кога;
 * - изтекъл срок без прекратяване (двата вида договори) — само за проследяване;
 * - списък за изплащане към бенефициентите за месец: сумата по 408 (след
 *   комисионата), бенефициентът към последния ден на месеца (с бележка при
 *   смяна), IBAN само с правото „ЕГН и IBAN“, иначе само последните 4 знака.
 */
final class Reports
{
    /** @return list<array{contract: LeaseContract, properties: Collection, owners: Collection, end_on: CarbonImmutable, days: int}> */
    public function expiring(int $days, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $until = $today->addDays($days);

        $leases = LeaseContract::query()->with(['tenant', 'lines.property'])
            ->whereNull('archived_at')->whereNull('terminated_on')
            ->whereDate('end_on', '>=', $today->toDateString())->whereDate('end_on', '<=', $until->toDateString())
            ->orderBy('end_on')->orderBy('number')->get();

        return $leases->map(function (LeaseContract $lease) use ($today) {
            $properties = $this->properties($lease);

            return [
                'contract' => $lease,
                'properties' => $properties,
                'owners' => $this->ownersOf($properties, $today),
                'end_on' => CarbonImmutable::parse($lease->end_on->toDateString()),
                'days' => (int) $today->diffInDays(CarbonImmutable::parse($lease->end_on->toDateString())),
            ];
        })->values()->all();
    }

    /** @return list<array{property: Property, since: ?CarbonImmutable, last_tenant: ?string, managed: bool}> */
    public function vacant(bool $managedOnly = false, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $rows = [];

        foreach (Property::query()->with('propertyType')->whereNull('archived_at')->orderBy('number')->get() as $property) {
            $lines = $this->leaseLines($property);
            $occupied = $lines->contains(fn ($l) => $l['from']->lte($today) && ($l['to'] === null || $l['to']->gt($today)));
            if ($occupied) {
                continue;
            }
            $managed = $this->managedOn($property, $today);
            if ($managedOnly && ! $managed) {
                continue;
            }
            $last = $lines->filter(fn ($l) => $l['to'] !== null && $l['to']->lte($today))->sortByDesc(fn ($l) => $l['to']->timestamp)->first();
            $rows[] = ['property' => $property, 'since' => $last['to'] ?? null, 'last_tenant' => $last['tenant'] ?? null, 'managed' => $managed];
        }

        // Най-дълго свободните първи: никога не отдаваните, после по дата.
        usort($rows, fn ($a, $b) => [$a['since']?->timestamp ?? PHP_INT_MIN, $a['property']->number] <=> [$b['since']?->timestamp ?? PHP_INT_MIN, $b['property']->number]);

        return $rows;
    }

    /** @return list<array{kind: string, contract: LeaseContract|OwnerContract, party: ?string, party_url: ?string, properties: Collection, end_on: CarbonImmutable, days: int}> */
    public function expired(?string $kind = null, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $rows = [];
        $sources = ['lease' => LeaseContract::query()->with('tenant'), 'owner' => OwnerContract::query()->with('owner')];

        foreach ($sources as $type => $query) {
            if ($kind !== null && $kind !== $type) {
                continue;
            }
            foreach ($query->with('lines.property')->whereNull('archived_at')->whereNull('terminated_on')
                ->whereDate('end_on', '<', $today->toDateString())->get() as $contract) {
                $party = $type === 'lease' ? $contract->tenant : $contract->owner;
                $end = CarbonImmutable::parse($contract->end_on->toDateString());
                $rows[] = [
                    'kind' => $type, 'contract' => $contract,
                    'party' => $party?->name, 'party_url' => $party ? '/'.($type === 'lease' ? 'tenants' : 'owners').'/'.$party->id : null,
                    'properties' => $this->properties($contract),
                    'end_on' => $end, 'days' => (int) $end->diffInDays($today),
                ];
            }
        }

        usort($rows, fn ($a, $b) => [$a['end_on']->timestamp, $a['kind'], $a['contract']->number] <=> [$b['end_on']->timestamp, $b['kind'], $b['contract']->number]);

        return $rows;
    }

    /**
     * @return array{issued: bool, groups: list<array{beneficiary: ?Beneficiary, name: string, method: ?string, iban: ?string,
     *     missing_iban: bool, total: string, rows: list<array{contract: OwnerContract, owner: ?Owner, properties: Collection, amount: string, changed: bool}>}>}
     */
    public function payouts(CarbonImmutable $month): array
    {
        $month = $month->startOfMonth()->startOfDay();
        $last = $month->endOfMonth()->startOfDay();

        // Само начисленото (без изплащанията, 26.11.2026).
        $sums = LedgerEntry::query()->where('account', LedgerAccount::Owner->value)->whereDate('month', $month->toDateString())
            ->whereNotIn('kind', \App\Enums\LedgerKind::money())
            ->groupBy('owner_contract_id', 'property_id')->selectRaw('owner_contract_id, property_id, SUM(amount) as total')->get();

        $groups = [];
        foreach ($sums->groupBy('owner_contract_id') as $contractId => $parts) {
            $cents = $parts->sum(fn ($p) => Cents::from((string) $p->total));
            if ($cents === 0) {
                continue;
            }
            $contract = OwnerContract::query()->with('owner')->find($contractId);
            if ($contract === null) {
                continue;
            }
            $payee = $contract->payeeOn($last);
            $first = $contract->payeeOn($month);
            $beneficiary = $payee?->beneficiary_id ? Beneficiary::query()->find($payee->beneficiary_id) : null;
            $key = $beneficiary ? 'b'.$beneficiary->id : 'o'.$contract->owner_id;
            $iban = $beneficiary ? $this->iban($beneficiary) : null;

            $groups[$key] ??= [
                'beneficiary' => $beneficiary, 'name' => $beneficiary?->name ?? $contract->owner?->name ?? '',
                'method' => $payee?->payment_method?->value, 'iban' => $iban,
                'missing_iban' => $payee?->payment_method?->value === 'bank' && $iban === null,
                'cents' => 0, 'rows' => [],
            ];
            $groups[$key]['cents'] += $cents;
            $groups[$key]['rows'][] = [
                'contract' => $contract, 'owner' => $contract->owner,
                'properties' => Property::query()->whereIn('id', $parts->pluck('property_id'))->orderBy('number')->get(),
                'amount' => Cents::format($cents),
                // Изплатено и остава за месеца (най-старите месеци първи, 26.11.2026).
                'paid' => Cents::format($paid = app(\App\Services\Payments\OwnerAllocation::class)->paidForMonth((int) $contract->company_id, $contract->id, $month->format('Y-m'))),
                'left' => Cents::format($cents - $paid),
                'changed' => $first !== null && $payee !== null && ($first->beneficiary_id !== $payee->beneficiary_id || $first->payment_method !== $payee->payment_method),
            ];
        }

        $groups = array_values(array_map(fn ($g) => array_merge($g, ['total' => Cents::format($g['cents'])]), $groups));
        usort($groups, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return ['issued' => PeriodLock::isLocked($month), 'groups' => $groups];
    }

    /**
     * IBAN за справката: целият само с правото „ЕГН и IBAN“, иначе „**** 6789“.
     * Не се пише никъде (журнал, логове).
     */
    private function iban(Beneficiary $beneficiary): ?string
    {
        $raw = $beneficiary->getRawOriginal('iban');
        if ($raw === null || $raw === '') {
            return null;
        }
        $iban = Crypt::decryptString($raw);

        return Auth::user()?->can_view_sensitive ? $iban : '**** '.substr($iban, -4);
    }

    /** @return Collection<int, Property> видимите имоти на договора, без повторения */
    private function properties(LeaseContract|OwnerContract $contract): Collection
    {
        return $contract->lines->pluck('property')->filter()->unique('id')->sortBy('number')->values();
    }

    /** @return Collection<int, Owner> собствениците на имотите по договорите за управление, които действат днес */
    private function ownersOf(Collection $properties, CarbonImmutable $today): Collection
    {
        return OwnerContract::query()->with('owner')
            ->whereHas('lines', fn (Builder $q) => $q->whereIn('property_id', $properties->pluck('id')))
            ->whereNull('archived_at')->get()
            ->filter(fn (OwnerContract $c) => $c->isActiveOn($today))
            ->pluck('owner')->filter()->unique('id')->values();
    }

    /** @return Collection<int, array{from: CarbonImmutable, to: ?CarbonImmutable, tenant: ?string}> редовете на неархивираните договори за наем на имота */
    private function leaseLines(Property $property): Collection
    {
        return LeaseContract::query()->withoutGlobalScopes([\App\Models\Scopes\RegistryVisibilityScope::class])->with(['tenant', 'lines' => fn ($q) => $q->where('property_id', $property->id)])
            ->whereNull('archived_at')->whereHas('lines', fn (Builder $q) => $q->where('property_id', $property->id))->get()
            ->flatMap(fn (LeaseContract $lease) => $lease->lines->map(function ($line) use ($lease) {
                $to = $line->valid_to ? CarbonImmutable::parse($line->valid_to) : null;
                $end = $lease->terminated_on ? CarbonImmutable::parse($lease->terminated_on->toDateString()) : null;

                return [
                    'from' => CarbonImmutable::parse($line->valid_from)->startOfDay(),
                    'to' => $to === null ? $end : ($end === null ? $to : min($to, $end)),
                    'tenant' => $lease->tenant?->name,
                ];
            }));
    }

    private function managedOn(Property $property, CarbonImmutable $today): bool
    {
        return OwnerContract::query()->withoutGlobalScopes([\App\Models\Scopes\RegistryVisibilityScope::class])
            ->whereNull('archived_at')->whereHas('lines', fn (Builder $q) => $q->where('property_id', $property->id))->get()
            ->contains(fn (OwnerContract $c) => $c->lineOn($property, $today) !== null && $c->isActiveOn($today));
    }
}
