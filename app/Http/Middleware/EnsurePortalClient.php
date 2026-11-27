<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Порталът е само за профилите с роля „клиент“; другите отиват към „Начало“ (27.11.2026). */
class EnsurePortalClient
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role !== Role::Client) {
            return redirect('/dashboard');
        }

        return $next($request);
    }
}
