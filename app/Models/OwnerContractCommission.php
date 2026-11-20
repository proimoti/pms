<?php

namespace App\Models;

use App\Enums\CalculationMethod;
use App\Models\Concerns\ContractVersion;
use Illuminate\Database\Eloquent\Model;

/** Процент на комисионата за период (само при тип „Представител, %“). */
class OwnerContractCommission extends Model
{
    use ContractVersion;

    protected $table = 'owner_contract_commissions';

    protected function casts(): array
    {
        return ['calculation' => CalculationMethod::class, 'percent' => 'decimal:2'];
    }
}
