<?php

namespace Tests\Feature\Screens;

use App\Models\Beneficiary;
use App\Models\Owner;

/**
 * Екранът „Бенефициенти“ (PROJECT.md, т. 2 „Бенефициент“, „Договори, уточнения“):
 * IBAN се вижда и въвежда само с правото за ЕГН и IBAN и не излиза в списъка.
 */
class BeneficiaryScreensTest extends ScreenTestCase
{
    // Измислен IBAN с вярна контролна сума (примерен, не на реална сметка).
    private const IBAN = 'BG80BNBG96611020345678';

    public function test_staff_with_the_right_creates_a_beneficiary_with_iban(): void
    {
        $this->actingAs($this->staff(['can_view_sensitive' => true]));

        $response = $this->post('/beneficiaries', ['name' => 'Измислен Получател', 'iban' => 'bg80 bnbg 9661 1020 3456 78']);

        $beneficiary = Beneficiary::query()->sole();
        $response->assertRedirect("/beneficiaries/{$beneficiary->id}");
        $this->assertSame(self::IBAN, $beneficiary->iban);
        $this->get("/beneficiaries/{$beneficiary->id}")->assertSee('Измислен Получател')->assertSee(self::IBAN);
    }

    public function test_invalid_iban_is_refused(): void
    {
        $this->actingAs($this->staff(['can_view_sensitive' => true]));

        $this->post('/beneficiaries', ['name' => 'Измислен Получател', 'iban' => 'BG00BNBG96611020345678'])
            ->assertSessionHasErrors('iban');
        $this->assertSame(0, Beneficiary::query()->count());
    }

    public function test_without_the_right_iban_is_neither_shown_nor_accepted(): void
    {
        $this->actingAs($this->staff(['can_view_sensitive' => true]));
        $this->post('/beneficiaries', ['name' => 'Измислен Получател', 'iban' => self::IBAN]);
        $beneficiary = Beneficiary::query()->sole();

        $this->actingAs($this->staff());

        $this->get("/beneficiaries/{$beneficiary->id}")->assertOk()->assertDontSee(self::IBAN)->assertSee('Скрит');
        $this->get("/beneficiaries/{$beneficiary->id}/edit")->assertOk()->assertDontSee('name="iban"', false);
        $this->post('/beneficiaries', ['name' => 'Друг Получател', 'iban' => self::IBAN])->assertSessionHasErrors('iban');
        // Смяна на името без право не трие IBAN.
        $this->put("/beneficiaries/{$beneficiary->id}", ['name' => 'Ново Име'])->assertSessionHasNoErrors();
        $this->assertNotNull($beneficiary->fresh()->getRawOriginal('iban'));
    }

    public function test_list_never_shows_iban(): void
    {
        $this->actingAs($this->staff(['can_view_sensitive' => true]));
        $this->post('/beneficiaries', ['name' => 'Измислен Получател', 'iban' => self::IBAN]);

        $this->get('/beneficiaries')->assertSee('Измислен Получател')->assertDontSee(self::IBAN);
    }

    public function test_show_links_the_owner_himself(): void
    {
        $owner = $this->record(Owner::class, ['name' => 'Иван Измислен']);
        $beneficiary = $this->record(Beneficiary::class, ['name' => 'Иван Измислен', 'owner_id' => $owner->id]);
        $this->actingAs($this->staff());

        $this->get("/beneficiaries/{$beneficiary->id}")->assertSee('това е самият собственик')->assertSee("/owners/{$owner->id}");
    }

    public function test_other_company_and_read_only_rules(): void
    {
        $foreign = $this->record(Beneficiary::class, ['name' => 'Чужд Получател'], $this->otherCompany);
        $this->record(Beneficiary::class, ['name' => 'Наш Получател']);
        $this->actingAs($this->readOnlyStaff());

        $this->get('/beneficiaries')->assertSee('Наш Получател')->assertDontSee('Чужд Получател');
        $this->get("/beneficiaries/{$foreign->id}")->assertNotFound();
        $this->get('/beneficiaries/create')->assertForbidden();
        $this->post('/beneficiaries', ['name' => 'Нов'])->assertForbidden();
    }

    public function test_staff_edits_archives_and_restores(): void
    {
        $beneficiary = $this->record(Beneficiary::class, ['name' => 'Старо Име']);
        $this->actingAs($this->staff());

        $this->put("/beneficiaries/{$beneficiary->id}", ['name' => 'Ново Име'])->assertRedirect("/beneficiaries/{$beneficiary->id}");
        $this->assertSame('Ново Име', $beneficiary->fresh()->name);

        $this->post("/beneficiaries/{$beneficiary->id}/archive");
        $this->assertNotNull($beneficiary->fresh()->archived_at);
        $this->post("/beneficiaries/{$beneficiary->id}/restore");
        $this->assertNull($beneficiary->fresh()->archived_at);
    }
}
