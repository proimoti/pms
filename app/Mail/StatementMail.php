<?php

namespace App\Mail;

use App\Models\Statement;
use App\Services\Charges\Month;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Писмото с известието (PROJECT.md, „PDF и имейл на известията“, 24.11.2026):
 * известието е в самия текст (прегледно и на телефон) и като прикачен PDF.
 * От името на фирмата (запомненото при издаване), с „Отговор до“ имейла ѝ.
 * Логото е вградено в писмото (cid:), не като адрес data:.
 */
class StatementMail extends Mailable
{
    /** @param ?array{bytes: string, mime: string} $logo */
    public function __construct(
        public Statement $statement,
        public ?array $logo,
        private readonly string $pdf,
        private readonly string $filename,
    ) {}

    public function envelope(): Envelope
    {
        $company = $this->statement->snapshot['company'] ?? [];

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $company['name'] ?? (string) config('mail.from.name')),
            replyTo: empty($company['email']) ? [] : [new Address($company['email'], $company['name'] ?? null)],
            subject: __('statements.send.subject', ['number' => $this->statement->number, 'month' => Month::label($this->statement->month)]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'statements.mail', with: ['statement' => $this->statement, 'logoData' => $this->logo]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->filename)->withMime('application/pdf')];
    }
}
