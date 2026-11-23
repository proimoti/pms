<?php

namespace App\Models;

use App\Models\Concerns\RegistryRules;
use App\Models\Scopes\RegistryVisibilityScope;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Имот на общ фонд. Не се архивира: при промяна на фонда махнатите имоти се
 * изтриват от списъка (с запис в журнала), новите се добавят.
 */
class FundProperty extends Model
{
    use RegistryRules;

    protected $table = 'fund_properties';

    protected $fillable = ['fund_id', 'property_id'];

    protected static function assignedScopeRule(): string|Closure
    {
        return RegistryVisibilityScope::throughContracts('fund_properties', 'fund_id', 'fund_id');
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
