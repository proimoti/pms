<?php

namespace App\Models\Scopes;

use App\Enums\PropertyScope;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Кой какво вижда в регистъра на своята фирма (PROJECT.md, т. 2 „Достъп“ и „Регистър“).
 *
 * - собственици и наематели (като потребители): нищо, докато не дойде порталът;
 * - служител с обхват „само прикачените имоти“: само прикачените имоти;
 *   собственици, наематели и бенефициенти не вижда, докато не дойдат договорите;
 *   отговорните лица и видовете имоти вижда.
 */
final class RegistryVisibilityScope implements Scope
{
    public const ALL = 'all';

    public const NONE = 'none';

    public const ASSIGNED_PROPERTIES = 'assigned_properties';

    /** @param  string  $assignedRule  какво вижда служител с обхват „само прикачените“ */
    public function __construct(private readonly string $assignedRule) {}

    public function apply(Builder $builder, Model $model): void
    {
        if (! Auth::hasUser()) {
            return;
        }

        /** @var User $user */
        $user = Auth::user();

        if ($user->role === Role::AppAdmin) {
            return; // филтърът по фирма вече го скрива
        }

        if ($user->role !== Role::Staff) {
            $builder->whereRaw('0 = 1');

            return;
        }

        if ($user->property_scope !== PropertyScope::Assigned) {
            return;
        }

        match ($this->assignedRule) {
            self::ALL => null,
            self::NONE => $builder->whereRaw('0 = 1'),
            self::ASSIGNED_PROPERTIES => $builder->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('user_properties')
                ->whereColumn('user_properties.property_id', $model->qualifyColumn('id'))
                ->where('user_properties.user_id', $user->id)),
        };
    }
}
