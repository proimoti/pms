<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Плащане от наемател (PROJECT.md, „Плащания и салда“, 26.11.2026): ръчно, към
 * един договор за наем. Сторното е отделен ред с reversal_of_id и сума с минус.
 * Само добавяне: плащането не се променя и не се трие.
 */
class Payment extends Model
{
    use AppendOnly, BelongsToCompany;

    public const BANK = 'bank';

    public const CASH = 'cash';

    protected $table = 'payments';

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'immutable_date', 'bank_account_id' => 'integer', 'created_by' => 'integer',
            'reversal_of_id' => 'integer'];
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    public function leaseContract(): BelongsTo
    {
        return $this->belongsTo(LeaseContract::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Сторното на това плащане, ако има. */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }
}
