<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\WorksheetRules;
use App\Models\Scopes\RegistryVisibilityScope;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Платена битова сметка, внесена от EasyPay или ePay (PROJECT.md, т. 5.3).
 * Само се добавя: не се променя и не се трие. Частите по имоти са в
 * PaidUtilityBillPart; в книгата всяка част е запис по 407.
 */
class PaidUtilityBill extends Model
{
    use AppendOnly, WorksheetRules;

    protected $table = 'paid_utility_bills';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['paid_at' => 'immutable_datetime', 'amount' => 'decimal:2'];
    }

    /** Служител с „само прикачените“ вижда сметката, ако поне един от имотите ѝ е прикачен. */
    protected static function worksheetScopeRule(): string|Closure
    {
        return fn (Builder $builder, Model $model, User $user) => $builder->whereIn(
            $model->qualifyColumn('id'),
            fn ($query) => $query->select('paid_utility_bill_id')->from('paid_utility_bill_parts')
                ->whereIn('property_id', RegistryVisibilityScope::assignedPropertyIds($user)),
        );
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(UtilityAccount::class, 'utility_account_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function parts(): HasMany
    {
        return $this->hasMany(PaidUtilityBillPart::class)->orderBy('id');
    }

    /** Сметка на общ абонат (няколко имота). В Микроинвест тя е една аналитика 407 (задача за експорта). */
    public function isShared(): bool
    {
        return $this->parts()->count() > 1;
    }
}
