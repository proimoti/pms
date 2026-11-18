<?php

namespace App\Models;

use App\Models\Builders\AppendOnlyBuilder;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Журнал: кой, кога и какво е променил. Само се добавя (като книгата).
 * Администраторът на приложението не го вижда (съдържа данни от регистъра).
 */
#[Table('audit_log')]
class AuditLog extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException(__('registry.append_only')));
        static::deleting(fn () => throw new LogicException(__('registry.append_only')));
    }

    public function newEloquentBuilder($query): AppendOnlyBuilder
    {
        return new AppendOnlyBuilder($query);
    }

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
