<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\PasswordResetLinkResponse;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

/** Вход чрез Fortify (PROJECT.md, т. 2 „Вход“). */
class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Забравена парола: един и същ отговор, независимо дали имейлът съществува.
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);

        // След вход клиентът отива в портала (27.11.2026).
        $this->app->singleton(\Laravel\Fortify\Contracts\LoginResponse::class, \App\Http\Responses\LoginResponse::class);
        $this->app->singleton(\Laravel\Fortify\Contracts\TwoFactorLoginResponse::class, \App\Http\Responses\LoginResponse::class);
    }

    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Писмо за забравена парола: на български, с името PMS като подател.
        ResetPassword::toMailUsing(fn (object $notifiable, string $token) => (new MailMessage)
            ->from(config('mail.from.address'), config('app.name'))
            ->subject(__('mail.reset.subject', ['app' => config('app.name')]))
            ->greeting(__('mail.reset.greeting'))
            ->line(__('mail.reset.intro', ['app' => config('app.name')]))
            ->action(__('mail.reset.action'), url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->email, // в адреса — само имейлът; ключът на връзката е с фирмата (27.11.2026)
            ], false)))
            ->line(__('mail.reset.expires', ['count' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire')]))
            ->line(__('mail.reset.not_you'))
            ->salutation(__('mail.reset.salutation', ['app' => config('app.name')])));

        Fortify::authenticateUsing(function (Request $request) {
            // Само профилите на фирмата на адреса (27.11.2026): имейлът е уникален в рамките на фирмата.
            $user = \App\Support\CompanyAddress::constrain(User::query()->withoutGlobalScopes(), $request)->where('email', $request->input('email'))->first();

            if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null; // общото съобщение „грешен имейл или парола“
            }

            // Казва се само след вярна парола, затова не издава дали имейлът съществува.
            if ($user->company_id !== null && ! $user->company?->is_active) {
                throw ValidationException::withMessages(['email' => __('auth.company_inactive')]);
            }
            if ($user->isStopped()) {
                throw ValidationException::withMessages(['email' => __('auth.access_stopped')]);
            }

            // Портал (27.11.2026): достъп, докато има неархивиран свързан запис; при ново
            // устройство — код по имейл. Който е включил приложението, ползва него.
            if ($user->role === \App\Enums\Role::Client) {
                if (! \App\Support\PortalAccess::hasAccess($user)) {
                    throw ValidationException::withMessages(['email' => __('auth.access_stopped')]);
                }
                if (! $user->hasEnabledTwoFactorAuthentication() && ! \App\Support\TrustedDevices::isTrusted($user, $request)) {
                    \App\Support\LoginCode::start($user, $request, $request->boolean('remember'));
                    throw new \Illuminate\Http\Exceptions\HttpResponseException(redirect('/login/code'));
                }
            }

            return $user;
        });

        RateLimiter::for('login', function (Request $request) {
            $key = Str::transliterate(Str::lower((string) $request->input(Fortify::username()))).'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by((string) $request->session()->get('login.id'));
        });

        Fortify::loginView(fn () => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));
    }
}
