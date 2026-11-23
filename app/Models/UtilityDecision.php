<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\NeverDeleted;
use App\Models\Concerns\WorksheetRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Решение за неразпределена битова сметка (PROJECT.md, част 5, 23.11.2026):
 * за остатъка на една платена сметка за един имот — „за сметка на
 * управителя“ или „към следващия наемател“ (договорът). Записва се през
 * UtilityDecisions. Не се трие: при отмяна остава с дата, кой и причина.
 * Вижда се, ако имотът е видим; решава само „четене и запис“ + „всички имоти“.
 */
class UtilityDecision extends Model
{
    use Audited, NeverDeleted, WorksheetRules;

    public const MANAGER = 'manager';

    public const NEXT_TENANT = 'next_tenant';

    protected $table = 'utility_decisions';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'decided_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->cancelled_at === null;
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PaidUtilityBill::class, 'paid_utility_bill_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function leaseContract(): BelongsTo
    {
        return $this->belongsTo(LeaseContract::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
