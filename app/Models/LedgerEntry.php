<?php

namespace App\Models;

use App\Enums\ChargeType;
use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\WorksheetRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запис в книгата (PROJECT.md, т. 2 „Парите стоят в книга, в която само се
 * добавя“; „Издаване и заключване“). Не се променя и не се трие: грешка се
 * поправя с коригиращ запис за същия месец. Салдата се смятат от записите.
 */
class LedgerEntry extends Model
{
    use AppendOnly, WorksheetRules;

    public const UPDATED_AT = null;

    protected $table = 'ledger_entries';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'month' => 'immutable_date',
            'entry_date' => 'immutable_date',
            'account' => LedgerAccount::class,
            'kind' => LedgerKind::class,
            'type' => ChargeType::class,
            'amount' => 'decimal:2',
        ];
    }

    public function leaseContract(): BelongsTo
    {
        return $this->belongsTo(LeaseContract::class);
    }

    public function ownerContract(): BelongsTo
    {
        return $this->belongsTo(OwnerContract::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    public function paidUtilityBill(): BelongsTo
    {
        return $this->belongsTo(PaidUtilityBill::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
