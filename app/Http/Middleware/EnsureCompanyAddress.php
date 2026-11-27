<?php

namespace App\Http\Middleware;

use App\Support\CompanyAddress;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * При всяка заявка (27.11.2026): влезлият потребител е от фирмата на адреса;
 * на адреса на администратора — само администраторът на приложението. Иначе
 * се изкарва от системата. На непознат адрес вход няма (виж CompanyAddressUserProvider).
 */
class EnsureCompanyAddress
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null) {
            $companyId = CompanyAddress::companyId($request);
            $wrong = CompanyAddress::isAdmin($request)
                ? ! $user->isAppAdmin()
                : $companyId !== null && $companyId !== (int) $user->company_id;
            if ($wrong) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login');
            }
        }

        return $next($request);
    }
}
