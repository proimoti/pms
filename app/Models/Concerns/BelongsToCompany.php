<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * За всеки модел, чиято таблица има company_id.
 *
 * - при четене: само редовете на фирмата на влезлия потребител;
 * - при запис: company_id винаги става фирмата на влезлия потребител,
 *   каквото и да е подадено. Така ред не може да се прехвърли в друга фирма.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::saving(function (Model $model) {
            $companyId = CompanyScope::restrictedCompanyId();

            if ($companyId !== null) {
                $model->company_id = $companyId;
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
