<?php

namespace App\Services\Charges;

use App\Enums\ChargeSource;
use App\Enums\ChargeType;
use App\Models\AuditLog;
use App\Models\ChargeDraft;
use App\Models\ChargeMonth;
use App\Models\LeaseContract;
use App\Models\Property;
use App\Models\Scopes\CompanyScope;
use App\Models\UpcomingCharge;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Работната таблица на месеца (PROJECT.md, т. 2 „Работна таблица на месеца“,
 * „Начисления“, „Начисления, уточнения“).
 *
 * „Генерирай месеца“ (и „Генерирай отново“): пресметнатите редове се
 * пресмятат наново; ръчно променените и добавените се пазят (с предупреждение,
 * ако пресметнатото се е сменило); ръчно махнатите се помнят и не се връщат;
 * предстоящите начисления на месеца влизат. Всяка ръчна промяна или махане
 * иска причина. Книгата не се пипа (тя идва с част 3).
 */
class MonthWorksheet
{
    public const AGREED_FIRST_RENT = 'agreed_first_rent';

    public const FOR_DECISION = 'for_decision';

    public const EDITED_CHANGED = 'edited_changed';

    public const NO_LONGER_COMPUTED = 'no_longer_computed';

    public const DEPOSIT_OVER = 'deposit_over';

    public const DEPOSIT_MISSING = 'deposit_missing';

    public const UPCOMING_INACTIVE = 'upcoming_inactive';

    public function __construct(
        private readonly ChargeCalculator $calculator,
        private readonly UpcomingChargeService $upcoming,
    ) {}

    public function generate(string $month): ChargeMonth
    {
        $start = Month::parse($month);
        $companyId = $this->companyId();

        return DB::transaction(function () use ($start, $companyId) {
            $stamp = ChargeMonth::query()->whereDate('month', $start)->lockForUpdate()->first() ?? new ChargeMonth;
            $rows = $this->rowsQuery($start)->lockForUpdate()->get();
            $before = $rows->whereNull('removed_at')->count();

            $rents = array_filter($this->calculator->forMonth($companyId, Month::key($start))->charges(),
                fn (ProposedCharge $charge) => $charge->type === ProposedCharge::RENT);
            $this->applyComputed($start, $rows->where('source', ChargeSource::Computed)->where('type', ChargeType::Rent), $rents);
            $this->applyUpcoming($start, $rows->where('source', ChargeSource::Upcoming));
            $this->refreshOwners($start);

            $stamp->forceFill(['month' => $start, 'generated_at' => now(), 'generated_by' => Auth::id()])->save();
            $this->journal($stamp, $start, $before);

            return $stamp;
        });
    }

    /**
     * Редовете към собственика: при процент дялът е от наема, какъвто е в
     * работната таблица (ръчно променен, махнат, добавен; PROJECT.md, „Дял на
     * собственика“). При фиксирана сума наемът не влияе.
     */
    private function refreshOwners(CarbonImmutable $start): void
    {
        $rows = $this->rowsQuery($start)->get();
        $overrides = [];

        foreach ($rows->where('type', ChargeType::Rent)->groupBy(fn ($row) => $row->lease_contract_id.':'.$row->property_id) as $key => $group) {
            if ($group->contains(fn (ChargeDraft $row) => $row->isManual())) {
                $overrides[$key] = Cents::format($group->whereNull('removed_at')->sum(fn ($row) => Cents::from($row->amount)));
            }
        }

        $owners = array_filter($this->calculator->forMonth($this->companyId(), Month::key($start), $overrides)->charges(),
            fn (ProposedCharge $charge) => $charge->type === ProposedCharge::OWNER);
        $this->applyComputed($start, $rows->where('source', ChargeSource::Computed)->where('type', ChargeType::Owner), $owners);
    }

    /** Един запис в журнала за всяко генериране: месец, кой, кога, брой редове. */
    private function journal(ChargeMonth $stamp, CarbonImmutable $start, int $before): void
    {
        AuditLog::query()->make()->forceFill([
            'company_id' => $stamp->company_id,
            'user_id' => Auth::id(),
            'auditable_type' => $stamp->getTable(),
            'auditable_id' => $stamp->id,
            'event' => 'generated',
            'changes' => [
                'month' => ['old' => null, 'new' => Month::key($start)],
                'rows' => ['old' => $before, 'new' => $this->rowsQuery($start)->whereNull('removed_at')->count()],
            ],
        ])->save();
    }

    /** Всички редове на месеца (и махнатите), които потребителят вижда. */
    public function rows(string $month): Collection
    {
        return $this->rowsQuery(Month::parse($month))
            ->with(['leaseContract', 'ownerContract', 'property', 'tenant', 'owner'])
            ->get();
    }

    /** @param  array{type: string, lease_contract_id?: int, owner_contract_id?: int, property_id: int, amount: string, note?: ?string, reason: string}  $data */
    public function addManual(string $month, array $data): ChargeDraft
    {
        $input = new ChargeInput;
        $start = Month::tryParse($month);
        if ($start === null) {
            $input->fail('month', 'month_invalid');
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

        $amount = $input->amount($data['amount'] ?? null);
        $note = $input->note($data['note'] ?? null, required: $type?->needsNote() ?? false);
        $reason = $input->reason($data['reason'] ?? null);
        $input->check();

        $row = new ChargeDraft;
        $row->forceFill([
            'month' => $start,
            'type' => $type,
            'source' => ChargeSource::Manual,
            'lease_contract_id' => $lease?->id,
            'owner_contract_id' => $management?->id,
            'property_id' => (int) $propertyId,
            'tenant_id' => $lease?->tenant_id,
            'owner_id' => $management?->owner_id,
            'amount' => $amount,
            'note' => $note,
            'edited_at' => now(),
            'edited_by' => Auth::id(),
            'edit_reason' => $reason,
        ])->save();
        $this->afterRentChange($row);

        return $row;
    }

    /** Ръчна промяна на сумата (и бележката), с причина. */
    public function change(ChargeDraft $row, array $data): ChargeDraft
    {
        $input = new ChargeInput;
        $amount = $input->amount($data['amount'] ?? null);
        $note = array_key_exists('note', $data) ? $input->note($data['note'], required: $row->type->needsNote()) : $row->note;
        $reason = $input->reason($data['reason'] ?? null);
        $input->check();

        $row->forceFill([
            'amount' => $amount,
            'note' => $note,
            'edited_at' => now(),
            'edited_by' => Auth::id(),
            'edit_reason' => $reason,
            'edited_proposed_amount' => $row->proposed_amount,
        ])->save();
        $this->afterRentChange($row);

        return $row;
    }

    /** Махане с причина: редът остава махнат и „Генерирай отново“ не го връща. */
    public function remove(ChargeDraft $row, ?string $reason): ChargeDraft
    {
        $input = new ChargeInput;
        $reason = $input->reason($reason);
        $input->check();

        $row->forceFill(['removed_at' => now(), 'removed_by' => Auth::id(), 'remove_reason' => $reason])->save();
        $this->afterRentChange($row);

        return $row;
    }

    /** Връщане на махнат ред, с причина (остава отбелязан като ръчно пипан). */
    public function restore(ChargeDraft $row, ?string $reason): ChargeDraft
    {
        $input = new ChargeInput;
        $reason = $input->reason($reason);
        $input->check();

        $row->forceFill([
            'removed_at' => null, 'removed_by' => null, 'remove_reason' => null,
            'edited_at' => now(), 'edited_by' => Auth::id(), 'edit_reason' => $reason,
            'edited_proposed_amount' => $row->proposed_amount,
        ])->save();
        $this->afterRentChange($row);

        return $row;
    }

    /** При промяна на ред за наем дялът на собственика при процент се обновява веднага. */
    private function afterRentChange(ChargeDraft $row): void
    {
        // Само за генериран месец: иначе редовете към собственика още ги няма.
        if ($row->type === ChargeType::Rent && ChargeMonth::query()->whereDate('month', $row->month)->exists()) {
            DB::transaction(fn () => $this->refreshOwners($row->month));
        }
    }

    /** Наемите „за решение“ (ден без договор за управление), сметнати сега, за видимите имоти. */
    public function forDecision(string $month): array
    {
        $start = Month::parse($month);
        $visible = Property::query()->pluck('id')->flip();

        return array_values(array_filter(
            $this->calculator->forMonth($this->companyId(), Month::key($start))->forDecision(),
            fn (ChargeForDecision $item) => $visible->has($item->propertyId),
        ));
    }

    /** @return list<WorksheetWarning> */
    public function warnings(string $month): array
    {
        $start = Month::parse($month);
        $rows = $this->rowsQuery($start)->with(['leaseContract', 'property'])->get();
        $warnings = [];

        foreach ($rows->whereNull('removed_at') as $row) {
            $where = $this->where($row->leaseContract, $row->property);

            if ($row->type === ChargeType::Rent && $row->computed_amount !== null && $row->computed_amount !== $row->amount
                && $row->edited_at === null) {
                $warnings[] = new WorksheetWarning(self::AGREED_FIRST_RENT, __('charges.warnings.agreed_first_rent', $where + [
                    'agreed' => Format::money($row->amount), 'computed' => Format::money($row->computed_amount),
                ]), $row->id, $row->lease_contract_id, $row->property_id);
            }

            if ($row->source !== ChargeSource::Manual && $row->edited_at !== null) {
                if ($row->proposed_amount === null) {
                    $warnings[] = new WorksheetWarning(self::NO_LONGER_COMPUTED, __('charges.warnings.no_longer_computed', $this->where($row->leaseContract ?? $row->ownerContract, $row->property)),
                        $row->id, $row->lease_contract_id, $row->property_id);
                } elseif ($row->proposed_amount !== $row->edited_proposed_amount) {
                    $warnings[] = new WorksheetWarning(self::EDITED_CHANGED, __('charges.warnings.edited_changed', $this->where($row->leaseContract ?? $row->ownerContract, $row->property) + [
                        'amount' => Format::money($row->amount), 'proposed' => Format::money($row->proposed_amount),
                    ]), $row->id, $row->lease_contract_id, $row->property_id);
                }
            }
        }

        foreach ($this->forDecision(Month::key($start)) as $item) {
            $lease = LeaseContract::query()->with('tenant')->find($item->leaseContractId);
            $warnings[] = new WorksheetWarning(self::FOR_DECISION, __('charges.warnings.for_decision', $this->where($lease, Property::query()->find($item->propertyId)) + [
                'tenant' => $lease?->tenant?->name, 'days' => $item->daysWithoutManagement,
            ]), null, $item->leaseContractId, $item->propertyId);
        }

        return array_merge($warnings, $this->depositWarnings($start), $this->upcomingWarnings($start));
    }

    /** Вноските надхвърлят договорения депозит; нов договор с договорен депозит без въведен депозит. */
    private function depositWarnings(CarbonImmutable $start): array
    {
        $warnings = [];

        $pairs = UpcomingCharge::query()->whereDate('month', $start)->where('type', ChargeType::Deposit)->whereNull('cancelled_at')
            ->with(['leaseContract', 'property'])->get()->unique(fn ($u) => $u->lease_contract_id.'-'.$u->property_id);

        foreach ($pairs as $upcoming) {
            $summary = $this->upcoming->deposit($upcoming->leaseContract, $upcoming->property_id);
            if (Cents::from($summary['remaining']) < 0) {
                $warnings[] = new WorksheetWarning(self::DEPOSIT_OVER, __('charges.warnings.deposit_over', $this->where($upcoming->leaseContract, $upcoming->property) + [
                    'entered' => Format::money($summary['entered']), 'contracted' => Format::money($summary['contracted']),
                ]), null, $upcoming->lease_contract_id, $upcoming->property_id);
            }
        }

        // Първият ред на имота в договора започва този месец, с договорен депозит, а депозит не е въведен.
        $starting = DB::table('lease_contract_lines as l')
            ->join('lease_contracts as c', fn ($join) => $join->on('c.id', '=', 'l.lease_contract_id')->on('c.company_id', '=', 'l.company_id'))
            ->where('l.company_id', $this->companyId())
            ->whereNull('c.archived_at')
            ->groupBy('l.lease_contract_id', 'l.property_id')
            ->havingRaw('MIN(l.valid_from) >= ? AND MIN(l.valid_from) < ?', [$start->toDateString(), $start->addMonth()->toDateString()])
            ->get(['l.lease_contract_id', 'l.property_id']);

        $visible = Property::query()->pluck('id')->flip();

        foreach ($starting as $pair) {
            if (! $visible->has($pair->property_id)) {
                continue;
            }

            $lease = LeaseContract::query()->find($pair->lease_contract_id);
            $summary = $this->upcoming->deposit($lease, (int) $pair->property_id);
            if (Cents::from($summary['contracted']) > 0 && Cents::from($summary['entered']) === 0) {
                $warnings[] = new WorksheetWarning(self::DEPOSIT_MISSING, __('charges.warnings.deposit_missing', $this->where($lease, Property::query()->find($pair->property_id)) + [
                    'contracted' => Format::money($summary['contracted']),
                ]), null, $lease->id, (int) $pair->property_id);
            }
        }

        return $warnings;
    }

    /** Предстоящо начисление за договор, който не действа за имота през месеца. */
    private function upcomingWarnings(CarbonImmutable $start): array
    {
        $warnings = [];
        $end = $start->addMonth();

        foreach (UpcomingCharge::query()->whereDate('month', $start)->whereNull('cancelled_at')->with(['leaseContract', 'property'])->get() as $upcoming) {
            $lease = $upcoming->leaseContract;
            $active = $lease !== null && $lease->archived_at === null
                && ($lease->terminated_on === null || $lease->terminated_on->gt($start))
                && $lease->lines()->where('property_id', $upcoming->property_id)
                    ->where('valid_from', '<', $end->toDateString())
                    ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $start->toDateString()))
                    ->exists();

            if (! $active) {
                $warnings[] = new WorksheetWarning(self::UPCOMING_INACTIVE, __('charges.warnings.upcoming_inactive', $this->where($lease, $upcoming->property) + [
                    'type' => __('charges.types.'.$upcoming->type->value), 'amount' => Format::money($upcoming->amount),
                ]), null, $upcoming->lease_contract_id, $upcoming->property_id);
            }
        }

        return $warnings;
    }

    /**
     * Пресметнатите редове: нов или без ръчна намеса → сумата е предложената;
     * ръчно променен или махнат → пази се, обновява се само предложението.
     * Вече непредлаган: без ръчна намеса отпада, иначе остава с празно предложение.
     *
     * @param  list<ProposedCharge>  $charges
     */
    private function applyComputed(CarbonImmutable $start, Collection $existing, array $charges): void
    {
        $byKey = $existing->keyBy(fn (ChargeDraft $row) => $this->key($row->type->value, $row->lease_contract_id, $row->owner_contract_id, $row->property_id));
        $seen = [];

        foreach ($charges as $charge) {
            $type = $charge->type === ProposedCharge::RENT ? ChargeType::Rent : ChargeType::Owner;
            $key = $this->key($type->value, $charge->leaseContractId, $charge->ownerContractId, $charge->propertyId);
            $seen[$key] = true;

            $row = $byKey->get($key) ?? (new ChargeDraft)->forceFill([
                'month' => $start, 'type' => $type, 'source' => ChargeSource::Computed,
                'lease_contract_id' => $charge->leaseContractId, 'owner_contract_id' => $charge->ownerContractId,
                'property_id' => $charge->propertyId,
            ]);

            $row->forceFill([
                'tenant_id' => $charge->tenantId,
                'owner_id' => $charge->ownerId,
                'days' => $charge->days,
                'proposed_amount' => $charge->amount,
                'computed_amount' => $charge->computedAmount,
            ]);
            if ($this->untouched($row)) {
                $row->amount = $charge->amount;
            }
            $row->save();
        }

        foreach ($byKey as $key => $row) {
            if (! isset($seen[$key])) {
                $this->drop($row);
            }
        }
    }

    /** Предстоящите начисления на месеца (без отказаните). */
    private function applyUpcoming(CarbonImmutable $start, Collection $existing): void
    {
        $byUpcoming = $existing->keyBy('upcoming_charge_id');
        $upcoming = UpcomingCharge::query()->whereDate('month', $start)->whereNull('cancelled_at')
            ->with('leaseContract')->get()->keyBy('id');

        foreach ($upcoming as $id => $charge) {
            $row = $byUpcoming->get($id) ?? (new ChargeDraft)->forceFill([
                'month' => $start, 'source' => ChargeSource::Upcoming, 'upcoming_charge_id' => $id,
            ]);

            $row->forceFill([
                'type' => $charge->type,
                'lease_contract_id' => $charge->lease_contract_id,
                'property_id' => $charge->property_id,
                'tenant_id' => $charge->leaseContract?->tenant_id,
                'proposed_amount' => $charge->amount,
            ]);
            if ($this->untouched($row)) {
                $row->amount = $charge->amount;
                $row->note = $charge->note;
            }
            $row->save();
        }

        foreach ($byUpcoming as $id => $row) {
            if (! $upcoming->has($id)) {
                $this->drop($row);
            }
        }
    }

    private function untouched(ChargeDraft $row): bool
    {
        return ! $row->exists || ($row->edited_at === null && $row->removed_at === null);
    }

    /** Ред, който вече не се предлага: без ръчна намеса отпада, иначе остава с празно предложение. */
    private function drop(ChargeDraft $row): void
    {
        if ($this->untouched($row)) {
            $row->delete();

            return;
        }

        $row->forceFill(['proposed_amount' => null, 'computed_amount' => null])->save();
    }

    private function key(string $type, ?int $lease, ?int $owner, int $property): string
    {
        return implode('|', [$type, $lease ?? 0, $owner ?? 0, $property]);
    }

    private function rowsQuery(CarbonImmutable $start)
    {
        return ChargeDraft::query()->whereDate('month', $start)->orderBy('id');
    }

    /** Заместителите за текста: договор №, имот №. */
    private function where($contract, ?Property $property): array
    {
        return ['contract' => $contract?->number ?? '?', 'property' => $property?->number ?? '?'];
    }

    private function companyId(): int
    {
        return CompanyScope::restrictedCompanyId()
            ?? throw new LogicException('Работната таблица иска влязъл служител на фирма.');
    }
}
