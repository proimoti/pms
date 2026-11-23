<?php

namespace Tests\Feature\Commands;

use App\Enums\AccessLevel;
use App\Enums\ChargeType;
use App\Enums\OwnerContractType;
use App\Enums\PaymentMethod;
use App\Enums\PropertyScope;
use App\Enums\Role;
use App\Models\Beneficiary;
use App\Models\Broker;
use App\Models\Company;
use App\Models\LeaseContract;
use App\Models\LeaseContractLine;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\OwnerContractPayee;
use App\Models\Property;
use App\Models\PropertyType;
use App\Models\Provider;
use App\Models\Tenant;
use App\Models\UpcomingCharge;
use App\Models\User;
use App\Models\UtilityAccount;
use App\Services\Charges\Cents;
use App\Services\Charges\ChargeCalculator;
use App\Services\Charges\ProposedCharge;
use App\Services\Utilities\UtilityImporter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Командата pms:demo-data: нова измислена демо фирма с трудните случаи за
 * тестване. Работи само с ALLOW_DEMO_DATA=true; не трие и не пипа нищо
 * съществуващо; всяко пускане е нова фирма. Само измислени данни.
 */
class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local'); // демо файловете за внос не отиват в истинската папка
    }

    private const PASSWORD = 'Измислена-парола-2026';

    private function runDemo(string $email = 'demo-test@example.test')
    {
        return $this->artisan('pms:demo-data')
            ->expectsQuestion('Имейл на служителя', $email)
            ->expectsQuestion('Име на служителя', 'Демо Служител')
            ->expectsQuestion('Парола (поне 12 знака, не се вижда)', self::PASSWORD)
            ->expectsQuestion('Повторете паролата', self::PASSWORD);
    }

    private function demoCompany(): Company
    {
        return Company::query()->where('name', 'like', 'Демо Имоти%')->latest('id')->firstOrFail();
    }

    /** Месецът на трудните случаи: следващият. */
    private function month(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfMonth()->addMonth();
    }

    private function rowsOf(string $model, Company $company): int
    {
        return $model::query()->withoutGlobalScopes()->where('company_id', $company->id)->count();
    }

    public function test_refuses_without_permission_in_env(): void
    {
        config(['app.allow_demo_data' => false]);

        $this->artisan('pms:demo-data')
            ->expectsOutputToContain('ALLOW_DEMO_DATA')
            ->assertFailed();

        $this->assertSame(0, Company::query()->count());
        $this->assertSame(0, User::query()->count());
    }

    public function test_creates_a_new_demo_company_with_a_company_admin(): void
    {
        config(['app.allow_demo_data' => true]);

        $this->runDemo()->assertSuccessful();

        $company = $this->demoCompany();
        $this->assertSame(count(PropertyType::DEFAULTS), $this->rowsOf(PropertyType::class, $company));
        $this->assertSame(15, $this->rowsOf(Property::class, $company));
        $this->assertSame(10, $this->rowsOf(Owner::class, $company));
        $this->assertSame(11, $this->rowsOf(Tenant::class, $company));
        $this->assertSame(10, $this->rowsOf(Broker::class, $company));
        $this->assertGreaterThanOrEqual(8, $this->rowsOf(Beneficiary::class, $company));

        $staff = User::query()->where('email', 'demo-test@example.test')->sole();
        $this->assertSame($company->id, $staff->company_id);
        $this->assertSame(Role::Staff, $staff->role);
        $this->assertTrue((bool) $staff->is_company_admin);
        $this->assertSame(AccessLevel::Write, $staff->access_level);
        $this->assertSame(PropertyScope::All, $staff->property_scope);
        $this->assertTrue(Hash::check(self::PASSWORD, $staff->password));
    }

    public function test_does_not_touch_other_companies_and_every_run_is_a_new_company(): void
    {
        config(['app.allow_demo_data' => true]);
        $other = Company::factory()->create(['name' => 'Тест Бета ООД']);
        Property::factory()->create(['company_id' => $other->id]);
        $before = $other->fresh()->toArray();

        $this->runDemo('demo-1@example.test')->assertSuccessful();
        $this->runDemo('demo-2@example.test')->assertSuccessful();

        $demo = Company::query()->where('name', 'like', 'Демо Имоти%')->get();
        $this->assertCount(2, $demo);
        $this->assertNotSame($demo[0]->eik, $demo[1]->eik);
        $this->assertSame($before, $other->fresh()->toArray());
        $this->assertSame(1, $this->rowsOf(Property::class, $other));
        $this->assertSame(0, $this->rowsOf(LeaseContract::class, $other));
        $this->assertSame(0, $this->rowsOf(OwnerContract::class, $other));
    }

    public function test_password_mismatch_creates_nothing(): void
    {
        config(['app.allow_demo_data' => true]);

        $this->artisan('pms:demo-data')
            ->expectsQuestion('Имейл на служителя', 'demo@example.test')
            ->expectsQuestion('Име на служителя', 'Демо Служител')
            ->expectsQuestion('Парола (поне 12 знака, не се вижда)', self::PASSWORD)
            ->expectsQuestion('Повторете паролата', 'друга-парола-123456')
            ->assertFailed();

        $this->assertSame(0, Company::query()->count());
    }

    public function test_the_hard_cases_are_there(): void
    {
        config(['app.allow_demo_data' => true]);
        $this->runDemo()->assertSuccessful();
        $company = $this->demoCompany();
        $month = $this->month();
        $managements = OwnerContract::query()->withoutGlobalScopes()->where('company_id', $company->id)->get();
        $leases = LeaseContract::query()->withoutGlobalScopes()->where('company_id', $company->id)->get();
        $lines = LeaseContractLine::query()->withoutGlobalScopes()->where('company_id', $company->id)->get();

        // Петте типа договори за управление; договор с повече от 5 имота.
        $this->assertEqualsCanonicalizing(array_map(fn ($t) => $t->value, OwnerContractType::cases()),
            $managements->pluck('type')->map(fn ($t) => $t->value)->unique()->values()->all());
        $this->assertTrue($managements->contains(fn ($c) => $c->lines()->withoutGlobalScopes()->distinct()->count('property_id') > 5));

        // Гараж с наем 0; наемател от 16-о число; договорен наем за първия период.
        $this->assertTrue($lines->contains(fn ($l) => $l->rent === '0.00'));
        $this->assertTrue($lines->contains(fn ($l) => $l->valid_from->equalTo($month->addDays(15)) && $l->first_period_rent !== null));
        // Прекратяване на 15-о число.
        $this->assertTrue($leases->contains(fn ($l) => $l->terminated_on?->equalTo($month->addDays(14))));
        // Анекс с нов наем и анекс с нов процент.
        $this->assertTrue($lines->contains(fn ($l) => $l->annex_id !== null && $l->rent === '650.00'));
        $this->assertTrue($managements->contains(fn ($c) => $c->commissions()->withoutGlobalScopes()->where('percent', '12.00')->exists()));
        // Бенефициент различен от собственика; плащане по банка без IBAN.
        $payees = OwnerContractPayee::query()->withoutGlobalScopes()->where('company_id', $company->id)->with('beneficiary')->get();
        $this->assertTrue($payees->contains(fn ($p) => $p->beneficiary->owner_id === null && $p->beneficiary->getRawOriginal('iban') !== null));
        $this->assertTrue($payees->contains(fn ($p) => $p->payment_method === PaymentMethod::Bank && $p->beneficiary->getRawOriginal('iban') === null));
        // Предстоящ депозит на вноски до договорения размер.
        $deposits = UpcomingCharge::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('type', ChargeType::Deposit)->get();
        $this->assertCount(3, $deposits);
        $this->assertSame('1200.00', Cents::format($deposits->sum(fn ($d) => Cents::from($d->amount))));
        // Един наемател с два договора за наем.
        $this->assertTrue($leases->groupBy('tenant_id')->contains(fn ($group) => $group->count() === 2));

        // Сметката за месец М: двата имота „за решение“ (без договор за управление и с частичен).
        $charges = app(ChargeCalculator::class)->forMonth($company->id, $month->format('Y-m'));
        $this->assertCount(2, $charges->forDecision());
        $this->assertTrue(collect($charges->forDecision())->contains(fn ($d) => $d->daysWithoutManagement === $month->daysInMonth));
        $this->assertTrue(collect($charges->forDecision())->contains(fn ($d) => $d->daysWithoutManagement < $month->daysInMonth));

        // Фиксирана сума от 10-о число: по дните (като 460 → 326,45 при 31 дни).
        $expected = Cents::format(Cents::divide(46000 * ($month->daysInMonth - 9), $month->daysInMonth));
        $this->assertTrue(collect($charges->charges())->contains(fn (ProposedCharge $c) => $c->type === ProposedCharge::OWNER
            && $c->amount === $expected && $c->days === $month->daysInMonth - 9));

        // Двата договора на един наемател дават две отделни сметки.
        $tenantWithTwo = $leases->groupBy('tenant_id')->first(fn ($group) => $group->count() === 2)->first()->tenant_id;
        $rents = collect($charges->charges())->where('type', ProposedCharge::RENT)->where('tenantId', $tenantWithTwo);
        $this->assertCount(2, $rents->pluck('leaseContractId')->unique());
    }

    public function test_utility_accounts_and_sample_import_files(): void
    {
        // Битови сметки (PROJECT.md, т. 3.4 и т. 5.3): доставчици, абонати (един имот,
        // общ 50/50 и 33,33/33,33/33,34, фиксиран) и два измислени файла за внос.
        Storage::fake('local');
        config(['app.allow_demo_data' => true]);
        $this->runDemo()->expectsOutputToContain('easypay-demo.xlsx')->expectsOutputToContain('epay-demo.xlsx')->assertSuccessful();
        $company = $this->demoCompany();

        $this->assertSame(4, $this->rowsOf(Provider::class, $company));
        $accounts = UtilityAccount::query()->withoutGlobalScopes()->where('company_id', $company->id)->with(['properties' => fn ($q) => $q->withoutGlobalScopes()])->get();
        $this->assertTrue($accounts->contains(fn ($a) => $a->isFixed() && $a->fixed_amount === '15.00'));
        $this->assertTrue($accounts->contains(fn ($a) => $a->properties->pluck('share')->all() === ['50.00', '50.00']));
        $this->assertTrue($accounts->contains(fn ($a) => $a->properties->pluck('share')->all() === ['33.33', '33.33', '33.34']));
        $this->assertTrue($accounts->contains(fn ($a) => str_starts_with((string) $a->number, '00')), 'номер с нули отпред');

        // Общ фонд за вход с няколко имота; фиксираният абонат е към него, общият ток се плаща от него.
        $fund = \App\Models\Fund::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Вход (измислен)')->sole();
        $this->assertNotNull($fund->analytic);
        $this->assertGreaterThan(1, count($fund->propertyIds()));
        $this->assertTrue($accounts->filter(fn ($a) => $a->isFixed())->every(fn ($a) => $a->fund_id === $fund->id));
        $light = $accounts->first(fn ($a) => $a->paid_from_fund);
        $this->assertSame($fund->id, $light->fund_id);
        $this->assertFalse($light->isFixed());

        $folder = "demo/{$company->id}";
        Storage::disk('local')->assertExists(["{$folder}/easypay-demo.xlsx", "{$folder}/epay-demo.xlsx"]);

        // Прегледът на файловете като демо служителя: разпознати, „за решение“, повторен ред, такси.
        $this->actingAs(User::query()->where('email', 'demo-test@example.test')->sole());
        $importer = app(UtilityImporter::class);

        $easypay = $importer->preview(Storage::disk('local')->path("{$folder}/easypay-demo.xlsx"));
        $this->assertSame('easypay', $easypay->source);
        $this->assertCount(8, $easypay->bills, '7 сметки по имоти и общият ток на входа, платен от фонда');
        $fromFund = collect($easypay->bills)->first(fn ($b) => $b->account->id === $light->id);
        $this->assertSame([], $fromFund->parts, 'сметката от фонда не се дели');
        $this->assertSame(['unknown_number'], array_map(fn ($i) => $i->reason, $easypay->issues));
        $this->assertCount(1, $easypay->duplicates);

        $epay = $importer->preview(Storage::disk('local')->path("{$folder}/epay-demo.xlsx"));
        $this->assertSame('epay', $epay->source);
        $this->assertCount(2, $epay->bills);
        $this->assertSame(['unknown_provider'], array_map(fn ($i) => $i->reason, $epay->issues));
        $this->assertSame(2, $epay->skippedFees);
        $shared = collect($epay->bills)->first(fn ($b) => count($b->parts) === 3);
        $this->assertSame(['40.40', '40.40', '40.41'], array_values($shared->parts), '121,21 € при 33,33/33,33/33,34: стотинката към последния');
    }
}