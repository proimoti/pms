<?php

namespace Tests\Feature\Screens;

use App\Enums\OwnerContractType;
use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\OwnerContractAnnex;
use App\Models\Property;
use App\Services\OwnerContractService;

/**
 * Екраните „Нов анекс“ и „Поправка“ на договор за управление (PROJECT.md, т. 2
 * „Договори“, „Поправки“, „Удължаване с анекс“).
 */
class OwnerContractAnnexScreensTest extends ScreenTestCase
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

    public function test_show_has_annex_and_correction_links_only_for_write_staff(): void
    {
        $contract = $this->contract([$this->record(Property::class)]);

        $this->actingAs($this->staff());
        $this->get("/owner-contracts/{$contract->id}")
            ->assertSee("/owner-contracts/{$contract->id}/annexes/create")
            ->assertSee("/owner-contracts/{$contract->id}/correct");

        $this->actingAs($this->readOnlyStaff());
        $this->get("/owner-contracts/{$contract->id}")
            ->assertDontSee("/owner-contracts/{$contract->id}/annexes/create")
            ->assertDontSee("/owner-contracts/{$contract->id}/correct");
        $this->get("/owner-contracts/{$contract->id}/annexes/create")->assertForbidden();
        $this->post("/owner-contracts/{$contract->id}/annexes", [])->assertForbidden();
        $this->get("/owner-contracts/{$contract->id}/correct")->assertForbidden();
        $this->put("/owner-contracts/{$contract->id}/correct", [])->assertForbidden();
    }

    public function test_annex_form_applies_several_changes_at_once(): void
    {
        $a = $this->record(Property::class);
        $new = $this->record(Property::class);
        $contract = $this->contract([$a], ['end_on' => '2026-12-31']);
        $this->actingAs($this->staff());

        $this->get("/owner-contracts/{$contract->id}/annexes/create")->assertOk()
            ->assertSee('value="1"', false)->assertSee('Добави още имоти');

        $this->post("/owner-contracts/{$contract->id}/annexes", [
            'number' => '1', 'signed_on' => '2026-05-20', 'note' => 'Измислено основание', 'from' => '2026-06-01',
            'commission_percent' => '12',
            'end_on' => '2027-12-31',
            'lines' => [
                ['action' => 'add', 'property_id' => $new->id, 'from' => '', 'fixed_amount' => ''],
                ['action' => 'add', 'property_id' => '', 'from' => '', 'fixed_amount' => ''], // празен ред
            ],
        ])->assertRedirect("/owner-contracts/{$contract->id}");

        $this->assertSame('12.00', $contract->commissionOn('2026-06-01'));
        $this->assertNotNull($contract->lineOn($new, '2026-06-01'));
        $this->assertSame('2027-12-31', $contract->fresh()->end_on->toDateString());
        $this->get("/owner-contracts/{$contract->id}")->assertSee('Анекс № 1')->assertSee('12,00 %')->assertSee('Измислено основание');
    }

    public function test_payee_changes_only_when_asked(): void
    {
        $contract = $this->contract([$this->record(Property::class)]);
        $before = $contract->payeeOn('2026-03-01')->beneficiary_id;
        $this->actingAs($this->staff());

        $this->post("/owner-contracts/{$contract->id}/annexes", [
            'signed_on' => '2026-05-20', 'from' => '2026-06-01', 'commission_percent' => '12',
            'payee' => ['beneficiary_id' => '', 'payment_method' => 'bank'],
        ])->assertSessionHasNoErrors();
        $this->assertSame(PaymentMethod::Cash, $contract->payeeOn('2026-06-01')->payment_method);

        $this->post("/owner-contracts/{$contract->id}/annexes", [
            'signed_on' => '2026-06-20', 'from' => '2026-07-01', 'change_payee' => '1',
            'payee' => ['beneficiary_id' => '', 'payment_method' => 'bank'],
        ])->assertSessionHasNoErrors()->assertSessionHas('warning');

        $this->assertSame(PaymentMethod::Bank, $contract->payeeOn('2026-07-01')->payment_method);
        $this->assertSame($before, $contract->payeeOn('2026-07-01')->beneficiary_id);
        // Номерът по подразбиране е следващият.
        $this->assertSame([1, 2], OwnerContractAnnex::query()->orderBy('number')->pluck('number')->all());
    }

    public function test_a_wrong_annex_saves_nothing_and_shows_the_error(): void
    {
        $busy = $this->record(Property::class);
        $this->contract([$busy]);
        $contract = $this->contract([$this->record(Property::class)]);
        $this->actingAs($this->staff());

        $this->from("/owner-contracts/{$contract->id}/annexes/create")->post("/owner-contracts/{$contract->id}/annexes", [
            'number' => '7', 'signed_on' => '2026-05-20', 'from' => '2026-06-01', 'commission_percent' => '12',
            'lines' => [['action' => 'add', 'property_id' => $busy->id]],
        ])->assertRedirect("/owner-contracts/{$contract->id}/annexes/create")->assertSessionHasErrors('lines');

        $this->assertSame(0, $contract->annexes()->count());
        $this->get("/owner-contracts/{$contract->id}/annexes/create")->assertSee('value="7"', false);
    }

    public function test_correction_form_changes_values_in_place(): void
    {
        $property = $this->record(Property::class);
        $contract = $this->contract([$property]);
        $line = $contract->lines()->sole();
        $commission = $contract->commissions()->sole();
        $payee = $contract->payees()->sole();
        $newOwner = $this->record(Owner::class, ['name' => 'Мария Измислена']);
        $this->actingAs($this->staff());

        $this->get("/owner-contracts/{$contract->id}/correct")->assertOk()
            ->assertSee('10.01.2026')->assertSee('Мария Измислена')->assertSee('докато няма начисления');

        $this->put("/owner-contracts/{$contract->id}/correct", [
            'owner_id' => $newOwner->id,
            'lines' => [$line->id => ['property_id' => $property->id, 'valid_from' => '2026-01-01']],
            'commissions' => [$commission->id => '9,5'],
            'payees' => [$payee->id => ['beneficiary_id' => '', 'payment_method' => 'cash']],
        ])->assertRedirect("/owner-contracts/{$contract->id}");

        $contract->refresh();
        $this->assertSame($newOwner->id, $contract->owner_id);
        $this->assertSame('9.50', $contract->commissionOn('2026-01-01'));
        $this->assertSame($newOwner->id, $contract->payeeOn('2026-01-01')->beneficiary->owner_id);
        $this->assertSame(1, $contract->commissions()->count());
    }

    public function test_wrong_correction_saves_nothing(): void
    {
        $contract = $this->contract([$this->record(Property::class)]);
        $commission = $contract->commissions()->sole();
        $line = $contract->lines()->sole();
        $this->actingAs($this->staff());

        $this->put("/owner-contracts/{$contract->id}/correct", [
            'owner_id' => $contract->owner_id,
            'lines' => [$line->id => ['valid_from' => '2026-01-01']],
            'commissions' => [$commission->id => '0'],
        ])->assertSessionHasErrors('commission_percent');

        $this->assertSame('2026-01-10', $line->fresh()->valid_from->toDateString());
    }

    public function test_other_company_gets_not_found(): void
    {
        $contract = $this->contract([$this->record(Property::class, [], $this->otherCompany)], [
            'owner_id' => $this->record(Owner::class, [], $this->otherCompany)->id,
        ], $this->otherCompany);
        $this->actingAs($this->staff());

        $this->get("/owner-contracts/{$contract->id}/annexes/create")->assertNotFound();
        $this->get("/owner-contracts/{$contract->id}/correct")->assertNotFound();
    }
}
