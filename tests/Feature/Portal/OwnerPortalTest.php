<?php

namespace Tests\Feature\Portal;

use App\Enums\OwnerContractType;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Models\Beneficiary;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\Property;
use App\Models\Provider;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Charges\Issuer;
use App\Services\Charges\Month;
use App\Services\Charges\MonthWorksheet;
use App\Services\LeaseContractService;
use App\Services\OwnerContractService;
use App\Services\Payments\PayoutService;
use App\Services\Utilities\UtilityAccountService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Screens\ScreenTestCase;

/**
 * Порталът за собственика (PROJECT.md, „Портал и служители“, 27.11.2026):
 * договорите за управление, имотите „Под наем“ или „Свободен“, името на
 * наемателя при „Представител, %“ и „Представител, фиксиран“ (не и при
 * „Преотдаване“), наемът и комисионата само при „Представител, %“, по месеци
 * начислено (408), изплатено и остатък, IBAN само като „•••• 1234“. Без
 * битови сметки и без чужди имоти. Чуждото е 404. Само измислени данни.
 *
 * Пример: „Днес“ е 10.01.2027. Петя има три договора от 01.01.2026:
 * A „Представител, %“ 10 % за № 701 (Борис, наем 500 → 450) и № 702 (свободен),
 * по банка на бенефициента Иван Получател (28.11.2026: името се вижда); B „Представител, фиксиран“ 350 за № 703
 * (Мария, наем 400); C „Преотдаване, физическо лице“ 450 за № 704 (Скрит
 * Поднаемател, наем 600). Договорът на Борис е и за № 705 (наем 999), който е
 * на друг собственик. Приключил договор D за № 706 (прекратен от 01.07.2026).
 * Декември и януари са издадени. Изплатено по A 700 (и 50, сторнирано), по B 350.
 */
class OwnerPortalTest extends ScreenTestCase
{
    private User $staffUser;

    private Owner $owner;

    private Owner $otherOwner;

    private OwnerContract $percent;

    private OwnerContract $fixed;

    private OwnerContract $sublease;

    private OwnerContract $ended;

    private OwnerContract $foreign;

    private Property $p701;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2027-01-10 12:00:00'));
        $this->onAddressOf($this->company);
        $this->staffUser = $this->staff(['is_company_admin' => true, 'can_view_sensitive' => true, 'name' => 'Служител Измислен']);
        [$this->p701, $p702, $p703, $p704, $p705, $p706] = array_map(fn ($n) => $this->record(Property::class, ['number' => $n, 'city' => 'Измисленово']), [701, 702, 703, 704, 705, 706]);
        $this->owner = $this->record(Owner::class, ['name' => 'Петя Измислена', 'email' => 'petya@example.test']);
        $this->otherOwner = $this->record(Owner::class, ['name' => 'Чужд Собственик', 'email' => 'chuzhd@example.test']);
        $boris = $this->record(Tenant::class, ['name' => 'Борис Измислен']);
        $maria = $this->record(Tenant::class, ['name' => 'Мария Наемателка']);
        $hidden = $this->record(Tenant::class, ['name' => 'Скрит Поднаемател']);
        $beneficiary = Beneficiary::factory()->create(['company_id' => $this->company->id, 'name' => 'Иван Получател',
            'iban' => 'BG18RZBB91550123456789']);
        $this->actingAs($this->staffUser);

        $contracts = app(OwnerContractService::class);
        $this->percent = $contracts->create(['type' => OwnerContractType::RepresentativePercent, 'owner_id' => $this->owner->id, 'signed_on' => '2026-01-01',
            'commission_percent' => '10', 'beneficiary_id' => $beneficiary->id, 'payment_method' => PaymentMethod::Bank,
            'lines' => [['property_id' => $this->p701->id], ['property_id' => $p702->id]]]);
        $this->fixed = $contracts->create(['type' => OwnerContractType::RepresentativeFixed, 'owner_id' => $this->owner->id, 'signed_on' => '2026-01-01',
            'payment_method' => PaymentMethod::Cash, 'lines' => [['property_id' => $p703->id, 'fixed_amount' => '350']]]);
        $this->sublease = $contracts->create(['type' => OwnerContractType::SubleaseIndividual, 'owner_id' => $this->owner->id, 'signed_on' => '2026-01-01',
            'payment_method' => PaymentMethod::Cash, 'lines' => [['property_id' => $p704->id, 'fixed_amount' => '450']]]);
        $this->ended = $contracts->create(['type' => OwnerContractType::RepresentativePercent, 'owner_id' => $this->owner->id, 'signed_on' => '2026-01-01',
            'commission_percent' => '15', 'payment_method' => PaymentMethod::Cash, 'lines' => [['property_id' => $p706->id]]]);
        $this->ended->update(['terminated_on' => '2026-07-01']); // последният ден е 30.06.2026
        $this->foreign = $contracts->create(['type' => OwnerContractType::RepresentativePercent, 'owner_id' => $this->otherOwner->id, 'signed_on' => '2026-01-01',
            'commission_percent' => '20', 'payment_method' => PaymentMethod::Cash, 'lines' => [['property_id' => $p705->id]]]);

        $leases = app(LeaseContractService::class);
        $leases->create(['tenant_id' => $boris->id, 'signed_on' => '2026-12-01', 'lines' => [
            ['property_id' => $this->p701->id, 'rent' => '500'], ['property_id' => $p705->id, 'rent' => '999'],
        ]]);
        $leases->create(['tenant_id' => $maria->id, 'signed_on' => '2026-12-01', 'lines' => [['property_id' => $p703->id, 'rent' => '400']]]);
        $leases->create(['tenant_id' => $hidden->id, 'signed_on' => '2026-12-01', 'lines' => [['property_id' => $p704->id, 'rent' => '600']]]);

        app(MonthWorksheet::class)->generate('2026-12');
        app(Issuer::class)->issueMonth('2026-12');
        app(MonthWorksheet::class)->generate('2027-01');
        app(Issuer::class)->issueMonth('2027-01');

        $payouts = app(PayoutService::class);
        $payouts->record($this->percent, ['amount' => '700', 'paid_on' => '09.01.2027', 'method' => 'cash']);
        $wrong = $payouts->record($this->percent, ['amount' => '50', 'paid_on' => '09.01.2027', 'method' => 'cash']);
        $payouts->reverse($wrong, 'Грешно въведено (измислено)');
        $payouts->record($this->fixed, ['amount' => '350', 'paid_on' => '09.01.2027', 'method' => 'cash']);
        auth()->logout();

        $this->client = $this->clientFor(owner: $this->owner);
    }

    private function clientFor(?Tenant $tenant = null, ?Owner $owner = null, string $email = 'petya@example.test'): User
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
        return $this->actingAs($this->client)->get("/portal/owners/{$this->owner->id}")->assertOk();
    }

    /** Частта от страницата за един договор (от заглавието му до следващия). */
    private function section(OwnerContract $contract): string
    {
        $html = $this->page()->getContent();
        $this->assertSame(1, preg_match('#data-contract="'.$contract->id.'"(.*?)(data-contract=|\z)#s', $html, $m), 'Няма договор № '.$contract->number);

        return $m[1];
    }

    // --- Начало ---

    public function test_the_home_page_links_to_the_owner_details(): void
    {
        $this->actingAs($this->client)->get('/portal')->assertOk()
            ->assertSee('Петя Измислена')->assertSee("/portal/owners/{$this->owner->id}", false);
    }

    // --- Договори ---

    public function test_the_contracts_include_the_ended_one_with_types_and_periods(): void
    {
        $this->page()
            ->assertSee('№ '.$this->percent->number)->assertSee('№ '.$this->fixed->number)->assertSee('№ '.$this->sublease->number)
            ->assertSee(__('contracts.types.representative_percent'))->assertSee(__('contracts.types.representative_fixed'))
            ->assertSee(__('contracts.types.sublease_individual'));

        $ended = $this->section($this->ended);
        $this->assertStringContainsString(__('portal.tenant.states.ended'), $ended);
        $this->assertStringContainsString('30.06.2026', $ended);
        $this->assertStringContainsString(__('portal.tenant.states.active'), $this->section($this->percent));
    }

    // --- Имоти ---

    public function test_properties_are_rented_or_vacant(): void
    {
        $percent = $this->section($this->percent);
        $this->assertMatchesRegularExpression('#№ 701.*?'.__('portal.owner.rented').'.*?№ 702.*?'.__('portal.owner.vacant').'#s', $percent);
        $this->assertStringContainsString(__('portal.owner.rented'), $this->section($this->fixed));
        $this->assertStringContainsString(__('portal.owner.rented'), $this->section($this->sublease));
    }

    public function test_the_tenant_name_is_shown_for_representative_contracts_only(): void
    {
        $this->assertStringContainsString('Борис Измислен', $this->section($this->percent));
        $this->assertStringContainsString('Мария Наемателка', $this->section($this->fixed));
        $this->page()->assertDontSee('Скрит Поднаемател');
    }

    public function test_rent_and_commission_are_shown_for_the_percent_contract_only(): void
    {
        $percent = $this->section($this->percent);
        $this->assertStringContainsString('500,00 €', $percent);
        $this->assertStringContainsString('10,00 %', $percent);

        $html = $this->page()->assertDontSee('400,00')->assertDontSee('600,00')->getContent();
        $this->assertSame(1, substr_count($html, __('portal.owner.commission')));
        $this->assertStringNotContainsString(__('portal.owner.rent'), $this->section($this->fixed));
        $this->assertStringNotContainsString(__('portal.owner.rent'), $this->section($this->sublease));
    }

    public function test_foreign_properties_contracts_and_owners_are_not_shown(): void
    {
        $this->page()->assertDontSee('№ 705')->assertDontSee('999,00')->assertDontSee('Чужд Собственик')
            ->assertDontSee('№ '.$this->foreign->number.' ', false)->assertDontSee('20,00 %');
    }

    // --- По месеци ---

    public function test_months_show_accrued_paid_and_left_without_the_reversed_payout(): void
    {
        $percent = $this->section($this->percent);
        $this->assertMatchesRegularExpression('#'.Month::label(CarbonImmutable::parse('2026-12-01')).'.*?450,00.*?450,00.*?0,00#s', $percent);
        $this->assertMatchesRegularExpression('#'.Month::label(CarbonImmutable::parse('2027-01-01')).'.*?450,00.*?250,00.*?200,00#s', $percent);
        $this->assertMatchesRegularExpression('#'.__('portal.owner.left_total').'.*?200,00 €#s', $percent);

        $this->assertMatchesRegularExpression('#'.Month::label(CarbonImmutable::parse('2027-01-01')).'.*?350,00.*?0,00.*?350,00#s', $this->section($this->fixed));
        $this->assertMatchesRegularExpression('#'.__('portal.owner.left_total').'.*?900,00 €#s', $this->section($this->sublease));
    }

    public function test_an_overpaid_contract_shows_overpaid_without_a_minus(): void
    {
        $this->actingAs($this->staffUser);
        app(PayoutService::class)->record($this->sublease, ['amount' => '1000', 'paid_on' => '09.01.2027', 'method' => 'cash']);
        auth()->logout();

        $sublease = $this->section($this->sublease);
        $this->assertStringContainsString(__('dossiers.overpaid', ['amount' => '100,00']), $sublease);
        $this->assertStringNotContainsString('-100,00', $sublease);
    }

    // --- Изплащане ---

    public function test_the_payee_name_is_shown_and_the_iban_only_as_the_last_four_digits(): void
    {
        $this->assertMatchesRegularExpression('#'.__('portal.owner.payee').'.*?Иван Получател.*?•••• 6789#s', $this->section($this->percent));
        $this->assertStringContainsString(__('contracts.payment_methods.cash'), $this->section($this->fixed));
        $this->page()->assertDontSee('BG18RZBB91550123456789')->assertDontSee('RZBB')->assertDontSee('9155');
    }

    // --- Без битови сметки и без служители ---

    public function test_utility_accounts_and_staff_names_are_not_shown(): void
    {
        $this->actingAs($this->staffUser);
        $provider = Provider::query()->create(['name' => 'Измислено Електро', 'easypay_name' => 'IZM EL']);
        app(UtilityAccountService::class)->create(['provider_id' => $provider->id, 'number' => '0098765', 'kind' => 'electricity',
            'billing' => 'actual', 'valid_from' => '2026-01-01', 'properties' => [['property_id' => $this->p701->id]]]);
        auth()->logout();

        $this->page()->assertDontSee('0098765')->assertDontSee('Измислено Електро')->assertDontSee('Служител Измислен');
    }

    // --- Само своето ---

    public function test_another_owner_is_not_found(): void
    {
        $this->actingAs($this->client)->get("/portal/owners/{$this->otherOwner->id}")->assertNotFound();
    }

    public function test_a_tenant_only_profile_has_no_owner_page(): void
    {
        $tenant = $this->record(Tenant::class, ['name' => 'Само Наемател', 'email' => 'samo@example.test']);
        $user = $this->clientFor(tenant: $tenant, email: 'samo@example.test');

        $this->actingAs($user)->get("/portal/owners/{$this->owner->id}")->assertNotFound();
    }

    public function test_an_owner_of_another_company_is_not_found(): void
    {
        $alien = Owner::factory()->create(['company_id' => $this->otherCompany->id, 'name' => 'Друга Фирма Собственик']);
        DB::table('portal_links')->insert(['company_id' => $this->otherCompany->id, 'user_id' => User::factory()->create([
            'company_id' => $this->otherCompany->id, 'role' => Role::Client, 'email' => 'alien@example.test', 'access_level' => 'read',
            'property_scope' => 'assigned'])->id, 'owner_id' => $alien->id]);

        $this->actingAs($this->client)->get("/portal/owners/{$alien->id}")->assertNotFound();
    }

    public function test_an_archived_owner_is_not_found(): void
    {
        // Профилът е и наемател, за да остане активен без собственика.
        $tenant = $this->record(Tenant::class, ['name' => 'Петя Наемател', 'email' => 'petya@example.test']);
        DB::table('portal_links')->insert(['company_id' => $this->company->id, 'user_id' => $this->client->id, 'tenant_id' => $tenant->id]);
        DB::table('owners')->where('id', $this->owner->id)->update(['archived_at' => now()]);

        $this->actingAs($this->client)->get("/portal/owners/{$this->owner->id}")->assertNotFound();
    }

    public function test_staff_are_sent_to_their_own_screens(): void
    {
        $this->actingAs($this->staffUser)->get("/portal/owners/{$this->owner->id}")->assertRedirect('/dashboard');
    }
}
