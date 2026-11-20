<?php

namespace Tests\Feature\Charges;

use App\Enums\ChargeSource;
use App\Enums\ChargeType;
use App\Models\AuditLog;
use App\Models\ChargeDraft;
use App\Models\ChargeMonth;
use App\Models\LeaseContract;
use App\Models\Property;
use App\Models\UpcomingCharge;
use App\Services\Charges\MonthWorksheet;
use App\Services\Charges\UpcomingChargeService;
use Tests\Feature\Contracts\ContractTestCase;

/**
 * Работната таблица на месеца (PROJECT.md, т. 2 „Работна таблица на месеца“,
 * „Начисления“, „Начисления, уточнения“): „Генерирай месеца“ записва
 * предложените начисления като работни редове; ръчните промени и махания
 * се пазят (с причина); предстоящите начисления влизат в своя месец;
 * предупрежденията показват какво да се провери. Книгата не се пипа.
 */
class MonthWorksheetTest extends ContractTestCase
{
    private MonthWorksheet $sheet;

    private UpcomingChargeService $upcoming;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sheet = app(MonthWorksheet::class);
        $this->upcoming = app(UpcomingChargeService::class);
    }

    /** Имот с договор за управление 10% и договор за наем 600 € (депозит 1200 €) от 10.01.2026. */
    private function rented(): array
    {
        $property = $this->property();
        $management = $this->percentContract([$property]);
        $lease = $this->leaseContract([$property]);

        return [$property, $lease, $management];
    }

    private function row(Property $property, ChargeType $type, string $month = '2026-12'): ?ChargeDraft
    {
        return ChargeDraft::query()->where('property_id', $property->id)->where('type', $type)
            ->whereDate('month', $month.'-01')->first();
    }

    private function codes(string $month = '2026-12'): array
    {
        return array_map(fn ($w) => $w->code, $this->sheet->warnings($month));
    }

    // Генериране

    public function test_generate_saves_the_proposed_charges_as_working_rows(): void
    {
        [$property, $lease, $management] = $this->rented();

        $stamp = $this->sheet->generate('2026-12');

        $rent = $this->row($property, ChargeType::Rent);
        $this->assertSame('600.00', $rent->amount);
        $this->assertSame('600.00', $rent->proposed_amount);
        $this->assertSame(ChargeSource::Computed, $rent->source);
        $this->assertSame($lease->id, $rent->lease_contract_id);
        $this->assertSame($lease->tenant_id, $rent->tenant_id);
        $this->assertSame(31, $rent->days);

        $owner = $this->row($property, ChargeType::Owner);
        $this->assertSame('540.00', $owner->amount);
        $this->assertSame($management->id, $owner->owner_contract_id);

        $this->assertInstanceOf(ChargeMonth::class, $stamp);
        $this->assertSame($this->staff->id, $stamp->generated_by);
        $this->assertNotNull($stamp->generated_at);
        $this->assertSame(2, ChargeDraft::query()->count());
    }

    public function test_generate_again_follows_the_contracts(): void
    {
        [$property, $lease] = $this->rented();
        $this->sheet->generate('2026-12');

        $this->leases->correct($lease, ['lines' => [$lease->lines()->sole()->id => ['rent' => '700']]]);
        $this->sheet->generate('2026-12');

        $this->assertSame('700.00', $this->row($property, ChargeType::Rent)->amount);
        $this->assertSame('630.00', $this->row($property, ChargeType::Owner)->amount);
        $this->assertSame(2, ChargeDraft::query()->count());
    }

    public function test_zero_rent_row_is_kept_for_information(): void
    {
        $garage = $this->property();
        $this->percentContract([$garage]);
        $this->leaseContract([], ['lines' => [['property_id' => $garage->id, 'rent' => '0']]]);

        $this->sheet->generate('2026-12');

        $this->assertSame('0.00', $this->row($garage, ChargeType::Rent)->amount);
    }

    // Ръчни промени, махане, ръчни редове: винаги с причина

    public function test_changed_row_keeps_its_amount_and_warns_when_the_computed_one_changes(): void
    {
        [$property, $lease] = $this->rented();
        $this->sheet->generate('2026-12');
        $rent = $this->row($property, ChargeType::Rent);

        $this->sheet->change($rent, ['amount' => '550', 'reason' => 'Договорена отстъпка']);
        $rent->refresh();
        $this->assertSame('550.00', $rent->amount);
        $this->assertSame('Договорена отстъпка', $rent->edit_reason);
        $this->assertSame($this->staff->id, $rent->edited_by);
        $this->assertNotContains(MonthWorksheet::EDITED_CHANGED, $this->codes());

        $this->sheet->generate('2026-12');
        $this->assertSame('550.00', $rent->fresh()->amount);
        $this->assertNotContains(MonthWorksheet::EDITED_CHANGED, $this->codes());

        $this->leases->correct($lease, ['lines' => [$lease->lines()->sole()->id => ['rent' => '700']]]);
        $this->sheet->generate('2026-12');

        $rent->refresh();
        $this->assertSame('550.00', $rent->amount);
        $this->assertSame('700.00', $rent->proposed_amount);
        $this->assertContains(MonthWorksheet::EDITED_CHANGED, $this->codes());
    }

    public function test_change_and_remove_need_a_reason(): void
    {
        [$property] = $this->rented();
        $this->sheet->generate('2026-12');
        $rent = $this->row($property, ChargeType::Rent);

        $this->assertRejected(fn () => $this->sheet->change($rent, ['amount' => '550', 'reason' => ' ']), 'reason');
        $this->assertRejected(fn () => $this->sheet->change($rent, ['amount' => 'abc', 'reason' => 'Причина']), 'amount');
        $this->assertRejected(fn () => $this->sheet->remove($rent, ''), 'reason');
        $this->assertSame('600.00', $rent->fresh()->amount);
        $this->assertNull($rent->fresh()->removed_at);
    }

    public function test_removed_row_is_remembered_and_not_brought_back(): void
    {
        [$property] = $this->rented();
        $this->sheet->generate('2026-12');
        $owner = $this->row($property, ChargeType::Owner);

        $this->sheet->remove($owner, 'Уговорено със собственика');
        $this->sheet->generate('2026-12');

        $owner->refresh();
        $this->assertNotNull($owner->removed_at);
        $this->assertSame('Уговорено със собственика', $owner->remove_reason);
        $this->assertSame(2, ChargeDraft::query()->count());

        $this->assertRejected(fn () => $this->sheet->restore($owner, ''), 'reason');
        $this->sheet->restore($owner, 'Грешка, връщам');
        $this->assertNull($owner->fresh()->removed_at);
    }

    // Дял на собственика (PROJECT.md, „Дял на собственика“): отстъпката е промяна на реда за наем

    public function test_discount_on_the_rent_row_changes_the_owner_share_at_a_percentage(): void
    {
        [$property] = $this->rented();
        $this->sheet->generate('2026-12');

        $this->sheet->change($this->row($property, ChargeType::Rent), ['amount' => '500', 'reason' => 'Отстъпка за ремонт']);

        $owner = $this->row($property, ChargeType::Owner);
        $this->assertSame('450.00', $owner->amount);
        $this->assertNull($owner->edited_at);

        $this->sheet->generate('2026-12');
        $this->assertSame('450.00', $this->row($property, ChargeType::Owner)->amount);
        $this->assertSame('500.00', $this->row($property, ChargeType::Rent)->amount);
        $this->assertSame('600.00', $this->row($property, ChargeType::Rent)->proposed_amount);
    }

    public function test_removed_rent_row_leaves_no_owner_share_at_a_percentage(): void
    {
        [$property] = $this->rented();
        $this->sheet->generate('2026-12');

        $this->sheet->remove($this->row($property, ChargeType::Rent), 'Не се начислява');
        $this->assertSame('0.00', $this->row($property, ChargeType::Owner)->amount);

        $this->sheet->restore($this->row($property, ChargeType::Rent), 'Грешка');
        $this->assertSame('540.00', $this->row($property, ChargeType::Owner)->amount);
    }

    public function test_discount_does_not_change_a_fixed_owner_amount(): void
    {
        $property = $this->property();
        $this->fixedContract([$property]);
        $this->leaseContract([$property]);
        $this->sheet->generate('2026-12');

        $this->sheet->change($this->row($property, ChargeType::Rent), ['amount' => '500', 'reason' => 'Отстъпка']);

        $this->assertSame('460.00', $this->row($property, ChargeType::Owner)->amount);
    }

    public function test_owner_row_changed_by_hand_is_not_touched_by_a_discount(): void
    {
        [$property] = $this->rented();
        $this->sheet->generate('2026-12');
        $this->sheet->change($this->row($property, ChargeType::Owner), ['amount' => '520', 'reason' => 'Уговорка']);

        $this->sheet->change($this->row($property, ChargeType::Rent), ['amount' => '500', 'reason' => 'Отстъпка']);

        $owner = $this->row($property, ChargeType::Owner);
        $this->assertSame('520.00', $owner->amount);
        $this->assertSame('450.00', $owner->proposed_amount);
    }

    // Журналът: един запис за всяко генериране

    public function test_every_generation_writes_one_journal_entry(): void
    {
        $this->rented();

        $stamp = $this->sheet->generate('2026-12');
        $this->sheet->generate('2026-12');

        $entries = AuditLog::query()->where('auditable_type', 'charge_months')->where('event', 'generated')->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame($stamp->id, $entries[0]->auditable_id);
        $this->assertSame($this->staff->id, $entries[0]->user_id);
        $this->assertSame('2026-12', $entries[0]->changes['month']['new']);
        $this->assertSame(2, $entries[0]->changes['rows']['new']);
        $this->assertSame(2, $entries[1]->changes['rows']['old']);
        $this->assertSame(0, AuditLog::query()->where('auditable_type', 'charge_drafts')->count());
    }

    public function test_removed_row_is_remembered_even_when_it_stops_and_comes_back(): void
    {
        [$property, $lease] = $this->rented();
        $this->sheet->generate('2026-12');
        $this->sheet->remove($this->row($property, ChargeType::Rent), 'Уговорка');

        $lease->update(['terminated_on' => '2026-11-15']);
        $this->sheet->generate('2026-12');
        $lease->update(['terminated_on' => null]);
        $this->sheet->generate('2026-12');

        $rent = $this->row($property, ChargeType::Rent);
        $this->assertNotNull($rent);
        $this->assertNotNull($rent->removed_at);
        $this->assertSame('Уговорка', $rent->remove_reason);
        $this->assertSame(1, ChargeDraft::query()->where('type', ChargeType::Rent)->count());
    }

    public function test_manual_rows_stay_after_generating_again(): void
    {
        [$property, $lease, $management] = $this->rented();
        $this->sheet->generate('2026-12');

        $penalty = $this->sheet->addManual('2026-12', [
            'type' => 'penalty', 'lease_contract_id' => $lease->id, 'property_id' => $property->id,
            'amount' => '50', 'note' => 'Закъснение', 'reason' => 'По договора',
        ]);
        $owner = $this->sheet->addManual('2026-12', [
            'type' => 'owner', 'owner_contract_id' => $management->id, 'property_id' => $property->id,
            'amount' => '20', 'note' => 'Ремонт за сметка на фирмата', 'reason' => 'Уговорка',
        ]);
        $this->sheet->generate('2026-12');

        $this->assertSame(ChargeSource::Manual, $penalty->fresh()->source);
        $this->assertSame('50.00', $penalty->fresh()->amount);
        $this->assertSame($lease->tenant_id, $penalty->fresh()->tenant_id);
        $this->assertSame($management->owner_id, $owner->fresh()->owner_id);
        $this->assertSame(4, ChargeDraft::query()->count());
    }

    public function test_manual_row_rules(): void
    {
        [$property, $lease] = $this->rented();
        $other = $this->property();
        $base = ['type' => 'penalty', 'lease_contract_id' => $lease->id, 'property_id' => $property->id, 'amount' => '50', 'reason' => 'Причина'];

        $this->assertRejected(fn () => $this->sheet->addManual('2026-12', ['reason' => ''] + $base), 'reason');
        $this->assertRejected(fn () => $this->sheet->addManual('2026-12', ['type' => 'other'] + $base), 'note');
        $this->assertRejected(fn () => $this->sheet->addManual('2026-12', ['type' => 'utility'] + $base), 'type');
        $this->assertRejected(fn () => $this->sheet->addManual('2026-12', ['property_id' => $other->id] + $base), 'property_id');
        $this->assertRejected(fn () => $this->sheet->addManual('2026-12', ['amount' => '-5'] + $base), 'amount');
        $this->assertRejected(fn () => $this->sheet->addManual('2026-13', $base), 'month');
        $this->assertSame(0, ChargeDraft::query()->count());
    }

    // Изчезнал пресметнат ред

    public function test_computed_row_that_no_longer_applies_goes_away_unless_changed_by_hand(): void
    {
        [$a, $leaseA] = $this->rented();
        [$b, $leaseB] = $this->rented();
        $this->sheet->generate('2026-12');
        $this->sheet->change($this->row($b, ChargeType::Rent), ['amount' => '590', 'reason' => 'Причина']);

        $leaseA->update(['terminated_on' => '2026-11-15']);
        $leaseB->update(['terminated_on' => '2026-11-15']);
        $this->sheet->generate('2026-12');

        $this->assertNull($this->row($a, ChargeType::Rent));
        $this->assertNotNull($this->row($b, ChargeType::Rent));
        $this->assertNull($this->row($b, ChargeType::Rent)->proposed_amount);
        $this->assertContains(MonthWorksheet::NO_LONGER_COMPUTED, $this->codes());
    }

    // Предстоящи начисления

    public function test_upcoming_charge_goes_into_its_month(): void
    {
        [$property, $lease] = $this->rented();
        $deposit = $this->upcoming->create([
            'lease_contract_id' => $lease->id, 'property_id' => $property->id, 'type' => 'deposit',
            'month' => '2026-12', 'amount' => '600', 'note' => 'Първа вноска',
        ]);

        $this->sheet->generate('2026-11');
        $this->assertNull($this->row($property, ChargeType::Deposit, '2026-11'));

        $this->sheet->generate('2026-12');
        $row = $this->row($property, ChargeType::Deposit);
        $this->assertSame('600.00', $row->amount);
        $this->assertSame(ChargeSource::Upcoming, $row->source);
        $this->assertSame($deposit->id, $row->upcoming_charge_id);
        $this->assertSame('Първа вноска', $row->note);

        $this->upcoming->update($deposit, ['amount' => '700', 'note' => 'Първа вноска', 'month' => '2026-12']);
        $this->sheet->generate('2026-12');
        $this->assertSame('700.00', $this->row($property, ChargeType::Deposit)->amount);

        $this->upcoming->cancel($deposit, 'Платен в брой при подписването');
        $this->sheet->generate('2026-12');
        $this->assertNull($this->row($property, ChargeType::Deposit));
        $this->assertNotNull($deposit->fresh()->cancelled_at);
        $this->assertSame(1, UpcomingCharge::query()->count());
    }

    public function test_upcoming_charge_rules(): void
    {
        [$property, $lease] = $this->rented();
        $base = ['lease_contract_id' => $lease->id, 'property_id' => $property->id, 'type' => 'penalty', 'month' => '2026-12', 'amount' => '50'];

        $this->assertRejected(fn () => $this->upcoming->create(['type' => 'rent'] + $base), 'type');
        $this->assertRejected(fn () => $this->upcoming->create(['type' => 'other'] + $base), 'note');
        $this->assertRejected(fn () => $this->upcoming->create(['property_id' => $this->property()->id] + $base), 'property_id');
        $this->assertRejected(fn () => $this->upcoming->create(['month' => '2026/12'] + $base), 'month');
        $this->assertRejected(fn () => $this->upcoming->create(['amount' => '0'] + $base), 'amount');
        $upcoming = $this->upcoming->create($base);
        $this->assertRejected(fn () => $this->upcoming->cancel($upcoming, ''), 'reason');
    }

    public function test_deposit_summary_shows_what_is_left(): void
    {
        [$property, $lease] = $this->rented();
        foreach (['2026-12' => '400', '2027-01' => '400'] as $month => $amount) {
            $this->upcoming->create(['lease_contract_id' => $lease->id, 'property_id' => $property->id,
                'type' => 'deposit', 'month' => $month, 'amount' => $amount]);
        }
        $cancelled = $this->upcoming->create(['lease_contract_id' => $lease->id, 'property_id' => $property->id,
            'type' => 'deposit', 'month' => '2027-02', 'amount' => '400']);
        $this->upcoming->cancel($cancelled, 'Отпада');

        $summary = $this->upcoming->deposit($lease, $property);

        $this->assertSame(['contracted' => '1200.00', 'entered' => '800.00', 'remaining' => '400.00'], $summary);
    }

    // Предупреждения

    public function test_warning_when_agreed_first_period_rent_differs(): void
    {
        $property = $this->property();
        $this->percentContract([$property]);
        $this->leaseContract([], ['signed_on' => '2026-12-16',
            'lines' => [['property_id' => $property->id, 'rent' => '600', 'first_period_rent' => '300']]]);

        $this->sheet->generate('2026-12');

        $rent = $this->row($property, ChargeType::Rent);
        $this->assertSame('300.00', $rent->amount);
        $this->assertSame('309.68', $rent->computed_amount);
        $warning = collect($this->sheet->warnings('2026-12'))->firstWhere('code', MonthWorksheet::AGREED_FIRST_RENT);
        $this->assertSame($rent->id, $warning->chargeDraftId);
        $this->assertStringContainsString('309,68', $warning->message);
        $this->assertStringContainsString('300,00', $warning->message);
    }

    public function test_warning_for_rent_waiting_for_decision(): void
    {
        $property = $this->property();
        $this->percentContract([$property], ['signed_on' => '2026-12-05']);
        $lease = $this->leaseContract([$property]);

        $this->sheet->generate('2026-12');

        $this->assertNull($this->row($property, ChargeType::Rent));
        $this->assertCount(1, $this->sheet->forDecision('2026-12'));
        $warning = collect($this->sheet->warnings('2026-12'))->firstWhere('code', MonthWorksheet::FOR_DECISION);
        $this->assertSame($lease->id, $warning->leaseContractId);
        $this->assertSame($property->id, $warning->propertyId);
    }

    public function test_deposit_warnings(): void
    {
        // Вноските надхвърлят договорения депозит.
        [$property, $lease] = $this->rented();
        $this->upcoming->create(['lease_contract_id' => $lease->id, 'property_id' => $property->id, 'type' => 'deposit', 'month' => '2026-12', 'amount' => '800']);
        $this->upcoming->create(['lease_contract_id' => $lease->id, 'property_id' => $property->id, 'type' => 'deposit', 'month' => '2027-01', 'amount' => '500']);

        // Договор от този месец с договорен депозит, а депозит не е въведен.
        $new = $this->property();
        $this->percentContract([$new]);
        $fresh = $this->leaseContract([], ['signed_on' => '2026-12-01',
            'lines' => [['property_id' => $new->id, 'rent' => '500', 'deposit' => '1000']]]);

        $this->sheet->generate('2026-12');

        $warnings = collect($this->sheet->warnings('2026-12'));
        $this->assertSame($lease->id, $warnings->firstWhere('code', MonthWorksheet::DEPOSIT_OVER)->leaseContractId);
        $this->assertSame($fresh->id, $warnings->firstWhere('code', MonthWorksheet::DEPOSIT_MISSING)->leaseContractId);
        $this->assertCount(1, $warnings->where('code', MonthWorksheet::DEPOSIT_MISSING));
    }

    public function test_warning_for_upcoming_charge_of_a_contract_not_active_in_the_month(): void
    {
        [$property, $lease] = $this->rented();
        $this->upcoming->create(['lease_contract_id' => $lease->id, 'property_id' => $property->id, 'type' => 'penalty', 'month' => '2026-12', 'amount' => '50']);
        $lease->update(['terminated_on' => '2026-11-15']);

        $this->sheet->generate('2026-12');

        $this->assertContains(MonthWorksheet::UPCOMING_INACTIVE, $this->codes());
    }

    public function test_nothing_is_written_for_other_months_or_companies(): void
    {
        [$property] = $this->rented();
        $this->inOtherCompany(function () {
            $p = $this->property();
            $this->percentContract([$p]);
            $this->leaseContract([$p]);
        });

        $this->sheet->generate('2026-12');

        $this->assertSame(2, ChargeDraft::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ChargeDraft::query()->whereDate('month', '2026-11-01')->count());
    }
}
