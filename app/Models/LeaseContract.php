<?php

namespace App\Models;

use App\Models\Concerns\ContractHeader;
use App\Services\ContractService;
use App\Services\LeaseContractService;
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
}
