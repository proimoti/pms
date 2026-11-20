<?php

namespace App\Models;

use App\Models\Concerns\NeverDeleted;
use App\Models\Concerns\RegistryRules;
use App\Models\Scopes\RegistryVisibilityScope;
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

    /** До част 4 служител с обхват „само прикачените“ не вижда договорите. */
    protected static function assignedScopeRule(): string
    {
        return RegistryVisibilityScope::NONE;
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(LeaseContract::class, 'lease_contract_id');
    }
}
