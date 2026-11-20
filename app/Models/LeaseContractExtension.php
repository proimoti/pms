<?php

namespace App\Models;

use App\Models\Concerns\NeverDeleted;
use App\Models\Concerns\RegistryRules;
use App\Models\Scopes\RegistryVisibilityScope;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Удължаване на срока на договор за наем по анекс: стар и нов срок. */
class LeaseContractExtension extends Model
{
    use NeverDeleted, RegistryRules;

    protected $table = 'lease_contract_extensions';

    protected function casts(): array
    {
        return ['previous_end_on' => 'immutable_date', 'end_on' => 'immutable_date'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(LeaseContract::class, 'lease_contract_id');
    }

    public function annex(): BelongsTo
    {
        return $this->belongsTo(LeaseContractAnnex::class, 'annex_id');
    }

    /** Обхват „само прикачените имоти“: вижда се, ако се вижда договорът. */
    protected static function assignedScopeRule(): string|Closure
    {
        return RegistryVisibilityScope::throughContracts('lease_contract_lines', 'lease_contract_id', 'lease_contract_id');
    }
}
