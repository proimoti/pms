<?php

namespace Tests\Feature\Charges;

use App\Enums\OwnerContractType;
use App\Models\AuditLog;
use App\Models\LeaseContract;
use App\Models\OwnerContract;
use App\Models\Property;
use App\Services\Charges\ChargeCalculator;
use App\Services\Charges\MonthlyCharges;
use Tests\Feature\Contracts\ContractTestCase;

/**
 * Сметачната част на начисленията (PROJECT.md, т. 3.1, 3.6 и т. 2 „Начисления,
 * правила“, „Начисления, уточнения“). Наемът е предварителен, за месец М, по
 * дни: първият ден се брои, последният и датата на прекратяване не. Резултатът
 * са предложени начисления към наемателя (418) и към собственика (408);
 * приходът е разликата. Нищо не се записва.
 */
class MonthlyChargesTest extends ContractTestCase
{
    private function month(string $month): MonthlyCharges
    {
        return app(ChargeCalculator::class)->forMonth($this->staff->company_id, $month);
    }

    /** Договор за наем за един имот от дата, с наем (и по желание договорен наем за първия период). */
    private function lease(Property $property, string $from, string $rent, array $line = [], array $overrides = []): LeaseContract
    {
        return $this->leaseContract([], array_merge([
            'signed_on' => $from,
            'lines' => [array_merge(['property_id' => $property->id, 'rent' => $rent], $line)],
        ], $overrides));
    }

    private function ownContract(Property $property): OwnerContract
    {
        return $this->contracts->create([
            'type' => OwnerContractType::Own, 'signed_on' => '2026-01-01',
            'lines' => [['property_id' => $property->id]],
        ]);
    }

    // т. 3.1: дни

    public function test_document_example_tenant_leaves_on_14_november(): void
    {
        $property = $this->property();
        $this->percentContract([$property]);
        $lease = $this->lease($property, '2026-10-01', '600');
        $lease->update(['terminated_on' => '2026-11-14']);

        $rent = $this->month('2026-11')->rent($property);

        $this->assertSame('260.00', $rent->amount);
        $this->assertSame(13, $rent->days);
        $this->assertSame('2026-11', $rent->month);
        $this->assertSame($lease->id, $rent->leaseContractId);
        $this->assertSame($lease->tenant_id, $rent->tenantId);
    }

    public function test_first_day_counts_and_whole_month_is_the_full_rent(): void
    {
        $property = $this->property();
        $this->percentContract([$property]);
        $this->lease($property, '2026-12-16', '600');

        $this->assertSame('309.68', $this->month('2026-12')->rent($property)->amount); // 600 × 16 ÷ 31
        $this->assertSame('600.00', $this->month('2027-01')->rent($property)->amount);
        $this->assertNull($this->month('2026-11')->rent($property));
    }

    public function test_termination_on_the_first_gives_nothing_for_that_month(): void
    {
        $property = $this->property();
        $this->percentContract([$property]);
        $this->lease($property, '2026-10-01', '600')->update(['terminated_on' => '2026-12-01']);

        $this->assertSame('600.00', $this->month('2026-11')->rent($property)->amount);
        $this->assertNull($this->month('2026-12')->rent($property));
    }

    // т. 3.6: примерите с наем 500 € за цял месец

    public function test_full_month_500_with_10_percent(): void
    {
        $property = $this->property();
        $management = $this->percentContract([$property]);
        $this->lease($property, '2026-02-01', '500');

        $month = $this->month('2026-12');

        $this->assertSame('500.00', $month->rent($property)->amount);
        $owner = $month->ownerPayable($property);
        $this->assertSame('450.00', $owner->amount);
        $this->assertSame($management->id, $owner->ownerContractId);
        $this->assertSame($management->owner_id, $owner->ownerId);
        $this->assertSame('50.00', $month->revenue($property));
    }

    public function test_full_month_500_with_fixed_460(): void
    {
        $property = $this->property();
        $this->fixedContract([$property]);
        $this->lease($property, '2026-02-01', '500');

        $month = $this->month('2026-12');

        $this->assertSame('460.00', $month->ownerPayable($property)->amount);
        $this->assertSame('40.00', $month->revenue($property));
    }

    public function test_full_month_500_own_property(): void
    {
        $property = $this->property();
        $this->ownContract($property);
        $this->lease($property, '2026-02-01', '500');

        $month = $this->month('2026-12');

        $this->assertNull($month->ownerPayable($property));
        $this->assertSame('500.00', $month->revenue($property));
    }

    public function test_free_property_with_fixed_460(): void
    {
        $property = $this->property();
        $this->fixedContract([$property]);

        $month = $this->month('2026-12');

        $this->assertNull($month->rent($property));
        $this->assertSame('460.00', $month->ownerPayable($property)->amount);
        $this->assertSame('-460.00', $month->revenue($property));
    }

    public function test_free_property_with_percent_or_own_gives_nothing(): void
    {
        $percent = $this->property();
        $own = $this->property();
        $this->percentContract([$percent]);
        $this->ownContract($own);

        $month = $this->month('2026-12');

        $this->assertSame([], $month->charges());
        $this->assertSame('0.00', $month->revenue($percent));
    }

    // т. 2 „Собственикът“: 15 от 31 дни, прекратяване 16.12

    public function test_document_example_15_of_31_days_with_10_percent(): void
    {
        $property = $this->property();
        $this->percentContract([$property]);
        $this->lease($property, '2026-12-01', '500')->update(['terminated_on' => '2026-12-16']);

        $month = $this->month('2026-12');

        $this->assertSame('241.94', $month->rent($property)->amount);
        $this->assertSame(15, $month->rent($property)->days);
        $this->assertSame('217.75', $month->ownerPayable($property)->amount);
        $this->assertSame('24.19', $month->revenue($property));
    }

    public function test_document_example_15_of_31_days_with_fixed_460(): void
    {
        $property = $this->property();
        $this->fixedContract([$property]);
        $this->lease($property, '2026-12-01', '500')->update(['terminated_on' => '2026-12-16']);

        $month = $this->month('2026-12');

        $this->assertSame('241.94', $month->rent($property)->amount);
        $this->assertSame('460.00', $month->ownerPayable($property)->amount);
        $this->assertSame('-218.06', $month->revenue($property));
    }

    public function test_document_example_15_of_31_days_own(): void
    {
        $property = $this->property();
        $this->ownContract($property);
        $this->lease($property, '2026-12-01', '500')->update(['terminated_on' => '2026-12-16']);

        $this->assertSame('241.94', $this->month('2026-12')->revenue($property));
    }

    // Фиксираната сума: по дните на договора за управление, независимо от наема

    public function test_fixed_amount_follows_the_days_of_the_management_contract(): void
    {
        $property = $this->property();
        $this->fixedContract([$property], ['signed_on' => '2026-12-10']);

        $this->assertSame('326.45', $this->month('2026-12')->ownerPayable($property)->amount); // 460 × 22 ÷ 31
    }

    // Промяна в средата на месеца: по части, сборува се, закръгля се веднъж

    public function test_rent_change_in_the_middle_of_the_month_is_rounded_once(): void
    {
        $property = $this->property();
        $this->percentContract([$property]);
        $lease = $this->lease($property, '2026-02-01', '100');
        $this->leases->changeRent($this->leases->addAnnex($lease, 1, '2026-12-01'), $property, '2026-12-16', '200');

        // (100 × 15 + 200 × 16) ÷ 31 = 151,6129 → 151,61 (по части поотделно би било 151,62)
        $this->assertSame('151.61', $this->month('2026-12')->rent($property)->amount);
    }

    public function test_commission_change_in_the_middle_of_the_month(): void
    {
        $property = $this->property();
        $management = $this->percentContract([$property]);
        $this->contracts->changeCommission($this->contracts->addAnnex($management, 1, '2026-12-01'), '2026-12-16', '20');
        $this->lease($property, '2026-02-01', '500');

        $month = $this->month('2026-12');

        // Комисиона 500 × (15 × 10% + 16 × 20%) ÷ 31 = 75,806 → 75,81
        $this->assertSame('424.19', $month->ownerPayable($property)->amount);
        $this->assertSame('75.81', $month->revenue($property));
    }

    public function test_two_tenants_in_one_month_and_one_owner(): void
    {
        $property = $this->property();
        $this->percentContract([$property]);
        $first = $this->lease($property, '2026-02-01', '500');
        $first->update(['terminated_on' => '2026-12-16']);
        $second = $this->lease($property, '2026-12-16', '600');

        $month = $this->month('2026-12');

        $this->assertSame('241.94', $month->rent($property, $first)->amount);
        $this->assertSame('309.68', $month->rent($property, $second)->amount);
        // Комисиона (500 × 15 + 600 × 16) × 10% ÷ 31 = 55,16; собственик 551,62 − 55,16
        $this->assertSame('496.46', $month->ownerPayable($property)->amount);
        $this->assertSame('55.16', $month->revenue($property));
    }

    // Договорен наем за първия период

    public function test_agreed_first_period_rent_replaces_the_computed_one(): void
    {
        $property = $this->property();
        $this->percentContract([$property]);
        $this->lease($property, '2026-12-16', '600', ['first_period_rent' => '300']);

        $month = $this->month('2026-12');
        $rent = $month->rent($property);

        $this->assertSame('300.00', $rent->amount);
        $this->assertSame('309.68', $rent->computedAmount);
        // При процент комисионата е от договорената сума.
        $this->assertSame('270.00', $month->ownerPayable($property)->amount);
        $this->assertSame('600.00', $this->month('2027-01')->rent($property)->amount);
        $this->assertNull($this->month('2027-01')->rent($property)->computedAmount);
    }

    public function test_agreed_first_period_rent_is_ignored_when_the_first_month_is_full(): void
    {
        $property = $this->property();
        $this->percentContract([$property]);
        $this->lease($property, '2026-12-01', '600', ['first_period_rent' => '550']);

        $this->assertSame('600.00', $this->month('2026-12')->rent($property)->amount);
    }

    public function test_agreed_first_period_rent_does_not_change_a_fixed_owner_amount(): void
    {
        $property = $this->property();
        $this->fixedContract([$property]);
        $this->lease($property, '2026-12-16', '600', ['first_period_rent' => '300']);

        $month = $this->month('2026-12');

        $this->assertSame('460.00', $month->ownerPayable($property)->amount);
        $this->assertSame('-160.00', $month->revenue($property));
    }

    public function test_agreed_first_period_rent_is_zero_or_more(): void
    {
        $property = $this->property();

        $this->assertRejected(fn () => $this->lease($property, '2026-12-16', '600', ['first_period_rent' => '-1']), 'lines');
        $this->assertSame(0, LeaseContract::query()->count());
    }

    // Без договор за управление: „за решение“

    public function test_a_day_without_management_contract_puts_the_rent_for_decision(): void
    {
        $property = $this->property();
        $this->percentContract([$property], ['signed_on' => '2026-12-05']);
        $lease = $this->lease($property, '2026-02-01', '600');

        $month = $this->month('2026-12');

        $this->assertNull($month->rent($property));
        $this->assertNull($month->ownerPayable($property));
        $this->assertCount(1, $month->forDecision());
        $decision = $month->forDecision()[0];
        $this->assertSame($property->id, $decision->propertyId);
        $this->assertSame($lease->id, $decision->leaseContractId);
        $this->assertSame(4, $decision->daysWithoutManagement);

        // Следващият месец е покрит изцяло.
        $this->assertSame('600.00', $this->month('2027-01')->rent($property)->amount);
        $this->assertSame([], $this->month('2027-01')->forDecision());
    }

    public function test_fixed_owner_amount_stays_when_the_rent_is_for_decision(): void
    {
        $property = $this->property();
        $this->fixedContract([$property], ['signed_on' => '2026-12-05']);
        $this->lease($property, '2026-02-01', '600');

        $month = $this->month('2026-12');

        $this->assertNull($month->rent($property));
        $this->assertSame('400.65', $month->ownerPayable($property)->amount); // 460 × 27 ÷ 31
        $this->assertCount(1, $month->forDecision());
    }

    // Какво не се начислява

    public function test_archived_contracts_and_other_companies_are_not_charged(): void
    {
        $archived = $this->property();
        $this->percentContract([$archived]);
        $this->lease($archived, '2026-02-01', '600')->archive();

        $foreign = $this->inOtherCompany(function () {
            $property = $this->property();
            $this->percentContract([$property]);
            $this->lease($property, '2026-02-01', '600');

            return $property;
        });

        $month = $this->month('2026-12');

        $this->assertNull($month->rent($archived));
        $this->assertSame([], array_filter($month->charges(), fn ($c) => $c->propertyId === $foreign->id));
    }

    public function test_zero_rent_for_an_accompanying_garage(): void
    {
        $flat = $this->property();
        $garage = $this->property();
        $this->percentContract([$flat, $garage]);
        $this->leaseContract([], ['signed_on' => '2026-02-01', 'lines' => [
            ['property_id' => $flat->id, 'rent' => '600'],
            ['property_id' => $garage->id, 'rent' => '0'],
        ]]);

        $month = $this->month('2026-12');

        $this->assertSame('600.00', $month->rent($flat)->amount);
        $this->assertSame('0.00', $month->rent($garage)->amount);
        $this->assertSame('0.00', $month->revenue($garage));
    }

    public function test_calculation_writes_nothing(): void
    {
        $property = $this->property();
        $this->percentContract([$property]);
        $this->lease($property, '2026-02-01', '600');
        $before = AuditLog::query()->withoutGlobalScopes()->count();

        $charges = $this->month('2026-12')->charges();

        $this->assertCount(2, $charges);
        $this->assertSame(['rent', 'owner'], array_map(fn ($c) => $c->type, $charges));
        $this->assertSame($before, AuditLog::query()->withoutGlobalScopes()->count());
    }

    // Свободни имоти (PROJECT.md, „Начисления, съдържание“, 21.11.2026)

    public function test_free_properties_with_a_management_contract(): void
    {
        $fixed = $this->property();
        $this->fixedContract([$fixed]);                       // свободен целия месец
        $partly = $this->property();
        $this->percentContract([$partly]);
        $this->lease($partly, '2026-12-17', '600');           // свободен 16 дни
        $own = $this->property();
        $this->ownContract($own);                             // собствен, свободен
        $rented = $this->property();
        $this->percentContract([$rented]);
        $this->lease($rented, '2026-02-01', '600');           // нает целия месец: не е свободен
        $this->property();                                    // без договор за управление: не е тук

        $free = app(ChargeCalculator::class)->vacancies($this->staff->company_id, '2026-12');

        $this->assertSame([$fixed->id, $own->id, $partly->id], array_map(fn ($v) => $v->propertyId, $free));
        $this->assertSame([31, 31, 16], array_map(fn ($v) => $v->freeDays, $free));
        $this->assertSame([true, true, false], array_map(fn ($v) => $v->wholeMonth, $free));
        $this->assertSame('460.00', $free[0]->ownerAmount);   // фиксирана сума за свободните дни
        $this->assertNull($free[1]->ownerAmount);             // собствен: нищо към собственик
        $this->assertNull($free[2]->ownerAmount);             // процент: нищо за свободните дни
        $this->assertSame('own', $free[1]->calculation);
    }

    public function test_fixed_amount_for_free_days_only(): void
    {
        $property = $this->property();
        $this->fixedContract([$property]);
        $this->lease($property, '2026-12-11', '500');         // свободен 10 дни

        $free = app(ChargeCalculator::class)->vacancies($this->staff->company_id, '2026-12');

        $this->assertCount(1, $free);
        $this->assertSame(10, $free[0]->freeDays);
        $this->assertSame('148.39', $free[0]->ownerAmount);   // 460 × 10 ÷ 31
    }

    public function test_month_must_be_year_and_month(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->month('2026-13');
    }
}
