<?php

namespace Tests\Feature\Commands;

use App\Enums\AccessLevel;
use App\Enums\PropertyScope;
use App\Enums\Role;
use App\Models\Company;
use App\Models\PropertyType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * pms:create-staff (PROJECT.md, т. 2 „Създаване на фирма и служител“).
 * Паролата се въвежда скрито и никога не е аргумент на командата.
 */
class CreateStaffCommandTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'staff@example.test';

    private const PASSWORD = 'измислена-парола-123';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create(['eik' => '000000000']);
    }

    public function test_command_creates_a_company_admin_with_the_chosen_rights(): void
    {
        $this->runCommand(companyAdmin: true, access: 'четене и запис', scope: 'всички имоти', sensitive: true)
            ->assertSuccessful();

        $staff = User::query()->where('email', self::EMAIL)->sole();
        $this->assertSame(Role::Staff, $staff->role);
        $this->assertSame($this->company->id, $staff->company_id);
        $this->assertTrue($staff->is_company_admin);
        $this->assertSame(AccessLevel::Write, $staff->access_level);
        $this->assertSame(PropertyScope::All, $staff->property_scope);
        $this->assertTrue($staff->can_view_sensitive);
        $this->assertTrue(Hash::check(self::PASSWORD, $staff->password));
    }

    public function test_command_creates_an_ordinary_read_only_staff_member(): void
    {
        $this->runCommand(companyAdmin: false, access: 'само четене', scope: 'само прикачените имоти', sensitive: false)
            ->assertSuccessful();

        $staff = User::query()->where('email', self::EMAIL)->sole();
        $this->assertFalse($staff->is_company_admin);
        $this->assertSame(AccessLevel::Read, $staff->access_level);
        $this->assertSame(PropertyScope::Assigned, $staff->property_scope);
        $this->assertFalse($staff->can_view_sensitive);
    }

    public function test_password_is_never_printed(): void
    {
        $this->runCommand()->doesntExpectOutputToContain(self::PASSWORD)->assertSuccessful();
    }

    public function test_command_has_no_arguments_or_password_option(): void
    {
        $definition = Artisan::all()['pms:create-staff']->getDefinition();

        $this->assertSame(0, $definition->getArgumentCount());
        $this->assertFalse($definition->hasOption('password'));
    }

    public function test_unknown_company_is_rejected(): void
    {
        $this->artisan('pms:create-staff')
            ->expectsQuestion('ЕИК на фирмата', '999999999')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_existing_email_is_rejected(): void
    {
        User::factory()->create(['email' => self::EMAIL]);

        $this->artisan('pms:create-staff')
            ->expectsQuestion('ЕИК на фирмата', '000000000')
            ->expectsQuestion('Имейл', self::EMAIL)
            ->assertFailed();

        $this->assertSame(1, User::query()->count());
    }

    public function test_short_or_mismatched_password_is_rejected(): void
    {
        foreach ([['12345678901', '12345678901'], [self::PASSWORD, self::PASSWORD.'x']] as [$password, $confirmation]) {
            $this->artisan('pms:create-staff')
                ->expectsQuestion('ЕИК на фирмата', '000000000')
                ->expectsQuestion('Имейл', self::EMAIL)
                ->expectsQuestion('Име', 'Измислен Служител')
                ->expectsQuestion('Парола (поне 12 знака, не се вижда)', $password)
                ->expectsQuestion('Повторете паролата', $confirmation)
                ->assertFailed();
        }

        $this->assertSame(0, User::query()->count());
    }

    public function test_new_staff_must_set_up_the_second_factor_at_first_login(): void
    {
        $this->runCommand()->assertSuccessful();

        $this->post('/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]);

        $this->get('/dashboard')->assertRedirect('/two-factor/setup');
    }

    public function test_new_staff_sees_the_property_types_of_their_company(): void
    {
        PropertyType::factory()->create(['company_id' => $this->company->id, 'name' => 'Апартамент']);
        PropertyType::factory()->create(['name' => 'Чужд вид']); // друга фирма

        $this->runCommand()->assertSuccessful();
        $this->actingAs(User::query()->where('email', self::EMAIL)->sole());

        $this->assertSame(['Апартамент'], PropertyType::query()->pluck('name')->all());
    }

    private function runCommand(
        bool $companyAdmin = false,
        string $access = 'четене и запис',
        string $scope = 'всички имоти',
        bool $sensitive = false,
    ) {
        return $this->artisan('pms:create-staff')
            ->expectsQuestion('ЕИК на фирмата', '000000000')
            ->expectsQuestion('Имейл', self::EMAIL)
            ->expectsQuestion('Име', 'Измислен Служител')
            ->expectsQuestion('Парола (поне 12 знака, не се вижда)', self::PASSWORD)
            ->expectsQuestion('Повторете паролата', self::PASSWORD)
            ->expectsConfirmation('Администратор на фирмата ли е?', $companyAdmin ? 'yes' : 'no')
            ->expectsChoice('Права', $access, ['само четене', 'четене и запис'])
            ->expectsChoice('Обхват', $scope, ['всички имоти', 'само прикачените имоти'])
            ->expectsConfirmation('Да вижда ли ЕГН и IBAN?', $sensitive ? 'yes' : 'no');
    }
}
