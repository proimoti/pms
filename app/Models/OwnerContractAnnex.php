<?php

namespace App\Models;

use App\Models\Concerns\NeverDeleted;
use App\Models\Concerns\RegistryRules;
use App\Models\Scopes\RegistryVisibilityScope;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Анекс към договор за управление: основанието (№, дата) за нов вариант. */
class OwnerContractAnnex extends Model
{
    use NeverDeleted, RegistryRules;

    protected $table = 'owner_contract_annexes';

    protected function casts(): array
    {
        return ['number' => 'integer', 'signed_on' => 'immutable_date'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(OwnerContract::class, 'owner_contract_id');
    }

    /** Обхват „само прикачените имоти“: вижда се, ако се вижда договорът. */
    protected static function assignedScopeRule(): string|Closure
    {
        return RegistryVisibilityScope::throughContracts('owner_contract_lines', 'owner_contract_id', 'owner_contract_id');
    }
}
