<?php

namespace App\Support;

use App\Mail\LoginCodeMail;
use App\Mail\MailGuard;
use App\Models\Company;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Код по имейл при вход в портала от ново устройство (27.11.2026).
 * 6 цифри, важи 10 минути, до 5 опита. Пази се в сесията само отпечатъкът
 * му. Писмото е от името на фирмата; в дневника на писмата кодът не влиза.
 */
final class LoginCode
{
    public const SESSION = 'portal_login';

    public const MINUTES = 10;

    public const ATTEMPTS = 5;

    /** Нов код за профила: в сесията и по имейл. */
    public static function start(User $user, Request $request, bool $remember): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $request->session()->put(self::SESSION, [
            'id' => $user->id,
            'code' => self::fingerprint($code, $user),
            'expires' => now()->addMinutes(self::MINUTES)->getTimestamp(),
            'attempts' => 0,
            'remember' => $remember,
        ]);

        self::mail($user, $code);
    }

    /** Профилът, който чака код на адреса на заявката, или null. */
    public static function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get(self::SESSION);
        if (! is_array($pending) || $pending['expires'] < now()->getTimestamp()) {
            return null;
        }

        return CompanyAddress::constrain(User::query()->withoutGlobalScopes(), $request)->whereKey($pending['id'])
            ->whereNull('disabled_at')->first();
    }

    /** @return 'ok'|'wrong'|'ended' */
    public static function check(Request $request, User $user, string $code): string
    {
        $pending = $request->session()->get(self::SESSION);
        if (hash_equals($pending['code'], self::fingerprint($code, $user))) {
            return 'ok';
        }
        $pending['attempts']++;
        if ($pending['attempts'] >= self::ATTEMPTS) {
            self::end($request);

            return 'ended';
        }
        $request->session()->put(self::SESSION, $pending);

        return 'wrong';
    }

    public static function remember(Request $request): bool
    {
        return (bool) ($request->session()->get(self::SESSION)['remember'] ?? false);
    }

    public static function end(Request $request): void
    {
        $request->session()->forget(self::SESSION);
    }

    private static function fingerprint(string $code, User $user): string
    {
        return hash_hmac('sha256', $user->id.'|'.$code, (string) config('app.key'));
    }

    private static function mail(User $user, string $code): void
    {
        $company = Company::query()->withoutGlobalScopes()->findOrFail($user->company_id);
        $delivery = app(MailGuard::class)->deliveryFor($user->email);
        $base = ['kind' => 'login_code', 'intended_to' => $user->email, 'mode' => $delivery['mode'], 'company_id' => $company->id, 'user_id' => $user->id, 'sent_at' => now()];
        try {
            Mail::to($user->email, $user->name)->send(new LoginCodeMail($company, $code, self::MINUTES, $company->logoFile()));
            $status = $delivery['mode'] === MailGuard::LOG ? EmailLog::LOGGED : EmailLog::SENT;
            (new EmailLog)->forceFill($base + ['delivered_to' => $delivery['to'], 'status' => $status])->save();
        } catch (Throwable $e) {
            (new EmailLog)->forceFill($base + ['status' => EmailLog::FAILED, 'error' => mb_substr($e->getMessage(), 0, 300)])->save();
        }
    }
}
