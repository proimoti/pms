<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyDomain;
use App\Models\User;
use App\Support\UserAccess;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Tests\TestCase;

/**
 * Спиране на достъпа (план „Портал и служители“, стъпка 2, 27.11.2026).
 * Спреният профил не влиза, не получава връзка за нова парола и се изкарва
 * при следващата заявка. Профилът и данните остават; достъпът се връща.
 * Всяко спиране и връщане е в журнала (кой и кога). Само измислени данни.
 */
class StoppedAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'измислена-парола-123';

    private Company $company;

    private User $admin;

    private User $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->onAddressOf($this->company);
        $this->admin = User::factory()->for($this->company)->create(['is_company_admin' => true]);
        $this->tenant = User::factory()->for($this->company)->tenant()->create(['email' => 'ivan@example.test', 'password' => self::PASSWORD]);
    }

    private function login(string $password = self::PASSWORD): \Illuminate\Testing\TestResponse
    {
        return $this->post('/login', ['email' => 'ivan@example.test', 'password' => $password]);
    }

    public function test_a_stopped_profile_cannot_log_in(): void
    {
        UserAccess::stop($this->tenant, $this->admin);

        $this->login()->assertSessionHasErrors(['email' => __('auth.access_stopped')]);
        $this->assertGuest();
    }

    public function test_a_wrong_password_still_gets_the_common_message(): void
    {
        UserAccess::stop($this->tenant, $this->admin);

        // Че достъпът е спрян, се казва само след вярна парола.
        $this->login('грешна-парола-123')->assertSessionHasErrors(['email' => 'Грешен имейл или парола.']);
    }

    public function test_a_logged_in_profile_is_logged_out_on_the_next_request(): void
    {
        $this->actingAs($this->tenant)->get('/dashboard')->assertOk();

        UserAccess::stop($this->tenant->fresh(), $this->admin);

        $this->get('/dashboard')->assertRedirect('/login')->assertSessionHasErrors(['email' => __('auth.access_stopped')]);
        $this->assertGuest();
    }

    public function test_a_stopped_profile_gets_no_reset_link_and_an_old_link_stops_working(): void
    {
        Notification::fake();
        $this->post('/forgot-password', ['email' => 'ivan@example.test']);
        $token = null;
        Notification::assertSentTo($this->tenant, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        UserAccess::stop($this->tenant, $this->admin);

        Notification::fake();
        $this->post('/forgot-password', ['email' => 'ivan@example.test'])->assertSessionHasNoErrors(); // същият общ отговор
        Notification::assertNothingSent();

        $this->post('/reset-password', ['token' => $token, 'email' => 'ivan@example.test',
            'password' => 'нова-парола-123', 'password_confirmation' => 'нова-парола-123'])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check(self::PASSWORD, $this->tenant->fresh()->password));
    }

    public function test_restored_access_lets_the_profile_in_again_with_the_same_data(): void
    {
        UserAccess::stop($this->tenant, $this->admin);
        UserAccess::restore($this->tenant, $this->admin);

        $this->assertNull($this->tenant->fresh()->disabled_at);
        $this->login()->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->tenant);
    }

    public function test_stop_and_restore_are_in_the_audit_log_with_who_and_when(): void
    {
        $this->travelTo('2026-11-27 10:00:00');
        UserAccess::stop($this->tenant, $this->admin);
        $this->travelTo('2026-11-27 11:00:00');
        UserAccess::restore($this->tenant, $this->admin);

        $rows = AuditLog::query()->withoutGlobalScopes()->where('auditable_type', 'users')
            ->where('auditable_id', $this->tenant->id)->orderBy('id')->get();
        $this->assertSame(['access_stopped', 'access_restored'], $rows->pluck('event')->all());
        $this->assertSame([$this->admin->id, $this->admin->id], $rows->pluck('user_id')->all());
        $this->assertSame([$this->company->id, $this->company->id], $rows->pluck('company_id')->all());
        $this->assertSame('2026-11-27 10:00:00', $rows[0]->created_at->format('Y-m-d H:i:s'));
        $this->assertSame(['disabled_at'], array_keys($rows[0]->changes)); // нищо друго за профила
    }

    public function test_stopping_twice_or_restoring_an_active_profile_changes_nothing(): void
    {
        UserAccess::stop($this->tenant, $this->admin);
        $first = $this->tenant->fresh()->disabled_at;
        $this->travel(1)->hours();
        UserAccess::stop($this->tenant->fresh(), $this->admin);
        UserAccess::restore($this->admin, $this->admin); // активен — нищо

        $this->assertEquals($first, $this->tenant->fresh()->disabled_at);
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('auditable_type', 'users')->count());
    }

    public function test_the_same_email_in_another_company_is_not_stopped(): void
    {
        $other = Company::factory()->create();
        (new CompanyDomain)->forceFill(['company_id' => $other->id, 'host' => 'firma-b.test'])->save();
        $inB = User::factory()->for($other)->tenant()->create(['email' => 'ivan@example.test', 'password' => self::PASSWORD]);

        UserAccess::stop($this->tenant, $this->admin);

        $this->assertNull($inB->fresh()->disabled_at);
        $this->post('http://firma-b.test/login', ['email' => 'ivan@example.test', 'password' => self::PASSWORD])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($inB);
    }

    public function test_a_user_of_another_company_cannot_stop_the_profile(): void
    {
        $stranger = User::factory()->for(Company::factory())->create(['is_company_admin' => true]);

        try {
            UserAccess::stop($this->tenant, $stranger);
            $this->fail('Спиране от друга фирма не трябва да минава.');
        } catch (LogicException) {
        }

        $this->assertNull($this->tenant->fresh()->disabled_at);
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->where('auditable_type', 'users')->count());
    }

    public function test_the_app_admin_is_not_stopped_this_way(): void
    {
        $appAdmin = User::factory()->appAdmin()->create();

        $this->expectException(LogicException::class);
        UserAccess::stop($appAdmin, $this->admin);
    }

    public function test_new_profiles_are_not_stopped(): void
    {
        $this->assertNull($this->tenant->fresh()->disabled_at);
        $this->assertFalse($this->tenant->fresh()->isStopped());
    }
}
