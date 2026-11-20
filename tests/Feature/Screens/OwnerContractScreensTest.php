<?php

namespace Tests\Feature\Screens;

use App\Enums\OwnerContractType;
use App\Enums\PaymentMethod;
use App\Models\Beneficiary;
use App\Models\Company;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\Property;
use App\Models\UserProperty;
use App\Services\OwnerContractService;

/**
 * Екраните „Договори за управление“ (PROJECT.md, т. 2 „Договори“, „Договори,
 * уточнения“, „Поправки“, „Плащане по банка без IBAN“): списък, нов договор,
 * преглед с историята, поправка на информационните полета, прекратяване, архив.
 */
class OwnerContractScreensTest extends ScreenTestCase
{
    private function contract(array $properties, array $overrides = [], ?Company $company = null): OwnerContract
    {
        $company ??= $this->company;

        return app(OwnerContractService::class)->create(array_merge([
            'company_id' => $company->id,
            'type' => OwnerContractType::RepresentativePercent,
            'owner_id' => $this->record(Owner::class, ['name' => 'Иван Измислен'], $company)->id,
            'signed_on' => '2026-01-10',
            'commission_percent' => '10',
            'payment_method' => PaymentMethod::Cash,
            'lines' => array_map(fn (Property $p) => ['property_id' => $p->id], $properties),
        ], $overrides));
    }

    // Списък

    public function test_list_shows_own_contracts_with_owner_and_properties(): void
    {
        $property = $this->record(Property::class);
        $this->contract([$property], ['number' => 17]);
        $this->contract([$this->record(Property::class, [], $this->otherCompany)], [
            'owner_id' => $this->record(Owner::class, ['name' => 'Чужд Собственик'], $this->otherCompany)->id,
        ], $this->otherCompany);
        $this->actingAs($this->staff());

        $this->get('/owner-contracts')->assertOk()
            ->assertSee('Иван Измислен')->assertSee('Представител, %')->assertSee("/owner-contracts/")
            ->assertSee((string) $property->number)
            ->assertDontSee('Чужд Собственик');
    }

    public function test_search_by_contract_number_owner_or_property_number(): void
    {
        $first = $this->record(Property::class);
        $second = $this->record(Property::class);
        $this->contract([$first], ['number' => 31]);
        $this->contract([$second], ['number' => 32, 'owner_id' => $this->record(Owner::class, ['name' => 'Мария Измислена'])->id]);
        $this->actingAs($this->staff());

        $this->get('/owner-contracts?q=Мария')->assertSee('Мария Измислена')->assertDontSee('Иван Измислен');
        $this->get('/owner-contracts?q=31')->assertSee('Иван Измислен')->assertDontSee('Мария Измислена');
        $this->get('/owner-contracts?q='.$second->number)->assertSee('Мария Измислена');
    }

    // Нов договор

    public function test_staff_creates_a_percent_contract_from_the_form(): void
    {
        $owner = $this->record(Owner::class, ['name' => 'Иван Измислен']);
        $a = $this->record(Property::class);
        $b = $this->record(Property::class);
        $this->actingAs($this->staff());

        $this->get('/owner-contracts/create')->assertOk()->assertSee('Иван Измислен')->assertSee('Представител, %');

        $response = $this->post('/owner-contracts', [
            'type' => 'representative_percent',
            'owner_id' => $owner->id,
            'signed_on' => '2026-01-10',
            'commission_percent' => '12,5',
            'payment_method' => 'cash',
            'lines' => [
                ['property_id' => $a->id, 'start_on' => ''],
                ['property_id' => $b->id, 'start_on' => '2026-02-01'],
                ['property_id' => '', 'start_on' => ''], // празен ред се пропуска
            ],
        ]);

        $contract = OwnerContract::query()->sole();
        $response->assertRedirect("/owner-contracts/{$contract->id}");
        $this->assertSame('12.50', $contract->commissionOn('2026-03-01'));
        $this->assertSame(2, $contract->lines()->count());
        $this->assertSame($owner->id, $contract->payeeOn('2026-03-01')->beneficiary->owner_id);

        $this->get("/owner-contracts/{$contract->id}")
            ->assertSee('12,50 %')->assertSee('Иван Измислен')->assertSee('10.01.2026')->assertSee('01.02.2026');
    }

    public function test_staff_creates_a_fixed_contract_with_an_amount_per_property(): void
    {
        $owner = $this->record(Owner::class);
        $property = $this->record(Property::class);
        $payee = $this->record(Beneficiary::class, ['name' => 'Измислен Получател']);
        $this->actingAs($this->staff());

        $this->post('/owner-contracts', [
            'type' => 'representative_fixed',
            'owner_id' => $owner->id,
            'signed_on' => '2026-01-10',
            'payment_method' => 'cash',
            'beneficiary_id' => $payee->id,
            'lines' => [['property_id' => $property->id, 'fixed_amount' => '460,00']],
        ])->assertSessionHasNoErrors();

        $contract = OwnerContract::query()->sole();
        $this->assertSame('460.00', $contract->lineOn($property, '2026-03-01')->fixed_amount);
        $this->get("/owner-contracts/{$contract->id}")->assertSee('460,00')->assertSee('Измислен Получател');
    }

    public function test_wrong_input_shows_the_error_and_creates_nothing(): void
    {
        $owner = $this->record(Owner::class);
        $property = $this->record(Property::class);
        $this->actingAs($this->staff());

        $this->from('/owner-contracts/create')->post('/owner-contracts', [
            'type' => 'representative_percent', 'owner_id' => $owner->id, 'signed_on' => '2026-01-10',
            'payment_method' => 'cash', 'lines' => [['property_id' => $property->id]],
        ])->assertRedirect('/owner-contracts/create')->assertSessionHasErrors('commission_percent');

        $this->post('/owner-contracts', [
            'type' => 'representative_percent', 'owner_id' => $owner->id, 'signed_on' => '2026-01-10',
            'commission_percent' => '10', 'payment_method' => 'cash', 'lines' => [['property_id' => '']],
        ])->assertSessionHasErrors('lines');

        $this->assertSame(0, OwnerContract::query()->count());
    }

    public function test_busy_property_is_refused_with_a_clear_message(): void
    {
        $property = $this->record(Property::class);
        $first = $this->contract([$property]);
        $this->actingAs($this->staff());

        $this->post('/owner-contracts', [
            'type' => 'representative_percent', 'owner_id' => $first->owner_id, 'signed_on' => '2026-05-01',
            'commission_percent' => '10', 'payment_method' => 'cash', 'lines' => [['property_id' => $property->id]],
        ])->assertSessionHasErrors(['lines' => "Имот № {$property->number} вече е в договор за управление № {$first->number} за този период."]);
    }

    public function test_bank_payment_without_iban_is_saved_with_a_warning(): void
    {
        $owner = $this->record(Owner::class);
        $property = $this->record(Property::class);
        $this->actingAs($this->staff());

        $response = $this->post('/owner-contracts', [
            'type' => 'representative_percent', 'owner_id' => $owner->id, 'signed_on' => '2026-01-10',
            'commission_percent' => '10', 'payment_method' => 'bank', 'lines' => [['property_id' => $property->id]],
        ]);

        $contract = OwnerContract::query()->sole();
        $response->assertSessionHas('warning');
        $this->followRedirects($response)->assertSee('няма IBAN');
        $this->assertSame(PaymentMethod::Bank, $contract->payeeOn('2026-03-01')->payment_method);
    }

    public function test_own_contract_needs_no_owner(): void
    {
        $property = $this->record(Property::class);
        $this->actingAs($this->staff());

        $this->post('/owner-contracts', [
            'type' => 'own', 'signed_on' => '2026-01-10', 'lines' => [['property_id' => $property->id]],
        ])->assertSessionHasNoErrors();

        $this->assertNull(OwnerContract::query()->sole()->owner_id);
    }

    // Преглед

    public function test_show_has_the_history_of_percent_lines_annexes_and_extensions(): void
    {
        $a = $this->record(Property::class);
        $b = $this->record(Property::class);
        $contract = $this->contract([$a], ['end_on' => '2026-12-31']);
        $service = app(OwnerContractService::class);
        $annex = $service->addAnnex($contract, 3, '2026-05-20', 'Измислено основание');
        $service->changeCommission($annex, '2026-06-01', '12');
        $service->addProperty($annex, $b, '2026-06-01');
        $extension = $service->addAnnex($contract, 4, '2026-11-15');
        $service->extendTerm($extension, '2027-12-31');
        $this->actingAs($this->staff());

        $this->get("/owner-contracts/{$contract->id}")->assertOk()
            ->assertSee('10,00 %')->assertSee('12,00 %')
            ->assertSee('Анекс № 3')->assertSee('Измислено основание')->assertSee('20.05.2026')
            ->assertSee('31.12.2026')->assertSee('31.12.2027')->assertSee('Анекс № 4')
            ->assertSee((string) $b->number);
    }

    public function test_other_company_gets_not_found(): void
    {
        $contract = $this->contract([$this->record(Property::class, [], $this->otherCompany)], [
            'owner_id' => $this->record(Owner::class, [], $this->otherCompany)->id,
        ], $this->otherCompany);
        $this->actingAs($this->staff());

        $this->get("/owner-contracts/{$contract->id}")->assertNotFound();
        $this->get("/owner-contracts/{$contract->id}/edit")->assertNotFound();
    }

    public function test_assigned_staff_sees_only_contracts_with_an_assigned_property(): void
    {
        $mine = $this->record(Property::class);
        $visible = $this->contract([$mine], ['number' => 41]);
        $hidden = $this->contract([$this->record(Property::class)], ['number' => 42]);
        $user = $this->assignedOnlyStaff();
        UserProperty::query()->forceCreate(['company_id' => $this->company->id, 'user_id' => $user->id, 'property_id' => $mine->id]);
        $this->actingAs($user);

        $this->get("/owner-contracts/{$visible->id}")->assertOk();
        $this->get("/owner-contracts/{$hidden->id}")->assertNotFound();
    }

    // Поправки, прекратяване, архив

    public function test_info_fields_are_corrected_and_the_contract_is_terminated(): void
    {
        $contract = $this->contract([$this->record(Property::class)], ['end_on' => '2026-12-31']);
        $this->actingAs($this->staff());

        $this->get("/owner-contracts/{$contract->id}/edit")->assertOk()->assertDontSee('name="type"', false);

        $this->put("/owner-contracts/{$contract->id}", [
            'number' => $contract->number, 'signed_on' => '2026-01-09', 'end_on' => '2026-11-30',
            'terminated_on' => '2026-10-01', 'note' => 'Измислена бележка',
        ])->assertRedirect("/owner-contracts/{$contract->id}");

        $contract->refresh();
        $this->assertSame('2026-01-09', $contract->signed_on->toDateString());
        $this->assertSame('2026-11-30', $contract->end_on->toDateString());
        $this->assertSame('2026-10-01', $contract->terminated_on->toDateString());
        $this->assertSame('Измислена бележка', $contract->note);
        $this->get("/owner-contracts/{$contract->id}")->assertSee('Прекратен');
    }

    public function test_end_date_cannot_be_corrected_after_an_extension(): void
    {
        $contract = $this->contract([$this->record(Property::class)], ['end_on' => '2026-12-31']);
        $service = app(OwnerContractService::class);
        $service->extendTerm($service->addAnnex($contract, 1, '2026-11-15'), '2027-12-31');
        $this->actingAs($this->staff());

        $this->get("/owner-contracts/{$contract->id}/edit")->assertSee('Срокът е удължаван с анекс');

        $this->put("/owner-contracts/{$contract->id}", [
            'number' => $contract->number, 'signed_on' => '2026-01-10', 'end_on' => '2028-01-31',
        ])->assertSessionHasErrors('end_on');
        $this->assertSame('2027-12-31', $contract->fresh()->end_on->toDateString());
    }

    public function test_termination_that_overlaps_another_contract_is_refused(): void
    {
        $property = $this->record(Property::class);
        $first = $this->contract([$property]);
        $first->forceFill(['terminated_on' => '2026-06-01'])->save();
        $this->contract([$property], ['signed_on' => '2026-06-01']);
        $this->actingAs($this->staff());

        $this->put("/owner-contracts/{$first->id}", [
            'number' => $first->number, 'signed_on' => '2026-01-10', 'terminated_on' => '',
        ])->assertSessionHasErrors('lines');
        $this->assertSame('2026-06-01', $first->fresh()->terminated_on->toDateString());
    }

    public function test_archive_and_restore(): void
    {
        $contract = $this->contract([$this->record(Property::class)]);
        $this->actingAs($this->staff());

        $this->post("/owner-contracts/{$contract->id}/archive")->assertRedirect("/owner-contracts/{$contract->id}");
        $this->assertTrue($contract->fresh()->isArchived());
        $this->get('/owner-contracts?archived=1')->assertSee((string) $contract->number);
        $this->post("/owner-contracts/{$contract->id}/restore");
        $this->assertFalse($contract->fresh()->isArchived());
    }

    public function test_read_only_staff_sees_but_does_not_change(): void
    {
        $contract = $this->contract([$this->record(Property::class)]);
        $this->actingAs($this->readOnlyStaff());

        $this->get('/owner-contracts')->assertOk()->assertDontSee('/owner-contracts/create');
        $this->get("/owner-contracts/{$contract->id}")->assertOk()->assertDontSee("/owner-contracts/{$contract->id}/edit");
        $this->get('/owner-contracts/create')->assertForbidden();
        $this->post("/owner-contracts/{$contract->id}/archive")->assertForbidden();
        $this->put("/owner-contracts/{$contract->id}", ['note' => 'x'])->assertForbidden();
    }

    public function test_menu_has_the_new_sections(): void
    {
        $this->actingAs($this->staff());

        $this->get('/dashboard')->assertSee('Договори за управление')->assertSee('Бенефициенти');
    }
}
