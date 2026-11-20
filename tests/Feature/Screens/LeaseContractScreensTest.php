<?php

namespace Tests\Feature\Screens;

use App\Enums\OwnerContractType;
use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\LeaseContract;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\UserProperty;
use App\Services\LeaseContractService;
use App\Services\OwnerContractService;

/**
 * Екраните „Договори за наем“ (PROJECT.md, т. 2 „Договори“, „Договор за наем“,
 * „Поправки“, „Дати в интерфейса“): списък, нов договор, преглед с историята,
 * промени, прекратяване, архив, нов анекс, поправка и предупреждение за имот
 * без договор за управление.
 */
class LeaseContractScreensTest extends ScreenTestCase
{
    private function lease(array $properties, array $overrides = [], ?Company $company = null): LeaseContract
    {
        $company ??= $this->company;

        return app(LeaseContractService::class)->create(array_merge([
            'company_id' => $company->id,
            'tenant_id' => $this->record(Tenant::class, ['name' => 'Петър Измислен'], $company)->id,
            'signed_on' => '2026-01-10',
            'lines' => array_map(fn (Property $p) => ['property_id' => $p->id, 'rent' => '600', 'deposit' => '1200'], $properties),
        ], $overrides));
    }

    private function management(Property $property): void
    {
        app(OwnerContractService::class)->create([
            'company_id' => $this->company->id, 'type' => OwnerContractType::RepresentativePercent,
            'owner_id' => $this->record(Owner::class)->id, 'signed_on' => '2026-01-01', 'commission_percent' => '10',
            'payment_method' => PaymentMethod::Cash, 'lines' => [['property_id' => $property->id]],
        ]);
    }

    // Списък

    public function test_list_shows_own_leases_with_tenant_and_properties_and_search(): void
    {
        $first = $this->record(Property::class);
        $second = $this->record(Property::class);
        $this->lease([$first], ['number' => 31]);
        $this->lease([$second], ['number' => 32, 'tenant_id' => $this->record(Tenant::class, ['name' => 'Мария Измислена'])->id]);
        $this->lease([$this->record(Property::class, [], $this->otherCompany)], [
            'tenant_id' => $this->record(Tenant::class, ['name' => 'Чужд Наемател'], $this->otherCompany)->id,
        ], $this->otherCompany);
        $this->actingAs($this->staff());

        $this->get('/lease-contracts')->assertOk()
            ->assertSee('Петър Измислен')->assertSee('Мария Измислена')->assertSee('600,00')
            ->assertDontSee('Чужд Наемател');
        $this->get('/lease-contracts?q=Мария')->assertSee('Мария Измислена')->assertDontSee('Петър Измислен');
        $this->get('/lease-contracts?q=31')->assertSee('Петър Измислен')->assertDontSee('Мария Измислена');
        $this->get('/lease-contracts?q='.$second->number)->assertSee('Мария Измислена');
    }

    // Нов договор

    public function test_form_has_one_row_an_add_button_and_day_month_year_dates(): void
    {
        $this->record(Tenant::class, ['name' => 'Петър Измислен']);
        $this->record(Property::class);
        $this->actingAs($this->staff());

        $page = $this->get('/lease-contracts/create')->assertOk()
            ->assertSee('Петър Измислен')->assertSee('Добави още имоти')->getContent();

        $this->assertStringNotContainsString('type="date"', $page);
        $this->assertStringContainsString('placeholder="дд.мм.гггг"', $page);
        $this->assertStringContainsString('name="lines[0][rent]"', $page);
        $this->assertStringNotContainsString('name="lines[1][rent]"', $page);
        $this->assertStringContainsString('name="lines[__INDEX__][deposit]"', $page);
    }

    public function test_staff_creates_a_lease_with_rent_and_deposit_per_property(): void
    {
        $tenant = $this->record(Tenant::class, ['name' => 'Петър Измислен']);
        $properties = collect(range(1, 7))->map(fn () => $this->record(Property::class));
        $properties->each(fn (Property $p) => $this->management($p));
        $this->actingAs($this->staff());

        $response = $this->post('/lease-contracts', [
            'tenant_id' => $tenant->id, 'signed_on' => '10.01.2026', 'end_on' => '09.01.2027',
            'lines' => $properties->map(fn (Property $p, $i) => [
                'property_id' => $p->id, 'start_on' => $i === 6 ? '01.02.2026' : '', 'rent' => '600,50', 'deposit' => $i === 0 ? '1200' : '',
            ])->push(['property_id' => '', 'rent' => '', 'deposit' => ''])->all(),
        ]);

        $contract = LeaseContract::query()->sole();
        $response->assertRedirect("/lease-contracts/{$contract->id}")->assertSessionMissing('warning');
        $this->assertSame(7, $contract->lines()->count());
        $this->assertSame('600.50', $contract->lineOn($properties[0], '2026-03-01')->rent);
        $this->assertSame('1200.00', $contract->lineOn($properties[0], '2026-03-01')->deposit);
        $this->assertSame('0.00', $contract->lineOn($properties[1], '2026-03-01')->deposit);
        $this->assertSame('2026-02-01', $contract->lineOn($properties[6], '2026-02-01')->valid_from->toDateString());

        $this->get("/lease-contracts/{$contract->id}")
            ->assertSee('Петър Измислен')->assertSee('600,50')->assertSee('1 200,00')->assertSee('10.01.2026')->assertSee('09.01.2027');
    }

    public function test_wrong_input_shows_the_error_and_creates_nothing(): void
    {
        $tenant = $this->record(Tenant::class);
        $property = $this->record(Property::class);
        $this->actingAs($this->staff());

        $this->post('/lease-contracts', [
            'tenant_id' => $tenant->id, 'signed_on' => '10.01.2026', 'lines' => [['property_id' => $property->id, 'rent' => '']],
        ])->assertSessionHasErrors('lines');
        $this->post('/lease-contracts', [
            'tenant_id' => '', 'signed_on' => '10.01.2026', 'lines' => [['property_id' => $property->id, 'rent' => '600']],
        ])->assertSessionHasErrors('tenant_id');
        $this->post('/lease-contracts', [
            'tenant_id' => $tenant->id, 'signed_on' => '31.02.2026', 'lines' => [['property_id' => $property->id, 'rent' => '600']],
        ])->assertSessionHasErrors('signed_on');

        $this->assertSame(0, LeaseContract::query()->count());
    }

    public function test_busy_property_is_refused_with_a_clear_message(): void
    {
        $property = $this->record(Property::class);
        $first = $this->lease([$property]);
        $this->actingAs($this->staff());

        $this->post('/lease-contracts', [
            'tenant_id' => $first->tenant_id, 'signed_on' => '01.05.2026', 'lines' => [['property_id' => $property->id, 'rent' => '600']],
        ])->assertSessionHasErrors(['lines' => "Имот № {$property->number} вече е в договор за наем № {$first->number} за този период."]);
    }

    public function test_property_without_a_management_contract_is_saved_with_a_warning(): void
    {
        $tenant = $this->record(Tenant::class);
        $property = $this->record(Property::class);
        $this->actingAs($this->staff());

        $response = $this->post('/lease-contracts', [
            'tenant_id' => $tenant->id, 'signed_on' => '10.01.2026', 'lines' => [['property_id' => $property->id, 'rent' => '600']],
        ]);

        $contract = LeaseContract::query()->sole();
        $response->assertSessionHas('warning');
        $this->followRedirects($response)->assertSee("Имот № {$property->number}")->assertSee('няма договор за управление');
        // Предупреждението стои и в прегледа, докато не се оправи.
        $this->get("/lease-contracts/{$contract->id}")->assertSee('няма договор за управление');

        $this->management($property);
        $this->get("/lease-contracts/{$contract->id}")->assertDontSee('няма договор за управление');
    }

    // Преглед, промени, архив

    public function test_show_has_the_history_of_rent_deposit_annexes_and_extensions(): void
    {
        $property = $this->record(Property::class);
        $contract = $this->lease([$property], ['end_on' => '2026-12-31']);
        $service = app(LeaseContractService::class);
        $annex = $service->addAnnex($contract, 3, '2026-05-20', 'Измислено основание');
        $service->changeRent($annex, $property, '2026-06-01', '650');
        $service->extendTerm($service->addAnnex($contract, 4, '2026-11-15'), '2027-12-31');
        $this->actingAs($this->staff());

        $this->get("/lease-contracts/{$contract->id}")->assertOk()
            ->assertSee('600,00')->assertSee('650,00')->assertSee('01.06.2026')
            ->assertSee('Анекс № 3')->assertSee('Измислено основание')
            ->assertSee('31.12.2026')->assertSee('31.12.2027')->assertSee('Анекс № 4');
    }

    public function test_info_fields_are_corrected_terminated_and_the_tenant_is_shown_as_an_amount_field(): void
    {
        $contract = $this->lease([$this->record(Property::class)], ['end_on' => '2026-12-31']);
        $this->actingAs($this->staff());

        $this->get("/lease-contracts/{$contract->id}/edit")->assertOk()
            ->assertSee('Петър Измислен')->assertSee('Поправка')->assertDontSee('name="tenant_id"', false);

        $this->put("/lease-contracts/{$contract->id}", [
            'number' => $contract->number, 'signed_on' => '09.01.2026', 'end_on' => '30.11.2026',
            'terminated_on' => '01.10.2026', 'note' => 'Измислена бележка',
        ])->assertRedirect("/lease-contracts/{$contract->id}");

        $contract->refresh();
        $this->assertSame('2026-10-01', $contract->terminated_on->toDateString());
        $this->assertSame('Измислена бележка', $contract->note);
        $this->get("/lease-contracts/{$contract->id}")->assertSee('Прекратен');
    }

    public function test_archive_and_restore(): void
    {
        $contract = $this->lease([$this->record(Property::class)]);
        $this->actingAs($this->staff());

        $this->post("/lease-contracts/{$contract->id}/archive")->assertRedirect("/lease-contracts/{$contract->id}");
        $this->assertTrue($contract->fresh()->isArchived());
        $this->post("/lease-contracts/{$contract->id}/restore");
        $this->assertFalse($contract->fresh()->isArchived());
    }

    // Анекс и поправка

    public function test_annex_form_applies_several_changes_at_once(): void
    {
        $property = $this->record(Property::class);
        $new = $this->record(Property::class);
        $contract = $this->lease([$property], ['end_on' => '2026-12-31']);
        $this->actingAs($this->staff());

        $this->get("/lease-contracts/{$contract->id}/annexes/create")->assertOk()
            ->assertSee('value="1"', false)->assertSee('Добави още имоти');

        $this->post("/lease-contracts/{$contract->id}/annexes", [
            'signed_on' => '20.05.2026', 'from' => '01.06.2026', 'end_on' => '31.12.2027', 'note' => 'Измислено основание',
            'lines' => [
                ['action' => 'change', 'property_id' => $property->id, 'rent' => '650', 'deposit' => ''],
                ['action' => 'add', 'property_id' => $new->id, 'from' => '01.07.2026', 'rent' => '300', 'deposit' => '300'],
                ['action' => 'add', 'property_id' => '', 'rent' => '', 'deposit' => ''],
            ],
        ])->assertRedirect("/lease-contracts/{$contract->id}");

        $this->assertSame('650.00', $contract->lineOn($property, '2026-06-01')->rent);
        $this->assertSame('1200.00', $contract->lineOn($property, '2026-06-01')->deposit);
        $this->assertNotNull($contract->lineOn($new, '2026-07-01'));
        $this->assertSame('2027-12-31', $contract->fresh()->end_on->toDateString());
    }

    public function test_a_wrong_annex_saves_nothing(): void
    {
        $busy = $this->record(Property::class);
        $this->lease([$busy]);
        $contract = $this->lease([$this->record(Property::class)]);
        $this->actingAs($this->staff());

        $this->from("/lease-contracts/{$contract->id}/annexes/create")->post("/lease-contracts/{$contract->id}/annexes", [
            'number' => '7', 'signed_on' => '20.05.2026', 'from' => '01.06.2026',
            'lines' => [['action' => 'add', 'property_id' => $busy->id, 'rent' => '300']],
        ])->assertSessionHasErrors('lines');

        $this->assertSame(0, $contract->annexes()->count());
        $this->get("/lease-contracts/{$contract->id}/annexes/create")->assertSee('value="7"', false);
    }

    public function test_correction_form_changes_tenant_dates_rent_and_deposit_in_place(): void
    {
        $property = $this->record(Property::class);
        $contract = $this->lease([$property]);
        $line = $contract->lines()->sole();
        $tenant = $this->record(Tenant::class, ['name' => 'Мария Измислена']);
        $this->actingAs($this->staff());

        $this->get("/lease-contracts/{$contract->id}/correct")->assertOk()
            ->assertSee('value="10.01.2026"', false)->assertSee('Мария Измислена')->assertSee('докато няма начисления');

        $this->put("/lease-contracts/{$contract->id}/correct", [
            'tenant_id' => $tenant->id,
            'lines' => [$line->id => ['property_id' => $property->id, 'valid_from' => '05.01.2026', 'rent' => '610', 'deposit' => '1220']],
        ])->assertRedirect("/lease-contracts/{$contract->id}");

        $contract->refresh();
        $this->assertSame($tenant->id, $contract->tenant_id);
        $this->assertSame('610.00', $contract->lineOn($property, '2026-01-05')->rent);
        $this->assertSame(1, $contract->lines()->count());

        $this->put("/lease-contracts/{$contract->id}/correct", [
            'tenant_id' => $tenant->id, 'lines' => [$line->id => ['rent' => '0']],
        ])->assertSessionHasErrors('lines');
        $this->assertSame('610.00', $line->fresh()->rent);
    }

    // Права, фирми, обхват, меню

    public function test_read_only_staff_sees_but_does_not_change(): void
    {
        $contract = $this->lease([$this->record(Property::class)]);
        $this->actingAs($this->readOnlyStaff());

        $this->get('/lease-contracts')->assertOk()->assertDontSee('/lease-contracts/create');
        $this->get("/lease-contracts/{$contract->id}")->assertOk()
            ->assertDontSee("/lease-contracts/{$contract->id}/edit")
            ->assertDontSee("/lease-contracts/{$contract->id}/annexes/create")
            ->assertDontSee("/lease-contracts/{$contract->id}/correct");
        $this->get('/lease-contracts/create')->assertForbidden();
        $this->post('/lease-contracts', [])->assertForbidden();
        $this->put("/lease-contracts/{$contract->id}", [])->assertForbidden();
        $this->post("/lease-contracts/{$contract->id}/archive")->assertForbidden();
        $this->get("/lease-contracts/{$contract->id}/annexes/create")->assertForbidden();
        $this->put("/lease-contracts/{$contract->id}/correct", [])->assertForbidden();
    }

    public function test_other_company_gets_not_found_and_assigned_staff_sees_only_own(): void
    {
        $foreign = $this->lease([$this->record(Property::class, [], $this->otherCompany)], [
            'tenant_id' => $this->record(Tenant::class, [], $this->otherCompany)->id,
        ], $this->otherCompany);
        $mine = $this->record(Property::class);
        $visible = $this->lease([$mine]);
        $hidden = $this->lease([$this->record(Property::class)]);

        $this->actingAs($this->staff());
        foreach (['', '/edit', '/annexes/create', '/correct'] as $page) {
            $this->get("/lease-contracts/{$foreign->id}{$page}")->assertNotFound();
        }

        $user = $this->assignedOnlyStaff();
        UserProperty::query()->forceCreate(['company_id' => $this->company->id, 'user_id' => $user->id, 'property_id' => $mine->id]);
        $this->actingAs($user);
        $this->get("/lease-contracts/{$visible->id}")->assertOk();
        $this->get("/lease-contracts/{$hidden->id}")->assertNotFound();
    }

    public function test_menu_has_the_lease_section(): void
    {
        $this->actingAs($this->staff());

        $this->get('/dashboard')->assertSee('Договори за наем');
    }
}
