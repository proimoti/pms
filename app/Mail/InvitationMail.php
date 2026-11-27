<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\User;
use App\Support\Invitation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Покана за достъп (27.11.2026): от името на фирмата, с логото ѝ, без името
 * на приложението. Връзката важи 72 часа.
 */
class InvitationMail extends Mailable
{
    /** @param ?array{bytes: string, mime: string} $logo */
    public function __construct(
        public User $user,
        public Company $company,
        public string $url,
        public ?array $logo,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->company->name),
            replyTo: empty($this->company->email) ? [] : [new Address($this->company->email, $this->company->name)],
            subject: __('invitation.mail.subject', ['company' => $this->company->name]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'auth.invitation-mail', with: [
            'name' => $this->user->name,
            'companyName' => $this->company->name,
            'url' => $this->url,
            'hours' => Invitation::HOURS,
            'logoData' => $this->logo,
        ]);
    }
}
