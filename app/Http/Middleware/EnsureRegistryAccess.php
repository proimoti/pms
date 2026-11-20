<?php

namespace App\Http\Middleware;

use App\Enums\AccessLevel;
use App\Enums\Role;
use App\Models\Concerns\WorksheetRules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Регистърът е само за служителите на фирмата (PROJECT.md, т. 2).
 * С параметър „write“: само за служител с „четене и запис“; с „manage“: и
 * с „всички имоти“ (начисленията).
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

        // Начисленията: генериране и промени само с „четене и запис“ и „всички имоти“.
        if ($mode === 'manage') {
            abort_unless(WorksheetRules::canManage($user), 403, __('charges.messages.cannot_manage'));
        }

        return $next($request);
    }
}
