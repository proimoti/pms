<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\WorksheetRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Частта на платена сметка за един имот (по съотношението на абоната). Само се добавя. */
class PaidUtilityBillPart extends Model
{
    use AppendOnly, WorksheetRules;

    protected $table = 'paid_utility_bill_parts';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['share' => 'decimal:2', 'amount' => 'decimal:2'];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PaidUtilityBill::class, 'paid_utility_bill_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
