<?php

namespace Tests\Feature\Mail;

use App\Mail\MailGuard;
use App\Models\Company;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use LogicException;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Пазачът на пощата (PROJECT.md, „PDF и имейл на известията“, 24.11.2026).
 * Всяко писмо на приложението минава през него:
 *
 * - MAIL_REDIRECT_TO попълнено → всичко отива само на този адрес, темата
 *   започва с „[ТЕСТ]“, а в писмото пише за кого и на кой адрес е било;
 * - празно и не production → писмото само се записва в лога, нищо не излиза;
 * - production и празно → истинско изпращане.
 *
 * И email_log: само добавяне, без съдържанието, по фирма. Само измислени адреси.
 */
class MailGuardTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 'ivan@example.test';

    private const REDIRECT = 'office-test@example.test';

    private function guard(string $env, ?string $redirect): void
    {
        $this->app['env'] = $env;
        config(['mail.redirect_to' => $redirect, 'mail.default' => 'array']);
    }

    private function send(): void
    {
        Mail::send(new class extends Mailable
        {
            public function envelope(): Envelope
            {
                return new Envelope(subject: 'Известие № 26', to: [new Address('ivan@example.test', 'Иван Измислен')],
                    cc: [new Address('cc@example.test')], bcc: [new Address('bcc@example.test')]);
            }

            public function content(): Content
            {
                return new Content(htmlString: '<p>Сума за плащане: 500,00 €</p>');
            }
        });
    }

    /** @return list<\Symfony\Component\Mime\Email> */
    private function sent(): array
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->map(fn ($m) => $m->getOriginalMessage())->all();
    }

    private function addresses(array $list): array
    {
        return array_map(fn ($a) => $a->getAddress(), $list);
    }

    public function test_redirect_sends_everything_only_to_the_redirect_address_with_a_test_note(): void
    {
        foreach (['local', 'production'] as $env) {
            $this->guard($env, self::REDIRECT);
            Mail::mailer('array')->getSymfonyTransport()->flush();
            $this->send();

            [$email] = $this->sent();
            $this->assertSame([self::REDIRECT], $this->addresses($email->getTo()), $env);
            $this->assertSame([], $email->getCc(), $env);
            $this->assertSame([], $email->getBcc(), $env);
            $this->assertStringStartsWith('[ТЕСТ] ', $email->getSubject(), $env);
            $this->assertStringContainsString('Иван Измислен', $email->getHtmlBody());
            $this->assertStringContainsString(self::TENANT, $email->getHtmlBody());
            $this->assertStringContainsString('Сума за плащане: 500,00 €', $email->getHtmlBody());
        }
    }

    public function test_without_redirect_outside_production_mail_only_goes_to_the_log(): void
    {
        foreach (['local', 'testing', 'staging'] as $env) {
            $this->guard($env, null);
            Mail::mailer('array')->getSymfonyTransport()->flush();
            Log::spy();

            $this->send();

            $this->assertSame([], $this->sent(), $env);
            Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => str_contains($message, 'Известие № 26')
                && in_array(self::TENANT, $context['to'] ?? [], true));
        }
    }

    public function test_empty_redirect_counts_as_missing(): void
    {
        $this->guard('local', '   ');
        $this->send();
        $this->assertSame([], $this->sent());
    }

    public function test_real_sending_only_in_production_without_redirect(): void
    {
        $this->guard('production', null);
        $this->send();

        [$email] = $this->sent();
        $this->assertSame([self::TENANT], $this->addresses($email->getTo()));
        $this->assertSame(['cc@example.test'], $this->addresses($email->getCc()));
        $this->assertSame('Известие № 26', $email->getSubject());
        $this->assertStringNotContainsString('ТЕСТ', $email->getHtmlBody());
    }

    public function test_the_guard_says_where_a_message_will_go(): void
    {
        $this->guard('production', null);
        $this->assertSame(['mode' => MailGuard::REAL, 'to' => self::TENANT], app(MailGuard::class)->deliveryFor(self::TENANT));
        $this->guard('production', self::REDIRECT);
        $this->assertSame(['mode' => MailGuard::REDIRECT, 'to' => self::REDIRECT], app(MailGuard::class)->deliveryFor(self::TENANT));
        $this->guard('local', null);
        $this->assertSame(['mode' => MailGuard::LOG, 'to' => null], app(MailGuard::class)->deliveryFor(self::TENANT));
    }

    public function test_password_reset_mail_also_goes_through_the_guard(): void
    {
        // Средата е „testing“ (също не е production), за да мине формата без CSRF ключ.
        $this->guard('testing', null);
        $this->post('/forgot-password', ['email' => User::factory()->tenant()->create(['email' => 'first@example.test'])->email]);
        $this->assertSame([], $this->sent());

        $this->guard('testing', self::REDIRECT);
        $this->post('/forgot-password', ['email' => User::factory()->tenant()->create(['email' => 'second@example.test'])->email]);
        [$email] = $this->sent();
        $this->assertSame([self::REDIRECT], $this->addresses($email->getTo()));
        $this->assertStringContainsString('second@example.test', $email->getHtmlBody());
    }

    // email_log

    private function user(Company $company): User
    {
        return User::factory()->withTwoFactor(app(Google2FA::class)->generateSecretKey())->create(['company_id' => $company->id]);
    }

    public function test_email_log_is_append_only_without_content_and_per_company(): void
    {
        [$a, $b] = [Company::factory()->create(), Company::factory()->create()];
        $this->actingAs($this->user($a));
        $entry = EmailLog::record(['kind' => 'statement', 'intended_to' => self::TENANT, 'delivered_to' => self::REDIRECT,
            'mode' => MailGuard::REDIRECT, 'status' => EmailLog::SENT]);

        $this->assertSame($a->id, $entry->company_id);
        $this->assertSame(auth()->id(), $entry->user_id);
        $this->assertNotNull($entry->sent_at);
        $this->assertFalse(\Schema::hasColumn('email_log', 'body'));
        $this->assertFalse(\Schema::hasColumn('email_log', 'subject'));

        try {
            $entry->update(['status' => EmailLog::FAILED]);
            $this->fail('Записът в email_log не трябва да се променя.');
        } catch (LogicException) {
        }
        try {
            $entry->delete();
            $this->fail('Записът в email_log не трябва да се трие.');
        } catch (LogicException) {
        }

        auth()->logout();
        $this->actingAs($this->user($b));
        $this->assertSame(0, EmailLog::query()->count());
    }
}
