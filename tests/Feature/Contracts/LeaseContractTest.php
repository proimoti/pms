<?php

namespace Tests\Feature\Contracts;

use App\Models\AuditLog;
use App\Models\LeaseContract;
use App\Models\LeaseContractAnnex;
use App\Models\LeaseContractLine;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Договор за наем (PROJECT.md, т. 2 „Договори“, „Договори, уточнения“,
 * „Дати на договорите“): заглавие с наемател и редове по имот с наем и
 * договорен депозит. Един имот не може да е в два договора за наем за един период.
 */
class LeaseContractTest extends ContractTestCase
{
    public function test_lease_is_created_with_a_tenant_and_lines_with_rent_and_deposit(): void
    {
        $a = $this->property();
        $b = $this->property();
        $tenant = $this->tenant();

        $contract = $this->leaseContract([$a], [
            'tenant_id' => $tenant->id,
            'end_on' => '2027-01-09',
            'note' => 'Измислена бележка',
            'lines' => [
                ['property_id' => $a->id, 'rent' => '600.00', 'deposit' => '1200.00'],
                ['property_id' => $b->id, 'rent' => '450,50', 'deposit' => '0'],
            ],
        ]);

        $this->assertSame($tenant->id, $contract->tenant_id);
        $this->assertSame('2026-01-10', $contract->signed_on->toDateString());
        $this->assertSame('2027-01-09', $contract->end_on->toDateString());
        $this->assertSame('600.00', $contract->lineOn($a, '2026-02-01')->rent);
        $this->assertSame('1200.00', $contract->lineOn($a, '2026-02-01')->deposit);
        $this->assertSame('450.50', $contract->lineOn($b, '2026-02-01')->rent);
        $this->assertSame('0.00', $contract->lineOn($b, '2026-02-01')->deposit);
    }

    public function test_tenant_is_required_and_must_be_of_the_company_and_not_archived(): void
    {
        $this->assertRejected(fn () => $this->leaseContract([$this->property()], ['tenant_id' => null]), 'tenant_id');

        $foreign = $this->inOtherCompany(fn () => $this->tenant());
        $this->assertRejected(fn () => $this->leaseContract([$this->property()], ['tenant_id' => $foreign->id]), 'tenant_id');

        $archived = $this->tenant();
        $archived->archive();
        $this->assertRejected(fn () => $this->leaseContract([$this->property()], ['tenant_id' => $archived->id]), 'tenant_id');

        $this->assertSame(0, LeaseContract::query()->count());
    }

    public function test_every_line_needs_a_rent_of_zero_or_more(): void
    {
        $property = $this->property();

        foreach ([null, '', '-5', '-0.01', 'abc', '12.345'] as $rent) {
            $this->assertRejected(fn () => $this->leaseContract([], [
                'lines' => [['property_id' => $property->id, 'rent' => $rent]],
            ]), 'lines');
        }

        $this->assertSame(0, LeaseContract::query()->count());
    }

    public function test_deposit_is_zero_or_more_and_empty_means_zero(): void
    {
        $property = $this->property();

        $this->assertRejected(fn () => $this->leaseContract([], [
            'lines' => [['property_id' => $property->id, 'rent' => '600', 'deposit' => '-1']],
        ]), 'lines');

        $contract = $this->leaseContract([], [
            'lines' => [['property_id' => $property->id, 'rent' => '600']],
        ]);

        $this->assertSame('0.00', $contract->lineOn($property, '2026-02-01')->deposit);
    }

    public function test_at_least_one_property_is_required(): void
    {
        $this->assertRejected(fn () => $this->leaseContract([]), 'lines');
    }

    public function test_property_of_another_company_or_archived_is_rejected(): void
    {
        $foreign = $this->inOtherCompany(fn () => $this->property());
        $this->assertRejected(fn () => $this->leaseContract([$foreign]), 'lines');

        $archived = $this->property();
        $archived->archive();
        $this->assertRejected(fn () => $this->leaseContract([$archived]), 'lines');
    }

    public function test_lease_numbers_are_the_largest_plus_1_separately_from_management_contracts(): void
    {
        $this->percentContract([$this->property()], ['number' => 70]);

        $this->assertSame(1, $this->leaseContract([$this->property()])->number);
        $this->assertSame(40, $this->leaseContract([$this->property()], ['number' => 40])->number);
        $this->assertSame(41, $this->leaseContract([$this->property()])->number);
        // Същият номер като договор за управление е позволен: поредиците са отделни.
        $this->assertSame(70, $this->leaseContract([$this->property()], ['number' => 70])->number);

        $this->assertRejected(fn () => $this->leaseContract([$this->property()], ['number' => 40]), 'number');
    }

    // Дати и „активен“

    public function test_line_starts_on_the_contract_date_unless_given(): void
    {
        $a = $this->property();
        $b = $this->property();

        $contract = $this->leaseContract([], [
            'lines' => [
                ['property_id' => $a->id, 'rent' => '600'],
                ['property_id' => $b->id, 'rent' => '500', 'start_on' => '2026-02-01'],
            ],
        ]);

        $this->assertSame('2026-01-10', $contract->lineOn($a, '2026-01-10')->valid_from->toDateString());
        $this->assertNull($contract->lineOn($b, '2026-01-31'));
        $this->assertSame('2026-02-01', $contract->lineOn($b, '2026-02-01')->valid_from->toDateString());
        $this->assertSame('2026-01-10', $contract->startsOn()->toDateString());
    }

    public function test_active_is_computed_and_the_termination_day_does_not_count(): void
    {
        $contract = $this->leaseContract([$this->property()], ['end_on' => '2026-06-30']);

        $this->assertFalse($contract->isActiveOn('2026-01-09'));
        $this->assertTrue($contract->isActiveOn('2026-01-10'));
        $this->assertTrue($contract->isActiveOn('2026-09-01'));

        $contract->update(['terminated_on' => '2026-10-15']);

        $this->assertTrue($contract->fresh()->isActiveOn('2026-10-14'));
        $this->assertFalse($contract->fresh()->isActiveOn('2026-10-15'));
        $this->assertFalse(\Schema::hasColumn('lease_contracts', 'is_active'));
    }

    public function test_termination_must_be_after_the_start(): void
    {
        $contract = $this->leaseContract([$this->property()]);

        $this->assertRejected(fn () => $contract->update(['terminated_on' => '2026-01-10']), 'terminated_on');
    }

    public function test_archived_lease_is_not_active(): void
    {
        $contract = $this->leaseContract([$this->property()]);
        $contract->archive();

        $this->assertFalse($contract->fresh()->isActiveOn('2026-03-01'));
    }

    // Защита: един имот в един договор за наем за един период

    public function test_property_cannot_be_in_two_leases_at_the_same_time(): void
    {
        $property = $this->property();
        $first = $this->leaseContract([$property]);

        try {
            $this->leaseContract([$property], ['signed_on' => '2026-05-01']);
            $this->fail('Имотът влезе в два договора за наем.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ["Имот № {$property->number} вече е в договор за наем № {$first->number} за този период."],
                $e->errors()['lines'],
            );
        }

        $this->assertSame(1, LeaseContract::query()->count());
    }

    public function test_lease_and_management_contract_for_the_same_property_do_not_conflict(): void
    {
        $property = $this->property();
        $this->percentContract([$property]);

        $this->assertTrue($this->leaseContract([$property])->exists);
    }

    public function test_new_lease_may_start_on_the_day_the_old_one_is_terminated(): void
    {
        $property = $this->property();
        $first = $this->leaseContract([$property]);
        $first->update(['terminated_on' => '2026-06-01']);

        $this->assertTrue($this->leaseContract([$property], ['signed_on' => '2026-06-01'])->exists);
        $this->assertRejected(fn () => $this->leaseContract([$this->property()], [
            'signed_on' => '2026-05-01',
            'lines' => [['property_id' => $property->id, 'rent' => '600']],
        ]), 'lines');
    }

    public function test_earlier_lease_cannot_run_into_a_later_one(): void
    {
        $property = $this->property();
        $this->leaseContract([$property], ['signed_on' => '2026-06-01']);

        // Започва преди него и няма край: застъпва.
        $this->assertRejected(fn () => $this->leaseContract([$property], ['signed_on' => '2026-03-01']), 'lines');
    }

    public function test_cancelling_a_termination_cannot_create_an_overlap(): void
    {
        $property = $this->property();
        $first = $this->leaseContract([$property]);
        $first->update(['terminated_on' => '2026-06-01']);
        $this->leaseContract([$property], ['signed_on' => '2026-06-01']);

        $this->assertRejected(fn () => $first->fresh()->update(['terminated_on' => null]), 'lines');
        $this->assertRejected(fn () => $first->fresh()->update(['terminated_on' => '2026-06-02']), 'lines');
        $this->assertSame('2026-06-01', $first->fresh()->terminated_on->toDateString());
    }

    public function test_same_property_cannot_be_twice_in_one_lease(): void
    {
        $property = $this->property();

        $this->assertRejected(fn () => $this->leaseContract([$property, $property]), 'lines');
    }

    public function test_archived_lease_does_not_count_and_cannot_come_back_over_another(): void
    {
        $property = $this->property();
        $wrong = $this->leaseContract([$property]);
        $wrong->archive();

        $right = $this->leaseContract([$property]);

        $this->assertTrue($right->exists);
        $this->assertRejected(fn () => $wrong->fresh()->restoreFromArchive(), 'lines');
        $this->assertTrue($wrong->fresh()->isArchived());
    }

    // Права, фирми, триене, журнал

    public function test_read_only_staff_cannot_create_a_lease(): void
    {
        $property = $this->property();
        $tenant = $this->tenant();
        $this->actingAs(User::factory()->readOnly()->create(['company_id' => $this->staff->company_id]));

        $this->expectException(AuthorizationException::class);

        $this->leaseContract([$property], ['tenant_id' => $tenant->id]);
    }

    public function test_another_company_sees_none_of_the_lease_tables(): void
    {
        $property = $this->property();
        $contract = $this->leaseContract([$property]);
        $annex = $this->leases->addAnnex($contract, 1, '2026-05-20');
        $this->leases->changeRent($annex, $property, '2026-06-01', '650.00');

        $this->actingAs($this->otherStaff);

        $this->assertSame(0, LeaseContract::query()->count());
        $this->assertSame(0, LeaseContractLine::query()->count());
        $this->assertSame(0, LeaseContractAnnex::query()->count());
    }

    public function test_leases_and_lines_are_never_deleted(): void
    {
        $contract = $this->leaseContract([$this->property()]);

        try {
            $contract->lines()->first()->delete();
            $this->fail('Редът беше изтрит.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $contract->delete();
    }

    public function test_creation_is_logged_in_the_journal(): void
    {
        $contract = $this->leaseContract([$this->property()]);

        $this->assertTrue(AuditLog::query()
            ->where('auditable_type', 'lease_contracts')->where('auditable_id', $contract->id)
            ->where('event', 'created')->exists());
        $this->assertTrue(AuditLog::query()->where('auditable_type', 'lease_contract_lines')->exists());
    }
}
