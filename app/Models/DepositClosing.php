<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Приключване на депозит (PROJECT.md, „Приключване на депозит“, 26.11.2026):
 * ръчно, по договор за наем; сумите по начини и имоти са в книгата. Сторното е
 * отделен ред с reversal_of_id и причина. Само добавяне.
 */
class DepositClosing extends Model
{
    use AppendOnly, BelongsToCompany;

    protected $table = 'deposit_closings';

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['closed_on' => 'immutable_date', 'bank_account_id' => 'integer', 'created_by' => 'integer', 'reversal_of_id' => 'integer'];
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

    /** Сторното на това приключване, ако има. */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
