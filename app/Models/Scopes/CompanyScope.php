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
 * Без филтър са само администраторът на приложението и случаите, в които
 * никой не е влязъл (команди и начални данни). Страниците с данни изискват вход.
 */
final class CompanyScope implements Scope
{
    public function __construct(private readonly string $column = 'company_id') {}

    public function apply(Builder $builder, Model $model): void
    {
        $companyId = self::restrictedCompanyId();

        if ($companyId !== null) {
            $builder->where($model->qualifyColumn($this->column), '=', $companyId);
        }
    }

    /**
     * Фирмата, до която е ограничен влезлият потребител, или null, ако няма ограничение.
     * Потребител без фирма, който не е администратор, получава 0, т.е. не вижда нищо.
     */
    public static function restrictedCompanyId(): ?int
    {
        // hasUser() не зарежда потребителя, така че самото влизане не се филтрира.
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
}
