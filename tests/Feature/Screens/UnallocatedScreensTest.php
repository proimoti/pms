<?php

namespace Tests\Feature\Screens;

use App\Enums\LedgerKind;
use App\Models\LedgerEntry;
use App\Models\PaidUtilityBill;
use App\Models\Property;
use App\Models\Provider;
use App\Models\User;
use App\Models\UserProperty;
use App\Models\UtilityAccount;
use App\Models\UtilityDecision;
use App\Services\LeaseContractService;
use App\Services\Utilities\UtilityAccountService;
use App\Services\Utilities\UtilityImporter;
use Tests\Support\UtilityFiles;

/**
 * Екранът „Неразпределени битови сметки“ (PROJECT.md, част 5, 23.11.2026):
 * основен списък с бутоните, секция „Чакат месеца“ без бутони, отмяна на
 * решение. Само измислени данни.
 */
class UnallocatedScreensTest extends ScreenTestCase
{
    use UtilityFiles;

    private User $user;

    private Property $flat;

    private UtilityAccount $meter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->staff();
        $this->actingAs($this->user);
        $this->flat = $this->record(Property::class, ['number' => 101]);
        Provider::query()->create(['name' => 'Измислено Електро', 'easypay_name' => 'IZM EL']);
        $this->meter = app(UtilityAccountService::class)->create([
            'provider_id' => Provider::query()->sole()->id, 'number' => '0012345', 'kind' => 'electricity', 'billing' => 'actual',
            'valid_from' => '2026-01-01', 'properties' => [['property_id' => $this->flat->id]],
        ]);
    }

    private function bill(float $amount, int $ref = 1, string $date = '2026-12-05 10:00:00'): PaidUtilityBill
    {
        app(UtilityImporter::class)->import($this->easypayFile([
            ['ref' => $ref, 'date' => $date, 'number' => 12345, 'provider' => 'IZM EL', 'amount' => $amount],
        ]), 'f.xlsx');

        return PaidUtilityBill::query()->where('reference', (string) $ref)->sole();
    }

    public function test_list_shows_the_bill_with_amounts_reason_and_the_buttons(): void
    {
        $this->bill(48.20);

        $this->get('/utility-unallocated')->assertOk()
            ->assertSee(__('registry.sections.utility-unallocated'))
            ->assertSee('Измислено Електро')->assertSee('№ 0012345')->assertSee('№ 101')->assertSee('48,20')
            ->assertSee(__('utilities.unallocated.reasons.vacant'))
            ->assertSee(__('utilities.unallocated.to_manager'))
            ->assertDontSee(__('utilities.unallocated.to_next_tenant'), false);
    }

    public function test_waiting_bills_are_in_their_own_section_without_buttons(): void
    {
        app(LeaseContractService::class)->create([
            'tenant_id' => $this->record(\App\Models\Tenant::class)->id, 'signed_on' => '2026-01-10',
            'lines' => [['property_id' => $this->flat->id, 'rent' => '500']],
        ]);
        $this->bill(90.00);

        $this->get('/utility-unallocated')->assertOk()
            ->assertSee(__('utilities.unallocated.waiting'))->assertSee('90,00')
            ->assertSee(__('utilities.unallocated.waiting_reasons.month', ['month' => 'Декември 2026']))
            ->assertDontSee(__('utilities.unallocated.to_manager'));
    }

    public function test_staff_decides_for_the_manager_and_cancels(): void
    {
        $bill = $this->bill(48.20);

        $this->post('/utility-unallocated/manager', ['bill' => $bill->id, 'property' => $this->flat->id, 'reason' => 'празен'])
            ->assertRedirect('/utility-unallocated')->assertSessionHasNoErrors();
        $decision = UtilityDecision::query()->sole();
        $this->assertSame('-48.20', LedgerEntry::query()->where('kind', LedgerKind::ManagerCost)->sole()->amount);
        $this->get('/utility-unallocated')->assertSee(__('utilities.unallocated.decisions'))->assertSee('празен');

        $this->post("/utility-decisions/{$decision->id}/cancel", ['reason' => 'грешка'])->assertRedirect('/utility-unallocated');
        $this->assertNotNull($decision->fresh()->cancelled_at);
        $this->assertSame(['-48.20', '48.20'], LedgerEntry::query()->where('kind', LedgerKind::ManagerCost)->orderBy('id')->pluck('amount')->all());
    }

    public function test_decision_without_reason_is_refused(): void
    {
        $bill = $this->bill(48.20);

        $this->post('/utility-unallocated/manager', ['bill' => $bill->id, 'property' => $this->flat->id])->assertSessionHasErrors('reason');
        $this->assertSame(0, UtilityDecision::query()->count());
    }

    public function test_read_only_staff_sees_the_list_without_buttons_and_cannot_post(): void
    {
        $bill = $this->bill(48.20);
        $this->actingAs($this->readOnlyStaff());

        $this->get('/utility-unallocated')->assertOk()->assertSee('48,20')->assertDontSee(__('utilities.unallocated.to_manager'));
        $this->post('/utility-unallocated/manager', ['bill' => $bill->id, 'property' => $this->flat->id, 'reason' => 'х'])->assertForbidden();
    }

    public function test_assigned_only_staff_sees_only_their_properties(): void
    {
        $this->bill(48.20);
        $other = $this->record(Property::class, ['number' => 202]);
        app(UtilityAccountService::class)->create([
            'provider_id' => Provider::query()->sole()->id, 'number' => '777', 'kind' => 'water', 'billing' => 'actual',
            'valid_from' => '2026-01-01', 'properties' => [['property_id' => $other->id]],
        ]);
        app(UtilityImporter::class)->import($this->easypayFile([['ref' => 2, 'date' => '2026-12-06 10:00:00', 'number' => 777, 'provider' => 'IZM EL', 'amount' => 31.11]]), 'f.xlsx');

        $assigned = $this->assignedOnlyStaff();
        (new UserProperty)->forceFill(['company_id' => $this->company->id, 'user_id' => $assigned->id, 'property_id' => $this->flat->id])->save();
        $this->actingAs($assigned);

        $this->get('/utility-unallocated')->assertOk()->assertSee('48,20')->assertDontSee('31,11');
    }

    public function test_company_a_does_not_see_or_cancel_decisions_of_company_b(): void
    {
        $bill = $this->bill(48.20);
        $this->post('/utility-unallocated/manager', ['bill' => $bill->id, 'property' => $this->flat->id, 'reason' => 'празен']);
        $decision = UtilityDecision::query()->sole();

        // Служителят на другата фирма се създава без влязъл потребител (иначе защитата го слага в нашата фирма).
        auth()->logout();
        $foreignStaff = $this->staff([], $this->otherCompany);
        $this->assertSame($this->otherCompany->id, $foreignStaff->company_id);
        $this->actingAs($foreignStaff);
        $this->get('/utility-unallocated')->assertOk()->assertDontSee('48,20')->assertDontSee('№ 0012345');
        $this->post("/utility-decisions/{$decision->id}/cancel", ['reason' => 'опит'])->assertNotFound();
        $this->post('/utility-unallocated/manager', ['bill' => $bill->id, 'property' => $this->flat->id, 'reason' => 'опит'])->assertSessionHasErrors('decision');
        $this->assertNull($decision->fresh()->cancelled_at);
    }

    public function test_menu_has_the_unallocated_list(): void
    {
        $this->get('/utility-unallocated')->assertSee('href="/utility-unallocated"', false);
    }
}
