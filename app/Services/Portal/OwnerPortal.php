<?php

namespace App\Services\Portal;

use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Enums\OwnerContractType;
use App\Models\Beneficiary;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Models\User;
use App\Services\Charges\Cents;
use App\Services\Payments\OwnerAllocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Данните за портала на собственика (PROJECT.md, „Портал и служители“,
 * 27.11.2026). Регистърът е скрит за клиентите, затова всичко се чете изрично:
 * само собствениците, свързани с профила (и неархивирани), във фирмата на
 * профила, и само имотите по техните договори за управление. Името на
 * наемателя — при „Представител, %“ и „Представител, фиксиран“; наемът и
 * комисионата — само при „Представител, %“. IBAN — само „•••• 1234“. Само чете.
 */
final class OwnerPortal
{
    /** Типовете, при които собственикът е страна по договора за наем. */
    private const SHOWS_TENANT = [OwnerContractType::RepresentativePercent, OwnerContractType::RepresentativeFixed];

    public function __construct(private readonly OwnerAllocation $allocation) {}

    /** @return Collection<int, Owner> собствениците на профила, без архивираните */
    public function owners(User $client): Collection
    {
        $ids = DB::table('portal_links')->where('company_id', $client->company_id)->where('user_id', $client->id)
            ->whereNotNull('owner_id')->pluck('owner_id');

        return Owner::query()->withoutGlobalScope(RegistryVisibilityScope::class)->where('company_id', $client->company_id)
            ->whereIn('id', $ids)->whereNull('archived_at')->orderBy('name')->get();
    }

    /** Собственик на профила или null (чужд, архивиран, несъществуващ). */
    public function owner(User $client, int $id): ?Owner
    {
        return $this->owners($client)->firstWhere('id', $id);
    }

    /** @return Collection<int, OwnerContract> договорите за управление (и приключилите), без архивираните; действащите първи */
    public function contracts(Owner $owner): Collection
    {
        $any = fn ($q) => $q->withoutGlobalScope(RegistryVisibilityScope::class);
        $contracts = OwnerContract::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->with(['lines' => fn ($q) => $any($q)->orderBy('valid_from')->orderBy('id'), 'lines.property' => $any,
                'commissions' => $any, 'payees' => $any])
            ->where('company_id', $owner->company_id)->where('owner_id', $owner->id)->whereNull('archived_at')->get();

        $rank = ['active' => 0, 'upcoming' => 1, 'ended' => 2];

        return $contracts->sortBy([
            fn ($a, $b) => $rank[$this->state($a)] <=> $rank[$this->state($b)],
            fn ($a, $b) => ($this->startsOn($b)?->timestamp ?? 0) <=> ($this->startsOn($a)?->timestamp ?? 0),
        ])->values();
    }

    public function startsOn(OwnerContract $contract): ?CarbonImmutable
    {
        $first = $contract->lines->min('valid_from');

        return $first === null ? null : CarbonImmutable::parse($first)->startOfDay();
    }

    /** Последният ден: денят преди прекратяването или преди края на последния имот („до“ е без него); null — без срок. */
    public function endsOn(OwnerContract $contract): ?CarbonImmutable
    {
        if ($contract->terminated_on !== null) {
            return CarbonImmutable::parse($contract->terminated_on)->subDay()->startOfDay();
        }
        if ($contract->lines->isEmpty() || $contract->lines->contains(fn ($line) => $line->valid_to === null)) {
            return null;
        }

        return CarbonImmutable::parse($contract->lines->max('valid_to'))->subDay()->startOfDay();
    }

    /** @return 'active'|'upcoming'|'ended' */
    public function state(OwnerContract $contract): string
    {
        $today = CarbonImmutable::today();
        $start = $this->startsOn($contract);
        $end = $this->endsOn($contract);

        return match (true) {
            $start !== null && $start->gt($today) => 'upcoming',
            $start === null || ($end !== null && $end->lt($today)) => 'ended',
            default => 'active',
        };
    }

    /**
     * Имотите по договора: при действащ — тези, които са в договора днес, с
     * „Под наем“ или „Свободен“; иначе всички, без състояние. Наемателят,
     * наемът и комисионата — според типа.
     *
     * @return list<array{property: \App\Models\Property, rented: ?bool, tenant: ?string, rent: ?string, commission: ?string}>
     */
    public function properties(OwnerContract $contract): array
    {
        $today = CarbonImmutable::today();
        $active = $this->state($contract) === 'active';
        $lines = $active ? $contract->lines->filter(fn ($line) => $this->validOn($line, $today)) : $contract->lines;
        $properties = $lines->pluck('property')->filter()->unique('id')->sortBy('number')->values();
        $leases = $active ? $this->leaseLinesOn($contract->company_id, $properties->pluck('id'), $today) : collect();
        $showTenant = in_array($contract->type, self::SHOWS_TENANT, true);
        $showMoney = $contract->type === OwnerContractType::RepresentativePercent;
        $commission = $showMoney ? $contract->commissions->first(fn ($c) => $this->validOn($c, $today))?->percent : null;

        return $properties->map(function ($property) use ($active, $leases, $showTenant, $showMoney, $commission) {
            $lease = $leases->get($property->id);

            return [
                'property' => $property,
                'rented' => $active ? $lease !== null : null,
                'tenant' => $showTenant ? $lease?->tenant : null,
                'rent' => $showMoney && $lease !== null ? Cents::format(Cents::from((string) $lease->rent)) : null,
                'commission' => $showMoney && $lease !== null ? $commission : null,
            ];
        })->all();
    }

    /**
     * По месеци: начислено по 408 (без изплащанията), изплатено (най-старите
     * месеци първи, както при изплащанията) и остатък; и общо.
     *
     * @return array{months: list<array{month: CarbonImmutable, accrued: string, paid: string, left: string}>, accrued: string, paid: string, left: string}
     */
    public function months(OwnerContract $contract): array
    {
        $state = $this->allocation->forContract((int) $contract->company_id, $contract->id);
        $accrued = DB::table('ledger_entries')->where('company_id', $contract->company_id)->where('owner_contract_id', $contract->id)
            ->where('account', LedgerAccount::Owner->value)->whereNotIn('kind', [LedgerKind::Payout->value, LedgerKind::PayoutReversal->value])
            ->groupBy('month')->orderBy('month')->selectRaw('month, SUM(amount) as total')->pluck('total', 'month');
        $paid = [];
        foreach ($state['debts'] as $debt) {
            $paid[$debt['month']] = ($paid[$debt['month']] ?? 0) + $debt['paid'];
        }

        $months = [];
        foreach ($accrued as $month => $total) {
            $cents = Cents::from((string) $total);
            $paidCents = $paid[$month] ?? 0;
            $months[] = ['month' => CarbonImmutable::parse($month)->startOfMonth(), 'accrued' => Cents::format($cents),
                'paid' => Cents::format($paidCents), 'left' => Cents::format($cents - $paidCents)];
        }

        return ['months' => $months, 'accrued' => Cents::format($state['accrued']), 'paid' => Cents::format($state['paid_out']),
            'left' => Cents::format($state['accrued'] - $state['paid_out'])];
    }

    /** @return array{method: ?string, name: ?string, iban: ?string} начинът на изплащане, получателят (бенефициентът) и IBAN само като „•••• 1234“ */
    public function payee(OwnerContract $contract): array
    {
        $today = CarbonImmutable::today();
        $payee = $contract->payees->first(fn ($p) => $this->validOn($p, $today)) ?? $contract->payees->sortBy('valid_from')->last();
        $beneficiary = $payee?->beneficiary_id === null ? null : Beneficiary::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->where('company_id', $contract->company_id)->whereKey($payee->beneficiary_id)->first();
        $iban = null;
        if ($payee?->payment_method?->value === 'bank' && $beneficiary !== null) {
            $raw = $beneficiary->getRawOriginal('iban');
            $iban = $raw === null || $raw === '' ? null : '•••• '.substr(Crypt::decryptString($raw), -4);
        }

        return ['method' => $payee?->payment_method?->value, 'name' => $beneficiary?->name, 'iban' => $iban];
    }

    private function validOn($version, CarbonImmutable $day): bool
    {
        return $version->valid_from->lte($day) && ($version->valid_to === null || $version->valid_to->gt($day));
    }

    /** @return Collection<int, object{property_id: int, rent: string, tenant: string}> действащите днес редове на неархивирани договори за наем, по имот */
    private function leaseLinesOn(int $companyId, Collection $propertyIds, CarbonImmutable $day): Collection
    {
        $date = $day->toDateString();

        return DB::table('lease_contract_lines as l')
            ->join('lease_contracts as c', fn ($j) => $j->on('c.id', '=', 'l.lease_contract_id')->on('c.company_id', '=', 'l.company_id'))
            ->join('tenants as t', fn ($j) => $j->on('t.id', '=', 'c.tenant_id')->on('t.company_id', '=', 'c.company_id'))
            ->where('l.company_id', $companyId)->whereIn('l.property_id', $propertyIds)->whereNull('c.archived_at')
            ->where(fn ($q) => $q->whereNull('c.terminated_on')->orWhere('c.terminated_on', '>', $date))
            ->where('l.valid_from', '<=', $date)->where(fn ($q) => $q->whereNull('l.valid_to')->orWhere('l.valid_to', '>', $date))
            ->orderBy('l.valid_from')->get(['l.property_id', 'l.rent', 't.name as tenant'])->keyBy('property_id');
    }
}
