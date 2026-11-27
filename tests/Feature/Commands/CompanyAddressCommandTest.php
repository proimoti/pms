<?php

namespace Tests\Feature\Commands;

use App\Models\Company;
use App\Models\CompanyDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * pms:company-address (27.11.2026): адресите (домейн или поддомейн) на фирма,
 * по които се определя фирмата при вход. Пуска се на сървъра от доставчика.
 */
class CompanyAddressCommandTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create(['eik' => '000000000', 'name' => 'Тест Алфа ООД']);
    }

    public function test_adds_an_address_in_small_letters_without_http_and_port(): void
    {
        $this->artisan('pms:company-address', ['eik' => '000000000', 'host' => 'HTTPS://Firma-A.Example.test:8443/'])->assertSuccessful();

        $this->assertSame(['firma-a.example.test'], CompanyDomain::query()->where('company_id', $this->company->id)->pluck('host')->all());
    }

    public function test_an_address_belongs_to_one_company_only(): void
    {
        $other = Company::factory()->create(['eik' => '111111111']);
        $this->artisan('pms:company-address', ['eik' => '000000000', 'host' => 'firma-a.test'])->assertSuccessful();

        $this->artisan('pms:company-address', ['eik' => '111111111', 'host' => 'firma-a.test'])
            ->expectsOutputToContain('Тест Алфа ООД')->assertFailed();
        $this->assertSame(0, CompanyDomain::query()->where('company_id', $other->id)->count());
    }

    public function test_the_admin_address_is_not_a_company_address(): void
    {
        config(['pms.admin_host' => 'admin.test']);

        $this->artisan('pms:company-address', ['eik' => '000000000', 'host' => 'admin.test'])->assertFailed();
        $this->assertSame(0, CompanyDomain::query()->count());
    }

    public function test_unknown_company_or_bad_address_is_rejected(): void
    {
        $this->artisan('pms:company-address', ['eik' => '999999999', 'host' => 'firma-a.test'])->assertFailed();
        $this->artisan('pms:company-address', ['eik' => '000000000', 'host' => 'не е адрес'])->assertFailed();
        $this->assertSame(0, CompanyDomain::query()->count());
    }

    public function test_lists_and_removes_addresses(): void
    {
        $this->artisan('pms:company-address', ['eik' => '000000000', 'host' => 'firma-a.test'])->assertSuccessful();

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('pms:company-address'));
        $this->assertMatchesRegularExpression('/firma-a\.test\s*\|\s*Тест Алфа ООД/u', \Illuminate\Support\Facades\Artisan::output());

        $this->artisan('pms:company-address', ['eik' => '000000000', 'host' => 'firma-a.test', '--remove' => true])
            ->expectsConfirmation('Да се махне ли адресът firma-a.test от „Тест Алфа ООД“? Потребителите на фирмата няма да могат да влизат на него.', 'yes')
            ->assertSuccessful();
        $this->assertSame(0, CompanyDomain::query()->count());
    }
}
