<?php

namespace Tests\Feature\Utilities;

use App\Models\LeaseContract;
use App\Models\PaidUtilityBill;
use App\Models\Property;
use App\Models\Provider;
use App\Models\UtilityAccount;
use App\Services\Utilities\UtilityAccountService;
use App\Services\Utilities\UtilityCalculator;
use App\Services\Utilities\UtilityImporter;
use App\Services\Utilities\UtilitySplit;
use Carbon\CarbonImmutable;
use Tests\Feature\Contracts\ContractTestCase;
use Tests\Support\UtilityFiles;

/**
 * Калкулаторът на битовите сметки (PROJECT.md, т. 3.2, 3.3 и 3.4, „Битови
 * сметки, правила за смятане“, 21.11.2026). Само смята: не записва нищо.
 *
 * - периодът е в месеца преди плащането, от „ден от“ до „ден до“, двата края
 *   се броят; за наемателя денят на напускане не се брои;
 * - сумата се дели между наемателите в периода по дни; неосвоените дни
 *   (празен имот) се поемат от тях; стотинката отива към този с най-много дни;
 * - напуснал наемател след срока за последно начисление (т. 3.2): частта му
 *   поемат останалите; ако няма никой, сметката е „за решение“;
 * - празен имот през целия период: „за решение“;
 * - фиксиран абонат: сума на месец, по дни като наема, за ПРЕДХОДНИЯ месец
 *   (известието за октомври начислява септември; решено 22.11.2026).
 *
 * Само измислени номера, имена и суми.
 */
class UtilityCalculatorTest extends ContractTestCase
{
    use UtilityFiles;

    private UtilityCalculator $calculator;

    private Provider $power;

    private Property $flat;

    private UtilityAccount $meter;

    private int $ref = 700000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = app(UtilityCalculator::class);
        $this->power = Provider::query()->create(['name' => 'Измислено Електро', 'easypay_name' => 'IZM EL']);
        $this->flat = $this->property();
        $this->meter = $this->account('0012345', [['property_id' => $this->flat->id]]);
    }

    // Помощници

    private function account(string $number, array $properties, array $overrides = []): UtilityAccount
    {
        return app(UtilityAccountService::class)->create(array_merge([
            'provider_id' => $this->power->id, 'number' => $number, 'kind' => 'electricity', 'billing' => 'actual',
            'valid_from' => '2026-01-01', 'properties' => $properties,
        ], $overrides));
    }

    /** Договор за наем за имота от дата, по желание с дата на прекратяване (денят не се брои). */
    private function lease(Property $property, string $from, ?string $terminated = null): LeaseContract
    {
        $lease = $this->leaseContract([], ['signed_on' => $from, 'lines' => [['property_id' => $property->id, 'rent' => '500.00']]]);
        if ($terminated !== null) {
            $lease->update(['terminated_on' => $terminated]);
        }

        return $lease;
    }

    /** Платена сметка, внесена от файл на EasyPay. */
    private function bill(UtilityAccount $account, string $paidAt, float $amount): PaidUtilityBill
    {
        $this->ref++;
        app(UtilityImporter::class)->import($this->easypayFile([
            ['ref' => $this->ref, 'date' => "{$paidAt} 10:00:00", 'number' => (int) $account->number, 'provider' => 'IZM EL', 'amount' => $amount],
        ]), 'f.xlsx');

        return PaidUtilityBill::query()->where('reference', (string) $this->ref)->sole();
    }

    /** Делението на сметката (с един имот) при начисление за месец М. */
    private function split(PaidUtilityBill $bill, string $month = '2026-12'): UtilitySplit
    {
        $splits = $this->calculator->forBill($bill, CarbonImmutable::parse("{$month}-01"));
        $this->assertCount(1, $splits);

        return $splits[0];
    }

    /** @return array<int, array{0: int, 1: string}> договор → [дни, сума] */
    private function shares(UtilitySplit $split): array
    {
        $result = [];
        foreach ($split->shares as $share) {
            $result[$share->leaseContractId] = [$share->days, $share->amount];
        }

        return $result;
    }

    // Период на потребление

    public function test_period_is_the_month_before_payment_both_ends_counted(): void
    {
        $period = UtilityCalculator::period($this->meter, CarbonImmutable::parse('2026-12-05 10:00'));
        $this->assertSame(['2026-11-01', '2026-11-30', 30], [$period[0]->toDateString(), $period[1]->toDateString(), $period[2]]);

        // „Ден до“ 31 във февруари е последният ден на месеца.
        $custom = $this->account('777', [['property_id' => $this->property()->id]], ['period_start_day' => 5, 'period_end_day' => 31]);
        $period = UtilityCalculator::period($custom, CarbonImmutable::parse('2027-03-02'));
        $this->assertSame(['2027-02-05', '2027-02-28', 24], [$period[0]->toDateString(), $period[1]->toDateString(), $period[2]]);
    }

    public function test_period_from_the_account_days_5_to_25(): void
    {
        // Период 05.11–25.11 (21 дни). А до 15.11 (5–14: 10 дни), Б от 15.11 (15–25: 11 дни).
        // 42,00 € → А 42 × 10 ÷ 21 = 20,00; Б 22,00.
        $flat = $this->property();
        $account = $this->account('555', [['property_id' => $flat->id]], ['period_start_day' => 5, 'period_end_day' => 25]);
        $a = $this->lease($flat, '2026-01-10', '2026-11-15');
        $b = $this->lease($flat, '2026-11-15');

        $split = $this->split($this->bill($account, '2026-12-03', 42.00));

        $this->assertSame([$a->id => [10, '20.00'], $b->id => [11, '22.00']], $this->shares($split));
        $this->assertSame(['2026-11-05', '2026-11-25'], [$split->from->toDateString(), $split->to->toDateString()]);
    }

    // Смяна на наемател в периода

    public function test_tenant_changes_in_the_period(): void
    {
        // Ток за ноември (30 дни), платен 05.12. А до 11.11 (1–10: 10 дни), Б от 11.11 (11–30: 20 дни).
        // 90,00 € → А 30,00; Б 60,00.
        $a = $this->lease($this->flat, '2026-01-10', '2026-11-11');
        $b = $this->lease($this->flat, '2026-11-11');

        $split = $this->split($this->bill($this->meter, '2026-12-05', 90.00));

        $this->assertSame([$a->id => [10, '30.00'], $b->id => [20, '60.00']], $this->shares($split));
        $this->assertSame([$a->tenant_id, $b->tenant_id], array_map(fn ($s) => $s->tenantId, $split->shares));
        $this->assertNull($split->reason);
        $this->assertSame('0.00', $split->forDecision);
    }

    public function test_the_rounding_cent_goes_to_the_tenant_with_most_days(): void
    {
        // 100,00 € при 10 и 20 дни: А 33,333… → 33,33; Б (най-много дни) 66,67.
        $a = $this->lease($this->flat, '2026-01-10', '2026-11-11');
        $b = $this->lease($this->flat, '2026-11-11');

        $split = $this->split($this->bill($this->meter, '2026-12-05', 100.00));

        $this->assertSame([$a->id => [10, '33.33'], $b->id => [20, '66.67']], $this->shares($split));
    }

    public function test_equal_days_the_cent_goes_to_the_later_tenant(): void
    {
        // 100,01 € при 15 и 15 дни: А 50,00; Б (по-късният, при равни дни) 50,01.
        $a = $this->lease($this->flat, '2026-01-10', '2026-11-16');
        $b = $this->lease($this->flat, '2026-11-16');

        $split = $this->split($this->bill($this->meter, '2026-12-05', 100.01));

        $this->assertSame([$a->id => [15, '50.00'], $b->id => [15, '50.01']], $this->shares($split));
    }

    // Неосвоени дни

    public function test_vacant_days_are_taken_by_the_tenants(): void
    {
        // Ноември: А до 11.11 (10 дни), празно 11–15.11 (5 дни), Б от 16.11 (15 дни).
        // Делят се 25-те дни на наемателите: 100,00 € → А 100 × 10 ÷ 25 = 40,00; Б 60,00.
        $a = $this->lease($this->flat, '2026-01-10', '2026-11-11');
        $b = $this->lease($this->flat, '2026-11-16');

        $split = $this->split($this->bill($this->meter, '2026-12-05', 100.00));

        $this->assertSame([$a->id => [10, '40.00'], $b->id => [15, '60.00']], $this->shares($split));
        $this->assertSame(5, $split->vacantDays);
        $this->assertSame('0.00', $split->forDecision, 'нищо не остава за управителя');
    }

    public function test_one_tenant_part_of_the_period_pays_all(): void
    {
        // Наемател само от 21.11 (10 дни от 30): поема цялата сметка 75,40 €.
        $a = $this->lease($this->flat, '2026-11-21');

        $split = $this->split($this->bill($this->meter, '2026-12-05', 75.40));

        $this->assertSame([$a->id => [10, '75.40']], $this->shares($split));
        $this->assertSame(20, $split->vacantDays);
    }

    // Празен имот през целия период

    public function test_vacant_property_for_the_whole_period_is_for_decision(): void
    {
        // Наемателят идва от 01.12, след периода (ноември): сметката 48,20 € е „за решение“,
        // не отива сама към следващия наемател.
        $this->lease($this->flat, '2026-12-01');

        $split = $this->split($this->bill($this->meter, '2026-12-05', 48.20));

        $this->assertSame([], $split->shares);
        $this->assertSame('vacant', $split->reason);
        $this->assertSame('48.20', $split->forDecision);
        $this->assertSame(30, $split->vacantDays);
    }

    public function test_archived_lease_does_not_count(): void
    {
        $lease = $this->lease($this->flat, '2026-01-10');
        $lease->forceFill(['archived_at' => now()])->saveQuietly();

        $split = $this->split($this->bill($this->meter, '2026-12-05', 48.20));

        $this->assertSame('vacant', $split->reason);
    }

    // Напуснал наемател и срокът за последно начисление (т. 3.2)

    public function test_last_charge_month_from_the_document(): void
    {
        // Напуска на 20.08 → последно ползване август → последно начисление 01.10.
        // Напуска на 01.08 → последно ползване юли → последно начисление 01.09.
        $this->assertSame('2026-10', UtilityCalculator::lastChargeMonth(CarbonImmutable::parse('2026-08-20'))->format('Y-m'));
        $this->assertSame('2026-09', UtilityCalculator::lastChargeMonth(CarbonImmutable::parse('2026-08-01'))->format('Y-m'));
    }

    public function test_former_tenant_still_in_time_gets_his_part(): void
    {
        // А напуска на 11.11 → последно начисление 01.01.2027. Начисление за януари 2027: А още плаща.
        $a = $this->lease($this->flat, '2026-01-10', '2026-11-11');
        $b = $this->lease($this->flat, '2026-11-11');

        $split = $this->split($this->bill($this->meter, '2026-12-05', 90.00), '2027-01');

        $this->assertSame([$a->id => [10, '30.00'], $b->id => [20, '60.00']], $this->shares($split));
        $this->assertSame([], $split->takenOver);
    }

    public function test_former_tenant_past_the_deadline_his_part_goes_to_the_others(): void
    {
        // Същата сметка (90,00 €), но се начислява чак за февруари 2027: срокът на А (01.01.2027)
        // е минал. Неговите 10 дни поема Б: Б 90,00. А е показан като „поет от останалите“.
        $a = $this->lease($this->flat, '2026-01-10', '2026-11-11');
        $b = $this->lease($this->flat, '2026-11-11');

        $split = $this->split($this->bill($this->meter, '2026-12-05', 90.00), '2027-02');

        $this->assertSame([$b->id => [20, '90.00']], $this->shares($split));
        $this->assertSame([$a->id], array_map(fn ($s) => $s->leaseContractId, $split->takenOver));
        $this->assertSame(10, $split->takenOver[0]->days);
        $this->assertNull($split->reason);
    }

    public function test_past_the_deadline_with_three_tenants(): void
    {
        // Ноември: А 1–10 (10 дни, напуснал, срокът минал), Б 11–20 (10 дни), В 21–30 (10 дни).
        // Начисление за февруари 2027: 100,00 € се делят между Б и В: 50,00 и 50,00.
        $a = $this->lease($this->flat, '2026-01-10', '2026-11-11');
        $b = $this->lease($this->flat, '2026-11-11', '2026-11-21');
        $c = $this->lease($this->flat, '2026-11-21');

        // Срокът на Б (напуска 21.11) също е 01.01.2027, затова за февруари остава само В.
        $split = $this->split($this->bill($this->meter, '2026-12-05', 100.00), '2027-02');
        $this->assertSame([$c->id => [10, '100.00']], $this->shares($split));

        // За януари 2027 и тримата са в срок: 33,33 + 33,33 + 33,34 (при равни дни — към последния).
        $split = $this->split($this->bill($this->meter, '2026-12-06', 100.00), '2027-01');
        $this->assertSame([$a->id => [10, '33.33'], $b->id => [10, '33.33'], $c->id => [10, '33.34']], $this->shares($split));
    }

    public function test_only_former_tenants_past_the_deadline_is_for_decision(): void
    {
        // А е единственият в периода и срокът му е минал: 90,00 € са „за решение“.
        $a = $this->lease($this->flat, '2026-01-10', '2026-11-11');

        $split = $this->split($this->bill($this->meter, '2026-12-05', 90.00), '2027-02');

        $this->assertSame([], $split->shares);
        $this->assertSame('deadline_passed', $split->reason);
        $this->assertSame('90.00', $split->forDecision);
        $this->assertSame([$a->id], array_map(fn ($s) => $s->leaseContractId, $split->takenOver));
    }

    // Обща сметка

    public function test_shared_bill_each_property_by_its_own_tenants(): void
    {
        // Парно 33,33/33,33/33,34, 100,00 € за ноември → части 33,33 / 33,33 / 33,34.
        // Имот 1: един наемател целия месец → 33,33.
        // Имот 2: А 10 дни, Б 20 дни → 33,33 × 10 ÷ 30 = 11,11; Б 22,22.
        // Имот 3: празен → 33,34 „за решение“.
        [$one, $two, $three] = [$this->property(), $this->property(), $this->property()];
        $heating = $this->account('2000045678', [
            ['property_id' => $one->id, 'share' => '33.33'], ['property_id' => $two->id, 'share' => '33.33'], ['property_id' => $three->id, 'share' => '33.34'],
        ]);
        $x = $this->lease($one, '2026-01-10');
        $a = $this->lease($two, '2026-01-10', '2026-11-11');
        $b = $this->lease($two, '2026-11-11');

        $splits = $this->calculator->forBill($this->bill($heating, '2026-12-05', 100.00), CarbonImmutable::parse('2026-12-01'));

        $this->assertSame([$one->id, $two->id, $three->id], array_map(fn ($s) => $s->propertyId, $splits));
        $this->assertSame(['33.33', '33.33', '33.34'], array_map(fn ($s) => $s->amount, $splits));
        $this->assertSame([$x->id => [30, '33.33']], $this->shares($splits[0]));
        $this->assertSame([$a->id => [10, '11.11'], $b->id => [20, '22.22']], $this->shares($splits[1]));
        $this->assertSame([[], 'vacant', '33.34'], [$splits[2]->shares, $splits[2]->reason, $splits[2]->forDecision]);
    }

    // Фиксиран абонат по дни

    private function fixedAccount(Property $property, string $amount, array $overrides = []): UtilityAccount
    {
        return app(UtilityAccountService::class)->create(array_merge([
            'kind' => 'common', 'billing' => 'fixed', 'fixed_amount' => $amount, 'valid_from' => '2026-01-01',
            'properties' => [['property_id' => $property->id]],
        ], $overrides));
    }

    /**
     * Фиксираните такси в известието за месец М (те са за месеца преди М).
     *
     * @return array<int, array{0: int, 1: string}> договор → [дни, сума]
     */
    private function fixedFor(string $month, ?UtilityAccount $account = null): array
    {
        $result = [];
        $notice = CarbonImmutable::parse("{$month}-01");
        foreach ($this->calculator->fixedForMonth($this->staff->company_id, $notice) as $charge) {
            if ($account === null || $charge->utilityAccountId === $account->id) {
                $this->assertSame($month, $charge->month, 'месецът на известието');
                $this->assertSame($notice->subMonth()->format('Y-m'), $charge->periodMonth, 'таксата е за предходния месец');
                $result[$charge->leaseContractId] = [$charge->days, $charge->amount];
            }
        }

        return $result;
    }

    public function test_fixed_account_by_days_like_rent(): void
    {
        // Такса вход 15,00 € на месец. Известието за януари 2027 начислява декември (31 дни).
        // А напуска на 14.12 (13 дни): 15 × 13 ÷ 31 = 6,29. Празно 14–19.12. Б от 20.12 (12 дни): 15 × 12 ÷ 31 = 5,81.
        // Празните дни не се поемат от никого (фиксираната сума не покрива плащане).
        $fee = $this->fixedAccount($this->flat, '15');
        $a = $this->lease($this->flat, '2026-01-10', '2026-12-14');
        $b = $this->lease($this->flat, '2026-12-20');

        $this->assertSame([$a->id => [13, '6.29'], $b->id => [12, '5.81']], $this->fixedFor('2027-01', $fee));
        $this->assertSame([$b->id => [31, '15.00']], $this->fixedFor('2027-02', $fee), 'цял януари в известието за февруари: цялата сума');
        $this->assertSame([$a->id => [30, '15.00']], $this->fixedFor('2026-12', $fee), 'известието за декември: целият ноември на А');
    }

    public function test_lease_from_1_september_gets_the_first_fixed_fee_in_the_october_notice(): void
    {
        // Договор от 01.09: в известието за септември няма фиксирана такса (тя би била за август);
        // първата е в известието за октомври, за целия септември (30 дни): 15,00.
        $fee = $this->fixedAccount($this->flat, '15');
        $a = $this->lease($this->flat, '2026-09-01');

        $this->assertSame([], $this->fixedFor('2026-09', $fee));
        $this->assertSame([$a->id => [30, '15.00']], $this->fixedFor('2026-10', $fee));
    }

    public function test_fixed_account_only_while_it_is_valid(): void
    {
        // Абонатът действа от 10.12 до 20.12 включително (11 дни): в известието за януари 15 × 11 ÷ 31 = 5,32.
        $fee = $this->fixedAccount($this->flat, '15', ['valid_from' => '2026-12-10', 'valid_to' => '2026-12-20']);
        $a = $this->lease($this->flat, '2026-01-10');

        $this->assertSame([$a->id => [11, '5.32']], $this->fixedFor('2027-01', $fee));
        $this->assertSame([], $this->fixedFor('2027-02', $fee));
        $this->assertSame([], $this->fixedFor('2026-12', $fee));
    }

    public function test_fixed_account_on_a_vacant_property_gives_nothing_and_archived_is_skipped(): void
    {
        $this->fixedAccount($this->flat, '15');
        $this->assertSame([], $this->fixedFor('2026-12'));

        $other = $this->property();
        $archived = $this->fixedAccount($other, '20');
        $this->lease($other, '2026-01-10');
        $archived->forceFill(['archived_at' => now()])->saveQuietly();
        $this->assertSame([], $this->fixedFor('2026-12'));
    }

    public function test_shared_fixed_account_is_split_by_the_shares_then_by_days(): void
    {
        // Обща такса 10,00 € на месец, 40/60 → 4,00 и 6,00. Декември, в известието за януари:
        // имот 1 целия месец → 4,00; имот 2 наемател от 17.12 (15 дни) → 6 × 15 ÷ 31 = 2,90.
        [$one, $two] = [$this->property(), $this->property()];
        $fee = $this->fixedAccount($one, '10', ['properties' => [['property_id' => $one->id, 'share' => '40'], ['property_id' => $two->id, 'share' => '60']]]);
        $x = $this->lease($one, '2026-01-10');
        $y = $this->lease($two, '2026-12-17');

        $this->assertSame([$x->id => [31, '4.00'], $y->id => [15, '2.90']], $this->fixedFor('2027-01', $fee));
    }

    public function test_fixed_charges_are_only_for_the_own_company(): void
    {
        $this->fixedAccount($this->flat, '15');
        $mine = $this->lease($this->flat, '2026-01-10');
        $theirs = $this->inOtherCompany(function () {
            $property = $this->property();
            $this->fixedAccount($property, '25');

            return $this->lease($property, '2026-01-10');
        });

        $this->assertSame([$mine->id => [30, '15.00']], $this->fixedFor('2026-12'), 'ноември');
        $this->actingAs($this->otherStaff);
        $other = $this->calculator->fixedForMonth($this->otherStaff->company_id, CarbonImmutable::parse('2026-12-01'));
        $this->assertSame([$theirs->id], array_map(fn ($c) => $c->leaseContractId, $other));
        $this->assertSame([], $this->calculator->fixedForMonth($this->staff->company_id, CarbonImmutable::parse('2026-12-01')), 'служител на друга фирма не вижда нашите');
    }

    public function test_calculator_writes_nothing(): void
    {
        $this->lease($this->flat, '2026-01-10');
        $bill = $this->bill($this->meter, '2026-12-05', 90.00);
        $before = \App\Models\LedgerEntry::query()->count();

        $this->calculator->forBill($bill, CarbonImmutable::parse('2026-12-01'));
        $this->calculator->fixedForMonth($this->staff->company_id, CarbonImmutable::parse('2026-12-01'));

        $this->assertSame($before, \App\Models\LedgerEntry::query()->count());
    }
}
