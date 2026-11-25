<?php

namespace App\Services\Statements;

use App\Mail\MailGuard;
use App\Mail\StatementMail;
use App\Models\Company;
use App\Models\EmailLog;
use App\Models\Statement;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Изпращане на известията (PROJECT.md, „PDF и имейл на известията“, 24.11.2026).
 * Веднага, без опашка (на хостинга няма постоянен процес). Всяко изпращане
 * (и „няма имейл“, и грешка) се записва в email_log без съдържанието. Писмото
 * минава през пазача на пощата. Правото (само управляващият) се пази в адресите.
 */
final class StatementSender
{
    public function __construct(
        private readonly StatementPdf $pdf,
        private readonly MailGuard $guard,
    ) {}

    /**
     * Изпратено ли е вече. При истинско изпращане (production, без
     * MAIL_REDIRECT_TO) се брои само истински изпратеното: пробно пренасоченото и
     * „само в лога“ наемателят не ги е получил (уточнено 25.11.2026). При проба се
     * броят и те, за да не се праща едно и също пак.
     */
    public function wasSent(Statement $statement): bool
    {
        $query = EmailLog::query()->where('statement_id', $statement->id);

        return $this->guard->mode() === MailGuard::REAL
            ? $query->where('status', EmailLog::SENT)->where('mode', MailGuard::REAL)->exists()
            : $query->whereIn('status', [EmailLog::SENT, EmailLog::LOGGED])->exists();
    }

    public function send(Statement $statement): EmailLog
    {
        $tenant = Tenant::query()->find($statement->tenant_id);
        $email = trim((string) $tenant?->email);
        $base = ['kind' => 'statement', 'statement_id' => $statement->id, 'tenant_id' => $statement->tenant_id];

        if ($email === '') {
            return EmailLog::record($base + ['status' => EmailLog::NO_EMAIL]);
        }

        $delivery = $this->guard->deliveryFor($email);
        $base += ['intended_to' => $email, 'mode' => $delivery['mode']];

        try {
            $mail = new StatementMail($statement, Company::query()->find($statement->company_id)?->logoFile(),
                $this->pdf->pdf($statement), $this->pdf->filename($statement));
            Mail::to($email, $tenant->name)->send($mail);
        } catch (Throwable $e) {
            return EmailLog::record($base + ['status' => EmailLog::FAILED, 'error' => mb_substr($e->getMessage(), 0, 300)]);
        }

        return EmailLog::record($base + [
            'delivered_to' => $delivery['to'],
            'status' => $delivery['mode'] === MailGuard::LOG ? EmailLog::LOGGED : EmailLog::SENT,
        ]);
    }

    /**
     * Всички неизпратени известия за месеца; повторно — само поотделно.
     *
     * @return array{sent: int, no_email: list<int>, failed: list<int>}
     */
    public function sendMonth(CarbonImmutable $month): array
    {
        $result = ['sent' => 0, 'no_email' => [], 'failed' => []];
        foreach (Statement::query()->whereDate('month', $month->startOfMonth())->orderBy('number')->get() as $statement) {
            if ($this->wasSent($statement)) {
                continue;
            }
            $log = $this->send($statement);
            match ($log->status) {
                EmailLog::NO_EMAIL => $result['no_email'][] = $statement->number,
                EmailLog::FAILED => $result['failed'][] = $statement->number,
                default => $result['sent']++,
            };
        }

        return $result;
    }
}
