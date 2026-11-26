<?php

namespace Tests\Feature\Screens;

use App\Models\Fund;
use App\Models\Property;
use App\Models\UserProperty;
use App\Services\Utilities\FundLedger;
use App\Services\Utilities\FundService;
use App\Services\Utilities\UtilityAccountService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Екранът „Общи фондове“ и справката за фонда (PROJECT.md, „Общ фонд“,
 * 22–23.11.2026). Само измислени имена и суми.
 */
class FundScreensTest extends ScreenTestCase
{
    private function property(array $attributes = [], $company = null): Property
    {
        return $this->record(Property::class, $attributes, $company);
    }

    private function fund(array $properties, array $overrides = [], $company = null): Fund
    {
        return app(FundService::class)->create(array_merge([
            'company_id' => ($company ?? $this->company)->id, 'name' => 'Вход (измислен)',
            'properties' => array_map(fn ($p) => $p->id, $properties),
        ], $overrides));
    }

    public function test_staff_creates_edits_and_archives_a_fund(): void
    {
        [$a, $b] = [$this->property(), $this->property()];
        $this->actingAs($this->staff());

        $this->post('/funds', ['name' => 'Вход А', 'properties' => [$a->id, $b->id]])->assertSessionHasNoErrors();
        $fund = Fund::query()->sole();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $fund->propertyIds());

        $this->get('/funds')->assertOk()->assertSee('Вход А')->assertSee('<td class="col-no"><a href="/funds/'.$fund->id.'">1</a></td>', false);
        $this->get("/funds/{$fund->id}")->assertOk()->assertSee('Вход А')->assertSee('№ '.$a->number);
        $this->get("/funds/{$fund->id}/edit")->assertOk();

        $this->put("/funds/{$fund->id}", ['name' => 'Вход Б', 'number' => '1', 'properties' => [$a->id]])->assertRedirect("/funds/{$fund->id}");
        $this->assertSame('Вход Б', $fund->fresh()->name);

        $this->post("/funds/{$fund->id}/archive");
        $this->assertTrue($fund->fresh()->isArchived());
    }

    public function test_form_shows_all_errors_together(): void
    {
        $this->actingAs($this->staff());

        $this->post('/funds', [])->assertSessionHasErrors(['name', 'properties']);
        $this->assertSame(0, Fund::query()->count());
    }

    public function test_fund_with_an_active_account_is_not_archived_and_says_why(): void
    {
        $property = $this->property();
        $fund = $this->fund([$property]);
        app(UtilityAccountService::class)->create([
            'company_id' => $this->company->id, 'kind' => 'common', 'billing' => 'fixed', 'fixed_amount' => '15',
            'valid_from' => '2026-01-01', 'fund_id' => $fund->id, 'properties' => [['property_id' => $property->id]],
        ]);
        $this->actingAs($this->staff());

        $this->post("/funds/{$fund->id}/archive")->assertSessionHasErrors('fund');
        $this->assertFalse($fund->fresh()->isArchived());
    }

    public function test_number_is_shown_and_can_be_changed_in_the_form(): void
    {
        $fund = $this->fund([$this->property()]);
        $this->actingAs($this->staff());

        $this->get('/funds')->assertSee(__('utilities.funds.columns.number'))->assertDontSee('Липсва аналитика');
        $this->get("/funds/{$fund->id}")->assertSee(__('utilities.funds.fields.number'))->assertSee('406', false)->assertDontSee('Липсва аналитика');
        $this->get("/funds/{$fund->id}/edit")->assertSee('name="number" value="1"', false);
        $this->get('/funds/create')->assertSee('name="number"', false)->assertSee(__('registry.number_hint'));
    }

    public function test_fund_page_shows_collected_paid_and_left(): void
    {
        $property = $this->property();
        $fund = $this->fund([$property]);
        $fee = app(UtilityAccountService::class)->create([
            'company_id' => $this->company->id, 'kind' => 'common', 'billing' => 'fixed', 'fixed_amount' => '15',
            'valid_from' => '2026-01-01', 'fund_id' => $fund->id, 'properties' => [['property_id' => $property->id]],
        ]);
        $this->actingAs($this->staff());
        app(FundLedger::class)->income($fee, CarbonImmutable::parse('2026-11-01'), 1500);
        app(FundLedger::class)->income($fee, CarbonImmutable::parse('2026-12-01'), 2250);

        $this->get("/funds/{$fund->id}")->assertOk()
            ->assertSee(__('utilities.funds.collected'))->assertSee(__('utilities.funds.paid'))->assertSee(__('utilities.funds.left'))
            ->assertSee('37,50')
            ->assertSee(__('registry.one.utility-accounts'));

        // За период: само декември.
        $this->get("/funds/{$fund->id}?from=12.2026&to=12.2026")->assertOk()->assertSee('22,50');
    }

    public function test_read_only_staff_sees_funds_but_cannot_create(): void
    {
        $this->fund([$this->property()]);
        $this->actingAs($this->readOnlyStaff());

        $this->get('/funds')->assertOk()->assertSee('Вход (измислен)');
        $this->get('/funds/create')->assertForbidden();
        $this->post('/funds', ['name' => 'Х', 'properties' => []])->assertForbidden();
    }

    public function test_assigned_only_staff_sees_only_funds_of_their_properties(): void
    {
        [$mine, $other] = [$this->property(), $this->property()];
        $visible = $this->fund([$mine], ['name' => 'Видим вход']);
        $hidden = $this->fund([$other], ['name' => 'Скрит вход']);
        $staff = $this->assignedOnlyStaff();
        (new UserProperty)->forceFill(['company_id' => $this->company->id, 'user_id' => $staff->id, 'property_id' => $mine->id])->save();
        $this->actingAs($staff);

        $this->get('/funds')->assertSee('Видим вход')->assertDontSee('Скрит вход');
        $this->get("/funds/{$visible->id}")->assertOk();
        $this->get("/funds/{$hidden->id}")->assertNotFound();
    }

    public function test_company_a_does_not_see_funds_of_company_b(): void
    {
        $foreign = $this->fund([$this->property([], $this->otherCompany)], ['name' => 'Чужд вход'], $this->otherCompany);
        $this->actingAs($this->staff());

        $this->get('/funds')->assertDontSee('Чужд вход');
        $this->get("/funds/{$foreign->id}")->assertNotFound();
        $this->get("/funds/{$foreign->id}/edit")->assertNotFound();
        $this->post("/funds/{$foreign->id}/archive")->assertNotFound();
        $this->assertFalse($foreign->fresh()->isArchived());
    }

    public function test_account_form_has_the_fund_fields(): void
    {
        $fund = $this->fund([$this->property()]);
        $this->actingAs($this->staff());

        $this->get('/utility-accounts/create')->assertOk()
            ->assertSee('name="fund_id"', false)->assertSee('name="paid_from_fund"', false)->assertSee('Вход (измислен)');
    }

    public function test_account_page_shows_its_fund(): void
    {
        $property = $this->property();
        $fund = $this->fund([$property]);
        $account = app(UtilityAccountService::class)->create([
            'company_id' => $this->company->id, 'kind' => 'common', 'billing' => 'fixed', 'fixed_amount' => '15',
            'valid_from' => '2026-01-01', 'fund_id' => $fund->id, 'properties' => [['property_id' => $property->id]],
        ]);
        $this->actingAs($this->staff());

        $this->get("/utility-accounts/{$account->id}")->assertOk()->assertSee('href="/funds/'.$fund->id.'"', false);
    }

    public function test_menu_has_the_funds(): void
    {
        $this->actingAs($this->staff());

        $this->get('/funds')->assertSee('href="/funds"', false)->assertSee(__('registry.sections.funds'));
    }
}
