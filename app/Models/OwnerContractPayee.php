<?php

namespace App\Models;

use App\Enums\CalculationMethod;
use App\Enums\PaymentMethod;
use App\Models\Concerns\ContractVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Бенефициент и начин на плащане за период (за целия договор). */
class OwnerContractPayee extends Model
{
    use ContractVersion;

    protected $table = 'owner_contract_payees';

    protected function casts(): array
    {
        return ['calculation' => CalculationMethod::class, 'payment_method' => PaymentMethod::class];
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }
}
