<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\CompanyDomain;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Фирмата по адреса (PROJECT.md, „Портал и служители“, 27.11.2026): приложението
 * е под бранда на всяка фирма и фирмата се определя по адреса (домейн или
 * поддомейн), на който се влиза. Имейлът е уникален в рамките на фирмата.
 * Входът и забравената парола работят само за фирмата на адреса.
 * Администраторът на приложението влиза на отделен адрес.
 *
 * Пример: фирма A на firma-a.test, фирма B на firma-b.test, един и същ
 * имейл и в двете, с различни пароли. Само измислени данни.
 */
class CompanyByAddressTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'ivan@example.test';

    private Company $a;

    private Company $b;

    private User $inA;

    private User $inB;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pms.admin_host' => 'admin.test']);
        [$this->a, $this->b] = [Company::factory()->create(), Company::factory()->create()];
        foreach ([[$this->a, 'firma-a.test'], [$this->b, 'firma-b.test']] as [$company, $host]) {
            (new CompanyDomain)->forceFill(['company_id' => $company->id, 'host' => $host])->save();
        }
        $this->inA = User::factory()->for($this->a)->tenant()->create(['email' => self::EMAIL, 'password' => 'парола-на-фирма-а']);
        $this->inB = User::factory()->for($this->b)->tenant()->create(['email' => self::EMAIL, 'password' => 'парола-на-фирма-б']);
    }

    private function login(string $host, string $password, string $email = self::EMAIL): \Illuminate\Testing\TestResponse
    {
        return $this->post("http://{$host}/login", ['email' => $email, 'password' => $password]);
    }

    public function test_the_same_email_may_exist_in_two_companies_but_once_in_a_company(): void
    {
        $this->assertSame(2, User::query()->where('email', self::EMAIL)->count());

        $this->expectException(QueryException::class);
        User::factory()->for($this->a)->create(['email' => self::EMAIL]);
    }

    public function test_login_on_the_address_of_a_finds_only_the_profile_in_a(): void
    {
        $this->login('firma-a.test', 'парола-на-фирма-а')->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->inA);
    }

    public function test_login_on_the_address_of_a_does_not_let_in_the_profile_of_b(): void
    {
        $this->login('firma-a.test', 'парола-на-фирма-б')->assertSessionHasErrors(['email' => 'Грешен имейл или парола.']);
        $this->assertGuest();

        $this->login('firma-b.test', 'парола-на-фирма-б')->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->inB);
    }

    public function test_login_on_an_unknown_address_is_refused(): void
    {
        $this->login('neizvesten.test', 'парола-на-фирма-а')->assertSessionHasErrors(['email' => 'Грешен имейл или парола.']);
        $this->assertGuest();
    }

    public function test_the_app_admin_logs_in_only_on_the_admin_address(): void
    {
        $admin = User::factory()->appAdmin()->create(['email' => 'admin@example.test', 'password' => 'парола-на-админа']);

        $this->login('firma-a.test', 'парола-на-админа', 'admin@example.test')->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->login('admin.test', 'парола-на-фирма-а')->assertSessionHasErrors('email'); // профил на фирма — не
        $this->assertGuest();

        $this->login('admin.test', 'парола-на-админа', 'admin@example.test');
        $this->assertAuthenticatedAs($admin);
    }

    /** Без PMS_ADMIN_HOST (засега на хостинга): служителите и клиентите работят нормално. */
    public function test_without_an_admin_address_staff_work_normally(): void
    {
        config(['pms.admin_host' => null]);
        $staff = User::factory()->for($this->a)->create(['email' => 'staff@example.test', 'password' => 'парола-на-служител']);
        User::factory()->appAdmin()->create(['email' => 'admin@example.test', 'password' => 'парола-на-админа']);

        $this->login('firma-a.test', 'парола-на-служител', 'staff@example.test')->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($staff);
        $this->get('http://firma-a.test/dashboard')->assertRedirect('/two-factor/setup'); // остава влязъл
        $this->assertAuthenticatedAs($staff);
        $this->post('http://firma-a.test/logout');

        // Администраторът на приложението няма къде да влезе, докато не му се даде адрес.
        $this->login('firma-a.test', 'парола-на-админа', 'admin@example.test')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_session_from_a_is_not_valid_on_the_address_of_b(): void
    {
        $this->actingAs($this->inA)->get('http://firma-b.test/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_forgot_password_sends_the_link_only_to_the_profile_of_the_address(): void
    {
        Notification::fake();

        $this->post('http://firma-a.test/forgot-password', ['email' => self::EMAIL])->assertSessionHasNoErrors();

        Notification::assertSentTo($this->inA, ResetPassword::class, function (ResetPassword $notification) {
            return str_starts_with($notification->toMail($this->inA)->actionUrl, 'http://firma-a.test/reset-password/');
        });
        Notification::assertNotSentTo($this->inB, ResetPassword::class);
    }

    public function test_reset_link_of_a_works_only_on_the_address_of_a(): void
    {
        Notification::fake();
        $this->post('http://firma-a.test/forgot-password', ['email' => self::EMAIL]);
        $token = null;
        Notification::assertSentTo($this->inA, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });
        $input = ['token' => $token, 'email' => self::EMAIL, 'password' => 'нова-парола-123', 'password_confirmation' => 'нова-парола-123'];

        // На адреса на B връзката не важи и не пипа профила в B.
        $this->post('http://firma-b.test/reset-password', $input)->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('парола-на-фирма-б', $this->inB->fresh()->password));

        $this->post('http://firma-a.test/reset-password', $input)->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('нова-парола-123', $this->inA->fresh()->password));
        $this->assertTrue(Hash::check('парола-на-фирма-б', $this->inB->fresh()->password));
    }

    public function test_forgot_password_on_b_does_not_change_the_link_of_a(): void
    {
        Notification::fake();
        $this->post('http://firma-a.test/forgot-password', ['email' => self::EMAIL]);
        $token = null;
        Notification::assertSentTo($this->inA, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        // Връзката, поискана в B, е отделна: не прави връзката на A невалидна.
        $this->post('http://firma-b.test/forgot-password', ['email' => self::EMAIL]);
        Notification::assertSentTo($this->inB, ResetPassword::class);

        $this->post('http://firma-a.test/reset-password', ['token' => $token, 'email' => self::EMAIL,
            'password' => 'нова-парола-123', 'password_confirmation' => 'нова-парола-123'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('нова-парола-123', $this->inA->fresh()->password));
    }
}
