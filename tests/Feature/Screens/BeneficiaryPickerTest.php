<?php

namespace Tests\Feature\Screens;

use App\Enums\OwnerContractType;
use App\Enums\PaymentMethod;
use App\Models\Beneficiary;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\Property;
use App\Services\OwnerContractService;

/**
 * Бенефициентът във формите на договора и анекса (PROJECT.md, т. 2
 * „Бенефициент в договора“): списък със „Самият собственик“ (по подразбиране)
 * и всички бенефициенти на фирмата, и „+ Нов бенефициент“ направо от формата.
 */
class BeneficiaryPickerTest extends ScreenTestCase
{
    // Измислен IBAN с вярна контролна сума (примерен, не на реална сметка).
    private const IBAN = 'BG80BNBG96611020345678';

    public function test_contract_form_lists_the_owner_himself_first_and_all_beneficiaries_of_the_company(): void
    {
        $owner = $this->record(Owner::class, ['name' => 'Иван Измислен']);
        $this->record(Beneficiary::class, ['name' => 'Измислен Получател']);
        $this->record(Beneficiary::class, ['name' => 'Иван Измислен', 'owner_id' => $owner->id]);
        $this->record(Beneficiary::class, ['name' => 'Архивиран Получател', 'archived_at' => now()]);
        $this->record(Beneficiary::class, ['name' => 'Чужд Получател'], $this->otherCompany);
        $this->actingAs($this->staff());

        $page = $this->get('/owner-contracts/create')->assertOk()
            ->assertSee('Самият собственик')
            ->assertSee('Измислен Получател')
            ->assertDontSee('Архивиран Получател')
            ->assertDontSee('Чужд Получател')
            ->assertDontSee('Празно = самият собственик')
            ->getContent();

        // „Самият собственик“ е първата и избраната възможност.
        $this->assertMatchesRegularExpression('/<select id="beneficiary_id" name="beneficiary_id">\s*<option value="" selected>Самият собственик<\/option>/u', $page);
        // Свързаният със собственик запис знае чий е (скрива се, когато този собственик е избран).
        $this->assertStringContainsString('data-owner="'.$owner->id.'"', $page);
    }

    public function test_annex_form_lists_all_beneficiaries_without_a_second_copy_of_the_owner(): void
    {
        $owner = $this->record(Owner::class, ['name' => 'Иван Измислен']);
        $contract = app(OwnerContractService::class)->create([
            'company_id' => $this->company->id, 'type' => OwnerContractType::RepresentativePercent, 'owner_id' => $owner->id,
            'signed_on' => '2026-01-10', 'commission_percent' => '10', 'payment_method' => PaymentMethod::Cash,
            'lines' => [['property_id' => $this->record(Property::class)->id]],
        ]);
        $this->record(Beneficiary::class, ['name' => 'Измислен Получател']);
        $this->actingAs($this->staff());

        $page = $this->get("/owner-contracts/{$contract->id}/annexes/create")->assertOk()
            ->assertSee('Самият собственик')->assertSee('Измислен Получател')->getContent();

        // Собственикът на договора е „Самият собственик“, не втори ред със своето име.
        $linked = Beneficiary::query()->where('owner_id', $owner->id)->sole();
        $this->assertStringNotContainsString('<option value="'.$linked->id.'"', $page, 'Собственикът е два пъти в списъка.');
        $this->assertStringNotContainsString('>Иван Измислен</option>', $page, 'Собственикът е два пъти в списъка.');
    }

    public function test_forms_have_a_new_beneficiary_panel_with_iban_only_for_the_right(): void
    {
        $contract = app(OwnerContractService::class)->create([
            'company_id' => $this->company->id, 'type' => OwnerContractType::RepresentativePercent,
            'owner_id' => $this->record(Owner::class)->id, 'signed_on' => '2026-01-10', 'commission_percent' => '10',
            'payment_method' => PaymentMethod::Cash, 'lines' => [['property_id' => $this->record(Property::class)->id]],
        ]);

        $this->actingAs($this->staff(['can_view_sensitive' => true]));
        foreach (['/owner-contracts/create', "/owner-contracts/{$contract->id}/annexes/create"] as $url) {
            $this->get($url)->assertSee('+ Нов бенефициент')->assertSee('id="new-beneficiary-iban"', false);
        }

        $this->actingAs($this->staff());
        $this->get('/owner-contracts/create')->assertSee('+ Нов бенефициент')->assertDontSee('id="new-beneficiary-iban"', false);
    }

    public function test_new_beneficiary_is_created_from_the_form_without_leaving_it(): void
    {
        $this->actingAs($this->staff(['can_view_sensitive' => true]));

        $this->postJson('/beneficiaries', ['name' => 'Нов Измислен Получател', 'iban' => self::IBAN])
            ->assertCreated()
            ->assertJson(['name' => 'Нов Измислен Получател'])
            ->assertJsonMissing(['iban' => self::IBAN]);

        $beneficiary = Beneficiary::query()->sole();
        $this->assertSame(self::IBAN, $beneficiary->iban);
        $this->assertSame($beneficiary->id, $this->postJson('/beneficiaries', ['name' => 'Втори'])->json('id') - 1);
    }

    public function test_quick_create_checks_like_the_beneficiary_screen(): void
    {
        $this->actingAs($this->staff(['can_view_sensitive' => true]));
        $this->postJson('/beneficiaries', ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/beneficiaries', ['name' => 'Х', 'iban' => 'BG00BNBG96611020345678'])->assertUnprocessable()->assertJsonValidationErrors('iban');

        $this->actingAs($this->staff());
        $this->postJson('/beneficiaries', ['name' => 'Х', 'iban' => self::IBAN])->assertUnprocessable()->assertJsonValidationErrors('iban');

        $this->actingAs($this->readOnlyStaff());
        $this->postJson('/beneficiaries', ['name' => 'Х'])->assertForbidden();

        $this->assertSame(0, Beneficiary::query()->count());
    }

    public function test_contract_is_saved_with_the_newly_created_beneficiary(): void
    {
        $owner = $this->record(Owner::class);
        $property = $this->record(Property::class);
        $this->actingAs($this->staff());

        $id = $this->postJson('/beneficiaries', ['name' => 'Нов Измислен Получател'])->json('id');

        $this->post('/owner-contracts', [
            'type' => 'representative_percent', 'owner_id' => $owner->id, 'signed_on' => '10.01.2026',
            'commission_percent' => '10', 'payment_method' => 'cash', 'beneficiary_id' => $id,
            'lines' => [['property_id' => $property->id]],
        ])->assertSessionHasNoErrors();

        $this->assertSame($id, OwnerContract::query()->sole()->payeeOn('2026-03-01')->beneficiary_id);
    }
}
