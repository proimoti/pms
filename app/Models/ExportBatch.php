<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Пакет за експорта към Микроинвест (PROJECT.md, „Експорт към Микроинвест“,
 * 26.11.2026): записите от книгата за един месец, които са експортирани
 * заедно. Само добавяне: пакетът не се променя и не се трие.
 */
class ExportBatch extends Model
{
    use AppendOnly, BelongsToCompany;

    protected $table = 'export_batches';

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['number' => 'integer', 'month' => 'immutable_date', 'entries_count' => 'integer', 'created_by' => 'integer'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ExportBatchEntry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
