<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Команда на сървъра, с която доставчикът нулира втория фактор на потребител,
 * изгубил телефона и резервните си кодове (PROJECT.md, т. 2).
 */
class ResetTwoFactorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_resets_the_second_factor_after_confirmation(): void
    {
        $user = User::factory()->withTwoFactor($this->secret())->create();

        $this->artisan('pms:reset-two-factor', ['email' => $user->email])
            ->expectsConfirmation("Да се нулира ли вторият фактор на {$user->email}?", 'yes')
            ->assertSuccessful();

        $fresh = $user->fresh();
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_recovery_codes);
        $this->assertNull($fresh->two_factor_confirmed_at);
    }

    public function test_nothing_changes_without_confirmation(): void
    {
        $user = User::factory()->withTwoFactor($this->secret())->create();

        $this->artisan('pms:reset-two-factor', ['email' => $user->email])
            ->expectsConfirmation("Да се нулира ли вторият фактор на {$user->email}?", 'no')
            ->assertFailed();

        $this->assertNotNull($user->fresh()->two_factor_secret);
    }

    public function test_unknown_email_is_an_error(): void
    {
        $this->artisan('pms:reset-two-factor', ['email' => 'nobody@example.test'])
            ->expectsOutputToContain('Няма потребител с този имейл')
            ->assertFailed();
    }

    /** Имейлът е уникален в рамките на фирмата (27.11.2026): при два профила се казва ЕИК. */
    public function test_email_in_two_companies_needs_the_company(): void
    {
        $a = User::factory()->withTwoFactor($this->secret())->create(['email' => 'same@example.test']);
        $b = User::factory()->withTwoFactor($this->secret())->create(['email' => 'same@example.test']);

        $this->artisan('pms:reset-two-factor', ['email' => 'same@example.test', '--force' => true])
            ->expectsOutputToContain('--company')
            ->assertFailed();
        $this->assertNotNull($a->fresh()->two_factor_secret);
        $this->assertNotNull($b->fresh()->two_factor_secret);

        $this->artisan('pms:reset-two-factor', ['email' => 'same@example.test', '--company' => $b->company->eik, '--force' => true])
            ->assertSuccessful();
        $this->assertNotNull($a->fresh()->two_factor_secret);
        $this->assertNull($b->fresh()->two_factor_secret);
    }

    public function test_after_reset_the_user_must_set_up_the_second_factor_again(): void
    {
        $user = User::factory()->withTwoFactor($this->secret())->create();

        $this->artisan('pms:reset-two-factor', ['email' => $user->email, '--force' => true])
            ->assertSuccessful();

        $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect('/two-factor/setup');
    }

    private function secret(): string
    {
        return app(Google2FA::class)->generateSecretKey();
    }
}
