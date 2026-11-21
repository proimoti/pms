<?php

namespace Tests\Feature\Screens;

use App\Enums\ChargeType;
use App\Enums\OwnerContractType;
use App\Enums\PaymentMethod;
use App\Models\ChargeDraft;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\LeaseContractService;
use App\Services\OwnerContractService;

/**
 * Новият дизайн (вариант А с лентата и предупреждението в реда от В,
 * 21.11.2026): странично меню с групи и „хармоника“ — страницата
 * „Начисления“ се зарежда на ниво договор, а с клик договорът се разгъва
 * и показва редовете си. Разгъването е общ елемент (за други страници).
 */
class DesignTest extends ScreenTestCase
{
    private function rented(string $tenant, array $lease = [], array $line = []): array
    {
        $property = $this->record(Property::class);
        app(OwnerContractService::class)->create([
            'company_id' => $this->company->id, 'type' => OwnerContractType::RepresentativePercent,
            'owner_id' => $this->record(Owner::class, ['name' => 'Георги Измислен'])->id,
            'signed_on' => '2026-01-01', 'commission_percent' => '10', 'payment_method' => PaymentMethod::Cash,
            'lines' => [['property_id' => $property->id]],
        ]);
        $contract = app(LeaseContractService::class)->create(array_merge([
            'company_id' => $this->company->id, 'tenant_id' => $this->record(Tenant::class, ['name' => $tenant])->id,
            'signed_on' => '2026-01-10', 'lines' => [array_merge(['property_id' => $property->id, 'rent' => '600'], $line)],
        ], $lease));

        return [$property, $contract];
    }

    /** Текстът между <summary> и </summary> на хармониката, в която е $needle. */
    private function summaryWith(string $html, string $needle): string
    {
        preg_match_all('#<summary[^>]*>(.*?)</summary>#s', $html, $matches);
        foreach ($matches[1] as $summary) {
            if (str_contains($summary, $needle)) {
                return $summary;
            }
        }
        $this->fail("Няма ред на договор с „{$needle}“.");
    }

    public function test_side_menu_with_groups_and_the_current_page(): void
    {
        $this->actingAs($this->staff());

        $html = $this->get('/charges?month=2026-12')->assertOk()->getContent();

        $this->assertStringContainsString('<nav class="side"', $html);
        foreach (__('registry.menu_groups') as $group) {
            $this->assertStringContainsString($group, $html);
        }
        foreach (['/properties', '/owner-contracts', '/lease-contracts', '/charges', '/statements', '/upcoming-charges'] as $link) {
            $this->assertStringContainsString('href="'.$link.'"', $html);
        }
        $this->assertMatchesRegularExpression('#<a [^>]*href="/charges"[^>]*aria-current="page"#', $html);
        $this->assertDoesNotMatchRegularExpression('#<a [^>]*href="/properties"[^>]*aria-current="page"#', $html);
    }

    public function test_charges_page_loads_on_contract_level_and_rows_are_inside(): void
    {
        [$property, $lease] = $this->rented('Петър Измислен');
        $this->actingAs($this->staff());
        $this->post('/charges/generate', ['month' => '2026-12']);

        $html = $this->get('/charges?month=2026-12')->assertOk()->getContent();

        // Едно разгъване за договор за наем, затворено по подразбиране; редовете са вътре.
        $this->assertMatchesRegularExpression('#<details class="accordion state-ok"[ >]#', $html);
        $summary = $this->summaryWith($html, 'Петър Измислен');
        $this->assertStringContainsString(__('registry.one.lease-contracts').' <strong>№ '.$lease->number.'</strong>', $summary);
        $this->assertStringContainsString('600,00', $summary);
        $this->assertStringNotContainsString('href="/charges/', $summary); // действията са в редовете, не в заглавието

        $this->assertStringContainsString('class="metric"', $html);
    }

    public function test_warning_is_in_the_contract_row_with_an_amber_stripe(): void
    {
        $this->rented('Нов Измислен', ['signed_on' => '2026-12-16'], ['first_period_rent' => '300']);
        $this->actingAs($this->staff());
        $this->post('/charges/generate', ['month' => '2026-12']);

        $html = $this->get('/charges?month=2026-12')->getContent();

        $this->assertMatchesRegularExpression('#<details class="accordion state-warning"[ >]#', $html);
        $this->assertStringContainsString('309,68', $this->summaryWith($html, 'Нов Измислен'));
    }

    public function test_terminated_contract_has_a_grey_stripe(): void
    {
        [, $lease] = $this->rented('Елена Измислена');
        $lease->update(['terminated_on' => '2026-12-15']);
        $this->actingAs($this->staff());
        $this->post('/charges/generate', ['month' => '2026-12']);

        $html = $this->get('/charges?month=2026-12')->getContent();

        $this->assertMatchesRegularExpression('#<details class="accordion state-off"[ >]#', $html);
        $this->assertStringContainsString('15.12.2026', $this->summaryWith($html, 'Елена Измислена'));
    }

    // „Начисления“, съдържание (PROJECT.md, 21.11.2026)

    public function test_tenant_total_is_broken_down_by_type_only_non_zero(): void
    {
        [$property, $lease] = $this->rented('Петър Измислен');
        $this->actingAs($this->staff());
        $this->post('/charges/generate', ['month' => '2026-12']);
        foreach (['penalty' => '50', 'deposit' => '1200'] as $type => $amount) {
            $this->post('/charges', ['month' => '2026-12', 'type' => $type, 'lease_contract_id' => $lease->id,
                'property_id' => $property->id, 'amount' => $amount, 'reason' => 'Причина']);
        }

        $html = $this->get('/charges?month=2026-12')->getContent();
        preg_match('#<div class="breakdown">(.*?)</div>#s', $html, $breakdown);

        $this->assertStringContainsString(__('charges.types_plural.rent').' 600,00', $breakdown[1]);
        $this->assertStringContainsString(__('charges.types_plural.deposit').' 1 200,00', $breakdown[1]);
        $this->assertStringContainsString(__('charges.types_plural.penalty').' 50,00', $breakdown[1]);
        $this->assertStringNotContainsString(__('charges.types_plural.utility'), $breakdown[1]);
        $this->assertStringNotContainsString(__('charges.types_plural.other'), $breakdown[1]);
    }

    public function test_free_properties_section_whole_month_first(): void
    {
        $free = $this->record(Property::class);
        app(OwnerContractService::class)->create([
            'company_id' => $this->company->id, 'type' => OwnerContractType::RepresentativeFixed,
            'owner_id' => $this->record(Owner::class)->id, 'signed_on' => '2026-01-01', 'payment_method' => PaymentMethod::Cash,
            'lines' => [['property_id' => $free->id, 'fixed_amount' => '460']],
        ]);
        [$partly] = $this->rented('Петър Измислен', ['signed_on' => '2026-12-17']);
        $this->actingAs($this->staff());

        $html = $this->get('/charges?month=2026-12')->assertOk()->getContent();
        $section = substr($html, strpos($html, 'id="free-properties"'));

        $this->assertStringContainsString(__('charges.screens.free_properties'), $html);
        $this->assertStringContainsString(__('contracts.types.representative_fixed'), $section);
        $this->assertStringContainsString('460,00', $section);
        $this->assertLessThan(strpos($section, '№ '.$partly->number.'<'), strpos($section, '№ '.$free->number.'<'));
        $this->assertStringContainsString('16', $section);
    }

    public function test_free_property_with_management_from_the_middle_shows_from_date(): void
    {
        $property = $this->record(Property::class);
        app(OwnerContractService::class)->create([
            'company_id' => $this->company->id, 'type' => OwnerContractType::RepresentativeFixed,
            'owner_id' => $this->record(Owner::class)->id, 'signed_on' => '2026-12-10', 'payment_method' => PaymentMethod::Cash,
            'lines' => [['property_id' => $property->id, 'fixed_amount' => '460']],
        ]);
        $this->actingAs($this->staff());

        $html = $this->get('/charges?month=2026-12')->getContent();
        $section = substr($html, strpos($html, 'id="free-properties"'));

        $this->assertStringContainsString(__('charges.screens.free_from', ['date' => '10.12']), $section);
        $rows = substr($section, strpos($section, '<tbody>'));
        $this->assertStringNotContainsString(__('charges.screens.free_whole'), $rows);
        $this->assertStringContainsString('326,45', $section);
    }

    public function test_month_state_label_and_switcher(): void
    {
        $this->rented('Петър Измислен');
        $this->actingAs($this->staff());
        $this->post('/charges/generate', ['month' => '2026-12']);

        $this->get('/charges?month=2026-12')
            ->assertSee('<span class="badge working">'.__('charges.screens.state_working').'</span>', false)
            ->assertSee('href="/charges?month=2026-11" aria-label="'.__('charges.screens.previous_month').'"', false)
            ->assertSee('href="/charges?month=2027-01" aria-label="'.__('charges.screens.next_month').'"', false)
            ->assertDontSee('class="button secondary" href="/upcoming-charges"', false)
            ->assertDontSee(__('charges.screens.one_bill_per_contract'))
            ->assertSee('href="/upcoming-charges"', false); // в менюто остава

        $this->post('/charges/issue', ['month' => '2026-12', 'reviewed' => '1']);
        $this->get('/charges?month=2026-12')
            ->assertSee('<span class="badge issued">'.__('charges.screens.state_issued').'</span>', false);
    }

    public function test_warnings_link_to_the_contract_and_rows_show_state_in_words(): void
    {
        [, $lease] = $this->rented('Нов Измислен', ['signed_on' => '2026-12-16'], ['first_period_rent' => '300']);
        [, $ok] = $this->rented('Петър Измислен');
        $this->actingAs($this->staff());
        $this->post('/charges/generate', ['month' => '2026-12']);

        $html = $this->get('/charges?month=2026-12')->getContent();

        $this->assertStringContainsString('<a href="#lease-'.$lease->id.'">', $html);
        $this->assertStringContainsString('id="lease-'.$lease->id.'"', $html);
        // Само цветната лента: без дума и знак за състоянието (21.11.2026); предупреждението в реда остава.
        $this->assertStringNotContainsString('acc-state', $html);
        $this->assertStringNotContainsString('Готов', $this->summaryWith($html, 'Петър Измислен'));
        $this->assertStringContainsString('309,68', $this->summaryWith($html, 'Нов Измислен'));
        $this->assertStringContainsString('<strong>№ '.$ok->number.'</strong>', $this->summaryWith($html, 'Петър Измислен'));
    }

    public function test_search_and_filters_open_the_contracts(): void
    {
        [$property] = $this->rented('Петър Измислен');
        $this->actingAs($this->staff());
        $this->post('/charges/generate', ['month' => '2026-12']);
        $row = ChargeDraft::query()->where('property_id', $property->id)->where('type', ChargeType::Rent)->sole();
        $this->put("/charges/{$row->id}", ['amount' => '590', 'reason' => 'Отстъпка']);

        $this->assertDoesNotMatchRegularExpression('#<details[^>]* open#', $this->get('/charges?month=2026-12')->getContent());
        $this->assertMatchesRegularExpression('#<details[^>]* open#', $this->get('/charges?month=2026-12&q=Петър')->getContent());
        $this->assertMatchesRegularExpression('#<details[^>]* open#', $this->get('/charges?month=2026-12&filter=manual')->getContent());
    }
}
