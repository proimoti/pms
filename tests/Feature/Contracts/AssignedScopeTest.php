<?php

namespace Tests\Feature\Contracts;

use App\Models\Beneficiary;
use App\Models\LeaseContract;
use App\Models\LeaseContractAnnex;
use App\Models\LeaseContractLine;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\OwnerContractAnnex;
use App\Models\OwnerContractCommission;
use App\Models\OwnerContractLine;
use App\Models\OwnerContractPayee;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserProperty;

/**
 * Обхват „само прикачените имоти“ при договорите (PROJECT.md, т. 2 „Регистър“
 * и „Договори, уточнения“): служителят вижда целия договор, ако поне един имот
 * в него е прикачен към него; собствениците, бенефициентите и наемателите —
 * през тези договори.
 */
class AssignedScopeTest extends ContractTestCase
{
    private User $assigned;

    private Property $mine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assigned = User::factory()->assignedPropertiesOnly()->create(['company_id' => $this->staff->company_id]);
        $this->mine = $this->property();
        UserProperty::query()->create(['user_id' => $this->assigned->id, 'property_id' => $this->mine->id]);
    }

    public function test_sees_the_whole_management_contract_with_an_assigned_property_and_nothing_else(): void
    {
        $notMine = $this->property();
        $visible = $this->percentContract([$this->mine, $notMine]);
        $annex = $this->contracts->addAnnex($visible, 1, '2026-05-20');
        $this->contracts->changeCommission($annex, '2026-06-01', '12.00');
        $hidden = $this->percentContract([$this->property()]);
        $this->contracts->addAnnex($hidden, 1, '2026-05-20');

        $this->actingAs($this->assigned);

        $this->assertSame([$visible->id], OwnerContract::query()->pluck('id')->all());
        // Целият договор: и редът на неприкачения имот.
        $this->assertEqualsCanonicalizing([$this->mine->id, $notMine->id], OwnerContractLine::query()->pluck('property_id')->all());
        $this->assertSame([$visible->id], OwnerContractAnnex::query()->pluck('owner_contract_id')->all());
        $this->assertSame([$visible->id, $visible->id], OwnerContractCommission::query()->pluck('owner_contract_id')->all());
        $this->assertSame([$visible->id], OwnerContractPayee::query()->pluck('owner_contract_id')->all());
        // Самият неприкачен имот остава скрит.
        $this->assertSame([$this->mine->id], Property::query()->pluck('id')->all());
    }

    public function test_sees_the_whole_lease_with_an_assigned_property_and_nothing_else(): void
    {
        $notMine = $this->property();
        $visible = $this->leaseContract([$this->mine, $notMine]);
        $this->leases->addAnnex($visible, 1, '2026-05-20');
        $hidden = $this->leaseContract([$this->property()]);
        $this->leases->addAnnex($hidden, 1, '2026-05-20');

        $this->actingAs($this->assigned);

        $this->assertSame([$visible->id], LeaseContract::query()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$this->mine->id, $notMine->id], LeaseContractLine::query()->pluck('property_id')->all());
        $this->assertSame([$visible->id], LeaseContractAnnex::query()->pluck('lease_contract_id')->all());
    }

    public function test_sees_owners_and_beneficiaries_through_visible_management_contracts(): void
    {
        $owner = $this->owner();
        $payee = Beneficiary::factory()->create(['name' => 'Измислен Получател']);
        $this->percentContract([$this->mine], ['owner_id' => $owner->id, 'beneficiary_id' => $payee->id]);
        $otherOwner = $this->owner();
        $this->percentContract([$this->property()], ['owner_id' => $otherOwner->id]);
        Beneficiary::factory()->create(['name' => 'Друг Измислен Получател']);

        $this->actingAs($this->assigned);

        $this->assertSame([$owner->id], Owner::query()->pluck('id')->all());
        $this->assertSame([$payee->id], Beneficiary::query()->pluck('id')->all());
    }

    public function test_sees_tenants_through_visible_leases(): void
    {
        $tenant = $this->tenant();
        $this->leaseContract([$this->mine], ['tenant_id' => $tenant->id]);
        $this->leaseContract([$this->property()]);
        $this->tenant();

        $this->actingAs($this->assigned);

        $this->assertSame([$tenant->id], Tenant::query()->pluck('id')->all());
    }

    public function test_owner_of_a_lease_property_is_not_visible_without_a_management_contract(): void
    {
        $this->leaseContract([$this->mine]);
        $this->owner();

        $this->actingAs($this->assigned);

        $this->assertSame(0, Owner::query()->count());
        $this->assertSame(1, Tenant::query()->count());
    }

    public function test_property_removed_by_annex_still_shows_the_contract_and_its_history(): void
    {
        $contract = $this->leaseContract([$this->mine, $this->property()]);
        $annex = $this->leases->addAnnex($contract, 1, '2026-03-15');
        $this->leases->removeProperty($annex, $this->mine, '2026-04-01');

        $this->actingAs($this->assigned);

        $this->assertSame([$contract->id], LeaseContract::query()->pluck('id')->all());
        $this->assertSame(1, Tenant::query()->count());
    }

    public function test_staff_with_all_properties_still_sees_everything(): void
    {
        $this->percentContract([$this->mine]);
        $this->percentContract([$this->property()]);
        $this->leaseContract([$this->property()]);

        $this->assertSame(2, OwnerContract::query()->count());
        $this->assertSame(2, Owner::query()->count());
        $this->assertSame(1, LeaseContract::query()->count());
        $this->assertSame(1, Tenant::query()->count());
    }

    public function test_assigned_staff_can_put_only_assigned_properties_in_a_contract(): void
    {
        $notMine = $this->property();
        $tenant = $this->tenant();

        $this->actingAs($this->assigned);

        $this->assertRejected(fn () => $this->leaseContract([$notMine], ['tenant_id' => $tenant->id]), 'lines');
        $this->assertRejected(fn () => $this->leaseContract([$this->mine, $notMine], ['tenant_id' => $tenant->id]), 'lines');
        $this->assertTrue($this->leaseContract([$this->mine], ['tenant_id' => $tenant->id])->exists);
    }

    public function test_assigned_staff_cannot_add_an_unassigned_property_by_annex(): void
    {
        $notMine = $this->property();
        $contract = $this->percentContract([$this->mine]);

        $this->actingAs($this->assigned);
        $annex = $this->contracts->addAnnex(OwnerContract::query()->findOrFail($contract->id), 1, '2026-03-15');

        $this->assertRejected(fn () => $this->contracts->addProperty($annex, $notMine, '2026-04-01'), 'lines');
    }
}
