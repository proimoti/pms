<?php

namespace App\Models;

use App\Enums\CalculationMethod;
use App\Models\Concerns\ContractVersion;
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

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
