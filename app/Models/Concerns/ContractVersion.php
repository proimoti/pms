<?php

namespace App\Models\Concerns;

use App\Models\OwnerContract;
use App\Models\OwnerContractAnnex;
use App\Models\Scopes\RegistryVisibilityScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Вариант с период „от–до“ в договор за управление (ред, процент, бенефициент).
 * Не се трие: при анекс се затваря („до“) и се добавя нов вариант.
 */
trait ContractVersion
{
    use NeverDeleted, RegistryRules;

    public function initializeContractVersion(): void
    {
        $this->mergeCasts([
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
        ]);
    }

    /** До част 4 служител с обхват „само прикачените“ не вижда договорите. */
    protected static function assignedScopeRule(): string
    {
        return RegistryVisibilityScope::NONE;
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(OwnerContract::class, 'owner_contract_id');
    }

    public function annex(): BelongsTo
    {
        return $this->belongsTo(OwnerContractAnnex::class, 'annex_id');
    }
}
