<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\WorksheetRules;
use App\Models\Scopes\RegistryVisibilityScope;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Известие за дължими суми (не фактура; PROJECT.md, „Издаване и заключване“):
 * едно за договор за наем и месец, с пореден номер във фирмата. Издаденото
 * известие не се променя; корекциите отиват в книгата.
 */
class Statement extends Model
{
    use AppendOnly, WorksheetRules;

    protected $table = 'statements';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'month' => 'immutable_date',
            'total' => 'decimal:2',
            'issued_at' => 'immutable_datetime',
            'snapshot' => 'array',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StatementLine::class)->orderBy('id');
    }

    public function leaseContract(): BelongsTo
    {
        return $this->belongsTo(LeaseContract::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** „Само прикачените“: известието се вижда, ако се вижда договорът (целият договор). */
    protected static function worksheetScopeRule(): string|Closure
    {
        return RegistryVisibilityScope::throughContracts('lease_contract_lines', 'lease_contract_id', 'lease_contract_id');
    }
}
