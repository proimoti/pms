<?php

namespace Tests\Feature\Contracts;

use App\Models\LeaseContractExtension;
use App\Models\OwnerContractExtension;
use App\Models\User;
use App\Models\UserProperty;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Удължаване с анекс (PROJECT.md, т. 2 „Удължаване с анекс“): анексът сменя
 * срока (дата край) на договор за управление или за наем. Старият срок остава
 * в историята с анекса, който го е сменил. Срокът не влияе на сумите.
 */
class ContractExtensionTest extends ContractTestCase
{
    public function test_annex_extends_a_management_contract_and_keeps_the_old_term(): void
    {
        $contract = $this->percentContract([$this->property()], ['end_on' => '2026-12-31']);
        $first = $this->contracts->addAnnex($contract, 1, '2026-11-15');
        $second = $this->contracts->addAnnex($contract, 2, '2027-11-15');

        $this->contracts->extendTerm($first, '2027-12-31');
        $this->contracts->extendTerm($second, '2028-06-30');

        $this->assertSame('2028-06-30', $contract->fresh()->end_on->toDateString());

        $history = $contract->extensions()->orderBy('end_on')->get();
        $this->assertSame(
            [['2026-12-31', '2027-12-31', $first->id], ['2027-12-31', '2028-06-30', $second->id]],
            $history->map(fn ($e) => [$e->previous_end_on->toDateString(), $e->end_on->toDateString(), $e->annex_id])->all(),
        );
    }

    public function test_annex_extends_a_lease(): void
    {
        $contract = $this->leaseContract([$this->property()], ['end_on' => '2026-12-31']);
        $annex = $this->leases->addAnnex($contract, 1, '2026-11-15');

        $this->leases->extendTerm($annex, '2027-12-31');

        $this->assertSame('2027-12-31', $contract->fresh()->end_on->toDateString());
        $extension = $contract->extensions()->sole();
        $this->assertSame('2026-12-31', $extension->previous_end_on->toDateString());
        $this->assertSame($annex->id, $extension->annex_id);
    }

    public function test_the_term_does_not_change_amounts_or_activity(): void
    {
        $property = $this->property();
        $contract = $this->leaseContract([$property], ['end_on' => '2026-06-30']);
        $annex = $this->leases->addAnnex($contract, 1, '2026-06-01');

        $this->leases->extendTerm($annex, '2027-06-30');

        $this->assertSame(1, $contract->lines()->count());
        $this->assertSame('600.00', $contract->lineOn($property, '2026-08-01')->rent);
        // Без прекратяване договорът действа и след срока, както преди.
        $this->assertTrue($contract->fresh()->isActiveOn('2027-09-01'));
    }

    public function test_new_term_must_be_later_than_the_current_one(): void
    {
        $contract = $this->leaseContract([$this->property()], ['end_on' => '2026-12-31']);
        $annex = $this->leases->addAnnex($contract, 1, '2026-11-15');

        $this->assertRejected(fn () => $this->leases->extendTerm($annex, '2026-12-31'), 'end_on');
        $this->assertRejected(fn () => $this->leases->extendTerm($annex, '2026-10-01'), 'end_on');
        $this->assertRejected(fn () => $this->leases->extendTerm($annex, null), 'end_on');
        $this->assertSame('2026-12-31', $contract->fresh()->end_on->toDateString());
        $this->assertSame(0, LeaseContractExtension::query()->count());
    }

    public function test_contract_without_a_term_cannot_be_extended(): void
    {
        $contract = $this->percentContract([$this->property()]);
        $annex = $this->contracts->addAnnex($contract, 1, '2026-11-15');

        $this->assertRejected(fn () => $this->contracts->extendTerm($annex, '2027-12-31'), 'end_on');
    }

    public function test_terminated_contract_cannot_be_extended(): void
    {
        $contract = $this->percentContract([$this->property()], ['end_on' => '2026-12-31']);
        $contract->update(['terminated_on' => '2026-10-01']);
        $annex = $this->contracts->addAnnex($contract->fresh(), 1, '2026-09-15');

        $this->assertRejected(fn () => $this->contracts->extendTerm($annex, '2027-12-31'), 'end_on');
    }

    public function test_one_annex_extends_only_once(): void
    {
        $contract = $this->leaseContract([$this->property()], ['end_on' => '2026-12-31']);
        $annex = $this->leases->addAnnex($contract, 1, '2026-11-15');
        $this->leases->extendTerm($annex, '2027-12-31');

        $this->assertRejected(fn () => $this->leases->extendTerm($annex, '2028-12-31'), 'end_on');
    }

    public function test_end_date_is_corrected_directly_only_until_the_first_extension(): void
    {
        $owner = $this->percentContract([$this->property()], ['end_on' => '2026-12-31']);
        $lease = $this->leaseContract([$this->property()], ['end_on' => '2026-12-31']);

        // Печатна грешка, докато няма удължаване: направо.
        $owner->update(['end_on' => '2026-11-30']);
        $lease->update(['end_on' => '2026-11-30']);
        $this->assertSame('2026-11-30', $owner->fresh()->end_on->toDateString());

        $this->contracts->extendTerm($this->contracts->addAnnex($owner, 1, '2026-11-15'), '2027-12-31');
        $this->leases->extendTerm($this->leases->addAnnex($lease, 1, '2026-11-15'), '2027-12-31');

        $this->assertRejected(fn () => $owner->fresh()->update(['end_on' => '2028-01-31']), 'end_on');
        $this->assertRejected(fn () => $lease->fresh()->update(['end_on' => null]), 'end_on');
        $this->assertSame('2027-12-31', $lease->fresh()->end_on->toDateString());
        // Другите информационни полета се поправят и след удължаване.
        $lease->fresh()->update(['note' => 'Измислена поправка']);
        $this->assertSame('Измислена поправка', $lease->fresh()->note);
    }

    public function test_database_refuses_an_extension_that_is_not_later(): void
    {
        $contract = $this->leaseContract([$this->property()], ['end_on' => '2026-12-31']);
        $annex = $this->leases->addAnnex($contract, 1, '2026-11-15');

        try {
            DB::table('lease_contract_extensions')->insert([
                'company_id' => $contract->company_id, 'lease_contract_id' => $contract->id, 'annex_id' => $annex->id,
                'previous_end_on' => '2026-12-31', 'end_on' => '2026-12-31',
            ]);
            $this->fail('Базата прие срок, който не е по-късен.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('lease_contract_extensions_term_check', $e->getMessage());
        }
    }

    public function test_extensions_are_never_deleted_and_hidden_from_other_companies_and_unassigned_staff(): void
    {
        $contract = $this->percentContract([$this->property()], ['end_on' => '2026-12-31']);
        $this->contracts->extendTerm($this->contracts->addAnnex($contract, 1, '2026-11-15'), '2027-12-31');

        $assigned = User::factory()->assignedPropertiesOnly()->create(['company_id' => $this->staff->company_id]);
        UserProperty::query()->create(['user_id' => $assigned->id, 'property_id' => $this->property()->id]);

        $this->actingAs($this->otherStaff);
        $this->assertSame(0, OwnerContractExtension::query()->count());

        $this->actingAs($assigned);
        $this->assertSame(0, OwnerContractExtension::query()->count());

        $this->actingAs($this->staff);
        $this->expectException(LogicException::class);
        OwnerContractExtension::query()->first()->delete();
    }
}
