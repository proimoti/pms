<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Правила за фирмите и потребителите, проверени директно в базата
 * (така важат, дори някой да заобиколи кода на приложението).
 */
class CompaniesAndUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_admin_has_no_company(): void
    {
        $admin = User::factory()->appAdmin()->create();

        $this->assertNull($admin->fresh()->company_id);
    }

    public function test_database_rejects_app_admin_with_a_company(): void
    {
        $company = Company::factory()->create();

        $this->expectRuleViolation('users_role_company_check');

        $this->insertUser(['role' => 'app_admin', 'company_id' => $company->id]);
    }

    public function test_database_rejects_staff_without_a_company(): void
    {
        $this->expectRuleViolation('users_role_company_check');

        $this->insertUser(['role' => 'staff', 'company_id' => null]);
    }

    public function test_database_rejects_owner_without_a_company(): void
    {
        $this->expectRuleViolation('users_role_company_check');

        $this->insertUser(['role' => 'owner', 'company_id' => null]);
    }

    public function test_database_rejects_tenant_without_a_company(): void
    {
        $this->expectRuleViolation('users_role_company_check');

        $this->insertUser(['role' => 'tenant', 'company_id' => null]);
    }

    public function test_database_rejects_unknown_role(): void
    {
        $company = Company::factory()->create();

        $this->expectRuleViolation('users_role_check');

        $this->insertUser(['role' => 'boss', 'company_id' => $company->id]);
    }

    public function test_new_user_gets_the_safest_defaults(): void
    {
        $company = Company::factory()->create();

        $id = $this->insertUser(['role' => 'staff', 'company_id' => $company->id]);
        $row = DB::table('users')->find($id);

        $this->assertSame(0, (int) $row->is_company_admin, 'по подразбиране не е администратор на фирмата');
        $this->assertSame(0, (int) $row->can_view_sensitive, 'по подразбиране не вижда ЕГН и IBAN');
        $this->assertSame('read', $row->access_level, 'по подразбиране само чете');
        $this->assertSame('assigned', $row->property_scope, 'по подразбиране само прикачените имоти');
    }

    public function test_email_is_unique_across_all_companies(): void
    {
        User::factory()->for(Company::factory())->create(['email' => 'ivan@example.test']);

        $this->expectRuleViolation('users_email_unique');

        User::factory()->for(Company::factory())->create(['email' => 'ivan@example.test']);
    }

    public function test_company_vat_number_and_manager_are_optional(): void
    {
        $company = Company::factory()->create(['vat_number' => null, 'manager_name' => null]);

        $this->assertNull($company->fresh()->vat_number);
        $this->assertNull($company->fresh()->manager_name);
    }

    public function test_company_payment_source_is_manual_by_default(): void
    {
        $id = DB::table('companies')->insertGetId(['name' => 'Тест Гама ООД', 'eik' => '000000000']);

        $this->assertSame('manual', DB::table('companies')->find($id)->payment_source);
    }

    /** Очаква базата да откаже записа точно заради даденото правило. */
    private function expectRuleViolation(string $ruleName): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage($ruleName);
    }

    /** Записва потребител направо в базата, без кода на приложението. */
    private function insertUser(array $values): int
    {
        return DB::table('users')->insertGetId($values + [
            'name' => 'Тестов Потребител',
            'email' => uniqid('user', true).'@example.test',
            'password' => 'not-a-real-hash',
        ]);
    }
}
