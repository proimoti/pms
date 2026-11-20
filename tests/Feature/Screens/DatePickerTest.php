<?php

namespace Tests\Feature\Screens;

use App\Enums\OwnerContractType;
use App\Enums\PaymentMethod;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\LeaseContractService;
use App\Services\OwnerContractService;

/**
 * Полето за дата остава текстово (дд.мм.гггг), а до него има бутон за
 * календар (PROJECT.md, „Дати в интерфейса“, 20.11.2026). Календарът е на
 * браузъра (скрито поле за дата зад бутона), без нов пакет. Скритото поле
 * няма име: формата праща само текстовото.
 */
class DatePickerTest extends ScreenTestCase
{
    /** @return list<string> адресите на всички форми на договорите с дати */
    private function contractForms(): array
    {
        $management = app(OwnerContractService::class)->create([
            'company_id' => $this->company->id, 'type' => OwnerContractType::RepresentativePercent,
            'owner_id' => $this->record(Owner::class)->id, 'signed_on' => '2026-01-01', 'commission_percent' => '10',
            'payment_method' => PaymentMethod::Cash, 'end_on' => '2026-12-31',
            'lines' => [['property_id' => $this->record(Property::class)->id]],
        ]);
        $lease = app(LeaseContractService::class)->create([
            'company_id' => $this->company->id, 'tenant_id' => $this->record(Tenant::class)->id,
            'signed_on' => '2026-01-10', 'end_on' => '2026-12-31',
            'lines' => [['property_id' => $this->record(Property::class)->id, 'rent' => '600']],
        ]);

        return [
            '/owner-contracts/create',
            "/owner-contracts/{$management->id}/edit",
            "/owner-contracts/{$management->id}/annexes/create",
            "/owner-contracts/{$management->id}/correct",
            '/lease-contracts/create',
            "/lease-contracts/{$lease->id}/edit",
            "/lease-contracts/{$lease->id}/annexes/create",
            "/lease-contracts/{$lease->id}/correct",
        ];
    }

    public function test_every_date_field_has_a_calendar_button(): void
    {
        $forms = $this->contractForms();
        $this->actingAs($this->staff());

        foreach ($forms as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            // Броят се самите полета (атрибутите в таговете), не текстът в скрипта.
            $count = fn (string $attribute) => preg_match_all('/<(?:input|button)\s[^>]*'.$attribute.'[\s>]/', $html);
            $texts = $count('data-date-text');
            $this->assertGreaterThan(0, $texts, $url);
            $this->assertSame($texts, $count('data-date-pick'), "Бутон до всяка дата: {$url}");
            $this->assertSame($texts, $count('data-date-native'), "Календар до всяка дата: {$url}");

            // Всяко текстово поле с дд.мм.гггг е от новия вид.
            $this->assertSame($texts, substr_count($html, 'placeholder="'.__('registry.date_placeholder').'"'), $url);

            // Скритото поле за календара не се праща с формата.
            $this->assertDoesNotMatchRegularExpression('/<input[^>]*type="date"[^>]*name=/', $html, $url);
            $this->assertDoesNotMatchRegularExpression('/<input[^>]*name=[^>]*type="date"/', $html, $url);
        }
    }

    public function test_button_has_an_accessible_name_and_page_has_the_script(): void
    {
        $this->actingAs($this->staff());

        $this->get('/lease-contracts/create')->assertOk()
            ->assertSee('aria-label="'.__('registry.date_pick').'"', false)
            ->assertSee('showPicker', false);
    }

    public function test_date_value_is_still_shown_as_dd_mm_yyyy(): void
    {
        $forms = $this->contractForms();
        $this->actingAs($this->staff());

        $this->get($forms[5])->assertOk()->assertSee('value="10.01.2026"', false);
    }

    public function test_new_rows_from_the_template_also_get_the_button(): void
    {
        $this->actingAs($this->staff());

        $html = $this->get('/lease-contracts/create')->getContent();
        $template = substr($html, strpos($html, '<template'));

        $this->assertStringContainsString('data-date-pick', $template);
    }
}
