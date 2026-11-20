<?php

namespace Tests\Feature\Screens;

use App\Enums\OwnerContractType;
use App\Enums\PaymentMethod;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\Property;
use App\Services\OwnerContractService;

/**
 * Всички дати се въвеждат и показват като дд.мм.гггг, независимо от езика на
 * браузъра, с проверка за грешна дата (PROJECT.md, т. 2 „Дати в интерфейса“).
 */
class DateInputTest extends ScreenTestCase
{
    private function contract(): OwnerContract
    {
        return app(OwnerContractService::class)->create([
            'company_id' => $this->company->id,
            'type' => OwnerContractType::RepresentativePercent,
            'owner_id' => $this->record(Owner::class)->id,
            'signed_on' => '2026-01-10',
            'end_on' => '2026-12-31',
            'commission_percent' => '10',
            'payment_method' => PaymentMethod::Cash,
            'lines' => [['property_id' => $this->record(Property::class)->id]],
        ]);
    }

    public function test_contract_is_created_with_dates_in_day_month_year(): void
    {
        $owner = $this->record(Owner::class);
        $a = $this->record(Property::class);
        $b = $this->record(Property::class);
        $this->actingAs($this->staff());

        $this->post('/owner-contracts', [
            'type' => 'representative_percent', 'owner_id' => $owner->id,
            'signed_on' => '10.01.2026', 'end_on' => '31.12.2026',
            'commission_percent' => '10', 'payment_method' => 'cash',
            'lines' => [['property_id' => $a->id], ['property_id' => $b->id, 'start_on' => '1.2.2026']],
        ])->assertSessionHasNoErrors();

        $contract = OwnerContract::query()->sole();
        $this->assertSame('2026-01-10', $contract->signed_on->toDateString());
        $this->assertSame('2026-12-31', $contract->end_on->toDateString());
        $this->assertSame('2026-02-01', $contract->lineOn($b, '2026-02-01')->valid_from->toDateString());
    }

    public function test_a_date_that_does_not_exist_is_refused_with_a_clear_message(): void
    {
        $owner = $this->record(Owner::class);
        $property = $this->record(Property::class);
        $this->actingAs($this->staff());

        foreach (['31.02.2026', '10/01/2026', 'abc'] as $wrong) {
            $this->from('/owner-contracts/create')->post('/owner-contracts', [
                'type' => 'representative_percent', 'owner_id' => $owner->id, 'signed_on' => $wrong,
                'commission_percent' => '10', 'payment_method' => 'cash', 'lines' => [['property_id' => $property->id]],
            ])->assertSessionHasErrors(['signed_on' => 'Полето „Дата на договора (подписване)“ трябва да е вярна дата във вида дд.мм.гггг (например 31.12.2026).']);
        }

        $this->post('/owner-contracts', [
            'type' => 'representative_percent', 'owner_id' => $owner->id, 'signed_on' => '10.01.2026',
            'commission_percent' => '10', 'payment_method' => 'cash',
            'lines' => [['property_id' => $property->id, 'start_on' => '30.02.2026']],
        ])->assertSessionHasErrors('lines.0.start_on');

        $this->assertSame(0, OwnerContract::query()->count());
    }

    public function test_after_an_error_the_dates_come_back_as_typed_and_as_day_month_year(): void
    {
        $owner = $this->record(Owner::class);
        $property = $this->record(Property::class);
        $this->actingAs($this->staff());

        $this->from('/owner-contracts/create')->post('/owner-contracts', [
            'type' => 'representative_percent', 'owner_id' => $owner->id,
            'signed_on' => '10.01.2026', 'end_on' => '31.02.2026',
            'commission_percent' => '10', 'payment_method' => 'cash', 'lines' => [['property_id' => $property->id]],
        ]);

        $this->get('/owner-contracts/create')
            ->assertSee('value="10.01.2026"', false)
            ->assertSee('value="31.02.2026"', false);
    }

    public function test_no_form_uses_the_browser_date_field_and_values_are_day_month_year(): void
    {
        $contract = $this->contract();
        $service = app(OwnerContractService::class);
        $service->removeProperty($service->addAnnex($contract, 1, '2026-03-15'), $contract->lines()->sole()->property_id, '2026-04-01');
        $this->actingAs($this->staff());

        foreach (['/owner-contracts/create', "/owner-contracts/{$contract->id}/edit",
            "/owner-contracts/{$contract->id}/annexes/create", "/owner-contracts/{$contract->id}/correct"] as $url) {
            $page = $this->get($url)->assertOk()->getContent();
            // Календарът на браузъра е само зад бутона (PROJECT.md, 20.11.2026): поле без име, не се праща.
            $this->assertDoesNotMatchRegularExpression('/<input[^>]*type="date"[^>]*name=/', $page, $url);
            $this->assertStringContainsString('placeholder="дд.мм.гггг"', $page, $url);
        }

        $this->get("/owner-contracts/{$contract->id}/edit")
            ->assertSee('value="10.01.2026"', false)->assertSee('value="31.12.2026"', false);
        $this->get("/owner-contracts/{$contract->id}/correct")
            ->assertSee('value="10.01.2026"', false)->assertSee('value="01.04.2026"', false);
    }

    public function test_edit_annex_and_correction_accept_day_month_year(): void
    {
        $contract = $this->contract();
        $line = $contract->lines()->sole();
        $this->actingAs($this->staff());

        $this->put("/owner-contracts/{$contract->id}", [
            'number' => $contract->number, 'signed_on' => '09.01.2026', 'end_on' => '30.11.2026', 'terminated_on' => '01.10.2026',
        ])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-01', $contract->fresh()->terminated_on->toDateString());

        $this->put("/owner-contracts/{$contract->id}", [
            'number' => $contract->number, 'signed_on' => '09.01.2026', 'terminated_on' => '31.09.2026',
        ])->assertSessionHasErrors('terminated_on');

        $this->post("/owner-contracts/{$contract->id}/annexes", [
            'signed_on' => '20.05.2026', 'from' => '01.06.2026', 'commission_percent' => '12',
        ])->assertSessionHasNoErrors();
        $this->assertSame('12.00', $contract->commissionOn('2026-06-01'));

        $this->put("/owner-contracts/{$contract->id}/correct", [
            'owner_id' => $contract->owner_id, 'lines' => [$line->id => ['valid_from' => '05.01.2026']],
        ])->assertSessionHasNoErrors();
        $this->assertSame('2026-01-05', $line->fresh()->valid_from->toDateString());
    }
}
