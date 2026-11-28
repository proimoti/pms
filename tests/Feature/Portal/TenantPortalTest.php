<?php

namespace Tests\Feature\Portal;

use App\Enums\OwnerContractType;
use App\Enums\Role;
use App\Models\BankAccount;
use App\Models\LeaseContract;
use App\Models\Owner;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Statement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Charges\Issuer;
use App\Services\Charges\MonthWorksheet;
use App\Services\Charges\UpcomingChargeService;
use App\Services\LeaseContractService;
use App\Services\OwnerContractService;
use App\Services\Payments\DepositClosingService;
use App\Services\Payments\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Screens\ScreenTestCase;

/**
 * Порталът за наемателя (план „Портал и служители“, 27.11.2026): договорите
 * (и приключилите), „Дължи / Надплатено“ общо и по договор, известията с PDF,
 * плащанията без сторнираните, депозитът (договорен, внесен, държан,
 * приключвания без сторнираните) и банковата сметка за плащане. Само записите,
 * свързани с профила; чуждото е 404. Само измислени данни.
 *
 * Пример: „Днес“ е 10.01.2027. Договор за № 601 (наем 300, депозит 1000) и
 * № 602 (наем 200, депозит 400) от 01.12.2026. Декември е издаден (500 наем +
 * 1400 депозит) и платен с 1900; плащане от 100 е сторнирано; януари е издаден
 * (500, неплатен) → дължи 500. Приключил (прекратен от 01.07.2026) договор за № 603 (01.01.–30.06.2026).
 * Друг наемател на фирмата с договор за № 604.
 */
class TenantPortalTest extends ScreenTestCase
{
    private User $staffUser;

    private Tenant $tenant;

    private Tenant $otherTenant;

    private LeaseContract $lease;

    private LeaseContract $ended;

    private LeaseContract $otherLease;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2027-01-10 12:00:00'));
        $this->onAddressOf($this->company);
        $this->staffUser = $this->staff(['is_company_admin' => true, 'name' => 'Служител Измислен']);
        [$p601, $p602, $p603, $p604] = array_map(fn ($n) => $this->record(Property::class, ['number' => $n, 'city' => 'Измисленово']), [601, 602, 603, 604]);
        $this->tenant = $this->record(Tenant::class, ['name' => 'Борис Измислен', 'email' => 'boris@example.test']);
        $this->otherTenant = $this->record(Tenant::class, ['name' => 'Чужд Наемател', 'email' => 'chuzhd@example.test']);
        $this->actingAs($this->staffUser);

        app(OwnerContractService::class)->create(['type' => OwnerContractType::Own, 'signed_on' => '2026-01-01',
            'lines' => [['property_id' => $p601->id], ['property_id' => $p602->id], ['property_id' => $p603->id], ['property_id' => $p604->id]]]);
        $this->ended = app(LeaseContractService::class)->create(['tenant_id' => $this->tenant->id, 'signed_on' => '2026-01-01',
            'lines' => [['property_id' => $p603->id, 'rent' => '250', 'deposit' => '0']]]);
        $this->ended->update(['terminated_on' => '2026-07-01']); // прекратен: последният ден е 30.06.2026
        $this->lease = app(LeaseContractService::class)->create(['tenant_id' => $this->tenant->id, 'signed_on' => '2026-12-01', 'lines' => [
            ['property_id' => $p601->id, 'rent' => '300', 'deposit' => '1000'],
            ['property_id' => $p602->id, 'rent' => '200', 'deposit' => '400'],
        ]]);
        $this->otherLease = app(LeaseContractService::class)->create(['tenant_id' => $this->otherTenant->id, 'signed_on' => '2026-12-01',
            'lines' => [['property_id' => $p604->id, 'rent' => '777', 'deposit' => '0']]]);
        foreach ([[$p601, '1000'], [$p602, '400']] as [$property, $amount]) {
            app(UpcomingChargeService::class)->create(['lease_contract_id' => $this->lease->id, 'property_id' => $property->id,
                'type' => 'deposit', 'month' => '2026-12', 'amount' => $amount]);
        }
        app(MonthWorksheet::class)->generate('2026-12');
        app(Issuer::class)->issueMonth('2026-12');
        app(PaymentService::class)->record($this->lease, ['amount' => '1900', 'paid_on' => '05.12.2026', 'method' => 'cash']);
        $wrong = app(PaymentService::class)->record($this->lease, ['amount' => '123.45', 'paid_on' => '06.12.2026', 'method' => 'cash']);
        app(PaymentService::class)->reverse($wrong, 'Грешно въведено (измислено)');
        app(PaymentService::class)->record($this->otherLease, ['amount' => '777', 'paid_on' => '07.12.2026', 'method' => 'cash']);
        app(MonthWorksheet::class)->generate('2027-01');
        app(Issuer::class)->issueMonth('2027-01');

        $bank = BankAccount::query()->create(['bank_name' => 'Измислена банка', 'iban' => 'BG80BNBG96611020345678']);
        $bank->forceFill(['for_statements' => true])->save();
        auth()->logout();

        $this->client = $this->clientFor(tenant: $this->tenant);
    }

    private function clientFor(?Tenant $tenant = null, ?Owner $owner = null, string $email = 'boris@example.test'): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id, 'role' => Role::Client, 'email' => $email,
            'access_level' => 'read', 'property_scope' => 'assigned', 'invitation_accepted_at' => now()]);
        foreach (array_filter(['tenant_id' => $tenant?->id, 'owner_id' => $owner?->id]) as $column => $id) {
            DB::table('portal_links')->insert(['company_id' => $this->company->id, 'user_id' => $user->id, $column => $id]);
        }

        return $user;
    }

    private function page(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->client)->get("/portal/tenants/{$this->tenant->id}")->assertOk();
    }

    private function statementOf(LeaseContract $lease, string $month): Statement
    {
        return Statement::query()->withoutGlobalScopes()->where('lease_contract_id', $lease->id)->whereDate('month', $month)->sole();
    }

    // --- Начало ---

    public function test_the_home_page_shows_what_the_tenant_owes_and_links_to_the_details(): void
    {
        $this->actingAs($this->client)->get('/portal')->assertOk()
            ->assertSee('Борис Измислен')->assertSee(__('portal.tenant.owes'))->assertSee('500,00 €')
            ->assertSee("/portal/tenants/{$this->tenant->id}", false);
    }

    // --- Договори ---

    public function test_the_contracts_include_the_ended_one_with_properties_and_periods(): void
    {
        $this->page()
            ->assertSee('№ '.$this->lease->number)->assertSee('№ '.$this->ended->number)
            ->assertSee(__('portal.tenant.states.active'))->assertSee(__('portal.tenant.states.ended'))
            ->assertSee('01.12.2026')->assertSee('30.06.2026')
            ->assertSee('№ 601')->assertSee('№ 602')->assertSee('№ 603')
            ->assertDontSee('№ 604')->assertDontSee('№ '.$this->otherLease->number.' ', false);
    }

    public function test_a_contract_whose_properties_were_all_removed_by_annex_ends_the_day_before(): void
    {
        // Анекс маха единствения имот от днес (10.01.2027): последният ден е 09.01.2027, а днес договорът е приключил (28.11.2026).
        $this->actingAs($this->staffUser);
        $property = $this->record(Property::class, ['number' => 605, 'city' => 'Измисленово']);
        app(\App\Services\OwnerContractService::class)->create(['type' => OwnerContractType::Own, 'signed_on' => '2026-01-01',
            'lines' => [['property_id' => $property->id]]]);
        $lease = app(LeaseContractService::class)->create(['tenant_id' => $this->tenant->id, 'signed_on' => '2026-06-01',
            'lines' => [['property_id' => $property->id, 'rent' => '100', 'deposit' => '0']]]);
        app(LeaseContractService::class)->applyAnnex($lease, ['signed_on' => '2027-01-05', 'from' => '2027-01-10',
            'lines' => [['action' => 'remove', 'property_id' => $property->id]]]);
        auth()->logout();

        $this->assertMatchesRegularExpression('#№ '.$lease->number.'\D.{0,300}?'.__('portal.tenant.states.ended').'.{0,300}?'
            .preg_quote(__('portal.tenant.period_to', ['to' => '09.01.2027']), '#').'#su', $this->page()->getContent());
    }

    // --- Дължи / Надплатено ---

    public function test_owes_in_total_and_per_contract(): void
    {
        $this->page()->assertSeeInOrder([__('portal.tenant.owes_total'), '500,00 €'])
            ->assertSeeInOrder(['№ '.$this->lease->number, '500,00 €']);
    }

    public function test_an_overpaid_tenant_sees_overpaid_without_a_minus(): void
    {
        $this->actingAs($this->staffUser);
        app(PaymentService::class)->record($this->lease->fresh(), ['amount' => '600', 'paid_on' => '09.01.2027', 'method' => 'cash']);
        auth()->logout();

        $this->page()->assertSee(__('dossiers.overpaid', ['amount' => '100,00']))->assertDontSee('-100,00');
    }

    // --- Известия ---

    public function test_the_statements_are_listed_with_a_pdf_link(): void
    {
        $january = $this->statementOf($this->lease, '2027-01-01');
        $december = $this->statementOf($this->lease, '2026-12-01');

        $this->page()->assertSeeInOrder(['№ '.$january->number, '№ '.$december->number])
            ->assertSee("/portal/statements/{$january->id}/pdf", false)
            ->assertSee("/portal/statements/{$december->id}/pdf", false);
    }

    public function test_the_pdf_of_an_own_statement_is_downloaded(): void
    {
        $statement = $this->statementOf($this->lease, '2027-01-01');

        $response = $this->actingAs($this->client)->get("/portal/statements/{$statement->id}/pdf")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('izvestie-'.$statement->number.'.pdf', (string) $response->headers->get('Content-Disposition'));

        // Регистърът е скрит за клиента: имотите и договорът в известието пак са попълнени.
        $this->actingAs($this->client);
        $html = app(\App\Services\Statements\StatementPdf::class)->html(app(\App\Services\Portal\TenantPortal::class)->statement($this->client, $statement->id));
        $this->assertStringContainsString('№ '.$this->lease->number, $html);
        $this->assertStringContainsString('№ 601', $html);
        $this->assertStringContainsString('№ 602', $html);
    }

    public function test_the_pdf_of_another_tenant_or_company_is_not_found(): void
    {
        $other = $this->statementOf($this->otherLease, '2027-01-01');
        $this->actingAs($this->client)->get("/portal/statements/{$other->id}/pdf")->assertNotFound();
        $this->actingAs($this->client)->get('/portal/statements/999999/pdf')->assertNotFound();
    }

    // --- Плащания ---

    public function test_payments_are_shown_without_the_reversed_ones(): void
    {
        $this->page()->assertSee('05.12.2026')->assertSee('1 900,00')
            ->assertDontSee('123,45')->assertDontSee('06.12.2026')
            ->assertDontSee('777,00')->assertDontSee('Грешно въведено');
    }

    public function test_staff_names_are_not_shown(): void
    {
        $this->page()->assertDontSee('Служител Измислен');
    }

    // --- Депозит ---

    public function test_the_deposit_is_contracted_paid_and_held(): void
    {
        $this->page()->assertSeeInOrder([__('portal.tenant.deposit.contracted'), '1 400,00'])
            ->assertSeeInOrder([__('portal.tenant.deposit.paid'), '1 400,00'])
            ->assertSeeInOrder([__('portal.tenant.deposit.held'), '1 400,00']);
    }

    public function test_deposit_closings_are_shown_without_the_reversed_ones(): void
    {
        $this->actingAs($this->staffUser);
        $service = app(DepositClosingService::class);
        [$first, $second] = $this->lease->lines()->orderBy('id')->pluck('property_id')->all();
        $service->record($this->lease->fresh(), ['closed_on' => '10.01.2027', 'lines' => [
            $first => ['offset' => '500', 'refund' => '', 'income' => ''],
        ]]);
        $wrong = $service->record($this->lease->fresh(), ['closed_on' => '10.01.2027', 'lines' => [
            $second => ['offset' => '', 'refund' => '', 'income' => '333'],
        ]]);
        $service->reverse($wrong, 'Грешка (измислено)');
        auth()->logout();

        $this->page()->assertSee(__('portal.tenant.deposit.closings'))->assertSee(__('deposits.methods.offset'))->assertSee('500,00')
            ->assertSeeInOrder([__('portal.tenant.deposit.held'), '900,00'])
            ->assertDontSee('333,00')->assertDontSee('Грешка (измислено)');
    }

    // --- Банкова сметка ---

    public function test_the_bank_account_for_payments_is_shown_with_the_reference(): void
    {
        $this->page()->assertSee('Измислена банка')->assertSee('BG80 BNBG 9661 1020 3456 78')
            ->assertSee(__('statements.pdf.reference_label'))->assertSee(__('statements.pdf.reference', ['contract' => $this->lease->number]));
    }

    public function test_without_a_bank_account_for_payments_the_section_says_so(): void
    {
        BankAccount::query()->withoutGlobalScopes()->update(['for_statements' => false]);

        $this->page()->assertSee(__('portal.tenant.bank.none'))->assertDontSee('BG80');
    }

    // --- Само своето ---

    public function test_another_tenant_page_is_not_found(): void
    {
        $this->actingAs($this->client)->get("/portal/tenants/{$this->otherTenant->id}")->assertNotFound();
        $this->actingAs($this->client)->get('/portal/tenants/999999')->assertNotFound();
    }

    public function test_a_tenant_of_another_company_is_not_found_even_with_the_same_id_shape(): void
    {
        $foreign = $this->record(Tenant::class, ['name' => 'Тенант Бета'], $this->otherCompany);

        $this->actingAs($this->client)->get("/portal/tenants/{$foreign->id}")->assertNotFound();
    }

    public function test_an_owner_only_profile_has_no_tenant_page(): void
    {
        $owner = $this->record(Owner::class, ['email' => 'owner@example.test']);
        $ownerClient = $this->clientFor(owner: $owner, email: 'owner@example.test');

        $this->actingAs($ownerClient)->get("/portal/tenants/{$this->tenant->id}")->assertNotFound();
        $this->actingAs($ownerClient)->get('/portal')->assertOk()->assertDontSee(__('portal.tenant.owes'));
    }

    public function test_an_archived_linked_tenant_is_not_shown(): void
    {
        $owner = $this->record(Owner::class, ['email' => 'boris@example.test']);
        DB::table('portal_links')->insert(['company_id' => $this->company->id, 'user_id' => $this->client->id, 'owner_id' => $owner->id]);
        DB::table('tenants')->where('id', $this->tenant->id)->update(['archived_at' => now()]);

        $this->actingAs($this->client)->get("/portal/tenants/{$this->tenant->id}")->assertNotFound();
    }

    public function test_staff_do_not_open_the_tenant_portal(): void
    {
        $this->actingAs($this->staffUser)->get("/portal/tenants/{$this->tenant->id}")->assertRedirect('/dashboard');
        $this->actingAs($this->staffUser)->get('/portal/statements/1/pdf')->assertRedirect('/dashboard');
    }
}
