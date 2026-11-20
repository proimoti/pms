<?php

namespace App\Models;

use App\Enums\ChargeType;
use App\Models\Concerns\Audited;
use App\Models\Concerns\NeverDeleted;
use App\Models\Concerns\WorksheetRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Предстоящо начисление (PROJECT.md, „Начисления“): депозит, неустойка или
 * друго, въведено през месеца за своя месец. „Генерирай месеца“ го включва.
 * Не се трие: отказва се с причина. Промените са в журнала.
 */
class UpcomingCharge extends Model
{
    use Audited, NeverDeleted, WorksheetRules;

    protected $table = 'upcoming_charges';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'type' => ChargeType::class,
            'month' => 'immutable_date',
            'amount' => 'decimal:2',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function leaseContract(): BelongsTo
    {
        return $this->belongsTo(LeaseContract::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
