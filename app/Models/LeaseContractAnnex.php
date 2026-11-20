<?php

namespace App\Models;

use App\Models\Concerns\NeverDeleted;
use App\Models\Concerns\RegistryRules;
use App\Models\Scopes\RegistryVisibilityScope;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Анекс към договор за наем: основанието (№, дата) за нов вариант. */
class LeaseContractAnnex extends Model
{
    use NeverDeleted, RegistryRules;

    protected $table = 'lease_contract_annexes';

    protected function casts(): array
    {
        return ['number' => 'integer', 'signed_on' => 'immutable_date'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(LeaseContract::class, 'lease_contract_id');
    }

    /** Обхват „само прикачените имоти“: вижда се, ако се вижда договорът. */
    protected static function assignedScopeRule(): string|Closure
    {
        return RegistryVisibilityScope::throughContracts('lease_contract_lines', 'lease_contract_id', 'lease_contract_id');
    }
}
