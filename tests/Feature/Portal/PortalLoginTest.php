<?php

namespace Tests\Feature\Portal;

use App\Enums\Role;
use App\Mail\LoginCodeMail;
use App\Models\Company;
use App\Models\CompanyDomain;
use App\Models\EmailLog;
use App\Models\Owner;
use App\Models\PortalLink;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Вход в портала (план „Портал и служители“, 27.11.2026): след паролата —
 * код по имейл при ново устройство; устройството се помни 30 дни.
 * Приложението на телефона е по избор: който го е включил, ползва него.
 * После — /portal, с бранда на фирмата, без менюто на служителите.
 * Архивиран запис спира достъпа. Само измислени данни.
 */
class PortalLoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'парола-на-клиента-1';

    private Company $company;

    private Tenant $tenant;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->company = Company::factory()->create(['name' => 'Тест Алфа ООД']);
        $this->onAddressOf($this->company);
        $this->tenant = Tenant::factory()->create(['company_id' => $this->company->id, 'name' => 'Иван Измислен', 'email' => 'ivan@example.test']);
        $this->client = $this->makeClient('ivan@example.test', tenant: $this->tenant);
    }

    private function makeClient(string $email, ?Tenant $tenant = null, ?Owner $owner = null, ?Company $company = null): User
    {
        $company ??= $this->company;
        $user = User::factory()->for($company)->create(['role' => Role::Client, 'email' => $email, 'password' => self::PASSWORD,
            'access_level' => 'read', 'property_scope' => 'assigned', 'invitation_accepted_at' => now()]);
        foreach (array_filter(['tenant_id' => $tenant?->id, 'owner_id' => $owner?->id]) as $column => $id) {
            DB::table('portal_links')->insert(['company_id' => $company->id, 'user_id' => $user->id, $column => $id, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $user;
    }

    private function login(string $email = 'ivan@example.test', string $password = self::PASSWORD, string $host = 'localhost'): \Illuminate\Testing\TestResponse
    {
        return $this->post("http://{$host}/login", ['email' => $email, 'password' => $password]);
    }

    /** Кодът от последното писмо. */
    private function mailedCode(): string
    {
        $code = null;
        Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return $code;
    }

    private function loginWithCode(): \Illuminate\Testing\TestResponse
    {
        $this->login();

        return $this->post('/login/code', ['code' => $this->mailedCode()]);
    }

    // --- Код по имейл ---

    public function test_on_a_new_device_the_password_is_followed_by_an_email_code(): void
    {
        $this->login()->assertRedirect('/login/code');
        $this->assertGuest();

        Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) {
            $mail->assertTo('ivan@example.test');
            $mail->assertFrom((string) config('mail.from.address'), 'Тест Алфа ООД');
            $mail->assertSeeInHtml('Тест Алфа ООД');
            $mail->assertDontSeeInHtml('PMS');

            return preg_match('/^\d{6}$/', $mail->code) === 1;
        });
        $this->get('/login/code')->assertOk()->assertSee(__('portal.code.title'));

        $this->post('/login/code', ['code' => $this->mailedCode()])->assertRedirect('/portal');
        $this->assertAuthenticatedAs($this->client);
    }

    public function test_a_wrong_password_sends_no_code(): void
    {
        $this->login(password: 'грешна-парола-123')->assertSessionHasErrors(['email' => 'Грешен имейл или парола.']);
        Mail::assertNothingSent();
    }

    public function test_a_wrong_code_does_not_log_in_and_five_wrong_codes_end_the_attempt(): void
    {
        $this->login();
        $code = $this->mailedCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->post('/login/code', ['code' => $wrong])->assertSessionHasErrors('code');
        $this->assertGuest();
        for ($i = 2; $i <= 5; $i++) {
            $this->post('/login/code', ['code' => $wrong]);
        }

        $this->post('/login/code', ['code' => $code])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_the_code_expires_after_10_minutes(): void
    {
        $this->login();
        $code = $this->mailedCode();
        $this->travel(11)->minutes();

        $this->post('/login/code', ['code' => $code])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_the_code_page_without_a_password_step_goes_to_login(): void
    {
        $this->get('/login/code')->assertRedirect('/login');
        $this->post('/login/code', ['code' => '123456'])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_the_code_can_be_sent_again(): void
    {
        $this->login();
        $first = $this->mailedCode();
        Mail::fake();

        $this->post('/login/code/resend')->assertRedirect('/login/code');
        $second = $this->mailedCode();

        if ($first !== $second) {
            $this->post('/login/code', ['code' => $first])->assertSessionHasErrors('code'); // старият код не важи
        }
        $this->post('/login/code', ['code' => $second])->assertRedirect('/portal');
    }

    public function test_the_code_mail_is_in_the_email_log_without_the_code(): void
    {
        $this->login();
        $code = $this->mailedCode();

        $log = EmailLog::query()->withoutGlobalScopes()->where('kind', 'login_code')->sole();
        $this->assertSame('ivan@example.test', $log->intended_to);
        $this->assertSame($this->company->id, $log->company_id);
        $this->assertStringNotContainsString($code, json_encode($log->getAttributes()));
    }

    // --- Запомнено устройство ---

    public function test_the_device_is_remembered_for_30_days(): void
    {
        $response = $this->loginWithCode();
        $cookie = $response->getCookie('portal_device', false);
        $this->assertNotNull($cookie);
        $this->post('/logout');
        Mail::fake();

        $this->withUnencryptedCookie('portal_device', $cookie->getValue());
        $this->login()->assertRedirect('/portal');
        $this->assertAuthenticatedAs($this->client);
        Mail::assertNothingSent();

        $this->post('/logout');
        $this->travel(31)->days();
        $this->login()->assertRedirect('/login/code');
        $this->assertGuest();
    }

    public function test_the_device_is_kept_only_as_a_fingerprint(): void
    {
        $cookie = $this->loginWithCode()->getCookie('portal_device', false);

        $row = DB::table('trusted_devices')->where('user_id', $this->client->id)->sole();
        $this->assertSame(hash('sha256', $cookie->getValue()), $row->token);
    }

    public function test_a_remembered_device_of_another_profile_does_not_skip_the_code(): void
    {
        $other = $this->makeClient('maria@example.test', tenant: Tenant::factory()->create(['company_id' => $this->company->id]));
        $this->login('maria@example.test');
        $cookie = $this->post('/login/code', ['code' => $this->mailedCode()])->getCookie('portal_device', false);
        $this->post('/logout');
        Mail::fake();

        $this->withUnencryptedCookie('portal_device', $cookie->getValue());
        $this->login()->assertRedirect('/login/code');
        $this->assertGuest();
        $this->assertNotSame($other->id, $this->client->id);
    }

    public function test_stopping_access_forgets_the_remembered_devices(): void
    {
        $this->loginWithCode();
        $this->assertSame(1, DB::table('trusted_devices')->where('user_id', $this->client->id)->count());

        $staff = User::factory()->for($this->company)->create();
        \App\Support\UserAccess::stop($this->client->fresh(), $staff);

        $this->assertSame(0, DB::table('trusted_devices')->where('user_id', $this->client->id)->count());
    }

    // --- Приложението на телефона по избор ---

    public function test_a_client_with_the_phone_app_uses_the_app_not_the_email_code(): void
    {
        $this->client->forceFill([
            'two_factor_secret' => \Laravel\Fortify\Fortify::currentEncrypter()->encrypt(app(Google2FA::class)->generateSecretKey()),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->login()->assertRedirect('/two-factor-challenge');
        Mail::assertNothingSent();
    }

    // --- Достъп ---

    public function test_archiving_the_only_linked_record_stops_access(): void
    {
        $this->tenant->forceFill(['archived_at' => now()])->save();

        $this->login()->assertSessionHasErrors(['email' => __('auth.access_stopped')]);
        $this->assertGuest();
        Mail::assertNothingSent();
    }

    public function test_a_logged_in_client_is_logged_out_when_the_record_is_archived(): void
    {
        $this->actingAs($this->client)->get('/portal')->assertOk();
        DB::table('tenants')->where('id', $this->tenant->id)->update(['archived_at' => now()]); // архивира служител

        $this->get('/portal')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_access_stays_while_one_linked_record_is_active(): void
    {
        $owner = Owner::factory()->create(['company_id' => $this->company->id]);
        DB::table('portal_links')->insert(['company_id' => $this->company->id, 'user_id' => $this->client->id, 'owner_id' => $owner->id]);
        $this->tenant->forceFill(['archived_at' => now()])->save();

        $this->actingAs($this->client)->get('/portal')->assertOk();
    }

    public function test_a_client_without_links_has_no_access(): void
    {
        PortalLink::query()->withoutGlobalScopes()->delete();

        $this->login()->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_the_client_does_not_reach_staff_screens(): void
    {
        $this->actingAs($this->client);

        $this->get('/dashboard')->assertRedirect('/portal');
        $this->get('/tenants')->assertForbidden();
        $this->get("/tenants/{$this->tenant->id}")->assertNotFound(); // регистърът е скрит за клиента: не издава, че записът съществува
        $this->get('/staff')->assertForbidden();
        $this->get('/company')->assertForbidden();
    }

    public function test_staff_do_not_open_the_portal(): void
    {
        $staff = User::factory()->for($this->company)->withTwoFactor(app(Google2FA::class)->generateSecretKey())->create();

        $this->actingAs($staff)->get('/portal')->assertRedirect('/dashboard');
    }

    public function test_the_code_of_company_a_does_not_log_in_on_the_address_of_b(): void
    {
        $other = Company::factory()->create();
        (new CompanyDomain)->forceFill(['company_id' => $other->id, 'host' => 'firma-b.test'])->save();
        $this->login();

        $this->post('http://firma-b.test/login/code', ['code' => $this->mailedCode()])->assertRedirect('/login');
        $this->assertGuest();
    }

    // --- Вид ---

    public function test_the_portal_has_the_company_brand_and_no_staff_menu(): void
    {
        $owner = Owner::factory()->create(['company_id' => $this->company->id, 'name' => 'Иван Собственик']);
        DB::table('portal_links')->insert(['company_id' => $this->company->id, 'user_id' => $this->client->id, 'owner_id' => $owner->id]);

        $this->actingAs($this->client)->get('/portal')->assertOk()
            ->assertSee('Тест Алфа ООД')->assertSee('Иван Измислен')->assertSee('Иван Собственик')
            ->assertSee(__('portal.home.as_tenant'))->assertSee(__('portal.home.as_owner'))
            ->assertSee('name="viewport"', false)->assertSee(__('login.logout'))
            ->assertDontSee('class="side"', false)->assertDontSee('PMS');
    }

    public function test_the_portal_shows_only_the_own_records(): void
    {
        Tenant::factory()->create(['company_id' => $this->company->id, 'name' => 'Чужд Наемател']);

        $this->actingAs($this->client)->get('/portal')->assertOk()->assertDontSee('Чужд Наемател');
    }

    public function test_the_login_screen_shows_the_company_name_not_the_app_name(): void
    {
        $this->get('/login')->assertOk()->assertSee('Тест Алфа ООД')->assertDontSee('PMS');
        $this->get('/forgot-password')->assertOk()->assertSee('Тест Алфа ООД')->assertDontSee('PMS');
    }
}
