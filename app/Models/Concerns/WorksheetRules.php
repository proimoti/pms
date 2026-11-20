<?php

namespace App\Models\Concerns;

use App\Enums\AccessLevel;
use App\Enums\PropertyScope;
use App\Enums\Role;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Правилата за начисленията (PROJECT.md, „Работна таблица на месеца“): филтър
 * по фирма; служител с „само прикачените“ вижда само редовете на своите имоти;
 * генерира и променя само служител с „четене и запис“ и „всички имоти“,
 * защото генерирането е за цялата фирма.
 */
trait WorksheetRules
{
    use BelongsToCompany;

    public static function bootWorksheetRules(): void
    {
        static::addGlobalScope(new RegistryVisibilityScope(static::worksheetScopeRule()));

        $guard = function () {
            if (! Auth::hasUser()) {
                return; // команди на сървъра
            }

            /** @var User $user */
            $user = Auth::user();

            if (! self::canManage($user)) {
                throw new AuthorizationException(__('charges.messages.cannot_manage'));
            }
        };

        static::saving($guard);
        static::deleting($guard);
    }

    /** Може ли потребителят да генерира и да променя начисленията. */
    public static function canManage(?User $user): bool
    {
        return $user !== null && $user->role === Role::Staff
            && $user->access_level === AccessLevel::Write && $user->property_scope === PropertyScope::All;
    }

    /** По подразбиране: редът се вижда, ако имотът му е прикачен. */
    protected static function worksheetScopeRule(): string|Closure
    {
        return fn (Builder $builder, Model $model, User $user) => $builder->whereIn(
            $model->qualifyColumn('property_id'),
            RegistryVisibilityScope::assignedPropertyIds($user),
        );
    }
}
