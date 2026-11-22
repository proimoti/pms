<?php

namespace App\Models;

use App\Enums\UtilityBilling;
use App\Enums\UtilityKind;
use App\Models\Concerns\RegistryRecord;
use App\Models\Scopes\RegistryVisibilityScope;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Абонат (PROJECT.md, т. 3.4 „Битови сметки, уточнения“, т. 5.3, 21.11.2026):
 * доставчик и номер, вид, реален или фиксиран, период на потребление (ден от
 * и ден до в месеца), имот или имоти със съотношение и дати на действие.
 * Записва се през UtilityAccountService (там са правилата).
 *
 * Служител с обхват „само прикачените“ вижда целия абонат, ако поне един
 * от имотите му е прикачен към него.
 */
class UtilityAccount extends Model
{
    use RegistryRecord;

    protected $table = 'utility_accounts';

    protected $fillable = [
        'provider_id', 'number', 'kind', 'billing', 'fixed_amount',
        'period_start_day', 'period_end_day', 'valid_from', 'valid_to', 'note',
    ];

    protected function casts(): array
    {
        return [
            'kind' => UtilityKind::class,
            'billing' => UtilityBilling::class,
            'fixed_amount' => 'decimal:2',
            'period_start_day' => 'integer',
            'period_end_day' => 'integer',
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
        ];
    }

    protected static function booted(): void
    {
        // Ключът за сравнение следва номера.
        static::saving(function (UtilityAccount $account) {
            $account->number_key = $account->number === null ? null : static::numberKey($account->number);
        });
    }

    protected static function assignedScopeRule(): string|Closure
    {
        return RegistryVisibilityScope::throughContracts('utility_account_properties', 'utility_account_id', 'id');
    }

    /**
     * Номерът за сравнение: без интервалите и без нулите отпред (EasyPay
     * записва номера като число и ги губи, понякога и с „.0“ накрая).
     */
    public static function numberKey(string $number): string
    {
        $key = trim($number);

        if (preg_match('/^(\d+)\.0+$/', $key, $match)) {
            $key = $match[1];
        }

        $key = ltrim($key, '0');

        return $key === '' ? '0' : $key;
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /** Имотите по реда им; последният поема остатъка. */
    public function properties(): HasMany
    {
        return $this->hasMany(UtilityAccountProperty::class)->orderBy('position');
    }

    /** Имотът, към който отива остатъкът при делене (последният в съотношението). */
    public function remainderPropertyId(): ?int
    {
        return $this->properties()->reorder()->orderByDesc('position')->value('property_id');
    }

    public function isFixed(): bool
    {
        return $this->billing === UtilityBilling::Fixed;
    }
}
