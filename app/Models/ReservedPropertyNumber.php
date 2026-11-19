<?php

namespace App\Models;

use App\Models\Builders\AppendOnlyBuilder;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Резервиран номер на имот: зает е, макар че никой имот вече не го носи.
 * Само се добавя (номер никога не се освобождава).
 */
class ReservedPropertyNumber extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    public const REASON_CHANGED = 'changed';

    public const REASON_IMPORTED = 'imported';

    protected $table = 'reserved_property_numbers';

    protected $fillable = ['number', 'reason'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException(__('registry.reserved_append_only')));
        static::deleting(fn () => throw new LogicException(__('registry.reserved_append_only')));
    }

    public function newEloquentBuilder($query): AppendOnlyBuilder
    {
        return new AppendOnlyBuilder($query);
    }

    protected function casts(): array
    {
        return ['number' => 'integer', 'created_at' => 'datetime'];
    }
}
