{{-- Статусът на последното изпращане на известието (24.11.2026). $log: EmailLog или null. --}}
@if ($log === null)
    <span class="muted">{{ __('statements.send.status.none') }}</span>
@elseif ($log->status === \App\Models\EmailLog::SENT)
    <span class="badge state-active">{{ __('statements.send.status.sent', ['date' => $log->sent_at->format('d.m.Y')]) }}</span>@if ($log->mode === \App\Mail\MailGuard::REDIRECT) <span class="muted">{{ __('statements.send.modes.redirect') }}</span>@endif
@elseif ($log->status === \App\Models\EmailLog::LOGGED)
    <span class="badge state-archived">{{ __('statements.send.status.logged', ['date' => $log->sent_at->format('d.m.Y')]) }}</span>
@elseif ($log->status === \App\Models\EmailLog::NO_EMAIL)
    <span class="badge" style="background: #fdf2dc; color: var(--warn);">{{ __('statements.send.status.no_email') }}</span>
@else
    <span class="badge" style="background: #fdecea; color: var(--error);">{{ __('statements.send.status.failed') }}</span>
@endif
