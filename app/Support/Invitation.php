<?php

namespace App\Support;

use App\Mail\InvitationMail;
use App\Mail\MailGuard;
use App\Models\Company;
use App\Models\CompanyDomain;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * Покана по имейл и „Задай парола“ (план „Портал и служители“, 27.11.2026).
 * Обща за служителите и за портала. Връзката е на адреса на фирмата, важи
 * 72 часа и само веднъж; пази се само отпечатъкът ѝ. Нова покана заменя старата.
 */
final class Invitation
{
    public const HOURS = 72;

    public static function send(User $user, User $by): EmailLog
    {
        if ($user->company_id === null) {
            throw new LogicException('Администраторът на приложението не се кани оттук.');
        }
        if ((int) $by->company_id !== (int) $user->company_id) {
            throw new LogicException('Покана се праща само от потребител на същата фирма.');
        }
        if ($user->isStopped()) {
            throw new LogicException('Достъпът е спрян. Първо се връща достъпът.');
        }
        if ($user->invitation_accepted_at !== null) {
            throw new LogicException('Профилът вече има парола. При забравена парола се ползва „Забравена парола“.');
        }
        $email = trim((string) $user->email);
        if ($email === '') {
            throw new LogicException('Профилът няма имейл.');
        }

        $company = Company::query()->withoutGlobalScopes()->findOrFail($user->company_id);
        $host = self::host($company);
        if ($host === null) {
            throw new LogicException('Фирмата няма адрес (pms:company-address).');
        }

        $token = Str::random(64);
        $expires = now()->addHours(self::HOURS);
        DB::transaction(function () use ($user, $by, $token, $expires) {
            $user->forceFill(['invitation_token' => hash('sha256', $token), 'invitation_expires_at' => $expires])->save();
            UserAudit::write($user, $by, 'invited', ['invitation_expires_at' => ['old' => null, 'new' => $expires->toDateTimeString()]]);
        });

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
        $url = "{$scheme}://{$host}/invitation/{$token}";

        $delivery = app(MailGuard::class)->deliveryFor($email);
        $base = ['kind' => 'invitation', 'intended_to' => $email, 'mode' => $delivery['mode'], 'user_id' => $by->id];
        try {
            Mail::to($email, $user->name)->send(new InvitationMail($user, $company, $url, $company->logoFile()));
        } catch (Throwable $e) {
            return self::log($company, $base + ['status' => EmailLog::FAILED, 'error' => mb_substr($e->getMessage(), 0, 300)]);
        }

        return self::log($company, $base + [
            'delivered_to' => $delivery['to'],
            'status' => $delivery['mode'] === MailGuard::LOG ? EmailLog::LOGGED : EmailLog::SENT,
        ]);
    }

    /** Профилът с тази връзка на адреса на заявката или null (непозната, чужд адрес, спрян). */
    public static function find(string $token, Request $request): ?User
    {
        if (! preg_match('/^[A-Za-z0-9]{64}$/', $token)) {
            return null;
        }

        return CompanyAddress::constrain(User::query()->withoutGlobalScopes(), $request)
            ->where('invitation_token', hash('sha256', $token))
            ->whereNull('disabled_at')->first();
    }

    public static function expired(User $user): bool
    {
        return $user->invitation_expires_at === null || $user->invitation_expires_at->isPast();
    }

    /** Задава паролата и прави връзката невалидна. */
    public static function accept(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password) {
            $user->forceFill([
                'password' => $password,
                'invitation_token' => null,
                'invitation_expires_at' => null,
                'invitation_accepted_at' => now(),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
            UserAudit::write($user, $user, 'invitation_accepted', ['invitation_accepted_at' => ['old' => null, 'new' => now()->toDateTimeString()]]);
        });
    }

    /** Адресът на фирмата: този, на който е служителят, ако е неин; иначе първият ѝ. */
    private static function host(Company $company): ?string
    {
        if (CompanyAddress::companyId(request()) === $company->id) {
            return CompanyDomain::normalize(request()->getHost());
        }

        return CompanyDomain::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->value('host');
    }

    private static function log(Company $company, array $attributes): EmailLog
    {
        $entry = new EmailLog;
        $entry->forceFill($attributes + ['company_id' => $company->id, 'sent_at' => now()])->save();

        return $entry;
    }
}
