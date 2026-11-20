<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\WorksheetRules;
use App\Models\Scopes\RegistryVisibilityScope;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Издаден (заключен) месец. Не се отключва (PROJECT.md, „Издаване и заключване“). */
class PeriodLock extends Model
{
    use AppendOnly, WorksheetRules;

    protected $table = 'period_locks';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['month' => 'immutable_date', 'locked_at' => 'immutable_datetime'];
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /** Издаден ли е месецът (за фирмата на влезлия). */
    public static function isLocked(CarbonImmutable $month): bool
    {
        return static::query()->whereDate('month', $month)->exists();
    }

    protected static function worksheetScopeRule(): string|Closure
    {
        return RegistryVisibilityScope::ALL;
    }
}
