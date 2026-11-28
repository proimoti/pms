<?php

namespace App\Services\Portal;

use App\Enums\LedgerAccount;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\DepositClosing;
use App\Models\LeaseContract;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Models\Statement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Charges\Cents;
use App\Services\Payments\Allocation;
use App\Services\Payments\DepositClosingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Данните за портала на наемателя (план „Портал и служители“, 27.11.2026).
 * Регистърът е скрит за клиентите, затова всичко се чете изрично: само
 * наемателите, свързани с профила (и неархивирани), във фирмата на профила
 * (филтърът по фирма остава). Само чете.
 */
final class TenantPortal
{
    public function __construct(
        private readonly Allocation $allocation,
        private readonly DepositClosingService $closings,
    ) {}

    /** @return Collection<int, Tenant> наемателите на профила, без архивираните */
    public function tenants(User $client): Collection
    {
        $ids = DB::table('portal_links')->where('company_id', $client->company_id)->where('user_id', $client->id)
            ->whereNotNull('tenant_id')->pluck('tenant_id');

        return Tenant::query()->withoutGlobalScope(RegistryVisibilityScope::class)->where('company_id', $client->company_id)
            ->whereIn('id', $ids)->whereNull('archived_at')->orderBy('name')->get();
    }

    /** Наемател на профила или null (чужд, архивиран, несъществуващ). */
    public function tenant(User $client, int $id): ?Tenant
    {
        return $this->tenants($client)->firstWhere('id', $id);
    }

    /** @return Collection<int, LeaseContract> договорите (и приключилите), без архивираните; действащите първи */
    public function leases(Tenant $tenant): Collection
    {
        $any = fn ($q) => $q->withoutGlobalScope(RegistryVisibilityScope::class);
        $leases = LeaseContract::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->with(['lines' => fn ($q) => $any($q)->orderBy('valid_from')->orderBy('id'), 'lines.property' => $any])
            ->where('company_id', $tenant->company_id)->where('tenant_id', $tenant->id)->whereNull('archived_at')->get();

        $rank = ['active' => 0, 'upcoming' => 1, 'ended' => 2];

        return $leases->sortBy([
            fn ($a, $b) => $rank[$this->state($a)] <=> $rank[$this->state($b)],
            fn ($a, $b) => ($this->startsOn($b)?->timestamp ?? 0) <=> ($this->startsOn($a)?->timestamp ?? 0),
        ])->values();
    }

    public function startsOn(LeaseContract $lease): ?CarbonImmutable
    {
        $first = $lease->lines->min('valid_from');

        return $first === null ? null : CarbonImmutable::parse($first)->startOfDay();
    }

    /** Последният ден: денят преди прекратяването или преди края на последния имот („до“ е без него); null — без срок. */
    public function endsOn(LeaseContract $lease): ?CarbonImmutable
    {
        if ($lease->terminated_on !== null) {
            return CarbonImmutable::parse($lease->terminated_on)->subDay()->startOfDay();
        }
        if ($lease->lines->isEmpty() || $lease->lines->contains(fn ($line) => $line->valid_to === null)) {
            return null;
        }

        return CarbonImmutable::parse($lease->lines->max('valid_to'))->subDay()->startOfDay();
    }

    /** @return 'active'|'upcoming'|'ended' */
    public function state(LeaseContract $lease): string
    {
        $today = CarbonImmutable::today();
        $start = $this->startsOn($lease);
        $end = $this->endsOn($lease);

        return match (true) {
            $start !== null && $start->gt($today) => 'upcoming',
            $start === null || ($end !== null && $end->lt($today)) => 'ended',
            default => 'active',
        };
    }

    /** @return Collection<int, \App\Models\Property> имотите на договора, по номер */
    public function properties(LeaseContract $lease): Collection
    {
        return $lease->lines->pluck('property')->filter()->unique('id')->sortBy('number')->values();
    }

    /** @return array{total: string, contracts: array<int, string>} дължи по 418 (минус = надплатено) */
    public function owes(Tenant $tenant): array
    {
        $contracts = LedgerEntry::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->where('company_id', $tenant->company_id)->where('account', LedgerAccount::Tenant->value)->where('tenant_id', $tenant->id)
            ->groupBy('lease_contract_id')->selectRaw('lease_contract_id, SUM(amount) as total')->pluck('total', 'lease_contract_id')
            ->mapWithKeys(fn ($total, $id) => [(int) $id => Cents::format(Cents::from((string) $total))])->all();

        return ['total' => Cents::format(array_sum(array_map(fn ($v) => Cents::from($v), $contracts))), 'contracts' => $contracts];
    }

    /** @return Collection<int, Statement> най-новите първи */
    public function statements(Tenant $tenant): Collection
    {
        return Statement::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->where('company_id', $tenant->company_id)->where('tenant_id', $tenant->id)
            ->orderByDesc('month')->orderByDesc('number')->get();
    }

    /** Известие на профила (за PDF) или null. */
    public function statement(User $client, int $id): ?Statement
    {
        $statement = Statement::query()->withoutGlobalScope(RegistryVisibilityScope::class)->where('company_id', $client->company_id)->find($id);
        if ($statement === null || $this->tenant($client, (int) $statement->tenant_id) === null) {
            return null;
        }
        // Връзките на известието за PDF, без филтъра на регистъра.
        $any = fn ($q) => $q->withoutGlobalScope(RegistryVisibilityScope::class);

        return $statement->load(['leaseContract' => $any, 'lines' => $any, 'lines.property' => $any,
            'lines.paidUtilityBill' => $any, 'lines.utilityAccount' => $any, 'lines.utilityAccount.provider' => $any]);
    }

    /** @return Collection<int, Payment> без сторнираните и без самите сторна; най-новите първи */
    public function payments(Tenant $tenant): Collection
    {
        return Payment::query()->where('company_id', $tenant->company_id)->where('tenant_id', $tenant->id)
            ->whereNull('reversal_of_id')->whereDoesntHave('reversal')
            ->orderByDesc('paid_on')->orderByDesc('id')->get();
    }

    /**
     * Депозитът по договор: договорен (сегашните и предстоящите имоти), внесен
     * (по разнасянето), държан (491) и приключванията без сторнираните.
     *
     * @return array<int, array{contracted: int, paid: int, held: int, closings: Collection}>
     */
    public function deposits(Collection $leases): array
    {
        $result = [];
        foreach ($leases as $lease) {
            $latest = $lease->lines->filter(fn ($line) => $line->valid_to === null || $line->valid_to->isFuture())
                ->groupBy('property_id')->map(fn ($versions) => $versions->sortBy('valid_from')->last());
            $closings = DepositClosing::query()
                ->with(['entries' => fn ($q) => $q->withoutGlobalScope(RegistryVisibilityScope::class)->where('account', LedgerAccount::Deposit->value)])
                ->where('company_id', $lease->company_id)->where('lease_contract_id', $lease->id)
                ->whereNull('reversal_of_id')->whereDoesntHave('reversal')
                ->orderByDesc('closed_on')->orderByDesc('id')->get();
            $result[$lease->id] = [
                'contracted' => $latest->sum(fn ($line) => Cents::from((string) ($line->deposit ?? '0'))),
                'paid' => $this->allocation->depositPaid((int) $lease->company_id, (int) $lease->id),
                'held' => array_sum($this->closings->held($lease)),
                'closings' => $closings,
            ];
        }

        return array_filter($result, fn ($d) => $d['contracted'] !== 0 || $d['paid'] !== 0 || $d['held'] !== 0 || $d['closings']->isNotEmpty());
    }

    /** Банковата сметка „за известията“ на фирмата или null. */
    public function bank(User $client): ?BankAccount
    {
        return Company::query()->withoutGlobalScopes()->find($client->company_id)?->statementAccount();
    }
}
