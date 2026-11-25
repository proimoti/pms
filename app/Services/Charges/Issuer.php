<?php

namespace App\Services\Charges;

use App\Enums\CalculationMethod;
use App\Enums\ChargeType;
use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Models\ChargeDraft;
use App\Models\ChargeMonth;
use App\Models\LeaseContract;
use App\Models\LedgerEntry;
use App\Models\PaidUtilityBill;
use App\Models\Property;
use App\Models\PeriodLock;
use App\Models\Scopes\CompanyScope;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Models\UpcomingCharge;
use App\Models\UtilityAccount;
use App\Enums\ChargeSource;
use App\Services\Utilities\FundLedger;
use App\Services\Utilities\UtilityMonth;
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
 *
 * Битовите сметки (PROJECT.md, „Битови сметки в месеца“, „Общ фонд“; част 4б):
 * - битов ред: 418 + към наемателя (договор, имот, известие, платена сметка)
 *   и 407 − за същата сметка и имот (407 е по сметка и имот, без договор);
 * - фиксирана такса: 418 + към наемателя и приход във фонда (407 − на фонда),
 *   с връзка към известието;
 * - битов ред със сума 0 не е в известието и не е в книгата; намаленият ред
 *   оставя разликата неначислена (407 на имота не е 0, за част 5);
 * - преди записа: сметката не е начислена вече на същия договор и имот и
 *   общо не се начислява повече от платеното; фиксираната такса за същия
 *   месец не е издадена вече на договора и имота;
 * - „Издай сега“ в неиздаден месец взима и битовите редове: от работната
 *   таблица, а ако месецът още не е генериран — пресмята дяловете на договора
 *   само за него (при генерирането те се броят за начислени, останалите
 *   дялове на сметката отиват при другите наематели); в издаден месец е без
 *   тях — отиват в следващото генериране;
 * - след издаването дяловете не се преизчисляват (поправката е ръчна корекция).
 */
class Issuer
{
    public const MISSED = 'missed_issue_now';

    private const LOOK_BACK = 12;

    public function __construct(
        private readonly ChargeCalculator $calculator,
        private readonly Reconciliation $reconciliation,
        private readonly FundLedger $funds,
        private readonly UtilityMonth $utilities,
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
            $issued = $this->reconciliation->issuedLeases($start);
            $statements = collect();
            $toTenants = $rows->filter(fn ($row) => $row->type->forTenant() && ! in_array($row->lease_contract_id, $issued, true));
            $this->ensureNotCharged($toTenants);

            foreach ($toTenants->groupBy('lease_contract_id') as $leaseId => $group) {
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
     * @return array{rows: list<array>, owners: list<array>, drafts: Collection, computed: Collection}
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
            // Неиздаден и още негенериран месец: битовите дялове на договора с калкулатора, само за него
            // (план за част 4, т. 5, 23.11.2026). При генерирането те се броят за начислени.
            if (! PeriodLock::isLocked($start) && ! ChargeMonth::query()->whereDate('month', $start)->exists()) {
                $computed = $this->computedUtilities($lease, $start);
                foreach ($computed as $row) {
                    $rows[] = $this->line($row);
                }
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

        return ['rows' => $rows, 'owners' => $owners, 'drafts' => $drafts, 'computed' => $computed ?? collect()];
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
            $this->ensureNotCharged($preview['drafts']->concat($preview['computed']));
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
            // Данните на фирмата, наемателя и сметката към момента (PROJECT.md, 24.11.2026).
            'snapshot' => app(\App\Services\Statements\StatementSnapshot::class)->build($this->companyId(), $lease->tenant_id)
                + ['due_on' => \App\Services\Statements\StatementSnapshot::dueOn($start, now())],
        ])->save();

        foreach ($rows as $row) {
            $cents = Cents::from((string) $row['amount']);
            $utility = in_array($row['type'], [ChargeType::Utility, ChargeType::FixedFee], true);
            if ($utility && $cents === 0) {
                continue; // битов ред със сума 0: не е в известието и не е в книгата
            }

            $line = new StatementLine;
            $line->forceFill([
                'statement_id' => $statement->id, 'property_id' => $row['property_id'], 'type' => $row['type'],
                'amount' => $row['amount'], 'days' => $row['days'], 'note' => $row['note'],
                'paid_utility_bill_id' => $row['bill'] ?? null, 'utility_account_id' => $row['account'] ?? null,
                'period_from' => $row['from'] ?? null, 'period_to' => $row['to'] ?? null,
            ])->save();

            $attributes = ['type' => $row['type'], 'lease_contract_id' => $lease->id, 'tenant_id' => $lease->tenant_id,
                'property_id' => $row['property_id'], 'statement_id' => $statement->id];
            if ($row['type'] === ChargeType::Utility) {
                $attributes['paid_utility_bill_id'] = $row['bill'];
            }

            Ledger::book($start, LedgerKind::Issue, $attributes + ['account' => LedgerAccount::Tenant], $cents);
            if ($row['type'] === ChargeType::Deposit) {
                Ledger::book($start, LedgerKind::Issue, $attributes + ['account' => LedgerAccount::Deposit], $cents);
            }
            if ($row['type'] === ChargeType::Utility) {
                // 407 − за същата сметка и имот: начислено на наемателя.
                Ledger::book($start, LedgerKind::Issue, [
                    'account' => LedgerAccount::Utility, 'type' => ChargeType::Utility, 'property_id' => $row['property_id'],
                    'paid_utility_bill_id' => $row['bill'], 'statement_id' => $statement->id,
                ], -$cents);
            }
            if ($row['type'] === ChargeType::FixedFee) {
                $account = UtilityAccount::query()->withoutGlobalScope(RegistryVisibilityScope::class)->findOrFail($row['account']);
                $this->funds->income($account, $start, $cents, $statement->id);
            }
        }

        return $statement;
    }

    private function line(ChargeDraft $row): array
    {
        return ['type' => $row->type, 'property_id' => $row->property_id, 'amount' => $row->amount, 'days' => $row->days, 'note' => $row->note,
            'bill' => $row->paid_utility_bill_id, 'account' => $row->utility_account_id,
            'from' => $row->period_from, 'to' => $row->period_to, 'draft' => $row];
    }

    /** Редовете на месеца без махнатите (за целия месец, без филтъра „само прикачените“). */
    private function drafts(CarbonImmutable $start)
    {
        return ChargeDraft::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->whereDate('month', $start)->whereNull('removed_at')->orderBy('id');
    }

    /**
     * Защита от двойно начисление на битовите сметки (част 4б), преди записа:
     * - сметката не е начислена вече на същия договор и имот (418 с нея);
     * - общо за сметката и имота не се начислява повече от платеното (407 на
     *   имота не минава под 0);
     * - фиксираната такса за същия месец не е издадена вече на договора и имота.
     *
     * @param  Collection<int, ChargeDraft>  $rows
     */
    private function ensureNotCharged(Collection $rows): void
    {
        $charged = $rows->filter(fn (ChargeDraft $row) => $row->isUtility() && Cents::from($row->amount) > 0);
        $bills = $charged->where('type', ChargeType::Utility);

        foreach ($bills as $row) {
            $twice = LedgerEntry::query()->withoutGlobalScope(RegistryVisibilityScope::class)
                ->where('account', LedgerAccount::Tenant->value)->where('type', ChargeType::Utility->value)
                ->where('paid_utility_bill_id', $row->paid_utility_bill_id)->where('lease_contract_id', $row->lease_contract_id)
                ->where('property_id', $row->property_id)->exists();
            if ($twice) {
                $this->reject('utility_already_charged', $this->billLabel($row));
            }
        }

        foreach ($bills->groupBy(fn (ChargeDraft $row) => $row->paid_utility_bill_id.':'.$row->property_id) as $group) {
            $row = $group->first();
            $left = Cents::from((string) (LedgerEntry::query()->withoutGlobalScope(RegistryVisibilityScope::class)
                ->where('account', LedgerAccount::Utility->value)->whereNull('fund_id')
                ->where('paid_utility_bill_id', $row->paid_utility_bill_id)->where('property_id', $row->property_id)
                ->sum('amount') ?: '0'));
            if ($group->sum(fn (ChargeDraft $r) => Cents::from($r->amount)) > $left) {
                $this->reject('utility_over_bill', $this->billLabel($row) + ['left' => Cents::format(max(0, $left))]);
            }
        }

        foreach ($charged->where('type', ChargeType::FixedFee) as $row) {
            $twice = StatementLine::query()->withoutGlobalScope(RegistryVisibilityScope::class)
                ->where('type', ChargeType::FixedFee->value)->where('utility_account_id', $row->utility_account_id)
                ->where('property_id', $row->property_id)->whereDate('period_from', $row->period_from)
                ->whereIn('statement_id', fn ($q) => $q->select('id')->from('statements')->where('lease_contract_id', $row->lease_contract_id))
                ->exists();
            if ($twice) {
                $this->reject('fixed_fee_already_charged', [
                    'property' => Property::query()->withoutGlobalScopes()->whereKey($row->property_id)->value('number'),
                    'month' => Month::label(CarbonImmutable::parse($row->period_from->toDateString())),
                ]);
            }
        }
    }

    /**
     * Битовите дялове на един договор за месеца, пресметнати сега (без ред в
     * работната таблица): редове, които не се записват, а само се издават.
     *
     * @return Collection<int, ChargeDraft>
     */
    private function computedUtilities(LeaseContract $lease, CarbonImmutable $start): Collection
    {
        return collect($this->utilities->proposals($this->companyId(), $start)['rows'])
            ->filter(fn (array $proposal) => $proposal['lease'] === (int) $lease->id)
            ->map(fn (array $proposal) => (new ChargeDraft)->forceFill([
                'company_id' => $lease->company_id, 'month' => $start, 'type' => $proposal['type'], 'source' => ChargeSource::Computed,
                'lease_contract_id' => $proposal['lease'], 'tenant_id' => $proposal['tenant'], 'property_id' => $proposal['property'],
                'paid_utility_bill_id' => $proposal['bill'], 'utility_account_id' => $proposal['account'],
                'period_from' => $proposal['from'], 'period_to' => $proposal['to'],
                'days' => $proposal['days'], 'amount' => $proposal['amount'], 'proposed_amount' => $proposal['amount'],
            ]))
            ->values();
    }

    /** За съобщенията: номер и доставчик на сметката, имот. */
    private function billLabel(ChargeDraft $row): array
    {
        $bill = PaidUtilityBill::query()->withoutGlobalScopes()->whereKey($row->paid_utility_bill_id)->first();

        return [
            'number' => $bill?->file_number, 'provider' => $bill?->provider_name,
            'property' => Property::query()->withoutGlobalScopes()->whereKey($row->property_id)->value('number'),
        ];
    }

    private function reject(string $message, array $replace = []): never
    {
        throw ValidationException::withMessages(['month' => __('charges.messages.'.$message, $replace)]);
    }

    private function companyId(): int
    {
        return CompanyScope::restrictedCompanyId()
            ?? throw new LogicException('Издаването иска влязъл служител на фирма.');
    }
}
