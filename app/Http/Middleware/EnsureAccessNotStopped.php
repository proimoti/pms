<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * При всяка заявка (27.11.2026): ако достъпът на влезлия потребител е спрян,
 * той се изкарва от системата. Профилът се чете наново от базата при всяка заявка.
 */
class EnsureAccessNotStopped
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $stopped = fn () => User::query()->withoutGlobalScopes()->whereKey($user->id)->whereNotNull('disabled_at')->exists();

        if ($user !== null && $stopped()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => __('auth.access_stopped')]);
        }

        return $next($request);
    }
}
