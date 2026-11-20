<?php

namespace App\Models\Concerns;

/**
 * Вариант с период „от–до“ в договор (ред, процент, бенефициент).
 * Не се трие: при анекс се затваря („до“) и се добавя нов вариант.
 * Моделът сам дава връзките contract() и annex() и правилото за обхват
 * „само прикачените“ (вижда се, ако се вижда договорът).
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
}
