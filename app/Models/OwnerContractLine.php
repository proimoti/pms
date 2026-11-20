<?php

namespace App\Models;

use App\Enums\CalculationMethod;
use App\Models\Concerns\ContractVersion;
use App\Models\Scopes\RegistryVisibilityScope;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ред от договор за управление: имот за период „от–до“. При фиксиран тип
 * пази сумата към собственика; процентът е в договора (за всички имоти).
 */
class OwnerContractLine extends Model
{
    use ContractVersion;

    protected $table = 'owner_contract_lines';

    protected function casts(): array
    {
        return ['calculation' => CalculationMethod::class, 'fixed_amount' => 'decimal:2'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(OwnerContract::class, 'owner_contract_id');
    }

    public function annex(): BelongsTo
    {
        return $this->belongsTo(OwnerContractAnnex::class, 'annex_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** Обхват „само прикачените имоти“: вижда се, ако се вижда договорът (целият договор). */
    protected static function assignedScopeRule(): string|Closure
    {
        return RegistryVisibilityScope::throughContracts('owner_contract_lines', 'owner_contract_id', 'owner_contract_id');
    }
}
