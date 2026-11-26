<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Изплащане към собственик или бенефициент (PROJECT.md, „Плащания и салда“,
 * 26.11.2026): по договор за управление; бенефициентът към датата се запомня.
 * Сторното е отделен ред с reversal_of_id и сума с минус. Само добавяне.
 */
class OwnerPayout extends Model
{
    use AppendOnly, BelongsToCompany;

    protected $table = 'owner_payouts';

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'immutable_date', 'bank_account_id' => 'integer', 'beneficiary_id' => 'integer',
            'owner_id' => 'integer', 'reversal_of_id' => 'integer', 'created_by' => 'integer'];
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    public function ownerContract(): BelongsTo
    {
        return $this->belongsTo(OwnerContract::class);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }
}
