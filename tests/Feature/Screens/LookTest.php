<?php

namespace Tests\Feature\Screens;

use App\Models\Broker;
use App\Models\Fund;
use App\Models\Property;
use App\Models\Provider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Support\DossierScenario;

/**
 * Общ вид (PROJECT.md, „Общ вид“ и „Поправки по екраните“, 24.11.2026):
 * тъмно меню вляво в реда Начало, Договори, Начисления, Битови сметки,
 * Регистър, Справки; най-долу име, фирма и „Изход“. Списъците и справките са
 * широки (до 1600 px), картите и формите по-тесни (1240 px). В списъците:
 * „Всички / Активни / Архивирани“, целият ред води към картата, без скоби в
 * заглавията на колоните, състоянието е цветен етикет. Само измислени данни.
 */
class LookTest extends ScreenTestCase
{
    use DossierScenario;

    private User $user;

    /** Списъците на регистъра и договорите: адрес => адресът на картата на първия запис. */
    private array $lists;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00:00'));
        $this->user = $this->staff(['name' => 'Демо Служител']);
        $this->buildDossierScenario($this->user);
        $broker = Broker::factory()->create(['company_id' => $this->company->id, 'name' => 'Иво Измислен']);

        $this->lists = [
            'properties' => "/properties/{$this->flat->id}",
            'owners' => "/owners/{$this->owner->id}",
            'beneficiaries' => "/beneficiaries/{$this->beneficiary->id}",
            'tenants' => "/tenants/{$this->boris->id}",
            'brokers' => "/brokers/{$broker->id}",
            'owner-contracts' => "/owner-contracts/{$this->management->id}",
            'lease-contracts' => "/lease-contracts/{$this->lease->id}",
            'utility-accounts' => "/utility-accounts/{$this->meter->id}",
            'funds' => '/funds/'.Fund::query()->value('id'),
            'providers' => '/providers/'.Provider::query()->value('id'),
        ];
    }

    // Меню

    public function test_menu_groups_are_in_the_agreed_order(): void
    {
        $html = $this->get('/properties')->assertOk()->getContent();
        $menu = $this->menu($html);

        $this->assertStringContainsString('class="side"', $html);
        $order = ['href="/dashboard"', __('registry.menu_groups.contracts'), 'href="/owner-contracts"', 'href="/lease-contracts"',
            __('registry.menu_groups.charges'), 'href="/charges"', 'href="/upcoming-charges"', 'href="/statements"',
            __('registry.menu_groups.utilities'), 'href="/utility-imports"', 'href="/utility-unallocated"', 'href="/providers"',
            __('registry.menu_groups.registry'), 'href="/properties"', 'href="/brokers"',
            __('registry.menu_groups.reports'), 'href="/reports/expiring"', 'href="/reports/payouts"'];
        $position = -1;
        foreach ($order as $needle) {
            $next = strpos($menu, $needle, $position + 1);
            $this->assertNotFalse($next, "Няма „{$needle}“ в менюто.");
            $this->assertGreaterThan($position, $next, "„{$needle}“ не е на мястото си.");
            $position = $next;
        }
        // Страниците са под заглавието на групата си (отместени надясно).
        $this->assertMatchesRegularExpression('#<a class="item sub" href="/properties"[^>]*aria-current="page"#', $menu);
        $this->assertMatchesRegularExpression('#<a class="item" href="/dashboard"#', $menu);
    }

    public function test_menu_ends_with_the_name_the_company_and_logout(): void
    {
        $menu = $this->menu($this->get('/properties')->getContent());
        $foot = substr($menu, strpos($menu, 'class="side-foot"'));

        $this->assertStringContainsString('Демо Служител', $foot);
        $this->assertStringContainsString('Тест Алфа ООД', $foot);
        $this->assertMatchesRegularExpression('#<form method="POST" action="[^"]*/logout"#', $foot);
        $this->assertStringContainsString(__('registry.logout'), $foot);
    }

    public function test_dark_menu_and_light_cards_on_grey(): void
    {
        $html = $this->get('/properties')->getContent();

        $this->assertStringContainsString('--menu: #16213a', $html);
        $this->assertStringContainsString('--bg: #eef0f4', $html);
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
    }

    public function test_menu_is_compact_and_scrolls_on_its_own(): void
    {
        $html = $this->get('/properties')->getContent();

        // По-малко разстояние между редовете и групите (24.11.2026), без да се свива текстът; менюто се превърта отделно.
        $this->assertStringContainsString('nav.side a.item { display: block; padding: 4px 12px;', $html);
        $this->assertStringContainsString('padding: 10px 12px 3px;', $html);
        $this->assertMatchesRegularExpression('#nav\.side \{[^}]*font-size: 14px;[^}]*height: 100vh; overflow-y: auto;#', $html);
    }

    // Широчина

    public function test_lists_and_reports_are_wide_cards_and_forms_narrower(): void
    {
        foreach (array_keys($this->lists) as $section) {
            $this->assertStringContainsString('<main class="list">', $this->get("/{$section}")->getContent(), $section);
        }
        foreach (['/reports/expiring', '/reports/vacant', '/reports/expired', '/reports/payouts', '/statements?month=2026-12', '/utility-unallocated'] as $url) {
            $this->assertStringContainsString('<main class="list">', $this->get($url)->getContent(), $url);
        }
        $this->assertStringContainsString('<main class="board">', $this->get('/charges?month=2026-12')->getContent());
        foreach ([$this->lists['properties'], $this->lists['lease-contracts'], '/properties/create', '/lease-contracts/create'] as $url) {
            $this->assertMatchesRegularExpression('#<main class="(full|wide)">#', $this->get($url)->getContent(), $url);
        }

        $css = $this->get('/properties')->getContent();
        $this->assertStringContainsString('main.list, main.board { max-width: 1600px; }', $css);
        $this->assertStringContainsString('main.full, main.wide { max-width: 1240px; }', $css);
    }

    // Списъци

    public function test_every_list_has_all_active_archived_switch(): void
    {
        foreach (array_keys($this->lists) as $section) {
            $html = $this->get("/{$section}")->assertOk()->getContent();
            $this->assertStringContainsString('class="seg"', $html, $section);
            foreach (['all' => __('registry.show_all'), 'active' => __('registry.show_active'), 'archived' => __('registry.show_archived')] as $show => $label) {
                $this->assertStringContainsString('href="/'.$section.'?show='.$show.'"', $html, $section);
                $this->assertStringContainsString('>'.$label.'</a>', $html, $section);
            }
            $this->assertMatchesRegularExpression('#<a href="/'.preg_quote($section, '#').'\?show=active" aria-current="true"#', $html, $section);
        }
    }

    public function test_the_switch_filters_the_records(): void
    {
        $old = Property::factory()->create(['company_id' => $this->company->id, 'number' => 777, 'archived_at' => now()]);
        $row = fn (Property $property) => 'data-href="/properties/'.$property->id.'"';

        $this->get('/properties')->assertSee($row($this->flat), false)->assertDontSee($row($old), false);
        $this->get('/properties?show=active')->assertSee($row($this->flat), false)->assertDontSee($row($old), false);
        $this->get('/properties?show=archived')->assertSee($row($old), false)->assertDontSee($row($this->flat), false);
        $this->get('/properties?show=all')->assertSee($row($old), false)->assertSee($row($this->flat), false)
            ->assertSee('<a href="/properties?show=all" aria-current="true"', false);
        // Старият адрес продължава да работи.
        $this->get('/properties?archived=1')->assertSee($row($old), false)->assertDontSee($row($this->flat), false);
        // Търсенето пази избора.
        $this->get('/properties?show=all')->assertSee('<input type="hidden" name="show" value="all">', false);
    }

    public function test_the_whole_row_leads_to_the_card(): void
    {
        foreach ($this->lists as $section => $card) {
            $html = $this->get("/{$section}")->getContent();
            $this->assertStringContainsString('<tr data-href="'.$card.'">', $html, $section);
        }
        $html = $this->get('/properties')->getContent();
        $this->assertStringContainsString('tbody tr[data-href]:hover', $html);
        $this->assertStringContainsString("closest('tr[data-href]')", $html);
    }

    public function test_column_headings_have_no_brackets(): void
    {
        foreach (array_keys($this->lists) as $section) {
            preg_match('#<thead>(.*?)</thead>#s', $this->get("/{$section}")->getContent(), $head);
            $this->assertNotEmpty($head, $section);
            $this->assertStringNotContainsString('(', $head[1], $section);
        }
        $this->get('/lease-contracts')->assertSeeInOrder([__('contracts.screens.columns.rent'), __('contracts.screens.columns.signed_on'), __('contracts.screens.columns.end_on')]);
    }

    public function test_the_state_is_a_coloured_label(): void
    {
        $this->get('/lease-contracts?show=all')
            ->assertSee('<span class="badge state-active">'.__('contracts.screens.states.active').'</span>', false)
            ->assertSee('<span class="badge state-terminated">'.__('contracts.screens.states.terminated').'</span>', false);
        $this->get('/owner-contracts')
            ->assertSee('<span class="badge state-active">'.__('contracts.screens.states.active').'</span>', false);
    }

    public function test_the_worksheet_keeps_its_look(): void
    {
        $html = $this->get('/charges?month=2026-12')->assertOk()->getContent();

        $this->assertStringContainsString('class="accordion-list"', $html);
        $this->assertStringContainsString('class="metric"', $html);
        $this->assertStringNotContainsString('class="seg"', $html);
    }

    private function menu(string $html): string
    {
        $start = strpos($html, '<nav class="side"');
        $this->assertNotFalse($start, 'Няма меню.');

        return substr($html, $start, strpos($html, '</nav>', $start) - $start);
    }
}
