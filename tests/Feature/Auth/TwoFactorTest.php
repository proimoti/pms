<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Втори фактор (PROJECT.md, т. 2): задължителен за администратора на
 * приложението и за служителите, с приложение на телефона.
 */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'измислена-парола-123';

    private const RECOVERY_CODE = 'izmislen-kod-0001';

    public function test_staff_without_second_factor_is_sent_to_set_it_up(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertRedirect('/two-factor/setup');
    }

    public function test_app_admin_without_second_factor_is_sent_to_set_it_up(): void
    {
        $this->actingAs(User::factory()->appAdmin()->create())
            ->get('/dashboard')
            ->assertRedirect('/two-factor/setup');
    }

    public function test_setup_page_is_shown_in_bulgarian(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/two-factor/setup')
            ->assertOk()
            ->assertSee('Втори фактор');
    }

    public function test_owner_and_tenant_are_not_forced_to_set_it_up(): void
    {
        $this->actingAs(User::factory()->owner()->create())->get('/dashboard')->assertOk();
        $this->actingAs(User::factory()->tenant()->create())->get('/dashboard')->assertOk();
    }

    public function test_staff_sets_up_the_second_factor_with_a_code_from_the_phone(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

        $this->post('/user/two-factor-authentication')->assertSessionHasNoErrors();

        $secret = decrypt($user->fresh()->two_factor_secret);
        $this->post('/user/confirmed-two-factor-authentication', [
            'code' => $this->codeFor($secret),
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
        $this->get('/dashboard')->assertOk();
    }

    public function test_after_password_staff_with_second_factor_must_enter_the_code(): void
    {
        $user = User::factory()->withTwoFactor($this->secret())->create(['password' => self::PASSWORD]);

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect('/two-factor-challenge');

        $this->assertGuest();
    }

    public function test_correct_code_from_the_phone_logs_in(): void
    {
        $secret = $this->secret();
        $user = User::factory()->withTwoFactor($secret)->create(['password' => self::PASSWORD]);

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);
        $this->post('/two-factor-challenge', ['code' => $this->codeFor($secret)])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_code_does_not_log_in(): void
    {
        $user = User::factory()->withTwoFactor($this->secret())->create(['password' => self::PASSWORD]);

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);
        $this->post('/two-factor-challenge', ['code' => '000000']);

        $this->assertGuest();
    }

    public function test_recovery_code_logs_in_only_once(): void
    {
        $user = User::factory()
            ->withTwoFactor($this->secret(), [self::RECOVERY_CODE])
            ->create(['password' => self::PASSWORD]);

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);
        $this->post('/two-factor-challenge', ['recovery_code' => self::RECOVERY_CODE]);
        $this->assertAuthenticatedAs($user);

        $this->post('/logout');

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);
        $this->post('/two-factor-challenge', ['recovery_code' => self::RECOVERY_CODE]);
        $this->assertGuest();
    }

    public function test_staff_cannot_switch_off_the_second_factor(): void
    {
        $user = User::factory()->withTwoFactor($this->secret())->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete('/user/two-factor-authentication')
            ->assertForbidden();

        $this->assertNotNull($user->fresh()->two_factor_secret);
    }

    private function secret(): string
    {
        return app(Google2FA::class)->generateSecretKey();
    }

    private function codeFor(string $secret): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }
}
