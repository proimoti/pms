<?php

namespace Tests\Feature\Screens;

use App\Models\Broker;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

/** Кой стига до екраните на регистъра (PROJECT.md, т. 2). */
class ScreenAccessTest extends ScreenTestCase
{
    private const SECTIONS = [
        'properties' => 'Имоти',
        'owners' => 'Собственици',
        'tenants' => 'Наематели',
        'brokers' => 'Отговорни лица',
    ];

    public function test_guest_is_sent_to_the_login_page(): void
    {
        foreach (array_keys(self::SECTIONS) as $section) {
            $this->get("/{$section}")->assertRedirect('/login');
        }
    }

    public function test_staff_sees_every_section_in_bulgarian(): void
    {
        $this->actingAs($this->staff());

        foreach (self::SECTIONS as $section => $title) {
            $this->get("/{$section}")->assertOk()->assertSee($title);
        }
    }

    public function test_menu_links_to_every_section(): void
    {
        $this->actingAs($this->staff());

        $response = $this->get('/dashboard')->assertOk();
        foreach (self::SECTIONS as $section => $title) {
            $response->assertSee("/{$section}", false)->assertSee($title);
        }
    }

    public function test_app_admin_cannot_open_the_registry(): void
    {
        $admin = User::factory()->appAdmin()->withTwoFactor(app(Google2FA::class)->generateSecretKey())->create();
        $this->actingAs($admin);

        foreach (array_keys(self::SECTIONS) as $section) {
            $this->get("/{$section}")->assertForbidden();
        }
    }

    public function test_owner_and_tenant_users_cannot_open_the_registry(): void
    {
        foreach ([User::factory()->owner(), User::factory()->tenant()] as $factory) {
            $this->actingAs($factory->create(['company_id' => $this->company->id]));

            foreach (array_keys(self::SECTIONS) as $section) {
                $this->get("/{$section}")->assertForbidden();
            }
        }
    }

    public function test_read_only_staff_sees_lists_and_details_without_change_buttons(): void
    {
        $property = $this->record(Property::class);
        $this->actingAs($this->readOnlyStaff());

        $this->get('/properties')->assertOk()->assertDontSee('/properties/create', false);
        $this->get("/properties/{$property->id}")->assertOk()
            ->assertDontSee("/properties/{$property->id}/edit", false)
            ->assertDontSee('Архивирай');
    }

    public function test_read_only_staff_cannot_create_change_or_archive(): void
    {
        $records = [
            'properties' => $this->record(Property::class),
            'owners' => $this->record(Owner::class),
            'tenants' => $this->record(Tenant::class),
            'brokers' => $this->record(Broker::class),
        ];
        $this->actingAs($this->readOnlyStaff());

        foreach ($records as $section => $record) {
            $this->get("/{$section}/create")->assertForbidden();
            $this->post("/{$section}", ['name' => 'Ново'])->assertForbidden();
            $this->get("/{$section}/{$record->id}/edit")->assertForbidden();
            $this->put("/{$section}/{$record->id}", ['name' => 'Ново'])->assertForbidden();
            $this->post("/{$section}/{$record->id}/archive")->assertForbidden();
        }

        $this->assertNull($records['brokers']->fresh()->archived_at);
    }

    public function test_records_cannot_be_deleted_through_the_screens(): void
    {
        $broker = $this->record(Broker::class);
        $this->actingAs($this->staff());

        $this->delete("/brokers/{$broker->id}")->assertStatus(405);

        $this->assertDatabaseHas('brokers', ['id' => $broker->id]);
    }
}
