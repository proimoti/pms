<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\CompanyAdminOnly;
use Illuminate\Database\Eloquent\Model;

/**
 * Една настройка за експорта към Микроинвест (ключ → стойност), PROJECT.md,
 * „Експорт към Микроинвест“, 26.11.2026. Променя я само администраторът на
 * фирмата; всяка промяна е в журнала. Ключовете са в App\Services\Export\ExportSettings.
 */
class ExportRule extends Model
{
    use Audited, BelongsToCompany, CompanyAdminOnly;

    protected $table = 'export_rules';

    protected $fillable = ['key', 'value'];
}
