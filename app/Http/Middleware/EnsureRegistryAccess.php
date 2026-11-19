<?php

namespace App\Http\Middleware;

use App\Enums\AccessLevel;
use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Регистърът е само за служителите на фирмата (PROJECT.md, т. 2).
 * С параметър „write“: само за служител с „четене и запис“.
 */
class EnsureRegistryAccess
{
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $user = $request->user();

        abort_unless($user && $user->role === Role::Staff, 403, __('registry.no_access'));

        if ($mode === 'write') {
            abort_unless($user->access_level === AccessLevel::Write, 403, __('registry.read_only'));
        }

        return $next($request);
    }
}
