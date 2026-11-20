<?php

namespace App\Models;

use App\Enums\CalculationMethod;
use App\Enums\PaymentMethod;
use App\Models\Concerns\ContractVersion;
use App\Models\Scopes\RegistryVisibilityScope;
use Closure;
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

    public function contract(): BelongsTo
    {
        return $this->belongsTo(OwnerContract::class, 'owner_contract_id');
    }

    public function annex(): BelongsTo
    {
        return $this->belongsTo(OwnerContractAnnex::class, 'annex_id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    /** Обхват „само прикачените имоти“: вижда се, ако се вижда договорът. */
    protected static function assignedScopeRule(): string|Closure
    {
        return RegistryVisibilityScope::throughContracts('owner_contract_lines', 'owner_contract_id', 'owner_contract_id');
    }
}
