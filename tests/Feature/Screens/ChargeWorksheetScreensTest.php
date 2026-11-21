<?php

namespace Tests\Feature\Screens;

use App\Enums\ChargeType;
use App\Enums\OwnerContractType;
use App\Enums\PaymentMethod;
use App\Models\ChargeDraft;
use App\Models\Company;
use App\Models\LeaseContract;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\UpcomingCharge;
use App\Models\UserProperty;
use App\Services\Charges\MonthWorksheet;
use App\Services\LeaseContractService;
use App\Services\OwnerContractService;

/**
 * Екраните „Начисления“ (работната таблица на месеца) и „Предстоящи
 * начисления“ (PROJECT.md, т. 2 „Работна таблица на месеца“, „Начисления“).
 * Една сметка е за един договор за наем: редовете към наемателите са по
 * договор. Генерирането и промените са за „четене и запис“ и „всички имоти“.
 */
class ChargeWorksheetScreensTest extends ScreenTestCase
{
    /** Имот с договор за управление 10% (собственик „Георги Измислен“) и наем 600 €. */
    private function rented(string $tenant = 'Петър Измислен', ?Company $company = null, array $line = []): array
    {
        $company ??= $this->company;
        $property = $this->record(Property::class, [], $company);

        $management = app(OwnerContractService::class)->create([
            'company_id' => $company->id, 'type' => OwnerContractType::RepresentativePercent,
            'owner_id' => $this->record(Owner::class, ['name' => 'Георги Измислен'], $company)->id,
            'signed_on' => '2026-01-01', 'commission_percent' => '10', 'payment_method' => PaymentMethod::Cash,
            'lines' => [['property_id' => $property->id]],
        ]);
        $lease = app(LeaseContractService::class)->create([
            'company_id' => $company->id,
            'tenant_id' => $this->record(Tenant::class, ['name' => $tenant], $company)->id,
            'signed_on' => '2026-01-10',
            'lines' => [array_merge(['property_id' => $property->id, 'rent' => '600', 'deposit' => '1200'], $line)],
        ]);

        return [$property, $lease, $management];
    }

    private function generated(): void
    {
        $this->post('/charges/generate', ['month' => '2026-12'])->assertRedirect('/charges?month=2026-12');
    }

    // Екранът

    public function test_menu_and_empty_month(): void
    {
        $this->actingAs($this->staff());

        $this->get('/charges?month=2026-12')->assertOk()
            ->assertSee('href="/charges"', false)
            ->assertSee('Декември 2026')
            ->assertSee('href="/charges?month=2026-11"', false)
            ->assertSee('href="/charges?month=2027-01"', false)
            ->assertSee(__('charges.screens.generate'))
            ->assertSee(__('charges.screens.not_generated'));
    }

    public function test_generate_shows_rows_by_lease_contract_and_owner(): void
    {
        [$property, $lease] = $this->rented();
        $this->actingAs($this->staff());

        $this->generated();

        $this->get('/charges?month=2026-12')->assertOk()
            ->assertSee(__('charges.screens.generate_again'))
            ->assertSee('Петър Измислен')
            ->assertSee(__('registry.one.lease-contracts').' <strong>№ '.$lease->number.'</strong>', false)
            ->assertSee('№ '.$property->number)
            ->assertSee('600,00')
            ->assertSee('Георги Измислен')
            ->assertSee('540,00')
            ->assertSee('60,00'); // приход
    }

    public function test_month_defaults_to_next_month_and_bad_month_is_rejected(): void
    {
        $this->actingAs($this->staff());

        $this->get('/charges')->assertRedirect('/charges?month='.now()->addMonthNoOverflow()->format('Y-m'));
        $this->get('/charges?month=2026-13')->assertNotFound();
    }

    public function test_zero_rows_are_shown_grey(): void
    {
        $this->rented(line: ['rent' => '0']);
        $this->actingAs($this->staff());
        $this->generated();

        $this->get('/charges?month=2026-12')->assertOk()->assertSee('class="zero"', false);
    }

    public function test_search_and_filters(): void
    {
        [$a] = $this->rented('Петър Измислен');
        [$b, $leaseB] = $this->rented('Мария Измислена');
        $this->actingAs($this->staff());
        $this->generated();
        $row = ChargeDraft::query()->where('property_id', $b->id)->where('type', ChargeType::Rent)->sole();
        $this->put("/charges/{$row->id}", ['amount' => '590', 'reason' => 'Отстъпка'])->assertRedirect('/charges?month=2026-12');

        $this->get('/charges?month=2026-12&q=Мария')->assertSee('Мария Измислена')->assertDontSee('Петър Измислен');
        $this->get('/charges?month=2026-12&q='.$a->number)->assertSee('Петър Измислен')->assertDontSee('Мария Измислена');
        $this->get('/charges?month=2026-12&filter=manual')->assertSee('Мария Измислена')->assertDontSee('Петър Измислен')
            ->assertSee('Отстъпка');
        $this->get('/charges?month=2026-12&filter=warnings')->assertDontSee('Петър Измислен');
    }

    // Промяна, махане, връщане, ръчен ред

    public function test_change_and_remove_need_a_reason_on_the_screen(): void
    {
        [$property] = $this->rented();
        $this->actingAs($this->staff());
        $this->generated();
        $row = ChargeDraft::query()->where('property_id', $property->id)->where('type', ChargeType::Rent)->sole();

        $this->get("/charges/{$row->id}/edit")->assertOk()->assertSee('name="reason"', false);
        $this->put("/charges/{$row->id}", ['amount' => '590', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/charges/{$row->id}/remove", ['reason' => ''])->assertSessionHasErrors('reason');

        $this->post("/charges/{$row->id}/remove", ['reason' => 'Наемателят е в чужбина'])->assertRedirect('/charges?month=2026-12');
        $this->get('/charges?month=2026-12')->assertSee('Наемателят е в чужбина')->assertSee(__('charges.screens.removed'));

        $this->post("/charges/{$row->id}/restore", ['reason' => 'Върнат'])->assertRedirect('/charges?month=2026-12');
        $this->assertNull($row->fresh()->removed_at);
    }

    public function test_manual_row_from_the_screen(): void
    {
        [$property, $lease] = $this->rented();
        $this->actingAs($this->staff());

        $this->get('/charges/create?month=2026-12')->assertOk()->assertSee('name="reason"', false);
        $this->post('/charges', [
            'month' => '2026-12', 'type' => 'penalty', 'lease_contract_id' => $lease->id, 'property_id' => $property->id,
            'amount' => '50', 'note' => 'Закъснение', 'reason' => 'По договора',
        ])->assertRedirect('/charges?month=2026-12');

        $this->get('/charges?month=2026-12')->assertSee('Закъснение')->assertSee('50,00');
    }

    /** Неустойката и „Друго“ са приход на фирмата; депозитът не е (PROJECT.md, „Дял на собственика“). */
    public function test_revenue_includes_penalties_and_other_but_not_the_deposit(): void
    {
        [$property, $lease] = $this->rented();
        $this->actingAs($this->staff());
        $this->generated();
        foreach (['penalty' => '50', 'other' => '15', 'deposit' => '1200'] as $type => $amount) {
            $this->post('/charges', ['month' => '2026-12', 'type' => $type, 'lease_contract_id' => $lease->id,
                'property_id' => $property->id, 'amount' => $amount, 'note' => 'Измислено', 'reason' => 'Причина'])->assertSessionHasNoErrors();
        }

        // Приход: 600 − 540 + 50 + 15 = 125; към наемателите: 600 + 50 + 15 + 1200 = 1 865.
        $this->get('/charges?month=2026-12')->assertOk()
            ->assertSee('<div class="value">125,00 €</div>', false)
            ->assertSee('1 865,00');
    }

    public function test_warnings_are_shown(): void
    {
        $this->rented(line: ['first_period_rent' => '300']);
        $property = $this->record(Property::class);
        app(OwnerContractService::class)->create([
            'company_id' => $this->company->id, 'type' => OwnerContractType::RepresentativePercent,
            'owner_id' => $this->record(Owner::class)->id, 'signed_on' => '2026-12-01', 'commission_percent' => '10',
            'payment_method' => PaymentMethod::Cash, 'lines' => [['property_id' => $property->id]],
        ]);
        app(LeaseContractService::class)->create([
            'company_id' => $this->company->id, 'tenant_id' => $this->record(Tenant::class, ['name' => 'Нов Измислен'])->id,
            'signed_on' => '2026-12-16', 'lines' => [['property_id' => $property->id, 'rent' => '600', 'first_period_rent' => '300']],
        ]);
        $this->actingAs($this->staff());
        $this->generated();

        $this->get('/charges?month=2026-12')->assertOk()
            ->assertSee('class="warning"', false)
            ->assertSee('309,68')
            ->assertSee('Нов Измислен');
    }

    public function test_rent_for_decision_is_listed(): void
    {
        $property = $this->record(Property::class);
        app(OwnerContractService::class)->create([
            'company_id' => $this->company->id, 'type' => OwnerContractType::RepresentativePercent,
            'owner_id' => $this->record(Owner::class)->id, 'signed_on' => '2026-12-05', 'commission_percent' => '10',
            'payment_method' => PaymentMethod::Cash, 'lines' => [['property_id' => $property->id]],
        ]);
        app(LeaseContractService::class)->create([
            'company_id' => $this->company->id, 'tenant_id' => $this->record(Tenant::class, ['name' => 'Чакащ Измислен'])->id,
            'signed_on' => '2026-01-10', 'lines' => [['property_id' => $property->id, 'rent' => '600']],
        ]);
        $this->actingAs($this->staff());
        $this->generated();

        $this->get('/charges?month=2026-12')->assertOk()
            ->assertSee(__('charges.screens.for_decision'))
            ->assertSee('Чакащ Измислен');
    }

    // Права и фирми

    public function test_read_only_staff_sees_but_does_not_generate_or_change(): void
    {
        [$property] = $this->rented();
        $this->actingAs($this->staff());
        $this->generated();
        $row = ChargeDraft::query()->where('type', ChargeType::Rent)->sole();

        $this->actingAs($this->readOnlyStaff());
        $this->get('/charges?month=2026-12')->assertOk()->assertSee('600,00')
            ->assertDontSee('action="/charges/generate"', false)->assertDontSee("/charges/{$row->id}/edit", false);
        $this->post('/charges/generate', ['month' => '2026-12'])->assertForbidden();
        $this->put("/charges/{$row->id}", ['amount' => '1', 'reason' => 'x'])->assertForbidden();
        $this->get('/charges/create?month=2026-12')->assertForbidden();
    }

    public function test_assigned_only_staff_sees_only_own_properties_and_does_not_generate(): void
    {
        [$mine] = $this->rented('Петър Измислен');
        $this->rented('Мария Измислена');
        $this->actingAs($this->staff());
        $this->generated();

        $user = $this->assignedOnlyStaff();
        UserProperty::query()->forceCreate(['company_id' => $this->company->id, 'user_id' => $user->id, 'property_id' => $mine->id]);
        $this->actingAs($user);

        $this->get('/charges?month=2026-12')->assertOk()->assertSee('Петър Измислен')->assertDontSee('Мария Измислена')
            ->assertDontSee('action="/charges/generate"', false);
        $this->post('/charges/generate', ['month' => '2026-12'])->assertForbidden();
    }

    public function test_other_company_rows_are_not_found(): void
    {
        $this->rented('Чужд Измислен', $this->otherCompany);
        // Потребителите се създават преди влизането (иначе защитата ги слага във фирмата на влезлия).
        $mine = $this->staff();
        $this->actingAs($this->staff([], $this->otherCompany));
        $this->generated();
        $foreign = ChargeDraft::query()->withoutGlobalScopes()->first();

        $this->actingAs($mine);
        $this->get('/charges?month=2026-12')->assertDontSee('Чужд Измислен');
        $this->get("/charges/{$foreign->id}/edit")->assertNotFound();
        $this->post("/charges/{$foreign->id}/remove", ['reason' => 'x'])->assertNotFound();
    }

    // Предстоящи начисления

    public function test_upcoming_charges_screen(): void
    {
        [$property, $lease] = $this->rented();
        $this->actingAs($this->staff());

        $this->get('/upcoming-charges')->assertOk()->assertSee(__('charges.upcoming.title'));
        $this->get('/upcoming-charges/create')->assertOk()->assertSee('name="month"', false);

        $this->post('/upcoming-charges', [
            'lease_contract_id' => $lease->id, 'property_id' => $property->id, 'type' => 'deposit',
            'month' => '12.2026', 'amount' => '800', 'note' => 'Първа вноска',
        ])->assertRedirect('/upcoming-charges');

        $upcoming = UpcomingCharge::query()->sole();
        $this->assertSame('2026-12-01', $upcoming->month->toDateString());
        $this->get('/upcoming-charges')->assertSee('Първа вноска')->assertSee('800,00')
            ->assertSee('1 200,00')->assertSee('400,00'); // договорен и остава

        $this->post("/upcoming-charges/{$upcoming->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/upcoming-charges/{$upcoming->id}/cancel", ['reason' => 'Отпада'])->assertRedirect('/upcoming-charges');
        $this->assertNotNull($upcoming->fresh()->cancelled_at);
    }

    public function test_upcoming_charge_of_another_company_is_not_found(): void
    {
        [$property, $lease] = $this->rented('Чужд Измислен', $this->otherCompany);
        $mine = $this->staff();
        $this->actingAs($this->staff([], $this->otherCompany));
        $this->post('/upcoming-charges', ['lease_contract_id' => $lease->id, 'property_id' => $property->id,
            'type' => 'penalty', 'month' => '12.2026', 'amount' => '50'])->assertRedirect('/upcoming-charges');
        $foreign = UpcomingCharge::query()->withoutGlobalScopes()->sole();

        $this->actingAs($mine);
        $this->get('/upcoming-charges')->assertDontSee('Чужд Измислен');
        $this->get("/upcoming-charges/{$foreign->id}/edit")->assertNotFound();
    }

    // Договорен наем за първия период във формите на договора за наем

    public function test_first_period_rent_in_the_lease_forms(): void
    {
        $tenant = $this->record(Tenant::class);
        $property = $this->record(Property::class);
        $added = $this->record(Property::class);
        $this->actingAs($this->staff());

        $this->get('/lease-contracts/create')->assertSee('name="lines[0][first_period_rent]"', false);
        $this->post('/lease-contracts', [
            'tenant_id' => $tenant->id, 'signed_on' => '16.12.2026',
            'lines' => [['property_id' => $property->id, 'rent' => '600', 'first_period_rent' => '300']],
        ])->assertSessionHasNoErrors();
        $contract = LeaseContract::query()->sole();
        $line = $contract->lines()->sole();
        $this->assertSame('300.00', $line->first_period_rent);
        $this->get("/lease-contracts/{$contract->id}")->assertSee('300,00');

        $this->put("/lease-contracts/{$contract->id}/correct", [
            'tenant_id' => $tenant->id, 'lines' => [$line->id => ['first_period_rent' => '310']],
        ])->assertSessionHasNoErrors();
        $this->assertSame('310.00', $line->fresh()->first_period_rent);

        $this->post("/lease-contracts/{$contract->id}/annexes", [
            'signed_on' => '20.12.2026', 'from' => '20.12.2026',
            'lines' => [['action' => 'add', 'property_id' => $added->id, 'rent' => '100', 'first_period_rent' => '40']],
        ])->assertSessionHasNoErrors();
        $this->assertSame('40.00', $contract->lines()->where('property_id', $added->id)->sole()->first_period_rent);
    }
}
