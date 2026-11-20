<?php

namespace App\Models\Concerns;

use App\Enums\AccessLevel;
use App\Enums\Role;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

/**
 * Правилата за четене и запис, общи за регистъра и договорите:
 * филтър по фирма (скрита от администратора на приложението), журнал,
 * обхват и права (само служител с „четене и запис“ записва).
 */
trait RegistryRules
{
    use Audited, BelongsToCompany;

    public static function bootRegistryRules(): void
    {
        static::addGlobalScope(new RegistryVisibilityScope(static::assignedScopeRule()));

        static::saving(function () {
            if (! Auth::hasUser()) {
                return; // команди на сървъра
            }

            /** @var User $user */
            $user = Auth::user();

            if ($user->role === Role::AppAdmin) {
                return; // отказва се от филтъра по фирма
            }

            if ($user->role !== Role::Staff || $user->access_level !== AccessLevel::Write) {
                throw new AuthorizationException(__('registry.read_only'));
            }
        });
    }

    /**
     * Какво вижда служител с обхват „само прикачените имоти“. По подразбиране всичко.
     * Правило от RegistryVisibilityScope или своя проверка (Closure).
     */
    protected static function assignedScopeRule(): string|Closure
    {
        return RegistryVisibilityScope::ALL;
    }
}
