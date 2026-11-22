<?php

namespace App\Services\Charges;

use App\Enums\CalculationMethod;
use App\Enums\ChargeType;
use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Models\ChargeDraft;
use App\Models\ChargeMonth;
use App\Models\LeaseContract;
use App\Models\PeriodLock;
use App\Models\Scopes\CompanyScope;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Models\UpcomingCharge;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Издаване (PROJECT.md, т. 2 „Издаване и заключване“, „Нов договор от
 * средата на месеца“).
 *
 * „Издай месеца“: от работната таблица — известие за всеки договор за наем
 * (без вече издадените с „Издай сега“ и без тези „за решение“), записи в
 * книгата (418 наемател, 491 депозит, 408 собственик) и заключен месец.
 * „Издай сега“: само един договор за един месец (и в издаден месец, за
 * пропуснат договор). И двете са всичко или нищо; фирмата се заключва за
 * времето на издаването, затова двойно натискане не дава двойни записи.
 * Редовете със сума 0 са в известието, но не и в книгата.
 */
class Issuer
{
    public const MISSED = 'missed_issue_now';

    private const LOOK_BACK = 12;

    public function __construct(
        private readonly ChargeCalculator $calculator,
        private readonly Reconciliation $reconciliation,
    ) {}

    public function isLocked(string $month): bool
    {
        return PeriodLock::isLocked(Month::parse($month));
    }

    /**
     * Какво ще се издаде с „Издай месеца“.
     *
     * @return array{tenants: Collection, owners: Collection, issued: list<int>}
     */
    public function preview(string $month): array
    {
        $start = Month::parse($month);
        $rows = $this->drafts($start)->get();
        $issued = $this->reconciliation->issuedLeases($start);

        return [
            'tenants' => $rows->filter(fn ($row) => $row->type->forTenant() && ! in_array($row->lease_contract_id, $issued, true))
                ->groupBy('lease_contract_id'),
            'owners' => $rows->where('type', ChargeType::Owner),
            'issued' => $issued,
        ];
    }

    /** @return Collection<int, Statement> */
    public function issueMonth(string $month): Collection
    {
        $start = Month::parse($month);

        return DB::transaction(function () use ($start) {
            Ledger::lockCompany($this->companyId());

            if (PeriodLock::isLocked($start)) {
                $this->reject('already_issued');
            }
            if (! ChargeMonth::query()->whereDate('month', $start)->exists()) {
                $this->reject('not_generated');
            }

            $rows = $this->drafts($start)->lockForUpdate()->get();
            $this->ensureNoUtilities($rows);
            $issued = $this->reconciliation->issuedLeases($start);
            $statements = collect();

            foreach ($rows->filter(fn ($row) => $row->type->forTenant() && ! in_array($row->lease_contract_id, $issued, true))
                ->groupBy('lease_contract_id') as $leaseId => $group) {
                $lease = LeaseContract::query()->withoutGlobalScopes()->findOrFail($leaseId);
                $statements->push($this->statement($start, $lease, $group->map(fn (ChargeDraft $row) => $this->line($row))->all()));
                foreach ($group as $row) {
                    $row->forceFill(['statement_id' => $statements->last()->id])->save();
                }
            }

            // Към собственика: сумата от работната таблица минус записаното вече (от „Издай сега“).
            $booked = $this->reconciliation->booked($start);
            foreach ($rows->where('type', ChargeType::Owner) as $row) {
                Ledger::book($start, LedgerKind::Issue, [
                    'account' => LedgerAccount::Owner, 'type' => ChargeType::Owner,
                    'owner_contract_id' => $row->owner_contract_id, 'owner_id' => $row->owner_id, 'property_id' => $row->property_id,
                ], Cents::from($row->amount) - ($booked['408:'.$row->owner_contract_id.':'.$row->property_id] ?? 0));
            }

            $lock = new PeriodLock;
            $lock->forceFill(['month' => $start, 'locked_at' => now(), 'locked_by' => Auth::id()])->save();

            return $statements;
        });
    }

    /**
     * Какво ще се издаде с „Издай сега“: редовете към наемателя и дяловете
     * към собственика (само при процент; фиксираните са с месеца).
     *
     * @return array{rows: list<array>, owners: list<array>, drafts: Collection}
     */
    public function previewNow(LeaseContract $lease, string $month): array
    {
        $start = Month::parse($month);
        $drafts = ! PeriodLock::isLocked($start) && ChargeMonth::query()->whereDate('month', $start)->exists()
            ? $this->drafts($start)->where('lease_contract_id', $lease->id)->whereNull('statement_id')->get()->filter(fn ($row) => $row->type->forTenant())
            : collect();

        if ($drafts->isNotEmpty()) {
            $rows = $drafts->map(fn (ChargeDraft $row) => $this->line($row))->values()->all();
        } else {
            $rows = [];
            foreach ($this->calculator->forMonth($this->companyId(), Month::key($start), [], [$lease->id])->charges() as $charge) {
                if ($charge->type === ProposedCharge::RENT) {
                    $rows[] = ['type' => ChargeType::Rent, 'property_id' => $charge->propertyId, 'amount' => $charge->amount,
                        'days' => $charge->days, 'note' => null];
                }
            }
            foreach (UpcomingCharge::query()->withoutGlobalScope(RegistryVisibilityScope::class)->where('lease_contract_id', $lease->id)
                ->whereDate('month', $start)->whereNull('cancelled_at')->orderBy('id')->get() as $upcoming) {
                $rows[] = ['type' => $upcoming->type, 'property_id' => $upcoming->property_id, 'amount' => $upcoming->amount,
                    'days' => null, 'note' => $upcoming->note];
            }
        }

        // Ръчно сменен наем в работната таблица: дялът на собственика е от него.
        $overrides = [];
        foreach ($drafts->where('type', ChargeType::Rent)->groupBy('property_id') as $propertyId => $group) {
            if ($group->contains(fn (ChargeDraft $row) => $row->isManual())) {
                $overrides[$lease->id.':'.$propertyId] = Cents::format($group->whereNull('removed_at')->sum(fn ($row) => Cents::from($row->amount)));
            }
        }

        $properties = array_unique(array_map(fn ($row) => $row['property_id'], $rows));
        $booked = $this->reconciliation->booked($start);
        $owners = [];
        $leases = array_values(array_unique(array_merge($this->reconciliation->issuedLeases($start), [$lease->id])));
        foreach ($this->reconciliation->ownerTargets($start, $leases, false, $overrides) as $key => $target) {
            if ($target['calculation'] === CalculationMethod::Percent->value && in_array($target['property'], $properties, true)) {
                $cents = $target['cents'] - ($booked['408:'.$key] ?? 0);
                if ($cents !== 0) {
                    $owners[] = $target + ['change' => $cents];
                }
            }
        }

        return ['rows' => $rows, 'owners' => $owners, 'drafts' => $drafts];
    }

    public function issueNow(LeaseContract $lease, string $month): Statement
    {
        $start = Month::parse($month);

        return DB::transaction(function () use ($lease, $start) {
            Ledger::lockCompany($this->companyId());

            if (Statement::query()->withoutGlobalScope(RegistryVisibilityScope::class)
                ->where('lease_contract_id', $lease->id)->whereDate('month', $start)->exists()) {
                $this->reject('already_issued_contract');
            }

            $forDecision = collect($this->calculator->forMonth($this->companyId(), Month::key($start), [], [$lease->id])->forDecision());
            if ($forDecision->isNotEmpty()) {
                $this->reject('issue_for_decision');
            }

            $preview = $this->previewNow($lease, Month::key($start));
            $this->ensureNoUtilities($preview['drafts']);
            if ($preview['rows'] === []) {
                $this->reject('nothing_to_issue');
            }

            $statement = $this->statement($start, $lease, $preview['rows']);
            foreach ($preview['drafts'] as $row) {
                $row->forceFill(['statement_id' => $statement->id])->save();
            }

            foreach ($preview['owners'] as $owner) {
                Ledger::book($start, LedgerKind::Issue, [
                    'account' => LedgerAccount::Owner, 'type' => ChargeType::Owner,
                    'owner_contract_id' => $owner['contract'], 'owner_id' => $owner['owner'], 'property_id' => $owner['property'],
                ], $owner['change']);
            }

            return $statement;
        });
    }

    /**
     * Пропуснато „Издай сега“: в издаден месец преди М има наем по договор,
     * за който няма известие (например договор, въведен след издаването).
     */
    public function warnings(string $month): array
    {
        $start = Month::parse($month);
        $warnings = [];

        $locked = PeriodLock::query()->whereDate('month', '<', $start)
            ->whereDate('month', '>=', $start->subMonths(self::LOOK_BACK))->orderBy('month')->pluck('month');

        foreach ($locked as $issuedMonth) {
            $issuedMonth = CarbonImmutable::parse($issuedMonth);
            $issued = $this->reconciliation->issuedLeases($issuedMonth);

            $missing = [];
            foreach ($this->calculator->forMonth($this->companyId(), Month::key($issuedMonth))->charges() as $charge) {
                if ($charge->type === ProposedCharge::RENT && ! in_array($charge->leaseContractId, $issued, true)) {
                    $missing[$charge->leaseContractId] = true;
                }
            }

            foreach (array_keys($missing) as $leaseId) {
                $lease = LeaseContract::query()->with('tenant')->find($leaseId);
                if ($lease === null) {
                    continue; // не се вижда от потребителя
                }
                $warnings[] = new WorksheetWarning(self::MISSED, __('charges.warnings.missed_issue_now', [
                    'contract' => $lease->number, 'tenant' => $lease->tenant?->name, 'month' => Month::label($issuedMonth),
                ]), null, $lease->id, null);
            }
        }

        return $warnings;
    }

    /** Ново известие с редовете и записите в книгата към наемателя. */
    private function statement(CarbonImmutable $start, LeaseContract $lease, array $rows): Statement
    {
        $number = (int) Statement::query()->withoutGlobalScopes()->where('company_id', $lease->company_id)->max('number') + 1;
        $total = array_sum(array_map(fn ($row) => Cents::from((string) $row['amount']), $rows));

        $statement = new Statement;
        $statement->forceFill([
            'number' => $number, 'month' => $start, 'lease_contract_id' => $lease->id, 'tenant_id' => $lease->tenant_id,
            'total' => Cents::format($total), 'issued_at' => now(), 'issued_by' => Auth::id(),
        ])->save();

        foreach ($rows as $row) {
            $line = new StatementLine;
            $line->forceFill([
                'statement_id' => $statement->id, 'property_id' => $row['property_id'], 'type' => $row['type'],
                'amount' => $row['amount'], 'days' => $row['days'], 'note' => $row['note'],
            ])->save();

            $attributes = ['type' => $row['type'], 'lease_contract_id' => $lease->id, 'tenant_id' => $lease->tenant_id,
                'property_id' => $row['property_id'], 'statement_id' => $statement->id];
            $cents = Cents::from((string) $row['amount']);

            Ledger::book($start, LedgerKind::Issue, $attributes + ['account' => LedgerAccount::Tenant], $cents);
            if ($row['type'] === ChargeType::Deposit) {
                Ledger::book($start, LedgerKind::Issue, $attributes + ['account' => LedgerAccount::Deposit], $cents);
            }
        }

        return $statement;
    }

    private function line(ChargeDraft $row): array
    {
        return ['type' => $row->type, 'property_id' => $row->property_id, 'amount' => $row->amount, 'days' => $row->days, 'note' => $row->note];
    }

    /** Редовете на месеца без махнатите (за целия месец, без филтъра „само прикачените“). */
    private function drafts(CarbonImmutable $start)
    {
        return ChargeDraft::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->whereDate('month', $start)->whereNull('removed_at')->orderBy('id');
    }

    /**
     * Временно, до част 4б (издаване и книга за битовите сметки): месец или
     * договор с битови редове още не се издава, за да няма 418 без 407.
     */
    private function ensureNoUtilities(Collection $rows): void
    {
        if ($rows->contains(fn (ChargeDraft $row) => $row->isUtility())) {
            $this->reject('utilities_next_step');
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['month' => __('charges.messages.'.$message)]);
    }

    private function companyId(): int
    {
        return CompanyScope::restrictedCompanyId()
            ?? throw new LogicException('Издаването иска влязъл служител на фирма.');
    }
}
