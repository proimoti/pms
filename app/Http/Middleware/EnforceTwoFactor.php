<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Задължителен втори фактор за администратора на приложението и служителите
 * (PROJECT.md, т. 2).
 *
 * - докато не е настроен, потребителят стига само до настройката му и до изхода;
 * - не може да бъде изключен от самия потребител (само с командата на сървъра).
 */
class EnforceTwoFactor
{
    /** Адресите, нужни за настройката на втория фактор. */
    private const SETUP_ROUTES = [
        'two-factor.setup',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->requiresTwoFactor()) {
            return $next($request);
        }

        if ($request->routeIs('two-factor.disable')) {
            abort(403, __('auth.two_factor_required'));
        }

        if ($user->hasEnabledTwoFactorAuthentication() || $request->routeIs(...self::SETUP_ROUTES)) {
            return $next($request);
        }

        return redirect()->route('two-factor.setup');
    }
}
