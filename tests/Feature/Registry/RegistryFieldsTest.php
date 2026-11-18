<?php

namespace Tests\Feature\Registry;

use App\Enums\PartyKind;
use App\Models\Broker;
use App\Models\Owner;
use App\Models\Property;
use App\Models\PropertyType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Полетата на регистъра (PROJECT.md, т. 2 „Регистър, полета“ и „уточнения“). */
class RegistryFieldsTest extends TestCase
{
    use RefreshDatabase;

    private User $alpha;

    private User $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = User::factory()->create();
        $this->beta = User::factory()->create();
        $this->actingAs($this->alpha);
    }

    public function test_property_has_a_structured_address_type_area_and_broker(): void
    {
        $type = PropertyType::factory()->create(['name' => 'Апартамент']);
        $broker = Broker::factory()->create();

        $property = Property::factory()->create([
            'property_type_id' => $type->id,
            'broker_id' => $broker->id,
            'postal_code' => '1000',
            'city' => 'София',
            'district' => 'Център',
            'street' => 'ул. Измислена',
            'street_number' => '12А',
            'entrance' => 'Б',
            'floor' => '3',
            'apartment' => '7',
            'area' => '75.50',
        ])->fresh();

        $this->assertSame('Апартамент', $property->propertyType->name);
        $this->assertSame($broker->id, $property->broker->id);
        $this->assertSame(['1000', 'София', 'Център', 'ул. Измислена', '12А', 'Б', '3', '7'], [
            $property->postal_code, $property->city, $property->district, $property->street,
            $property->street_number, $property->entrance, $property->floor, $property->apartment,
        ]);
        $this->assertSame('75.50', $property->area);
    }

    public function test_area_is_optional(): void
    {
        $this->assertNull(Property::factory()->create(['area' => null])->fresh()->area);
    }

    public function test_database_rejects_a_property_type_from_another_company(): void
    {
        $this->actingAs($this->beta);
        $foreignType = PropertyType::factory()->create();
        $this->actingAs($this->alpha);
        $property = Property::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('properties')->where('id', $property->id)->update(['property_type_id' => $foreignType->id]);
    }

    public function test_database_rejects_a_broker_from_another_company(): void
    {
        $this->actingAs($this->beta);
        $foreignBroker = Broker::factory()->create();
        $this->actingAs($this->alpha);
        $property = Property::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('properties')->where('id', $property->id)->update(['broker_id' => $foreignBroker->id]);
    }

    public function test_legal_entity_owner_has_eik_and_manager(): void
    {
        $owner = Owner::factory()->legal()->create(['eik' => '000000000', 'manager_name' => 'Измислен Управител'])->fresh();

        $this->assertSame(PartyKind::Legal, $owner->kind);
        $this->assertSame('000000000', $owner->eik);
        $this->assertSame('Измислен Управител', $owner->manager_name);
    }

    public function test_database_rejects_eik_or_manager_for_an_individual(): void
    {
        foreach ([Owner::factory(), Tenant::factory()] as $factory) {
            $party = $factory->create(); // физическо лице по подразбиране

            foreach (['eik' => '000000000', 'manager_name' => 'Измислен Управител'] as $field => $value) {
                try {
                    DB::table($party->getTable())->where('id', $party->id)->update([$field => $value]);
                    $this->fail("{$party->getTable()}.{$field} трябваше да бъде отказано при физическо лице.");
                } catch (QueryException $e) {
                    $this->assertStringContainsString('legal_fields_check', $e->getMessage());
                }
            }
        }
    }

    public function test_person_has_full_address_with_country_bulgaria_by_default(): void
    {
        $tenant = Tenant::factory()->create(['city' => 'Пловдив', 'postal_code' => '4000'])->fresh();

        $this->assertSame('BG', $tenant->country);
        $this->assertSame('Пловдив', $tenant->city);
        $this->assertSame('4000', $tenant->postal_code);
    }

    public function test_names_are_not_unique(): void
    {
        Owner::factory()->create(['name' => 'Иван Измислен']);
        Owner::factory()->create(['name' => 'Иван Измислен']);

        $this->assertSame(2, Owner::query()->where('name', 'Иван Измислен')->count());
    }
}
