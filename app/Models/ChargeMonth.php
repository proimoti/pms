<?php

namespace App\Models;

use App\Models\Concerns\WorksheetRules;
use App\Models\Scopes\RegistryVisibilityScope;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Месецът на начисленията: кой и кога го е генерирал (издаването идва с част 3). */
class ChargeMonth extends Model
{
    use WorksheetRules;

    protected $table = 'charge_months';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['month' => 'immutable_date', 'generated_at' => 'immutable_datetime'];
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /** Отметката за генерирането се вижда от всички служители на фирмата. */
    protected static function worksheetScopeRule(): string|Closure
    {
        return RegistryVisibilityScope::ALL;
    }
}
