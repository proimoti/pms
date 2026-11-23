<?php

namespace Tests\Feature\Charges;

use App\Enums\ChargeSource;
use App\Enums\ChargeType;
use App\Models\ChargeDraft;
use App\Models\LeaseContract;
use App\Models\LedgerEntry;
use App\Models\PaidUtilityBill;
use App\Models\PeriodLock;
use App\Models\Property;
use App\Models\Provider;
use App\Models\UtilityAccount;
use App\Services\Charges\Issuer;
use App\Services\Charges\MonthWorksheet;
use App\Services\Utilities\UtilityAccountService;
use App\Services\Utilities\UtilityImporter;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Contracts\ContractTestCase;
use Tests\Support\Funds;
use Tests\Support\UtilityFiles;

/**
 * Битовите сметки в работната таблица на месеца (PROJECT.md, „Битови сметки в
 * месеца“, 22.11.2026; част 4а — без издаване и книга):
 *
 * - „Генерирай месеца“ взима платените, но неначислени сметки (всяка по своя
 *   период) и фиксираните абонати за предходния месец;
 * - една сметка е само в една работна таблица;
 * - редовете се променят и махат с причина; ръчен битов ред не се добавя;
 * - сметките „за решение“ не са редове и не спират издаването;
 * - напуснал наемател само със сметки има свои редове (само тях).
 *
 * Само измислени номера, имена и суми.
 */
class UtilityWorksheetTest extends ContractTestCase
{
    use Funds, UtilityFiles;

    private MonthWorksheet $sheet;

    private Provider $power;

    private Property $flat;

    private UtilityAccount $meter;

    private int $ref = 800000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sheet = app(MonthWorksheet::class);
        $this->power = Provider::query()->create(['name' => 'Измислено Електро', 'easypay_name' => 'IZM EL']);
        $this->flat = $this->property();
        $this->percentContract([$this->flat]);
        $this->meter = $this->account('0012345', $this->flat);
    }

    private function account(string $number, Property $property): UtilityAccount
    {
        return app(UtilityAccountService::class)->create([
            'provider_id' => $this->power->id, 'number' => $number, 'kind' => 'electricity', 'billing' => 'actual',
            'valid_from' => '2026-01-01', 'properties' => [['property_id' => $property->id]],
        ]);
    }

    private function lease(Property $property, string $from, ?string $terminated = null, string $rent = '500.00'): LeaseContract
    {
        $lease = $this->leaseContract([], ['signed_on' => $from, 'lines' => [['property_id' => $property->id, 'rent' => $rent]]]);
        if ($terminated !== null) {
            $lease->update(['terminated_on' => $terminated]);
        }

        return $lease;
    }

    private function bill(UtilityAccount $account, string $paidAt, float $amount): PaidUtilityBill
    {
        $this->ref++;
        app(UtilityImporter::class)->import($this->easypayFile([
            ['ref' => $this->ref, 'date' => "{$paidAt} 10:00:00", 'number' => (int) $account->number, 'provider' => 'IZM EL', 'amount' => $amount],
        ]), 'f.xlsx');

        return PaidUtilityBill::query()->where('reference', (string) $this->ref)->sole();
    }

    private function fixedFee(Property $property, string $amount): UtilityAccount
    {
        return app(UtilityAccountService::class)->create([
            'kind' => 'common', 'billing' => 'fixed', 'fixed_amount' => $amount, 'valid_from' => '2026-01-01',
            'fund_id' => $this->fund([$property])->id, 'properties' => [['property_id' => $property->id]],
        ]);
    }

    /** Битовите редове на месеца: [вид, договор, дни, сума]. */
    private function utilityRows(string $month = '2026-12'): array
    {
        return ChargeDraft::query()->whereDate('month', "{$month}-01")->whereIn('type', [ChargeType::Utility, ChargeType::FixedFee])
            ->whereNull('removed_at')->orderBy('id')->get()
            ->map(fn (ChargeDraft $row) => [$row->type->value, $row->lease_contract_id, $row->days, $row->amount])->all();
    }

    // Генериране

    public function test_generate_adds_a_row_per_tenant_with_period_days_and_amount(): void
    {
        // Ток за ноември, платен 05.12: А 10 дни, Б 20 дни; 90,00 € → 30,00 и 60,00 (част 3).
        $a = $this->lease($this->flat, '2026-01-10', '2026-11-11');
        $b = $this->lease($this->flat, '2026-11-11');
        $bill = $this->bill($this->meter, '2026-12-05', 90.00);

        $this->sheet->generate('2026-12');

        $this->assertSame([['utility', $a->id, 10, '30.00'], ['utility', $b->id, 20, '60.00']], $this->utilityRows());
        $row = ChargeDraft::query()->where('type', ChargeType::Utility)->where('lease_contract_id', $b->id)->sole();
        $this->assertSame(ChargeSource::Computed, $row->source);
        $this->assertSame([$bill->id, $this->meter->id, $this->flat->id, $b->tenant_id],
            [$row->paid_utility_bill_id, $row->utility_account_id, $row->property_id, $row->tenant_id]);
        $this->assertSame(['2026-11-01', '2026-11-30'], [$row->period_from->toDateString(), $row->period_to->toDateString()]);
        $this->assertSame('60.00', $row->proposed_amount);
    }

    public function test_fixed_fee_rows_are_for_the_previous_month(): void
    {
        // Такса вход 15 € в известието за декември: за ноември (30 дни). А до 16.11 (15 дни): 7,50; Б от 16.11: 7,50.
        $fee = $this->fixedFee($this->flat, '15');
        $a = $this->lease($this->flat, '2026-01-10', '2026-11-16');
        $b = $this->lease($this->flat, '2026-11-16');

        $this->sheet->generate('2026-12');

        $this->assertSame([['fixed_fee', $a->id, 15, '7.50'], ['fixed_fee', $b->id, 15, '7.50']], $this->utilityRows());
        $row = ChargeDraft::query()->where('type', ChargeType::FixedFee)->where('lease_contract_id', $b->id)->sole();
        $this->assertSame([$fee->id, null], [$row->utility_account_id, $row->paid_utility_bill_id]);
        $this->assertSame(['2026-11-01', '2026-11-30'], [$row->period_from->toDateString(), $row->period_to->toDateString()]);
    }

    public function test_each_bill_is_in_one_worksheet_only(): void
    {
        $this->lease($this->flat, '2026-01-10');
        $this->bill($this->meter, '2026-12-05', 90.00);

        $this->sheet->generate('2026-12');
        $this->sheet->generate('2027-01');

        $this->assertCount(1, $this->utilityRows('2026-12'));
        $this->assertSame([], array_filter($this->utilityRows('2027-01'), fn ($row) => $row[0] === 'utility'), 'сметката вече е в декември');

        $this->sheet->generate('2026-12');
        $this->assertCount(1, $this->utilityRows('2026-12'), 'генерирай отново: пак там, без двоен ред');
    }

    public function test_bill_imported_later_goes_to_the_next_generated_month(): void
    {
        // Декември е генериран; сметката е внесена после: влиза в януари, по своя период (ноември).
        $a = $this->lease($this->flat, '2026-01-10');
        $this->sheet->generate('2026-12');
        $this->bill($this->meter, '2026-12-05', 90.00);

        $warnings = array_map(fn ($w) => $w->code, $this->sheet->warnings('2026-12'));
        $this->assertContains(MonthWorksheet::NEW_UTILITY_BILLS, $warnings, 'внесени след генерирането: генерирай отново');

        $this->sheet->generate('2027-01');
        $this->assertSame([['utility', $a->id, 30, '90.00']], $this->utilityRows('2027-01'));
        $row = ChargeDraft::query()->where('type', ChargeType::Utility)->sole();
        $this->assertSame('2026-11-01', $row->period_from->toDateString());
    }

    public function test_deadline_is_checked_against_the_notice_month(): void
    {
        // Сметката за ноември влиза чак в известието за февруари 2027: срокът на А (01.01.2027) е минал,
        // частта му поема Б: Б 90,00.
        $this->lease($this->flat, '2026-01-10', '2026-11-11');
        $b = $this->lease($this->flat, '2026-11-11');
        $this->bill($this->meter, '2026-12-05', 90.00);

        $this->sheet->generate('2027-02');

        $this->assertSame([['utility', $b->id, 20, '90.00']], $this->utilityRows('2027-02'));
    }

    // Ръчни промени

    public function test_manual_change_and_removal_are_kept_on_generate_again(): void
    {
        $a = $this->lease($this->flat, '2026-01-10', '2026-11-11');
        $b = $this->lease($this->flat, '2026-11-11');
        $this->bill($this->meter, '2026-12-05', 90.00);
        $this->sheet->generate('2026-12');

        $rowA = ChargeDraft::query()->where('type', ChargeType::Utility)->where('lease_contract_id', $a->id)->sole();
        $rowB = ChargeDraft::query()->where('type', ChargeType::Utility)->where('lease_contract_id', $b->id)->sole();

        try {
            $this->sheet->change($rowB, ['amount' => '50']);
            $this->fail('Промяна без причина.');
        } catch (ValidationException) {
        }
        $this->sheet->change($rowB, ['amount' => '50', 'reason' => 'отстъпка, уговорена с наемателя']);
        $this->sheet->remove($rowA, 'платено на място');

        $this->sheet->generate('2026-12');

        $this->assertSame([['utility', $b->id, 20, '50.00']], $this->utilityRows(), 'ръчната сума остава, махнатият не се връща');
        $this->assertSame('60.00', $rowB->fresh()->proposed_amount);
        $this->assertNotNull($rowA->fresh()->removed_at);
    }

    public function test_a_utility_row_cannot_be_added_by_hand(): void
    {
        $lease = $this->lease($this->flat, '2026-01-10');

        foreach ([ChargeType::Utility, ChargeType::FixedFee] as $type) {
            $this->assertRejected(fn () => $this->sheet->addManual('2026-12', [
                'type' => $type->value, 'lease_contract_id' => $lease->id, 'property_id' => $this->flat->id, 'amount' => '10', 'reason' => 'опит',
            ]), 'type');
        }
    }

    // Напуснал наемател само със сметки

    public function test_former_tenant_has_only_utility_rows(): void
    {
        // А напуска на 11.11: в декември няма наем, но има своята част от тока за ноември.
        $a = $this->lease($this->flat, '2026-01-10', '2026-11-11');
        $b = $this->lease($this->flat, '2026-11-11');
        $this->bill($this->meter, '2026-12-05', 90.00);

        $this->sheet->generate('2026-12');

        $rowsA = ChargeDraft::query()->whereDate('month', '2026-12-01')->where('lease_contract_id', $a->id)->get();
        $this->assertSame(['utility'], $rowsA->map(fn ($row) => $row->type->value)->all());
        $this->assertSame(['rent', 'utility'], ChargeDraft::query()->whereDate('month', '2026-12-01')->where('lease_contract_id', $b->id)
            ->orderBy('id')->get()->map(fn ($row) => $row->type->value)->all());
    }

    // За решение

    public function test_vacant_bill_is_for_decision_not_a_row(): void
    {
        // Имотът е празен през ноември: 48,20 € не са ред, а са в „Битови сметки за решение“.
        $bill = $this->bill($this->meter, '2026-12-05', 48.20);

        $this->sheet->generate('2026-12');

        $this->assertSame([], $this->utilityRows());
        $items = $this->sheet->utilitiesForDecision('2026-12');
        $this->assertCount(1, $items);
        $this->assertSame([$bill->id, $this->flat->id, 'vacant', '48.20'],
            [$items[0]['bill']->id, $items[0]['split']->propertyId, $items[0]['split']->reason, $items[0]['split']->forDecision]);
    }

    public function test_for_decision_does_not_block_issuing_and_the_bill_stays_unallocated(): void
    {
        $other = $this->property();
        $this->percentContract([$other]);
        $this->lease($other, '2026-01-10');
        $this->bill($this->meter, '2026-12-05', 48.20); // празният имот

        $this->sheet->generate('2026-12');
        app(Issuer::class)->issueMonth('2026-12');

        $this->assertTrue(PeriodLock::isLocked(\Carbon\CarbonImmutable::parse('2026-12-01')));
        $this->assertSame(0, LedgerEntry::query()->where('type', ChargeType::Utility)->where('account', '418')->count());
        $this->assertCount(1, $this->sheet->utilitiesForDecision('2027-01'), 'остава за решение и в следващия месец');
    }

    // Временно, до част 4б

    public function test_issuing_with_utility_rows_waits_for_part_4b(): void
    {
        $lease = $this->lease($this->flat, '2026-01-10');
        $this->bill($this->meter, '2026-12-05', 90.00);
        $this->sheet->generate('2026-12');

        $this->assertRejected(fn () => app(Issuer::class)->issueMonth('2026-12'), 'month');
        $this->assertRejected(fn () => app(Issuer::class)->issueNow($lease, '2026-12'), 'month');
        $this->assertSame(0, LedgerEntry::query()->where('account', '<>', '407')->count(), 'нищо в книгата освен 407 от вноса');
    }

    // Фирми

    public function test_bills_of_another_company_are_not_in_our_worksheet(): void
    {
        $this->lease($this->flat, '2026-01-10');
        $this->inOtherCompany(function () {
            $provider = Provider::query()->create(['name' => 'Друго Електро', 'easypay_name' => 'DRUGO EL']);
            $property = $this->property();
            $this->leaseContract([$property]);
            app(UtilityAccountService::class)->create([
                'provider_id' => $provider->id, 'number' => '999', 'kind' => 'electricity', 'billing' => 'actual',
                'valid_from' => '2026-01-01', 'properties' => [['property_id' => $property->id]],
            ]);
            app(UtilityImporter::class)->import($this->easypayFile([
                ['ref' => 1, 'date' => '2026-12-05 10:00:00', 'number' => 999, 'provider' => 'DRUGO EL', 'amount' => 70],
            ]), 'f.xlsx');
        });

        $this->sheet->generate('2026-12');

        $this->assertSame([], $this->utilityRows());
        $this->assertSame([], $this->sheet->utilitiesForDecision('2026-12'));
    }
}
