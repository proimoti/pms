<?php

namespace Tests\Feature\Commands;

use App\Enums\PaymentSource;
use App\Models\Company;
use App\Models\PropertyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * pms:create-company (PROJECT.md, т. 2 „Създаване на фирма и служител“):
 * създава фирма и готовия списък с видове имоти за нея.
 */
class CreateCompanyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_a_company(): void
    {
        $this->runCommand('Тест Алфа ООД', '000000000', 'BG000000000', 'Измислен Управител', 'гр. София, ул. Измислена 1', 'office@example.test')
            ->assertSuccessful();

        $company = Company::query()->sole();
        $this->assertSame('Тест Алфа ООД', $company->name);
        $this->assertSame('000000000', $company->eik);
        $this->assertSame('BG000000000', $company->vat_number);
        $this->assertSame('Измислен Управител', $company->manager_name);
        $this->assertSame('office@example.test', $company->email);
        $this->assertSame(PaymentSource::Manual, $company->payment_source);
        $this->assertTrue($company->is_active);
    }

    public function test_optional_fields_can_be_left_empty(): void
    {
        $this->runCommand('Тест Бета ООД', '0000000000000', '', '', '', '')->assertSuccessful();

        $company = Company::query()->sole();
        $this->assertNull($company->vat_number);
        $this->assertNull($company->manager_name);
        $this->assertNull($company->address);
        $this->assertNull($company->email);
    }

    public function test_default_property_types_are_created_for_the_new_company_only(): void
    {
        $other = Company::factory()->create();
        PropertyType::factory()->create(['company_id' => $other->id, 'name' => 'Друг вид']);

        $this->runCommand('Тест Алфа ООД', '000000000', '', '', '', '')->assertSuccessful();

        $company = Company::query()->where('eik', '000000000')->sole();
        $this->assertSame(PropertyType::DEFAULTS,
            PropertyType::query()->where('company_id', $company->id)->orderBy('sort_order')->pluck('name')->all());
        $this->assertSame(['Друг вид'], PropertyType::query()->where('company_id', $other->id)->pluck('name')->all());
    }

    public function test_eik_must_be_9_or_13_digits(): void
    {
        foreach (['12345678', '1234567890', 'ABCDEFGHI', ''] as $bad) {
            $this->artisan('pms:create-company')
                ->expectsQuestion('Име на фирмата', 'Тест Алфа ООД')
                ->expectsQuestion('ЕИК', $bad)
                ->assertFailed();
        }

        $this->assertSame(0, Company::query()->count());
    }

    public function test_duplicate_eik_is_rejected(): void
    {
        Company::factory()->create(['eik' => '000000000']);

        $this->artisan('pms:create-company')
            ->expectsQuestion('Име на фирмата', 'Тест Алфа ООД')
            ->expectsQuestion('ЕИК', '000000000')
            ->assertFailed();

        $this->assertSame(1, Company::query()->count());
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->runCommand('Тест Алфа ООД', '000000000', '', '', '', 'not-an-email')->assertFailed();

        $this->assertSame(0, Company::query()->count());
    }

    private function runCommand(string $name, string $eik, string $vat, string $manager, string $address, string $email)
    {
        return $this->artisan('pms:create-company')
            ->expectsQuestion('Име на фирмата', $name)
            ->expectsQuestion('ЕИК', $eik)
            ->expectsQuestion('ДДС номер (по избор)', $vat)
            ->expectsQuestion('МОЛ (по избор)', $manager)
            ->expectsQuestion('Адрес (по избор)', $address)
            ->expectsQuestion('Имейл (по избор)', $email);
    }
}
