<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * За всеки модел, чиято таблица има company_id.
 *
 * - при четене: само редовете на фирмата на влезлия потребител;
 * - при запис: company_id винаги става фирмата на влезлия потребител,
 *   каквото и да е подадено. Така ред не може да се прехвърли в друга фирма;
 * - администраторът на приложението по подразбиране не вижда и не записва
 *   (регистърът). Модел, който той трябва да вижда, го казва изрично.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope('company_id', static::appAdminSeesAll()));

        static::saving(function (Model $model) {
            if (CompanyScope::currentUserIsAppAdmin() && ! static::appAdminSeesAll()) {
                throw new AuthorizationException(__('auth.app_admin_no_registry'));
            }

            $companyId = CompanyScope::restrictedCompanyId();

            if ($companyId !== null) {
                $model->company_id = $companyId;
            }
        });
    }

    /** Вижда ли администраторът на приложението тези записи. По подразбиране не. */
    protected static function appAdminSeesAll(): bool
    {
        return false;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
