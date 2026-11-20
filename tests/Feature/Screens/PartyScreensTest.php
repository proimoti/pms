<?php

namespace Tests\Feature\Screens;

use App\Enums\PartyKind;
use App\Models\Owner;
use App\Models\Tenant;

/** Екраните „Собственици“ и „Наематели“ (еднакви полета). */
class PartyScreensTest extends ScreenTestCase
{
    private const SECTIONS = ['owners' => Owner::class, 'tenants' => Tenant::class];

    public function test_staff_creates_an_individual_with_an_automatic_number(): void
    {
        $this->actingAs($this->staff());

        foreach (self::SECTIONS as $section => $class) {
            $response = $this->post("/{$section}", [
                'kind' => 'individual', 'name' => 'Иван Измислен', 'city' => 'София', 'phone' => '0888000000',
            ]);

            $party = $class::query()->sole();
            $response->assertRedirect("/{$section}/{$party->id}");
            $this->assertSame(1, $party->number);
            $this->assertSame('BG', $party->country);
            $this->get("/{$section}/{$party->id}")->assertSee('Иван Измислен')->assertSee('Физическо лице');
        }
    }

    public function test_staff_creates_a_legal_entity_with_eik_and_manager(): void
    {
        $this->actingAs($this->staff());

        $this->post('/owners', [
            'kind' => 'legal', 'name' => 'Тест Гама ООД', 'eik' => '000000000', 'manager_name' => 'Измислен Управител',
        ])->assertSessionHasNoErrors();

        $owner = Owner::query()->sole();
        $this->assertSame(PartyKind::Legal, $owner->kind);
        $this->assertSame('000000000', $owner->eik);
    }

    public function test_eik_and_manager_are_refused_for_an_individual(): void
    {
        $this->actingAs($this->staff());

        $this->post('/tenants', ['kind' => 'individual', 'name' => 'Иван Измислен', 'eik' => '000000000'])
            ->assertSessionHasErrors('eik');
        $this->post('/tenants', ['kind' => 'individual', 'name' => 'Иван Измислен', 'manager_name' => 'Някой'])
            ->assertSessionHasErrors('manager_name');

        $this->assertSame(0, Tenant::query()->count());
    }

    public function test_eik_must_be_9_or_13_digits(): void
    {
        $this->actingAs($this->staff());

        $this->post('/owners', ['kind' => 'legal', 'name' => 'Тест ООД', 'eik' => '123'])->assertSessionHasErrors('eik');
    }

    public function test_same_name_shows_a_warning_before_creating_a_second_record(): void
    {
        $this->record(Owner::class, ['name' => 'Иван Измислен', 'number' => 4]);
        $this->actingAs($this->staff());

        $this->from('/owners/create')
            ->post('/owners', ['kind' => 'individual', 'name' => '  иван   ИЗМИСЛЕН '])
            ->assertRedirect('/owners/create')
            ->assertSessionHas('similar_names');
        $this->assertSame(1, Owner::query()->count());

        $this->get('/owners/create')->assertSee('Вече има запис с подобно име')->assertSee('№ 4');

        $this->post('/owners', ['kind' => 'individual', 'name' => 'Иван Измислен', 'confirm_similar_name' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, Owner::query()->count());
    }

    public function test_search_by_name_number_or_eik(): void
    {
        // Постоянни телефон и имейл: случайните понякога съдържат „31“ и търсенето ги намира.
        $fixed = ['phone' => '0888000000', 'street_number' => '1'];
        $this->record(Tenant::class, ['name' => 'Мария Измислена', 'number' => 31, 'email' => 'maria@example.test'] + $fixed);
        $this->record(Tenant::class, ['kind' => 'legal', 'name' => 'Тест Делта ООД', 'eik' => '111111111', 'email' => 'delta@example.test'] + $fixed);
        $this->actingAs($this->staff());

        $this->get('/tenants?q=Мария')->assertSee('Мария Измислена')->assertDontSee('Тест Делта ООД');
        $this->get('/tenants?q=31')->assertSee('Мария Измислена')->assertDontSee('Тест Делта ООД');
        $this->get('/tenants?q=111111111')->assertSee('Тест Делта ООД')->assertDontSee('Мария Измислена');
    }

    public function test_list_shows_only_own_company(): void
    {
        $this->record(Owner::class, ['name' => 'Наш Собственик']);
        $this->record(Owner::class, ['name' => 'Чужд Собственик'], $this->otherCompany);
        $this->actingAs($this->staff());

        $this->get('/owners')->assertSee('Наш Собственик')->assertDontSee('Чужд Собственик');
    }

    public function test_staff_edits_archives_and_restores(): void
    {
        $tenant = $this->record(Tenant::class, ['name' => 'Старо Име']);
        $this->actingAs($this->staff());

        $this->put("/tenants/{$tenant->id}", ['number' => $tenant->number, 'kind' => 'individual', 'name' => 'Ново Име'])
            ->assertRedirect("/tenants/{$tenant->id}");
        $this->assertSame('Ново Име', $tenant->fresh()->name);

        $this->post("/tenants/{$tenant->id}/archive");
        $this->assertNotNull($tenant->fresh()->archived_at);
        $this->post("/tenants/{$tenant->id}/restore");
        $this->assertNull($tenant->fresh()->archived_at);
    }

    public function test_user_with_assigned_properties_sees_no_owners_or_tenants_yet(): void
    {
        $owner = $this->record(Owner::class, ['name' => 'Скрит Собственик']);
        $this->actingAs($this->assignedOnlyStaff());

        $this->get('/owners')->assertOk()->assertDontSee('Скрит Собственик');
        $this->get("/owners/{$owner->id}")->assertNotFound();
    }
}
