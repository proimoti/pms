<?php

namespace App\Mail;

use App\Models\Company;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Код за вход в портала (27.11.2026): от името на фирмата, без името на приложението. */
class LoginCodeMail extends Mailable
{
    /** @param ?array{bytes: string, mime: string} $logo */
    public function __construct(
        public Company $company,
        public string $code,
        public int $minutes,
        public ?array $logo,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->company->name),
            subject: __('portal.code.mail.subject', ['company' => $this->company->name]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'auth.login-code-mail', with: [
            'companyName' => $this->company->name,
            'code' => $this->code,
            'minutes' => $this->minutes,
            'logoData' => $this->logo,
        ]);
    }
}
