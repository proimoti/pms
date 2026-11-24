<?php

namespace App\Models\Concerns;

use App\Enums\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

/**
 * Данните на фирмата и банковите ѝ сметки (24.11.2026): записва само
 * администраторът на фирмата. Командите на сървъра (без влязъл потребител)
 * и администраторът на приложението също могат.
 */
trait CompanyAdminOnly
{
    public static function bootCompanyAdminOnly(): void
    {
        static::saving(function ($model) {
            if (! Auth::hasUser() || ! $model->needsCompanyAdmin()) {
                return;
            }
            $user = Auth::user();
            if ($user->role === Role::AppAdmin) {
                return;
            }
            if ($user->role !== Role::Staff || ! $user->is_company_admin) {
                throw new AuthorizationException(__('company.admin_only'));
            }
        });
    }

    /** Иска ли този запис администратора на фирмата. По подразбиране винаги. */
    protected function needsCompanyAdmin(): bool
    {
        return true;
    }
}
