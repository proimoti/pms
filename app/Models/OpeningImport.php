<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Вносът на началните салда (PROJECT.md, „Начални салда“, 26.11.2026): един за
 * фирма; сторното е отделен ред с reversal_of_id и причина. Само добавяне.
 */
class OpeningImport extends Model
{
    use AppendOnly, BelongsToCompany;

    protected $table = 'opening_imports';

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['as_of' => 'immutable_date', 'rows_count' => 'integer', 'reversal_of_id' => 'integer', 'created_by' => 'integer'];
    }

    /** Действащият внос на фирмата (без сторно) или null. */
    public static function active(): ?self
    {
        return static::query()->whereNull('reversal_of_id')
            ->whereNotIn('id', static::query()->whereNotNull('reversal_of_id')->select('reversal_of_id'))->first();
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
