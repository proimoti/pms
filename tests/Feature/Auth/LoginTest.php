<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Вход и изход (PROJECT.md, т. 2 „Вход“).
 *
 * Тук влизат собственици и наематели, защото при тях вторият фактор
 * не е задължителен. Вторият фактор се проверява в TwoFactorTest.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'измислена-парола-123';

    public function test_login_page_is_shown_in_bulgarian(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Вход')
            ->assertSee('Забравена парола');
    }

    public function test_user_logs_in_with_the_correct_password(): void
    {
        $user = $this->tenant();

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_does_not_log_in(): void
    {
        $user = $this->tenant();

        $this->post('/login', ['email' => $user->email, 'password' => 'грешна-парола-123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_wrong_login_does_not_reveal_whether_the_email_exists(): void
    {
        $user = $this->tenant();

        $this->post('/login', ['email' => $user->email, 'password' => 'грешна-парола-123'])
            ->assertSessionHasErrors(['email' => 'Грешен имейл или парола.']);

        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'грешна-парола-123'])
            ->assertSessionHasErrors(['email' => 'Грешен имейл или парола.']);
    }

    public function test_sixth_attempt_within_a_minute_is_blocked_even_with_the_correct_password(): void
    {
        $user = $this->tenant();

        for ($i = 1; $i <= 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'грешна-парола-123']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertStatus(429);

        $this->assertGuest();
    }

    public function test_user_logs_out(): void
    {
        $this->actingAs($this->tenant())
            ->post('/logout')
            ->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_registration_does_not_exist(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    public function test_guest_is_sent_to_the_login_page(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_after_login_company_a_user_sees_only_company_a(): void
    {
        $alpha = Company::factory()->create(['name' => 'Тест Алфа ООД']);
        Company::factory()->create(['name' => 'Тест Бета ООД']);
        $user = $this->tenant($alpha);

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Тест Алфа ООД')
            ->assertDontSee('Тест Бета ООД');

        $this->assertSame([$alpha->id], Company::query()->pluck('id')->all());
    }

    public function test_user_of_an_inactive_company_cannot_log_in(): void
    {
        $user = $this->tenant(Company::factory()->inactive()->create());

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_logged_in_user_is_logged_out_on_the_next_request_when_the_company_becomes_inactive(): void
    {
        $company = Company::factory()->create();
        $user = $this->tenant($company);

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $company->forceFill(['is_active' => false])->save();

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    private function tenant(?Company $company = null): User
    {
        return User::factory()
            ->for($company ?? Company::factory())
            ->tenant()
            ->create(['password' => self::PASSWORD]);
    }
}
