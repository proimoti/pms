<?php

namespace App\Models;

use App\Models\Concerns\ContractHeader;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Services\ContractService;
use App\Services\LeaseContractService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Договор за наем (с наемател, сметка 418) — заглавието (PROJECT.md, т. 2
 * „Договори“). Наемът и договореният депозит са на редовете (по имот).
 * Създава се и се променя с анекси през LeaseContractService.
 */
class LeaseContract extends Model
{
    use ContractHeader;

    protected $table = 'lease_contracts';

    protected $fillable = ['number', 'tenant_id', 'signed_on', 'end_on', 'terminated_on', 'note'];

    protected static function contractService(): ContractService
    {
        return app(LeaseContractService::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function annexes(): HasMany
    {
        return $this->hasMany(LeaseContractAnnex::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LeaseContractLine::class);
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(LeaseContractExtension::class);
    }

    /** Обхват „само прикачените имоти“: вижда се, ако поне един имот в договора е прикачен. */
    protected static function assignedScopeRule(): string|Closure
    {
        return RegistryVisibilityScope::throughContracts('lease_contract_lines', 'lease_contract_id', 'id');
    }
}
