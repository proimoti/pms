<?php

namespace App\Models\Concerns;

use App\Models\Scopes\RegistryVisibilityScope;

/**
 * Вариант с период „от–до“ в договор (ред, процент, бенефициент).
 * Не се трие: при анекс се затваря („до“) и се добавя нов вариант.
 * Моделът сам дава връзките contract() и annex().
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
}
