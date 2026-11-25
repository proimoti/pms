<?php

namespace Tests\Feature\Charges;

use App\Mail\MailGuard;
use App\Models\BankAccount;
use App\Models\EmailLog;
use App\Models\Property;
use App\Models\Statement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserProperty;
use App\Services\Charges\Issuer;
use App\Services\Charges\MonthWorksheet;
use App\Services\LeaseContractService;
use App\Services\OwnerContractService;
use App\Services\Statements\StatementSender;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tests\Feature\Screens\ScreenTestCase;
use Tests\Support\DossierScenario;

/**
 * Изпращане на известията (PROJECT.md, „PDF и имейл на известията“, 24.11.2026):
 * поотделно и „Изпрати всички за месеца“ (само неизпратените); повторно —
 * само поотделно, с потвърждение; изпраща само управляващият начисленията;
 * известието е в текста на писмото и като PDF; статус в списъка; запис в
 * email_log без съдържанието. Всичко минава през пазача на пощата: тук писмата
 * отиват на адреса за проба (MAIL_REDIRECT_TO), както на хостинга.
 *
 * Пример: януари 2027 е издаден за Борис (с имейл) и за наемател без имейл.
 * „Днес“ е 10.01.2027. Само измислени данни.
 */
class StatementSendTest extends ScreenTestCase
{
    use DossierScenario;

    private const REDIRECT = 'office-test@example.test';

    private User $user;

    private Statement $statement;

    private Statement $noEmail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2027-01-10 12:00:00'));
        $this->user = $this->staff(['is_company_admin' => true]);
        $this->buildDossierScenario($this->user);
        $this->company->update(['name' => 'Тест Алфа ООД', 'email' => 'office@example.test']);
        BankAccount::query()->create(['bank_name' => 'Измислена банка', 'iban' => 'BG80BNBG96611020345678'])->forceFill(['for_statements' => true])->save();

        // Наемател без имейл.
        $property = Property::factory()->create(['company_id' => $this->company->id, 'number' => 140]);
        app(OwnerContractService::class)->create(['type' => \App\Enums\OwnerContractType::Own, 'signed_on' => '2026-01-01', 'lines' => [['property_id' => $property->id]]]);
        auth()->logout();
        $tenant = Tenant::factory()->create(['company_id' => $this->company->id, 'name' => 'Без Имейл', 'email' => null]);
        $this->actingAs($this->user);
        $lease = app(LeaseContractService::class)->create(['tenant_id' => $tenant->id, 'signed_on' => '2026-12-01', 'lines' => [['property_id' => $property->id, 'rent' => '300']]]);

        app(MonthWorksheet::class)->generate('2027-01');
        app(Issuer::class)->issueMonth('2027-01');
        $this->statement = Statement::query()->where('lease_contract_id', $this->lease->id)->whereDate('month', '2027-01-01')->sole();
        $this->noEmail = Statement::query()->where('lease_contract_id', $lease->id)->sole();

        config(['mail.redirect_to' => self::REDIRECT, 'mail.default' => 'array']);
    }

    /** @return list<Email> */
    private function sent(): array
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->map(fn ($m) => $m->getOriginalMessage())->values()->all();
    }

    private function logs(Statement $statement): array
    {
        return EmailLog::query()->where('statement_id', $statement->id)->orderBy('id')->get()->all();
    }

    public function test_send_one_statement_with_the_document_in_the_text_and_as_pdf(): void
    {
        $this->post("/statements/{$this->statement->id}/send")->assertRedirect("/statements/{$this->statement->id}")
            ->assertSessionHas('status', __('statements.send.sent_one', ['number' => $this->statement->number]));

        [$email] = $this->sent();
        $this->assertSame([self::REDIRECT], array_map(fn ($a) => $a->getAddress(), $email->getTo()));
        $this->assertSame('[ТЕСТ → Борис Измислен] '.__('statements.send.subject', ['number' => $this->statement->number, 'month' => 'Януари 2027']), $email->getSubject());
        $html = $email->getHtmlBody();
        foreach (['Борис Измислен', __('statements.pdf.due_on').': 15.01.2027', '500,00', 'BG80 BNBG 9661 1020 3456 78'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringNotContainsString('boris@example.test', $html);
        $this->assertStringNotContainsString('ТЕСТ', $html);
        [$attachment] = $email->getAttachments();
        $this->assertSame('izvestie-'.$this->statement->number.'.pdf', $attachment->getFilename());
        $this->assertSame('application/pdf', $attachment->getContentType());
        $this->assertStringStartsWith('%PDF-', $attachment->getBody());

        [$log] = $this->logs($this->statement);
        $this->assertSame(['statement', 'boris@example.test', self::REDIRECT, MailGuard::REDIRECT, EmailLog::SENT, $this->user->id, $this->boris->id],
            [$log->kind, $log->intended_to, $log->delivered_to, $log->mode, $log->status, $log->user_id, $log->tenant_id]);
    }

    public function test_real_sending_in_production_goes_to_the_tenant_with_reply_to_the_company(): void
    {
        $this->app['env'] = 'production';
        config(['mail.redirect_to' => null]);

        $log = app(StatementSender::class)->send($this->statement);

        [$email] = $this->sent();
        $this->assertSame(['boris@example.test'], array_map(fn ($a) => $a->getAddress(), $email->getTo()));
        $this->assertSame('Борис Измислен', $email->getTo()[0]->getName());
        $this->assertSame(['office@example.test'], array_map(fn ($a) => $a->getAddress(), $email->getReplyTo()));
        $this->assertSame('Тест Алфа ООД', $email->getFrom()[0]->getName());
        $this->assertSame([MailGuard::REAL, 'boris@example.test', EmailLog::SENT], [$log->mode, $log->delivered_to, $log->status]);
    }

    public function test_without_redirect_outside_production_it_only_goes_to_the_log(): void
    {
        config(['mail.redirect_to' => null]);
        $this->post("/statements/{$this->statement->id}/send");

        $this->assertSame([], $this->sent());
        [$log] = $this->logs($this->statement);
        $this->assertSame([MailGuard::LOG, null, EmailLog::LOGGED], [$log->mode, $log->delivered_to, $log->status]);
    }

    public function test_tenant_without_email_is_skipped_and_recorded(): void
    {
        $this->post("/statements/{$this->noEmail->id}/send")->assertSessionHas('warning', __('statements.send.no_email_one', ['number' => $this->noEmail->number]));

        $this->assertSame([], $this->sent());
        [$log] = $this->logs($this->noEmail);
        $this->assertSame([null, null, EmailLog::NO_EMAIL], [$log->intended_to, $log->delivered_to, $log->status]);
        $this->get('/statements?month=2027-01')->assertSee(__('statements.send.status.no_email'));
    }

    public function test_send_all_for_the_month_only_sends_the_unsent(): void
    {
        $december = Statement::query()->where('lease_contract_id', $this->lease->id)->whereDate('month', '2026-12-01')->sole();

        $this->post('/statements/send-month', ['month' => '2027-01'])->assertRedirect('/statements?month=2027-01')
            ->assertSessionHas('status', __('statements.send.sent_month', ['sent' => 1]))
            ->assertSessionHas('warning', __('statements.send.skipped_no_email', ['numbers' => '№ '.$this->noEmail->number]));
        $this->assertCount(1, $this->sent());
        $this->assertSame([], $this->logs($december)); // друг месец

        // Втори път: изпратеното не се праща пак; без имейл пак се отбелязва.
        $this->post('/statements/send-month', ['month' => '2027-01'])->assertSessionHas('status', __('statements.send.sent_month', ['sent' => 0]));
        $this->assertCount(1, $this->sent());
        $this->assertCount(1, $this->logs($this->statement));
    }

    public function test_real_sending_includes_statements_that_were_only_redirected_or_logged(): void
    {
        // Преди: Борис — само пробно (пренасочено); наемателят без имейл — нищо. Друг наемател — само в лога.
        $this->post("/statements/{$this->statement->id}/send");
        $this->assertSame(MailGuard::REDIRECT, $this->logs($this->statement)[0]->mode);
        $other = $this->issuedFor('Лог Измислен', 'log@example.test', 141);
        config(['mail.redirect_to' => null]);
        app(StatementSender::class)->send($other);
        $this->assertSame(EmailLog::LOGGED, $this->logs($other)[0]->status);
        Mail::mailer('array')->getSymfonyTransport()->flush();

        // Сега истинско изпращане: и двете отиват при наемателите, защото не са ги получили.
        $this->app['env'] = 'production';
        $result = app(StatementSender::class)->sendMonth(CarbonImmutable::parse('2027-01-01'));

        $this->assertSame(2, $result['sent']);
        $this->assertEqualsCanonicalizing(['boris@example.test', 'log@example.test'],
            array_map(fn (Email $email) => $email->getTo()[0]->getAddress(), $this->sent()));
        $this->assertSame(MailGuard::REAL, $this->logs($this->statement)[1]->mode);

        // Истински изпратеното не се праща пак; поотделно — само с потвърждение.
        $this->assertSame(0, app(StatementSender::class)->sendMonth(CarbonImmutable::parse('2027-01-01'))['sent']);
        $this->assertTrue(app(StatementSender::class)->wasSent($this->statement));
    }

    public function test_redirected_statement_is_not_already_sent_for_real_sending(): void
    {
        $this->post("/statements/{$this->statement->id}/send");
        $this->assertTrue(app(StatementSender::class)->wasSent($this->statement));

        $this->app['env'] = 'production';
        config(['mail.redirect_to' => null]);
        $this->assertFalse(app(StatementSender::class)->wasSent($this->statement));
    }

    private function issuedFor(string $name, string $email, int $number): Statement
    {
        $property = Property::factory()->create(['company_id' => $this->company->id, 'number' => $number]);
        app(OwnerContractService::class)->create(['type' => \App\Enums\OwnerContractType::Own, 'signed_on' => '2026-01-01', 'lines' => [['property_id' => $property->id]]]);
        auth()->logout();
        $tenant = Tenant::factory()->create(['company_id' => $this->company->id, 'name' => $name, 'email' => $email]);
        $this->actingAs($this->user);
        $lease = app(LeaseContractService::class)->create(['tenant_id' => $tenant->id, 'signed_on' => '2027-01-01', 'lines' => [['property_id' => $property->id, 'rent' => '200']]]);

        return app(Issuer::class)->issueNow($lease, '2027-01');
    }

    public function test_resend_needs_confirmation_and_only_one_by_one(): void
    {
        $this->post("/statements/{$this->statement->id}/send");
        $this->post("/statements/{$this->statement->id}/send")->assertSessionHas('warning', __('statements.send.already_sent'));
        $this->assertCount(1, $this->sent());

        $this->post("/statements/{$this->statement->id}/send", ['confirm_resend' => '1'])->assertSessionHas('status');
        $this->assertCount(2, $this->sent());
        $this->assertCount(2, $this->logs($this->statement));
        $this->get("/statements/{$this->statement->id}")->assertSee('name="confirm_resend"', false);
    }

    public function test_failure_is_recorded_and_the_others_continue(): void
    {
        Mail::extend('broken', fn () => new class implements TransportInterface
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new \RuntimeException('Сървърът за поща не отговаря (измислено).');
            }

            public function __toString(): string
            {
                return 'broken';
            }
        });
        config(['mail.mailers.broken' => ['transport' => 'broken'], 'mail.default' => 'broken']);

        $this->post('/statements/send-month', ['month' => '2027-01'])->assertSessionHas('warning');

        [$log] = $this->logs($this->statement);
        $this->assertSame(EmailLog::FAILED, $log->status);
        $this->assertStringContainsString('не отговаря', $log->error);
        $this->assertSame(EmailLog::NO_EMAIL, $this->logs($this->noEmail)[0]->status);
        $this->get('/statements?month=2027-01')->assertSee(__('statements.send.status.failed'));
    }

    public function test_status_in_the_list_and_history_on_the_card(): void
    {
        $this->get('/statements?month=2027-01')->assertSee(__('statements.send.column'))->assertSee(__('statements.send.send_month'));
        $this->post("/statements/{$this->statement->id}/send");

        $this->get('/statements?month=2027-01')->assertSee(__('statements.send.status.sent', ['date' => '10.01.2027']));
        $this->get("/statements/{$this->statement->id}")->assertSee(__('statements.send.history'))
            ->assertSee('boris@example.test')->assertSee(self::REDIRECT)->assertSee(__('statements.send.modes.redirect'));
    }

    public function test_logo_is_embedded_in_the_mail(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->post('/company/logo', ['logo' => \Illuminate\Http\UploadedFile::fake()->image('logo.png', 400, 200)]);

        $this->post("/statements/{$this->statement->id}/send");
        [$email] = $this->sent();
        $this->assertStringContainsString('src="cid:', $email->getHtmlBody());
        // В писмото логото е до 200 × 60 px, с пропорциите (400 × 200 → 120 × 60), 25.11.2026.
        $this->assertMatchesRegularExpression('#<img src="cid:[^"]+" alt="" width="120" height="60"#', $email->getHtmlBody());
        $this->assertStringNotContainsString('data:image', $email->getHtmlBody());
    }

    public function test_only_the_charges_manager_sends(): void
    {
        foreach ([$this->readOnlyStaff(), $this->assignedOnlyStaff()] as $staff) {
            auth()->logout();
            $this->actingAs($staff);
            if ($staff->property_scope?->value === 'assigned') {
                (new UserProperty)->forceFill(['company_id' => $this->company->id, 'user_id' => $staff->id, 'property_id' => $this->flat->id])->save();
            }
            $this->post("/statements/{$this->statement->id}/send")->assertForbidden();
            $this->post('/statements/send-month', ['month' => '2027-01'])->assertForbidden();
            $this->get("/statements/{$this->statement->id}")->assertOk()->assertDontSee('/statements/'.$this->statement->id.'/send', false);
        }
        $this->assertSame([], $this->sent());
        $this->assertSame(0, EmailLog::query()->count());
    }

    public function test_company_b_cannot_send_the_statements_of_company_a(): void
    {
        auth()->logout();
        $this->actingAs($this->staff([], $this->otherCompany));

        $this->post("/statements/{$this->statement->id}/send")->assertNotFound();
        $this->post('/statements/send-month', ['month' => '2027-01'])->assertRedirect();
        $this->assertSame([], $this->sent());
        $this->assertSame(0, EmailLog::query()->withoutGlobalScopes()->count());
    }
}
