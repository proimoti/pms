<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Команда на сървъра, която създава администратор на приложението.
 * Паролата се въвежда скрито и никога не е аргумент на командата.
 */
class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'admin@example.test';

    private const PASSWORD = 'измислена-парола-123';

    public function test_command_creates_an_app_admin_without_a_company(): void
    {
        $this->runCommand(self::EMAIL, self::PASSWORD, self::PASSWORD)->assertSuccessful();

        $admin = User::query()->where('email', self::EMAIL)->firstOrFail();
        $this->assertSame(Role::AppAdmin, $admin->role);
        $this->assertNull($admin->company_id);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
        $this->assertNull($admin->two_factor_secret);
    }

    public function test_password_is_never_printed(): void
    {
        $this->runCommand(self::EMAIL, self::PASSWORD, self::PASSWORD)
            ->doesntExpectOutputToContain(self::PASSWORD)
            ->assertSuccessful();
    }

    public function test_command_has_no_password_argument_or_option(): void
    {
        $definition = Artisan::all()['pms:create-admin']->getDefinition();

        $this->assertSame(0, $definition->getArgumentCount(), 'Командата не приема аргументи.');
        $this->assertFalse($definition->hasOption('password'));
    }

    public function test_password_shorter_than_12_characters_is_rejected(): void
    {
        $this->runCommand(self::EMAIL, '12345678901', '12345678901')->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
    }

    public function test_passwords_that_do_not_match_are_rejected(): void
    {
        $this->runCommand(self::EMAIL, self::PASSWORD, self::PASSWORD.'x')->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->artisan('pms:create-admin')
            ->expectsQuestion('Имейл', 'not-an-email')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    /** Имейлът е уникален в рамките на фирмата (27.11.2026); при администраторите — сред тях. */
    public function test_existing_admin_email_is_rejected(): void
    {
        User::factory()->appAdmin()->create(['email' => self::EMAIL]);

        $this->artisan('pms:create-admin')
            ->expectsConfirmation('Вече има администратор на приложението. Да се създаде ли още един?', 'yes')
            ->expectsQuestion('Имейл', self::EMAIL)
            ->assertFailed();

        $this->assertSame(1, User::query()->count());
    }

    public function test_when_an_admin_already_exists_it_asks_before_creating_another(): void
    {
        User::factory()->appAdmin()->create();

        $this->artisan('pms:create-admin')
            ->expectsConfirmation('Вече има администратор на приложението. Да се създаде ли още един?', 'no')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
    }

    public function test_new_admin_must_set_up_the_second_factor_at_first_login(): void
    {
        $this->runCommand(self::EMAIL, self::PASSWORD, self::PASSWORD)->assertSuccessful();

        $this->onAddressOf(null); // адресът на тестовете е на администратора (27.11.2026)
        $this->post('/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]);

        $this->get('/dashboard')->assertRedirect('/two-factor/setup');
    }

    private function runCommand(string $email, string $password, string $confirmation)
    {
        return $this->artisan('pms:create-admin')
            ->expectsQuestion('Имейл', $email)
            ->expectsQuestion('Име', 'Администратор')
            ->expectsQuestion('Парола (поне 12 знака, не се вижда)', $password)
            ->expectsQuestion('Повторете паролата', $confirmation);
    }
}
