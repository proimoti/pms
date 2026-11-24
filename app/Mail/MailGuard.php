<?php

namespace App\Mail;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Пазачът на пощата (PROJECT.md, „PDF и имейл на известията“, 24.11.2026).
 * Всяко писмо на приложението (известия, забравена парола и др.) минава оттук:
 *
 * - MAIL_REDIRECT_TO попълнено → само на този адрес, с „[ТЕСТ]“ в темата и
 *   бележка за кого и на кой адрес е било (без копия);
 * - празно и APP_ENV не е production → нищо не излиза, писмото се записва
 *   само в лога (локално там има само измислени данни);
 * - APP_ENV=production и празно → истинско изпращане.
 */
final class MailGuard
{
    public const REAL = 'real';

    public const REDIRECT = 'redirect';

    public const LOG = 'log';

    public function mode(): string
    {
        return match (true) {
            $this->redirectTo() !== null => self::REDIRECT,
            app()->environment('production') => self::REAL,
            default => self::LOG,
        };
    }

    /** Къде ще отиде писмо до $address: ['mode' => …, 'to' => адресът или null (само в лога)]. */
    public function deliveryFor(string $address): array
    {
        return match ($this->mode()) {
            self::REDIRECT => ['mode' => self::REDIRECT, 'to' => $this->redirectTo()],
            self::REAL => ['mode' => self::REAL, 'to' => $address],
            default => ['mode' => self::LOG, 'to' => null],
        };
    }

    /** Слушател на MessageSending: false спира изпращането. */
    public function handle(MessageSending $event): ?bool
    {
        $email = $event->message;
        if (! $email instanceof Email) {
            return $this->mode() === self::REAL ? null : false;
        }

        return match ($this->mode()) {
            self::REAL => null,
            self::REDIRECT => $this->redirect($email),
            default => $this->log($email),
        };
    }

    private function redirect(Email $email): ?bool
    {
        $intended = $this->list($email->getTo());
        $copies = $this->list(array_merge($email->getCc(), $email->getBcc()));
        $note = 'ТЕСТ: писмото е било за '.($intended === '' ? '—' : $intended).($copies === '' ? '' : ' (копия: '.$copies.')')
            .'. Пренасочено към '.$this->redirectTo().' (MAIL_REDIRECT_TO).';

        $headers = $email->getHeaders();
        foreach (['To', 'Cc', 'Bcc'] as $name) {
            $headers->remove($name);
        }
        $email->to(new Address($this->redirectTo()));
        $email->subject('[ТЕСТ] '.$email->getSubject());

        $html = $email->getHtmlBody();
        if ($html !== null) {
            $box = '<div style="border:2px solid #b42318;padding:8px 12px;margin-bottom:16px;font-family:sans-serif;">'.e($note).'</div>';
            $email->html(is_string($html) ? $box.$html : $box.stream_get_contents($html));
        }
        $text = $email->getTextBody();
        if ($text !== null) {
            $email->text($note."\n\n".(is_string($text) ? $text : stream_get_contents($text)));
        }

        return null;
    }

    private function log(Email $email): bool
    {
        $html = $email->getHtmlBody();
        $text = $email->getTextBody();
        $body = $text !== null ? (is_string($text) ? $text : stream_get_contents($text))
            : trim(strip_tags(is_string($html) ? $html : (string) ($html === null ? '' : stream_get_contents($html))));

        Log::info('Писмото не е изпратено (само в лога; MAIL_REDIRECT_TO е празно и APP_ENV не е production): '.$email->getSubject(), [
            'to' => array_map(fn (Address $a) => $a->getAddress(), $email->getTo()),
            'cc' => array_map(fn (Address $a) => $a->getAddress(), $email->getCc()),
            'attachments' => count($email->getAttachments()),
            'body' => mb_substr($body, 0, 2000),
        ]);

        return false;
    }

    private function redirectTo(): ?string
    {
        $value = trim((string) config('mail.redirect_to'));

        return $value === '' ? null : $value;
    }

    /** @param list<Address> $addresses */
    private function list(array $addresses): string
    {
        return implode(', ', array_map(fn (Address $a) => $a->getName() !== '' ? $a->getName().' <'.$a->getAddress().'>' : $a->getAddress(), $addresses));
    }
}
