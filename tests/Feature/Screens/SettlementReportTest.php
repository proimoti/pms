<?php

namespace Tests\Feature\Screens;

use App\Enums\OwnerContractType;
use App\Enums\PaymentMethod;
use App\Models\Broker;
use App\Models\LeaseContract;
use App\Models\LedgerEntry;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserProperty;
use App\Services\Charges\Issuer;
use App\Services\Charges\MonthWorksheet;
use App\Services\LeaseContractService;
use App\Services\OwnerContractService;
use App\Support\Xlsx\XlsxReader;
use Carbon\CarbonImmutable;
use Tests\Support\DossierScenario;

/**
 * Справка „Разчети за период“ (PROJECT.md, т. 2.14, 24.11.2026): по издадените
 * месеци в периода (от месец до месец), по ред за всеки имот (и при договор с
 * няколко имота); начисленият наем (418), сумата към собственика (408) и
 * резултатът = наемът минус 408 (при „Собствен“ целият наем, при фиксиран
 * наемът минус фиксираната сума); сборове най-долу; Excel. Нищо не се записва.
 *
 * Пример: декември и януари са издадени, февруари не е.
 * - № 101 (Представител, 10 %): наем 500 на месец → 1000 / 900 / 100;
 * - № 120 и № 121 („Собствен“, един договор, само януари): 300 и 200, резултат целият наем;
 * - № 122 (фиксиран 460, само януари): 700 / 460 / 240;
 * - общо: 2200 / 1360 / 840.
 * „Днес“ е 24.11.2026. Само измислени данни.
 */
class SettlementReportTest extends ScreenTestCase
{
    use DossierScenario;

    private User $user;

    private Property $first;

    private Property $second;

    private Property $fixed;

    private LeaseContract $double;

    private LeaseContract $fixedLease;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00:00'));
        $this->user = $this->staff();
        $this->buildDossierScenario($this->user);

        $owners = app(OwnerContractService::class);
        $leases = app(LeaseContractService::class);
        $broker = Broker::query()->create(['name' => 'Иво Измислен']);
        $this->first = Property::factory()->create(['company_id' => $this->company->id, 'number' => 120, 'broker_id' => $broker->id]);
        $this->second = Property::factory()->create(['company_id' => $this->company->id, 'number' => 121]);
        $this->fixed = Property::factory()->create(['company_id' => $this->company->id, 'number' => 122]);
        $owners->create(['type' => OwnerContractType::Own, 'signed_on' => '2026-01-01', 'lines' => [['property_id' => $this->first->id], ['property_id' => $this->second->id]]]);
        $owners->create(['type' => OwnerContractType::RepresentativeFixed, 'owner_id' => $this->owner->id, 'signed_on' => '2026-01-01',
            'payment_method' => PaymentMethod::Cash, 'lines' => [['property_id' => $this->fixed->id, 'fixed_amount' => '460']]]);

        $this->double = $leases->create(['tenant_id' => $this->tenant('Христо Измислен')->id, 'signed_on' => '2026-12-01', 'lines' => [
            ['property_id' => $this->first->id, 'rent' => '300'], ['property_id' => $this->second->id, 'rent' => '200'],
        ]]);
        $this->fixedLease = $leases->create(['tenant_id' => $this->tenant('Зоя Измислена')->id, 'signed_on' => '2026-12-01',
            'lines' => [['property_id' => $this->fixed->id, 'rent' => '700']]]);

        app(MonthWorksheet::class)->generate('2027-01');
        app(Issuer::class)->issueMonth('2027-01');

        // Февруари не е издаден: „Издай сега“ не влиза в справката.
        app(Issuer::class)->issueNow($this->fixedLease, '2027-02');
    }

    private function tenant(string $name): Tenant
    {
        auth()->logout();
        $tenant = Tenant::factory()->create(['company_id' => $this->company->id, 'name' => $name]);
        $this->actingAs($this->user);

        return $tenant;
    }

    private function row(string $html, Property $property): string
    {
        $start = strpos($html, '<tr id="settlement-'.$property->id.'"');
        $this->assertNotFalse($start, "Няма ред за № {$property->number}.");

        return substr($html, $start, strpos($html, '</tr>', $start) - $start);
    }

    /** Числата в реда: месечен наем, начислен наем, към собственика, резултат. */
    private function amounts(string $row): array
    {
        preg_match_all('#<td class="num">([^<]*)</td>#', $row, $m);

        return array_map('trim', $m[1]);
    }

    public function test_columns_in_the_agreed_order(): void
    {
        $html = $this->get('/reports/settlements?from=12.2026&to=01.2027')->assertOk()->getContent();
        preg_match('#<thead>(.*?)</thead>#s', $html, $head);
        preg_match_all('#<th[^>]*>(.*?)</th>#s', $head[1], $cells);

        $c = 'reports.settlements.columns.';
        $this->assertSame(array_map(fn ($k) => __($c.$k), ['property', 'address', 'lease', 'tenant', 'management', 'owner', 'broker', 'monthly_rent', 'rent', 'owner_payable', 'result']),
            array_map(fn ($cell) => trim(strip_tags($cell)), $cells[1]));
    }

    public function test_rows_per_property_with_result_by_contract_type_and_totals(): void
    {
        $html = $this->get('/reports/settlements?from=12.2026&to=01.2027')->assertOk()->getContent();

        $flat = $this->row($html, $this->flat);
        $this->assertSame(['500,00', '1 000,00', '900,00', '100,00'], $this->amounts($flat));
        foreach (['href="/lease-contracts/'.$this->lease->id.'"', 'Борис Измислен', 'href="/owner-contracts/'.$this->management->id.'"', 'Петя Измислена'] as $needle) {
            $this->assertStringContainsString($needle, $flat);
        }

        // Договор с два имота: по ред за всеки; „Собствен“ — резултатът е целият наем.
        $first = $this->row($html, $this->first);
        $this->assertSame(['300,00', '300,00', '0,00', '300,00'], $this->amounts($first));
        $this->assertStringContainsString('href="/lease-contracts/'.$this->double->id.'"', $first);
        $this->assertStringContainsString(__('contracts.screens.own_company'), $first);
        $this->assertStringContainsString('Иво Измислен', $first);
        $this->assertSame(['200,00', '200,00', '0,00', '200,00'], $this->amounts($this->row($html, $this->second)));

        // Фиксиран: наемът минус фиксираната сума.
        $this->assertSame(['700,00', '700,00', '460,00', '240,00'], $this->amounts($this->row($html, $this->fixed)));

        // Свободен имот без записи няма ред; сборове най-долу.
        $this->assertStringNotContainsString('id="settlement-'.$this->empty->id.'"', $html);
        preg_match('#<tfoot>(.*?)</tfoot>#s', $html, $foot);
        $this->assertSame(['2 200,00', '1 360,00', '840,00'], array_slice($this->amounts($foot[1]), -3));
        $this->assertSeeInOrderHtml($html, ['№ 101', '№ 120', '№ 121', '№ 122']);
    }

    public function test_only_issued_months_count_with_a_note_for_the_others(): void
    {
        $html = $this->get('/reports/settlements?from=01.2027&to=02.2027')->assertOk()->getContent();

        $this->assertSame(['700,00', '700,00', '460,00', '240,00'], $this->amounts($this->row($html, $this->fixed)));
        $this->assertStringContainsString(__('reports.settlements.not_issued', ['months' => 'Февруари 2027']), $html);

        $this->get('/reports/settlements?from=12.2026&to=12.2026')->assertDontSee('id="settlement-'.$this->fixed->id.'"', false);
    }

    public function test_defaults_and_period_errors(): void
    {
        // По подразбиране: последният издаден месец.
        $html = $this->get('/reports/settlements')->assertOk()->getContent();
        $this->assertStringContainsString('value="01.2027"', $html);
        $this->assertSame(['500,00', '500,00', '450,00', '50,00'], $this->amounts($this->row($html, $this->flat)));

        $this->get('/reports/settlements?from=02.2027&to=01.2027')->assertSessionHasErrors('from');
        $this->get('/reports/settlements?from=13.2026&to=01.2027')->assertSessionHasErrors('from');
    }

    public function test_excel_has_the_same_columns_rows_and_totals(): void
    {
        $response = $this->get('/reports/settlements?from=12.2026&to=01.2027&export=xlsx')->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('content-type'));
        $rows = (new XlsxReader)->rows($response->baseResponse->getFile()->getPathname());

        $c = 'reports.settlements.columns.';
        $this->assertSame(__($c.'property'), $rows[0][0]);
        $this->assertSame(__($c.'result'), $rows[0][10]);
        $flat = collect($rows)->first(fn ($row) => $row[0] === '№ 101');
        $this->assertEquals([500, 1000, 900, 100], array_slice($flat, 7, 4));
        $total = end($rows);
        $this->assertSame(__('reports.settlements.total'), $total[0]);
        $this->assertEquals([2200, 1360, 840], array_slice($total, 8, 3));
    }

    public function test_menu_link_and_nothing_written_to_the_ledger(): void
    {
        $before = LedgerEntry::query()->count();
        $this->get('/reports/expiring')->assertSee('href="/reports/settlements"', false)->assertSee(__('registry.sections.reports/settlements'));
        $this->get('/reports/settlements?from=12.2026&to=01.2027&export=xlsx')->assertOk();
        $this->assertSame($before, LedgerEntry::query()->count());
    }

    public function test_assigned_only_staff_sees_only_their_properties(): void
    {
        auth()->logout();
        $assigned = $this->assignedOnlyStaff();
        (new UserProperty)->forceFill(['company_id' => $this->company->id, 'user_id' => $assigned->id, 'property_id' => $this->fixed->id])->save();
        $this->actingAs($assigned);

        $html = $this->get('/reports/settlements?from=12.2026&to=01.2027')->assertOk()->getContent();
        $this->assertStringContainsString('id="settlement-'.$this->fixed->id.'"', $html);
        $this->assertStringNotContainsString('id="settlement-'.$this->flat->id.'"', $html);
        $this->assertStringNotContainsString('Борис Измислен', $html);
    }

    public function test_company_b_sees_nothing_of_company_a(): void
    {
        auth()->logout();
        $foreign = $this->staff([], $this->otherCompany);
        $this->assertSame($this->otherCompany->id, $foreign->company_id);
        $this->actingAs($foreign);

        $html = $this->get('/reports/settlements?from=12.2026&to=01.2027')->assertOk()->getContent();
        $this->assertStringNotContainsString('settlement-', $html);
        $this->assertStringNotContainsString('Петя Измислена', $html);
        $rows = (new XlsxReader)->rows($this->get('/reports/settlements?from=12.2026&to=01.2027&export=xlsx')->baseResponse->getFile()->getPathname());
        $this->assertStringNotContainsString('Петя', json_encode($rows, JSON_UNESCAPED_UNICODE));
    }

    private function assertSeeInOrderHtml(string $html, array $needles): void
    {
        $position = -1;
        foreach ($needles as $needle) {
            $next = strpos($html, $needle, $position + 1);
            $this->assertNotFalse($next, $needle);
            $this->assertGreaterThan($position, $next, $needle);
            $position = $next;
        }
    }
}
