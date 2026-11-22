<?php

namespace App\Models;

use App\Models\Concerns\RegistryRules;
use App\Models\Scopes\RegistryVisibilityScope;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Имот на абонат със съотношение (дял в проценти). При един имот делът е 100.
 * Не се архивира: при промяна на абоната редовете се записват наново (в журнала).
 */
class UtilityAccountProperty extends Model
{
    use RegistryRules;

    protected $table = 'utility_account_properties';

    protected $fillable = ['utility_account_id', 'property_id', 'share', 'position'];

    protected function casts(): array
    {
        return ['share' => 'decimal:2', 'position' => 'integer'];
    }

    protected static function assignedScopeRule(): string|Closure
    {
        return RegistryVisibilityScope::throughContracts('utility_account_properties', 'utility_account_id', 'utility_account_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(UtilityAccount::class, 'utility_account_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
