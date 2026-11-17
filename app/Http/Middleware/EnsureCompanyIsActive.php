<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * При всяка заявка: ако фирмата на влезлия потребител е неактивна,
 * той се изкарва от системата (PROJECT.md, т. 2).
 */
class EnsureCompanyIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Чете се наново от базата при всяка заявка, не от паметта.
        $companyIsActive = fn () => Company::query()->whereKey($user->company_id)->where('is_active', true)->exists();

        if ($user && $user->company_id !== null && ! $companyIsActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => __('auth.company_inactive')]);
        }

        return $next($request);
    }
}
