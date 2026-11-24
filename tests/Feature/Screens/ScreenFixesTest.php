<?php

namespace Tests\Feature\Screens;

use App\Enums\ChargeType;
use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Enums\OwnerContractType;
use App\Models\Beneficiary;
use App\Models\Broker;
use App\Models\LeaseContract;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Provider;
use App\Models\Statement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UtilityAccount;
use App\Services\Charges\Issuer;
use App\Services\Charges\Ledger;
use App\Services\LeaseContractService;
use App\Services\OwnerContractService;
use Carbon\CarbonImmutable;
use Tests\Support\DossierScenario;

/**
 * Поправки по съдържанието на екраните (PROJECT.md, т. 2.1–2.13, 24.11.2026):
 * колони и сортиране по регистрите и договорите, номер на бенефициента и на
 * отговорното лице, картите на имот, наемател („Депозити“, „Надплатено“) и
 * отговорно лице („Имоти“), търсене на известия, пояснение за „Ден от / Ден
 * до“, вид услуга при доставчика. „Днес“ е 24.11.2026. Само измислени данни.
 */
class ScreenFixesTest extends ScreenTestCase
{
    use DossierScenario;

    private User $user;

    private Broker $broker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00:00'));
        $this->user = $this->staff();
        $this->buildDossierScenario($this->user);
        $this->broker = Broker::query()->create(['name' => 'Иво Измислен', 'phone' => '0888 400 401', 'email' => 'ivo@example.test']);
        $this->flat->update(['broker_id' => $this->broker->id]);
    }

    /** Заглавията на колоните на списъка, по ред. */
    private function headings(string $url): array
    {
        preg_match('#<thead>(.*?)</thead>#s', $this->get($url)->assertOk()->getContent(), $head);
        preg_match_all('#<th[^>]*>(.*?)</th>#s', $head[1] ?? '', $cells);

        return array_map(fn ($cell) => trim(strip_tags($cell)), $cells[1]);
    }

    /** Текстът на реда в списъка, който води към $href. */
    private function row(string $url, string $href): string
    {
        $html = $this->get($url)->getContent();
        $start = strpos($html, '<tr data-href="'.$href.'">');
        $this->assertNotFalse($start, "Няма ред за {$href}.");

        return substr($html, $start, strpos($html, '</tr>', $start) - $start);
    }

    private function party(string $class, string $name): Tenant|Owner
    {
        auth()->logout();
        $party = $class::factory()->create(['company_id' => $this->company->id, 'name' => $name]);
        $this->actingAs($this->user);

        return $party;
    }

    // 2.1 Имоти (списък)

    public function test_properties_list_columns_and_order(): void
    {
        $c = 'registry.columns.';
        $this->assertSame([__($c.'number'), __($c.'address'), __($c.'owner'), __($c.'tenant'), __($c.'broker')], $this->headings('/properties'));

        $row = $this->row('/properties', "/properties/{$this->flat->id}");
        $this->assertStringContainsString('Петя Измислена', $row);
        $this->assertStringContainsString('Борис Измислен', $row);
        $this->assertStringNotContainsString('Анна Измислена', $row); // миналият наемател не е сегашен
        $this->assertStringContainsString('Иво Измислен', $row);

        $this->get('/properties')->assertSeeInOrder(['data-href="/properties/'.$this->flat->id.'"', 'data-href="/properties/'.$this->empty->id.'"'], false);
    }

    // 2.2 Имот (карта)

    public function test_property_card_without_funds_section_and_hint(): void
    {
        $this->get("/properties/{$this->flat->id}")->assertOk()
            ->assertDontSee('<h2>'.__('dossiers.sections.funds').'</h2>', false)
            ->assertDontSee(__('dossiers.balances_hint'))
            ->assertSee('Вход (измислен)'); // фондът се вижда при абонатите
    }

    // 2.3 Собственици

    public function test_owners_list_columns_contract_numbers_and_sorting_by_name(): void
    {
        $first = $this->party(Owner::class, 'Аделина Измислена');
        $c = 'registry.columns.';
        $this->assertSame([__($c.'number'), __($c.'name'), __($c.'owner_contracts')], $this->headings('/owners'));
        $this->assertStringContainsString('№ '.$this->management->number, $this->row('/owners', "/owners/{$this->owner->id}"));
        $this->get('/owners')->assertSeeInOrder(['Аделина Измислена', 'Петя Измислена']);
        $this->assertGreaterThan($this->owner->number, $first->number);
    }

    // 2.4 Бенефициенти

    public function test_beneficiaries_get_the_smallest_free_number_and_the_list_columns(): void
    {
        $this->assertSame(1, $this->beneficiary->fresh()->number);
        auth()->logout();
        $second = Beneficiary::factory()->create(['company_id' => $this->company->id, 'name' => 'Ана Получател']);
        $foreign = Beneficiary::factory()->create(['company_id' => $this->otherCompany->id, 'name' => 'Чужд Получател']);
        $this->actingAs($this->user);
        $this->assertSame(2, $second->number);
        $this->assertSame(1, $foreign->number); // всяка фирма брои отделно

        $c = 'registry.columns.';
        $this->assertSame([__($c.'number'), __($c.'name'), __($c.'owner_contracts')], $this->headings('/beneficiaries'));
        $row = $this->row('/beneficiaries', "/beneficiaries/{$this->beneficiary->id}");
        $this->assertStringContainsString('>1<', $row);
        $this->assertStringContainsString('№ '.$this->management->number, $row);
        $this->get('/beneficiaries')->assertSeeInOrder(['Ана Получател', 'Иван Получател'])->assertDontSee('Чужд Получател');
        $this->get("/beneficiaries/{$this->beneficiary->id}")->assertSee(__('registry.one.beneficiaries').' № 1');
    }

    // 2.5 Наематели

    public function test_tenants_list_columns_with_only_current_leases_sorted_by_name(): void
    {
        $c = 'registry.columns.';
        $this->assertSame([__($c.'number'), __($c.'name'), __($c.'lease_contracts')], $this->headings('/tenants'));
        $this->assertStringContainsString('№ '.$this->lease->number, $this->row('/tenants', "/tenants/{$this->boris->id}"));
        $this->assertStringNotContainsString('№ '.$this->oldLease->number, $this->row('/tenants', "/tenants/{$this->anna->id}"));
        $this->get('/tenants')->assertSeeInOrder(['Анна Измислена', 'Борис Измислен']);
    }

    public function test_upcoming_lease_is_shown_with_its_start_date(): void
    {
        // Предстоящ договор (24.11.2026): „№ 3 (от 16.12.2026)“ при наемателя и при имота без сегашен наемател.
        $hristo = $this->party(Tenant::class, 'Христо Измислен');
        $upcoming = app(LeaseContractService::class)->create(['tenant_id' => $hristo->id, 'signed_on' => '2026-12-16',
            'lines' => [['property_id' => $this->empty->id, 'rent' => '400']]]);
        $label = __('registry.upcoming_from', ['date' => '16.12.2026']);

        $tenant = $this->row('/tenants', "/tenants/{$hristo->id}");
        $this->assertStringContainsString('№ '.$upcoming->number, $tenant);
        $this->assertStringContainsString($label, $tenant);
        // Сегашният договор е без бележка.
        $this->assertStringNotContainsString('(от', $this->row('/tenants', "/tenants/{$this->boris->id}"));

        $property = $this->row('/properties', "/properties/{$this->empty->id}");
        $this->assertStringContainsString('Христо Измислен', $property);
        $this->assertStringContainsString($label, $property);
        $this->assertStringNotContainsString('(от', $this->row('/properties', "/properties/{$this->flat->id}"));
    }

    // 2.6 Наемател (карта)

    public function test_tenant_card_without_statements_and_hint_with_deposits_next_to_owes(): void
    {
        $property = Property::factory()->create(['company_id' => $this->company->id, 'number' => 110]);
        app(OwnerContractService::class)->create(['type' => OwnerContractType::Own, 'signed_on' => '2026-01-01', 'lines' => [['property_id' => $property->id]]]);
        $lease = app(LeaseContractService::class)->create(['tenant_id' => $this->boris->id, 'signed_on' => '2026-11-01',
            'lines' => [['property_id' => $property->id, 'rent' => '400', 'deposit' => '1000']]]);
        Ledger::book(CarbonImmutable::parse('2026-11-01'), LedgerKind::Manual, ['account' => LedgerAccount::Deposit, 'type' => ChargeType::Deposit,
            'lease_contract_id' => $lease->id, 'tenant_id' => $this->boris->id, 'property_id' => $property->id], 60000);

        $html = $this->get("/tenants/{$this->boris->id}")->assertOk()->getContent();

        $this->assertStringNotContainsString('<h2>'.__('dossiers.sections.statements').'</h2>', $html);
        $this->assertStringNotContainsString(__('dossiers.balances_hint'), $html);
        $this->assertMatchesRegularExpression('#'.preg_quote(__('dossiers.sections.owes'), '#').'.*'.preg_quote(__('dossiers.sections.deposits'), '#').'#s', $html);
        $deposits = substr($html, strpos($html, 'id="deposits"'));
        foreach ([__('dossiers.deposits.contracted'), __('dossiers.deposits.charged'), __('dossiers.deposits.paid')] as $label) {
            $this->assertStringContainsString($label, $deposits);
        }
        $this->assertStringContainsString('href="/lease-contracts/'.$lease->id.'"', $deposits);
        $this->assertStringContainsString('1 000,00', $deposits);
        $this->assertStringContainsString('600,00', $deposits);
    }

    public function test_overpaid_is_shown_in_green_without_minus(): void
    {
        // Корекция в полза на наемателя: по договора −100 € (418).
        Ledger::book(CarbonImmutable::parse('2026-12-01'), LedgerKind::Manual, ['account' => LedgerAccount::Tenant, 'type' => ChargeType::Rent,
            'lease_contract_id' => $this->lease->id, 'tenant_id' => $this->boris->id, 'property_id' => $this->flat->id], -60000);

        $html = $this->get("/tenants/{$this->boris->id}")->getContent();
        $this->assertStringContainsString('<span class="overpaid">'.__('dossiers.overpaid', ['amount' => '100,00']).'</span>', $html);
        $this->assertStringNotContainsString('-100,00', $html);
        $this->get("/lease-contracts/{$this->lease->id}")->assertSee(__('dossiers.overpaid', ['amount' => '100,00']))->assertDontSee('-100,00');
    }

    // 2.7 и 2.8 Отговорни лица

    public function test_brokers_get_numbers_and_the_list_columns(): void
    {
        $second = Broker::query()->create(['name' => 'Ана Измислена']);
        $this->assertSame([1, 2], [$this->broker->fresh()->number, $second->number]);

        $c = 'registry.columns.';
        $this->assertSame([__($c.'number'), __($c.'name'), __($c.'phone'), __($c.'email')], $this->headings('/brokers'));
        $this->get('/brokers')->assertSeeInOrder(['Ана Измислена', 'Иво Измислен']);
        $this->assertStringContainsString('0888 400 401', $this->row('/brokers', "/brokers/{$this->broker->id}"));
    }

    public function test_broker_card_lists_their_properties_sorted_by_owner(): void
    {
        $other = Property::factory()->create(['company_id' => $this->company->id, 'number' => 111, 'broker_id' => $this->broker->id]);
        $aOwner = $this->party(Owner::class, 'Аделина Измислена');
        app(OwnerContractService::class)->create(['type' => OwnerContractType::RepresentativePercent, 'owner_id' => $aOwner->id, 'signed_on' => '2026-01-01',
            'commission_percent' => '10', 'payment_method' => \App\Enums\PaymentMethod::Cash, 'lines' => [['property_id' => $other->id]]]);

        $html = $this->get("/brokers/{$this->broker->id}")->assertOk()->getContent();
        $section = substr($html, strpos($html, 'id="broker-properties"'));

        $this->assertStringContainsString('href="/properties/'.$this->flat->id.'"', $section);
        $this->assertStringContainsString('href="/properties/'.$other->id.'"', $section);
        $this->assertStringNotContainsString('href="/properties/'.$this->empty->id.'"', $section);
        $this->assertLessThan(strpos($section, 'Петя Измислена'), strpos($section, 'Аделина Измислена'));
    }

    // 2.9 Договори за управление

    public function test_owner_contracts_list_column_order(): void
    {
        $s = 'contracts.screens.';
        $this->assertSame([__($s.'fields.number'), __($s.'fields.owner'), __($s.'fields.properties'), __($s.'fields.type'),
            __($s.'columns.signed_on'), __($s.'columns.end_on'), __($s.'fields.state')], $this->headings('/owner-contracts'));
    }

    // 2.11 Известия

    private function moreStatements(): void
    {
        for ($i = 1; $i <= 11; $i++) {
            $property = Property::factory()->create(['company_id' => $this->company->id, 'number' => 200 + $i]);
            app(OwnerContractService::class)->create(['type' => OwnerContractType::Own, 'signed_on' => '2026-01-01', 'lines' => [['property_id' => $property->id]]]);
            $tenant = $this->party(Tenant::class, sprintf('Наемател %02d', $i));
            $lease = app(LeaseContractService::class)->create(['tenant_id' => $tenant->id, 'signed_on' => '2026-12-01', 'lines' => [['property_id' => $property->id, 'rent' => '300']]]);
            app(Issuer::class)->issueNow($lease, '2027-01');
        }
    }

    public function test_statements_search_by_exact_number_and_contract(): void
    {
        $this->moreStatements();
        $two = Statement::query()->where('number', 2)->sole();
        $twelve = Statement::query()->where('number', 12)->sole();

        $this->get('/statements?number=2')->assertOk()
            ->assertSee('href="/statements/'.$two->id.'"', false)->assertDontSee('href="/statements/'.$twelve->id.'"', false);

        $lease3 = LeaseContract::query()->where('number', 3)->sole();
        $lease13 = LeaseContract::query()->where('number', 13)->sole();
        $html = $this->get('/statements?contract=3')->getContent();
        $this->assertStringContainsString('href="/statements/'.Statement::query()->where('lease_contract_id', $lease3->id)->value('id').'"', $html);
        $this->assertStringNotContainsString('href="/statements/'.Statement::query()->where('lease_contract_id', $lease13->id)->value('id').'"', $html);
    }

    public function test_statements_search_by_tenant_name_part_and_month(): void
    {
        $this->moreStatements();
        $boris = Statement::query()->where('lease_contract_id', $this->lease->id)->sole();
        $ten = Statement::query()->whereHas('tenant', fn ($q) => $q->where('name', 'Наемател 10'))->sole();
        $one = Statement::query()->whereHas('tenant', fn ($q) => $q->where('name', 'Наемател 01'))->sole();

        $this->get('/statements?tenant=Наемател 1')->assertSee('href="/statements/'.$ten->id.'"', false)
            ->assertDontSee('href="/statements/'.$one->id.'"', false)->assertDontSee('href="/statements/'.$boris->id.'"', false);
        $this->get('/statements?month=12.2026')->assertSee('href="/statements/'.$boris->id.'"', false)->assertDontSee('href="/statements/'.$ten->id.'"', false);
        $this->get('/statements?month=2026-12')->assertSee('href="/statements/'.$boris->id.'"', false);

        $form = $this->get('/statements?month=12.2026')->getContent();
        foreach (['month', 'number', 'contract', 'tenant'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $form);
        }
    }

    public function test_statements_search_does_not_cross_companies(): void
    {
        $boris = Statement::query()->where('lease_contract_id', $this->lease->id)->sole();
        auth()->logout();
        $this->actingAs($this->staff([], $this->otherCompany));

        $this->get('/statements?number='.$boris->number)->assertOk()->assertDontSee('href="/statements/'.$boris->id.'"', false)->assertDontSee('Борис Измислен');
    }

    // 2.12 Абонат

    public function test_account_card_explains_day_from_and_day_to(): void
    {
        $this->get("/utility-accounts/{$this->meter->id}")->assertOk()->assertSee(__('utilities.hints.period_days'));
    }

    // 2.13 Доставчици

    public function test_provider_has_a_kind_and_a_new_account_takes_it(): void
    {
        $this->post('/providers', ['name' => 'Измислена Вода', 'kind' => 'water'])->assertRedirect();
        $water = Provider::query()->where('name', 'Измислена Вода')->sole();
        $this->assertSame('water', $water->kind->value);

        $this->assertSame([__('utilities.fields.name'), __('utilities.fields.kind'), __('utilities.fields.easypay_name'), __('utilities.fields.epay_name')], $this->headings('/providers'));
        $this->assertStringContainsString(__('utilities.kinds.water'), $this->row('/providers', "/providers/{$water->id}"));
        $this->get("/providers/{$water->id}")->assertSee(__('utilities.kinds.water'));
        $this->get('/utility-accounts/create')->assertSee('data-kind="water"', false);

        // Без избран вид абонатът взима вида на доставчика; избраният вид остава.
        $this->post('/utility-accounts', ['provider_id' => $water->id, 'number' => '777', 'billing' => 'actual', 'valid_from' => '01.12.2026',
            'properties' => [['property_id' => $this->empty->id, 'share' => '100']]]);
        $this->assertSame('water', UtilityAccount::query()->where('number', '777')->sole()->kind->value);
        $this->post('/utility-accounts', ['provider_id' => $water->id, 'number' => '778', 'kind' => 'heating', 'billing' => 'actual', 'valid_from' => '01.12.2026',
            'properties' => [['property_id' => $this->empty->id, 'share' => '100']]]);
        $this->assertSame('heating', UtilityAccount::query()->where('number', '778')->sole()->kind->value);
    }
}
