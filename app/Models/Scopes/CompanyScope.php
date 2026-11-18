<?php

namespace App\Models\Scopes;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Автоматичен филтър: влезлият потребител вижда само редовете на своята фирма.
 *
 * Администраторът на приложението вижда всички фирми и потребители, но не
 * вижда регистъра (PROJECT.md, т. 2): за него таблиците на регистъра са празни.
 * Без филтър е само случаят, в който никой не е влязъл (команди и начални данни).
 * Страниците с данни изискват вход.
 */
final class CompanyScope implements Scope
{
    public function __construct(
        private readonly string $column = 'company_id',
        private readonly bool $appAdminSeesAll = false,
    ) {}

    public function apply(Builder $builder, Model $model): void
    {
        // hasUser() не зарежда потребителя, така че самото влизане не се филтрира.
        if (! Auth::hasUser()) {
            return;
        }

        /** @var User $user */
        $user = Auth::user();

        if ($user->role === Role::AppAdmin) {
            if (! $this->appAdminSeesAll) {
                $builder->whereRaw('0 = 1');
            }

            return;
        }

        // Потребител без фирма (не би трябвало да съществува) получава 0, т.е. не вижда нищо.
        $builder->where($model->qualifyColumn($this->column), '=', $user->company_id ?? 0);
    }

    /**
     * Фирмата, до която е ограничен влезлият потребител, или null, ако няма ограничение
     * (никой не е влязъл или е администраторът на приложението).
     */
    public static function restrictedCompanyId(): ?int
    {
        if (! Auth::hasUser()) {
            return null;
        }

        /** @var User $user */
        $user = Auth::user();

        if ($user->role === Role::AppAdmin) {
            return null;
        }

        return $user->company_id ?? 0;
    }

    public static function currentUserIsAppAdmin(): bool
    {
        return Auth::hasUser() && Auth::user()->role === Role::AppAdmin;
    }
}
