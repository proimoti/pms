<?php

namespace Tests\Feature\Auth;

use App\Mail\InvitationMail;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyDomain;
use App\Models\EmailLog;
use App\Models\User;
use App\Support\Invitation;
use App\Support\UserAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Tests\TestCase;

/**
 * Покана по имейл и „Задай парола“ (план „Портал и служители“, стъпка 3,
 * 27.11.2026). Общи са за служителите и за портала. Връзката важи 72 часа,
 * само веднъж и само на адреса на фирмата. Писмото е от името на фирмата.
 * Паролата е поне 12 знака. Всичко е в журнала, без връзката и паролата.
 * Само измислени данни.
 */
class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'моята-нова-парола-1';

    private Company $company;

    private User $admin;

    private User $invited;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->company = Company::factory()->create(['name' => 'Тест Алфа ООД']);
        (new CompanyDomain)->forceFill(['company_id' => $this->company->id, 'host' => 'firma-a.test'])->save();
        $this->admin = User::factory()->for($this->company)->create(['is_company_admin' => true]);
        $this->invited = User::factory()->for($this->company)->tenant()->create(['name' => 'Иван Измислен', 'email' => 'ivan@example.test']);
    }

    /** Праща поканата и връща връзката от писмото (на адреса на фирмата). */
    private function invite(?User $user = null): string
    {
        $this->actingAs($this->admin);
        Invitation::send($user ?? $this->invited, $this->admin);
        $url = null;
        Mail::assertSent(InvitationMail::class, function (InvitationMail $mail) use (&$url) {
            $url = $mail->url;

            return true;
        });
        auth()->logout();

        return $url;
    }

    private function accept(string $url, string $password = self::NEW_PASSWORD): \Illuminate\Testing\TestResponse
    {
        return $this->post($url, ['password' => $password, 'password_confirmation' => $password]);
    }

    public function test_the_mail_goes_to_the_profile_from_the_company_with_a_link_on_its_address(): void
    {
        $url = $this->invite();

        $this->assertMatchesRegularExpression('#^http://firma-a\.test/invitation/[A-Za-z0-9]{64}$#', $url);
        Mail::assertSent(InvitationMail::class, function (InvitationMail $mail) {
            $mail->assertTo('ivan@example.test');
            $mail->assertFrom((string) config('mail.from.address'), 'Тест Алфа ООД');
            $mail->assertHasSubject('Покана за достъп – Тест Алфа ООД');
            $mail->assertSeeInHtml('Тест Алфа ООД');
            $mail->assertSeeInHtml('72 часа');
            $mail->assertDontSeeInHtml('PMS');

            return true;
        });
    }

    public function test_the_link_is_kept_only_as_a_fingerprint(): void
    {
        $url = $this->invite();
        $token = basename($url);

        $row = DB::table('users')->find($this->invited->id);
        $this->assertNotSame($token, $row->invitation_token);
        $this->assertSame(hash('sha256', $token), $row->invitation_token);
    }

    public function test_the_page_shows_the_company_and_the_email(): void
    {
        $url = $this->invite();

        $this->get($url)->assertOk()->assertSee('Тест Алфа ООД')->assertSee('ivan@example.test')
            ->assertSee(__('invitation.title'))->assertDontSee('PMS');
    }

    public function test_setting_the_password_lets_the_profile_log_in(): void
    {
        $url = $this->invite();

        $this->accept($url)->assertRedirect('/login')->assertSessionHas('status', __('invitation.done'));
        $this->assertGuest();

        $fresh = $this->invited->fresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $fresh->password));
        $this->assertNotNull($fresh->invitation_accepted_at);
        $this->assertNull($fresh->invitation_token);

        $this->post('http://firma-a.test/login', ['email' => 'ivan@example.test', 'password' => self::NEW_PASSWORD])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->invited);
    }

    public function test_a_short_or_unconfirmed_password_is_refused_and_the_link_stays_valid(): void
    {
        $url = $this->invite();

        $this->accept($url, '12345678901')->assertSessionHasErrors('password');
        $this->post($url, ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD.'x'])->assertSessionHasErrors('password');
        $this->assertNull($this->invited->fresh()->invitation_accepted_at);

        $this->accept($url)->assertRedirect('/login');
    }

    public function test_the_link_expires_after_72_hours(): void
    {
        $url = $this->invite();
        $password = $this->invited->fresh()->password;

        $this->travel(72)->hours();
        $this->travel(1)->minutes();

        $this->get($url)->assertStatus(410)->assertSee(__('invitation.expired'));
        $this->accept($url)->assertStatus(410);
        $this->assertSame($password, $this->invited->fresh()->password);
    }

    public function test_the_link_is_valid_just_before_72_hours(): void
    {
        $url = $this->invite();
        $this->travel(71)->hours();

        $this->accept($url)->assertRedirect('/login');
    }

    public function test_the_link_works_only_once(): void
    {
        $url = $this->invite();
        $this->accept($url)->assertRedirect('/login');

        $this->accept($url, 'друга-парола-12345')->assertNotFound();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $this->invited->fresh()->password));
    }

    public function test_a_new_invitation_replaces_the_old_link(): void
    {
        $old = $this->invite();
        Mail::fake();
        $new = $this->invite();

        $this->assertNotSame($old, $new);
        $this->accept($old)->assertNotFound();
        $this->accept($new)->assertRedirect('/login');
    }

    public function test_an_unknown_link_is_not_found(): void
    {
        $this->get('http://firma-a.test/invitation/'.str_repeat('a', 64))->assertNotFound();
    }

    public function test_the_link_of_company_a_does_not_work_on_the_address_of_b(): void
    {
        $other = Company::factory()->create();
        (new CompanyDomain)->forceFill(['company_id' => $other->id, 'host' => 'firma-b.test'])->save();
        $url = $this->invite();

        $onB = str_replace('firma-a.test', 'firma-b.test', $url);
        $this->get($onB)->assertNotFound();
        $this->accept($onB)->assertNotFound();
        $this->assertNull($this->invited->fresh()->invitation_accepted_at);
    }

    public function test_a_stopped_profile_is_not_invited_and_its_old_link_does_not_work(): void
    {
        $url = $this->invite();
        UserAccess::stop($this->invited, $this->admin);

        $this->accept($url)->assertNotFound();

        $this->expectException(LogicException::class);
        Invitation::send($this->invited->fresh(), $this->admin);
    }

    public function test_a_profile_that_already_set_a_password_is_not_invited_again(): void
    {
        $this->accept($this->invite());

        $this->expectException(LogicException::class);
        Invitation::send($this->invited->fresh(), $this->admin);
    }

    public function test_only_a_user_of_the_same_company_invites(): void
    {
        $stranger = User::factory()->for(Company::factory())->create(['is_company_admin' => true]);

        try {
            Invitation::send($this->invited, $stranger);
            $this->fail('Покана от друга фирма не трябва да минава.');
        } catch (LogicException) {
        }

        Mail::assertNothingSent();
        $this->assertNull($this->invited->fresh()->invitation_token);
    }

    public function test_the_app_admin_is_not_invited_this_way(): void
    {
        $this->expectException(LogicException::class);
        Invitation::send(User::factory()->appAdmin()->create(), $this->admin);
    }

    public function test_a_profile_without_an_email_is_not_invited(): void
    {
        $this->invited->forceFill(['email' => ''])->save();

        $this->expectException(LogicException::class);
        Invitation::send($this->invited->fresh(), $this->admin);
    }

    public function test_invitation_and_acceptance_are_in_the_audit_log_without_the_link_or_password(): void
    {
        $url = $this->invite();
        $this->accept($url);

        $rows = AuditLog::query()->withoutGlobalScopes()->where('auditable_type', 'users')->where('auditable_id', $this->invited->id)->orderBy('id')->get();
        $this->assertSame(['invited', 'invitation_accepted'], $rows->pluck('event')->all());
        $this->assertSame([$this->admin->id, $this->invited->id], $rows->pluck('user_id')->all());

        $json = $rows->toJson();
        $this->assertStringNotContainsString(basename($url), $json);
        $this->assertStringNotContainsString(hash('sha256', basename($url)), $json);
        $this->assertStringNotContainsString(self::NEW_PASSWORD, $json);
    }

    public function test_the_mail_is_in_the_email_log_without_the_link(): void
    {
        $url = $this->invite();

        $log = EmailLog::query()->withoutGlobalScopes()->sole();
        $this->assertSame('invitation', $log->kind);
        $this->assertSame('ivan@example.test', $log->intended_to);
        $this->assertSame($this->company->id, $log->company_id);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertStringNotContainsString(basename($url), json_encode($log->getAttributes()));
    }
}
