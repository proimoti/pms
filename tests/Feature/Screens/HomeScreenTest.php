<?php

namespace Tests\Feature\Screens;

use App\Enums\OwnerContractType;
use App\Enums\PaymentMethod;
use App\Models\Beneficiary;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Provider;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserProperty;
use App\Services\Charges\MonthWorksheet;
use App\Services\LeaseContractService;
use App\Services\OwnerContractService;
use App\Services\Utilities\UtilityAccountService;
use App\Services\Utilities\UtilityImporter;
use Carbon\CarbonImmutable;
use Tests\Support\DossierScenario;

/**
 * „Начало“ (PROJECT.md, „Поправки по екраните“ и „Общ вид“, 24.11.2026):
 * тъмна лента за месеца (състояние, заетост, начислен наем, към
 * собствениците, комисиона), „Иска решение“, „За проследяване“ и бързи
 * бутони. Месецът е този, който работната таблица отваря (следващият).
 * Данните са от сегашните услуги; нищо не се записва. „Днес“ е 24.11.2026.
 * Само измислени данни.
 */
class HomeScreenTest extends ScreenTestCase
{
    use DossierScenario;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00:00'));
        $this->user = $this->staff(['name' => 'Демо Служител']);
        $this->buildDossierScenario($this->user);
    }

    private function home(): \Illuminate\Testing\TestResponse
    {
        return $this->get('/dashboard')->assertOk();
    }

    /** Текстът на секцията с id $id. */
    private function section(string $id): string
    {
        $html = $this->home()->getContent();
        $start = strpos($html, 'id="'.$id.'"');
        $this->assertNotFalse($start, "Няма секция {$id}.");

        return substr($html, $start, strpos($html, '</section>', $start) - $start);
    }

    private function tenantLease(Property $property, string $name, string $from, array $extra = []): \App\Models\LeaseContract
    {
        auth()->logout();
        $tenant = Tenant::factory()->create(['company_id' => $this->company->id, 'name' => $name]);
        $this->actingAs($this->user);
        $lease = app(LeaseContractService::class)->create(['tenant_id' => $tenant->id, 'signed_on' => $from, 'lines' => [['property_id' => $property->id, 'rent' => '400']]]);
        if ($extra !== []) {
            $lease->update($extra);
        }

        return $lease;
    }

    private function own(Property $property): void
    {
        app(OwnerContractService::class)->create(['type' => OwnerContractType::Own, 'signed_on' => '2026-01-01', 'lines' => [['property_id' => $property->id]]]);
    }

    public function test_heading_date_and_quick_buttons(): void
    {
        $this->home()
            ->assertSee('<h1>'.__('home.title').'</h1>', false)
            ->assertSee('вторник, 24.11.2026')
            ->assertSee('href="/utility-imports"', false)->assertSee(__('home.buttons.import'))
            ->assertSee('href="/lease-contracts/create"', false)->assertSee(__('home.buttons.new_lease'))
            ->assertSee('href="/charges?month=2026-12"', false)->assertSee(__('home.buttons.generate'));
    }

    public function test_month_band_for_an_issued_month(): void
    {
        $band = $this->section('home-month');

        $this->assertStringContainsString('Декември 2026', $band);
        $this->assertStringContainsString(__('home.states.issued'), $band);
        $this->assertStringContainsString('href="/charges?month=2026-12"', $band);
        // Имот № 101 е отдаден, № 102 е свободен.
        $this->assertStringContainsString('50 %', $band);
        $this->assertStringContainsString(__('home.occupancy_of', ['occupied' => 1, 'total' => 2]), $band);
        // Като в работната таблица: наем 500, към собственика 450, комисиона 50.
        $this->assertStringContainsString('500,00 €', $band);
        $this->assertStringContainsString(__('home.to_owners', ['amount' => '450,00']), $band);
        $this->assertStringContainsString('<span class="big gain">50,00 €</span>', $band);
    }

    public function test_month_band_for_a_generated_and_for_a_new_month(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-24 12:00:00'));
        $band = $this->section('home-month');
        $this->assertStringContainsString('Януари 2027', $band);
        $this->assertStringContainsString(__('home.states.none'), $band);
        $this->assertStringNotContainsString('€', $band);

        app(MonthWorksheet::class)->generate('2027-01');
        $band = $this->section('home-month');
        $this->assertStringContainsString(__('home.states.generated'), $band);
        $this->assertStringContainsString('500,00 €', $band);
    }

    public function test_needs_decision_counts_and_links(): void
    {
        // Внос: ред с непознат доставчик; сметка за свободния № 102; имот с наем без договор за управление; плащане по банка без IBAN.
        $accounts = app(UtilityAccountService::class);
        $accounts->create(['provider_id' => Provider::query()->value('id'), 'number' => '0055555', 'kind' => 'electricity', 'billing' => 'actual',
            'valid_from' => '2026-01-01', 'properties' => [['property_id' => $this->empty->id]]]);
        app(UtilityImporter::class)->import($this->easypayFile([
            ['ref' => 990010, 'date' => '2026-12-05 10:00:00', 'number' => 55555, 'provider' => 'IZM EL', 'amount' => 33.00],
            ['ref' => 990011, 'date' => '2026-12-05 10:00:00', 'number' => 1, 'provider' => 'NEPOZNAT', 'amount' => 10.00],
        ]), 'g.xlsx');
        $loose = Property::factory()->create(['company_id' => $this->company->id, 'number' => 105]);
        $this->tenantLease($loose, 'Христо Измислен', '2026-06-01');
        auth()->logout();
        $owner = Owner::factory()->create(['company_id' => $this->company->id, 'name' => 'Зоя Измислена']);
        $noIban = Beneficiary::factory()->create(['company_id' => $this->company->id, 'name' => 'Без Сметка', 'iban' => null]);
        $this->actingAs($this->user);
        $bank = Property::factory()->create(['company_id' => $this->company->id, 'number' => 106]);
        app(OwnerContractService::class)->create(['type' => OwnerContractType::RepresentativePercent, 'owner_id' => $owner->id, 'signed_on' => '2026-01-01',
            'commission_percent' => '10', 'beneficiary_id' => $noIban->id, 'payment_method' => PaymentMethod::Bank, 'lines' => [['property_id' => $bank->id]]]);

        $decide = $this->section('home-decide');

        foreach ([
            ['/utility-issues', __('home.decide.import')],
            ['/utility-unallocated', __('home.decide.unallocated')],
            ['/charges?month=2026-12#for-decision', __('home.decide.contracts', ['month' => 'декември'])],
            ['/reports/payouts?month=12.2026', __('home.decide.no_iban')],
        ] as [$url, $label]) {
            $this->assertMatchesRegularExpression('#<a class="home-row" href="'.preg_quote($url, '#').'"><span>'.preg_quote($label, '#').'</span><span class="count[^"]*">1</span></a>#', $decide, $label);
        }
        $this->assertStringNotContainsString('BG18', $this->home()->getContent());
    }

    public function test_nothing_to_decide(): void
    {
        $this->assertStringContainsString(__('home.decide.nothing'), $this->section('home-decide'));
    }

    public function test_follow_up_lists_expiring_expired_and_vacant(): void
    {
        $soon = Property::factory()->create(['company_id' => $this->company->id, 'number' => 107]);
        $this->own($soon);
        $expiring = $this->tenantLease($soon, 'Христо Измислен', '2026-06-01', ['end_on' => '2026-12-15']);
        $late = Property::factory()->create(['company_id' => $this->company->id, 'number' => 108]);
        $this->own($late);
        $expired = $this->tenantLease($late, 'Елена Измислена', '2026-02-01', ['end_on' => '2026-10-31']);

        $follow = $this->section('home-follow');

        $this->assertStringContainsString('href="/lease-contracts/'.$expiring->id.'"', $follow);
        $this->assertStringContainsString(__('home.follow.expiring', ['number' => $expiring->number, 'name' => 'Христо Измислен', 'date' => '15.12.2026']), $follow);
        $this->assertStringContainsString(__('home.follow.days', ['days' => 21]), $follow);
        $this->assertStringContainsString('href="/lease-contracts/'.$expired->id.'"', $follow);
        $this->assertStringContainsString(__('home.follow.expired', ['number' => $expired->number, 'name' => 'Елена Измислена']), $follow);
        $this->assertStringContainsString(__('home.follow.since', ['date' => '31.10.2026']), $follow);
        // Свободни: само № 102 (№ 108 е зает до днес по договора с изтекъл срок).
        $this->assertMatchesRegularExpression('#<a class="home-row" href="/reports/vacant"><span>'.preg_quote(__('home.follow.vacant'), '#').'</span><span[^>]*>1</span></a>#', $follow);
    }

    public function test_read_only_staff_without_manage_rights_sees_fewer_buttons(): void
    {
        auth()->logout();
        $reader = $this->readOnlyStaff();
        $this->actingAs($reader);

        $this->home()
            ->assertDontSee('href="/utility-imports"', false)
            ->assertDontSee('href="/lease-contracts/create"', false)
            ->assertDontSee(__('home.buttons.generate'))
            ->assertSee('id="home-month"', false);
    }

    public function test_assigned_only_staff_sees_only_their_properties(): void
    {
        $other = Property::factory()->create(['company_id' => $this->company->id, 'number' => 109]);
        $this->own($other);
        $this->tenantLease($other, 'Христо Измислен', '2026-06-01', ['end_on' => '2026-12-15']);
        auth()->logout();
        $assigned = $this->assignedOnlyStaff();
        (new UserProperty)->forceFill(['company_id' => $this->company->id, 'user_id' => $assigned->id, 'property_id' => $this->flat->id])->save();
        $this->actingAs($assigned);

        $html = $this->home()->getContent();
        $this->assertStringNotContainsString('Христо Измислен', $html);
        $this->assertStringContainsString(__('home.occupancy_of', ['occupied' => 1, 'total' => 1]), $html);
    }

    public function test_company_b_sees_nothing_of_company_a(): void
    {
        auth()->logout();
        $foreign = $this->staff([], $this->otherCompany);
        $this->assertSame($this->otherCompany->id, $foreign->company_id);
        $this->actingAs($foreign);

        $html = $this->home()->getContent();
        $this->assertStringContainsString('Тест Бета ООД', $html);
        $this->assertStringNotContainsString('500,00', $html);
        $this->assertStringNotContainsString('450,00', $html);
        $this->assertStringContainsString(__('home.occupancy_of', ['occupied' => 0, 'total' => 0]), $html);
        $this->assertStringContainsString(__('home.states.none'), $html);
    }

    public function test_users_who_are_not_staff_keep_the_simple_page(): void
    {
        auth()->logout();
        $admin = User::factory()->appAdmin()->withTwoFactor(app(\PragmaRX\Google2FA\Google2FA::class)->generateSecretKey())->create();
        $this->actingAs($admin);

        $this->get('/dashboard')->assertOk()->assertDontSee('id="home-month"', false)->assertSee(__('registry.logout'));
    }
}
