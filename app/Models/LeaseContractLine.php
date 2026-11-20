<?php

namespace App\Models;

use App\Models\Concerns\ContractVersion;
use App\Models\Scopes\RegistryVisibilityScope;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ред от договор за наем: имот за период „от–до“ с месечен наем и договорен
 * депозит. Депозитът е размерът по договор; начисляването му (и на вноски)
 * идва с начисленията.
 */
class LeaseContractLine extends Model
{
    use ContractVersion;

    protected $table = 'lease_contract_lines';

    protected function casts(): array
    {
        return ['rent' => 'decimal:2', 'deposit' => 'decimal:2'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(LeaseContract::class, 'lease_contract_id');
    }

    public function annex(): BelongsTo
    {
        return $this->belongsTo(LeaseContractAnnex::class, 'annex_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** Обхват „само прикачените имоти“: вижда се, ако се вижда договорът (целият договор). */
    protected static function assignedScopeRule(): string|Closure
    {
        return RegistryVisibilityScope::throughContracts('lease_contract_lines', 'lease_contract_id', 'lease_contract_id');
    }
}
