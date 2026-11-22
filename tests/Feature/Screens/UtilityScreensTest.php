<?php

namespace Tests\Feature\Screens;

use App\Models\Property;
use App\Models\Provider;
use App\Models\UtilityAccount;
use App\Models\UserProperty;

/**
 * Екраните „Доставчици“ и „Абонати“ (PROJECT.md, т. 3.4 и т. 5.3, 21.11.2026).
 * Само измислени имена и номера.
 */
class UtilityScreensTest extends ScreenTestCase
{
    private function provider(array $attributes = []): Provider
    {
        $provider = (new Provider)->forceFill($attributes + ['company_id' => $this->company->id, 'name' => 'Измислено Електро', 'easypay_name' => 'IZM EL', 'epay_name' => 'Измислено Електро АД']);
        $provider->save();

        return $provider;
    }

    private function property(array $attributes = []): Property
    {
        return $this->record(Property::class, $attributes);
    }

    // Доставчици

    public function test_staff_creates_edits_and_archives_a_provider(): void
    {
        $this->actingAs($this->staff());

        $this->post('/providers', ['name' => 'Измислена Вода', 'easypay_name' => "\u{00A0}IZM VODA\u{00A0}", 'epay_name' => 'Измислена Вода ЕАД'])
            ->assertSessionHasNoErrors();
        $provider = Provider::query()->sole();
        $this->assertSame('IZM VODA', $provider->easypay_name);

        $this->get('/providers')->assertOk()->assertSee('Измислена Вода')->assertSee('IZM VODA')->assertSee('Измислена Вода ЕАД');
        $this->get("/providers/{$provider->id}")->assertOk()->assertSee('IZM VODA');

        $this->put("/providers/{$provider->id}", ['name' => 'Измислена Вода 2'])->assertRedirect("/providers/{$provider->id}");
        $this->assertSame('Измислена Вода 2', $provider->fresh()->name);
        $this->assertNull($provider->fresh()->easypay_name);

        $this->post("/providers/{$provider->id}/archive");
        $this->assertNotNull($provider->fresh()->archived_at);
    }

    public function test_provider_name_is_required_and_names_are_unique(): void
    {
        $this->provider();
        $this->actingAs($this->staff());

        $this->post('/providers', [])->assertSessionHasErrors('name');
        $this->post('/providers', ['name' => 'Измислено Електро', 'easypay_name' => 'IZM EL'])->assertSessionHasErrors(['name', 'easypay_name']);
        $this->assertSame(1, Provider::query()->count());
    }

    public function test_read_only_staff_sees_providers_but_cannot_create(): void
    {
        $this->provider();
        $this->actingAs($this->readOnlyStaff());

        $this->get('/providers')->assertOk()->assertSee('Измислено Електро')->assertDontSee('/providers/create');
        $this->get('/providers/create')->assertForbidden();
        $this->post('/providers', ['name' => 'Ново'])->assertForbidden();
    }

    // Абонати

    public function test_staff_creates_a_shared_account_and_sees_it(): void
    {
        $provider = $this->provider();
        $a = $this->property(['number' => 101]);
        $b = $this->property(['number' => 102]);
        $this->actingAs($this->staff());

        $this->get('/utility-accounts/create')->assertOk()->assertSee('Измислено Електро')->assertSee('№ 101');

        $this->post('/utility-accounts', [
            'provider_id' => $provider->id, 'number' => '0012345', 'kind' => 'electricity', 'billing' => 'actual',
            'period_start_day' => '1', 'period_end_day' => '', 'valid_from' => '01.01.2026', 'valid_to' => '',
            'properties' => [
                ['property_id' => $a->id, 'share' => '40'],
                ['property_id' => $b->id, 'share' => '60'],
                ['property_id' => '', 'share' => ''],
            ],
        ])->assertSessionHasNoErrors();

        $account = UtilityAccount::query()->sole();
        $this->assertSame('0012345', $account->number);
        $this->assertSame('2026-01-01', $account->valid_from->toDateString());

        $this->get('/utility-accounts')->assertOk()
            ->assertSee('0012345')->assertSee('Измислено Електро')->assertSee('Електроенергия')->assertSee('№ 101')->assertSee('№ 102');
        $this->get("/utility-accounts/{$account->id}")->assertOk()
            ->assertSee('0012345')->assertSee('40,00 %')->assertSee('60,00 %')->assertSee('Реален')
            ->assertSee(__('utilities.hints.remainder', ['number' => 102]));
    }

    public function test_fixed_account_shows_its_amount(): void
    {
        $property = $this->property(['number' => 7]);
        $this->actingAs($this->staff());

        $this->post('/utility-accounts', [
            'kind' => 'common', 'billing' => 'fixed', 'fixed_amount' => '12,50', 'valid_from' => '01.01.2026',
            'properties' => [['property_id' => $property->id]],
        ])->assertSessionHasNoErrors();

        $account = UtilityAccount::query()->sole();
        $this->get("/utility-accounts/{$account->id}")->assertSee('Фиксиран')->assertSee('12,50 €')->assertSee('Общи нужди');
    }

    public function test_form_shows_all_errors_together(): void
    {
        $this->actingAs($this->staff());

        $this->from('/utility-accounts/create')->post('/utility-accounts', [
            'kind' => 'electricity', 'billing' => 'actual', 'valid_from' => '31.02.2026', 'properties' => [],
        ])->assertRedirect('/utility-accounts/create')->assertSessionHasErrors(['provider_id', 'number', 'valid_from', 'properties']);

        $this->assertSame(0, UtilityAccount::query()->count());
    }

    public function test_staff_edits_and_archives_an_account(): void
    {
        $provider = $this->provider();
        $property = $this->property();
        $this->actingAs($this->staff());
        $this->post('/utility-accounts', [
            'provider_id' => $provider->id, 'number' => '555', 'kind' => 'water', 'billing' => 'actual', 'valid_from' => '01.01.2026',
            'properties' => [['property_id' => $property->id]],
        ]);
        $account = UtilityAccount::query()->sole();

        $this->get("/utility-accounts/{$account->id}/edit")->assertOk()->assertSee('555');
        $this->put("/utility-accounts/{$account->id}", [
            'provider_id' => $provider->id, 'number' => '0555', 'kind' => 'water', 'billing' => 'actual', 'valid_from' => '01.01.2026',
            'valid_to' => '31.12.2026', 'properties' => [['property_id' => $property->id]],
        ])->assertRedirect("/utility-accounts/{$account->id}");
        $this->assertSame('0555', $account->fresh()->number);
        $this->assertSame('2026-12-31', $account->fresh()->valid_to->toDateString());

        $this->post("/utility-accounts/{$account->id}/archive");
        $this->assertNotNull($account->fresh()->archived_at);
        $this->get('/utility-accounts')->assertDontSee('0555');
        $this->get('/utility-accounts?archived=1')->assertSee('0555');
    }

    public function test_search_by_number_provider_or_property(): void
    {
        $provider = $this->provider();
        $other = $this->provider(['name' => 'Измислена Вода', 'easypay_name' => null, 'epay_name' => null]);
        $a = $this->property(['number' => 301]);
        $b = $this->property(['number' => 302]);
        $this->actingAs($this->staff());
        foreach ([[$provider, '111222', $a], [$other, '333444', $b]] as [$p, $number, $property]) {
            $this->post('/utility-accounts', [
                'provider_id' => $p->id, 'number' => $number, 'kind' => 'water', 'billing' => 'actual', 'valid_from' => '01.01.2026',
                'properties' => [['property_id' => $property->id]],
            ])->assertSessionHasNoErrors();
        }

        $this->get('/utility-accounts?q=1112')->assertSee('111222')->assertDontSee('333444');
        $this->get('/utility-accounts?q=Вода')->assertSee('333444')->assertDontSee('111222');
        $this->get('/utility-accounts?q=301')->assertSee('111222')->assertDontSee('333444');
    }

    public function test_assigned_only_staff_sees_only_accounts_of_their_properties(): void
    {
        $provider = $this->provider();
        $mine = $this->property(['number' => 401]);
        $notMine = $this->property(['number' => 402]);
        $this->actingAs($this->staff());
        foreach ([['901', $mine], ['902', $notMine]] as [$number, $property]) {
            $this->post('/utility-accounts', [
                'provider_id' => $provider->id, 'number' => $number, 'kind' => 'gas', 'billing' => 'actual', 'valid_from' => '01.01.2026',
                'properties' => [['property_id' => $property->id]],
            ]);
        }
        $hidden = UtilityAccount::query()->where('number', '902')->sole();

        $assigned = $this->assignedOnlyStaff();
        UserProperty::query()->create(['company_id' => $this->company->id, 'user_id' => $assigned->id, 'property_id' => $mine->id]);
        $this->actingAs($assigned);

        $this->get('/utility-accounts')->assertSee('901')->assertDontSee('902');
        $this->get("/utility-accounts/{$hidden->id}")->assertNotFound();
    }

    public function test_account_of_another_company_is_not_found(): void
    {
        $mine = $this->staff(); // преди влизането, иначе защитата го слага в чуждата фирма
        $this->actingAs($this->staff([], $this->otherCompany));
        $provider = Provider::query()->create(['name' => 'Чужд']);
        $foreign = UtilityAccount::query()->create([
            'provider_id' => $provider->id, 'number' => '1', 'kind' => 'gas', 'billing' => 'actual', 'valid_from' => '2026-01-01',
        ]);

        $this->actingAs($mine);

        $this->get("/utility-accounts/{$foreign->id}")->assertNotFound();
        $this->get("/providers/{$provider->id}")->assertNotFound();
    }

    public function test_menu_has_the_utilities_group(): void
    {
        $this->actingAs($this->staff());

        $this->get('/providers')->assertSee('href="/providers"', false)->assertSee('href="/utility-accounts"', false)
            ->assertSee(__('registry.menu_groups.utilities'));
    }
}
