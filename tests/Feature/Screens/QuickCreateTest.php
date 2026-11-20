<?php

namespace Tests\Feature\Screens;

use App\Enums\OwnerContractType;
use App\Enums\PaymentMethod;
use App\Models\Owner;
use App\Models\Property;
use App\Models\PropertyType;
use App\Models\Tenant;
use App\Services\LeaseContractService;
use App\Services\OwnerContractService;

/**
 * „+ Нов“ до избора на имот, собственик и наемател във формите на договорите
 * и анексите (PROJECT.md, 20.11.2026): отваря обикновената форма в малък
 * прозорец (popup=1, без менюто). След запис прозорецът казва на формата
 * кой е новият запис, за да бъде избран; формата на договора не се
 * презарежда. Бенефициентът остава с краткото поле.
 */
class QuickCreateTest extends ScreenTestCase
{
    private function button(string $section): string
    {
        return 'data-quick-new="/'.$section.'/create?popup=1"';
    }

    public function test_contract_forms_have_new_buttons_for_owner_tenant_and_property(): void
    {
        $management = app(OwnerContractService::class)->create([
            'company_id' => $this->company->id, 'type' => OwnerContractType::RepresentativePercent,
            'owner_id' => $this->record(Owner::class)->id, 'signed_on' => '2026-01-01', 'commission_percent' => '10',
            'payment_method' => PaymentMethod::Cash, 'lines' => [['property_id' => $this->record(Property::class)->id]],
        ]);
        $lease = app(LeaseContractService::class)->create([
            'company_id' => $this->company->id, 'tenant_id' => $this->record(Tenant::class)->id, 'signed_on' => '2026-01-10',
            'lines' => [['property_id' => $this->record(Property::class)->id, 'rent' => '600']],
        ]);
        $this->actingAs($this->staff());

        $expected = [
            '/owner-contracts/create' => ['owners', 'properties'],
            "/owner-contracts/{$management->id}/annexes/create" => ['properties'],
            "/owner-contracts/{$management->id}/correct" => ['owners', 'properties'],
            '/lease-contracts/create' => ['tenants', 'properties'],
            "/lease-contracts/{$lease->id}/annexes/create" => ['properties'],
            "/lease-contracts/{$lease->id}/correct" => ['tenants', 'properties'],
        ];

        foreach ($expected as $url => $sections) {
            $response = $this->get($url)->assertOk();
            foreach ($sections as $section) {
                $response->assertSee($this->button($section), false);
            }
            // Бенефициентът остава с краткото поле, без прозорец.
            $response->assertDontSee($this->button('beneficiaries'), false);
            // Прозорецът е на страницата, скрит до натискане.
            $response->assertSee('<dialog id="quick-new"', false);
        }

        $this->get('/owner-contracts/create')->assertDontSee($this->button('tenants'), false);
        $this->get('/lease-contracts/create')->assertDontSee($this->button('owners'), false);
    }

    public function test_form_in_the_small_window_has_no_menu_and_remembers_the_mode(): void
    {
        $this->actingAs($this->staff());

        foreach (['properties', 'owners', 'tenants'] as $section) {
            $this->get("/{$section}/create?popup=1")->assertOk()
                ->assertDontSee('<nav class="top"', false)
                ->assertSee('<input type="hidden" name="popup" value="1">', false);
        }

        $this->get('/properties/create')->assertOk()->assertSee('<nav class="top"', false)
            ->assertDontSee('name="popup"', false);
    }

    public function test_saving_in_the_small_window_reports_the_new_property(): void
    {
        $type = PropertyType::factory()->create(['company_id' => $this->company->id]);
        $this->actingAs($this->staff());

        $response = $this->post('/properties', [
            'popup' => '1', 'property_type_id' => $type->id, 'city' => 'София', 'street' => 'ул. Измислена', 'street_number' => '5',
        ])->assertOk()->assertSee('postMessage', false);

        $property = Property::query()->sole();
        $response->assertSee('"id":'.$property->id, false)
            ->assertSee('"section":"properties"', false)
            ->assertSee('№ '.$property->number, false);
    }

    public function test_saving_in_the_small_window_reports_the_new_owner_and_tenant(): void
    {
        $this->actingAs($this->staff());

        $response = $this->post('/owners', ['popup' => '1', 'kind' => 'individual', 'name' => 'Иван Измислен', 'country' => 'BG'])
            ->assertOk()->assertSee('postMessage', false);
        $owner = Owner::query()->sole();
        $response->assertSee('"id":'.$owner->id, false)->assertSee('"section":"owners"', false)
            ->assertSee('Иван Измислен', false);

        $this->post('/tenants', ['popup' => '1', 'kind' => 'legal', 'name' => 'Тест Гама ООД', 'country' => 'BG'])
            ->assertOk()->assertSee('"section":"tenants"', false)->assertSee('Тест Гама ООД', false);
    }

    public function test_errors_in_the_small_window_stay_in_it(): void
    {
        $this->actingAs($this->staff());

        $this->from('/properties/create?popup=1')->post('/properties', ['popup' => '1'])
            ->assertRedirect('/properties/create?popup=1')->assertSessionHasErrors('city');

        // Подобно име: предупреждението също е в прозореца.
        $this->record(Owner::class, ['name' => 'Иван Измислен']);
        $this->from('/owners/create?popup=1')->post('/owners', ['popup' => '1', 'kind' => 'individual', 'name' => 'Иван Измислен', 'country' => 'BG'])
            ->assertRedirect('/owners/create?popup=1');
        $this->get('/owners/create?popup=1')->assertDontSee('<nav class="top"', false);
        $this->assertSame(1, Owner::query()->count());
    }

    public function test_normal_save_still_opens_the_new_record(): void
    {
        $this->actingAs($this->staff());

        $this->post('/owners', ['kind' => 'individual', 'name' => 'Иван Измислен', 'country' => 'BG'])
            ->assertRedirect('/owners/'.Owner::query()->sole()->id);
    }
}
