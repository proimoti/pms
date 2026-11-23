<?php

namespace Tests\Feature\Screens;

use App\Enums\OwnerContractType;
use App\Enums\PaymentMethod;
use App\Models\Beneficiary;
use App\Models\LedgerEntry;
use App\Models\Owner;
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
use Illuminate\Support\Facades\DB;
use Tests\Support\DossierScenario;

/**
 * Първите справки (PROJECT.md, „Досиета и първи справки“, 23.11.2026): изтичащи
 * договори за наем, свободни имоти, изтекъл срок без прекратяване и списък за
 * изплащане към бенефициентите; всяка се изтегля в Excel. Служител с „само
 * прикачените имоти“ вижда само своето; IBAN само с право (и в Excel). Нищо
 * не се записва в книгата. „Днес“ е 23.11.2026. Само измислени данни.
 */
class ReportScreensTest extends ScreenTestCase
{
    use DossierScenario;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-23 12:00:00'));
        $this->user = $this->staff();
        $this->buildDossierScenario($this->user);
    }

    private function managed(Property $property, ?Owner $owner = null): void
    {
        app(OwnerContractService::class)->create($owner === null
            ? ['type' => OwnerContractType::Own, 'signed_on' => '2026-01-01', 'lines' => [['property_id' => $property->id]]]
            : ['type' => OwnerContractType::RepresentativePercent, 'owner_id' => $owner->id, 'signed_on' => '2026-01-01', 'commission_percent' => '10',
                'beneficiary_id' => $this->beneficiary->id, 'payment_method' => PaymentMethod::Bank, 'lines' => [['property_id' => $property->id]]]);
    }

    private function lease(Property $property, string $tenant, string $from, array $extra = []): \App\Models\LeaseContract
    {
        auth()->logout();
        $party = Tenant::factory()->create(['company_id' => $this->company->id, 'name' => $tenant, 'phone' => '0899 000 '.random_int(100, 999), 'email' => 'tenant@example.test']);
        $this->actingAs($this->user);
        $lease = app(LeaseContractService::class)->create(['tenant_id' => $party->id, 'signed_on' => $from, 'lines' => [['property_id' => $property->id, 'rent' => '400']]]);
        if ($extra !== []) {
            $lease->update($extra);
        }

        return $lease;
    }

    private function property(int $number): Property
    {
        return Property::factory()->create(['company_id' => $this->company->id, 'number' => $number]);
    }

    /** Редовете на изтегления Excel файл (първият е заглавният). */
    private function xlsx(string $url): array
    {
        $response = $this->get($url)->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('content-type'));
        $path = $response->baseResponse->getFile()->getPathname();

        return (new XlsxReader)->rows($path);
    }

    // 1. Изтичащи договори за наем

    public function test_expiring_leases_in_the_next_n_days_with_tenant_and_owner_contacts(): void
    {
        $a = $this->property(103);
        $this->managed($a, $this->owner);
        $b = $this->property(104);
        $this->managed($b);
        $c = $this->property(105);
        $this->managed($c);
        $soon = $this->lease($a, 'Скоро Изтичащ', '2026-06-01', ['end_on' => '2026-12-13']);   // след 20 дни
        $later = $this->lease($b, 'По-късно Изтичащ', '2026-06-01', ['end_on' => '2027-01-02']); // след 40 дни
        $this->lease($c, 'Прекратен Наемател', '2026-06-01', ['end_on' => '2026-12-03', 'terminated_on' => '2026-11-20']);

        $this->get('/reports/expiring')->assertOk()
            ->assertSee('Скоро Изтичащ')->assertSee('tenant@example.test')
            ->assertSee('Петя Измислена')->assertSee('0888 100 101')->assertSee('petya@example.test')
            ->assertSee('13.12.2026')->assertSee('20')
            ->assertDontSee('По-късно Изтичащ')->assertDontSee('Прекратен Наемател');

        $this->get('/reports/expiring?days=60')->assertOk()->assertSeeInOrder(['Скоро Изтичащ', 'По-късно Изтичащ']);
        $this->get('/reports/expiring?days=0')->assertSessionHasErrors('days');
        $this->get('/reports/expiring?days=366')->assertSessionHasErrors('days');
        $this->assertNotNull($soon->id);
        $this->assertNotNull($later->id);
    }

    // 2. Свободни имоти

    public function test_vacant_properties_today_and_since_when(): void
    {
        $left = $this->property(106);
        $this->lease($left, 'Напуснал Наемател', '2026-02-01', ['terminated_on' => '2026-10-01']); // без договор за управление

        $page = $this->get('/reports/vacant')->assertOk();
        $page->assertDontSee('href="/properties/'.$this->flat->id.'"', false)       // зает от Борис
            ->assertSee('href="/properties/'.$this->empty->id.'"', false)->assertSee(__('reports.vacant.never'))
            ->assertSee('href="/properties/'.$left->id.'"', false)->assertSee('01.10.2026')->assertSee('Напуснал Наемател')
            ->assertSeeInOrder(['№ 102', '№ 106'], false);

        $this->get('/reports/vacant?managed=1')->assertOk()
            ->assertSee('href="/properties/'.$this->empty->id.'"', false)
            ->assertDontSee('href="/properties/'.$left->id.'"', false);
    }

    // 3. Изтекъл срок без прекратяване

    public function test_expired_term_without_termination_for_both_kinds_with_a_filter(): void
    {
        $a = $this->property(107);
        $this->managed($a);
        $lease = $this->lease($a, 'Изтекъл Срок', '2026-02-01', ['end_on' => '2026-10-31']);
        $this->lease($this->property(108), 'Прекратен Навреме', '2026-02-01', ['end_on' => '2026-10-31', 'terminated_on' => '2026-10-31']);
        $this->management->update(['end_on' => '2026-09-30']);

        $this->get('/reports/expired')->assertOk()
            ->assertSee(__('reports.expired.note'))
            ->assertSee('href="/lease-contracts/'.$lease->id.'"', false)->assertSee('Изтекъл Срок')->assertSee('23')
            ->assertSee('href="/owner-contracts/'.$this->management->id.'"', false)->assertSee('Петя Измислена')->assertSee('54')
            ->assertDontSee('Прекратен Навреме')
            ->assertSeeInOrder(['30.09.2026', '31.10.2026']);

        $this->get('/reports/expired?kind=lease')->assertOk()->assertSee('Изтекъл Срок')->assertDontSee('href="/owner-contracts/'.$this->management->id.'"', false);
        $this->get('/reports/expired?kind=owner')->assertOk()->assertDontSee('Изтекъл Срок')->assertSee('Петя Измислена');
    }

    // 4. Списък за изплащане

    public function test_payouts_are_the_408_amount_after_the_commission_percent_and_fixed(): void
    {
        // Процент: наем 500, 10 % комисиона → 450. Фиксиран: 380 при наем 500 → 380.
        auth()->logout();
        $georgi = Owner::factory()->create(['company_id' => $this->company->id, 'name' => 'Георги Измислен']);
        $cashier = Beneficiary::factory()->create(['company_id' => $this->company->id, 'name' => 'Георги Получател', 'iban' => null, 'owner_id' => $georgi->id]);
        $this->actingAs($this->user);
        $fixedFlat = $this->property(109);
        $fixed = app(OwnerContractService::class)->create([
            'type' => OwnerContractType::RepresentativeFixed, 'owner_id' => $georgi->id, 'signed_on' => '2026-01-01',
            'beneficiary_id' => $cashier->id, 'payment_method' => PaymentMethod::Bank,
            'lines' => [['property_id' => $fixedFlat->id, 'fixed_amount' => '380']],
        ]);
        app(LeaseContractService::class)->create(['tenant_id' => $this->anna->id, 'signed_on' => '2026-06-01', 'lines' => [['property_id' => $fixedFlat->id, 'rent' => '500']]]);
        app(MonthWorksheet::class)->generate('2027-01');
        app(Issuer::class)->issueMonth('2027-01');

        $page = $this->get('/reports/payouts?month=01.2027')->assertOk();
        $page->assertSee(__('reports.payouts.amount'))
            ->assertSeeInOrder(['Георги Получател', 'href="/owner-contracts/'.$fixed->id.'"', '380,00'], false)
            ->assertSee(__('reports.payouts.missing_iban'))
            ->assertSeeInOrder(['Иван Получател', 'href="/owner-contracts/'.$this->management->id.'"', '450,00'], false)
            ->assertDontSee(__('reports.payouts.not_issued'));

        $this->assertSame('450.00', LedgerEntry::query()->where('account', '408')->where('owner_contract_id', $this->management->id)->whereDate('month', '2027-01-01')->sum('amount'));
    }

    public function test_iban_is_hidden_without_the_right_also_in_excel(): void
    {
        $this->get('/reports/payouts?month=12.2026')->assertOk()
            ->assertDontSee('BG18RZBB91550123456789')->assertDontSee('RZBB 9155')->assertSee('**** 6789');
        $rows = $this->xlsx('/reports/payouts?month=12.2026&export=xlsx');
        $flat = json_encode($rows, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('RZBB', $flat);
        $this->assertStringContainsString('**** 6789', $flat);
        $this->assertStringContainsString('Иван Получател', $flat);
        $this->assertStringContainsString(__('reports.payouts.amount'), $flat);

        // С правото — целият IBAN, и в Excel.
        auth()->logout();
        $sensitive = $this->staff(['can_view_sensitive' => true]);
        $this->actingAs($sensitive);
        $this->get('/reports/payouts?month=12.2026')->assertOk()->assertSee('BG18RZBB91550123456789');
        $this->assertStringContainsString('BG18RZBB91550123456789', json_encode($this->xlsx('/reports/payouts?month=12.2026&export=xlsx')));
    }

    public function test_iban_never_goes_to_the_logs_or_the_audit_log(): void
    {
        auth()->logout();
        $this->actingAs($this->staff(['can_view_sensitive' => true]));
        $before = DB::table('audit_log')->count();

        $this->get('/reports/payouts?month=12.2026')->assertOk();
        $this->xlsx('/reports/payouts?month=12.2026&export=xlsx');

        $this->assertSame($before, DB::table('audit_log')->count());
        $log = storage_path('logs/laravel.log');
        $this->assertTrue(! is_file($log) || ! str_contains((string) file_get_contents($log), 'BG18RZBB91550123456789'));
    }

    public function test_payee_changed_during_the_month_is_the_one_on_the_last_day_with_a_note(): void
    {
        auth()->logout();
        $other = Beneficiary::factory()->create(['company_id' => $this->company->id, 'name' => 'Нов Получател', 'iban' => null]);
        $this->actingAs($this->user);
        $owners = app(OwnerContractService::class);
        $annex = $owners->addAnnex($this->management, 2, '2027-01-10');
        $owners->changePayee($annex, '2027-01-15', $other->id, PaymentMethod::Cash);
        app(MonthWorksheet::class)->generate('2027-01');
        app(Issuer::class)->issueMonth('2027-01');

        $this->get('/reports/payouts?month=01.2027')->assertOk()
            ->assertSee('Нов Получател')->assertSee(__('reports.payouts.changed'))->assertSee(__('contracts.payment_methods.cash'))
            ->assertDontSee('Иван Получател');
    }

    public function test_month_not_issued_is_warned_about(): void
    {
        app(Issuer::class)->issueNow($this->lease, '2027-02');

        $this->get('/reports/payouts?month=02.2027')->assertOk()->assertSee(__('reports.payouts.not_issued'))->assertSee('450,00');
        $this->get('/reports/payouts?month=03.2027')->assertOk()->assertSee(__('reports.payouts.empty'));
    }

    // Excel за всяка справка

    public function test_every_report_downloads_as_excel_with_the_same_rows(): void
    {
        $a = $this->property(110);
        $this->managed($a, $this->owner);
        $this->lease($a, 'Скоро Изтичащ', '2026-06-01', ['end_on' => '2026-12-13']);

        $expiring = $this->xlsx('/reports/expiring?export=xlsx');
        $this->assertSame(__('reports.expiring.columns.contract'), $expiring[0][0]);
        $this->assertStringContainsString('Скоро Изтичащ', json_encode($expiring, JSON_UNESCAPED_UNICODE));

        $this->assertStringContainsString('102', json_encode($this->xlsx('/reports/vacant?export=xlsx')));
        $this->assertCount(1, $this->xlsx('/reports/expired?export=xlsx'), 'само заглавният ред');
        $this->assertCount(2, $this->xlsx('/reports/payouts?month=12.2026&export=xlsx'));
    }

    // Права и фирми

    public function test_assigned_only_staff_sees_only_their_properties(): void
    {
        $a = $this->property(111);
        $this->managed($a, $this->owner);
        $this->lease($a, 'Чужд Изтичащ', '2026-06-01', ['end_on' => '2026-12-13']);
        $assigned = $this->assignedOnlyStaff();
        (new UserProperty)->forceFill(['company_id' => $this->company->id, 'user_id' => $assigned->id, 'property_id' => $this->flat->id])->save();
        $this->actingAs($assigned);

        $this->get('/reports/expiring')->assertOk()->assertDontSee('Чужд Изтичащ');
        $this->get('/reports/vacant')->assertOk()->assertDontSee('href="/properties/'.$this->empty->id.'"', false);
        $this->get('/reports/payouts?month=12.2026')->assertOk()->assertSee('450,00');
        $this->assertStringNotContainsString('Чужд Изтичащ', json_encode($this->xlsx('/reports/expiring?export=xlsx&days=365'), JSON_UNESCAPED_UNICODE));
    }

    public function test_company_b_sees_nothing_of_company_a(): void
    {
        auth()->logout();
        $foreign = $this->staff(['can_view_sensitive' => true], $this->otherCompany);
        $this->assertSame($this->otherCompany->id, $foreign->company_id);
        $this->actingAs($foreign);

        $this->get('/reports/vacant')->assertOk()->assertDontSee('href="/properties/'.$this->empty->id.'"', false);
        $this->get('/reports/payouts?month=12.2026')->assertOk()->assertDontSee('Иван Получател')->assertDontSee('450,00');
        $this->assertStringNotContainsString('Иван', json_encode($this->xlsx('/reports/payouts?month=12.2026&export=xlsx'), JSON_UNESCAPED_UNICODE));
        $this->get('/reports/expired')->assertOk()->assertDontSee('Петя Измислена');
    }

    public function test_reports_do_not_write_to_the_ledger(): void
    {
        $before = LedgerEntry::query()->count();
        foreach (['/reports/expiring', '/reports/vacant', '/reports/expired', '/reports/payouts?month=12.2026'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->assertSame($before, LedgerEntry::query()->count());
    }

    public function test_menu_has_the_reports(): void
    {
        $this->get('/reports/vacant')->assertSee(__('registry.menu_groups.reports'))
            ->assertSee('href="/reports/expiring"', false)->assertSee('href="/reports/payouts"', false);
    }
}
