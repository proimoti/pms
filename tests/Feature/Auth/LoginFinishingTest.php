<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Довършване на входа: текстовете са в езиковите файлове, писмото за
 * забравена парола е на български с името PMS, приложението за втория
 * фактор показва „PMS“.
 */
class LoginFinishingTest extends TestCase
{
    use RefreshDatabase;

    // 1) Текстовете на екраните за вход

    public function test_login_screens_have_no_text_written_directly_in_the_page(): void
    {
        foreach (File::files(resource_path('views/auth')) as $file) {
            // Бележките за програмиста {{-- --}} не се показват и не се броят.
            $html = preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());

            $this->assertDoesNotMatchRegularExpression('/\p{Cyrillic}/u', $html,
                "{$file->getFilename()} има текст на кирилица направо в страницата; той трябва да е в lang/bg.");
        }
    }

    public function test_login_screens_show_bulgarian_text_not_raw_keys(): void
    {
        $this->get('/login')->assertOk()->assertSee('Вход')->assertSee('Забравена парола')->assertDontSee('login.');
        $this->get('/forgot-password')->assertOk()->assertSee('Изпрати връзка')->assertDontSee('login.');
        $this->get('/reset-password/izmislen-token')->assertOk()->assertSee('Смени паролата')->assertDontSee('login.');

        $staff = User::factory()->create();
        $this->actingAs($staff)->get('/two-factor/setup')->assertOk()
            ->assertSee('Започни настройката')->assertDontSee('login.');
    }

    // 2) Писмото за забравена парола

    private function resetMail(): array
    {
        Notification::fake();
        $user = User::factory()->tenant()->create(['email' => 'izmislen@example.test']);
        $this->post('/forgot-password', ['email' => $user->email]);

        $mail = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user, &$mail) {
            $mail = $n->toMail($user);

            return true;
        });

        return [$mail, (string) $mail->render()];
    }

    public function test_reset_mail_subject_names_the_application(): void
    {
        [$mail] = $this->resetMail();

        $this->assertSame('Смяна на паролата – PMS', $mail->subject);
    }

    public function test_reset_mail_has_no_trace_of_laravel(): void
    {
        [$mail, $html] = $this->resetMail();

        $this->assertStringNotContainsStringIgnoringCase('laravel', $html);
        $this->assertStringNotContainsStringIgnoringCase('laravel', $mail->subject);
        $this->assertStringContainsString('PMS', $html);
    }

    public function test_reset_mail_is_in_bulgarian_and_has_the_link(): void
    {
        [$mail, $html] = $this->resetMail();

        $this->assertStringContainsString('Здравейте', $html);
        $this->assertStringContainsString('Задай нова парола', $html);
        $this->assertStringContainsString('/reset-password/', $html);
        $this->assertStringNotContainsString('Reset', $html);
    }

    public function test_reset_mail_sender_name_is_pms(): void
    {
        [$mail] = $this->resetMail();

        $this->assertSame('PMS', $mail->from[1] ?? null);
    }

    public function test_reset_mail_is_actually_sent_without_laravel(): void
    {
        $user = User::factory()->tenant()->create(['email' => 'izmislen@example.test']);
        $this->post('/forgot-password', ['email' => $user->email]);

        $sent = collect(Mail::mailer()->getSymfonyTransport()->messages());
        $this->assertCount(1, $sent);
        $message = $sent->first()->getOriginalMessage();

        $this->assertSame('PMS', $message->getFrom()[0]->getName());
        $this->assertStringNotContainsStringIgnoringCase('laravel', $message->getHtmlBody());
        $this->assertStringNotContainsStringIgnoringCase('laravel', $message->getTextBody());
    }

    // 3) Името в приложението за втория фактор

    public function test_authenticator_app_shows_pms(): void
    {
        $user = User::factory()->withTwoFactor('JBSWY3DPEHPK3PXP')->create(['email' => 'izmislen@example.test']);

        $url = $user->twoFactorQrCodeUrl();

        $this->assertStringStartsWith('otpauth://totp/PMS:', $url);
        $this->assertStringContainsString('issuer=PMS', $url);
        $this->assertStringNotContainsStringIgnoringCase('laravel', $url);
    }

    public function test_application_name_does_not_depend_on_env(): void
    {
        $this->assertSame('PMS', config('app.name'));
    }
}
