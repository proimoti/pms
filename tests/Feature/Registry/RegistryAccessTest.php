<?php

namespace Tests\Feature\Registry;

use App\Models\Beneficiary;
use App\Models\Broker;
use App\Models\Company;
use App\Models\Owner;
use App\Models\Property;
use App\Models\PropertyType;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserProperty;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Обхват и права в регистъра (PROJECT.md, т. 2 „Достъп“ и „Регистър“).
 */
class RegistryAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $writer;

    private User $assignedOnly;

    private User $readOnly;

    private User $otherCompanyUser;

    /** @var list<User> */
    private array $portalUsers;

    protected function setUp(): void
    {
        parent::setUp();
        // Всички се създават преди „влизането“; всички освен последния са в една фирма.
        $company = ['company_id' => Company::factory()->create()->id];
        $this->writer = User::factory()->create($company);
        $this->assignedOnly = User::factory()->assignedPropertiesOnly()->create($company);
        $this->readOnly = User::factory()->readOnly()->create($company);
        $this->portalUsers = [User::factory()->owner()->create($company), User::factory()->tenant()->create($company)];
        $this->otherCompanyUser = User::factory()->create();
        $this->actingAs($this->writer);
    }

    public function test_user_with_all_properties_sees_all_company_properties(): void
    {
        Property::factory()->count(3)->create();

        $this->assertSame(3, Property::query()->count());
    }

    public function test_user_with_assigned_properties_sees_only_those(): void
    {
        [$assigned, $other] = Property::factory()->count(2)->create();
        UserProperty::query()->create(['user_id' => $this->assignedOnly->id, 'property_id' => $assigned->id]);

        $this->actingAs($this->assignedOnly);

        $this->assertSame([$assigned->id], Property::query()->pluck('id')->all());
        $this->assertNull(Property::find($other->id));
    }

    public function test_user_with_assigned_properties_sees_no_owners_tenants_or_beneficiaries_yet(): void
    {
        Owner::factory()->create();
        Tenant::factory()->create();
        Beneficiary::factory()->create();

        $this->actingAs($this->assignedOnly);

        $this->assertSame(0, Owner::query()->count());
        $this->assertSame(0, Tenant::query()->count());
        $this->assertSame(0, Beneficiary::query()->count());
    }

    public function test_user_with_assigned_properties_still_sees_brokers_and_property_types(): void
    {
        Broker::factory()->create();
        PropertyType::factory()->create();

        $this->actingAs($this->assignedOnly);

        $this->assertSame(1, Broker::query()->count());
        $this->assertSame(1, PropertyType::query()->count());
    }

    public function test_database_rejects_assigning_a_property_of_another_company(): void
    {
        $this->actingAs($this->otherCompanyUser);
        $foreignProperty = Property::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('user_properties')->insert([
            'company_id' => $this->writer->company_id,
            'user_id' => $this->assignedOnly->id,
            'property_id' => $foreignProperty->id,
        ]);
    }

    public function test_read_only_user_cannot_create_change_or_archive(): void
    {
        $broker = Broker::factory()->create();
        $this->actingAs($this->readOnly);

        $attempts = [
            'създаване' => fn () => Broker::factory()->create(),
            'промяна' => fn () => Broker::query()->findOrFail($broker->id)->update(['name' => 'Ново Име']),
            'архивиране' => fn () => Broker::query()->findOrFail($broker->id)->archive(),
        ];

        foreach ($attempts as $what => $attempt) {
            try {
                $attempt();
                $this->fail("{$what} трябваше да бъде отказано при само четене.");
            } catch (AuthorizationException) {
                // очаквано
            }
        }

        $this->assertSame(1, Broker::query()->count());
        $this->assertNull($broker->fresh()->archived_at);
    }

    public function test_read_only_user_still_sees_the_registry(): void
    {
        Broker::factory()->create();

        $this->actingAs($this->readOnly);

        $this->assertSame(1, Broker::query()->count());
    }

    public function test_owner_and_tenant_users_see_nothing_in_the_registry_until_the_portal(): void
    {
        Property::factory()->create();
        Broker::factory()->create();

        foreach ($this->portalUsers as $portalUser) {
            $this->actingAs($portalUser);

            $this->assertSame(0, Property::query()->count());
            $this->assertSame(0, Broker::query()->count());
        }
    }
}
