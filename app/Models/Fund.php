<?php

namespace App\Models;

use App\Models\Concerns\RegistryRecord;
use App\Models\Scopes\RegistryVisibilityScope;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * Общ фонд (PROJECT.md, „Общ фонд“, решено 22.11.2026, уточнено 23.11.2026),
 * например за вход: име, аналитика за Микроинвест и имотите (един или
 * няколко). Фиксираните такси са приход във фонда, а сметките „плаща се от
 * фонд“ — разход от него. Салдото се смята от книгата (FundLedger).
 * Записва се през FundService (там са правилата). Не се трие, а се
 * архивира, и то само когато няма неархивиран абонат към фонда.
 *
 * Служител с обхват „само прикачените“ вижда целия фонд, ако поне един от
 * имотите му е прикачен към него.
 */
class Fund extends Model
{
    use RegistryRecord {
        archive as private archiveRecord;
    }

    protected $table = 'funds';

    protected $fillable = ['name', 'analytic', 'note'];

    protected static function assignedScopeRule(): string|Closure
    {
        return RegistryVisibilityScope::throughContracts('fund_properties', 'fund_id', 'id');
    }

    public function properties(): HasMany
    {
        return $this->hasMany(FundProperty::class)->orderBy('id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(UtilityAccount::class);
    }

    /** @return list<int> */
    public function propertyIds(): array
    {
        return FundProperty::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->where('company_id', $this->company_id)->where('fund_id', $this->id)
            ->orderBy('id')->pluck('property_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Фонд без аналитика (от обновяването): предупреждение; експортът по-късно го отказва. */
    public function missingAnalytic(): bool
    {
        return trim((string) $this->analytic) === '';
    }

    /** Има ли неархивиран абонат към фонда. */
    public function hasActiveAccounts(): bool
    {
        return UtilityAccount::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->where('company_id', $this->company_id)->where('fund_id', $this->id)->whereNull('archived_at')->exists();
    }

    public function archive(): void
    {
        if ($this->hasActiveAccounts()) {
            throw ValidationException::withMessages(['fund' => __('utilities.errors.fund_has_accounts')]);
        }

        $this->archiveRecord();
    }
}
